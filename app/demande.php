<?php
/*
 * Demande de devis depuis arbogestion.fr :
 * contrôle → recalcul des prix → client + devis BROUILLON dans Pennylane → contrat PDF en annexe → courriels.
 * Le devis n'est PAS envoyé au client : Kevin le relit et l'envoie depuis Pennylane (signature en ligne).
 */
declare(strict_types=1);
require_once __DIR__ . '/contrat.php';

final class ErreurDemande extends RuntimeException {}

function nettoyer(?string $s, int $max = 300): string {
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$s));
    return mb_substr($s, 0, $max);
}

/** Valide et normalise la demande reçue du site. */
function valider_demande(array $in): array {
    $profil = (string)($in['profil'] ?? '');
    if (!isset(PROFILS[$profil])) { throw new ErreurDemande('Profil inconnu.'); }
    $c = is_array($in['client'] ?? null) ? $in['client'] : [];
    $cl = [];
    foreach (['prenom', 'nom', 'denomination', 'forme', 'siren', 'siret', 'adresse', 'cp', 'ville', 'representant', 'referent', 'habilitation', 'email', 'tel'] as $k) {
        $cl[$k] = nettoyer($c[$k] ?? '', $k === 'adresse' ? 300 : 160);
    }
    $cl['siren'] = preg_replace('/\D/', '', $cl['siren']);
    $cl['siret'] = preg_replace('/\D/', '', $cl['siret']);
    if (strlen($cl['siren']) === 14) { $cl['siret'] = $cl['siret'] ?: $cl['siren']; $cl['siren'] = substr($cl['siren'], 0, 9); }
    if (strlen($cl['siret']) !== 14 || substr($cl['siret'], 0, 9) !== $cl['siren']) { $cl['siret'] = ''; }
    $cl['cp'] = preg_replace('/[^0-9A-Za-z ]/', '', $cl['cp']);
    if (!filter_var($cl['email'], FILTER_VALIDATE_EMAIL)) { throw new ErreurDemande('Adresse e-mail invalide.'); }
    if ($cl['tel'] !== '') {
        $t = preg_replace('/[^\d+]/', '', $cl['tel']);
        if (str_starts_with($t, '+33')) { $t = '0' . substr($t, 3); } elseif (str_starts_with($t, '0033')) { $t = '0' . substr($t, 4); }
        if (preg_match('/^0[1-9]\d{8}$/', $t)) { $cl['tel'] = trim(chunk_split($t, 2, ' ')); }
        elseif (!preg_match('/^\+\d{8,15}$/', $t)) { throw new ErreurDemande('Numéro de téléphone incorrect : 10 chiffres commençant par 0, ou au format international (+33…).'); }
    }
    if ($cl['adresse'] === '' || $cl['cp'] === '' || $cl['ville'] === '') { throw new ErreurDemande('Adresse incomplète.'); }
    if ($profil === 'particulier') {
        if ($cl['prenom'] === '' || $cl['nom'] === '') { throw new ErreurDemande('Nom et prénom obligatoires.'); }
    } else {
        if ($cl['denomination'] === '') { throw new ErreurDemande('Raison sociale ou nom de la collectivité obligatoire.'); }
        if (strlen($cl['siren']) !== 9) { throw new ErreurDemande('Numéro SIREN à 9 chiffres obligatoire.'); }
        if ($cl['representant'] === '') { throw new ErreurDemande('Représentant (nom et fonction) obligatoire.'); }
        if ($cl['referent'] === '') { $cl['referent'] = $cl['representant']; }
        if ($profil === 'pro') { $cl['tva'] = tva_intracom($cl['siren']); }
    }
    $e = is_array($in['estimation'] ?? null) ? $in['estimation'] : [];
    $est = [
        'profil' => $profil,
        'nb' => (int)($e['nb'] ?? 0),
        'demarrage' => !empty($e['demarrage']), 'diag' => !empty($e['diag']), 'prev' => !empty($e['prev']),
        'rb' => (int)($e['rb'] ?? 0), 'rh' => (int)($e['rh'] ?? 0),
        'km' => (isset($e['km']) && is_numeric($e['km'])) ? (float)$e['km'] : null,
        'duree_h' => (isset($e['duree_h']) && is_numeric($e['duree_h'])) ? (float)$e['duree_h'] : null,
        'km_peage' => (isset($e['km_peage']) && is_numeric($e['km_peage'])) ? (float)$e['km_peage'] : 0.0,
        'abonnement' => !empty($e['abonnement']),
    ];
    if ($est['nb'] < 1 || $est['nb'] > 10000) { throw new ErreurDemande("Nombre d'arbres invalide."); }
    if (!$est['demarrage'] && !$est['abonnement']) { throw new ErreurDemande('Choisissez une formule : prestation ponctuelle, recensement et diagnostic initial + abonnement de suivi, ou abonnement de suivi seul.'); }
    if ($est['demarrage'] && $est['abonnement'] && !$est['diag'] && !$est['prev'] && !$est['rb'] && !$est['rh']) { throw new ErreurDemande('Sélectionnez au moins une prestation.'); }
    if (empty($in['consentement'])) { throw new ErreurDemande('Merci de cocher la case de consentement.'); }
    $debut = (string)($in['date_debut'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut) || strtotime($debut) < strtotime('today')) {
        $debut = date('Y-m-d', strtotime('first day of next month'));
    }
    return [
        'profil' => $profil,
        'client' => $cl,
        'estimation' => $est,
        'paiement' => ($profil !== 'collectivite' && ($in['paiement'] ?? '') === 'mensuel') ? 'mensuel' : 'annuel',
        'date_debut' => $debut,
        'site_adresse' => nettoyer($in['site_adresse'] ?? '', 300),
        'lieu_label' => nettoyer($in['lieu_label'] ?? '', 200),
        'contraintes' => nettoyer($in['contraintes'] ?? '', 500),
        'message' => nettoyer($in['message'] ?? '', 2000),
        'execution_anticipee' => $profil === 'particulier' && !empty($in['execution_anticipee']),
    ];
}


/* ---------- Vérifications du signataire (paragraphe « À vérifier » du courriel à Kevin, rien n'est bloqué) ---------- */
function http_json(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'arbogestion.fr']);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($r !== false && $code < 400) ? (json_decode((string)$r, true) ?: null) : null;
}
function domaine_mail(string $m): string { return strtolower(trim(substr(strrchr($m, '@') ?: '', 1))); }
function domaine_url(string $u): string { $h = strtolower((string)parse_url(preg_match('~^https?://~i', $u) ? $u : 'https://' . $u, PHP_URL_HOST)); return preg_replace('/^www\./', '', $h); }
const DOMAINES_PERSO = ['gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.fr', 'outlook.com', 'outlook.fr', 'live.com', 'live.fr', 'msn.com', 'yahoo.com', 'yahoo.fr',
    'orange.fr', 'wanadoo.fr', 'free.fr', 'sfr.fr', 'neuf.fr', 'laposte.net', 'bbox.fr', 'icloud.com', 'me.com', 'gmx.fr', 'gmx.com', 'aol.com', 'protonmail.com', 'proton.me', 'numericable.fr', 'club-internet.fr'];

/** Mairie : e-mail, téléphone et site officiels (Annuaire de l'administration, service-public.fr). */
function mairie_officielle(string $insee): ?array {
    if (!preg_match('/^\d[\dAB]\d{3}$/', $insee)) { return null; }
    $d = http_json('https://api-lannuaire.service-public.fr/api/explore/v2.1/catalog/datasets/api-lannuaire-administration/records?limit=50&where=' . rawurlencode('code_insee_commune="' . $insee . '"'));
    foreach (($d['results'] ?? []) as $r) {
        $piv = is_string($r['pivot'] ?? null) ? $r['pivot'] : json_encode($r['pivot'] ?? '');
        if (stripos($piv, '"mairie"') === false && stripos((string)($r['nom'] ?? ''), 'mairie') !== 0) { continue; }
        $val = function ($v) { if (is_string($v) && ($j = json_decode($v, true)) !== null) { $v = $j; } if (is_array($v)) { $v = $v[0]['valeur'] ?? ($v[0] ?? ''); } return is_string($v) ? trim($v) : ''; };
        return ['nom' => (string)($r['nom'] ?? 'Mairie'), 'email' => $val($r['adresse_courriel'] ?? ''), 'tel' => $val($r['telephone'] ?? ''), 'site' => $val($r['site_internet'] ?? '')];
    }
    return null;
}

function verifications_signataire(array $dem): array {
    if ($dem['profil'] === 'particulier') { return []; }
    $c = $dem['client']; $col = $dem['profil'] === 'collectivite';
    $dom = domaine_mail($c['email']); $v = [];
    $e = null;
    $r = http_json('https://recherche-entreprises.api.gouv.fr/search?per_page=1&q=' . $c['siren']);
    if (!empty($r['results'][0]) && ($r['results'][0]['siren'] ?? '') === $c['siren']) { $e = $r['results'][0]; }
    if ($r === null) { /* répertoire officiel injoignable : pas de vérification, pas de fausse alerte */ }
    elseif (!$e) { $v[] = "SIREN {$c['siren']} introuvable dans le répertoire officiel : vérifier qu'il est exact."; }
    else {
        if (($e['etat_administratif'] ?? 'A') !== 'A') { $v[] = 'ATTENTION : ce SIREN correspond à une structure fermée (' . ($e['nom_complet'] ?? '') . ').'; }
        $off = norm_nom((string)($e['nom_raison_sociale'] ?? $e['nom_complet'] ?? '')); $saisi = norm_nom($c['denomination']);
        if ($off && $saisi && !str_contains($off, $saisi) && !str_contains($saisi, $off)) { $v[] = 'Nom saisi « ' . $c['denomination'] . ' » différent du nom officiel « ' . ($e['nom_complet'] ?? '') . ' ».'; }
        if ($col && empty($e['complements']['collectivite_territoriale']) && !preg_match('/^7/', (string)($e['nature_juridique'] ?? ''))) { $v[] = "Ce SIREN ne correspond pas à une collectivité ou un organisme public."; }
    }
    if (in_array($dom, DOMAINES_PERSO, true)) {
        $v[] = "Adresse e-mail personnelle ($dom) pour " . ($col ? 'une collectivité' : 'une entreprise') . " : s'assurer que le signataire est bien habilité.";
    }
    if ($col && $e) {
        $m = mairie_officielle((string)($e['complements']['collectivite_territoriale']['code_insee'] ?? ''));
        if ($m) {
            $doms = array_filter([domaine_mail($m['email']), $m['site'] ? domaine_url($m['site']) : '']);
            $ok = false; foreach ($doms as $d) { if ($d && ($dom === $d || str_ends_with($dom, '.' . $d) || str_ends_with($d, '.' . $dom))) { $ok = true; } }
            if (!$ok) { $v[] = "L'adresse {$c['email']} ne correspond pas à l'e-mail officiel de la mairie" . ($m['email'] ? " ({$m['email']})" : '') . '.'; }
            $v[] = 'Coordonnées officielles (service-public.fr) : ' . implode(' · ', array_filter([$m['nom'], $m['email'], $m['tel'] ? 'tél. ' . $m['tel'] : '', $m['site']])) . ($ok ? '' : ' : appeler le standard pour confirmer la demande.');
        }
    }
    if (!$col && !in_array($dom, DOMAINES_PERSO, true) && $e) {
        $label = norm_nom(explode('.', $dom)[0]); $off = norm_nom((string)($e['nom_complet'] ?? '') . ($e['sigle'] ?? ''));
        if (strlen($label) >= 3 && !str_contains($off, $label) && !str_contains($label, substr($off, 0, 6))) { $v[] = "Le domaine de l'adresse e-mail ($dom) ne ressemble pas au nom de l'entreprise : à vérifier."; }
    }
    return $v;
}

function nom_client(array $dem): string {
    $c = $dem['client'];
    return $dem['profil'] === 'particulier' ? trim($c['prenom'] . ' ' . $c['nom']) : $c['denomination'];
}

/** Retrouve ou crée le client dans Pennylane. */
function norm_nom(string $s): string { return preg_replace('/[^a-z]/', '', strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT', $s))); }
function norm_tel(string $s): string { $d = preg_replace('/\D/', '', $s); if (str_starts_with($d, '33') && strlen($d) === 11) { $d = '0' . substr($d, 2); } return strlen($d) >= 9 ? substr($d, -9) : ''; }

/**
 * Recherche d'un client déjà connu, pour éviter les doublons dans Pennylane.
 * Entreprises et collectivités : SIREN. Particuliers : même e-mail, ou même téléphone, ou même nom (sans tenir compte
 * des accents, tirets, espaces, majuscules, ni de l'ordre prénom / nom) et même code postal.
 * Renvoie ['id' => …, 'motif' => …] ; un motif autre que l'e-mail ou le SIREN est signalé à Kevin pour vérification.
 */
function rapprocher_client(array $dem): ?array {
    $c = $dem['client'];
    $email = strtolower(trim($c['email'])); $tel = norm_tel((string)$c['tel']);
    $part = $dem['profil'] === 'particulier';
    $noms = $part ? [norm_nom($c['prenom'] . $c['nom']), norm_nom($c['nom'] . $c['prenom'])] : [norm_nom($c['denomination'])];
    // 1. Même adresse e-mail dans Pennylane (y compris les fiches créées à la main)
    try {
        $l = pennylane('GET', '/customers?limit=20&filter=' . pl_filtre([['field' => 'emails', 'operator' => 'in', 'value' => [$email]]]));
        if (!empty($l['items'][0]['id'])) { return ['id' => (string)$l['items'][0]['id'], 'motif' => 'même adresse e-mail']; }
    } catch (Throwable $e) { journal('Recherche client par e-mail : ' . $e->getMessage()); }
    if (!$part) { return null; }
    // 2. Demandes déjà reçues par le site
    try {
        $rows = db()->query("SELECT pl_client_id, donnees FROM demandes WHERE pl_client_id IS NOT NULL AND pl_client_id <> '' AND profil = 'particulier' ORDER BY id DESC LIMIT 2000")->fetchAll();
        foreach ($rows as $r) {
            $d = json_decode($r['donnees'], true)['client'] ?? [];
            if (!ctype_digit((string)$r['pl_client_id'])) { continue; }
            if (strtolower(trim((string)($d['email'] ?? ''))) === $email) { return ['id' => $r['pl_client_id'], 'motif' => 'même adresse e-mail']; }
            if ($tel !== '' && norm_tel((string)($d['tel'] ?? '')) === $tel) { return ['id' => $r['pl_client_id'], 'motif' => 'même numéro de téléphone (' . $c['tel'] . ')']; }
            if (in_array(norm_nom(($d['prenom'] ?? '') . ($d['nom'] ?? '')), $noms, true) && preg_replace('/\s/', '', (string)($d['cp'] ?? '')) === preg_replace('/\s/', '', $c['cp'])) {
                return ['id' => $r['pl_client_id'], 'motif' => 'même nom et même code postal (' . $c['cp'] . ')'];
            }
        }
    } catch (Throwable $e) { journal('Recherche client (base) : ' . $e->getMessage()); }
    // 3. Fiches Pennylane du même prénom : nom, téléphone ou code postal comparés
    try {
        foreach (array_unique([$c['prenom'], $c['nom']]) as $debut) {
            if (mb_strlen($debut) < 2) { continue; }
            $l = pennylane('GET', '/customers?limit=100&filter=' . pl_filtre([['field' => 'name', 'operator' => 'start_with', 'value' => $debut]]));
            foreach (($l['items'] ?? []) as $it) {
                if ($tel !== '' && norm_tel((string)($it['phone'] ?? '')) === $tel) { return ['id' => (string)$it['id'], 'motif' => 'même numéro de téléphone (' . $c['tel'] . ')']; }
                $n = norm_nom((string)($it['name'] ?? (($it['first_name'] ?? '') . ($it['last_name'] ?? ''))));
                $cp = preg_replace('/\s/', '', (string)($it['billing_address']['postal_code'] ?? ''));
                if (in_array($n, $noms, true) && $cp === preg_replace('/\s/', '', $c['cp'])) { return ['id' => (string)$it['id'], 'motif' => 'même nom et même code postal (' . $c['cp'] . ')']; }
            }
        }
    } catch (Throwable $e) { journal('Recherche client par nom : ' . $e->getMessage()); }
    return null;
}

function pl_client(array $dem): string {
    $GLOBALS['arbo_rapprochement'] = null;
    $c = $dem['client'];
    if ($dem['profil'] === 'particulier') {
        $trouve = rapprocher_client($dem);
        if ($trouve && $trouve['id'] !== '') {
            if ($trouve['motif'] !== 'même adresse e-mail') { $GLOBALS['arbo_rapprochement'] = $trouve['motif']; }
            return $trouve['id'];
        }
    }
    $adresse = ['address' => $c['adresse'], 'postal_code' => $c['cp'], 'city' => $c['ville'], 'country_alpha2' => 'FR'];
    if ($dem['profil'] === 'particulier') {
        $ref = 'arbo-' . substr(sha1(strtolower($c['email'])), 0, 20);
        $f = pl_filtre([['field' => 'external_reference', 'operator' => 'eq', 'value' => $ref]]);
        $l = pennylane('GET', '/customers?filter=' . $f . '&filters=' . $f);
        if (!empty($l['items'][0]['id'])) { return (string)$l['items'][0]['id']; }
        $r = pennylane('POST', '/individual_customers', array_filter([
            'first_name' => $c['prenom'], 'last_name' => $c['nom'], 'emails' => [$c['email']],
            'phone' => $c['tel'] ?: null, 'billing_address' => $adresse, 'external_reference' => $ref,
            'payment_conditions' => 'upon_receipt', 'billing_language' => 'fr_FR',
        ], fn($v) => $v !== null));
    } else {
        $f = pl_filtre([['field' => 'reg_no', 'operator' => 'eq', 'value' => $c['siren']]]);
        $l = pennylane('GET', '/customers?filter=' . $f . '&filters=' . $f);
        if (!empty($l['items'][0]['id'])) { return (string)$l['items'][0]['id']; }
        $trouve = rapprocher_client($dem); // fiche créée à la main sans SIREN, mais avec la même adresse e-mail
        if ($trouve && $trouve['id'] !== '') { $GLOBALS['arbo_rapprochement'] = $trouve['motif'] . ' (fiche sans SIREN : à compléter)'; return $trouve['id']; }
        $corps = [
            'name' => $c['denomination'], 'reg_no' => $c['siren'], 'emails' => [$c['email']],
            'phone' => $c['tel'] ?: null, 'billing_address' => $adresse,
            'payment_conditions' => $dem['profil'] === 'collectivite' ? '30_days' : 'upon_receipt',
            'recipient' => $c['referent'] ?: null, 'billing_language' => 'fr_FR',
            'external_reference' => 'arbo-' . $c['siren'],
        ];
        if (!empty($c['tva'])) { $corps['vat_number'] = $c['tva']; }
        $r = pennylane('POST', '/company_customers', array_filter($corps, fn($v) => $v !== null));
    }
    return (string)($r['id'] ?? '');
}

/** Contact signataire du client (nécessaire pour l'envoi en signature électronique depuis Pennylane). Non bloquant. */
function pl_contact(string $client_id, array $dem): void {
    $c = $dem['client'];
    try {
        $l = pennylane('GET', '/customers/' . $client_id . '/contacts?limit=100');
        foreach (($l['items'] ?? $l['contacts'] ?? (array_is_list($l) ? $l : [])) as $ct) {
            if (is_array($ct) && strcasecmp(trim((string)($ct['email'] ?? '')), $c['email']) === 0) { return; } // contact déjà présent (même e-mail)
        }
        if ($dem['profil'] === 'particulier') { $prenom = $c['prenom']; $nom = $c['nom']; $role = null; }
        else {
            $qui = trim(explode(',', (string)($c['referent'] ?: $c['representant']))[0]);
            $role = trim(substr((string)($c['referent'] ?: $c['representant']), strlen($qui)), " ,") ?: null;
            $m = preg_split('/\s+/', $qui, 2); $prenom = $m[0] ?? $qui; $nom = $m[1] ?? $qui;
        }
        pennylane('POST', '/customers/' . $client_id . '/contacts', array_filter([
            'first_name' => $prenom, 'last_name' => $nom, 'email' => $c['email'], 'role' => $role,
            'mobile_number' => $c['tel'] ?: null,
        ], fn($v) => $v !== null && $v !== ''));
    } catch (Throwable $e) { journal('Contact Pennylane non créé : ' . $e->getMessage()); }
}

function prix6(float $v): string { return number_format($v, 6, '.', ''); }

/**
 * Version « client » des lignes : une ligne par prestation, au forfait, sans base de calcul
 * (pas de prix à l'arbre, au km ni à la journée). Le détail reste dans le courriel à Kevin.
 */
function lignes_client(array $calc): array {
    $out = []; $n = (int)$calc['nb']; $pl = fn(int $q, string $u) => $q . ' ' . $u . ($q > 1 ? 's' : '');
    foreach ($calc['lignes_demarrage'] as $l) {
        $m = round($l['qte'] * $l['pu'], 2);
        if ($l['label'] === 'Arbres supplémentaires' && $out) { $out[count($out) - 1]['pu'] = round($out[count($out) - 1]['pu'] + $m, 2); continue; }
        $label = $l['label']; $desc = $l['desc'];
        if (in_array($l['unite'], ['journée', 'forfait'], true) || str_contains($label, 'Prévisionnel') || $l['unite'] === 'arbre' && !str_contains($label, 'couronne')) { $label .= ' – ' . $pl($n, 'arbre'); }
        elseif ($l['unite'] === 'test') { $label .= ' – ' . $pl((int)$l['qte'], 'test'); }
        elseif (str_contains($label, 'couronne')) { $label .= ' – ' . $pl((int)$l['qte'], 'arbre'); }
        if ($l['unite'] === 'km' && str_starts_with($l['label'], 'Frais de déplacement')) { $label = 'Frais de déplacement'; $desc = 'Aller-retour depuis Cambes (2 × ' . str_replace('.', ',', (string)($calc['km'] ?? '')) . ' km par la route)' . (!empty($calc['loin']) ? '' : ($calc['jours'] > 1 ? ', ' . $calc['jours'] . ' déplacements' : '')); }
        elseif ($l['unite'] === 'jour') { $label = 'Hébergement et repas – ' . $pl((int)$l['qte'], 'jour'); $desc = 'Mission à plus de ' . (int)(tarifs()['eloignement']['heuresTrajetMax'] ?? 3) . ' h de route de Cambes'; }
        elseif ($l['unite'] === 'km') { $label = 'Frais de péage (estimation)'; $desc = 'Autoroute à péage, aller-retour'; }
        $out[] = ['label' => $label, 'desc' => $desc, 'qte' => 1, 'unite' => 'forfait', 'pu' => $m];
    }
    return $out;
}

/** Lignes du devis Pennylane (démarrage + abonnement 1re année). */
function lignes_devis(array $dem, array $calc): array {
    $sections = []; $lignes = []; $rang = 0;
    if ($calc['lignes_demarrage']) {
        $sections[] = $calc['abonnement'] ? ['title' => 'Démarrage', 'description' => 'Prestations ponctuelles, facturées après leur réalisation', 'rank' => ++$rang]
            : ['title' => 'Prestation ponctuelle', 'description' => 'Sans abonnement, facturée après sa réalisation', 'rank' => ++$rang];
        foreach (lignes_client($calc) as $l) {
            $lignes[] = ['label' => $l['label'], 'description' => $l['desc'], 'quantity' => (float)$l['qte'], 'unit' => $l['unite'],
                'raw_currency_unit_price' => prix6($l['pu']), 'vat_rate' => 'FR_200', 'section_rank' => $rang];
        }
    }
    $ab = $calc['abonnement'];
    if ($ab && empty($ab['sur_devis'])) {
        $formule = PROFILS[$dem['profil']]['formule'];
        $sections[] = ['title' => 'Suivi annuel – ' . $formule, 'description' => 'Abonnement avec le logiciel Grality, 1/6 du parc rediagnostiqué chaque année (contrat joint en annexe)', 'rank' => ++$rang];
        if ($dem['paiement'] === 'mensuel') {
            $lignes[] = ['label' => "Abonnement $formule – jusqu'à {$ab['max']} arbres", 'description' => 'Mensualité, prélèvement SEPA le 10 de chaque mois – première année',
                'quantity' => 12, 'unit' => 'mois', 'raw_currency_unit_price' => prix6($ab['prix'] / 12), 'vat_rate' => 'FR_200', 'section_rank' => $rang];
        } else {
            $lignes[] = ['label' => "Abonnement $formule – jusqu'à {$ab['max']} arbres", 'description' => 'Forfait annuel – première année, reconductible selon le contrat joint',
                'quantity' => 1, 'unit' => 'an', 'raw_currency_unit_price' => prix6($ab['prix']), 'vat_rate' => 'FR_200', 'section_rank' => $rang];
        }
    }
    return [$sections, $lignes];
}

function texte_estimation(array $dem, array $calc, bool $client = false): string {
    $T = tarifs(); $t = [];
    if ($client) { foreach (lignes_client($calc) as $l) { $t[] = '- ' . $l['label'] . ' : ' . eur($l['pu']) . ' HT'; } }
    else foreach ($calc['lignes_demarrage'] as $l) { $t[] = '- ' . $l['label'] . ' : ' . str_replace(['.'], [','], (string)$l['qte']) . ' ' . $l['unite'] . ((float)$l['qte'] > 1 && !str_ends_with($l['unite'], 's') ? 's' : '') . ' × ' . eur($l['pu']) . ' = ' . eur(round($l['qte'] * $l['pu'], 2)) . ' HT'; }
    if ($calc['demarrage_ht'] > 0) { $t[] = '  Total ' . ($calc['abonnement'] ? 'démarrage' : 'prestation') . ' : ' . eur($calc['demarrage_ht']) . ' HT (' . eur(round($calc['demarrage_ht'] * (1 + $T['tva']), 2)) . ' TTC)'; }
    if (!empty($calc['acompte_ht'])) { $t[] = '  Acompte à la signature (' . $T['acompte']['pourcentage'] . ' %) : ' . eur(round($calc['acompte_ht'] * (1 + $T['tva']), 2)) . ' TTC'; }
    $ab = $calc['abonnement'];
    if ($ab) { $t[] = empty($ab['sur_devis']) ? '- Abonnement ' . PROFILS[$dem['profil']]['formule'] . " jusqu'à {$ab['max']} arbres : " . eur($ab['prix']) . ' HT / an (' . $dem['paiement'] . ')' : '- Abonnement : au-delà du dernier palier, sur devis'; }
    return implode("\n", $t);
}

/*
 * Enregistre la demande et crée le devis dans Pennylane (modèle « Devis Arbogestion », annexe : contrat ou
 * conditions particulières + CGV). Utilisé par le formulaire du site et par l'espace connecté.
 * Renvoie ['id', 'numero', 'devis_id', 'erreur'].
 */
function creer_devis(array $dem, array $calc, array $opt = []): array {
    $origine = (string)($opt['origine'] ?? 'site');
    $dem['origine'] = $origine;
    $dem['cgv_version'] = $dem['cgv_version'] ?? cgv_version_courante(); // version des CGV jointe au devis (preuve)
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO demandes (cree_le, profil, statut, nom_client, email, donnees, calcul, maj_le) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$now, $dem['profil'], 'recue', nom_client($dem), $dem['client']['email'],
            json_encode($dem, JSON_UNESCAPED_UNICODE), json_encode($calc, JSON_UNESCAPED_UNICODE), $now]);
    $id = (int)$pdo->lastInsertId();
    $erreur = null; $numero = 'DEM-' . $id; $devis_id = null; $client_id = null;

    try {
        $client_id = pl_client($dem);
        if ($client_id !== '') { pl_contact($client_id, $dem); }
        [$sections, $lignes] = lignes_devis($dem, $calc);
        if (!$lignes) { throw new RuntimeException('Aucune ligne chiffrable (abonnement sur devis) : devis à établir manuellement.'); }
        $ponctuel = empty($calc['abonnement']);
        $cgv_txt = 'conditions générales de vente Arbogestion (version du ' . date_fr($dem['cgv_version']) . ', consultables sur arbogestion.fr/cgv.php)';
        $mention = $ponctuel
            ? 'La signature du présent devis vaut acceptation des conditions particulières et des ' . $cgv_txt . ', jointes en annexe. Prestation sans abonnement, facturée après sa réalisation. Les interventions de terrain ne sont pas comprises.'
            : 'La signature du présent devis vaut acceptation du contrat d’abonnement Arbogestion joint en annexe (grille tarifaire 2026 et conditions particulières) ainsi que des ' . $cgv_txt . ', jointes à la suite. '
              . 'Les prestations de démarrage sont facturées après leur réalisation ; l’abonnement est facturé selon l’échéancier du contrat. Les interventions de terrain ne sont pas comprises.';
        if ($calc['acompte_ht'] > 0) { $mention .= ' Un acompte de ' . tarifs()['acompte']['pourcentage'] . ' % des prestations ' . ($ponctuel ? '' : 'de démarrage ') . '(' . eur(round($calc['acompte_ht'] * (1 + tarifs()['tva']), 2)) . ' TTC) est demandé à la signature, le solde étant facturé après réalisation.'; }
        if ($dem['profil'] === 'particulier') { $mention .= ' Vous disposez d’un droit de rétractation de 14 jours (article ' . ($ponctuel ? cgv_article('particulier', 'Rétractation') . ' des conditions générales de vente' : '6 du contrat') . ').'; }
        if ($dem['profil'] === 'collectivite') { $mention .= ' L’acceptation est complétée par la transmission du bon de commande ou de la décision d’engagement.'; }
        if (!empty($opt['mention'])) { $mention = trim($opt['mention']) . ' ' . $mention; }
        $desc = $origine === 'site'
            ? 'Demande en ligne n° ' . $id . ' – ' . $calc['nb'] . ' arbres' . ($dem['lieu_label'] ? ' – ' . $dem['lieu_label'] : '')
            : ((string)($opt['description'] ?? '') ?: ('Réf. ' . $id . ($calc['nb'] ? ' – ' . $calc['nb'] . ' arbres' : '') . ($dem['lieu_label'] ? ' – ' . $dem['lieu_label'] : '')));
        $objet = (string)($opt['objet'] ?? '') ?: ('Arbogestion – ' . ($ponctuel ? ($calc['nb'] ? 'Diagnostic ponctuel' : 'Prestation de conseil') : PROFILS[$dem['profil']]['formule']));
        $corps = [
            'date' => date('Y-m-d'), 'deadline' => date('Y-m-d', strtotime('+' . max(1, (int)($opt['validite_mois'] ?? 3)) . ' months')),
            'customer_id' => (int)$client_id, 'language' => 'fr_FR',
            'pdf_invoice_subject' => $objet, 'pdf_description' => $desc, 'special_mention' => $mention,
            'external_reference' => 'arbo-demande-' . $id . '-' . substr(md5($now), 0, 6),
            'invoice_line_sections' => $sections, 'invoice_lines' => $lignes,
        ];
        if ($modele = pl_modele('devis')) { $corps['quote_template_id'] = $modele; }
        $devis = pennylane('POST', '/quotes', $corps);
        $devis_id = (string)($devis['id'] ?? '');
        $numero = (string)(($devis['quote_number'] ?? '') ?: ('DEM-' . $id));
        $pdf = contrat_pdf($dem, $calc, $numero, date('Y-m-d'), ARBO_APP . '/storage/contrats/contrat-' . $id . '.pdf');
        pennylane('POST', '/quotes/' . $devis_id . '/appendices', null, $pdf);
        $pdo->prepare('UPDATE demandes SET statut=?, pl_client_id=?, pl_devis_id=?, pl_devis_numero=?, maj_le=? WHERE id=?')
            ->execute(['devis_brouillon', $client_id, $devis_id, $numero, date('Y-m-d H:i:s'), $id]);
    } catch (Throwable $e) {
        $erreur = $e->getMessage();
        journal("Demande $id : $erreur");
        try { contrat_pdf($dem, $calc, $numero, date('Y-m-d'), ARBO_APP . '/storage/contrats/contrat-' . $id . '.pdf'); } catch (Throwable $e2) {}
        $pdo->prepare('UPDATE demandes SET statut=?, pl_client_id=?, notes=?, maj_le=? WHERE id=?')
            ->execute(['erreur_pennylane', $client_id, $erreur, date('Y-m-d H:i:s'), $id]);
    }
    return ['id' => $id, 'numero' => $numero, 'devis_id' => $devis_id, 'erreur' => $erreur];
}

/** Envoie au client, par Pennylane, un devis déjà créé (lien de consultation et de signature). */
function envoyer_devis(int $id): void {
    $s = db()->prepare('SELECT * FROM demandes WHERE id = ?'); $s->execute([$id]); $row = $s->fetch();
    if (!$row || !$row['pl_devis_id']) { throw new ErreurDemande('Devis introuvable dans Pennylane.'); }
    try {
        pennylane('POST', '/quotes/' . rawurlencode((string)$row['pl_devis_id']) . '/send_by_email', ['recipients' => [$row['email']]]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'HTTP 409') !== false) { throw new ErreurDemande('Pennylane prépare encore le PDF du devis. Réessaie dans une à deux minutes.'); }
        throw $e;
    }
    if ($row['statut'] === 'devis_brouillon') {
        db()->prepare('UPDATE demandes SET statut=?, maj_le=?, notes=? WHERE id=?')
            ->execute(['devis_envoye', date('Y-m-d H:i:s'), trim(($row['notes'] ?? '') . "\n" . date('d/m/Y H:i') . ' Devis envoyé au client depuis l’espace connecté (' . $row['email'] . ').'), $id]);
    }
}

/** Traite une demande complète venant du site. Renvoie ['id'=>…, 'pennylane'=>bool]. */
function traiter_demande(array $in): array {
    $dem = valider_demande($in);
    $calc = calculer($dem['estimation']);
    $r = creer_devis($dem, $calc, ['origine' => 'site']);
    $id = $r['id']; $numero = $r['numero']; $erreur = $r['erreur'];
    $resume = texte_estimation($dem, $calc);
    try { $verifs = verifications_signataire($dem); } catch (Throwable $e) { $verifs = []; journal('Vérifications signataire : ' . $e->getMessage()); }

    $c = $dem['client'];
    $infos = "Profil : " . PROFILS[$dem['profil']]['nom'] . "\nClient : " . nom_client($dem)
        . ($dem['profil'] === 'particulier' ? '' : "\nSIREN : {$c['siren']}" . (!empty($c['siret']) ? "\nSIRET : {$c['siret']}" : '') . "\nReprésentant : {$c['representant']}\nRéférent : {$c['referent']}")
        . "\nAdresse : {$c['adresse']}, {$c['cp']} {$c['ville']}\nE-mail : {$c['email']}\nTéléphone : " . ($c['tel'] ?: '—')
        . ($dem['site_adresse'] ? "\nSite suivi : {$dem['site_adresse']}" : '') . ($dem['lieu_label'] ? "\nLieu d'intervention : {$dem['lieu_label']}" : '')
        . "\nBesoin : " . (empty($calc['abonnement']) ? 'prestation ponctuelle (sans abonnement)' : ($calc['demarrage_ht'] > 0 ? 'recensement et diagnostic initial + abonnement de suivi annuel' : 'abonnement de suivi annuel seul')) . "\nNombre d'arbres : {$calc['nb']}\nDate de début souhaitée : " . date_fr($dem['date_debut'])
        . ($dem['profil'] !== 'collectivite' ? "\nPaiement : " . (empty($calc['abonnement']) ? 'carte bancaire' : $dem['paiement']) : '')
        . ($dem['contraintes'] ? "\nContraintes : {$dem['contraintes']}" : '')
        . ($dem['execution_anticipee'] ? "\nDemande d'exécution avant la fin du délai de rétractation : oui" : '')
        . ($verifs ? "\n\nÀ VÉRIFIER AVANT D'ENVOYER LE DEVIS :\n- " . implode("\n- ", $verifs) : '')
        . (!empty($GLOBALS['arbo_rapprochement']) ? "\n\nÀ VÉRIFIER : la demande a été rattachée à une fiche client Pennylane existante (" . $GLOBALS['arbo_rapprochement'] . "). Si ce n'est pas la même personne, crée une nouvelle fiche et change le client du devis." : '')
        . "\n\nEstimation recalculée :\n$resume" . ($dem['message'] ? "\n\nMessage du client :\n{$dem['message']}" : '');
    $sujet = $erreur ? "[Arbogestion] Demande n° $id à traiter à la main – " . nom_client($dem) : "[Arbogestion] Devis $numero à relire et envoyer – " . nom_client($dem);
    $texte = ($erreur
        ? "La demande a été enregistrée mais le devis n'a pas pu être créé automatiquement dans Pennylane.\nMotif : $erreur\n\n"
        : "Un devis BROUILLON a été créé dans Pennylane (n° $numero), avec " . (empty($calc['abonnement']) ? 'les conditions particulières et les CGV' : 'le contrat d\'abonnement et les CGV') . " en annexe.\nÀ faire : ouvrir Pennylane > Facturation > Devis, relire, puis l'envoyer au client pour signature.\n\n")
        . $infos;
    envoyer_mail((string)cfg('mail_kevin'), $sujet, $texte);
    envoyer_mail($c['email'], 'Arbogestion – Votre demande de devis est bien reçue',
        "Bonjour,\n\nNous avons bien reçu votre demande d'estimation (référence $id). Nous vérifions les informations et vous adressons votre devis et votre contrat par courriel, à signer en ligne, sous 48 heures ouvrées.\n\nRécapitulatif de votre estimation :\n\n" . texte_estimation($dem, $calc, true) . "\n\nCette estimation est indicative ; le devis fait foi.\n\nBien cordialement,\nArbogestion – arboriste conseil",
        (string)cfg('mail_kevin'));
    return ['id' => $id, 'pennylane' => $erreur === null];
}
