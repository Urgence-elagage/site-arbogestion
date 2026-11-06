<?php
/*
 * Agenda des interventions (espace connecté).
 * Une intervention = une journée (ou demi-journée) sur place, créée automatiquement à la signature d'un devis
 * qui comporte du terrain (recensement, diagnostic, résistographe). Durée estimée d'après la grille
 * (50 arbres par journée de 7 h) et temps de trajet depuis Cambes.
 * Statuts : a_planifier → pre (pré-planifié, en attente) → planifie → propose (mail au client) → confirme → realise.
 */
declare(strict_types=1);
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/demande.php'; // http_json()

const RDV_STATUTS = [
    'a_planifier' => ['À planifier', ''],
    'pre' => ['Pré-planifié', 'p-a'],
    'planifie' => ['Planifié', 'p-att'],
    'propose' => ['Proposé au client', 'p-att'],
    'confirme' => ['Confirmé par le client', 'p-ok'],
    'refuse' => ['Date refusée par le client', 'p-err'],
    'realise' => ['Réalisé', 'p-ok'],
    'annule' => ['Annulé', ''],
];
const RDV_ACTIFS = ['pre', 'planifie', 'propose', 'confirme', 'realise'];
const JOUR_DEBUT = 7.5;   // départ au plus tôt de Cambes (7 h 30)
const JOUR_FIN = 18.5;    // retour au plus tard (18 h 30)

function installer_agenda(): void {
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    $pdo = db();
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $txt = $mysql ? 'MEDIUMTEXT' : 'TEXT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS interventions (
        id $id,
        demande_id INT NULL,
        jour_num INT NOT NULL DEFAULT 1,
        jours_total INT NOT NULL DEFAULT 1,
        titre VARCHAR(255) NOT NULL,
        client VARCHAR(255) NOT NULL,
        email VARCHAR(255) NULL,
        tel VARCHAR(60) NULL,
        adresse VARCHAR(400) NULL,
        nb_arbres INT NOT NULL DEFAULT 0,
        duree_h DECIMAL(5,2) NOT NULL DEFAULT 2,
        trajet_h DECIMAL(5,2) NOT NULL DEFAULT 0,
        km DECIMAL(7,1) NULL,
        date_min VARCHAR(10) NULL,
        debut VARCHAR(16) NULL,
        statut VARCHAR(20) NOT NULL DEFAULT 'a_planifier',
        propose_le VARCHAR(19) NULL,
        reponse_le VARCHAR(19) NULL,
        reponse_msg $txt NULL,
        notes $txt NULL,
        cree_le VARCHAR(19) NOT NULL,
        maj_le VARCHAR(19) NULL
    )" . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
    foreach (['lat' => 'DECIMAL(9,6) NULL', 'lon' => 'DECIMAL(9,6) NULL'] as $col => $type) {
        try { $pdo->query("SELECT $col FROM interventions LIMIT 1"); }
        catch (Throwable $e) { $pdo->exec("ALTER TABLE interventions ADD COLUMN $col $type"); }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS trajets (cle VARCHAR(80) NOT NULL PRIMARY KEY, duree_h DECIMAL(5,2) NOT NULL, km DECIMAL(7,1) NULL, estime INT NOT NULL DEFAULT 0, calcule_le VARCHAR(19) NOT NULL)"
        . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
}

/* ---------- Trajets enchaînés : d'un chantier au suivant, depuis Cambes le matin, retour à Cambes le soir ---------- */
function depart_coord(): array { $d = tarifs()['depart'] ?? []; return ['lat' => (float)($d['lat'] ?? 44.6156), 'lon' => (float)($d['lon'] ?? 1.9419), 'nom' => (string)($d['nom'] ?? 'Cambes')]; }

/** Coordonnées du chantier (Base Adresse Nationale), mémorisées à la première demande. */
function rdv_coord(array $r): ?array {
    static $memo = [];
    if (array_key_exists((int)$r['id'], $memo)) { return $memo[(int)$r['id']]; }
    return $memo[(int)$r['id']] = rdv_coord_lire($r);
}
function rdv_coord_lire(array $r): ?array {
    if ($r['lat'] !== null && $r['lat'] !== '' && $r['lon'] !== null) { return (float)$r['lat'] == 0.0 && (float)$r['lon'] == 0.0 ? null : ['lat' => (float)$r['lat'], 'lon' => (float)$r['lon']]; }
    static $essais = 0;
    if (trim((string)$r['adresse']) === '' || ++$essais > 6) { return null; }
    $d = http_json('https://api-adresse.data.gouv.fr/search/?limit=1&q=' . rawurlencode((string)$r['adresse']));
    $f = $d['features'][0] ?? null;
    $c = ($f && ($f['properties']['score'] ?? 0) >= 0.4) ? ['lat' => (float)$f['geometry']['coordinates'][1], 'lon' => (float)$f['geometry']['coordinates'][0]] : null;
    if ($d !== null) { rdv_maj((int)$r['id'], ['lat' => $c['lat'] ?? 0, 'lon' => $c['lon'] ?? 0]); } // 0/0 = adresse introuvable, on ne recherche plus
    return $c;
}

function distance_vol(array $a, array $b): float {
    $t = M_PI / 180; $dla = ($b['lat'] - $a['lat']) * $t; $dlo = ($b['lon'] - $a['lon']) * $t;
    $x = sin($dla / 2) ** 2 + cos($a['lat'] * $t) * cos($b['lat'] * $t) * sin($dlo / 2) ** 2;
    return 2 * 6371 * asin(sqrt($x));
}

/** Durée de route (heures, au quart d'heure supérieur) entre deux points : itinéraire IGN, mis en cache. */
function duree_route(?array $a, ?array $b, float $defaut): float {
    if (!$a || !$b) { return $defaut; }
    if (distance_vol($a, $b) < 0.3) { return 0.0; }
    $cle = sprintf('%.4f,%.4f>%.4f,%.4f', $a['lat'], $a['lon'], $b['lat'], $b['lon']);
    static $mem = [], $appels = 0;
    if (isset($mem[$cle])) { return $mem[$cle]; }
    $s = db()->prepare('SELECT duree_h, estime, calcule_le FROM trajets WHERE cle = ?'); $s->execute([$cle]); $c = $s->fetch();
    if ($c && (!(int)$c['estime'] || strtotime((string)$c['calcule_le']) > time() - 86400)) { return $mem[$cle] = (float)$c['duree_h']; }
    $h = null; $km = null;
    if (++$appels <= 12) {
        $d = http_json('https://data.geopf.fr/navigation/itineraire?resource=bdtopo-osrm&profile=car&optimization=fastest&getBbox=false&distanceUnit=kilometer&timeUnit=hour&start=' . $a['lon'] . ',' . $a['lat'] . '&end=' . $b['lon'] . ',' . $b['lat']);
        if (isset($d['duration']) && is_numeric($d['duration'])) { $h = (float)$d['duration']; $km = isset($d['distance']) ? (float)$d['distance'] : null; }
    }
    $estime = $h === null;
    if ($estime) { $km = distance_vol($a, $b) * 1.3; $h = $km / 65; }
    $h = round(ceil(max($h, 0.08) * 4) / 4, 2);
    $pdo = db();
    $pdo->prepare('DELETE FROM trajets WHERE cle = ?')->execute([$cle]);
    $pdo->prepare('INSERT INTO trajets (cle, duree_h, km, estime, calcule_le) VALUES (?,?,?,?,?)')->execute([$cle, $h, $km !== null ? round($km, 1) : null, $estime ? 1 : 0, date('Y-m-d H:i:s')]);
    return $mem[$cle] = $h;
}

function heure_dec(string $dt): float { $t = strtotime($dt); return (int)date('G', $t) + (int)date('i', $t) / 60; }

/**
 * Déroulé d'une journée : chantiers placés dans l'ordre, avec le trajet qui précède chacun
 * (depuis Cambes ou depuis le chantier précédent) et le retour à Cambes après le dernier.
 * $cand / $cand_debut : intervention que l'on essaie de placer (test d'un créneau).
 */
function journee(string $date, ?array $cand = null, string $cand_debut = ''): array {
    static $cache = [];
    if (!isset($cache[$date])) {
        $s = db()->prepare("SELECT * FROM interventions WHERE debut LIKE ? AND statut IN ('" . implode("','", RDV_ACTIFS) . "') ORDER BY debut");
        $s->execute([$date . '%']); $cache[$date] = $s->fetchAll();
    }
    $l = array_values(array_filter($cache[$date], fn($r) => !$cand || (int)$r['id'] !== (int)$cand['id']));
    if ($cand) { $cand['debut'] = $cand_debut; $cand['_cand'] = true; $l[] = $cand; usort($l, fn($u, $v) => strcmp($u['debut'], $v['debut'])); }
    $base = depart_coord(); $prec = null; $out = []; $n = count($l);
    foreach ($l as $i => $r) {
        $co = rdv_coord($r);
        $avant = $prec ? duree_route(rdv_coord($prec), $co, (float)$r['trajet_h']) : duree_route($base, $co, (float)$r['trajet_h']);
        $h = heure_dec($r['debut']);
        $out[] = ['r' => $r, 'h' => $h, 'fin' => $h + (float)$r['duree_h'], 'avant' => $avant, 'depuis' => $prec ? (string)$prec['client'] : $base['nom'],
                  'retour' => $i === $n - 1 ? duree_route($co, $base, (float)$r['trajet_h']) : 0.0];
        $prec = $r;
    }
    return $out;
}


function rdv(int $id): ?array { installer_agenda(); $s = db()->prepare('SELECT * FROM interventions WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function rdv_maj(int $id, array $c): void {
    $c['maj_le'] = date('Y-m-d H:i:s');
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($c)));
    db()->prepare("UPDATE interventions SET $set WHERE id = ?")->execute([...array_values($c), $id]);
}
function rdv_fin(array $r): ?string { return $r['debut'] ? date('Y-m-d H:i', (int)(strtotime($r['debut']) + (float)$r['duree_h'] * 3600)) : null; }
function heure_fr(string $dt): string { $t = strtotime($dt); return (int)date('G', $t) . ' h ' . date('i', $t); }
function duree_fr(float $h): string { $m = (int)round($h * 60); return intdiv($m, 60) ? intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT) : '') : ($m % 60) . ' min'; }
function jour_fr(string $d): string {
    $j = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int)date('w', strtotime($d))];
    return $j . ' ' . date_fr(substr($d, 0, 10));
}
function rdv_jeton(int $id): string { return $id . '.' . substr(hash_hmac('sha256', 'rdv-' . $id, (string)cfg('secret')), 0, 32); }
function rdv_jeton_ok(string $t): ?int { return preg_match('/^(\d+)\.[a-f0-9]{32}$/', $t, $m) && hash_equals(rdv_jeton((int)$m[1]), $t) ? (int)$m[1] : null; }
function agenda_ics_cle(): string { return substr(hash_hmac('sha256', 'agenda-ics', (string)cfg('secret')), 0, 32); }

/** Temps sur place estimé (heures) pour un devis, et nombre de journées. */
function estimer_terrain(array $calc, array $est): array {
    $T = tarifs();
    $n = (int)($calc['nb'] ?? $est['nb'] ?? 0);
    $hparjour = (float)($T['heuresParJour'] ?? 7);
    // Recensement initial avec prévisionnel fait sur place : moins d'arbres par journée qu'un diagnostic ponctuel
    $parjour = !empty($calc['abonnement']) ? (int)($T['arbresParJourDemarrage'] ?? $T['arbresParJour'] ?? 40) : (int)($T['arbresParJour'] ?? 50);
    $total = $n * $hparjour / max(1, $parjour)
        + (int)($est['rb'] ?? 0) * 0.25
        + (int)($est['rh'] ?? 0) * (float)($T['resistographe']['houppierHeuresParTest'] ?? 2);
    $total = max((float)($T['ponctuel']['forfaitHeures'] ?? 2), $total);
    $jours = max((int)($calc['jours'] ?? 1), (int)ceil($total / $hparjour - 0.001), 1);
    return ['total_h' => round($total, 2), 'jours' => $jours, 'par_jour' => round(ceil($total / $jours * 4) / 4, 2)];
}

/** Crée les interventions d'un devis signé (une par journée sur place). Ne fait rien si elles existent déjà ou s'il n'y a pas de terrain. */
function agenda_creer_depuis_demande(array $row): int {
    installer_agenda();
    $s = db()->prepare('SELECT COUNT(*) FROM interventions WHERE demande_id = ?'); $s->execute([(int)$row['id']]);
    if ((int)$s->fetchColumn() > 0) { return 0; }
    $dem = json_decode($row['donnees'], true) ?: []; $calc = json_decode($row['calcul'], true) ?: [];
    $est = $dem['estimation'] ?? [];
    if (empty($calc['demarrage_ht']) || (float)$calc['demarrage_ht'] <= 0) { return 0; } // abonnement seul : rien sur le terrain
    $e = estimer_terrain($calc, $est);
    $km = isset($est['km']) && is_numeric($est['km']) ? (float)$est['km'] : null;
    $trajet = isset($est['duree_h']) && is_numeric($est['duree_h']) && $est['duree_h'] > 0 ? (float)$est['duree_h'] : ($km !== null ? $km / 65 : 0.5);
    $trajet = round(ceil($trajet * 4) / 4, 2);
    $c = $dem['client'] ?? [];
    $adresse = ($dem['site_adresse'] ?? '') ?: (($dem['lieu_label'] ?? '') ?: trim(($c['adresse'] ?? '') . ', ' . ($c['cp'] ?? '') . ' ' . ($c['ville'] ?? ''), ' ,'));
    $min = date('Y-m-d', strtotime('+1 day'));
    if (($dem['profil'] ?? '') === 'particulier' && empty($dem['execution_anticipee'])) { $min = date('Y-m-d', strtotime('+15 days')); } // délai de rétractation
    // La date de début saisie sur le site est préremplie (1er du mois suivant) : simple indication, pas une contrainte
    $souhait = (!empty($dem['date_debut']) && $dem['date_debut'] !== date('Y-m-d', strtotime('first day of next month', strtotime((string)$row['cree_le'])))) ? 'Début souhaité par le client : ' . date_fr($dem['date_debut']) . '. ' : '';
    $abo = !empty($calc['abonnement']);
    $titre = ($abo ? 'Recensement et diagnostic initial' : 'Diagnostic ponctuel') . ' – ' . (int)($calc['nb'] ?? 0) . ' arbres';
    $ins = db()->prepare('INSERT INTO interventions (demande_id, jour_num, jours_total, titre, client, email, tel, adresse, nb_arbres, duree_h, trajet_h, km, date_min, statut, notes, cree_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $reste = (int)($calc['nb'] ?? 0);
    for ($j = 1; $j <= $e['jours']; $j++) {
        $nbj = $e['jours'] > 1 ? (int)min($reste, (int)ceil(((int)($calc['nb'] ?? 0)) / $e['jours'])) : (int)($calc['nb'] ?? 0);
        $reste -= $nbj;
        $ins->execute([(int)$row['id'], $j, $e['jours'], $titre, $row['nom_client'], $row['email'], $c['tel'] ?? '', $adresse, $nbj, $e['par_jour'], $trajet, $km, $min, 'a_planifier',
            trim(($row['profil'] === 'collectivite' ? 'Collectivité : attendre le bon de commande avant d’intervenir. ' : '') . $souhait . ($dem['contraintes'] ?? '')), date('Y-m-d H:i:s')]);
    }
    return $e['jours'];
}

/** Crée les interventions manquantes pour les devis déjà signés. */
function agenda_synchroniser(): void {
    installer_agenda();
    $rows = db()->query("SELECT d.* FROM demandes d WHERE d.statut IN ('signe_ponctuel','signe_paiement_demande','signe_mandat_demande','signe_attente_bdc','bdc_recu')
        AND NOT EXISTS (SELECT 1 FROM interventions i WHERE i.demande_id = d.id)")->fetchAll();
    foreach ($rows as $r) { try { agenda_creer_depuis_demande($r); } catch (Throwable $e) { journal('Agenda, demande ' . $r['id'] . ' : ' . $e->getMessage()); } }
}

/** Interventions en conflit si $r commence à $debut (trajets enchaînés compris). */
function conflits(array $r, string $debut): array {
    $j = journee(substr($debut, 0, 10), $r, $debut); $c = [];
    foreach ($j as $k => $x) {
        if ($k === 0) { continue; }
        $p = $j[$k - 1];
        if ($p['fin'] + $x['avant'] > $x['h'] + 0.001 && (!empty($x['r']['_cand']) || !empty($p['r']['_cand']))) { $c[] = !empty($x['r']['_cand']) ? $p['r'] : $x['r']; }
    }
    return $c;
}

/** Le créneau tient-il dans la journée (départ ≥ 7 h 30, retour ≤ 18 h 30, sans chevauchement) ? */
function creneau_possible(array $r, string $debut): bool {
    $j = journee(substr($debut, 0, 10), $r, $debut);
    foreach ($j as $k => $x) {
        if (empty($x['r']['_cand'])) { continue; }
        if ($k === 0 && $x['h'] - $x['avant'] < JOUR_DEBUT - 0.001) { return false; }
        if ($k > 0 && $j[$k - 1]['fin'] + $x['avant'] > $x['h'] + 0.001) { return false; }
        if (isset($j[$k + 1]) && $x['fin'] + $j[$k + 1]['avant'] > $j[$k + 1]['h'] + 0.001) { return false; }
        $der = end($j);
        if ($der['fin'] + $der['retour'] > JOUR_FIN + 0.001) { return false; }
        return true;
    }
    return false;
}

/** Premier créneau libre (jours ouvrés) à partir de la date minimale, trajets enchaînés compris. */
function creneau_suggere(array $r, ?string $apres = null): ?string {
    $d = max((string)$r['date_min'] ?: date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+1 day')), (string)$apres);
    for ($i = 0; $i < 60; $i++, $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if ((int)date('N', strtotime($d)) > 5) { continue; }
        $occ = journee($d);
        // Heures d'arrivée à tester : au plus tôt le matin, puis juste après chaque chantier déjà placé
        $essais = [ceil((JOUR_DEBUT + duree_route(depart_coord(), rdv_coord($r), (float)$r['trajet_h'])) * 4) / 4];
        foreach ($occ as $x) { $essais[] = ceil(($x['fin'] + duree_route(rdv_coord($x['r']), rdv_coord($r), (float)$r['trajet_h'])) * 4) / 4; }
        sort($essais);
        foreach ($essais as $h) {
            if ($h + (float)$r['duree_h'] > JOUR_FIN) { continue; }
            $deb = $d . ' ' . sprintf('%02d:%02d', (int)$h, (int)round(($h - (int)$h) * 60));
            if (creneau_possible($r, $deb)) { return $deb; }
        }
    }
    return null;
}

/** Courriel au client : proposition de date avec boutons de réponse. */
function proposer_au_client(int $id): void {
    $r = rdv($id);
    if (!$r || !$r['debut']) { throw new RuntimeException('Intervention non planifiée.'); }
    if (!filter_var((string)$r['email'], FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Pas d’adresse e-mail pour ce client.'); }
    $lien = rtrim((string)cfg('url_site', 'https://arbogestion.fr'), '/') . '/rdv.php?t=' . rdv_jeton($id);
    $multi = (int)$r['jours_total'] > 1 ? ' (journée ' . (int)$r['jour_num'] . ' sur ' . (int)$r['jours_total'] . ')' : '';
    envoyer_mail((string)$r['email'], 'Arbogestion – Proposition de date d’intervention',
        "Bonjour,\n\nNous vous proposons la date suivante pour notre intervention$multi :\n\n"
        . '- Date : ' . jour_fr($r['debut']) . "\n- Arrivée sur place : vers " . heure_fr($r['debut']) . "\n- Durée estimée : " . duree_fr((float)$r['duree_h']) . "\n- Lieu : " . $r['adresse']
        . "\n\nMerci de nous indiquer si cette date vous convient.\n\n" . bouton_mail('Je confirme ce rendez-vous', $lien . '&r=ok')
        . "\n\nCette date ne vous convient pas ? Indiquez-nous vos disponibilités : " . $lien . '&r=autre'
        . "\n\nBien cordialement,\nArbogestion – arboriste conseil", (string)cfg('mail_kevin'));
    rdv_maj($id, ['statut' => 'propose', 'propose_le' => date('Y-m-d H:i:s'), 'reponse_le' => null, 'reponse_msg' => null]);
}

/** Réponse du client (page rdv.php). */
function reponse_client(int $id, bool $ok, string $msg): void {
    $r = rdv($id);
    if (!$r) { return; }
    rdv_maj($id, ['statut' => $ok ? 'confirme' : 'refuse', 'reponse_le' => date('Y-m-d H:i:s'), 'reponse_msg' => $msg]);
    envoyer_mail((string)cfg('mail_kevin'), '[Arbogestion] ' . ($ok ? 'Rendez-vous confirmé' : 'Date refusée') . ' – ' . $r['client'] . ' (' . jour_fr($r['debut']) . ')',
        ($ok ? "Le client a confirmé l'intervention du " . jour_fr($r['debut']) . ' à ' . heure_fr($r['debut']) . '.' : "Le client ne peut pas le " . jour_fr($r['debut']) . " : l'intervention est à replanifier dans l'agenda.")
        . ($msg !== '' ? "\n\nSon message :\n$msg" : '') . "\n\n" . $r['titre'] . "\n" . $r['adresse'] . ($r['tel'] ? "\nTél. " . $r['tel'] : '') . "\n\nAgenda : " . rtrim((string)cfg('url_site', 'https://arbogestion.fr'), '/') . '/espace/?p=agenda');
}

/** Flux iCalendar (abonnement depuis le calendrier du téléphone). */
function agenda_ics(): string {
    installer_agenda();
    $rows = db()->query("SELECT * FROM interventions WHERE debut IS NOT NULL AND statut IN ('" . implode("','", RDV_ACTIFS) . "') AND debut >= '" . date('Y-m-d', strtotime('-60 days')) . "'")->fetchAll();
    $esc = fn($s) => str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], (string)$s);
    $tz = new DateTimeZone('Europe/Paris'); $utc = new DateTimeZone('UTC');
    $f = fn($dt) => (new DateTime($dt, $tz))->setTimezone($utc)->format('Ymd\THis\Z');
    $o = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Arbogestion//Agenda//FR\r\nCALSCALE:GREGORIAN\r\nX-WR-CALNAME:Arbogestion – interventions\r\nX-WR-TIMEZONE:Europe/Paris\r\n";
    $enchaine = [];
    foreach (array_unique(array_map(fn($r) => substr((string)$r['debut'], 0, 10), $rows)) as $d) { foreach (journee($d) as $x) { $enchaine[(int)$x['r']['id']] = $x; } }
    foreach ($rows as $r) {
        $x = $enchaine[(int)$r['id']] ?? ['avant' => (float)$r['trajet_h'], 'retour' => (float)$r['trajet_h'], 'depuis' => 'Cambes'];
        $dep = date('Y-m-d H:i', (int)(strtotime($r['debut']) - $x['avant'] * 3600));
        $ret = date('Y-m-d H:i', (int)(strtotime($r['debut']) + ((float)$r['duree_h'] + $x['retour']) * 3600));
        $prefixe = ['pre' => '[Pré-planifié] ', 'planifie' => '[À proposer] ', 'propose' => '[Attente client] ', 'confirme' => '', 'realise' => '[Réalisé] '][$r['statut']] ?? '';
        $o .= "BEGIN:VEVENT\r\nUID:arbo-rdv-{$r['id']}@arbogestion.fr\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\nDTSTART:" . $f($dep) . "\r\nDTEND:" . $f($ret) . "\r\n"
            . 'SUMMARY:' . $esc($prefixe . $r['client'] . ' – ' . $r['nb_arbres'] . ' arbres') . "\r\n"
            . 'LOCATION:' . $esc($r['adresse']) . "\r\n"
            . 'DESCRIPTION:' . $esc($r['titre'] . ((int)$r['jours_total'] > 1 ? " (jour {$r['jour_num']}/{$r['jours_total']})" : '') . "\nSur place : " . heure_fr($r['debut']) . ' – ' . heure_fr((string)rdv_fin($r)) . ' (trajet ' . duree_fr($x['avant']) . ' depuis ' . $x['depuis'] . ($x['retour'] > 0 ? ', retour à Cambes ' . duree_fr($x['retour']) : '') . ')' . ($r['tel'] ? "\nTél. " . $r['tel'] : '') . ($r['notes'] ? "\n" . $r['notes'] : '')) . "\r\n"
            . 'STATUS:' . ($r['statut'] === 'confirme' || $r['statut'] === 'realise' ? 'CONFIRMED' : 'TENTATIVE') . "\r\nEND:VEVENT\r\n";
    }
    return $o . "END:VCALENDAR\r\n";
}
