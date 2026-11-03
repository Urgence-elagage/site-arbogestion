<?php
/*
 * Arbogestion — fonctions communes du serveur (demandes de devis, contrats, Pennylane).
 * Ce dossier « app » est placé HORS de la partie publique du site.
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Paris');

const ARBO_APP = __DIR__;

function cfg(string $cle, $defaut = null) {
    static $c = null;
    if ($c === null) {
        $f = ARBO_APP . '/config.php';
        if (!is_file($f)) { throw new RuntimeException('config.php absent'); }
        $c = require $f;
    }
    return $c[$cle] ?? $defaut;
}

function journal(string $msg): void {
    $dir = ARBO_APP . '/storage';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    @file_put_contents($dir . '/journal.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (cfg('db_dsn')) { // utilisé pour les tests (SQLite)
            $pdo = new PDO(cfg('db_dsn'));
        } else {
            $pdo = new PDO('mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4',
                cfg('db_user'), cfg('db_pass'));
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        installer_base($pdo);
    }
    return $pdo;
}

function installer_base(PDO $pdo): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $txt = $mysql ? 'MEDIUMTEXT' : 'TEXT';
    $suffixe = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS demandes (
        id $id,
        cree_le VARCHAR(19) NOT NULL,
        profil VARCHAR(20) NOT NULL,
        statut VARCHAR(30) NOT NULL DEFAULT 'recue',
        nom_client VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        donnees $txt NOT NULL,
        calcul $txt NOT NULL,
        pl_client_id VARCHAR(40) NULL,
        pl_devis_id VARCHAR(40) NULL,
        pl_devis_numero VARCHAR(60) NULL,
        pl_abonnement_id VARCHAR(40) NULL,
        jeton VARCHAR(64) NULL,
        bon_commande VARCHAR(255) NULL,
        maj_le VARCHAR(19) NULL,
        notes $txt NULL
    )$suffixe");
    $pdo->exec("CREATE TABLE IF NOT EXISTS espace_admin (
        id $id,
        email VARCHAR(255) NOT NULL,
        mdp_hash VARCHAR(255) NOT NULL,
        totp_secret VARCHAR(64) NOT NULL,
        totp_dernier_pas BIGINT NOT NULL DEFAULT 0,
        secours $txt NOT NULL,
        cree_le VARCHAR(19) NOT NULL,
        maj_le VARCHAR(19) NULL
    )$suffixe");
    $pdo->exec("CREATE TABLE IF NOT EXISTS espace_tentatives (
        id $id,
        cle VARCHAR(80) NOT NULL,
        quand BIGINT NOT NULL
    )$suffixe");
}

/* ---------- Tarifs (fichier unique partagé avec le calculateur) ---------- */
function tarifs(): array {
    static $t = null;
    if ($t === null) {
        $f = dirname(ARBO_APP) . '/www/tarifs.json';
        $t = json_decode((string)file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
    }
    return $t;
}

const PROFILS = [
    'particulier'  => ['nom' => 'Particuliers',  'formule' => 'Formule Particuliers',  'max' => 500],
    'pro'          => ['nom' => 'Professionnels', 'formule' => 'Formule Professionnels', 'max' => 500],
    'collectivite' => ['nom' => 'Collectivités', 'formule' => 'Formule Collectivités', 'max' => 4000],
];

function palier(string $profil, int $n): ?array {
    foreach (tarifs()['paliers'][$profil] as $i => $p) {
        if ($n <= $p[0]) { return ['rang' => $i, 'max' => (int)$p[0], 'prix' => (float)$p[1]]; }
    }
    return null;
}

/*
 * Recalcule l'estimation côté serveur (mêmes règles que le calculateur du site).
 * $d : profil, nb, demarrage(bool), diag(bool), prev(bool), rb(int), rh(int), km(float|null), abonnement(bool)
 */
function calculer(array $d): array {
    $T = tarifs();
    $n = max(1, min(10000, (int)($d['nb'] ?? 1)));
    $mini = (float)$T['tauxHoraire'] * (float)$T['minimumHeures'];
    $res = ['nb' => $n, 'lignes_demarrage' => [], 'demarrage_ht' => 0.0, 'abonnement' => null, 'jours' => 0, 'houppier_sur_devis' => false];
    // Prix au nombre d'arbres, avec un minimum d'1 h au taux horaire : seuil calculé automatiquement
    $ligne_arbres = function (string $label, string $desc, float $pu) use ($n, $mini, &$res) {
        if ($n * $pu < $mini) {
            $res['lignes_demarrage'][] = ['label' => $label, 'desc' => $desc, 'qte' => 1, 'unite' => 'forfait', 'pu' => $mini];
        } else {
            $res['lignes_demarrage'][] = ['label' => $label, 'desc' => $desc, 'qte' => $n, 'unite' => 'arbre', 'pu' => $pu];
        }
    };
    $ponctuel = !empty($d['demarrage']) && empty($d['abonnement']);
    // Démarrage d'un abonnement : recensement, diagnostic ET prévisionnel toujours inclus (base du budget)
    if (!empty($d['demarrage']) && !empty($d['abonnement'])) { $d['diag'] = true; $d['prev'] = true; }
    // Sur place (prestation ponctuelle ou recensement de départ d'un abonnement) : même règle par journée
    if ($ponctuel || (!empty($d['demarrage']) && !empty($d['diag']))) {
        // Prestation ponctuelle : forfait sur place jusqu'à N arbres, puis prix par arbre supplémentaire ; préconisations comprises
        $P = $T['ponctuel'];
        $forfait = round((float)$P['forfaitHeures'] * (float)$T['tauxHoraire'], 2);
        $parJour = (int)$T['arbresParJour']; $inclus = (int)$P['arbresInclus'];
        $nbJours = (int)ceil($n / $parJour);
        // Chaque journée sur place : forfait de N h (jusqu'à X arbres), puis prix par arbre supplémentaire
        $sup = 0;
        for ($j = 1; $j <= $nbJours; $j++) { $sup += max(0, min($parJour, $n - ($j - 1) * $parJour) - $inclus); }
        $res['lignes_demarrage'][] = ['label' => $ponctuel ? 'Diagnostic ponctuel sur place' : 'Recensement et diagnostic sur place', 'desc' => ($ponctuel ? 'Recensement, diagnostic visuel et préconisations, compte rendu écrit' : 'Recensement et diagnostic initial de chaque arbre, saisi dans Grality'), 'qte' => $nbJours, 'unite' => 'journée', 'pu' => $forfait];
        if ($sup > 0) {
            $res['lignes_demarrage'][] = ['label' => 'Arbres supplémentaires', 'desc' => 'Arbres diagnostiqués en plus', 'qte' => $sup, 'unite' => 'arbre', 'pu' => (float)$P['prixArbreSupplementaire']];
        }
        $res['jours'] = (int)ceil($n / $T['arbresParJour']);
        $d['diag'] = false;
        if ($ponctuel) { $d['prev'] = false; }
    }
    if (!empty($d['demarrage'])) {
        if (!empty($d['diag'])) {
            $ligne_arbres('Recensement et diagnostic sur place', 'Localisation, identification et analyse sanitaire de chaque arbre, saisie dans Grality', (float)$T['diagnosticParArbre']);
            $res['jours'] = (int)ceil($n / $T['arbresParJour']);
        }
        if (!empty($d['prev'])) {
            $ligne_arbres("Prévisionnel d'intervention", 'Planification des interventions, calendrier et estimation budgétaire', (float)$T['previsionnelParArbre']);
        }
        $R = $T['resistographe'];
        $rb = max(0, min(500, (int)($d['rb'] ?? 0)));
        $rh = max(0, min(100, (int)($d['rh'] ?? 0)));
        if ($rb) { $res['lignes_demarrage'][] = ['label' => 'Test au résistographe au pied du tronc', 'desc' => 'Mesure de la résistance du bois à la base de l’arbre, remise des résultats', 'qte' => $rb, 'unite' => 'test', 'pu' => (float)$R['base']]; }
        if ($rh) {
            $puh = round((float)$R['houppierTauxHoraire'] * (float)$R['houppierHeuresParTest'], 2);
            $res['lignes_demarrage'][] = ['label' => 'Diagnostic dans la couronne de l\'arbre', 'desc' => 'Prestation réalisée avec un arboriste grimpeur', 'qte' => $rh, 'unite' => 'arbre', 'pu' => $puh];
        }
        if (!$res['jours'] && ($rb || $rh)) { $res['jours'] = 1; }
        $km = isset($d['km']) && $d['km'] !== null && $d['km'] !== '' ? round(max(0, min(1500, (float)$d['km'])), 1) : null;
        if ($res['jours'] && $km !== null) {
            $ar = !empty($T['deplacementAllerRetour']) ? 2 : 1;
            $res['km'] = $km;
            // Au-delà de N h de route (aller) : un seul aller-retour pour toute la mission, hébergement et repas par journée sur place
            $E = $T['eloignement'] ?? null; $duree = isset($d['duree_h']) && is_numeric($d['duree_h']) ? (float)$d['duree_h'] : null;
            $res['loin'] = $E && $duree !== null && $duree > (float)$E['heuresTrajetMax'];
            $trajets = $res['loin'] ? 1 : $res['jours'];
            $res['lignes_demarrage'][] = ['label' => 'Frais de déplacement depuis Cambes', 'desc' => $trajets . ' trajet(s) × ' . str_replace('.', ',', (string)$km) . ' km' . ($ar === 2 ? ' × 2 (aller-retour)' : '') . ' × ' . number_format((float)$T['deplacementKm'], 2, ',', ' ') . ' €', 'qte' => round($km * $ar * $trajets, 1), 'unite' => 'km', 'pu' => (float)$T['deplacementKm']];
            $kp = round(max(0, min($km, (float)($d['km_peage'] ?? 0))), 1);
            $P = $T['peage'] ?? null;
            if ($P && $kp > 0 && $kp * 2 * $trajets * (float)$P['prixKm'] >= (float)$P['minimum']) {
                $res['lignes_demarrage'][] = ['label' => 'Frais de péage', 'desc' => $trajets . ' trajet(s) × ' . str_replace('.', ',', (string)$kp) . ' km à péage × 2 (aller-retour) × ' . number_format((float)$P['prixKm'], 2, ',', ' ') . ' €', 'qte' => round($kp * 2 * $trajets, 1), 'unite' => 'km', 'pu' => (float)$P['prixKm']];
            }
            if ($res['loin']) {
                $res['lignes_demarrage'][] = ['label' => 'Hébergement et repas', 'desc' => 'Plus de ' . (int)$E['heuresTrajetMax'] . ' h de route depuis Cambes (' . str_replace('.', ',', (string)round($duree, 1)) . ' h) : ' . $res['jours'] . ' jour(s) × ' . eur((float)$E['hebergementParJour']), 'qte' => $res['jours'], 'unite' => 'jour', 'pu' => (float)$E['hebergementParJour']];
            }
        }
        foreach ($res['lignes_demarrage'] as $l) { $res['demarrage_ht'] += round($l['qte'] * $l['pu'], 2); }
        $res['demarrage_ht'] = round($res['demarrage_ht'], 2);
    }
    $A = $T['acompte'] ?? null;
    $res['acompte_ht'] = ($A && in_array($d['profil'], $A['profils'], true) && $res['demarrage_ht'] > 0) ? round($res['demarrage_ht'] * $A['pourcentage'] / 100, 2) : 0.0;
    if (!empty($d['abonnement'])) {
        $p = palier($d['profil'], $n);
        $res['abonnement'] = $p ? $p + ['sur_devis' => false] : ['sur_devis' => true];
    }
    return $res;
}

/* ---------- Mise en forme ---------- */
function eur(float $v, int $dec = 2): string {
    $s = number_format($v, $dec, ',', "\u{202F}");
    if ($dec === 2 && substr($s, -3) === ',00') { $s = substr($s, 0, -3); }
    return $s . "\u{00A0}€";
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function date_fr(string $ymd): string {
    $m = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $t = strtotime($ymd);
    return (int)date('j', $t) . (date('j', $t) === '1' ? 'er' : '') . ' ' . $m[(int)date('n', $t)] . ' ' . date('Y', $t);
}
function tva_intracom(string $siren): string {
    $s = preg_replace('/\D/', '', $siren);
    if (strlen($s) !== 9) { return ''; }
    $cle = (12 + 3 * ((int)$s % 97)) % 97;
    return 'FR' . str_pad((string)$cle, 2, '0', STR_PAD_LEFT) . $s;
}

/* ---------- Liens signés envoyés aux clients ---------- */
function jeton_signe(int $id): string {
    return $id . '.' . substr(hash_hmac('sha256', 'demande-' . $id, (string)cfg('secret')), 0, 32);
}
function verifier_jeton(string $t): ?int {
    if (!preg_match('/^(\d+)\.([a-f0-9]{32})$/', $t, $m)) { return null; }
    return hash_equals(jeton_signe((int)$m[1]), $t) ? (int)$m[1] : null;
}

/* ---------- Courriel ---------- */
function envoyer_mail(string $a, string $sujet, string $texte, ?string $repondre_a = null, ?string $piece_jointe = null): bool {
    if (cfg('mail_capture')) { // tests
        file_put_contents(cfg('mail_capture'), "À: $a\nSujet: $sujet\n" . ($piece_jointe ? 'PJ: ' . basename($piece_jointe) . "\n" : '') . "\n$texte\n-----\n", FILE_APPEND);
        return true;
    }
    $from = (string)cfg('mail_from', 'noreply@arbogestion.fr');
    $repondre_a = $repondre_a ?: (string)cfg('mail_kevin', 'contact@arbogestion.fr');
    $entetes = "From: Arbogestion <$from>\r\n" . ($repondre_a ? "Reply-To: $repondre_a\r\n" : '') . "MIME-Version: 1.0\r\n";
    if ($piece_jointe && is_file($piece_jointe)) {
        $b = 'arbo' . bin2hex(random_bytes(8));
        $entetes .= "Content-Type: multipart/mixed; boundary=\"$b\"\r\n";
        $corps = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$texte\r\n"
            . "--$b\r\nContent-Type: application/pdf; name=\"" . basename($piece_jointe) . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . basename($piece_jointe) . "\"\r\n\r\n"
            . chunk_split(base64_encode((string)file_get_contents($piece_jointe))) . "--$b--";
    } else {
        $entetes .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
        $corps = $texte;
    }
    return @mail($a, '=?UTF-8?B?' . base64_encode($sujet) . '?=', $corps, $entetes, '-f' . $from);
}

/* ---------- Client API Pennylane v2 ---------- */
function pennylane(string $methode, string $chemin, ?array $corps = null, ?string $fichier = null): array {
    $base = (string)cfg('pennylane_base', 'https://app.pennylane.com/api/external/v2');
    if (cfg('mode_essai') && $methode !== 'GET') {
        journal("ESSAI Pennylane $methode $chemin " . json_encode($corps, JSON_UNESCAPED_UNICODE));
        return ['id' => 'essai-' . substr(md5($chemin . microtime()), 0, 8), 'quote_number' => 'D-ESSAI', 'essai' => true];
    }
    $ch = curl_init($base . $chemin);
    $entetes = ['Authorization: Bearer ' . cfg('pennylane_token'), 'Accept: application/json'];
    $opts = [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40];
    if ($fichier !== null) {
        $opts[CURLOPT_POSTFIELDS] = ['file' => new CURLFile($fichier, 'application/pdf', basename($fichier))];
    } elseif ($corps !== null) {
        $entetes[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($corps, JSON_UNESCAPED_UNICODE);
    }
    $opts[CURLOPT_HTTPHEADER] = $entetes;
    curl_setopt_array($ch, $opts);
    $rep = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($rep === false || $code >= 400) {
        journal("ERREUR Pennylane $methode $chemin HTTP $code $err " . substr((string)$rep, 0, 800));
        throw new RuntimeException("Pennylane HTTP $code sur $chemin : " . substr((string)$rep, 0, 300));
    }
    return $rep === '' ? [] : (json_decode($rep, true) ?? []);
}

/*
 * Modèle Pennylane « Devis Arbogestion » ou « Facture Arbogestion ».
 * Trouvé automatiquement par son nom (mis en cache 1 jour) ; on peut forcer l'identifiant dans config.php :
 * 'pennylane_modele_devis' => 123, 'pennylane_modele_facture' => 456 (0 = aucun modèle).
 */
function pl_modele(string $type): ?int {
    $cle = $type === 'devis' ? 'pennylane_modele_devis' : 'pennylane_modele_facture';
    if (cfg($cle) !== null) { return (int)cfg($cle) ?: null; }
    $cache = ARBO_APP . '/storage/modeles-pennylane.json';
    $c = is_file($cache) ? (json_decode((string)file_get_contents($cache), true) ?: []) : [];
    if (isset($c[$type]) && ($c['_le'] ?? 0) > time() - 86400) { return $c[$type] ?: null; }
    $norm = fn($t) => strtolower(preg_replace('/[^a-z]/i', '', (string)iconv('UTF-8', 'ASCII//TRANSLIT', (string)$t)));
    $chemins = $type === 'devis' ? ['/quote_templates', '/customer_invoice_templates'] : ['/customer_invoice_templates'];
    $trouve = 0;
    foreach ($chemins as $ch) {
        try { $l = pennylane('GET', $ch); } catch (Throwable $e) { continue; }
        foreach (($l['items'] ?? $l) as $m) {
            if (!is_array($m)) { continue; }
            $nom = $norm($m['name'] ?? $m['label'] ?? $m['title'] ?? '');
            if (strpos($nom, 'arbogestion') !== false && strpos($nom, $type) !== false) { $trouve = (int)($m['id'] ?? 0); break 2; }
        }
    }
    $c[$type] = $trouve; $c['_le'] = time();
    if (!is_dir(dirname($cache))) { @mkdir(dirname($cache), 0750, true); }
    @file_put_contents($cache, json_encode($c));
    if (!$trouve) { journal("Modèle Pennylane « " . ucfirst($type) . " Arbogestion » introuvable : document créé avec le modèle par défaut."); }
    return $trouve ?: null;
}

function pl_filtre(array $f): string {
    return rawurlencode(json_encode($f));
}
