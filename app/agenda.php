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
    $total = $n * $hparjour / max(1, (int)($T['arbresParJour'] ?? 50))
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

/** Plages occupées d'une journée (heures décimales, trajet compris). */
function plages_occupees(string $date, int $sauf = 0): array {
    $s = db()->prepare("SELECT * FROM interventions WHERE debut LIKE ? AND id <> ? AND statut IN ('" . implode("','", RDV_ACTIFS) . "')");
    $s->execute([$date . '%', $sauf]);
    $p = [];
    foreach ($s->fetchAll() as $r) { $h = (float)date('G', strtotime($r['debut'])) + (int)date('i', strtotime($r['debut'])) / 60; $p[] = [$h - (float)$r['trajet_h'], $h + (float)$r['duree_h'] + (float)$r['trajet_h'], $r]; }
    return $p;
}

/** Conflits d'une intervention placée au créneau $debut. */
function conflits(array $r, string $debut): array {
    $h = (float)date('G', strtotime($debut)) + (int)date('i', strtotime($debut)) / 60;
    $a = $h - (float)$r['trajet_h']; $b = $h + (float)$r['duree_h'] + (float)$r['trajet_h'];
    $c = [];
    foreach (plages_occupees(substr($debut, 0, 10), (int)$r['id']) as [$x, $y, $o]) { if ($a < $y && $x < $b) { $c[] = $o; } }
    return $c;
}

/** Premier créneau libre (jours ouvrés), à partir de la date minimale, en tenant compte du trajet. */
function creneau_suggere(array $r, ?string $apres = null): ?string {
    $d = max((string)$r['date_min'] ?: date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+1 day')), (string)$apres);
    $besoin = (float)$r['duree_h'] + 2 * (float)$r['trajet_h'];
    for ($i = 0; $i < 90; $i++, $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if ((int)date('N', strtotime($d)) > 5) { continue; }
        $occ = plages_occupees($d);
        usort($occ, fn($u, $v) => $u[0] <=> $v[0]);
        for ($dep = JOUR_DEBUT; $dep + $besoin <= JOUR_FIN + 0.001; $dep += 0.5) {
            $libre = true;
            foreach ($occ as [$x, $y]) { if ($dep < $y && $x < $dep + $besoin) { $libre = false; break; } }
            if ($libre) {
                $arr = $dep + (float)$r['trajet_h']; $arr = ceil($arr * 4) / 4;
                return $d . ' ' . sprintf('%02d:%02d', (int)$arr, (int)round(($arr - (int)$arr) * 60));
            }
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
    foreach ($rows as $r) {
        $dep = date('Y-m-d H:i', (int)(strtotime($r['debut']) - (float)$r['trajet_h'] * 3600));
        $ret = date('Y-m-d H:i', (int)(strtotime($r['debut']) + ((float)$r['duree_h'] + (float)$r['trajet_h']) * 3600));
        $prefixe = ['pre' => '[Pré-planifié] ', 'planifie' => '[À proposer] ', 'propose' => '[Attente client] ', 'confirme' => '', 'realise' => '[Réalisé] '][$r['statut']] ?? '';
        $o .= "BEGIN:VEVENT\r\nUID:arbo-rdv-{$r['id']}@arbogestion.fr\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\nDTSTART:" . $f($dep) . "\r\nDTEND:" . $f($ret) . "\r\n"
            . 'SUMMARY:' . $esc($prefixe . $r['client'] . ' – ' . $r['nb_arbres'] . ' arbres') . "\r\n"
            . 'LOCATION:' . $esc($r['adresse']) . "\r\n"
            . 'DESCRIPTION:' . $esc($r['titre'] . ((int)$r['jours_total'] > 1 ? " (jour {$r['jour_num']}/{$r['jours_total']})" : '') . "\nSur place : " . heure_fr($r['debut']) . ' – ' . heure_fr((string)rdv_fin($r)) . ' (trajet ' . duree_fr((float)$r['trajet_h']) . ' aller)' . ($r['tel'] ? "\nTél. " . $r['tel'] : '') . ($r['notes'] ? "\n" . $r['notes'] : '')) . "\r\n"
            . 'STATUS:' . ($r['statut'] === 'confirme' || $r['statut'] === 'realise' ? 'CONFIRMED' : 'TENTATIVE') . "\r\nEND:VEVENT\r\n";
    }
    return $o . "END:VCALENDAR\r\n";
}
