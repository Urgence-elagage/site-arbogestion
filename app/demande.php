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
    foreach (['prenom', 'nom', 'denomination', 'forme', 'siren', 'adresse', 'cp', 'ville', 'representant', 'referent', 'habilitation', 'email', 'tel'] as $k) {
        $cl[$k] = nettoyer($c[$k] ?? '', $k === 'adresse' ? 300 : 160);
    }
    $cl['siren'] = preg_replace('/\D/', '', $cl['siren']);
    $cl['cp'] = preg_replace('/[^0-9A-Za-z ]/', '', $cl['cp']);
    if (!filter_var($cl['email'], FILTER_VALIDATE_EMAIL)) { throw new ErreurDemande('Adresse e-mail invalide.'); }
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
        'abonnement' => !empty($e['abonnement']),
    ];
    if ($est['nb'] < 1 || $est['nb'] > 10000) { throw new ErreurDemande("Nombre d'arbres invalide."); }
    if (!$est['demarrage'] && !$est['abonnement']) { throw new ErreurDemande('Choisissez une formule : prestation ponctuelle, démarrage + suivi, ou suivi seul.'); }
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

function nom_client(array $dem): string {
    $c = $dem['client'];
    return $dem['profil'] === 'particulier' ? trim($c['prenom'] . ' ' . $c['nom']) : $c['denomination'];
}

/** Retrouve ou crée le client dans Pennylane. */
function pl_client(array $dem): string {
    $c = $dem['client'];
    $adresse = ['address' => $c['adresse'], 'postal_code' => $c['cp'], 'city' => $c['ville'], 'country_alpha2' => 'FR'];
    if ($dem['profil'] === 'particulier') {
        $ref = 'arbo-' . substr(sha1(strtolower($c['email'])), 0, 20);
        $f = pl_filtre([['field' => 'external_reference', 'operator' => 'eq', 'value' => $ref]]);
        $l = pennylane('GET', '/customers?filter=' . $f . '&filters=' . $f);
        if (!empty($l['items'][0]['id'])) { return (string)$l['items'][0]['id']; }
        $r = pennylane('POST', '/individual_customers', [
            'first_name' => $c['prenom'], 'last_name' => $c['nom'], 'emails' => [$c['email']],
            'phone' => $c['tel'] ?: null, 'billing_address' => $adresse, 'external_reference' => $ref,
            'payment_conditions' => 'upon_receipt', 'billing_language' => 'fr_FR',
        ]);
    } else {
        $f = pl_filtre([['field' => 'reg_no', 'operator' => 'eq', 'value' => $c['siren']]]);
        $l = pennylane('GET', '/customers?filter=' . $f . '&filters=' . $f);
        if (!empty($l['items'][0]['id'])) { return (string)$l['items'][0]['id']; }
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

function prix6(float $v): string { return number_format($v, 6, '.', ''); }

/** Lignes du devis Pennylane (démarrage + abonnement 1re année). */
function lignes_devis(array $dem, array $calc): array {
    $sections = []; $lignes = []; $rang = 0;
    if ($calc['lignes_demarrage']) {
        $sections[] = $calc['abonnement'] ? ['title' => 'Démarrage', 'description' => 'Prestations ponctuelles, facturées après leur réalisation', 'rank' => ++$rang]
            : ['title' => 'Prestation ponctuelle', 'description' => 'Sans abonnement, facturée après sa réalisation', 'rank' => ++$rang];
        foreach ($calc['lignes_demarrage'] as $l) {
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

function texte_estimation(array $dem, array $calc): string {
    $T = tarifs(); $t = [];
    foreach ($calc['lignes_demarrage'] as $l) { $t[] = '- ' . $l['label'] . ' : ' . str_replace(['.'], [','], (string)$l['qte']) . ' ' . $l['unite'] . ' × ' . eur($l['pu']) . ' = ' . eur(round($l['qte'] * $l['pu'], 2)) . ' HT'; }
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

    $c = $dem['client'];
    $infos = "Profil : " . PROFILS[$dem['profil']]['nom'] . "\nClient : " . nom_client($dem)
        . ($dem['profil'] === 'particulier' ? '' : "\nSIREN : {$c['siren']}\nReprésentant : {$c['representant']}\nRéférent : {$c['referent']}")
        . "\nAdresse : {$c['adresse']}, {$c['cp']} {$c['ville']}\nE-mail : {$c['email']}\nTéléphone : " . ($c['tel'] ?: '—')
        . ($dem['site_adresse'] ? "\nSite suivi : {$dem['site_adresse']}" : '') . ($dem['lieu_label'] ? "\nLieu d'intervention : {$dem['lieu_label']}" : '')
        . "\nBesoin : " . (empty($calc['abonnement']) ? 'prestation ponctuelle (sans abonnement)' : ($calc['demarrage_ht'] > 0 ? 'démarrage + suivi annuel' : 'suivi annuel seul')) . "\nNombre d'arbres : {$calc['nb']}\nDate de début souhaitée : " . date_fr($dem['date_debut'])
        . ($dem['profil'] !== 'collectivite' ? "\nPaiement : {$dem['paiement']}" : '')
        . ($dem['contraintes'] ? "\nContraintes : {$dem['contraintes']}" : '')
        . ($dem['execution_anticipee'] ? "\nDemande d'exécution avant la fin du délai de rétractation : oui" : '')
        . "\n\nEstimation recalculée :\n$resume" . ($dem['message'] ? "\n\nMessage du client :\n{$dem['message']}" : '');
    $sujet = $erreur ? "[Arbogestion] Demande n° $id à traiter à la main – " . nom_client($dem) : "[Arbogestion] Devis $numero à relire et envoyer – " . nom_client($dem);
    $texte = ($erreur
        ? "La demande a été enregistrée mais le devis n'a pas pu être créé automatiquement dans Pennylane.\nMotif : $erreur\n\n"
        : "Un devis BROUILLON a été créé dans Pennylane (n° $numero), avec " . (empty($calc['abonnement']) ? 'les conditions particulières et les CGV' : 'le contrat d\'abonnement et les CGV') . " en annexe.\nÀ faire : ouvrir Pennylane > Facturation > Devis, relire, puis l'envoyer au client pour signature.\n\n")
        . $infos;
    envoyer_mail((string)cfg('mail_kevin'), $sujet, $texte, $c['email']);
    envoyer_mail($c['email'], 'Arbogestion – Votre demande de devis est bien reçue',
        "Bonjour,\n\nNous avons bien reçu votre demande d'estimation (référence $id). Nous vérifions les informations et vous adressons votre devis et votre contrat par courriel, à signer en ligne, sous 48 heures ouvrées.\n\nRécapitulatif de votre estimation :\n$resume\n\nCette estimation est indicative ; le devis fait foi.\n\nBien cordialement,\nArbogestion – arboriste conseil\n133 rue Madeleine Brès, 46100 Cambes\nhttps://arbogestion.fr",
        (string)cfg('mail_kevin'));
    return ['id' => $id, 'pennylane' => $erreur === null];
}
