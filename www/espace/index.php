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
.barre{background:var(--vert2);color:#fff}
.barre .in{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-block:12px}
.marque{font-weight:900;letter-spacing:.08em;color:#fff;text-decoration:none}
.marque small{font-weight:400;letter-spacing:0;opacity:.75;margin-left:8px}
.barre nav{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.barre nav a,.barre nav button{color:#fff;text-decoration:none;font:inherit;font-size:.92rem;background:none;border:1px solid rgba(255,255,255,.25);border-radius:6px;padding:6px 12px;cursor:pointer}
.barre nav a:hover,.barre nav button:hover{background:rgba(255,255,255,.1)}
.in{max-width:62rem;margin:0 auto;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px))}
main.in{padding-block:24px 60px}
h1{color:var(--vert);font-size:1.6rem;line-height:1.2;margin:0 0 6px;text-wrap:balance}
h2{color:var(--vert);font-size:1.1rem;margin:0 0 10px}
.sous{color:var(--gris);margin:0 0 20px}
.carte{background:var(--blanc);border:1px solid var(--ligne);border-radius:10px;padding:18px 20px;margin-bottom:16px}
.etroit{max-width:30rem;margin:40px auto}
label{display:block;font-weight:700;font-size:.9rem;margin:10px 0 4px}
input[type=text],input[type=email],input[type=tel],input[type=password],input[type=number],input[type=date],select,textarea{width:100%;font:inherit;padding:9px 10px;border:1px solid #c9cec3;border-radius:6px;background:#fff;color:var(--texte)}
textarea{min-height:70px}
input:focus,select:focus,textarea:focus,button:focus-visible,a:focus-visible{outline:3px solid var(--lime);outline-offset:1px}
.grille{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px}
.grille>div{min-width:0}
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
.lignes input,.lignes select{padding:6px 8px}
.total td{font-weight:900;background:#fafbf6}
.aide{color:var(--gris);font-size:.88rem;margin:4px 0 0}
.codes{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:1.1rem;columns:2;background:#fafbf6;border:1px dashed var(--gris);border-radius:8px;padding:12px 16px 12px 36px}
.qr{text-align:center;margin:12px 0}
.cle{font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;background:#fafbf6;padding:6px 10px;border-radius:6px}
.code6{font-size:1.6rem;letter-spacing:.3em;text-align:center;font-variant-numeric:tabular-nums}
[hidden]{display:none!important}
</style></head><body>
<div class="barre"><div class="in"><a class="marque" href="./">ARBOGESTION<small>Espace connecté</small></a>
<?php if ($nav && espace_connecte()): ?><nav><a href="./">Devis</a><a href="./?p=devis">Nouveau devis</a><a href="./?p=agenda">Agenda</a><a href="./?p=securite">Sécurité</a>
<form method="post" action="./?p=deconnexion" style="margin:0"><?= espace_csrf_champ() ?><button type="submit">Se déconnecter</button></form></nav><?php endif; ?></div></div>
<main class="in">
<?php if ($essai && espace_connecte()): ?><div class="essai"><b>Mode essai actif</b> : rien n’est créé ni envoyé dans Pennylane (config.php, « mode_essai »).</div><?php endif; ?>
<?php if ($msg): ?><div class="msg<?= strpos($msg, '!') === 0 ? ' err' : '' ?>" role="status"><?= h(ltrim($msg, '!')) ?></div><?php endif; ?>
<?= $contenu ?>
</main>
<script nonce="<?= h($nonce) ?>">
document.querySelectorAll('[data-confirmer]').forEach(function(f){f.addEventListener('submit',function(e){if(f.dataset.ok)return;e.preventDefault();var b=f.querySelector('[type=submit]');if(b.dataset.arme){f.dataset.ok=1;f.submit();return;}b.dataset.arme=1;b.dataset.txt=b.textContent;b.textContent=f.dataset.confirmer;setTimeout(function(){delete b.dataset.arme;b.textContent=b.dataset.txt;},4000);});});
</script>
<?php if (function_exists('script_devis')) { script_devis(); } ?>
</body></html><?php
    exit;
}

/* =================================================================
 * Accès : installation, connexion, double authentification
 * ================================================================= */
$admin = espace_admin();

if ($p === 'deconnexion' && $post) { espace_fermer_session(); message('Vous êtes déconnecté.'); aller(''); }

// ----- Installation du compte (une seule fois)
if ($admin === null) {
    if ($p === 'installation') {
        $t = (string)($_GET['t'] ?? '');
        if (!lien_installation_valide($t)) { espace_rendu('Lien invalide', '<div class="carte etroit"><h1>Lien invalide ou expiré</h1><p>Demandez un nouveau lien d’installation.</p><p><a class="btn" href="./">Retour</a></p></div>', false); }
        $err = '';
        if ($post && ($_POST['etape'] ?? '') === '1') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $m1 = (string)($_POST['mdp'] ?? ''); $m2 = (string)($_POST['mdp2'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'Adresse e-mail invalide.'; }
            elseif ($m1 !== $m2) { $err = 'Les deux mots de passe ne sont pas identiques.'; }
            elseif ($e = espace_mdp_valide($m1)) { $err = $e; }
            else {
                $_SESSION['install'] = ['email' => $email, 'mdp_hash' => password_hash($m1, PASSWORD_DEFAULT), 'secret' => base32_encoder(random_bytes(20))];
            }
        }
        if ($post && ($_POST['etape'] ?? '') === '2' && !empty($_SESSION['install'])) {
            $i = $_SESSION['install'];
            $pas = totp_verifier($i['secret'], (string)($_POST['code'] ?? ''), 0);
            if ($pas === null) { $err = 'Code incorrect. Vérifiez l’heure du téléphone et saisissez le code affiché maintenant.'; }
            else {
                $codes = codes_secours_nouveaux();
                db()->prepare('INSERT INTO espace_admin (email, mdp_hash, totp_secret, totp_dernier_pas, secours, cree_le) VALUES (?,?,?,?,?,?)')
                    ->execute([$i['email'], $i['mdp_hash'], $i['secret'], $pas, json_encode(array_map('code_secours_hache', $codes)), date('Y-m-d H:i:s')]);
                unset($_SESSION['install']);
                espace_ouvrir_session((int)espace_admin()['id'], 'ok');
                journal('Espace : compte créé pour ' . $i['email']);
                envoyer_mail((string)cfg('mail_kevin'), '[Arbogestion] Espace connecté activé', "L'accès à l'espace connecté Arbogestion a été créé pour {$i['email']}, avec double authentification.\nSi ce n'est pas toi, préviens immédiatement ton hébergeur et supprime le compte (table espace_admin).");
                $_SESSION['codes_a_afficher'] = $codes;
                aller('codes-secours');
            }
        }
        $q = './?' . http_build_query(['p' => 'installation', 't' => $t]);
        if (empty($_SESSION['install'])) {
            $html = '<div class="carte etroit"><h1>Créer votre accès</h1><p class="sous">Étape 1 sur 2 : identifiant et mot de passe.</p>'
                . ($err ? '<div class="msg err">' . h($err) . '</div>' : '')
                . '<form method="post" action="' . h($q) . '">' . espace_csrf_champ() . '<input type="hidden" name="etape" value="1">'
                . '<label for="email">Adresse e-mail (identifiant)</label><input type="email" id="email" name="email" required autocomplete="username" value="' . h((string)cfg('mail_kevin')) . '">'
                . '<label for="mdp">Mot de passe</label><input type="password" id="mdp" name="mdp" required minlength="12" autocomplete="new-password"><p class="aide">Au moins 12 caractères, avec des lettres et des chiffres. Une phrase facile à retenir est idéale.</p>'
                . '<label for="mdp2">Mot de passe, encore une fois</label><input type="password" id="mdp2" name="mdp2" required minlength="12" autocomplete="new-password">'
                . '<div class="actions"><button class="btn" type="submit">Continuer</button></div></form></div>';
        } else {
            $i = $_SESSION['install'];
            $html = '<div class="carte etroit"><h1>Double authentification</h1><p class="sous">Étape 2 sur 2 : reliez votre téléphone.</p>'
                . ($err ? '<div class="msg err">' . h($err) . '</div>' : '')
                . '<p>1. Installez une application d’authentification sur votre iPhone : <b>Google Authenticator</b>, <b>Microsoft Authenticator</b>, ou l’app <b>Mots de passe</b> d’Apple (iOS 17 et plus).</p>'
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
                    espace_ouvrir_session((int)$a['id'], 'ok'); journal('Espace : connexion');
                    aller('');
                } elseif (strlen(preg_replace('/[^A-Za-z0-9]/', '', $saisie)) === 8 && code_secours_utiliser($a, $saisie)) {
                    espace_ouvrir_session((int)$a['id'], 'ok'); journal('Espace : connexion avec un code de secours');
                    $reste = count(json_decode((string)espace_admin()['secours'], true) ?: []);
                    envoyer_mail((string)cfg('mail_kevin'), '[Arbogestion] Connexion avec un code de secours', "Un code de secours vient d'être utilisé pour se connecter à l'espace connecté. Il en reste $reste.\nSi ce n'est pas toi, change immédiatement ton mot de passe et régénère tes codes (Espace > Sécurité).");
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
            $ok = hash_equals(strtolower((string)$admin['email']), $email) & password_verify($mdp, (string)$admin['mdp_hash']);
            if ($ok) {
                if (password_needs_rehash((string)$admin['mdp_hash'], PASSWORD_DEFAULT)) { espace_maj_admin((int)$admin['id'], ['mdp_hash' => password_hash($mdp, PASSWORD_DEFAULT)]); }
                espace_ouvrir_session((int)$admin['id'], 'mdp');
                aller('code');
            }
            espace_echec('mot de passe'); $err = 'Identifiant ou mot de passe incorrect.';
        }
    }
    espace_rendu('Connexion', '<div class="carte etroit"><h1>Connexion</h1><p class="sous">Espace réservé à Arbogestion.</p>'
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
    'bdc_recu' => ['Bon de commande reçu', 'p-ok'], 'refuse' => ['Refusé', 'p-err'], 'expire' => ['Expiré', ''], 'erreur_pennylane' => ['Erreur Pennylane', 'p-err'],
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
    $html = '<p><a href="./">← Tous les devis</a></p><h1>' . h($row['pl_devis_numero'] ?: 'Demande n° ' . $row['id']) . ' – ' . h($row['nom_client']) . '</h1>'
        . '<p class="sous">' . h(PROFILS[$row['profil']]['nom'] ?? $row['profil']) . ' · ' . (($dem['origine'] ?? 'site') === 'espace' ? 'saisi dans l’espace' : 'demande du site') . ' · ' . h(date('d/m/Y H:i', strtotime($row['cree_le']))) . ' · ' . pastille($row['statut']) . '</p>'
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

// ----- Sécurité du compte
if ($p === 'securite') {
    $a = espace_admin(); $err = '';
    if ($post) {
        $pas = totp_verifier((string)$a['totp_secret'], (string)($_POST['code'] ?? ''), (int)$a['totp_dernier_pas']);
        if (espace_bloque()) { $err = 'Trop de tentatives. Réessayez dans 15 minutes.'; }
        elseif ($pas === null) { espace_echec('code (sécurité)'); $err = 'Code de l’application incorrect.'; }
        elseif (($_POST['action'] ?? '') === 'mdp') {
            $m1 = (string)($_POST['mdp'] ?? '');
            if ($m1 !== (string)($_POST['mdp2'] ?? '')) { $err = 'Les deux mots de passe ne sont pas identiques.'; }
            elseif ($e = espace_mdp_valide($m1)) { $err = $e; }
            else { espace_maj_admin((int)$a['id'], ['mdp_hash' => password_hash($m1, PASSWORD_DEFAULT), 'totp_dernier_pas' => $pas]); journal('Espace : mot de passe changé'); message('Mot de passe changé.'); aller('securite'); }
        } elseif (($_POST['action'] ?? '') === 'codes') {
            $codes = codes_secours_nouveaux();
            espace_maj_admin((int)$a['id'], ['secours' => json_encode(array_map('code_secours_hache', $codes)), 'totp_dernier_pas' => $pas]);
            journal('Espace : codes de secours régénérés');
            $_SESSION['codes_a_afficher'] = $codes; aller('codes-secours');
        }
    }
    $reste = count(json_decode((string)$a['secours'], true) ?: []);
    $code = '<label for="code-%1$s">Code actuel de l’application (6 chiffres)</label><input class="code6" type="text" id="code-%1$s" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code">';
    espace_rendu('Sécurité', '<h1>Sécurité du compte</h1><p class="sous">Identifiant : ' . h($a['email']) . ' · double authentification active · ' . $reste . ' code(s) de secours restant(s).</p>'
        . ($err ? '<div class="msg err">' . h($err) . '</div>' : '')
        . '<div class="grille"><div class="carte"><h2>Changer le mot de passe</h2><form method="post" action="./?p=securite">' . espace_csrf_champ() . '<input type="hidden" name="action" value="mdp">'
        . '<label for="mdp">Nouveau mot de passe</label><input type="password" id="mdp" name="mdp" required minlength="12" autocomplete="new-password"><label for="mdp2">Encore une fois</label><input type="password" id="mdp2" name="mdp2" required minlength="12" autocomplete="new-password">'
        . sprintf($code, 'mdp') . '<div class="actions"><button class="btn" type="submit">Changer</button></div></form></div>'
        . '<div class="carte"><h2>Codes de secours</h2><p>Génère 10 nouveaux codes ; les anciens ne fonctionneront plus.</p><form method="post" action="./?p=securite">' . espace_csrf_champ() . '<input type="hidden" name="action" value="codes">'
        . sprintf($code, 'codes') . '<div class="actions"><button class="btn sec" type="submit">Générer de nouveaux codes</button></div></form></div></div>');
}

// ----- Agenda des interventions
if ($p === 'agenda') {
    require __DIR__ . '/agenda.inc.php';
}

// ----- Nouveau devis
if ($p === 'devis') {
    require __DIR__ . '/devis.inc.php';
}

// ----- Tableau de bord
$rows = db()->query('SELECT * FROM demandes ORDER BY id DESC LIMIT 60')->fetchAll();
$tr = '';
foreach ($rows as $r) {
    $calc = json_decode($r['calcul'], true) ?: []; $dem = json_decode($r['donnees'], true) ?: [];
    $tr .= '<tr><td><a href="./?p=fiche&amp;id=' . (int)$r['id'] . '">' . h($r['pl_devis_numero'] ?: 'n° ' . $r['id']) . '</a></td><td>' . h(date('d/m/Y', strtotime($r['cree_le']))) . '</td><td>' . h($r['nom_client'])
        . '<br><span class="aide">' . h(PROFILS[$r['profil']]['nom'] ?? $r['profil']) . ' · ' . (($dem['origine'] ?? 'site') === 'espace' ? 'espace' : 'site') . '</span></td><td class="n">' . h(montant_txt($calc)) . '</td><td>' . pastille($r['statut']) . '</td></tr>';
}
$a_envoyer = count(array_filter($rows, fn($r) => $r['statut'] === 'devis_brouillon'));
$modeles = [];
foreach (['devis', 'facture'] as $t) { $id = null; try { $id = pl_modele($t); } catch (Throwable $e) {} $modeles[] = ucfirst($t) . ' Arbogestion : ' . ($id ? 'trouvé (n° ' . $id . ')' : 'non trouvé, modèle par défaut de Pennylane'); }
espace_rendu('Devis', '<div class="actions" style="justify-content:space-between;margin:0 0 16px"><div><h1>Devis</h1><p class="sous" style="margin:0">' . count($rows) . ' derniers devis · ' . $a_envoyer . ' à envoyer</p></div><a class="btn" href="./?p=devis">Nouveau devis</a></div>'
    . ($rows ? '<div class="tab"><table><thead><tr><th>Devis</th><th>Date</th><th>Client</th><th class="n">Montant</th><th>Statut</th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
        : '<div class="carte"><p>Aucun devis pour l’instant. Les demandes du site et les devis saisis ici apparaîtront dans cette liste.</p><a class="btn" href="./?p=devis">Saisir un premier devis</a></div>')
    . '<p class="aide" style="margin-top:16px">Modèles Pennylane – ' . h(implode(' · ', $modeles)) . ' · CGV en vigueur : version du ' . h(date_fr(cgv_version_courante())) . '. Les signatures sont vérifiées toutes les heures.</p>');
