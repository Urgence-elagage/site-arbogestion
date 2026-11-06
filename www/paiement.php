<?php
// Page de paiement personnelle (lien reçu par courriel après signature du devis) : redirige vers Stripe Checkout.
declare(strict_types=1);
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
require dirname(__DIR__) . '/app/signatures.php';

$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$id = verifier_jeton($t);
$row = null;
if ($id) {
    installer_stripe();
    $s = db()->prepare("SELECT * FROM demandes WHERE id = ? AND profil <> 'collectivite'");
    $s->execute([$id]);
    $row = $s->fetch() ?: null;
}
$etat = 'invalide'; $msg = '';
if ($row) {
    $b = besoin_paiement($row);
    $echecs = db()->prepare("SELECT COUNT(*) FROM prelevements WHERE demande_id = ? AND statut = 'echec'");
    $echecs->execute([$id]);
    $a_refaire = (int)$echecs->fetchColumn() > 0 || $row['paiement_statut'] === 'acompte_echec';
    if (($_GET['retour'] ?? '') === 'ok') {
        try { verifier_session($row); } catch (Throwable $e) { journal('Retour paiement ' . $id . ' : ' . $e->getMessage()); }
        $etat = 'merci';
    } elseif (!$b['abonnement'] && $b['acompte_ttc'] <= 0) {
        $etat = 'rien';
    } elseif (in_array($row['paiement_statut'], ['acompte_paye', 'acompte_en_cours', 'moyen_enregistre', 'complet'], true) && !$a_refaire) {
        $etat = 'fait';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if ($a_refaire) { // nouveau moyen de paiement : pas de nouvel acompte s'il a déjà été payé
                $acompte_regle = $row['paiement_statut'] !== 'acompte_echec' && !empty($row['stripe_acompte_pi']);
                maj_demande_s($id, ['paiement_statut' => 'attente']);
                $row['paiement_statut'] = 'attente';
                if ($acompte_regle) {
                    $calc = json_decode($row['calcul'], true); $calc['acompte_ht'] = 0; $row['calcul'] = json_encode($calc);
                }
            }
            header('Location: ' . creer_session_paiement($row), true, 303);
            exit;
        } catch (Throwable $e) {
            journal('Paiement ' . $id . ' : ' . $e->getMessage());
            $msg = 'Le paiement est momentanément indisponible. Réessayez dans quelques minutes ou écrivez-nous à contact@arbogestion.fr.';
            $etat = 'payer';
        }
    } else {
        $etat = 'payer';
    }
    if (($_GET['retour'] ?? '') === 'annule') { $msg = 'Le paiement a été interrompu. Vous pouvez recommencer quand vous le souhaitez.'; }
}
$titre = ['invalide' => 'Lien invalide', 'merci' => 'Merci, c’est enregistré', 'rien' => 'Aucun paiement à effectuer', 'fait' => 'Paiement déjà effectué', 'payer' => 'Paiement sécurisé'][$etat];
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($titre) ?> — Arbogestion</title><meta name="robots" content="noindex">
<style>body{font-family:Lato,system-ui,sans-serif;background:#f6f5f0;color:#2b2b2b;margin:0;padding:20px;display:flex;min-height:100vh;align-items:center;justify-content:center}
.b{background:#fff;border-top:6px solid #1e3d33;border-radius:12px;padding:32px;max-width:580px;width:100%;box-shadow:0 10px 30px rgba(0,0,0,.08)}h1{color:#1e3d33;font-size:1.45rem;margin:0 0 12px}
.btn{display:inline-block;margin-top:18px;background:#b7c93b;color:#1e3d33;padding:14px 24px;border-radius:6px;border:0;font:inherit;font-weight:900;cursor:pointer;text-decoration:none}
.lignes{margin:18px 0 6px;padding:0;list-style:none;border-top:1px solid #e6e6df}.lignes li{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #e6e6df}
.note{color:#5f655f;font-size:.92rem}.err{color:#8a2b2b;font-weight:700}
.secu{display:flex;align-items:center;gap:8px;color:#5f655f;font-size:.88rem;margin-top:16px}.secu svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8}</style></head><body><div class="b">
<h1><?= h($titre) ?></h1>
<?php if ($etat === 'invalide'): ?>
<p>Ce lien n’est pas valide. Contactez-nous à contact@arbogestion.fr.</p>
<?php elseif ($etat === 'merci'): ?>
<p>Votre paiement est bien pris en compte. Vous recevrez votre facture par courriel. <?php if (!empty($b['abonnement'])): ?>Les prochaines factures de votre abonnement seront réglées automatiquement avec le moyen de paiement enregistré.<?php endif; ?></p>
<p class="note">Un prélèvement SEPA peut prendre quelques jours avant d’apparaître sur votre compte.</p><a class="btn" href="/">Retour au site</a>
<?php elseif ($etat === 'rien'): ?>
<p>Aucun paiement en ligne n’est nécessaire pour votre devis n° <?= h($row['pl_devis_numero']) ?>.</p><a class="btn" href="/">Retour au site</a>
<?php elseif ($etat === 'fait'): ?>
<p>Le paiement de votre devis n° <?= h($row['pl_devis_numero']) ?> est déjà enregistré. Merci.</p><a class="btn" href="/">Retour au site</a>
<?php else: ?>
<p><?= h($row['nom_client']) ?> – devis n° <?= h($row['pl_devis_numero']) ?></p>
<ul class="lignes">
<?php $avec_acompte = $b['acompte_ttc'] > 0 && !($a_refaire && !empty($row['stripe_acompte_pi']) && $row['paiement_statut'] !== 'acompte_echec'); if ($avec_acompte): ?><li><span>Acompte à la signature (<?= (int)tarifs()['acompte']['pourcentage'] ?> %)</span><b><?= h(eur($b['acompte_ttc'])) ?> TTC</b></li><?php endif; ?>
<?php if ($b['abonnement']): ?><li><span>Moyen de paiement de l’abonnement</span><b>Prélèvement SEPA ou carte</b></li><?php endif; ?>
</ul>
<?php if ($b['abonnement']): ?><p class="note">Le moyen de paiement enregistré servira uniquement à régler les factures de votre abonnement, que vous recevez par courriel avant chaque prélèvement.</p><?php endif; ?>
<?php if ($msg): ?><p class="err"><?= h($msg) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="t" value="<?= h($t) ?>"><button class="btn" type="submit"><?= $avec_acompte ? 'Payer en ligne' : 'Enregistrer mon moyen de paiement' ?></button></form>
<p class="secu"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>Paiement sécurisé par Stripe. Arbogestion n’a jamais accès à vos coordonnées bancaires.</p>
<?php endif; ?>
</div></body></html>
