<?php
// Formulaire de contact Arbogestion — envoie le message à l'adresse ci-dessous.
$destinataire = 'contact@arbogestion.fr';
$expediteur   = 'noreply@arbogestion.fr';

header('Content-Type: text/html; charset=utf-8');

function retour($ok, $msg) {
    $couleur = $ok ? '#1e3d33' : '#8a2b2b';
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Arbogestion — Contact</title>'
       . '<style>body{font-family:Lato,system-ui,sans-serif;background:#f6f5f0;color:#2b2b2b;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:20px}'
       . '.b{background:#fff;border-top:6px solid ' . $couleur . ';border-radius:12px;padding:36px;max-width:520px;box-shadow:0 10px 30px rgba(0,0,0,.08)}'
       . 'h1{color:' . $couleur . ';font-size:1.5rem;margin:0 0 12px}a{display:inline-block;margin-top:22px;background:#b7c93b;color:#1e3d33;font-weight:900;padding:12px 22px;border-radius:6px;text-decoration:none}</style></head>'
       . '<body><div class="b"><h1>' . ($ok ? 'Message envoyé' : 'Envoi impossible') . '</h1><p>' . $msg . '</p><a href="/">Retour au site</a></div></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

// Piège à robots : ce champ est invisible, un humain le laisse vide.
if (!empty($_POST['site_web'])) {
    retour(true, 'Merci, votre message a bien été pris en compte.');
}

$nom     = trim(strip_tags($_POST['nom'] ?? ''));
$email   = trim($_POST['email'] ?? '');
$tel     = trim(strip_tags($_POST['tel'] ?? ''));
$profil  = trim(strip_tags($_POST['profil'] ?? ''));
$message = trim(strip_tags($_POST['message'] ?? ''));

if ($nom === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    retour(false, 'Merci de renseigner votre nom, une adresse e-mail valide et votre message.');
}
if (preg_match('/[\r\n]/', $nom . $email . $tel)) {
    retour(false, 'Les informations saisies ne sont pas valides.');
}

$sujet = '=?UTF-8?B?' . base64_encode('[arbogestion.fr] Demande de contact — ' . $nom) . '?=';
$corps = "Nouvelle demande reçue depuis le formulaire du site arbogestion.fr\n\n"
       . "Nom : $nom\n"
       . "E-mail : $email\n"
       . "Téléphone : " . ($tel !== '' ? $tel : '—') . "\n"
       . "Profil : " . ($profil !== '' ? $profil : '—') . "\n"
       . "Date : " . date('d/m/Y H:i') . "\n\n"
       . "Message :\n$message\n";

$entetes = "From: Arbogestion <$expediteur>\r\n"
         . "Reply-To: $nom <$email>\r\n"
         . "MIME-Version: 1.0\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n"
         . "Content-Transfer-Encoding: 8bit\r\n";

if (@mail($destinataire, $sujet, $corps, $entetes, '-f' . $expediteur)) {
    retour(true, 'Merci ' . htmlspecialchars($nom) . ', votre message a bien été envoyé. Nous revenons vers vous rapidement.');
}
retour(false, 'Une erreur technique est survenue. Merci de réessayer dans quelques instants.');
