<?php
// Dépôt du bon de commande d'une collectivité, après signature du devis (lien personnel reçu par courriel).
declare(strict_types=1);
header('X-Robots-Tag: noindex');
require dirname(__DIR__) . '/app/signatures.php';
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$id = verifier_jeton($t);
$row = null;
if ($id) { $s = db()->prepare('SELECT * FROM demandes WHERE id = ? AND profil = ?'); $s->execute([$id, 'collectivite']); $row = $s->fetch(); }
$msg = ''; $ok = false;
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_FILES['bdc'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) { $msg = 'Aucun fichier reçu.'; }
    elseif ($f['size'] > 10 * 1024 * 1024) { $msg = 'Fichier trop volumineux (10 Mo maximum).'; }
    elseif (strpos((string)file_get_contents($f['tmp_name'], false, null, 0, 5), '%PDF') !== 0) { $msg = 'Merci de transmettre un fichier PDF.'; }
    else {
        try { recevoir_bon_commande((int)$id, $f['tmp_name'], basename((string)$f['name'])); $ok = true; }
        catch (Throwable $e) { journal('BDC ' . $id . ' : ' . $e->getMessage()); $msg = 'Erreur lors de l’enregistrement. Vous pouvez aussi l’envoyer à contact@arbogestion.fr.'; }
    }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bon de commande — Arbogestion</title><meta name="robots" content="noindex">
<style>body{font-family:Lato,system-ui,sans-serif;background:#f6f5f0;color:#2b2b2b;margin:0;padding:20px;display:flex;min-height:100vh;align-items:center;justify-content:center}
.b{background:#fff;border-top:6px solid #1e3d33;border-radius:12px;padding:32px;max-width:560px;width:100%;box-shadow:0 10px 30px rgba(0,0,0,.08)}h1{color:#1e3d33;font-size:1.4rem;margin:0 0 12px}
.btn{display:inline-block;margin-top:18px;background:#b7c93b;color:#1e3d33;font-weight:900;padding:12px 22px;border-radius:6px;border:0;font:inherit;font-weight:900;cursor:pointer;text-decoration:none}
input[type=file]{margin-top:12px;width:100%}.err{color:#8a2b2b;font-weight:700}</style></head><body><div class="b">
<?php if (!$row): ?><h1>Lien invalide</h1><p>Ce lien n’est pas valide ou a expiré. Contactez-nous à contact@arbogestion.fr.</p>
<?php elseif ($ok || $row['statut'] === 'bdc_recu'): ?><h1>Bon de commande bien reçu</h1><p>Merci. Votre bon de commande pour le devis n° <?= h($row['pl_devis_numero']) ?> a bien été transmis à Arbogestion. Nous revenons vers vous pour planifier le démarrage.</p><a class="btn" href="/">Retour au site</a>
<?php else: ?><h1>Transmission du bon de commande</h1><p><?= h($row['nom_client']) ?> – devis n° <?= h($row['pl_devis_numero']) ?>.</p><p>Merci de déposer le bon de commande ou la décision d’engagement (PDF, 10 Mo maximum).</p>
<?php if ($msg): ?><p class="err"><?= h($msg) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="t" value="<?= h($t) ?>"><input type="file" name="bdc" accept="application/pdf" required><button class="btn" type="submit">Envoyer le bon de commande</button></form>
<?php endif; ?></div></body></html>
