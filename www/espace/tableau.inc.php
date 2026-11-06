<?php
// Tableau de bord de l'espace connecté (inclus par index.php, utilisateur déjà authentifié). Renvoie $tableau (HTML).
declare(strict_types=1);
if (!function_exists('espace_connecte') || !espace_connecte()) { http_response_code(403); exit; }
require_once ARBO_APP . '/agenda.php';
installer_agenda();
try { installer_stripe(); } catch (Throwable $e) {}

$tous = db()->query('SELECT * FROM demandes ORDER BY id DESC')->fetchAll();
$SIGNES = ['signe_ponctuel', 'signe_mandat_demande', 'signe_paiement_demande', 'signe_attente_bdc', 'bdc_recu'];
$mois = date('Y-m'); $an = date('Y');
$k = ['brouillon' => [0, 0.0], 'envoye' => [0, 0.0], 'signe_mois' => [0, 0.0], 'signe_an' => [0, 0.0], 'acompte' => [0, 0.0], 'abo' => [0, 0.0], 'erreur' => 0, 'bdc' => 0];
$TVA = (float)tarifs()['tva'];
foreach ($tous as $r) {
    $c = json_decode($r['calcul'], true) ?: [];
    $ab = (!empty($c['abonnement']) && empty($c['abonnement']['sur_devis'])) ? (float)$c['abonnement']['prix'] : 0.0;
    $m = (float)($c['demarrage_ht'] ?? 0) + (!empty($c['abonnement']['prix_an1']) ? (float)$c['abonnement']['prix_an1'] : $ab);
    $st = (string)$r['statut']; $quand = (string)($r['maj_le'] ?: $r['cree_le']);
    if ($st === 'devis_brouillon') { $k['brouillon'][0]++; $k['brouillon'][1] += $m; }
    if ($st === 'devis_envoye') { $k['envoye'][0]++; $k['envoye'][1] += $m; }
    if (in_array($st, $SIGNES, true)) {
        if (str_starts_with($quand, $mois)) { $k['signe_mois'][0]++; $k['signe_mois'][1] += $m; }
        if (str_starts_with($quand, $an)) { $k['signe_an'][0]++; $k['signe_an'][1] += $m; }
        if (($r['paiement_statut'] ?? '') === 'attente' || ($r['paiement_statut'] ?? '') === 'acompte_echec') { $k['acompte'][0]++; $k['acompte'][1] += (float)($c['acompte_ht'] ?? 0) * (1 + $TVA); }
        if (!empty($r['pl_abonnement_id'])) { $k['abo'][0]++; $k['abo'][1] += $ab; }
        if ($st === 'signe_attente_bdc') { $k['bdc']++; }
    }
    if ($st === 'erreur_pennylane') { $k['erreur']++; }
}
$prel_echec = 0; $prel_mois = [0, 0];
try {
    $prel_echec = (int)db()->query("SELECT COUNT(*) FROM prelevements WHERE statut = 'echec'")->fetchColumn();
    $s = db()->prepare("SELECT COUNT(*), COALESCE(SUM(montant_cts),0) FROM prelevements WHERE cree_le LIKE ? AND statut <> 'echec'"); $s->execute([$mois . '%']); $prel_mois = $s->fetch(PDO::FETCH_NUM);
} catch (Throwable $e) {}

$a_planifier = (int)db()->query("SELECT COUNT(*) FROM interventions WHERE statut IN ('a_planifier','refuse')")->fetchColumn();
$refus = (int)db()->query("SELECT COUNT(*) FROM interventions WHERE statut = 'refuse'")->fetchColumn();
$attente_client = (int)db()->query("SELECT COUNT(*) FROM interventions WHERE statut = 'propose'")->fetchColumn();
$pre = (int)db()->query("SELECT COUNT(*) FROM interventions WHERE statut = 'pre'")->fetchColumn();
$s = db()->prepare("SELECT * FROM interventions WHERE debut >= ? AND debut < ? AND statut IN ('pre','planifie','propose','confirme') ORDER BY debut LIMIT 8");
$s->execute([date('Y-m-d'), date('Y-m-d', strtotime('+15 days'))]); $prochains = $s->fetchAll();

$tuile = fn(string $titre, string $valeur, string $detail, string $lien = '', string $ton = '') =>
    '<a class="tuile' . ($ton ? ' t-' . $ton : '') . '"' . ($lien ? ' href="' . h($lien) . '"' : '') . '><span class="t-titre">' . h($titre) . '</span><span class="t-val">' . $valeur . '</span><span class="t-detail">' . $detail . '</span></a>';
$n = fn(int $x, string $sing, string $plur) => $x . ' ' . ($x > 1 ? $plur : $sing);

$alertes = [];
if ($k['erreur']) { $alertes[] = $n($k['erreur'], 'devis', 'devis') . ' en erreur Pennylane : ouvre la fiche pour voir le message.'; }
if ($prel_echec) { $alertes[] = $n($prel_echec, 'prélèvement', 'prélèvements') . ' SEPA en échec : relance le client.'; }
if ($refus) { $alertes[] = $n($refus, 'client a refusé', 'clients ont refusé') . ' la date proposée : à replanifier dans l’agenda.'; }
if ($k['bdc']) { $alertes[] = $n($k['bdc'], 'collectivité', 'collectivités') . ' : bon de commande attendu avant d’intervenir.'; }

$li = '';
foreach ($prochains as $r) {
    $li .= '<li><span class="d">' . h(ucfirst(jour_fr((string)$r['debut']))) . '<br><b>' . h(heure_fr((string)$r['debut'])) . '</b></span><span><b>' . h($r['client']) . '</b> ' . rdv_pastille_tb((string)$r['statut']) . '<br><span class="aide">' . h($r['titre']) . ' · ' . h($r['adresse']) . '</span></span></li>';
}
function rdv_pastille_tb(string $s): string { $x = RDV_STATUTS[$s] ?? [$s, '']; return '<span class="pastille ' . $x[1] . '">' . h($x[0]) . '</span>'; }

$tableau = '<style>
.tuiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px;margin:0 0 20px}
.tuile{display:flex;flex-direction:column;gap:2px;background:#fff;border:1px solid var(--ligne);border-radius:12px;padding:14px 16px;text-decoration:none;color:var(--texte);box-shadow:0 1px 2px rgba(30,61,51,.05),0 6px 18px rgba(30,61,51,.06);border-top:4px solid var(--lime);min-height:118px}
a.tuile[href]:hover{border-color:var(--vert)}
.t-titre{font-size:.82rem;font-weight:700;color:var(--gris);text-transform:uppercase;letter-spacing:.04em}.t-val{font-size:1.9rem;font-weight:900;color:var(--vert);line-height:1.15;font-variant-numeric:tabular-nums}.t-detail{font-size:.88rem;color:var(--gris);margin-top:auto}
.t-alerte{border-top-color:var(--rouge)}.t-alerte .t-val{color:var(--rouge)}.t-info{border-top-color:var(--bleu)}.t-ok{border-top-color:var(--ok)}
.tb2{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:18px;align-items:start}@media (max-width:900px){.tb2{grid-template-columns:1fr}}
.prochains{list-style:none;margin:0;padding:0}.prochains li{display:flex;gap:14px;padding:10px 0;border-top:1px solid var(--ligne)}.prochains li:first-child{border-top:0}.prochains .d{flex:0 0 150px;color:var(--gris);font-size:.88rem}
.alertes{margin:0;padding-left:18px}.alertes li{margin:4px 0}
</style>'
    . '<div class="actions" style="justify-content:space-between;margin:0 0 16px"><div><h1>Tableau de bord</h1><p class="sous" style="margin:0">' . h(ucfirst(jour_fr(date('Y-m-d')))) . '</p></div><div class="actions" style="margin:0"><a class="btn" href="./?p=devis">Nouveau devis</a><a class="btn sec" href="./?p=agenda">Agenda</a></div></div>'
    . ($alertes ? '<div class="msg err" role="status"><b>À traiter</b><ul class="alertes">' . implode('', array_map(fn($a) => '<li>' . h($a) . '</li>', $alertes)) . '</ul></div>' : '')
    . '<div class="tuiles">'
    . $tuile('À envoyer', (string)$k['brouillon'][0], $k['brouillon'][0] ? eur($k['brouillon'][1]) . ' HT à faire signer' : 'aucun devis en brouillon', '#devis', $k['brouillon'][0] ? 'info' : '')
    . $tuile('Attente de signature', (string)$k['envoye'][0], $k['envoye'][0] ? eur($k['envoye'][1]) . ' HT envoyés' : 'rien en attente', '#devis')
    . $tuile('Signés ce mois-ci', (string)$k['signe_mois'][0], eur($k['signe_mois'][1]) . ' HT · ' . $an . ' : ' . eur($k['signe_an'][1]), '#devis', 'ok')
    . $tuile('Acomptes à encaisser', (string)$k['acompte'][0], $k['acompte'][0] ? eur($k['acompte'][1]) . ' TTC (Stripe)' : 'tout est réglé', '#devis', $k['acompte'][0] ? 'info' : '')
    . $tuile('Abonnements actifs', (string)$k['abo'][0], eur($k['abo'][1]) . ' HT / an récurrents', '', 'ok')
    . $tuile('Prélèvements du mois', (string)(int)$prel_mois[0], eur(((int)$prel_mois[1]) / 100) . ' TTC' . ($prel_echec ? ' · ' . $prel_echec . ' en échec' : ''), '', $prel_echec ? 'alerte' : '')
    . $tuile('À planifier', (string)$a_planifier, ($pre ? $pre . ' pré-planifié(s) à confirmer · ' : '') . $attente_client . ' en attente du client', './?p=agenda', $a_planifier ? 'info' : '')
    . '</div>'
    . '<div class="tb2"><div class="carte"><h2>Prochaines interventions (15 jours)</h2>' . ($li ? '<ul class="prochains">' . $li . '</ul>' : '<p class="aide">Rien de planifié. Les créneaux à poser sont dans l’agenda.</p>') . '<div class="actions"><a class="btn sec petit" href="./?p=agenda">Ouvrir l’agenda</a></div></div>'
    . '<div class="carte"><h2>Pennylane</h2><p>Factures, encaissements et relances se suivent dans Pennylane ; ici, ce sont les devis et paiements passés par le site.</p><div class="actions"><a class="btn sec petit" href="https://app.pennylane.com" target="_blank" rel="noopener">Ouvrir Pennylane</a><a class="btn sec petit" href="https://dashboard.stripe.com" target="_blank" rel="noopener">Ouvrir Stripe</a></div></div></div>';
