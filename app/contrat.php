<?php
/*
 * Trames de contrat Arbogestion 2026 — Particuliers, Professionnels, Collectivités.
 * Texte repris des contrats d'octobre 2026, rempli automatiquement à partir de la demande.
 * Le contrat est joint en annexe du devis Pennylane : la signature du devis vaut acceptation.
 */
declare(strict_types=1);
require_once __DIR__ . '/cgv.php';

const PRESTATAIRE = 'SAS Arbogestion, au capital de 1 000 €, RCS Cahors 105 612 543, SIRET 105 612 543 00014, TVA intracommunautaire FR42 105 612 543, siège social 133 rue Madeleine Brès, 46100 Cambes, représentée par Kévin Cazes-Bouchet, Président, contact@arbogestion.fr – 06 09 70 46 50. Ci-après « Arbogestion ».';

function contrat_html(array $dem, array $calc, string $numero_devis, string $date_doc): string {
    $p = $dem['profil'];
    $c = $dem['client'];
    $T = tarifs();
    $part = $p === 'particulier'; $pro = $p === 'pro'; $col = $p === 'collectivite';
    $titre = $part ? 'Particuliers et grands propriétaires' : ($pro ? 'Professionnels' : 'Collectivités et personnes publiques');
    $mensuel = !$col && (($dem['paiement'] ?? 'annuel') === 'mensuel');
    $ab = $calc['abonnement'];
    $debut = $dem['date_debut'] ?? date('Y-m-d', strtotime('first day of next month'));

    // ----- Identification du client
    $adr = h(trim(($c['adresse'] ?? '') . ', ' . ($c['cp'] ?? '') . ' ' . ($c['ville'] ?? ''), ', '));
    if ($part) {
        $client = h(trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? ''))) . ', demeurant ' . $adr . ', ' . h($c['tel'] ?? '') . ', ' . h($c['email'] ?? '')
            . '. Adresse du ou des terrains concernés : ' . h(($dem['site_adresse'] ?? '') ?: 'identique') . '.';
    } elseif ($pro) {
        $client = h($c['denomination'] ?? '') . (empty($c['forme']) ? '' : ', ' . h($c['forme'])) . ', SIREN ' . h($c['siren'] ?? '')
            . (empty($c['tva']) ? '' : ', TVA ' . h($c['tva'])) . ', siège ' . $adr . ', représenté(e) par ' . h($c['representant'] ?? '') . ', dûment habilité(e). '
            . 'Site(s) concerné(s) : ' . h(($dem['site_adresse'] ?? '') ?: 'à l\'adresse du siège') . '. Interlocuteur référent : ' . h($c['referent'] ?? '') . ', ' . h($c['email'] ?? '') . '.';
    } else {
        $client = h($c['denomination'] ?? '') . ', SIREN ' . h($c['siren'] ?? '') . ', ' . $adr . ', représenté(e) par ' . h($c['representant'] ?? '')
            . ', habilité(e) ' . (empty($c['habilitation']) ? 'à cet effet' : 'par ' . h($c['habilitation'])) . '. Interlocuteur référent : ' . h($c['referent'] ?? '') . ', ' . h($c['email'] ?? '') . '.';
    }

    // ----- Prix de l'abonnement retenu
    if ($ab && empty($ab['sur_devis'])) {
        $ht = $ab['prix']; $ttc = round($ht * (1 + $T['tva']), 2);
        if ($part) { $prix_txt = $mensuel ? eur(round($ttc / 12, 2)) . ' TTC par mois (' . eur($ttc) . ' TTC par an)' : eur($ttc) . ' TTC par an'; }
        elseif ($pro) { $prix_txt = $mensuel ? eur(round($ht / 12, 2)) . ' HT par mois (' . eur($ht) . ' HT par an), prélèvement SEPA mensuel' : eur($ht) . ' HT par an, paiement annuel'; }
        else { $prix_txt = eur($ttc) . ' TTC par an (' . eur($ht) . ' HT), paiement annuel par mandat administratif'; }
        $palier_txt = $calc['nb'] . ' arbres – palier « jusqu\'à ' . $ab['max'] . ' arbres »';
    } else {
        $prix_txt = 'Sur devis'; $palier_txt = $calc['nb'] . ' arbres – sur devis';
    }
    $demarrage_txt = $calc['demarrage_ht'] > 0
        ? 'Comprises au devis n° ' . h($numero_devis) . ' – montant ' . ($part ? eur(round($calc['demarrage_ht'] * (1 + $T['tva']), 2)) . ' TTC' : eur($calc['demarrage_ht']) . ' HT')
        : 'Déjà réalisées ou hors présent devis';

    // ----- Grille tarifaire (Annexe 1)
    $grille = '<table class="g"><thead><tr><th>Nombre d’arbres</th>';
    $grille .= $part ? '<th>Prix mensuel TTC</th><th>Prix annuel TTC</th>' : ($pro ? '<th>Mensuel HT</th><th>Annuel HT</th><th>Annuel TTC</th>' : '<th>Prix annuel HT</th><th>Prix annuel TTC</th>');
    $grille .= '</tr></thead><tbody>';
    $prec = 0;
    foreach ($T['paliers'][$p] as $pl) {
        $lib = $prec === 0 ? 'Jusqu’à ' . number_format($pl[0], 0, ',', ' ') : ($prec + 1) . ' à ' . number_format($pl[0], 0, ',', ' ');
        $ttc = round($pl[1] * (1 + $T['tva']), 2);
        $cl = ($ab && empty($ab['sur_devis']) && $ab['max'] === (int)$pl[0]) ? ' class="actif"' : '';
        $grille .= "<tr$cl><td>$lib</td>" . ($part ? '<td>' . eur(round($ttc / 12, 2)) . '</td><td>' . eur($ttc) . '</td>'
            : ($pro ? '<td>' . eur(round($pl[1] / 12, 2)) . '</td><td>' . eur($pl[1]) . '</td><td>' . eur($ttc) . '</td>' : '<td>' . eur($pl[1]) . '</td><td>' . eur($ttc) . '</td>')) . '</tr>';
        $prec = (int)$pl[0];
    }
    $grille .= '<tr><td>Au-delà de ' . number_format($prec, 0, ',', ' ') . '</td><td colspan="3">Sur devis</td></tr></tbody></table>';

    $users = $col ? '4 accès utilisateurs simultanés' : ($pro ? '2 accès utilisateurs simultanés (accès supplémentaires en option)' : '2 accès utilisateurs simultanés');
    $ouverture = $pro ? 'ouverture temporaire à un intervenant extérieur ou à un assureur (durée et périmètre fixés par le Client)' : 'ouverture temporaire à un intervenant extérieur (durée et périmètre ' . ($part ? 'choisis' : 'fixés') . ' par le Client)';
    $indic = $pro ? 'indicateurs écologiques (valeur d’agrément, absorption carbone, utilisables dans un reporting RSE)' : 'indicateurs écologiques (valeur d’agrément, absorption carbone)';

    ob_start(); ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
@page{margin:22mm 18mm 20mm 18mm}
body{font-family:'DejaVu Sans',sans-serif;font-size:8.6pt;line-height:1.42;color:#2b2b2b}
h1{font-size:15pt;color:#1e3d33;margin:0 0 4px}
h2{font-size:10pt;color:#1e3d33;margin:13px 0 4px;border-bottom:1.5px solid #b7c93b;padding-bottom:2px}
p{margin:0 0 5px;text-align:justify}
.entete{width:100%;margin-bottom:10px}.entete td{vertical-align:middle}
.sous{color:#5f655f;font-size:8pt}
.ref{background:#f2f6dc;border-left:3px solid #8fa224;padding:6px 9px;margin:8px 0;font-size:8.2pt}
table.g,table.cp{width:100%;border-collapse:collapse;margin:6px 0}
table.g th,table.cp th{background:#1e3d33;color:#fff;font-weight:bold;padding:4px 6px;text-align:left}
table.g td,table.cp td{border-bottom:0.5px solid #d9dcd4;padding:3.5px 6px}
table.g td+td,table.g th+th{text-align:right}
tr.actif td{background:#f2f6dc;font-weight:bold}
table.cp td:first-child{width:42%;color:#1e3d33;font-weight:bold}
.sign{margin-top:10px;border:1px solid #b7c93b;padding:8px 10px;background:#fafbf6}
ul{margin:2px 0 6px 16px;padding:0}li{margin-bottom:2px}
.pied{position:fixed;bottom:-12mm;left:0;right:0;font-size:7pt;color:#8a8f88;text-align:center}
<?= CGV_STYLE_PDF ?>
</style></head><body>
<div class="pied">Arbogestion – SAS au capital de 1 000 € – RCS Cahors 105 612 543 – 133 rue Madeleine Brès, 46100 Cambes – Contrat <?= h($titre) ?> – Devis n° <?= h($numero_devis) ?></div>
<table class="entete"><tr><td style="width:70px"><img src="<?= h(ARBO_APP . '/assets/logo.png') ?>" style="width:62px"></td>
<td><h1>Contrat d’abonnement Arbogestion</h1><div class="sous"><?= h($titre) ?> – Conditions générales, grille tarifaire 2026 et conditions particulières – Version 2.0, novembre 2026</div></td></tr></table>

<div class="ref">Le présent contrat est joint en annexe du devis n° <b><?= h($numero_devis) ?></b> du <?= h(date_fr($date_doc)) ?>. <b>La signature électronique de ce devis vaut acceptation du présent contrat</b>, de ses annexes et des conditions générales de vente Arbogestion (version du <?= h(date_fr(cgv_version_courante())) ?>) jointes à la suite.<?= $col ? ' Pour les collectivités, l’acceptation est complétée par la transmission du bon de commande ou de la décision d’engagement.' : '' ?></div>

<h2>Entre les soussignés</h2>
<p><b>Le Prestataire</b> : <?= h(PRESTATAIRE) ?></p>
<p><b>Le Client</b> : <?= $client ?> Ci-après « le Client ».</p>
<p>Il a été convenu ce qui suit.</p>

<h2>1. Objet</h2>
<?php if ($col): ?>
<p>Arbogestion met à disposition du Client, en mode SaaS, le logiciel Grality (offre Arbogestion) et réalise les prestations de diagnostic, de suivi, de planification et d’accompagnement décrites à l’article 4, pour la gestion pluriannuelle du patrimoine arboré <b>déjà recensé</b> du Client (parcs, alignements, chemins ruraux, abords de bâtiments publics, etc.).</p>
<p>Le logiciel et les informations qu’il génère sont des outils d’aide à la décision. Arbogestion formule des recommandations ; <b>le Client décide seul</b> de la réalisation des interventions et en assume la responsabilité.</p>
<?php elseif ($pro): ?>
<p>Arbogestion met à disposition du Client, en mode SaaS, le logiciel Grality (offre Arbogestion) et réalise les prestations de diagnostic, de suivi, de planification et d’accompagnement décrites à l’article 4, pour la gestion du patrimoine arboré <b>déjà recensé</b> du Client.</p>
<p>Le logiciel et les informations qu’il génère sont des outils d’aide à la décision ; ils ne constituent pas une étude technique approfondie d’un arbre déterminé. Arbogestion formule des recommandations ; <b>le Client décide seul</b> de la réalisation des interventions et en assume la responsabilité, notamment au titre de ses obligations de sécurité envers le public, les salariés et les tiers.</p>
<?php else: ?>
<p>Arbogestion met à disposition du Client, en ligne, le logiciel Grality (offre Arbogestion) et réalise les prestations de diagnostic, de suivi, de planification et d’accompagnement décrites à l’article 4, pour le suivi du patrimoine arboré <b>déjà recensé</b> du Client (jardin, parc, propriété, domaine, boisement d’agrément).</p>
<p>Le logiciel et les informations qu’il fournit sont des outils d’aide à la décision. Arbogestion formule des recommandations ; <b>le Client décide</b> de faire réaliser ou non les interventions préconisées.</p>
<?php endif; ?>

<h2>2. Documents contractuels</h2>
<p>Par ordre de priorité : (1) les Conditions particulières (Annexe 2), (2) le présent contrat, (3) la grille tarifaire (Annexe 1), (4) le devis n° <?= h($numero_devis) ?>, (5) les conditions générales de vente Arbogestion jointes, qui s’appliquent pour tout point que les documents précédents ne règlent pas. <?= $part ? 'Le Client reconnaît en avoir reçu un exemplaire avant de s’engager.' : ($pro ? 'Ils annulent tout accord antérieur sur le même objet et prévalent sur les conditions générales d’achat du Client.' : 'Ils annulent tout accord antérieur sur le même objet.') ?></p>

<h2>3. Prérequis (prestations distinctes)</h2>
<p>Le parc doit avoir fait l’objet d’un recensement initial, d’un diagnostic complet et d’un prévisionnel d’intervention. Ces prestations de démarrage, ainsi que les analyses complémentaires (tests au résistographe notamment), ne sont pas comprises dans l’abonnement. Elles sont facturées selon les tarifs de l’Annexe 1 (frais de déplacement en sus) et le devis mentionné en Annexe 2<?= $col ? ', après leur réalisation' : ' : un acompte de ' . (int)$T['acompte']['pourcentage'] . ' % est demandé à la signature du devis, le solde étant facturé après leur réalisation' ?>.</p>

<h2>4. Prestations incluses dans l’abonnement</h2>
<p><b>4.1 Logiciel Grality</b> : <?= $users ?> ; <?= $ouverture ?> ; carte interactive géolocalisée ; fiches arbres (photos haute résolution, espèce, dimensions, état sanitaire et mécanique, historique) ; <?= $indic ?> ; consignation des événements marquants (intempéries, casses, dépérissements, urgences) ; prévisionnels d’intervention.</p>
<p><b>4.2 Diagnostic annuel</b> : diagnostic visuel (méthode VTA) d’environ 1/6 du parc par an, priorisé selon <?= $pro ? 'les enjeux (zones fréquentées par le public, emplacements, parkings, voiries, bâtiments)' : ($col ? 'les enjeux et risques (arbres à risque, sujets sensibles)' : 'les risques') ?>, avec mise à jour automatique des données et suivi de l’évolution sanitaire et mécanique. Les déplacements nécessaires à ce diagnostic annuel sont compris dans l’abonnement.</p>
<p><b>4.3 Planification</b> : hiérarchisation des travaux (élagage, abattage, traitements, haubanage…)<?= $col ? ' ; calendrier prévisionnel pluriannuel (3 à 6 ans) ; coordination avec les équipes techniques ou entreprises du Client.' : ($pro ? ' selon les observations et les priorités du Client, y compris son calendrier d’exploitation (hors saison, fermeture de site) ; calendrier prévisionnel annuel.' : ' selon les observations et les priorités du Client ; calendrier prévisionnel annuel.') ?></p>
<p><b>4.4 Estimation budgétaire</b> : évaluation prévisionnelle des coûts (main-d’œuvre, matériel, évacuation), <?= $col ? 'outil d’aide à la planification budgétaire pluriannuelle.' : ($pro ? 'outil d’aide à la construction du budget d’entretien.' : 'pour aider le Client à budgéter l’entretien.') ?></p>
<p><b>4.5 Suivi et bilans</b> : <?= $col ? 'rapport trimestriel d’avancement (PDF ou Excel) ; ajustement des priorités selon les observations, événements climatiques ou contraintes budgétaires ; traçabilité complète (historique, photos avant/après) ; suivi des prestataires et validation des travaux sur demande du Client.' : ($pro ? 'rapport annuel (PDF ou Excel) sur l’état du patrimoine et l’avancement des interventions, utilisable pour les audits, assureurs et contrôles ; ajustement des priorités ; traçabilité complète (historique, photos avant/après).' : 'rapport annuel (PDF ou Excel) sur l’état du patrimoine et l’avancement des interventions ; ajustement des priorités selon les observations, les événements climatiques ou les contraintes du Client ; traçabilité des actions (historique, photos avant/après).') ?></p>
<p><b>4.6 Accompagnement</b> : <?= $col ? 'formation des utilisateurs 4 h par an ; réunion de suivi trimestrielle d’1 h en visioconférence ; assistance technique continue ; recommandations techniques générales issues des diagnostics.' : 'formation initiale d’1 h ; réunion de suivi annuelle d’1 h en visioconférence ; assistance ' . ($pro ? 'technique continue (paramétrage, usage avancé)' : 'à l’utilisation du logiciel') . ' ; recommandations techniques générales issues des diagnostics.' ?></p>
<p><b>4.7 Export</b> : <?= $col ? 'export des données au format Excel inclus dans l’usage courant ; format Shapefile sur devis.' : ($pro ? 'transmission des fiches arbres depuis le logiciel. Export complet au format Excel ou Shapefile : sur devis.' : 'transmission des fiches arbres à l’assureur, à un professionnel ou à la mairie depuis le logiciel. Export complet de la base au format Excel ou Shapefile : sur devis.') ?></p>

<h2>5. Exclusions</h2>
<p>Les interventions de terrain (élagage, abattage, haubanage, traitements, etc.) ne sont <b>jamais</b> comprises dans l’abonnement. Elles font l’objet de devis <?= $col ? 'ou marchés ' : '' ?>séparés, réalisés par l’entreprise du choix du Client. Sont également hors forfait : réunions au-delà de celles prévues, déplacements supplémentaires, étude ponctuelle d’un arbre déterminé, <?= $pro ? 'diagnostic d’urgence après événement climatique, ' : '' ?>formats d’export spécifiques. Ils sont réalisés uniquement sur devis complémentaire accepté.</p>

<?php if ($part): ?>
<h2>6. Droit de rétractation</h2>
<p>Lorsque le contrat est conclu à distance (internet, téléphone, signature électronique) ou hors établissement, le Client dispose d’un délai de <b>14 jours</b> à compter de son acceptation pour se rétracter, sans avoir à se justifier ni à payer de pénalité, au moyen du formulaire de l’Annexe 3 ou de toute déclaration dénuée d’ambiguïté adressée à contact@arbogestion.fr ou par courrier. Arbogestion rembourse les sommes versées dans les 14 jours suivant la rétractation. Si le Client demande expressément que les prestations commencent avant la fin de ce délai, il paie le montant correspondant au service fourni jusqu’à sa rétractation, proportionnellement au prix total.</p>
<h2>7. Durée, renouvellement et résiliation</h2>
<p><b>Durée</b> : 1 an à compter de la date de début indiquée en Annexe 2, puis reconduction tacite par périodes d’1 an. <b>Information avant échéance</b> : Arbogestion informe le Client par écrit, entre 3 mois et 1 mois avant l’échéance, de la possibilité de ne pas reconduire le contrat ; le Client peut alors y mettre fin sans frais à l’échéance. <b>Défaut d’information</b> : le Client peut résilier gratuitement à tout moment après la date de reconduction ; les sommes payées pour la période postérieure lui sont remboursées. <b>Après reconduction</b> : résiliation à tout moment avec un préavis d’1 mois. <b>Résiliation en ligne</b> : depuis la page « Résilier mon abonnement » du site arbogestion.fr ou par courriel à contact@arbogestion.fr ; Arbogestion confirme la résiliation et sa date d’effet par courriel. <b>Première année</b> : une résiliation pendant la première année, sans manquement d’Arbogestion, laisse dues les mensualités restant à courir jusqu’à la fin de cette première année, sauf motif légitime justifié (déménagement, vente du bien, décès…).</p>
<h2>8. Prix et paiement</h2>
<p>Forfait selon le nombre d’arbres, conformément à l’Annexe 1, prix TTC. Au-delà de 500 arbres : sur devis. Le palier applicable est indiqué en Annexe 2 ; un changement de palier donne lieu à avenant. <b>Paiement</b> : par prélèvement SEPA (mandat signé en ligne, paiement sécurisé par Stripe), mensuel le 10 de chaque mois, le premier après la fin du délai de rétractation, ou annuel à la date anniversaire. La facture vaut notification préalable de prélèvement. <b>Incident de paiement</b> : après mise en demeure restée sans effet 15 jours, les sommes dues portent intérêt au taux légal et l’accès au logiciel peut être suspendu jusqu’à régularisation, sans perte de données.</p>
<h2>9. Évolution des prix</h2>
<p>Les prix peuvent être révisés à chaque date anniversaire, notamment selon l’indice Syntec, dans la limite de <b>5 % par an</b>. Toute hausse est notifiée au moins <b>2 mois</b> avant l’échéance ; le Client qui la refuse peut résilier sans frais à cette échéance. Une hausse supérieure à 5 % n’est possible qu’avec un préavis de 3 mois et la même faculté de résiliation sans frais.</p>
<?php else: ?>
<h2>6. Durée et renouvellement</h2>
<p>Le contrat est conclu pour <b>1 an</b> à compter de la date de début indiquée en Annexe 2<?= $col ? '. Il est reconduit tacitement par périodes de 1 an, dans la limite de <b>5 reconductions</b> (6 ans au total, durée recommandée pour couvrir le parc à raison de 1/6 par an), sauf dénonciation par l’une des parties, par LRAR ou courriel avec accusé de réception, <b>2 mois au moins</b> avant l’échéance. Arbogestion rappelle ce droit par courriel 3 mois avant l’échéance. Au-delà de 6 ans, la poursuite suppose un nouvel accord écrit.' : ', puis reconduit tacitement par périodes d’1 an, sauf dénonciation par l’une des parties, par LRAR ou courriel avec accusé de réception, <b>2 mois au moins</b> avant l’échéance. Arbogestion rappelle cette faculté par courriel 3 mois avant l’échéance. Durée de suivi recommandée : 6 ans (couverture complète du parc à raison de 1/6 par an).' ?></p>
<h2>7. Prix</h2>
<p>Forfait annuel selon le nombre d’arbres, conformément à l’Annexe 1<?= $col ? '' : ', prix exprimés en HT, TVA au taux en vigueur en sus' ?>. Au-delà de <?= $col ? '4 000' : '500' ?> arbres : sur devis. Le palier applicable figure en Annexe 2 ; un changement de palier en cours de contrat (ajout ou retrait d’arbres) donne lieu à avenant.</p>
<h2>8. Facturation et paiement</h2>
<?php if ($col): ?>
<p>Facture annuelle émise à la date de début puis à chaque date anniversaire (trimestrielle sur demande) et déposée sur Chorus Pro. Paiement par mandat administratif sous <b>30 jours</b> à compter de la réception de la facture. Aucun escompte pour paiement anticipé. En cas de retard : intérêts moratoires et indemnité forfaitaire de recouvrement de 40 €, de plein droit, selon la réglementation en vigueur.</p>
<?php else: ?>
<p>Paiement au choix du Client indiqué en Annexe 2 : par prélèvement SEPA (mandat signé en ligne, paiement sécurisé par Stripe), mensuel le 10 de chaque mois, le premier le 10 du mois suivant la date de début ; ou par prélèvement ou virement annuel, à 30 jours date de facture. La facture vaut notification préalable de prélèvement. Aucun escompte pour paiement anticipé. En cas de retard ou de rejet de prélèvement imputable au Client : pénalités de retard au taux de trois fois le taux d’intérêt légal, exigibles de plein droit, et indemnité forfaitaire pour frais de recouvrement de <b>40 €</b> (articles L.441-10 et D.441-5 du Code de commerce). Après mise en demeure restée sans effet 15 jours, Arbogestion peut suspendre l’accès au service jusqu’à régularisation, sans perte de données.</p>
<?php endif; ?>
<h2>9. Révision des prix</h2>
<p>Révision possible à chaque date anniversaire, notamment selon l’indice Syntec (ou indice de substitution), <b>plafonnée à 5 % par an</b>, notifiée par écrit au moins <?= $col ? '3 mois' : '60 jours' ?> avant l’échéance. En cas de hausse exceptionnelle des coûts (indice Syntec &gt; 10 % sur un an, changement réglementaire majeur), une révision supérieure peut être proposée avec un préavis de 3 mois. Le Client qui refuse peut résilier sans frais à l’échéance.</p>
<?php endif; ?>

<h2><?= $part ? '10' : '10' ?>. Obligations d’Arbogestion</h2>
<p>Fournir les prestations avec diligence (obligation de moyens) ; maintenir le logiciel à jour ; informer le Client des mises à jour majeures et ne pas supprimer de fonctionnalité essentielle sans son accord ; informer le Client sans délai de tout risque grave constaté (danger pour les personnes ou les biens) ; respecter la confidentialité. Arbogestion garantit disposer des droits nécessaires pour mettre le logiciel Grality à disposition du Client.</p>
<h2>11. Obligations du Client</h2>
<p><?= $part ? 'Transmettre les informations utiles et signaler tout événement notable (tempête, casse, chute, travaux) ; permettre l’accès au terrain pour les diagnostics, à une date convenue ; ne pas communiquer ses identifiants à des personnes non autorisées ; maintenir une autorisation de prélèvement valide en cas de paiement par prélèvement. Le Client reste responsable de ses arbres en tant que propriétaire et des décisions prises à partir des recommandations.' : 'Désigner un référent ; transmettre les informations utiles et signaler tout événement notable (tempête, casse, chute, travaux) ; faciliter l’accès aux sites lors des diagnostics' . ($pro ? ', en tenant compte des contraintes d’exploitation' : '') . ' ; valider ou commenter les livrables dans un délai raisonnable ; ne pas partager les accès à des tiers non autorisés ; veiller à l’exactitude des données qu’il saisit' . ($pro ? ' ; maintenir une autorisation de prélèvement valide en cas de paiement par prélèvement' : ' ; respecter ses procédures internes') . '. Tout retard du Client peut décaler les délais d’exécution sans engager la responsabilité d’Arbogestion.' . ($col ? ' Arbogestion n’est pas responsable des contraintes administratives propres au Client public.' : '') ?></p>
<h2>12. Disponibilité et assistance</h2>
<p>Disponibilité cible de 99 % mesurée mensuellement, hors maintenance planifiée (annoncée 48 h à l’avance) et causes externes (coupure internet du Client, attaque visant l’hébergeur). Assistance du lundi au vendredi, 9 h–12 h et 14 h–17 h, jours ouvrés, à contact@arbogestion.fr ou au 06 09 70 46 50. Réponse sous 24 h ouvrées (48 h pour une demande complexe) ; incident critique traité sous 48 h ouvrées ; incident mineur sous 5 jours ouvrés. L’assistance couvre l’usage du logiciel ; l’étude d’un cas particulier relève de l’article 5.</p>
<h2>13. Indépendance et libre choix des prestataires</h2>
<p>Les recommandations d’Arbogestion sont formulées de manière objective, indépendamment de toute prestation de travaux. Le Client confie librement les interventions à l’entreprise de son choix<?= $col ? ', dans le respect des règles de la commande publique' : '' ?>.</p>
<h2>14. Propriété intellectuelle</h2>
<p>Rapports, méthodes, outils et supports créés par Arbogestion restent sa propriété ; le logiciel Grality reste celle de son éditeur. Le Client bénéficie d’une licence non exclusive, non transférable, limitée à la durée du contrat et à ses besoins propres. Il peut communiquer les fiches arbres et rapports à ses assureurs, conseils, prestataires<?= $part ? ', à une mairie, à un acquéreur ou à un notaire' : ($pro ? ', auditeurs, acquéreurs et autorités de contrôle' : '') ?>. Toute autre reproduction, modification ou diffusion requiert l’accord écrit d’Arbogestion.</p>
<h2>15. Données et RGPD</h2>
<p>Les données relatives au patrimoine arboré restent la propriété du Client. Arbogestion les héberge en France et en assure l’accès pendant le contrat ; le Client autorise leur usage sous forme anonymisée pour améliorer les méthodes et outils. Les données personnelles sont traitées conformément au RGPD pour exécuter le contrat et respecter les obligations légales : ni vendues ni louées, données sensibles chiffrées, notification de toute violation dans les meilleurs délais et au plus tard sous 72 h, sous-traitants (hébergement, facturation Pennylane, paiement Stripe) soumis aux mêmes obligations. Conservation pendant le contrat puis archivage pour la durée légale (pièces comptables 10 ans, preuves du contrat 5 ans). Les personnes concernées exercent leurs droits d’accès, de rectification, d’effacement, de limitation, d’opposition et de portabilité à contact@arbogestion.fr et peuvent saisir la CNIL (www.cnil.fr).</p>
<h2>16. Confidentialité</h2>
<p>Chaque partie garde confidentielles les informations non publiques échangées (méthodes, tarifs, devis, diagnostics, bases de données, projets) pendant le contrat et <b>5 ans</b> après son terme, sauf informations déjà publiques ou reçues légitimement d’un tiers.<?= $col ? ' Les obligations de transparence administrative (communication de documents administratifs, publicité des marchés) demeurent applicables.' : '' ?></p>
<h2>17. Responsabilité et assurance</h2>
<p>Arbogestion est tenue d’une obligation de moyens. Sa responsabilité n’est pas engagée si les interventions préconisées ne sont pas réalisées, ni en cas de dommage causé par un événement imprévisible (tempête, foudre) ou par une donnée inexacte transmise par le Client. <?= $part ? 'Aucune disposition du contrat ne limite la responsabilité d’Arbogestion en cas de dommage corporel, de faute lourde ou intentionnelle, ou dans les cas où la loi l’interdit.' : 'Elle est limitée au montant des sommes versées au titre du contrat sur les 12 derniers mois et exclut les dommages indirects (pertes d’exploitation, préjudices immatériels, perte de valeur du patrimoine), sauf dommage corporel, faute lourde ou dolosive.' ?> Arbogestion est couverte par une assurance RC Professionnelle de <b>10 000 000 € par sinistre</b> (France métropolitaine et DOM-TOM).</p>
<h2>18. Suspension et résiliation</h2>
<p><b>Manquement grave</b> (non-paiement persistant, usage frauduleux du logiciel comme le partage d’accès à des tiers non autorisés, inexécution répétée des prestations) : résiliation par l’autre partie après mise en demeure restée sans effet 30 jours<?= $part ? ' ; la résiliation pour manquement d’Arbogestion ouvre droit au remboursement des sommes payées pour la période non exécutée.' : '.' ?>
<?php if ($pro): ?> <b>Résiliation anticipée par le Client</b> hors manquement d’Arbogestion : les sommes dues jusqu’à l’échéance de l’année contractuelle en cours restent exigibles. En cas de cession ou de fermeture définitive du site suivi, le Client peut résilier avec un préavis de 3 mois, sur justificatif.<?php elseif ($col): ?> <b>Résiliation anticipée par le Client</b> sans manquement d’Arbogestion : les sommes dues jusqu’à l’échéance de l’année contractuelle en cours restent exigibles, sauf résiliation pour motif d’intérêt général dans les conditions du Code de la commande publique.<?php endif; ?></p>
<h2>19. Fin de contrat et restitution des données</h2>
<p><?= $part ? 'À la fin du contrat, le Client reçoit <b>gratuitement</b>, sur simple demande dans les 30 jours, un export de ses fiches arbres au format PDF. Un export complet de la base au format Excel est proposé au prix de 150 € TTC ; les formats spécifiques sont sur devis.' : 'Le Client peut demander l’extraction de ses données brutes <b>dans les 30 jours</b> suivant la fin du contrat : export .csv/.xlsx facturé <b>150 € HT</b> ; formats spécifiques (Shapefile…) sur devis.' ?> À défaut de demande dans ce délai, les données du patrimoine sont supprimées au plus tard <b>90 jours</b> après la fin du contrat, sauf obligation légale de conservation.</p>
<h2>20. Force majeure, sous-traitance et cession</h2>
<p>Aucune partie n’est responsable d’une inexécution causée par un événement imprévisible et insurmontable, pour la durée de cet événement<?= $pro ? ' ; au-delà de 3 mois, chaque partie peut résilier sans indemnité' : '' ?>. Arbogestion peut confier certaines tâches (hébergement, maintenance informatique, facturation) à des partenaires soumis aux mêmes obligations et reste responsable envers le Client.<?= $part ? '' : ' Le Client ne peut céder le contrat sans l’accord écrit d’Arbogestion' . ($pro ? ', qui ne peut le refuser sans motif légitime en cas de cession du site suivi.' : '.') ?></p>
<?php if (!$part): ?>
<h2>21. Non-détournement<?= $pro ? ' et non-sollicitation' : '' ?></h2>
<p>Le Client s’interdit d’utiliser les fonctionnalités du logiciel ou les méthodes d’Arbogestion pour créer ou commercialiser un service concurrent, pendant le contrat et 2 ans après sa fin<?= $pro ? ', sous peine d’une indemnité forfaitaire de 10 000 €, sans préjudice de dommages et intérêts. Il s’interdit également de débaucher un salarié d’Arbogestion ayant participé à l’exécution du contrat, pendant le contrat et 12 mois après sa fin, sauf accord écrit.' : '.' ?></p>
<?php endif; ?>
<h2><?= $part ? '21. Réclamations, médiation et droit applicable' : '22. Litiges et droit applicable' ?></h2>
<?php if ($part): ?>
<p>Toute réclamation est adressée par écrit à contact@arbogestion.fr ou par courrier au siège. À défaut de solution amiable dans un délai de 2 mois, le Client peut saisir gratuitement le médiateur de la consommation : CM2C (Centre de médiation de la consommation de conciliateurs de justice), 49 rue de Ponthieu, 75008 Paris, www.cm2c.net. La médiation ne prive pas le Client de son droit d’agir en justice. Contrat soumis au droit français ; le Client peut saisir la juridiction du lieu où il demeurait lors de la conclusion du contrat ou de la survenance du fait dommageable, ou toute autre juridiction compétente selon la loi.</p>
<?php elseif ($pro): ?>
<p>Contrat soumis au droit français. Les parties recherchent d’abord une solution amiable (réclamation écrite, puis réunion dans les 30 jours). À défaut, tout litige est porté devant le Tribunal de commerce de Cahors.</p>
<?php else: ?>
<p>Contrat soumis au droit français. Les parties recherchent d’abord une solution amiable (courrier ou courriel du référent, puis réunion). À défaut, le litige est porté devant le tribunal administratif territorialement compétent lorsque le contrat est un contrat de la commande publique, ou à défaut devant le tribunal du siège d’Arbogestion.</p>
<?php endif; ?>
<h2><?= $part ? '22' : '23' ?>. Acceptation, signature et dispositions générales</h2>
<p>Le présent contrat est accepté par la <b>signature électronique du devis n° <?= h($numero_devis) ?></b> auquel il est joint, sur la plateforme de facturation Pennylane, qui a la valeur d’une signature manuscrite<?= $col ? ', complétée par le bon de commande ou la décision d’engagement de la collectivité' : '' ?>. <?= $col ? '' : 'Le mandat de prélèvement SEPA est signé en ligne via Stripe. ' ?>Toute modification fait l’objet d’un avenant écrit. Arbogestion archive les documents signés 5 ans après la fin du contrat et en remet une copie au Client sur demande. Les notifications se font par courriel avec accusé de réception ou par LRAR aux adresses indiquées ci-dessus.<?= $col ? ' Le Client déclare avoir respecté les règles de publicité et de mise en concurrence applicables à la conclusion du présent contrat.' : '' ?></p>
<div class="sign">Établi à Cambes, le <?= h(date_fr($date_doc)) ?>, pour Arbogestion : Kévin Cazes-Bouchet, Président.<br>Acceptation par le Client : signature électronique du devis n° <?= h($numero_devis) ?> sur Pennylane (date et preuve de signature conservées par la plateforme).</div>

<h2 style="page-break-before:always">Annexe 1 – Grille tarifaire 2026 – <?= h(PROFILS[$p]['nom']) ?></h2>
<p><?= $part ? 'Prix TTC. Paiement mensuel (prélèvement SEPA) ou annuel. Engagement d’1 an, reconductible tacitement.' : ($pro ? 'Paiement mensuel (prélèvement SEPA) ou annuel. Engagement d’1 an, reconductible tacitement. TVA 20 %.' : 'Paiement annuel par mandat administratif via Chorus Pro. Engagement d’un an, reconductible dans les conditions de l’article 6.') ?></p>
<?= $grille ?>
<p>Prestations de démarrage : recensement et diagnostic sur place, selon le devis ; prévisionnel d’intervention <?= eur((float)$T['previsionnelParArbre']) ?> HT par arbre ; minimum facturé : <?= eur((float)$T['tauxHoraire'] * (float)$T['minimumHeures']) ?> HT pour le prévisionnel. Frais de déplacement <?= number_format((float)$T['deplacementKm'], 2, ',', ' ') ?> € HT par km depuis Cambes (aller-retour, par journée sur place), auxquels s’ajoutent, le cas échéant, les frais de péage (aller-retour, estimés au devis). Tests au résistographe : <?= eur((float)$T['resistographe']['base']) ?> HT le test au pied du tronc ; <?= eur(round((float)$T['resistographe']['houppierTauxHoraire'] * (float)$T['resistographe']['houppierHeuresParTest'], 2)) ?> HT par arbre pour le diagnostic dans la couronne de l’arbre. Les interventions de terrain ne sont pas comprises ; elles font l’objet de devis séparés.</p>

<h2>Annexe 2 – Conditions particulières</h2>
<table class="cp"><tbody>
<?php if (!$part): ?><tr><td>Client</td><td><?= h($c['denomination'] ?? '') ?> – SIREN <?= h($c['siren'] ?? '') ?></td></tr><?php else: ?><tr><td>Client</td><td><?= h(trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? ''))) ?></td></tr><?php endif; ?>
<tr><td><?= $part ? 'Adresse(s) du patrimoine suivi' : 'Site(s) suivi(s)' ?></td><td><?= h(($dem['site_adresse'] ?? '') ?: trim(($c['adresse'] ?? '') . ', ' . ($c['cp'] ?? '') . ' ' . ($c['ville'] ?? ''), ', ')) ?></td></tr>
<tr><td>Nombre d’arbres et palier retenu</td><td><?= h($palier_txt) ?></td></tr>
<tr><td><?= $col ? 'Prix annuel' : 'Prix et mode de paiement' ?></td><td><?= h($prix_txt) ?></td></tr>
<tr><td>Date de début</td><td><?= h(date_fr($debut)) ?></td></tr>
<tr><td>Prestations initiales (recensement, diagnostic, prévisionnel)</td><td><?= $demarrage_txt ?></td></tr>
<?php if (!$part): ?><tr><td>Référent du Client</td><td><?= h($c['referent'] ?? '') ?> – <?= h($c['email'] ?? '') ?><?= empty($c['tel']) ? '' : ' – ' . h($c['tel']) ?></td></tr><?php endif; ?>
<?php if ($pro): ?><tr><td>Contraintes d’exploitation (saison, horaires d’accès)</td><td><?= h(($dem['contraintes'] ?? '') ?: 'Néant') ?></td></tr><?php endif; ?>
<?php if ($col): ?><tr><td>Bon de commande / décision d’engagement</td><td><?= h(($c['habilitation'] ?? '') ?: 'À transmettre après signature du devis') ?></td></tr><?php endif; ?>
<?php if ($part): ?><tr><td>Demande d’exécution avant la fin du délai de rétractation</td><td><?= !empty($dem['execution_anticipee']) ? 'Oui' : 'Non' ?></td></tr><?php endif; ?>
<tr><td>Options (utilisateurs supplémentaires, réunions, déplacements)</td><td>Néant, sauf avenant</td></tr>
<tr><td>Validité du devis</td><td>3 mois à compter de son émission</td></tr>
</tbody></table>

<?php if ($part): ?>
<h2>Annexe 3 – Formulaire de rétractation</h2>
<p><i>À compléter et renvoyer uniquement si vous souhaitez vous rétracter, à SAS Arbogestion, 133 rue Madeleine Brès, 46100 Cambes – contact@arbogestion.fr.</i></p>
<p>Je vous notifie par la présente ma rétractation du contrat d’abonnement Arbogestion (devis n° <?= h($numero_devis) ?>) :</p>
<ul><li>Accepté le : ……………………………</li><li>Nom du Client : ……………………………</li><li>Adresse du Client : ……………………………………………………</li><li>Date : ……………………………</li><li>Signature (en cas d’envoi papier) : ……………………………</li></ul>
<?php endif; ?>
<div style="page-break-before:always"><?= cgv_fragment_html($p) ?></div>
</body></html>
<?php
    return (string)ob_get_clean();
}


/** Prestation ponctuelle : conditions particulières (1 page) suivies des CGV du profil, version en vigueur. */
function conditions_ponctuelles_html(array $dem, array $calc, string $numero_devis, string $date_doc): string {
    $p = $dem['profil']; $c = $dem['client']; $T = tarifs();
    $part = $p === 'particulier'; $pro = $p === 'pro'; $col = $p === 'collectivite';
    $titre = $part ? 'Particuliers' : ($pro ? 'Professionnels' : 'Collectivités et personnes publiques');
    $version = cgv_version_courante();
    $retract = $part ? cgv_article('particulier', 'Rétractation') : null;
    $adr = h(trim(($c['adresse'] ?? '') . ', ' . ($c['cp'] ?? '') . ' ' . ($c['ville'] ?? ''), ', '));
    $client = $part ? h(trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? ''))) . ', demeurant ' . $adr . ', ' . h($c['email'] ?? '')
        : h($c['denomination'] ?? '') . ', SIREN ' . h($c['siren'] ?? '') . ', ' . $adr . ', représenté(e) par ' . h($c['representant'] ?? '') . ($col && !empty($c['habilitation']) ? ', habilité(e) par ' . h($c['habilitation']) : '') . '. Référent : ' . h($c['referent'] ?? '') . ', ' . h($c['email'] ?? '');
    $presta = array_map(fn($l) => h($l['label']), $calc['lignes_demarrage']);
    $montant = $part ? eur(round($calc['demarrage_ht'] * (1 + $T['tva']), 2)) . ' TTC (' . eur($calc['demarrage_ht']) . ' HT)' : eur($calc['demarrage_ht']) . ' HT (' . eur(round($calc['demarrage_ht'] * (1 + $T['tva']), 2)) . ' TTC)';
    $acompte = $col ? 'Aucun – facture après réalisation, déposée sur Chorus Pro' : (int)$T['acompte']['pourcentage'] . ' % du devis à la signature, solde après réalisation, par carte bancaire';
    ob_start(); ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
@page{margin:16mm 16mm 18mm 16mm}
body{font-family:'DejaVu Sans',sans-serif;font-size:8.3pt;line-height:1.38;color:#2b2b2b}
h1{font-size:15pt;color:#1e3d33;margin:0 0 4px}h2{font-size:10pt;color:#1e3d33;margin:9px 0 3px;border-bottom:1.5px solid #b7c93b;padding-bottom:2px}
p{margin:0 0 5px;text-align:justify}.sous{color:#5f655f;font-size:8pt}.entete{width:100%;margin-bottom:10px}.entete td{vertical-align:middle}
.ref{background:#f2f6dc;border-left:3px solid #8fa224;padding:6px 9px;margin:8px 0}
table.cp{width:100%;border-collapse:collapse;margin:6px 0}table.cp td{border-bottom:0.5px solid #d9dcd4;padding:2.5px 6px}table.cp td:first-child{width:40%;color:#1e3d33;font-weight:bold}
.sign{margin-top:10px;border:1px solid #b7c93b;padding:8px 10px;background:#fafbf6}
.pied{position:fixed;bottom:-12mm;left:0;right:0;font-size:7pt;color:#8a8f88;text-align:center}
<?= CGV_STYLE_PDF ?>
</style></head><body>
<div class="pied">Arbogestion – SAS au capital de 1 000 € – RCS Cahors 105 612 543 – 133 rue Madeleine Brès, 46100 Cambes – Prestation ponctuelle – Devis n° <?= h($numero_devis) ?></div>
<table class="entete"><tr><td style="width:70px"><img src="<?= h(ARBO_APP . '/assets/logo.png') ?>" style="width:62px"></td>
<td><h1>Conditions particulières – prestation ponctuelle</h1><div class="sous"><?= h($titre) ?> – sans abonnement ni engagement de durée – conditions générales de vente du <?= h(date_fr($version)) ?> jointes</div></td></tr></table>
<div class="ref">Le présent document est joint au devis n° <b><?= h($numero_devis) ?></b> du <?= h(date_fr($date_doc)) ?>, avec les conditions générales de vente Arbogestion (version du <?= h(date_fr($version)) ?>) qui le suivent. <b>La signature électronique de ce devis vaut acceptation des présentes conditions particulières et des conditions générales de vente.</b><?= $col ? ' Pour les collectivités, elle est complétée par la transmission du bon de commande.' : '' ?> Ordre de priorité : (1) le devis, (2) les présentes conditions particulières, (3) les conditions générales de vente.</div>
<h2>Les parties</h2>
<p><b>Le Prestataire</b> : <?= h(PRESTATAIRE) ?></p>
<p><b>Le Client</b> : <?= $client ?>. Ci-après « le Client ».</p>
<h2>Prestation commandée</h2>
<table class="cp"><tbody>
<tr><td>Site concerné</td><td><?= h(($dem['site_adresse'] ?? '') ?: trim(($c['adresse'] ?? '') . ', ' . ($c['cp'] ?? '') . ' ' . ($c['ville'] ?? ''), ', ')) ?></td></tr>
<tr><td>Nombre d’arbres</td><td><?= (int)$calc['nb'] ? (int)$calc['nb'] . ' (' . (int)$T['arbresParJour'] . ' arbres maximum par journée sur place)' : 'Sans objet' ?></td></tr>
<tr><td>Prestations retenues</td><td><?= implode(' ; ', $presta) ?></td></tr>
<tr><td>Livrable</td><td><?= (int)$calc['nb'] ? 'Compte rendu écrit avec les préconisations d’Arbogestion (interventions conseillées et priorités), compris dans le prix' : 'Selon le devis' ?></td></tr>
<tr><td>Montant</td><td><?= $montant ?> – détail au devis n° <?= h($numero_devis) ?></td></tr>
<tr><td>Acompte et paiement</td><td><?= h($acompte) ?></td></tr>
<tr><td>Période souhaitée</td><td>À partir du <?= h(date_fr($dem['date_debut'] ?? date('Y-m-d'))) ?>, date fixée d’un commun accord après signature</td></tr>
<?php if ($part): ?><tr><td>Exécution avant la fin du délai de rétractation</td><td><?= !empty($dem['execution_anticipee']) ? 'Oui, demandée expressément par le Client' : 'Non' ?></td></tr>
<tr><td>Droit de rétractation</td><td>14 jours – article <?= (int)$retract ?> des conditions générales de vente ; formulaire en fin de document</td></tr><?php endif; ?>
<tr><td>Interventions de terrain (élagage, abattage…)</td><td>Non comprises : devis séparés, auprès de l’entreprise du choix du Client</td></tr>
<tr><td>Validité du devis</td><td>3 mois à compter de son émission</td></tr>
</tbody></table>
<div class="sign">Établi à Cambes, le <?= h(date_fr($date_doc)) ?>, pour Arbogestion : Kévin Cazes-Bouchet, Président.<br>Acceptation par le Client : signature électronique du devis n° <?= h($numero_devis) ?> sur Pennylane<?= $col ? ', complétée par le bon de commande' : '' ?>.</div>
<div style="page-break-before:always"><?= cgv_fragment_html($p, $version) ?></div>
</body></html>
<?php
    return (string)ob_get_clean();
}

function contrat_pdf(array $dem, array $calc, string $numero_devis, string $date_doc, string $chemin): string {
    require_once ARBO_APP . '/lib/dompdf/autoload.inc.php';
    $opts = new \Dompdf\Options();
    $opts->set('defaultFont', 'DejaVu Sans');
    $opts->set('isRemoteEnabled', false);
    $opts->setChroot([ARBO_APP]);
    $tmp = ARBO_APP . '/storage/tmp';
    if (!is_dir($tmp)) { @mkdir($tmp, 0750, true); }
    $opts->set('tempDir', $tmp);
    $opts->set('fontCache', $tmp);
    $pdf = new \Dompdf\Dompdf($opts);
    $html = empty($calc['abonnement']) ? conditions_ponctuelles_html($dem, $calc, $numero_devis, $date_doc) : contrat_html($dem, $calc, $numero_devis, $date_doc);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4');
    $pdf->render();
    if (!is_dir(dirname($chemin))) { @mkdir(dirname($chemin), 0750, true); }
    file_put_contents($chemin, $pdf->output());
    return $chemin;
}
