<?php
/*
 * Espace connecté Arbogestion (https://arbogestion.fr/espace/) — sécurité.
 *  - premier compte (administrateur) créé par un lien d'installation envoyé à l'adresse « mail_kevin » de config.php ;
 *  - comptes supplémentaires créés par un administrateur (Espace > Comptes) : la personne reçoit une invitation
 *    par e-mail (valable 72 h) et choisit elle-même son mot de passe et sa double authentification ;
 *  - rôles : « admin » (tout, dont la gestion des comptes) et « collaborateur » (devis et agenda) ;
 *  - connexion : e-mail + mot de passe, puis code à 6 chiffres d'une application d'authentification (TOTP, RFC 6238)
 *    ou un code de secours à usage unique ;
 *  - session limitée (30 min d'inactivité, 10 h au total), jeton anti-falsification sur chaque formulaire,
 *    limitation des tentatives (par adresse IP et pour le compte).
 * Perte du téléphone ET des codes de secours : un autre administrateur « réinitialise l'accès » (Espace > Comptes).
 * S'il n'y a qu'un administrateur : supprimer sa ligne de la table « espace_admin » (OVH > phpMyAdmin) ; s'il n'en
 * reste aucune, l'installation se refait depuis l'espace (nouveau lien envoyé par e-mail).
 */
declare(strict_types=1);
require_once __DIR__ . '/signatures.php';

const ESPACE_INACTIVITE = 1800;       // 30 min
const ESPACE_DUREE_MAX = 36000;       // 10 h
const ESPACE_ESSAIS_IP = 8;           // échecs autorisés par IP sur 15 min
const ESPACE_ESSAIS_COMPTE = 10;      // échecs autorisés pour le compte sur 15 min

/* ---------- En-têtes et session ---------- */
function espace_entetes(string $nonce): void {
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { header('Strict-Transport-Security: max-age=31536000'); }
}

function espace_session(): void {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name($https ? '__Host-arbo_espace' : 'arbo_espace');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
    $t = time();
    if (isset($_SESSION['debut']) && ($t - (int)$_SESSION['debut'] > ESPACE_DUREE_MAX || $t - (int)($_SESSION['vu'] ?? 0) > ESPACE_INACTIVITE)) {
        $_SESSION = []; session_regenerate_id(true);
        $_SESSION['message'] = 'Session expirée : merci de vous reconnecter.';
    }
    $_SESSION['debut'] = $_SESSION['debut'] ?? $t;
    $_SESSION['vu'] = $t;
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
}

function espace_csrf_champ(): string { return '<input type="hidden" name="jeton" value="' . h($_SESSION['csrf']) . '">'; }
function espace_csrf_ok(): bool { return hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['jeton'] ?? '')); }

function espace_connecte(): bool { return !empty($_SESSION['admin_id']) && ($_SESSION['etape'] ?? '') === 'ok'; }

function espace_ouvrir_session(int $admin_id, string $etape): void {
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $admin_id;
    $_SESSION['etape'] = $etape;
    if ($etape === 'ok') { $_SESSION['debut'] = time(); }
}

function espace_fermer_session(): void {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ---------- Comptes ---------- */
const ESPACE_ROLES = ['admin' => 'Administrateur', 'collaborateur' => 'Collaborateur'];

function installer_comptes(): void {
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    $pdo = db();
    foreach (['nom' => "VARCHAR(120) NULL", 'role' => "VARCHAR(20) NOT NULL DEFAULT 'admin'", 'actif' => 'INT NOT NULL DEFAULT 1',
              'invite_le' => 'VARCHAR(19) NULL', 'derniere_connexion' => 'VARCHAR(19) NULL'] as $col => $type) {
        try { $pdo->query("SELECT $col FROM espace_admin LIMIT 1"); }
        catch (Throwable $e) { $pdo->exec("ALTER TABLE espace_admin ADD COLUMN $col $type"); }
    }
}

/** Au moins un compte existe (sinon : installation). */
function espace_un_compte(): bool { installer_comptes(); return (bool)db()->query('SELECT COUNT(*) FROM espace_admin')->fetchColumn(); }

function espace_compte(int $id): ?array {
    installer_comptes();
    $s = db()->prepare('SELECT * FROM espace_admin WHERE id = ?'); $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Compte de la personne en cours de connexion (ou connectée). */
function espace_admin(): ?array { return empty($_SESSION['admin_id']) ? null : espace_compte((int)$_SESSION['admin_id']); }

function espace_compte_par_email(string $email): ?array {
    installer_comptes();
    $s = db()->prepare('SELECT * FROM espace_admin WHERE LOWER(email) = ?'); $s->execute([strtolower(trim($email))]);
    return $s->fetch() ?: null;
}

function espace_comptes(): array { installer_comptes(); return db()->query('SELECT * FROM espace_admin ORDER BY actif DESC, id')->fetchAll(); }

function espace_est_admin(?array $c = null): bool { $c = $c ?? espace_admin(); return $c !== null && ($c['role'] ?? 'admin') === 'admin'; }

function espace_en_attente(array $c): bool { return (string)$c['mdp_hash'] === '' || (string)$c['totp_secret'] === ''; }

/** Nombre d'administrateurs actifs et activés (on ne peut pas retirer le dernier). */
function espace_nb_admins_actifs(): int {
    return count(array_filter(espace_comptes(), fn($c) => (int)$c['actif'] === 1 && ($c['role'] ?? 'admin') === 'admin' && !espace_en_attente($c)));
}

function espace_nom(array $c): string { return trim((string)($c['nom'] ?? '')) ?: (string)$c['email']; }

/* ---------- Invitations (72 h ; un nouvel envoi annule le précédent) ---------- */
function invitation_jeton(array $c): string {
    $exp = time() + 72 * 3600;
    return (int)$c['id'] . '.' . $exp . '.' . hash_hmac('sha256', 'invitation|' . (int)$c['id'] . '|' . $exp . '|' . $c['invite_le'], (string)cfg('secret'));
}
function invitation_compte(string $t): ?array {
    if (!preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/', $t, $m) || (int)$m[2] < time()) { return null; }
    $c = espace_compte((int)$m[1]);
    if (!$c || (int)$c['actif'] !== 1 || !espace_en_attente($c) || empty($c['invite_le'])) { return null; }
    return hash_equals(hash_hmac('sha256', 'invitation|' . (int)$c['id'] . '|' . $m[2] . '|' . $c['invite_le'], (string)cfg('secret')), $m[3]) ? $c : null;
}
function envoyer_invitation(int $id, string $par): void {
    espace_maj_admin($id, ['invite_le' => date('Y-m-d H:i:s')]);
    $c = espace_compte($id);
    $lien = url_site('espace/?' . http_build_query(['p' => 'invitation', 't' => invitation_jeton($c)]));
    envoyer_mail((string)$c['email'], 'Arbogestion – Votre accès à l’espace connecté',
        "Bonjour " . espace_nom($c) . ",\n\n$par vous a créé un accès à l'espace connecté Arbogestion (rôle : " . (ESPACE_ROLES[$c['role']] ?? $c['role']) . ").\n\nPour l'activer, choisissez votre mot de passe puis reliez votre téléphone (double authentification) :\n[[BOUTON:Activer mon accès|$lien]]\n\nCe lien est valable 72 heures et ne sert qu'une fois. Si vous n'attendiez pas ce message, ignorez-le.");
}

function espace_maj_admin(int $id, array $champs): void {
    $champs['maj_le'] = date('Y-m-d H:i:s');
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($champs)));
    db()->prepare("UPDATE espace_admin SET $set WHERE id = ?")->execute([...array_values($champs), $id]);
}

function espace_mdp_valide(string $mdp): ?string {
    if (mb_strlen($mdp) < 12) { return 'Le mot de passe doit contenir au moins 12 caractères.'; }
    if (!preg_match('/[A-Za-zÀ-ÿ]/u', $mdp) || !preg_match('/\d/', $mdp)) { return 'Le mot de passe doit contenir des lettres et des chiffres.'; }
    return null;
}

/* ---------- Limitation des tentatives ---------- */
function espace_cle_ip(): string { return 'ip:' . substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . cfg('secret')), 0, 24); }

function espace_echecs(string $cle): int {
    $s = db()->prepare('SELECT COUNT(*) FROM espace_tentatives WHERE cle = ? AND quand > ?');
    $s->execute([$cle, time() - 900]);
    return (int)$s->fetchColumn();
}

function espace_bloque(): bool { return espace_echecs(espace_cle_ip()) >= ESPACE_ESSAIS_IP || espace_echecs('compte') >= ESPACE_ESSAIS_COMPTE; }

function espace_echec(string $motif): void {
    $pdo = db();
    $pdo->prepare('INSERT INTO espace_tentatives (cle, quand) VALUES (?, ?)')->execute([espace_cle_ip(), time()]);
    $pdo->prepare('INSERT INTO espace_tentatives (cle, quand) VALUES (?, ?)')->execute(['compte', time()]);
    $pdo->prepare('DELETE FROM espace_tentatives WHERE quand < ?')->execute([time() - 86400]);
    journal('Espace : échec de connexion (' . $motif . ')');
    if (espace_echecs('compte') === ESPACE_ESSAIS_COMPTE) {
        envoyer_mail((string)cfg('mail_kevin'), '[Arbogestion] Espace connecté verrouillé 15 minutes',
            "Plusieurs tentatives de connexion échouées ont eu lieu sur l'espace connecté Arbogestion (dernière : $motif). L'accès est bloqué 15 minutes.\nSi ce n'était pas toi, change ton mot de passe dès que possible (Espace > Sécurité).");
    }
}

/* ---------- Code à usage unique (TOTP, RFC 6238 : 30 s, 6 chiffres, SHA-1) ---------- */
function base32_encoder(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) { $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); }
    foreach (str_split($bits, 5) as $b) { $out .= $alpha[bindec(str_pad($b, 5, '0'))]; }
    return $out;
}

function base32_decoder(string $b32): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) { $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT); }
    foreach (str_split($bits, 8) as $b) { if (strlen($b) === 8) { $out .= chr(bindec($b)); } }
    return $out;
}

function totp_code(string $secret, int $pas): string {
    $h = hash_hmac('sha1', pack('N2', 0, $pas), base32_decoder($secret), true);
    $o = ord($h[19]) & 0xf;
    $n = (unpack('N', substr($h, $o, 4))[1] & 0x7fffffff) % 1000000;
    return str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

/** Renvoie le pas de temps accepté (tolérance ± 30 s), ou null. Refuse un code déjà utilisé. */
function totp_verifier(string $secret, string $code, int $dernier_pas): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) { return null; }
    $maintenant = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) {
        $pas = $maintenant + $d;
        if ($pas > $dernier_pas && hash_equals(totp_code($secret, $pas), $code)) { return $pas; }
    }
    return null;
}

function totp_uri(string $secret, string $email): string {
    return 'otpauth://totp/' . rawurlencode('Arbogestion:' . $email) . '?secret=' . $secret . '&issuer=Arbogestion&digits=6&period=30&algorithm=SHA1';
}

/** QR code en SVG, généré sur le serveur (aucun service extérieur ne voit la clé). */
function qr_svg(string $texte): string {
    require_once ARBO_APP . '/lib/qrcode/qrcode.php';
    $qr = QRCode::getMinimumQRCode($texte, QR_ERROR_CORRECT_LEVEL_M);
    $n = $qr->getModuleCount(); $m = 4; $t = $n + 2 * $m; $d = '';
    for ($r = 0; $r < $n; $r++) { for ($c = 0; $c < $n; $c++) { if ($qr->isDark($r, $c)) { $d .= 'M' . ($c + $m) . ' ' . ($r + $m) . 'h1v1h-1z'; } } }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $t . ' ' . $t . '" width="220" height="220" role="img" aria-label="QR code à scanner" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $d . '"/></svg>';
}

/* ---------- Codes de secours (10 codes, usage unique, stockés hachés) ---------- */
function codes_secours_nouveaux(): array {
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $codes = [];
    for ($i = 0; $i < 10; $i++) {
        $c = ''; for ($j = 0; $j < 8; $j++) { $c .= $alpha[random_int(0, strlen($alpha) - 1)]; }
        $codes[] = substr($c, 0, 4) . '-' . substr($c, 4);
    }
    return $codes;
}
function code_secours_hache(string $code): string { return hash_hmac('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)), (string)cfg('secret')); }

/** Utilise un code de secours : true s'il était valide (il est alors supprimé). */
function code_secours_utiliser(array $admin, string $code): bool {
    $liste = json_decode((string)$admin['secours'], true) ?: [];
    $h = code_secours_hache($code);
    foreach ($liste as $i => $x) {
        if (hash_equals($x, $h)) {
            unset($liste[$i]);
            espace_maj_admin((int)$admin['id'], ['secours' => json_encode(array_values($liste))]);
            return true;
        }
    }
    return false;
}

/* ---------- Lien d'installation (30 min, uniquement tant qu'aucun compte n'existe) ---------- */
function lien_installation_jeton(): string {
    $exp = time() + 1800;
    return $exp . '.' . hash_hmac('sha256', 'installation|' . $exp, (string)cfg('secret'));
}
function lien_installation_valide(string $t): bool {
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $t, $m) || (int)$m[1] < time()) { return false; }
    return hash_equals(hash_hmac('sha256', 'installation|' . $m[1], (string)cfg('secret')), $m[2]) && !espace_un_compte();
}
