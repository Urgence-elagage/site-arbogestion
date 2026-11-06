<?php
// Réponse du client à une proposition de date d'intervention (lien reçu par courriel).
declare(strict_types=1);
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
require dirname(__DIR__) . '/app/agenda.php';
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$id = rdv_jeton_ok($t);
$r = $id ? rdv($id) : null;
$etat = !$r ? 'invalide' : (in_array($r['statut'], ['annule'], true) ? 'annule' : 'question');
$choix = (string)($_POST['r'] ?? $_GET['r'] ?? '');
if ($r && $etat === 'question') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($choix, ['ok', 'autre'], true)) {
        $msg = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 1500);
        if ($r['statut'] === 'propose' || $r['statut'] === 'confirme' || $r['statut'] === 'refuse') { reponse_client($id, $choix === 'ok', $msg); }
        $etat = $choix === 'ok' ? 'confirme' : 'autre_envoye';
        $r = rdv($id);
    } elseif ($r['statut'] === 'confirme' && $choix !== 'autre') { $etat = 'deja'; }
    elseif (!in_array($r['statut'], ['propose', 'confirme', 'refuse'], true)) { $etat = 'replanif'; }
}
$titres = ['invalide' => 'Lien invalide', 'annule' => 'Rendez-vous annulé', 'question' => 'Votre rendez-vous', 'confirme' => 'Rendez-vous confirmé', 'autre_envoye' => 'Message transmis', 'deja' => 'Rendez-vous déjà confirmé', 'replanif' => 'Rendez-vous en cours de replanification'];
$ics = $r && $r['debut'] ? 'rdv.php?' . http_build_query(['t' => $t, 'ics' => 1]) : '';
if ($r && $r['debut'] && isset($_GET['ics'])) {
    header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: attachment; filename="rdv-arbogestion.ics"');
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Arbogestion//RDV//FR\r\nBEGIN:VEVENT\r\nUID:arbo-rdv-{$r['id']}-client@arbogestion.fr\r\nDTSTAMP:" . gmdate('Ymd\THis\Z')
        . "\r\nDTSTART;TZID=Europe/Paris:" . date('Ymd\THis', strtotime($r['debut'])) . "\r\nDTEND;TZID=Europe/Paris:" . date('Ymd\THis', strtotime((string)rdv_fin($r)))
        . "\r\nSUMMARY:Intervention Arbogestion\r\nLOCATION:" . str_replace([',', ';'], ['\\,', '\\;'], (string)$r['adresse']) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    exit;
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($titres[$etat]) ?> — Arbogestion</title><meta name="robots" content="noindex">
<style>body{font-family:Lato,system-ui,sans-serif;background:#f6f5f0;color:#2b2b2b;margin:0;padding:20px;display:flex;min-height:100vh;align-items:center;justify-content:center}
.b{background:#fff;border-top:6px solid #1e3d33;border-radius:12px;padding:32px;max-width:580px;width:100%;box-shadow:0 10px 30px rgba(0,0,0,.08)}h1{color:#1e3d33;font-size:1.45rem;margin:0 0 12px}
.btn{display:inline-block;margin-top:14px;background:#b7c93b;color:#1e3d33;padding:14px 24px;border-radius:6px;border:0;font:inherit;font-weight:900;cursor:pointer;text-decoration:none}
.btn.sec{background:#fff;border:2px solid #1e3d33;padding:12px 22px}
.lignes{margin:16px 0;padding:0;list-style:none;border-top:1px solid #e6e6df}.lignes li{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #e6e6df}.lignes b{text-align:right}
textarea{width:100%;box-sizing:border-box;font:inherit;min-height:90px;padding:10px;border:1px solid #c9cec3;border-radius:6px;margin-top:6px}
.note{color:#5f655f;font-size:.92rem}details{margin-top:18px}summary{cursor:pointer;font-weight:700;color:#1e3d33}</style></head><body><div class="b">
<h1><?= h($titres[$etat]) ?></h1>
<?php if ($etat === 'invalide'): ?><p>Ce lien n’est pas valide. Écrivez-nous à contact@arbogestion.fr.</p>
<?php elseif ($etat === 'annule'): ?><p>Ce rendez-vous a été annulé. Nous revenons vers vous si besoin.</p>
<?php elseif ($etat === 'replanif'): ?><p>Nous préparons une nouvelle date pour votre intervention et vous la proposerons très prochainement par courriel.</p>
<?php else: ?>
<ul class="lignes"><li><span>Date</span><b><?= h(ucfirst(jour_fr($r['debut']))) ?></b></li><li><span>Arrivée sur place</span><b>vers <?= h(heure_fr($r['debut'])) ?></b></li><li><span>Durée estimée</span><b><?= h(duree_fr((float)$r['duree_h'])) ?></b></li><li><span>Lieu</span><b><?= h($r['adresse']) ?></b></li></ul>
<?php if ($etat === 'confirme' || $etat === 'deja'): ?><p>Merci, votre rendez-vous est confirmé. Nous vous préviendrons en cas d’imprévu (météo notamment).</p><a class="btn" href="<?= h($ics) ?>">Ajouter à mon agenda</a>
<?php elseif ($etat === 'autre_envoye'): ?><p>Merci, votre message a bien été transmis. Nous vous proposons une autre date très prochainement.</p>
<?php else: ?>
<form method="post"><input type="hidden" name="t" value="<?= h($t) ?>"><input type="hidden" name="r" value="ok"><button class="btn" type="submit">Je confirme ce rendez-vous</button></form>
<details<?= $choix === 'autre' ? ' open' : '' ?>><summary>Cette date ne me convient pas</summary>
<form method="post"><input type="hidden" name="t" value="<?= h($t) ?>"><input type="hidden" name="r" value="autre"><label for="m" class="note">Indiquez vos disponibilités (jours, matin ou après-midi) :</label><textarea id="m" name="message" required></textarea><button class="btn sec" type="submit">Envoyer</button></form></details>
<?php endif; endif; ?>
</div></body></html>
