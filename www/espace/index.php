<?php
// Espace connecté Arbogestion : saisie et suivi des devis. Sécurité : voir app/espace.php.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/espace.php';

$nonce = base64_encode(random_bytes(16));
espace_entetes($nonce);
espace_session();

$p = (string)($_GET['p'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !espace_csrf_ok()) { http_response_code(400); espace_rendu('Formulaire expiré', '<p>Ce formulaire a expiré. <a href="./">Recharger la page</a>.</p>', false); }

function aller(string $p, array $q = []): void { header('Location: ./' . ($p ? '?' . http_build_query(['p' => $p] + $q) : '')); exit; }
function message(string $m): void { $_SESSION['message'] = $m; }

/* ---------- Mise en page ---------- */
function espace_rendu(string $titre, string $contenu, bool $nav = true): void {
    global $nonce;
    $msg = $_SESSION['message'] ?? ''; unset($_SESSION['message']);
    $essai = false; try { $essai = (bool)cfg('mode_essai'); } catch (Throwable $e) {}
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($titre) ?> — Espace Arbogestion</title><meta name="robots" content="noindex,nofollow">
<style>
:root{--vert:#1e3d33;--vert2:#163028;--lime:#b7c93b;--creme:#f6f5f0;--texte:#2b2b2b;--gris:#5f655f;--ligne:#dcdfd6;--blanc:#fff;--rouge:#8a2b2b;--rougef:#fbecec;--ok:#2f6b3a;--okf:#e6f2e2;--jaune:#8a6a12;--jaunef:#fff4d6;--bleu:#24507a;--bleuf:#e8f0f8}
*{box-sizing:border-box}
body{margin:0;font-family:Lato,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--creme);color:var(--texte);font-size:16px;line-height:1.5}
a{color:var(--vert)}
.barre{background:var(--vert2);color:#fff;position:sticky;top:0;z-index:50;box-shadow:0 2px 8px rgba(0,0,0,.15)}
.barre .in{max-width:96rem;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-block:8px}
.marque{font-weight:900;letter-spacing:.08em;color:#fff;text-decoration:none;display:flex;align-items:center;gap:10px}
.marque img{height:44px;width:auto;background:#fff;border-radius:7px;padding:3px}
.ico{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:-3px;margin-right:5px}
.vers-site{color:#fff;text-decoration:none;font-size:.9rem;opacity:.85;margin-right:auto;margin-left:6px;padding:6px 10px;border-radius:6px}.vers-site:hover{opacity:1;background:rgba(255,255,255,.1)}
.marque small{font-weight:400;letter-spacing:0;opacity:.75;margin-left:8px}
.barre nav a[aria-current]{background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.5)}
.barre nav{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.barre nav a,.barre nav button{color:#fff;text-decoration:none;font:inherit;font-size:.92rem;background:none;border:1px solid rgba(255,255,255,.25);border-radius:6px;padding:6px 12px;cursor:pointer}
.barre nav a:hover,.barre nav button:hover{background:rgba(255,255,255,.1)}
.in{max-width:62rem;margin:0 auto;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px))}
main.in{padding-block:24px 60px}
h1{color:var(--vert);font-size:1.6rem;line-height:1.2;margin:0 0 6px;text-wrap:balance}
h2{color:var(--vert);font-size:1.1rem;margin:0 0 10px}
.sous{color:var(--gris);margin:0 0 20px}
.carte{background:var(--blanc);border:1px solid var(--ligne);border-radius:12px;padding:18px 22px 20px;margin-bottom:18px;box-shadow:0 1px 2px rgba(30,61,51,.05),0 6px 18px rgba(30,61,51,.06)}
.carte>h2:first-child{margin:-2px 0 14px;padding-bottom:10px;border-bottom:1px solid var(--ligne);display:flex;align-items:center;gap:8px}
.carte>h2:first-child::before{content:"";width:4px;height:1.05em;border-radius:2px;background:var(--lime)}
.etroit{max-width:30rem;margin:40px auto}
label{display:block;font-weight:700;font-size:.9rem;margin:10px 0 4px}
input[type=text],input[type=email],input[type=tel],input[type=password],input[type=number],input[type=date],input[type=time],input:not([type]),select,textarea{width:100%;height:42px;font:inherit;padding:9px 11px;border:1px solid #c3c9bc;border-radius:8px;background:#fff;color:var(--texte);box-shadow:inset 0 1px 2px rgba(30,61,51,.08);transition:border-color .15s,box-shadow .15s}
textarea{height:auto}
select{appearance:none;-webkit-appearance:none;padding-right:34px;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%231e3d33' stroke-width='2.2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 10px center/16px}
input:not([type=checkbox]):hover,select:hover,textarea:hover{border-color:#9aa592}
input::placeholder,textarea::placeholder{color:#9aa096}
input:not([type=checkbox]):focus,select:focus,textarea:focus{border-color:var(--vert);box-shadow:inset 0 1px 2px rgba(30,61,51,.08),0 0 0 3px rgba(183,201,59,.45);outline:0}
textarea{min-height:70px}
button:focus-visible,a:focus-visible{outline:3px solid var(--lime);outline-offset:1px}
.grille{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:2px 16px;align-items:start}
.grille label:not(.coche){display:flex;align-items:flex-end;line-height:1.3}
.grille>div{min-width:0}
.g3{grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
details.plus{position:relative}details.plus>summary{list-style:none}details.plus>summary::-webkit-details-marker{display:none}details.plus>.carte{position:absolute;right:0;z-index:20;width:300px;margin-top:6px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
hr{border:0;border-top:1px solid var(--ligne);margin:14px 0}
.coche{display:flex;gap:8px;align-items:flex-start;font-weight:400;margin:12px 0}
.coche input{margin-top:4px}
.btn{display:inline-block;font:inherit;font-weight:900;border:0;border-radius:6px;padding:11px 20px;cursor:pointer;text-decoration:none;background:var(--lime);color:var(--vert)}
.btn.sec{background:#fff;border:1px solid var(--vert);color:var(--vert);font-weight:700}
.btn.petit{padding:6px 12px;font-size:.9rem}
.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px}
.msg{border-radius:8px;padding:10px 14px;margin-bottom:16px;background:var(--bleuf);color:var(--bleu);border-left:4px solid var(--bleu)}
.err{background:var(--rougef);color:var(--rouge);border-left-color:var(--rouge)}
.essai{background:var(--jaunef);color:var(--jaune);border-left:4px solid var(--jaune);border-radius:8px;padding:10px 14px;margin-bottom:16px}
.tab{overflow-x:auto;border:1px solid var(--ligne);border-radius:10px;background:#fff}
table{border-collapse:collapse;width:100%;font-size:.93rem}
th{background:var(--vert);color:#fff;text-align:left;padding:8px 10px;font-weight:700;white-space:nowrap}
td{border-top:1px solid var(--ligne);padding:8px 10px;vertical-align:top}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.pastille{display:inline-block;border-radius:999px;padding:2px 10px;font-size:.8rem;font-weight:700;white-space:nowrap;background:#eef0ea;color:var(--gris)}
.p-ok{background:var(--okf);color:var(--ok)}.p-att{background:var(--bleuf);color:var(--bleu)}.p-err{background:var(--rougef);color:var(--rouge)}.p-a{background:var(--jaunef);color:var(--jaune)}
.lignes td{padding:4px}
.lignes input:not([type=checkbox]),.lignes select{padding:6px 8px;height:38px}
.total td{font-weight:900;background:#fafbf6}
.aide{color:var(--gris);font-size:.88rem;margin:4px 0 0}
.codes{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:1.1rem;columns:2;background:#fafbf6;border:1px dashed var(--gris);border-radius:8px;padding:12px 16px 12px 36px}
.qr{text-align:center;margin:12px 0}
.cle{font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;background:#fafbf6;padding:6px 10px;border-radius:6px}
.code6{font-size:1.6rem;letter-spacing:.3em;text-align:center;font-variant-numeric:tabular-nums}
[hidden]{display:none!important}
@media (max-width:760px){.barre .in{flex-wrap:nowrap;gap:6px;padding-block:6px}.marque>span,.vers-site span{display:none}.marque img{height:36px}.vers-site{margin:0;padding:6px}
.barre nav{flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch}.barre nav::-webkit-scrollbar{display:none}.barre nav a,.barre nav button{white-space:nowrap;padding:6px 10px}}
</style></head><body>
<div class="barre"><div class="in"><a class="marque" href="./"><img src="/img/logo.png" alt="" width="44" height="45"><span>ARBOGESTION<small>Espace connecté</small></span></a>
<a class="vers-site" href="/" title="Retour au site arbogestion.fr"><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/></svg><span>Le site</span></a>
<?php if ($nav && espace_connecte()): $moi = espace_admin(); global $p; $ici = fn(array $x) => in_array($p, $x, true) ? ' aria-current="page"' : ''; ?><nav><a href="./"<?= $ici(['', 'fiche']) ?>>Tableau de bord</a><a href="./?p=devis"<?= $ici(['devis']) ?>>Nouveau devis</a><a href="./?p=agenda"<?= $ici(['agenda']) ?>>Agenda</a>
<?php if (espace_est_admin($moi)): ?><a href="./?p=comptes"<?= $ici(['comptes']) ?>>Comptes</a><?php endif; ?><a href="./?p=compte"<?= $ici(['compte', 'securite']) ?> title="Mon compte"><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg><?= h(explode(' ', espace_nom($moi))[0]) ?></a>
<form method="post" action="./?p=deconnexion" style="margin:0"><?= espace_csrf_champ() ?><button type="submit">Se déconnecter</button></form></nav><?php endif; ?></div></div>
<main class="in">
<?php if ($essai && espace_connecte()): ?><div class="essai"><b>Mode essai actif</b> : rien n’est créé ni envoyé dans Pennylane (config.php, « mode_essai »).</div><?php endif; ?>
<?php if ($msg): ?><div class="msg<?= strpos($msg, '!') === 0 ? ' err' : '' ?>" role="status"><?= h(ltrim($msg, '!')) ?></div><?php endif; ?>
<?= $contenu ?>
</main>
<script nonce="<?= h($nonce) ?>">
/* Alignement des champs : sur chaque ligne d'une grille, les libellés prennent la hauteur du plus haut */
(function(){function aligner(g){var ls=[].slice.call(g.children).map(function(c){var l=c.querySelector('label');if(l&&!l.classList.contains('coche')&&c.firstElementChild&&(c.firstElementChild===l||c.firstElementChild.firstElementChild===l)){l._ligne=c;return l}return null}).filter(Boolean);ls.forEach(function(l){l.style.minHeight=''});
var rangs={};ls.forEach(function(l){if(!l.offsetParent)return;var t=l._ligne.offsetTop;(rangs[t]=rangs[t]||[]).push(l)});
Object.keys(rangs).forEach(function(t){var r=rangs[t];if(r.length<2)return;var m=Math.max.apply(null,r.map(function(l){return l.offsetHeight}));r.forEach(function(l){l.style.minHeight=m+'px'})})}
var gs=document.querySelectorAll('.grille');gs.forEach(aligner);var ro=window.ResizeObserver&&new ResizeObserver(function(e){e.forEach(function(x){aligner(x.target)})});if(ro)gs.forEach(function(g){ro.observe(g)});
document.addEventListener('change',function(){setTimeout(function(){gs.forEach(aligner)},0)});})();
document.querySelectorAll('[data-confirmer]').forEach(function(f){f.addEventListener('submit',function(e){if(f.dataset.ok)return;e.preventDefault();var b=f.querySelector('[type=submit]');if(b.dataset.arme){f.dataset.ok=1;f.submit();return;}b.dataset.arme=1;b.dataset.txt=b.textContent;b.textContent=f.dataset.confirmer;setTimeout(function(){delete b.dataset.arme;b.textContent=b.dataset.txt;},4000);});});
</script>
<?php if (function_exists('script_devis')) { script_devis(); } ?>
</body></html><?php
    exit;
}

/* =================================================================
 * Accès : installation, connexion, double authentification
 * ================================================================= */
if ($p === 'deconnexion' && $post) { espace_fermer_session(); message('Vous êtes déconnecté.'); aller(''); }
// Compte désactivé ou supprimé pendant la session : on coupe.
if (!empty($_SESSION['admin_id']) && (!($moi = espace_admin()) || (int)$moi['actif'] !== 1)) { espace_fermer_session(); message('!Cet accès a été désactivé.'); aller(''); }

// ----- Activation d'un accès : installation du premier compte, ou invitation d'un administrateur
$invite = null;
if ($p === 'invitation') {
    $invite = invitation_compte((string)($_GET['t'] ?? ''));
    if (!$invite) { espace_rendu('Lien invalide', '<div class="carte etroit"><h1>Invitation invalide ou expirée</h1><p>Le lien a peut-être déjà servi ou dépassé 72 heures. Demandez une nouvelle invitation à un administrateur.</p><p><a class="btn" href="./">Retour</a></p></div>', false); }
    if (espace_connecte()) { espace_fermer_session(); }
}
if ($invite || !espace_un_compte()) {
    if ($invite || $p === 'installation') {
        $t = (string)($_GET['t'] ?? '');
        if (!$invite && !lien_installation_valide($t)) { espace_rendu('Lien invalide', '<div class="carte etroit"><h1>Lien invalide ou expiré</h1><p>Demandez un nouveau lien d’installation.</p><p><a class="btn" href="./">Retour</a></p></div>', false); }
        $err = '';
        if (!empty($_SESSION['install']) && ($_SESSION['install']['pour'] ?? 0) !== ($invite ? (int)$invite['id'] : 0)) { unset($_SESSION['install']); }
        if ($post && ($_POST['etape'] ?? '') === '1') {
            $email = $invite ? strtolower((string)$invite['email']) : strtolower(trim((string)($_POST['email'] ?? '')));
            $nom = $invite ? (string)$invite['nom'] : trim((string)($_POST['nom'] ?? ''));
            $m1 = (string)($_POST['mdp'] ?? ''); $m2 = (string)($_POST['mdp2'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'Adresse e-mail invalide.'; }
            elseif ($m1 !== $m2) { $err = 'Les deux mots de passe ne sont pas identiques.'; }
            elseif ($e = espace_mdp_valide($m1)) { $err = $e; }
            else {
                $_SESSION['install'] = ['pour' => $invite ? (int)$invite['id'] : 0, 'email' => $email, 'nom' => $nom, 'mdp_hash' => password_hash($m1, PASSWORD_DEFAULT), 'secret' => base32_encoder(random_bytes(20))];
            }
        }
        if ($post && ($_POST['etape'] ?? '') === '2' && !empty($_SESSION['install'])) {
            $i = $_SESSION['install'];
            $pas = totp_verifier($i['secret'], (string)($_POST['code'] ?? ''), 0);
            if ($pas === null) { $err = 'Code incorrect. Vérifiez l’heure du téléphone et saisissez le code affiché maintenant.'; }
            else {
                $codes = codes_secours_nouveaux(); $sec = json_encode(array_map('code_secours_hache', $codes));
                if ($invite) {
                    espace_maj_admin((int)$invite['id'], ['mdp_hash' => $i['mdp_hash'], 'totp_secret' => $i['secret'], 'totp_dernier_pas' => $pas, 'secours' => $sec, 'invite_le' => null, 'derniere_connexion' => date('Y-m-d H:i:s')]);
                    $nouvel_id = (int)$invite['id'];
                } else {
                    db()->prepare('INSERT INTO espace_admin (email, nom, role, actif, mdp_hash, totp_secret, totp_dernier_pas, secours, cree_le, derniere_connexion) VALUES (?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$i['email'], $i['nom'], 'admin', 1, $i['mdp_hash'], $i['secret'], $pas, $sec, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                    $nouvel_id = (int)espace_compte_par_email($i['email'])['id'];
                }
                unset($_SESSION['install']);
                espace_ouvrir_session($nouvel_id, 'ok');
                journal('Espace : accès activé pour ' . $i['email']);
                envoyer_mail((string)cfg('mail_kevin'), '[Arbogestion] Accès à l’espace connecté activé', "L'accès à l'espace connecté Arbogestion a été activé pour {$i['email']}, avec double authentification.\nSi ce n'est pas normal, désactive ce compte tout de suite (Espace > Comptes).");
                $_SESSION['codes_a_afficher'] = $codes;
                aller('codes-secours');
            }
        }
        $q = './?' . http_build_query(['p' => $invite ? 'invitation' : 'installation', 't' => $t]);
        if (empty($_SESSION['install'])) {
            $html = '<div class="carte etroit"><h1>' . ($invite ? 'Bienvenue ' . h(espace_nom($invite)) : 'Créer votre accès') . '</h1><p class="sous">Étape 1 sur 2 : ' . ($invite ? 'votre mot de passe.' : 'identifiant et mot de passe.') . '</p>'
                . ($err ? '<div class="msg err">' . h($err) . '</div>' : '')
                . '<form method="post" action="' . h($q) . '">' . espace_csrf_champ() . '<input type="hidden" name="etape" value="1">'
                . ($invite ? '<p>Identifiant : <b>' . h($invite['email']) . '</b></p><input type="hidden" name="email" value="' . h($invite['email']) . '" autocomplete="username">'
                    : '<label for="nom">Prénom et nom</label><input type="text" id="nom" name="nom" required autocomplete="name" value="Kevin Cazes-Bouchet">'
                    . '<label for="email">Adresse e-mail (identifiant)</label><input type="email" id="email" name="email" required autocomplete="username" value="' . h((string)cfg('mail_kevin')) . '">')
                . '<label for="mdp">Mot de passe</label><input type="password" id="mdp" name="mdp" required minlength="12" autocomplete="new-password"><p class="aide">Au moins 12 caractères, avec des lettres et des chiffres. Une phrase facile à retenir est idéale.</p>'
                . '<label for="mdp2">Mot de passe, encore une fois</label><input type="password" id="mdp2" name="mdp2" required minlength="12" autocomplete="new-password">'
                . '<div class="actions"><button class="btn" type="submit">Continuer</button></div></form></div>';
        } else {
            $i = $_SESSION['install'];
            $html = '<div class="carte etroit"><h1>Double authentification</h1><p class="sous">Étape 2 sur 2 : reliez votre téléphone.</p>'
                . ($err ? '<div class="msg err">' . h($err) . '</div>' : '')
                . '<p>1. Installez une application d’authentification sur votre téléphone : <b>Google Authenticator</b>, <b>Microsoft Authenticator</b>, ou l’app <b>Mots de passe</b> d’Apple (iOS 17 et plus).</p>'
                . '<p>2. Dans l’application, ajoutez un compte en scannant ce QR code :</p><div class="qr">' . qr_svg(totp_uri($i['secret'], $i['email'])) . '</div>'
                . '<p class="aide">Impossible de scanner ? Saisissez cette clé à la main :</p><p class="cle">' . h(trim(chunk_split($i['secret'], 4, ' '))) . '</p>'
                . '<form method="post" action="' . h($q) . '">' . espace_csrf_champ() . '<input type="hidden" name="etape" value="2">'
                . '<label for="code">3. Saisissez le code à 6 chiffres affiché</label><input class="code6" type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autocomplete="one-time-code">'
                . '<div class="actions"><button class="btn" type="submit">Activer mon accès</button></div></form></div>';
        }
        espace_rendu('Installation', $html, false);
    }
    // Demande du lien d'installation, envoyé uniquement à l'adresse de config.php
    if ($post && $p === 'demander-installation') {
        if (espace_echecs('installation') >= 1) { message('!Un lien a déjà été envoyé il y a moins de 15 minutes. Vérifiez votre boîte de réception.'); aller(''); }
        db()->prepare('INSERT INTO espace_tentatives (cle, quand) VALUES (?, ?)')->execute(['installation', time()]);
        $lien = url_site('espace/?' . http_build_query(['p' => 'installation', 't' => lien_installation_jeton()]));
        envoyer_mail((string)cfg('mail_kevin'), 'Arbogestion – Lien d’installation de votre espace connecté',
            "Bonjour,\n\nVoici le lien pour créer votre accès à l'espace connecté Arbogestion (valable 30 minutes, une seule fois) :\n$lien\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message.");
        message('Lien envoyé à l’adresse de contact d’Arbogestion. Il est valable 30 minutes.');
        aller('');
    }
    espace_rendu('Première connexion', '<div class="carte etroit"><h1>Espace connecté Arbogestion</h1><p class="sous">Aucun accès n’a encore été créé.</p>'
        . '<p>Pour des raisons de sécurité, le lien de création de l’accès est envoyé uniquement à l’adresse de contact d’Arbogestion enregistrée sur le serveur.</p>'
        . '<form method="post" action="./?p=demander-installation">' . espace_csrf_champ() . '<div class="actions"><button class="btn" type="submit">Recevoir le lien d’installation</button></div></form></div>', false);
}

// ----- Codes de secours (affichés une seule fois)
if ($p === 'codes-secours' && espace_connecte() && !empty($_SESSION['codes_a_afficher'])) {
    $codes = $_SESSION['codes_a_afficher']; unset($_SESSION['codes_a_afficher']);
    espace_rendu('Codes de secours', '<div class="carte etroit"><h1>Vos codes de secours</h1>'
        . '<p>Si vous perdez votre téléphone, chacun de ces codes remplace <b>une fois</b> le code à 6 chiffres. <b>Notez-les maintenant</b> et rangez-les en lieu sûr (papier, coffre-fort de mots de passe) : ils ne seront plus jamais affichés.</p>'
        . '<ol class="codes">' . implode('', array_map(fn($c) => '<li>' . h($c) . '</li>', $codes)) . '</ol>'
        . '<div class="actions"><a class="btn" href="./">J’ai noté mes codes</a></div></div>');
}

// ----- Connexion : étape 1 (mot de passe)
if (!espace_connecte()) {
    $err = '';
    if ($p === 'code' && ($_SESSION['etape'] ?? '') === 'mdp' && !empty($_SESSION['admin_id'])) {
        // Étape 2 : code de l'application ou code de secours
        if ($post) {
            if (espace_bloque()) { $err = 'Trop de tentatives. Réessayez dans 15 minutes.'; }
            else {
                $a = espace_admin(); $saisie = trim((string)($_POST['code'] ?? ''));
                $pas = totp_verifier((string)$a['totp_secret'], $saisie, (int)$a['totp_dernier_pas']);
                if ($pas !== null) {
                    espace_maj_admin((int)$a['id'], ['totp_dernier_pas' => $pas]);
                    espace_maj_admin((int)$a['id'], ['derniere_connexion' => date('Y-m-d H:i:s')]);
                    espace_ouvrir_session((int)$a['id'], 'ok'); journal('Espace : connexion de ' . $a['email']);
                    aller('');
                } elseif (strlen(preg_replace('/[^A-Za-z0-9]/', '', $saisie)) === 8 && code_secours_utiliser($a, $saisie)) {
                    espace_maj_admin((int)$a['id'], ['derniere_connexion' => date('Y-m-d H:i:s')]);
                    espace_ouvrir_session((int)$a['id'], 'ok'); journal('Espace : connexion avec un code de secours (' . $a['email'] . ')');
                    $reste = count(json_decode((string)espace_admin()['secours'], true) ?: []);
                    envoyer_mail((string)$a['email'], '[Arbogestion] Connexion avec un code de secours', "Un code de secours vient d'être utilisé pour se connecter à l'espace connecté avec votre compte. Il en reste $reste.\nSi ce n'est pas vous, prévenez immédiatement Arbogestion.");
                    message('Connexion avec un code de secours. Il vous en reste ' . $reste . '. Pensez à les régénérer dans « Sécurité ».');
                    aller('');
                } else { espace_echec('code'); $err = 'Code incorrect.'; }
            }
        }
        espace_rendu('Code de vérification', '<div class="carte etroit"><h1>Code de vérification</h1><p class="sous">Ouvrez votre application d’authentification et saisissez le code Arbogestion.</p>'
            . ($err ? '<div class="msg err">' . h($err) . '</div>' : '')
            . '<form method="post" action="./?p=code">' . espace_csrf_champ() . '<label for="code">Code à 6 chiffres</label><input class="code6" type="text" id="code" name="code" inputmode="numeric" maxlength="9" required autofocus autocomplete="one-time-code">'
            . '<p class="aide">Téléphone perdu ? Saisissez l’un de vos codes de secours (format ABCD-EFGH).</p>'
            . '<div class="actions"><button class="btn" type="submit">Valider</button><a href="./?p=annuler">Annuler</a></div></form></div>', false);
    }
    if ($p === 'annuler') { espace_fermer_session(); aller(''); }
    if ($post && $p === 'connexion') {
        if (espace_bloque()) { $err = 'Trop de tentatives. Réessayez dans 15 minutes.'; }
        else {
            $email = strtolower(trim((string)($_POST['email'] ?? ''))); $mdp = (string)($_POST['mdp'] ?? '');
            $admin = espace_compte_par_email($email);
            // Hachage factice si le compte n'existe pas, pour un temps de réponse identique
            $hash = ($admin && (int)$admin['actif'] === 1 && !espace_en_attente($admin)) ? (string)$admin['mdp_hash'] : '$2y$10$pEroUcrAEF.XtDrj5toKAOs3tPFCzoMS/b2ChGvxlpvUfZptuqkye';
            $ok = password_verify($mdp, $hash) && $admin && (int)$admin['actif'] === 1 && !espace_en_attente($admin);
            if ($ok) {
                if (password_needs_rehash((string)$admin['mdp_hash'], PASSWORD_DEFAULT)) { espace_maj_admin((int)$admin['id'], ['mdp_hash' => password_hash($mdp, PASSWORD_DEFAULT)]); }
                espace_ouvrir_session((int)$admin['id'], 'mdp');
                aller('code');
            }
            espace_echec('mot de passe'); $err = 'Identifiant ou mot de passe incorrect.';
        }
    }
    espace_rendu('Connexion', '<div class="carte etroit"><h1>Connexion</h1><p class="sous">Espace réservé à l’équipe Arbogestion.</p>'
        . ($err ? '<div class="msg err">' . h($err) . '</div>' : '')
        . '<form method="post" action="./?p=connexion">' . espace_csrf_champ()
        . '<label for="email">Adresse e-mail</label><input type="email" id="email" name="email" required autocomplete="username" autofocus>'
        . '<label for="mdp">Mot de passe</label><input type="password" id="mdp" name="mdp" required autocomplete="current-password">'
        . '<div class="actions"><button class="btn" type="submit">Continuer</button></div></form></div>', false);
}

/* =================================================================
 * Espace connecté
 * ================================================================= */
const STATUTS = [
    'recue' => ['Enregistrée', ''], 'devis_brouillon' => ['Créé, à envoyer', 'p-a'], 'devis_envoye' => ['Envoyé, attente de signature', 'p-att'],
    'signe_ponctuel' => ['Signé', 'p-ok'], 'signe_mandat_demande' => ['Signé, mandat SEPA demandé', 'p-ok'], 'signe_paiement_demande' => ['Signé, paiement Stripe demandé', 'p-ok'], 'signe_attente_bdc' => ['Signé, bon de commande attendu', 'p-ok'],
    'bdc_recu' => ['Bon de commande reçu', 'p-ok'], 'refuse' => ['Refusé', 'p-err'], 'expire' => ['Expiré', ''], 'erreur_pennylane' => ['Non créé dans Pennylane', 'p-err'], 'essai' => ['Essai (non créé)', ''], 'archive' => ['Archivé', ''],
];
function pastille(string $s): string { $x = STATUTS[$s] ?? [$s, '']; return '<span class="pastille ' . $x[1] . '">' . h($x[0]) . '</span>'; }
function ligne_demande(int $id): ?array { $s = db()->prepare('SELECT * FROM demandes WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function montant_txt(array $calc): string {
    $t = [];
    if (($calc['demarrage_ht'] ?? 0) > 0) { $t[] = eur((float)$calc['demarrage_ht']) . ' HT'; }
    $ab = $calc['abonnement'] ?? null;
    if ($ab) { $t[] = empty($ab['sur_devis']) ? '+ ' . eur((float)$ab['prix']) . ' HT/an' : '+ abonnement sur devis'; }
    return implode(' ', $t) ?: '—';
}

// ----- Annexe PDF (contrat ou conditions particulières + CGV)
if ($p === 'annexe') {
    $id = (int)($_GET['id'] ?? 0); $f = ARBO_APP . '/storage/contrats/contrat-' . $id . '.pdf';
    if (!$id || !is_file($f)) { http_response_code(404); espace_rendu('Introuvable', '<p>Annexe introuvable.</p>'); }
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="annexe-devis-' . $id . '.pdf"');
    readfile($f); exit;
}

// ----- Envoi au client par Pennylane
if ($p === 'envoyer' && $post) {
    $id = (int)($_POST['id'] ?? 0);
    try { envoyer_devis($id); message('Devis envoyé au client par Pennylane, avec le lien de signature.'); }
    catch (ErreurDemande $e) { message('!' . $e->getMessage()); }
    catch (Throwable $e) { journal('Espace envoi ' . $id . ' : ' . $e->getMessage()); message('!Envoi impossible : ' . $e->getMessage()); }
    aller('fiche', ['id' => $id]);
}

// ----- Devis en erreur : relancer la création dans Pennylane, ou archiver
if ($p === 'reessayer' && $post) {
    $id = (int)($_POST['id'] ?? 0); $row = ligne_demande($id);
    if (!$row || !in_array($row['statut'], ['erreur_pennylane', 'essai', 'recue'], true)) { message('!Ce devis ne peut pas être relancé.'); aller('fiche', ['id' => $id]); }
    $dem = json_decode($row['donnees'], true) ?: []; $calc = json_decode($row['calcul'], true) ?: [];
    try {
        $r = creer_devis($dem, $calc, ($dem['opt'] ?? []) + ['origine' => $dem['origine'] ?? 'site']);
        if ($r['erreur']) { db()->prepare('DELETE FROM demandes WHERE id = ?')->execute([(int)$r['id']]); message('!Toujours en erreur : ' . $r['erreur']); aller('fiche', ['id' => $id]); }
        db()->prepare("UPDATE demandes SET statut = 'archive', notes = ?, maj_le = ? WHERE id = ?")->execute([trim((string)$row['notes'] . "\nRecréé dans Pennylane : " . $r['numero'] . ' (fiche ' . $r['id'] . ').'), date('Y-m-d H:i:s'), $id]);
        journal("Espace : demande $id recréée dans Pennylane ({$r['numero']})");
        message('Devis ' . $r['numero'] . ' créé dans Pennylane. L’ancienne fiche en erreur est archivée.'); aller('fiche', ['id' => $r['id']]);
    } catch (Throwable $e) { message('!' . $e->getMessage()); aller('fiche', ['id' => $id]); }
}
if ($p === 'archiver' && $post) {
    $id = (int)($_POST['id'] ?? 0); $row = ligne_demande($id);
    if ($row && in_array($row['statut'], ['erreur_pennylane', 'essai', 'recue', 'devis_brouillon', 'refuse', 'expire'], true)) {
        db()->prepare("UPDATE demandes SET statut = 'archive', maj_le = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $id]);
        journal("Espace : demande $id archivée"); message('Fiche archivée : elle n’apparaît plus dans la liste.');
    }
    aller('');
}

// ----- Démarrage manuel de l'abonnement (recensement réalisé hors agenda)
if ($p === 'demarrer-abo' && $post) {
    $id = (int)($_POST['id'] ?? 0); $d = (string)($_POST['date'] ?? '');
    try {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { throw new ErreurDemande('Date invalide.'); }
        require_once ARBO_APP . '/signatures.php';
        message('Abonnement : ' . demarrer_abonnement($id, $d) . '.');
    } catch (Throwable $e) { message('!' . $e->getMessage()); }
    aller('fiche', ['id' => $id]);
}

// ----- Fiche d'un devis
if ($p === 'fiche') {
    $row = ligne_demande((int)($_GET['id'] ?? 0));
    if (!$row) { espace_rendu('Introuvable', '<p>Devis introuvable. <a href="./">Retour</a></p>'); }
    $dem = json_decode($row['donnees'], true) ?: []; $calc = json_decode($row['calcul'], true) ?: [];
    $T = tarifs(); $lignes = '';
    foreach ($calc['lignes_demarrage'] ?? [] as $l) {
        $lignes .= '<tr><td>' . h($l['label']) . ($l['desc'] ? '<br><span class="aide">' . h($l['desc']) . '</span>' : '') . '</td><td class="n">' . h(str_replace('.', ',', (string)$l['qte'])) . ' ' . h($l['unite']) . '</td><td class="n">' . eur((float)$l['pu']) . '</td><td class="n">' . eur(round($l['qte'] * $l['pu'], 2)) . '</td></tr>';
    }
    $ab = $calc['abonnement'] ?? null;
    if ($ab) { $lignes .= '<tr><td>Abonnement ' . h(PROFILS[$row['profil']]['formule'] ?? '') . '</td><td class="n" colspan="2">' . (empty($ab['sur_devis']) ? 'jusqu’à ' . (int)$ab['max'] . ' arbres' : 'sur devis') . '</td><td class="n">' . (empty($ab['sur_devis']) ? eur((float)$ab['prix']) . ' / an' : '—') . '</td></tr>'; }
    $envoyable = $row['pl_devis_id'] && in_array($row['statut'], ['devis_brouillon', 'devis_envoye'], true);
    $html = '<p><a href="./#devis">← Tous les devis</a></p><h1>' . h($row['pl_devis_numero'] ?: 'Demande n° ' . $row['id']) . ' – ' . h($row['nom_client']) . '</h1>'
        . '<p class="sous">' . h(PROFILS[$row['profil']]['nom'] ?? $row['profil']) . ' · ' . (($dem['origine'] ?? 'site') === 'espace' ? 'saisi dans l’espace' : 'demande du site') . ' · ' . h(date('d/m/Y H:i', strtotime($row['cree_le']))) . ' · ' . pastille($row['statut']) . '</p>'
        . ($row['statut'] === 'erreur_pennylane' || $row['statut'] === 'essai' ? '<div class="msg err"><b>Ce devis n’existe pas dans Pennylane</b> : ' . ($row['statut'] === 'essai' ? 'il a été saisi pendant le mode essai.' : 'sa création a échoué, il est seulement enregistré ici.')
            . (trim((string)$row['notes']) !== '' ? '<br>Message reçu : ' . h(mb_substr(trim((string)$row['notes']), -400)) : '')
            . '<div class="actions" style="margin-top:10px"><form method="post" action="./?p=reessayer" style="margin:0">' . espace_csrf_champ() . '<input type="hidden" name="id" value="' . (int)$row['id'] . '"><button class="btn petit" type="submit">Relancer la création dans Pennylane</button></form>'
            . '<form method="post" action="./?p=archiver" style="margin:0" data-confirmer="Confirmer l’archivage">' . espace_csrf_champ() . '<input type="hidden" name="id" value="' . (int)$row['id'] . '"><button class="btn sec petit" type="submit">Archiver (ne plus afficher)</button></form></div></div>' : '')
        . '<div class="carte"><h2>Prestations (HT)</h2><div class="tab"><table><thead><tr><th>Prestation</th><th class="n">Quantité</th><th class="n">PU HT</th><th class="n">Total HT</th></tr></thead><tbody>' . $lignes
        . '<tr class="total"><td colspan="3">Total prestations</td><td class="n">' . eur((float)($calc['demarrage_ht'] ?? 0)) . '</td></tr></tbody></table></div>'
        . (!empty($calc['acompte_ht']) ? '<p class="aide">Acompte à la signature : ' . eur(round($calc['acompte_ht'] * (1 + $T['tva']), 2)) . ' TTC.</p>' : '') . '</div>'
        . '<div class="carte"><h2>Client</h2><p>' . h($row['nom_client']) . ' – ' . h($row['email']) . (empty($dem['client']['tel']) ? '' : ' – ' . h($dem['client']['tel'])) . '<br>' . h(trim(($dem['client']['adresse'] ?? '') . ', ' . ($dem['client']['cp'] ?? '') . ' ' . ($dem['client']['ville'] ?? ''), ', ')) . '</p>'
        . (!empty($dem['cgv_version']) ? '<p class="aide">CGV jointes : version du ' . h(date_fr($dem['cgv_version'])) . '.</p>' : '') . '</div>'
        . '<div class="actions">'
        . ($envoyable ? '<form method="post" action="./?p=envoyer" data-confirmer="Confirmer l’envoi à ' . h($row['email']) . '">' . espace_csrf_champ() . '<input type="hidden" name="id" value="' . (int)$row['id'] . '"><button class="btn" type="submit">' . ($row['statut'] === 'devis_envoye' ? 'Renvoyer au client' : 'Envoyer au client pour signature') . '</button></form>' : '')
        . (is_file(ARBO_APP . '/storage/contrats/contrat-' . (int)$row['id'] . '.pdf') ? '<a class="btn sec" href="./?p=annexe&amp;id=' . (int)$row['id'] . '" target="_blank" rel="noopener">Voir l’annexe jointe (PDF)</a>' : '')
        . '<a class="btn sec" href="https://app.pennylane.com" target="_blank" rel="noopener">Ouvrir Pennylane</a></div>'
        . (!empty($calc['abonnement']) && empty($calc['abonnement']['sur_devis']) && empty($row['pl_abonnement_id']) && (str_starts_with((string)$row['statut'], 'signe') || $row['statut'] === 'bdc_recu')
            ? '<div class="carte" style="margin-top:16px"><h2>Démarrer l’abonnement</h2><p class="aide">Se fait tout seul quand la dernière journée du recensement est marquée « Réalisé » dans l’agenda. Sinon, indique ici la date de réalisation du recensement.</p><form method="post" action="./?p=demarrer-abo" class="actions">' . espace_csrf_champ() . '<input type="hidden" name="id" value="' . (int)$row['id'] . '"><input type="date" name="date" required value="' . date('Y-m-d') . '" style="max-width:200px"><button class="btn sec" type="submit">Créer l’abonnement dans Pennylane</button></form></div>' : '')
        . ($envoyable && $row['statut'] === 'devis_brouillon' ? '<p class="aide">L’envoi passe par Pennylane : le client reçoit le devis et ses annexes avec le lien de signature électronique. Tu peux aussi le relire dans Pennylane avant.</p>' : '')
        . (!empty($row['notes']) ? '<div class="carte" style="margin-top:16px"><h2>Historique</h2><p style="white-space:pre-line">' . h(trim($row['notes'])) . '</p></div>' : '');
    espace_rendu('Devis ' . ($row['pl_devis_numero'] ?: $row['id']), $html);
}

// ----- Mon compte
if ($p === 'compte' || $p === 'securite') {
    $a = espace_admin(); $err = '';
    if ($post) {
        $pas = totp_verifier((string)$a['totp_secret'], (string)($_POST['code'] ?? ''), (int)$a['totp_dernier_pas']);
        $act = (string)($_POST['action'] ?? '');
        if (espace_bloque()) { $err = 'Trop de tentatives. Réessayez dans 15 minutes.'; }
        elseif ($pas === null) { espace_echec('code (mon compte)'); $err = 'Code de l’application incorrect.'; }
        elseif ($act === 'profil') {
            $nom = trim((string)($_POST['nom'] ?? '')); $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $autre = espace_compte_par_email($email);
            if ($nom === '' || mb_strlen($nom) > 120) { $err = 'Indique ton prénom et ton nom.'; }
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'Adresse e-mail invalide.'; }
            elseif ($autre && (int)$autre['id'] !== (int)$a['id']) { $err = 'Cette adresse est déjà utilisée par un autre compte.'; }
            else {
                $ancien = (string)$a['email'];
                espace_maj_admin((int)$a['id'], ['nom' => $nom, 'email' => $email, 'totp_dernier_pas' => $pas]);
                if ($ancien !== $email) {
                    envoyer_mail($ancien, '[Arbogestion] Identifiant modifié', "L'identifiant de votre accès à l'espace connecté Arbogestion vient d'être remplacé par $email.\nSi ce n'est pas vous, prévenez immédiatement Arbogestion.");
                    journal("Espace : identifiant $ancien remplacé par $email");
                }
                message('Informations enregistrées.'); aller('compte');
            }
        } elseif ($act === 'mdp') {
            $m1 = (string)($_POST['mdp'] ?? '');
            if (!password_verify((string)($_POST['actuel'] ?? ''), (string)$a['mdp_hash'])) { espace_echec('mot de passe actuel'); $err = 'Mot de passe actuel incorrect.'; }
            elseif ($m1 !== (string)($_POST['mdp2'] ?? '')) { $err = 'Les deux mots de passe ne sont pas identiques.'; }
            elseif ($e = espace_mdp_valide($m1)) { $err = $e; }
            else { espace_maj_admin((int)$a['id'], ['mdp_hash' => password_hash($m1, PASSWORD_DEFAULT), 'totp_dernier_pas' => $pas]); journal('Espace : mot de passe changé (' . $a['email'] . ')'); message('Mot de passe changé.'); aller('compte'); }
        } elseif ($act === 'codes') {
            $codes = codes_secours_nouveaux();
            espace_maj_admin((int)$a['id'], ['secours' => json_encode(array_map('code_secours_hache', $codes)), 'totp_dernier_pas' => $pas]);
            journal('Espace : codes de secours régénérés (' . $a['email'] . ')');
            $_SESSION['codes_a_afficher'] = $codes; aller('codes-secours');
        }
    }
    $reste = count(json_decode((string)$a['secours'], true) ?: []);
    $code = '<label for="code-%1$s">Code actuel de l’application (6 chiffres)</label><input class="code6" type="text" id="code-%1$s" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code">';
    espace_rendu('Mon compte', '<h1>Mon compte</h1><p class="sous">' . h(espace_nom($a)) . ' · ' . h(ESPACE_ROLES[$a['role']] ?? $a['role']) . ' · double authentification active · ' . $reste . ' code(s) de secours restant(s)'
        . ($a['derniere_connexion'] ? ' · dernière connexion le ' . h(date('d/m/Y à H:i', strtotime((string)$a['derniere_connexion']))) : '') . '</p>'
        . ($err ? '<div class="msg err">' . h($err) . '</div>' : '')
        . '<div class="grille g3"><div class="carte"><h2>Mes informations</h2><form method="post" action="./?p=compte">' . espace_csrf_champ() . '<input type="hidden" name="action" value="profil">'
        . '<label for="nom">Prénom et nom</label><input type="text" id="nom" name="nom" required maxlength="120" autocomplete="name" value="' . h((string)$a['nom']) . '">'
        . '<label for="email">Adresse e-mail (identifiant)</label><input type="email" id="email" name="email" required autocomplete="username" value="' . h((string)$a['email']) . '">'
        . sprintf($code, 'profil') . '<div class="actions"><button class="btn" type="submit">Enregistrer</button></div></form></div>'
        . '<div class="carte"><h2>Changer le mot de passe</h2><form method="post" action="./?p=compte">' . espace_csrf_champ() . '<input type="hidden" name="action" value="mdp">'
        . '<label for="actuel">Mot de passe actuel</label><input type="password" id="actuel" name="actuel" required autocomplete="current-password">'
        . '<label for="mdp">Nouveau mot de passe</label><input type="password" id="mdp" name="mdp" required minlength="12" autocomplete="new-password"><label for="mdp2">Encore une fois</label><input type="password" id="mdp2" name="mdp2" required minlength="12" autocomplete="new-password">'
        . sprintf($code, 'mdp') . '<div class="actions"><button class="btn" type="submit">Changer</button></div></form></div>'
        . '<div class="carte"><h2>Codes de secours</h2><p>Ils remplacent le code du téléphone si tu le perds. Génère 10 nouveaux codes ; les anciens ne fonctionneront plus.</p><form method="post" action="./?p=compte">' . espace_csrf_champ() . '<input type="hidden" name="action" value="codes">'
        . sprintf($code, 'codes') . '<div class="actions"><button class="btn sec" type="submit">Générer de nouveaux codes</button></div></form>'
        . '<p class="aide" style="margin-top:14px">Nouveau téléphone ? Demande à un administrateur de « réinitialiser ton accès » : tu recevras un lien pour relier le nouveau téléphone.</p></div></div>');
}

// ----- Gestion des comptes (administrateurs)
if ($p === 'comptes') {
    $moi = espace_admin();
    if (!espace_est_admin($moi)) { message('!La gestion des comptes est réservée aux administrateurs.'); aller(''); }
    $err = '';
    if ($post) {
        $act = (string)($_POST['action'] ?? ''); $cible = espace_compte((int)($_POST['id'] ?? 0));
        $besoin_code = in_array($act, ['creer', 'role', 'reinit'], true);
        $pas = $besoin_code ? totp_verifier((string)$moi['totp_secret'], (string)($_POST['code'] ?? ''), (int)$moi['totp_dernier_pas']) : 0;
        $par = espace_nom($moi);
        if (espace_bloque()) { $err = 'Trop de tentatives. Réessayez dans 15 minutes.'; }
        elseif ($pas === null) { espace_echec('code (comptes)'); $err = 'Code de l’application incorrect.'; }
        elseif ($act === 'creer') {
            $nom = trim((string)($_POST['nom'] ?? '')); $email = strtolower(trim((string)($_POST['email'] ?? ''))); $role = (string)($_POST['role'] ?? '');
            if ($nom === '' || mb_strlen($nom) > 120) { $err = 'Indique le prénom et le nom.'; }
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'Adresse e-mail invalide.'; }
            elseif (!isset(ESPACE_ROLES[$role])) { $err = 'Rôle inconnu.'; }
            elseif (espace_compte_par_email($email)) { $err = 'Un compte existe déjà avec cette adresse.'; }
            else {
                db()->prepare('INSERT INTO espace_admin (email, nom, role, actif, mdp_hash, totp_secret, totp_dernier_pas, secours, cree_le) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$email, $nom, $role, 1, '', '', 0, '[]', date('Y-m-d H:i:s')]);
                espace_maj_admin((int)$moi['id'], ['totp_dernier_pas' => $pas]);
                envoyer_invitation((int)espace_compte_par_email($email)['id'], $par);
                journal("Espace : compte créé pour $email ($role) par " . $moi['email']);
                message("Compte créé. Une invitation a été envoyée à $email (valable 72 h)."); aller('comptes');
            }
        } elseif (!$cible) { $err = 'Compte introuvable.'; }
        else {
            $soi = (int)$cible['id'] === (int)$moi['id'];
            $dernier_admin = ($cible['role'] === 'admin') && !espace_en_attente($cible) && (int)$cible['actif'] === 1 && espace_nb_admins_actifs() <= 1;
            if ($act === 'inviter' && espace_en_attente($cible) && (int)$cible['actif'] === 1) {
                envoyer_invitation((int)$cible['id'], $par); message('Invitation renvoyée à ' . $cible['email'] . '. L’ancien lien ne fonctionne plus.'); aller('comptes');
            } elseif ($soi) { $err = 'Pour ton propre compte, passe par « Mon compte ».'; }
            elseif ($act === 'desactiver') {
                if ($dernier_admin) { $err = 'Impossible : c’est le dernier administrateur actif.'; }
                else { espace_maj_admin((int)$cible['id'], ['actif' => 0]); journal('Espace : compte désactivé ' . $cible['email']); message(espace_nom($cible) . ' ne peut plus se connecter (déconnexion immédiate).'); aller('comptes'); }
            } elseif ($act === 'reactiver') {
                espace_maj_admin((int)$cible['id'], ['actif' => 1]); journal('Espace : compte réactivé ' . $cible['email']); message('Compte réactivé.'); aller('comptes');
            } elseif ($act === 'role' && isset(ESPACE_ROLES[$_POST['role'] ?? ''])) {
                if ($dernier_admin && $_POST['role'] !== 'admin') { $err = 'Impossible : c’est le dernier administrateur actif.'; }
                else { espace_maj_admin((int)$cible['id'], ['role' => (string)$_POST['role']]); espace_maj_admin((int)$moi['id'], ['totp_dernier_pas' => $pas]); journal('Espace : rôle de ' . $cible['email'] . ' → ' . $_POST['role']); message('Rôle modifié.'); aller('comptes'); }
            } elseif ($act === 'reinit') {
                if ($dernier_admin) { $err = 'Impossible : c’est le dernier administrateur actif.'; }
                else {
                    espace_maj_admin((int)$cible['id'], ['mdp_hash' => '', 'totp_secret' => '', 'totp_dernier_pas' => 0, 'secours' => '[]', 'actif' => 1]);
                    espace_maj_admin((int)$moi['id'], ['totp_dernier_pas' => $pas]);
                    envoyer_invitation((int)$cible['id'], $par);
                    journal('Espace : accès réinitialisé pour ' . $cible['email'] . ' par ' . $moi['email']);
                    message('Accès réinitialisé : ' . espace_nom($cible) . ' a reçu un lien pour choisir un nouveau mot de passe et relier son téléphone.'); aller('comptes');
                }
            } else { $err = 'Action impossible.'; }
        }
    }
    $f = fn(string $act, array $c, string $lib, string $cls = 'btn sec petit', string $conf = '', string $extra = '') => '<form method="post" action="./?p=comptes"' . ($conf ? ' data-confirmer="' . h($conf) . '"' : '') . ' style="margin:0;display:flex;gap:6px;align-items:center">' . espace_csrf_champ() . '<input type="hidden" name="action" value="' . $act . '"><input type="hidden" name="id" value="' . (int)$c['id'] . '">' . $extra . '<button class="' . $cls . '" type="submit">' . h($lib) . '</button></form>';
    $tr = '';
    foreach (espace_comptes() as $c) {
        $soi = (int)$c['id'] === (int)$moi['id'];
        $etat = (int)$c['actif'] !== 1 ? '<span class="pastille p-err">Désactivé</span>' : (espace_en_attente($c) ? '<span class="pastille p-a">Invitation envoyée</span>' : '<span class="pastille p-ok">Actif</span>');
        $act = '';
        if ($soi) { $act = '<a class="btn sec petit" href="./?p=compte">Mon compte</a>'; }
        elseif ((int)$c['actif'] !== 1) { $act = $f('reactiver', $c, 'Réactiver'); }
        else {
            if (espace_en_attente($c)) { $act .= $f('inviter', $c, 'Renvoyer l’invitation'); }
            $act .= '<details class="plus"><summary class="btn sec petit">Modifier…</summary><div class="carte">'
                . '<form method="post" action="./?p=comptes">' . espace_csrf_champ() . '<input type="hidden" name="action" value="role"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><label>Rôle</label><select name="role">'
                . implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . ($c['role'] === $k ? ' selected' : '') . '>' . h($v) . '</option>', array_keys(ESPACE_ROLES), ESPACE_ROLES)) . '</select>'
                . '<label>Ton code de l’application</label><input class="code6" type="text" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code"><div class="actions"><button class="btn petit" type="submit">Changer le rôle</button></div></form>'
                . '<hr><form method="post" action="./?p=comptes" data-confirmer="Confirmer la réinitialisation">' . espace_csrf_champ() . '<input type="hidden" name="action" value="reinit"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><p class="aide">Téléphone perdu ou mot de passe oublié : l’accès actuel est effacé et la personne reçoit un nouveau lien d’activation.</p>'
                . '<label>Ton code de l’application</label><input class="code6" type="text" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code"><div class="actions"><button class="btn sec petit" type="submit">Réinitialiser l’accès</button></div></form>'
                . '</div></details>';
            $act .= $f('desactiver', $c, 'Désactiver', 'btn sec petit', 'Confirmer : ' . espace_nom($c) . ' ne pourra plus se connecter');
        }
        $tr .= '<tr><td><b>' . h(espace_nom($c)) . '</b>' . ($soi ? ' <span class="aide">(toi)</span>' : '') . '<br><span class="aide">' . h($c['email']) . '</span></td><td>' . h(ESPACE_ROLES[$c['role']] ?? $c['role']) . '</td><td>' . $etat . '</td><td>'
            . ($c['derniere_connexion'] ? h(date('d/m/Y H:i', strtotime((string)$c['derniere_connexion']))) : '—') . '</td><td><div class="actions" style="margin:0;gap:6px">' . $act . '</div></td></tr>';
    }
    espace_rendu('Comptes', '<h1>Comptes</h1><p class="sous">Qui peut accéder à l’espace connecté. Chaque personne a son propre identifiant et sa double authentification.</p>'
        . ($err ? '<div class="msg err">' . h($err) . '</div>' : '')
        . '<div class="tab"><table><thead><tr><th>Personne</th><th>Rôle</th><th>État</th><th>Dernière connexion</th><th>Actions</th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
        . '<div class="carte" style="margin-top:18px"><h2>Ajouter une personne</h2><form method="post" action="./?p=comptes">' . espace_csrf_champ() . '<input type="hidden" name="action" value="creer"><div class="grille">'
        . '<div><label for="c-nom">Prénom et nom</label><input type="text" id="c-nom" name="nom" required maxlength="120"></div><div><label for="c-email">Adresse e-mail</label><input type="email" id="c-email" name="email" required></div>'
        . '<div><label for="c-role">Rôle</label><select id="c-role" name="role"><option value="collaborateur">Collaborateur – devis et agenda</option><option value="admin">Administrateur – tout, dont les comptes</option></select></div>'
        . '<div><label for="c-code">Ton code de l’application</label><input class="code6" type="text" id="c-code" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code"></div></div>'
        . '<p class="aide">La personne reçoit un e-mail pour choisir son mot de passe et relier son téléphone (lien valable 72 h).</p><div class="actions"><button class="btn" type="submit">Créer et envoyer l’invitation</button></div></form></div>');
}

// ----- Agenda des interventions
if ($p === 'agenda') {
    require __DIR__ . '/agenda.inc.php';
}

// ----- Nouveau devis
if ($p === 'devis') {
    require __DIR__ . '/devis.inc.php';
}

// ----- Tableau de bord, puis liste des devis
$tableau = '';
try { require __DIR__ . '/tableau.inc.php'; } catch (Throwable $e) { journal('Tableau de bord : ' . $e->getMessage()); }
$voir_archives = !empty($_GET['archives']);
$rows = db()->query("SELECT * FROM demandes WHERE statut " . ($voir_archives ? "= 'archive'" : "<> 'archive'") . " ORDER BY id DESC LIMIT 200")->fetchAll();
const GROUPES = ['envoyer' => 'À envoyer', 'signature' => 'Attente de signature', 'signe' => 'Signés', 'paiement' => 'Paiement à suivre', 'erreur' => 'Non créés (erreur)', 'essai' => 'Essais', 'clos' => 'Refusés / expirés'];
function groupes_devis(array $r): array {
    $st = (string)$r['statut']; $g = [];
    if ($st === 'devis_brouillon') { $g[] = 'envoyer'; }
    if ($st === 'devis_envoye') { $g[] = 'signature'; }
    if (str_starts_with($st, 'signe') || $st === 'bdc_recu') { $g[] = 'signe'; }
    if (in_array((string)($r['paiement_statut'] ?? ''), ['attente', 'acompte_echec'], true) || $st === 'signe_attente_bdc') { $g[] = 'paiement'; }
    if ($st === 'erreur_pennylane') { $g[] = 'erreur'; }
    if ($st === 'essai' || str_starts_with((string)$r['pl_devis_id'], 'essai')) { $g[] = 'essai'; }
    if (in_array($st, ['refuse', 'expire'], true)) { $g[] = 'clos'; }
    return $g;
}
function paiement_txt(array $r, array $calc): string {
    $ps = (string)($r['paiement_statut'] ?? ''); $o = [];
    if (!empty($calc['acompte_ht']) && (float)$calc['acompte_ht'] > 0) {
        $o[] = ['attente' => '<span class="pastille p-a">Acompte demandé</span>', 'acompte_echec' => '<span class="pastille p-err">Acompte en échec</span>', 'acompte_paye' => '<span class="pastille p-ok">Acompte payé</span>', 'complet' => '<span class="pastille p-ok">Payé</span>'][$ps]
            ?? (str_starts_with((string)$r['statut'], 'signe') ? '<span class="pastille">Acompte à demander</span>' : '<span class="aide">acompte à la signature</span>');
    } elseif ($ps === 'complet') { $o[] = '<span class="pastille p-ok">Payé</span>'; }
    if ($r['statut'] === 'signe_attente_bdc') { $o[] = '<span class="pastille p-a">Bon de commande attendu</span>'; }
    if (!empty($r['pl_acompte_id'])) { $o[] = '<span class="aide">Facture d’acompte Pennylane n° ' . h((string)$r['pl_acompte_id']) . '</span>'; }
    if (!empty($r['pl_abonnement_id'])) {
        $o[] = '<span class="aide">Abonnement actif</span>';
        try { $s = db()->prepare("SELECT statut, COUNT(*) n FROM prelevements WHERE demande_id = ? GROUP BY statut"); $s->execute([(int)$r['id']]);
            foreach ($s->fetchAll() as $x) { $o[] = '<span class="aide">' . (int)$x['n'] . ' prélèvement(s) ' . h(['echec' => 'en échec', 'paye' => 'payé(s)', 'en_cours' => 'en cours', 'paye_stripe' => 'payé(s)'][$x['statut']] ?? $x['statut']) . '</span>'; }
        } catch (Throwable $e) {}
    }
    return $o ? implode('<br>', $o) : '<span class="aide">—</span>';
}
$tr = ''; $compte = array_fill_keys(array_keys(GROUPES), 0);
foreach ($rows as $r) {
    $calc = json_decode($r['calcul'], true) ?: []; $dem = json_decode($r['donnees'], true) ?: [];
    $g = groupes_devis($r); foreach ($g as $x) { $compte[$x]++; }
    $tr .= '<tr data-g="' . h(implode(' ', $g)) . '"><td><a href="./?p=fiche&amp;id=' . (int)$r['id'] . '">' . h($r['pl_devis_numero'] ?: 'n° ' . $r['id']) . '</a></td><td>' . h(date('d/m/Y', strtotime($r['cree_le']))) . '</td><td>' . h($r['nom_client'])
        . '<br><span class="aide">' . h(PROFILS[$r['profil']]['nom'] ?? $r['profil']) . ' · ' . (($dem['origine'] ?? 'site') === 'espace' ? 'espace' : 'site') . '</span></td><td class="n">' . h(montant_txt($calc)) . '</td><td>' . pastille($r['statut']) . '</td><td>' . paiement_txt($r, $calc) . '</td></tr>';
}
$filtres = '<div class="filtres" role="group" aria-label="Filtrer les devis"><button type="button" data-f="" aria-pressed="true">Tous <b>' . count($rows) . '</b></button>';
foreach (GROUPES as $k => $lib) { if ($compte[$k]) { $filtres .= '<button type="button" data-f="' . $k . '" aria-pressed="false">' . h($lib) . ' <b>' . $compte[$k] . '</b></button>'; } }
$filtres .= '<a class="archives" href="./' . ($voir_archives ? '' : '?archives=1') . '#devis">' . ($voir_archives ? 'Revenir aux devis' : 'Voir les archives') . '</a></div>';
$filtres_js = '<style>.filtres{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px}.filtres button{font:inherit;font-size:.88rem;font-weight:700;background:#fff;color:var(--vert);border:1px solid #c3c9bc;border-radius:999px;padding:6px 14px;cursor:pointer}.filtres button b{margin-left:4px;font-variant-numeric:tabular-nums;color:var(--gris)}.filtres button[aria-pressed=true]{background:var(--vert);border-color:var(--vert);color:#fff}.filtres button[aria-pressed=true] b{color:var(--lime)}.filtres .archives{margin-left:auto;font-size:.88rem}</style>'
    . '<script nonce="' . h($nonce) . '">(function(){var bs=document.querySelectorAll(".filtres button"),rs=document.querySelectorAll("tr[data-g]");function f(v){bs.forEach(function(b){b.setAttribute("aria-pressed",b.dataset.f===v)});rs.forEach(function(r){r.hidden=v!==""&&(" "+r.dataset.g+" ").indexOf(" "+v+" ")<0});try{sessionStorage.setItem("arbo_filtre",v)}catch(e){}}
bs.forEach(function(b){b.addEventListener("click",function(){f(b.dataset.f)})});var v="";try{v=sessionStorage.getItem("arbo_filtre")||""}catch(e){}if(document.querySelector(".filtres button[data-f=\""+v+"\"]"))f(v);})();</script>';
$a_envoyer = count(array_filter($rows, fn($r) => $r['statut'] === 'devis_brouillon'));
$modeles = [];
foreach (['devis', 'facture'] as $t) { $id = null; try { $id = pl_modele($t); } catch (Throwable $e) {} $modeles[] = ucfirst($t) . ' Arbogestion : ' . ($id ? 'trouvé (n° ' . $id . ')' : 'non trouvé, modèle par défaut de Pennylane'); }
espace_rendu('Tableau de bord', $tableau . '<div class="actions" id="devis" style="justify-content:space-between;margin:24px 0 12px"><div><h2 style="font-size:1.3rem;margin:0">' . ($voir_archives ? 'Devis archivés' : 'Devis') . '</h2><p class="sous" style="margin:0">' . count($rows) . ' devis · ' . $a_envoyer . ' à envoyer</p></div></div>'
    . $filtres . ($rows ? '<div class="tab"><table><thead><tr><th>Devis</th><th>Date</th><th>Client</th><th class="n">Montant</th><th>Statut</th><th>Paiement / facturation</th></tr></thead><tbody>' . $tr . '</tbody></table></div>' . $filtres_js
        : '<div class="carte"><p>Aucun devis pour l’instant. Les demandes du site et les devis saisis ici apparaîtront dans cette liste.</p><a class="btn" href="./?p=devis">Saisir un premier devis</a></div>')
    . '<p class="aide" style="margin-top:16px">Modèles Pennylane – ' . h(implode(' · ', $modeles)) . ' · CGV en vigueur : version du ' . h(date_fr(cgv_version_courante())) . '. Les signatures sont vérifiées toutes les heures.</p>');
