<?php
// Saisie d'un devis dans l'espace connecté (inclus par index.php, utilisateur déjà authentifié).
declare(strict_types=1);
if (!function_exists('espace_connecte') || !espace_connecte()) { http_response_code(403); exit; }

const UNITES = ['heure', 'demi-journée', 'journée', 'forfait', 'arbre', 'test', 'km', 'réunion', 'unité'];
const FORMULES = [
    'libre' => 'Lignes libres uniquement (conseil, appui technique…)',
    'ponctuel' => 'Diagnostic ponctuel selon la grille',
    'demarrage' => 'Démarrage + abonnement annuel selon la grille',
    'abonnement' => 'Abonnement annuel seul (parc déjà recensé)',
];

function v(string $k, string $def = ''): string { return (string)($_POST[$k] ?? $def); }
function nombre_fr(string $s): ?float { $s = str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], trim($s)); return is_numeric($s) ? (float)$s : null; }

/** Construit la demande et le calcul à partir du formulaire. Renvoie [$dem, $calc, $opt]. */
function lire_formulaire(bool $brouillon = false): array {
    $profil = v('profil'); $formule = v('formule', 'libre');
    if (!isset(FORMULES[$formule])) { throw new ErreurDemande('Formule inconnue.'); }
    $grille = $formule !== 'libre';
    $nb = (int)v('nb', '0');
    if ($grille && $nb < 1) { throw new ErreurDemande($brouillon ? 'Indique le nombre d’arbres pour lancer le calcul.' : 'Indiquez le nombre d’arbres pour un calcul selon la grille.'); }
    $km = trim(v('km')) === '' ? null : nombre_fr(v('km'));
    $in = [
        'profil' => $profil,
        'client' => ['prenom' => v('prenom'), 'nom' => v('nom'), 'denomination' => v('denomination'), 'forme' => v('forme'), 'siren' => v('siren'),
            'adresse' => v('adresse'), 'cp' => v('cp'), 'ville' => v('ville'), 'representant' => v('representant'), 'referent' => v('referent'),
            'habilitation' => v('habilitation'), 'email' => v('email'), 'tel' => v('tel')],
        'estimation' => ['nb' => max(1, $nb), 'demarrage' => in_array($formule, ['libre', 'ponctuel', 'demarrage'], true), 'diag' => true, 'prev' => true,
            'rb' => (int)v('rb', '0'), 'rh' => (int)v('rh', '0'), 'km' => $km, 'km_peage' => nombre_fr(v('km_peage')) ?? 0, 'duree_h' => nombre_fr(v('duree_h')), 'abonnement' => in_array($formule, ['demarrage', 'abonnement'], true)],
        'consentement' => true, 'paiement' => v('paiement', 'annuel'), 'date_debut' => v('date_debut'),
        'site_adresse' => v('site_adresse'), 'lieu_label' => v('lieu_label'), 'contraintes' => v('contraintes'), 'message' => '',
        'execution_anticipee' => v('execution_anticipee') === '1',
    ];
    if ($brouillon) { // calcul en direct : la fiche client peut être incomplète
        $in['client'] = ['prenom' => 'A', 'nom' => 'B', 'denomination' => 'C', 'forme' => '', 'siren' => '000000000', 'adresse' => 'x', 'cp' => '46100', 'ville' => 'x',
            'representant' => 'x', 'referent' => '', 'habilitation' => '', 'email' => 'calcul@exemple.fr', 'tel' => ''];
    }
    $dem = valider_demande($in);
    if ($grille) {
        $calc = calculer($dem['estimation']);
    } else {
        $calc = ['nb' => max(0, $nb), 'lignes_demarrage' => [], 'demarrage_ht' => 0.0, 'abonnement' => null, 'jours' => 0, 'houppier_sur_devis' => false, 'acompte_ht' => 0.0];
        $dem['estimation']['nb'] = max(0, $nb);
    }
    // Lignes libres
    $lib = (array)($_POST['l_label'] ?? []); $nlib = 0;
    foreach ($lib as $i => $label) {
        $label = nettoyer((string)$label, 200);
        $qte = nombre_fr((string)($_POST['l_qte'][$i] ?? '')); $pu = nombre_fr((string)($_POST['l_pu'][$i] ?? ''));
        if ($label === '' && $qte === null && $pu === null) { continue; }
        if ($brouillon && ($label === '' || $qte === null || $qte <= 0 || $pu === null || $pu < 0)) { continue; }
        if ($label === '' || $qte === null || $qte <= 0 || $pu === null || $pu < 0) { throw new ErreurDemande('Ligne libre n° ' . ($i + 1) . ' incomplète : libellé, quantité et prix unitaire HT sont nécessaires.'); }
        $unite = (string)($_POST['l_unite'][$i] ?? 'forfait'); if (!in_array($unite, UNITES, true)) { $unite = 'forfait'; }
        $pid = preg_replace('/\D/', '', (string)($_POST['l_produit'][$i] ?? ''));
        $calc['lignes_demarrage'][] = ['label' => $label, 'desc' => nettoyer((string)($_POST['l_desc'][$i] ?? ''), 300), 'qte' => round($qte, 2), 'unite' => $unite, 'pu' => round($pu, 2),
            'libre' => true, 'produit_id' => $pid, 'catalogue' => in_array((string)$i, array_map('strval', (array)($_POST['l_cat'] ?? [])), true)];
        $nlib++;
    }
    if (!$grille && !$nlib && !$brouillon) { throw new ErreurDemande('Ajoutez au moins une ligne libre, ou choisissez un calcul selon la grille.'); }
    // Totaux et acompte (même règle que le site)
    $calc['demarrage_ht'] = round(array_sum(array_map(fn($l) => round($l['qte'] * $l['pu'], 2), $calc['lignes_demarrage'])), 2);
    $A = tarifs()['acompte'] ?? null;
    $calc['acompte_ht'] = ($A && in_array($dem['profil'], $A['profils'], true) && $calc['demarrage_ht'] > 0) ? round($calc['demarrage_ht'] * $A['pourcentage'] / 100, 2) : 0.0;
    $opt = ['origine' => 'espace', 'objet' => nettoyer(v('objet'), 150), 'mention' => nettoyer(v('mention'), 600), 'validite_mois' => max(1, min(12, (int)v('validite', '3')))];
    return [$dem, $calc, $opt];
}

/* ---------- Appels du formulaire (JSON) : articles Pennylane et calcul en direct ---------- */
function json_sortie(array $d): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if (isset($_GET['produits'])) {
    try { if (!empty($_GET['rafraichir'])) { pl_produits(true); } json_sortie(['ok' => true, 'produits' => produits_chercher((string)$_GET['produits'])]); }
    catch (Throwable $e) { json_sortie(['ok' => false, 'erreur' => str_contains($e->getMessage(), 'HTTP 403') || str_contains($e->getMessage(), 'HTTP 401') ? 'Le jeton Pennylane n’a pas le droit « Produits » (lecture et écriture).' : 'Catalogue Pennylane indisponible.']); }
}
if ($post && v('action') === 'calcul') {
    try {
        [$dem, $calc] = lire_formulaire(true);
        $T = tarifs(); $ht = (float)$calc['demarrage_ht']; $ab = $calc['abonnement'];
        json_sortie(['ok' => true, 'lignes' => array_map(fn($l) => ['label' => $l['label'], 'desc' => $l['desc'], 'qte' => $l['qte'], 'unite' => $l['unite'], 'pu' => $l['pu'], 'total' => round($l['qte'] * $l['pu'], 2), 'libre' => !empty($l['libre'])], $calc['lignes_demarrage']),
            'ht' => $ht, 'tva' => round($ht * $T['tva'], 2), 'ttc' => round($ht * (1 + $T['tva']), 2), 'acompte_ttc' => round($calc['acompte_ht'] * (1 + $T['tva']), 2),
            'abonnement' => $ab ? (empty($ab['sur_devis']) ? ['max' => (int)$ab['max'], 'prix' => (float)$ab['prix'], 'prix_an1' => $ab['prix_an1'] ?? null] : ['sur_devis' => true]) : null,
            'jours' => $calc['jours'] ?? 0]);
    } catch (ErreurDemande $e) { json_sortie(['ok' => false, 'erreur' => $e->getMessage()]); }
    catch (Throwable $e) { json_sortie(['ok' => false, 'erreur' => 'Calcul impossible pour l’instant.']); }
}

$erreur = ''; $apercu = '';
if ($post) {
    try {
        [$dem, $calc, $opt] = lire_formulaire();
        $empreinte = hash('sha256', json_encode([$dem, $calc, $opt]));
        if (v('action') === 'creer') {
            if (!hash_equals((string)($_SESSION['apercu'] ?? ''), $empreinte)) { throw new ErreurDemande('Le devis a changé depuis l’aperçu : vérifiez le nouvel aperçu puis validez.'); }
            if (hash_equals((string)($_SESSION['dernier_cree'] ?? ''), $empreinte)) { throw new ErreurDemande('Ce devis vient déjà d’être créé.'); }
            $_SESSION['dernier_cree'] = $empreinte; unset($_SESSION['apercu']);
            $r = creer_devis($dem, $calc, $opt);
            journal('Espace : devis ' . $r['numero'] . ' créé (demande ' . $r['id'] . ')');
            if ($r['erreur']) { message('!Le devis est enregistré mais n’a pas pu être créé dans Pennylane : ' . $r['erreur']); }
            elseif (v('envoyer') === '1') {
                try { envoyer_devis((int)$r['id']); message('Devis ' . $r['numero'] . ' créé et envoyé au client pour signature.'); }
                catch (Throwable $e) { message('Devis ' . $r['numero'] . ' créé dans Pennylane. Envoi pas encore possible : ' . $e->getMessage()); }
            } else { message('Devis ' . $r['numero'] . ' créé dans Pennylane, avec son annexe. Relis-le puis envoie-le au client.'); }
            aller('fiche', ['id' => $r['id']]);
        }
        $_SESSION['apercu'] = $empreinte;
        $T = tarifs(); $tva = (float)$T['tva']; $tr = '';
        foreach ($calc['lignes_demarrage'] as $l) {
            $tr .= '<tr><td>' . h($l['label']) . ($l['desc'] ? '<br><span class="aide">' . h($l['desc']) . '</span>' : '') . '</td><td class="n">' . h(str_replace('.', ',', (string)$l['qte'])) . ' ' . h($l['unite']) . '</td><td class="n">' . eur((float)$l['pu']) . '</td><td class="n">' . eur(round($l['qte'] * $l['pu'], 2)) . '</td></tr>';
        }
        $ht = (float)$calc['demarrage_ht'];
        $ab = $calc['abonnement'];
        $apercu = '<div class="carte" id="apercu"><h2>Aperçu du devis – ' . h(nom_client($dem)) . '</h2>'
            . ($tr ? '<div class="tab"><table><thead><tr><th>Prestation</th><th class="n">Quantité</th><th class="n">PU HT</th><th class="n">Total HT</th></tr></thead><tbody>' . $tr
                . '<tr class="total"><td colspan="3">Total HT</td><td class="n">' . eur($ht) . '</td></tr><tr><td colspan="3">TVA 20 %</td><td class="n">' . eur(round($ht * $tva, 2)) . '</td></tr><tr class="total"><td colspan="3">Total TTC</td><td class="n">' . eur(round($ht * (1 + $tva), 2)) . '</td></tr></tbody></table></div>' : '')
            . ($ab ? '<p style="margin-top:12px"><b>Abonnement :</b> ' . (empty($ab['sur_devis']) ? h(PROFILS[$dem['profil']]['formule']) . ', jusqu’à ' . (int)$ab['max'] . ' arbres, ' . (!empty($ab['prix_an1']) ? '1re année ' . eur((float)$ab['prix_an1']) . ' HT, puis ' : '') . eur((float)$ab['prix']) . ' HT par an (' . h($dem['paiement']) . ')' : 'au-delà du dernier palier : à chiffrer à la main dans Pennylane') . '</p>' : '')
            . ($calc['acompte_ht'] > 0 ? '<p><b>Acompte à la signature :</b> ' . eur(round($calc['acompte_ht'] * (1 + $tva), 2)) . ' TTC (' . (int)$T['acompte']['pourcentage'] . ' %).</p>' : '')
            . '<p class="aide">Annexe jointe automatiquement : ' . ($ab ? 'contrat d’abonnement' : 'conditions particulières') . ' + CGV ' . h(PROFILS[$dem['profil']]['nom']) . ' (version du ' . h(date_fr(cgv_version_courante())) . '). Modèle Pennylane : Devis Arbogestion.</p>'
            . '<label class="coche"><input type="checkbox" name="envoyer" value="1" form="f-devis"' . (v('envoyer') === '1' ? ' checked' : '') . '> Envoyer tout de suite au client (' . h($dem['client']['email']) . ') pour signature électronique</label>'
            . '<div class="actions"><button class="btn" type="submit" name="action" value="creer" form="f-devis">Créer le devis dans Pennylane</button><span class="aide">Sinon, modifie le formulaire ci-dessous et refais l’aperçu.</span></div></div>';
    } catch (ErreurDemande $e) { $erreur = $e->getMessage(); }
    catch (Throwable $e) { journal('Espace devis : ' . $e->getMessage()); $erreur = 'Erreur technique : ' . $e->getMessage(); }
}

/* ---------- Formulaire ---------- */
function champ(string $nom, string $label, string $type = 'text', array $attr = [], string $aide = ''): string {
    $a = ''; foreach ($attr as $k => $x) { $a .= ' ' . $k . ($x === true ? '' : '="' . h((string)$x) . '"'); }
    return '<div><label for="' . $nom . '">' . h($label) . '</label><input type="' . $type . '" id="' . $nom . '" name="' . $nom . '" value="' . h(v($nom)) . '"' . $a . '>' . ($aide ? '<p class="aide">' . h($aide) . '</p>' : '') . '</div>';
}
function choix(string $nom, string $label, array $options, string $def = ''): string {
    $o = ''; foreach ($options as $k => $x) { $o .= '<option value="' . h((string)$k) . '"' . (v($nom, $def) === (string)$k ? ' selected' : '') . '>' . h($x) . '</option>'; }
    return '<div><label for="' . $nom . '">' . h($label) . '</label><select id="' . $nom . '" name="' . $nom . '">' . $o . '</select></div>';
}
$lignes = '';
$nl = max(4, count((array)($_POST['l_label'] ?? [])));
for ($i = 0; $i < $nl; $i++) {
    $u = ''; foreach (UNITES as $x) { $u .= '<option' . ((($_POST['l_unite'][$i] ?? 'heure') === $x) ? ' selected' : '') . '>' . h($x) . '</option>'; }
    $pid = (string)($_POST['l_produit'][$i] ?? ''); $cat = in_array((string)$i, array_map('strval', (array)($_POST['l_cat'] ?? [])), true);
    $lignes .= '<tr><td><input type="text" name="l_label[]" class="l-label" aria-label="Libellé" placeholder="Libellé, ou recherche dans tes articles Pennylane" value="' . h((string)($_POST['l_label'][$i] ?? '')) . '"><input type="hidden" name="l_produit[]" class="l-produit" value="' . h($pid) . '">'
        . '<input type="text" name="l_desc[]" aria-label="Description" placeholder="Description (facultatif)" style="margin-top:6px" value="' . h((string)($_POST['l_desc'][$i] ?? '')) . '">'
        . '<div class="l-pl"><span class="pastille p-ok l-lie"' . ($pid ? '' : ' hidden') . '>Article Pennylane</span><label class="coche l-cat"' . ($pid ? ' hidden' : '') . '><input type="checkbox" name="l_cat[]" value="' . $i . '"' . ($cat ? ' checked' : '') . '> Enregistrer comme article dans Pennylane</label></div></td>'
        . '<td style="width:90px"><input type="text" name="l_qte[]" inputmode="decimal" aria-label="Quantité" value="' . h((string)($_POST['l_qte'][$i] ?? '')) . '"></td>'
        . '<td style="width:140px"><select name="l_unite[]" aria-label="Unité">' . $u . '</select></td>'
        . '<td style="width:110px"><input type="text" name="l_pu[]" inputmode="decimal" aria-label="Prix unitaire HT" value="' . h((string)($_POST['l_pu'][$i] ?? '')) . '"></td></tr>';
}
$T = tarifs();
$html = '<p class="m-cache"><a href="./#devis">← Tous les devis</a></p><h1>Nouveau devis</h1><p class="sous m-cache">Le devis est créé dans Pennylane avec le modèle « Devis Arbogestion », l’annexe et les CGV du bon public. Taux horaire de la grille : ' . eur((float)$T['tauxHoraire']) . ' HT.</p>'
    . ($erreur ? '<div class="msg err" role="alert">' . h($erreur) . '</div>' : '') . $apercu
    . '<div class="dv"><div class="dv-f"><form method="post" action="./?p=devis#apercu" id="f-devis">' . espace_csrf_champ()
    . '<div class="carte"><h2>Client</h2>' . choix('profil', 'Type de client', ['collectivite' => 'Collectivité', 'particulier' => 'Particulier', 'pro' => 'Professionnel'], 'collectivite')
    . '<div class="grille" data-pour="particulier">' . champ('prenom', 'Prénom') . champ('nom', 'Nom') . '</div>'
    . '<div class="grille" data-pour="pro collectivite">' . champ('denomination', v('profil') === 'collectivite' ? 'Nom de la collectivité' : 'Raison sociale') . champ('siren', 'SIREN', 'text', ['inputmode' => 'numeric', 'maxlength' => '11'])
    . '<div data-pour="pro">' . champ('forme', 'Forme juridique', 'text', ['placeholder' => 'SARL, SAS…']) . '</div>'
    . champ('representant', 'Représentant (nom et fonction)', 'text', ['placeholder' => 'Ex. Jean Martin, maire']) . champ('referent', 'Interlocuteur référent')
    . '<div data-pour="collectivite">' . champ('habilitation', 'Habilitation du signataire', 'text', ['placeholder' => 'Ex. délibération du 12/10/2026']) . '</div></div>'
    . '<div class="grille">' . champ('email', 'E-mail (reçoit le devis)', 'email', ['required' => true]) . champ('tel', 'Téléphone', 'tel') . '</div>'
    . '<div class="grille">' . champ('adresse', 'Adresse', 'text', ['required' => true]) . champ('cp', 'Code postal', 'text', ['required' => true, 'maxlength' => '10']) . champ('ville', 'Ville', 'text', ['required' => true]) . '</div>'
    . '<div class="grille">' . champ('site_adresse', 'Adresse du site, si différente') . champ('lieu_label', 'Lieu ou nom du site (pour le devis)') . '</div></div>'

    . '<div class="carte"><h2>Prestations selon la grille</h2>' . choix('formule', 'Formule', FORMULES, 'libre')
    . '<div class="grille" data-formule="grille">' . champ('nb', 'Nombre d’arbres', 'number', ['min' => '0', 'max' => '10000'])
    . '<div data-formule="ponctuel demarrage">' . champ('rb', 'Tests au résistographe au pied de l’arbre', 'number', ['min' => '0', 'max' => '500']) . '</div>'
    . '<div data-formule="ponctuel demarrage">' . champ('rh', 'Diagnostics dans la couronne (arbres)', 'number', ['min' => '0', 'max' => '100']) . '</div>'
    . '<div data-formule="ponctuel demarrage">' . champ('km', 'Distance depuis Cambes par la route (km, aller)', 'text', ['inputmode' => 'decimal'], 'Calculée toute seule à partir de l’adresse du site (ou du client). Facturée aller-retour.') . '</div><div data-formule="ponctuel demarrage">' . champ('km_peage', 'Dont km d’autoroute à péage (aller)', 'text', ['inputmode' => 'decimal']) . '</div><div data-formule="ponctuel demarrage">' . champ('duree_h', 'Temps de trajet aller (h)', 'text', ['inputmode' => 'decimal'], 'Au-delà de ' . (int)(tarifs()['eloignement']['heuresTrajetMax'] ?? 3) . ' h : hébergement et repas ajoutés.') . '</div>'
    . '<div data-formule="demarrage abonnement" data-pour="particulier pro">' . choix('paiement', 'Paiement de l’abonnement', ['annuel' => 'Annuel', 'mensuel' => 'Mensuel (prélèvement SEPA)'], 'annuel') . '</div>'
    . champ('date_debut', 'Date de début souhaitée', 'date') . '</div><p class="aide" id="dist-info" data-formule="ponctuel demarrage"></p>'
    . '<div data-pour="particulier"><label class="coche"><input type="checkbox" name="execution_anticipee" value="1"' . (v('execution_anticipee') === '1' ? ' checked' : '') . '> Le client demande que la prestation commence avant la fin du délai de rétractation de 14 jours</label></div></div>'

    . '<div class="carte"><h2>Lignes libres</h2><p class="aide" style="margin-top:-6px">Conseil, appui technique sur chantier, réunion, vacation… Elles s’ajoutent au calcul de la grille. Prix HT, TVA 20 %.</p>'
    . '<div class="tab lignes"><table id="t-lignes"><thead><tr><th>Libellé et description</th><th>Qté</th><th>Unité</th><th>PU HT (€)</th></tr></thead><tbody>' . $lignes . '</tbody></table></div>'
    . '<div class="actions" style="margin-top:8px"><button type="button" class="btn sec petit" id="ajout-ligne">Ajouter une ligne</button><button type="button" class="btn sec petit" id="pl-rafraichir">Actualiser les articles Pennylane</button><span class="aide" id="pl-etat"></span></div></div>'

    . '<div class="carte"><h2>Présentation</h2><div class="grille">' . champ('objet', 'Objet du devis (facultatif)', 'text', ['placeholder' => 'Par défaut : Diagnostic ponctuel, Prestation de conseil…'])
    . choix('validite', 'Validité', ['1' => '1 mois', '2' => '2 mois', '3' => '3 mois', '6' => '6 mois'], '3') . '</div>'
    . '<label for="mention">Mention particulière (facultatif, en tête des conditions du devis)</label><textarea id="mention" name="mention" maxlength="600">' . h(v('mention')) . '</textarea>'
    . '<div data-pour="pro">' . '<label for="contraintes">Contraintes d’exploitation (saison, horaires d’accès)</label><input type="text" id="contraintes" name="contraintes" value="' . h(v('contraintes')) . '"></div>'
    . '<div class="actions"><button class="btn" type="submit" name="action" value="apercu">Voir l’aperçu</button><span class="aide">Rien n’est créé avant ta validation de l’aperçu.</span></div></div>'
    . '</form></div>'
    . '<aside class="dv-r"><div class="carte" id="calcul"><h2>Calcul en direct</h2><div id="calcul-corps"><p class="aide">Choisis une formule et le nombre d’arbres, ou remplis une ligne libre : le chiffrage s’affiche ici au fur et à mesure.</p></div>'
    . '<p class="aide" style="margin-top:12px">Les lignes sont reliées à tes articles Pennylane du même nom ; ceux de la grille sont créés dans Pennylane s’ils n’existent pas encore.</p></div></aside></div>';
$html = '<style>main.in{max-width:88rem}.dv{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:20px;align-items:start}.dv-r{position:sticky;top:76px}
.dv-r table{font-size:.86rem}.dv-r td{padding:6px 8px}.dv-r .tot td{font-weight:900;background:#fafbf6}#calcul-corps .lib{color:var(--gris);font-size:.78rem}
.l-pl{display:flex;gap:10px;align-items:center;margin-top:4px}.l-pl .coche{margin:2px 0;font-size:.85rem;color:var(--gris)}
.pl-liste{position:absolute;left:0;right:0;top:100%;z-index:60;margin:4px 0 0;padding:4px;list-style:none;background:#fff;border:1px solid #cfd6cc;border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.14);max-height:300px;overflow:auto}
.pl-liste li{padding:9px 12px;border-radius:7px;cursor:pointer;color:var(--vert);display:flex;justify-content:space-between;gap:10px}.pl-liste li small{color:var(--gris)}.pl-liste li.actif,.pl-liste li:hover{background:#f1f5e0}.pl-liste .vide{cursor:default;color:var(--gris)}
.pl-box{position:relative}.dv-f{min-width:0}
@media (max-width:760px){#t-lignes thead{display:none}#t-lignes,#t-lignes tbody{display:block}#t-lignes tr{display:grid;grid-template-columns:1fr 1.3fr 1fr;gap:6px;padding:10px;border-bottom:1px solid var(--ligne)}#t-lignes td{display:block;width:auto!important;padding:0;border:0}#t-lignes td:first-child{grid-column:1/-1}
#t-lignes td:nth-child(2)::before{content:"Qté"}#t-lignes td:nth-child(3)::before{content:"Unité"}#t-lignes td:nth-child(4)::before{content:"PU HT (€)"}#t-lignes td:not(:first-child)::before{display:block;font-size:.75rem;font-weight:700;color:var(--gris);margin-bottom:2px}.lignes{overflow:visible}}#dist-info{margin-top:6px}
@media (max-width:1100px){.dv{grid-template-columns:1fr}.dv-r{position:static}}</style>' . $html;

function script_devis(): void { global $nonce; $D = tarifs()['depart']; ?>
<script src="/js/adresse.js?v=3" nonce="<?= h($nonce) ?>"></script>
<script nonce="<?= h($nonce) ?>">
(function(){
  var $=function(i){return document.getElementById(i)};
  var prof=$('profil'),form=$('formule'),f=$('f-devis');if(!prof)return;
  function maj(){var p=prof.value,fo=form.value;var ld=document.querySelector('label[for=denomination]');if(ld)ld.textContent=p==='collectivite'?'Nom de la collectivité':'Raison sociale';
    document.querySelectorAll('[data-pour]').forEach(function(el){var ok=el.dataset.pour.split(' ').indexOf(p)>=0;
      if(el.dataset.formule){var fs=el.dataset.formule.split(' ');ok=ok&&(fs.indexOf(fo)>=0||(fs.indexOf('grille')>=0&&fo!=='libre'));}el.hidden=!ok;});
    document.querySelectorAll('[data-formule]:not([data-pour])').forEach(function(el){var fs=el.dataset.formule.split(' ');el.hidden=!(fs.indexOf(fo)>=0||(fs.indexOf('grille')>=0&&fo!=='libre'));});}
  prof.addEventListener('change',maj);form.addEventListener('change',maj);maj();

  /* ----- Calcul en direct ----- */
  var eur=function(n){return Number(n).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'};
  var esc=function(t){var d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML};
  var tmr=null,num=0;
  function calculer(){clearTimeout(tmr);tmr=setTimeout(function(){var k=++num,fd=new FormData(f);fd.set('action','calcul');
    fetch('./?p=devis',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){if(k!==num)return;var c=$('calcul-corps');
      if(!d.ok){c.innerHTML='<p class="aide">'+esc(d.erreur||'')+'</p>';return}
      var h='';if(d.lignes.length){h+='<div class="tab"><table><tbody>';d.lignes.forEach(function(l){h+='<tr><td>'+esc(l.label)+(l.libre?' <span class="lib">ligne libre</span>':'')+'<br><span class="aide">'+esc(String(l.qte).replace('.',','))+' '+esc(l.unite)+' × '+eur(l.pu)+'</span></td><td class="n">'+eur(l.total)+'</td></tr>'});
        h+='<tr class="tot"><td>Total HT</td><td class="n">'+eur(d.ht)+'</td></tr><tr><td>TVA 20 %</td><td class="n">'+eur(d.tva)+'</td></tr><tr class="tot"><td>Total TTC</td><td class="n">'+eur(d.ttc)+'</td></tr></tbody></table></div>';}
      else h+='<p class="aide">Aucune prestation chiffrée pour l’instant.</p>';
      if(d.acompte_ttc>0)h+='<p style="margin:10px 0 0"><b>Acompte à la signature :</b> '+eur(d.acompte_ttc)+' TTC</p>';
      if(d.abonnement)h+='<p style="margin:10px 0 0"><b>Abonnement :</b> '+(d.abonnement.sur_devis?'au-delà du dernier palier, à chiffrer':'jusqu’à '+d.abonnement.max+' arbres, '+(d.abonnement.prix_an1?'1re année '+eur(d.abonnement.prix_an1)+' HT, puis ':'')+eur(d.abonnement.prix)+' HT / an')+'</p>';
      if(d.jours)h+='<p class="aide">Terrain estimé : '+d.jours+' journée'+(d.jours>1?'s':'')+'.</p>';
      c.innerHTML=h;}).catch(function(){})},350)}
  f.addEventListener('input',calculer);f.addEventListener('change',calculer);calculer();

  /* ----- Distance depuis Cambes (Base Adresse Nationale + itinéraire IGN) ----- */
  var D={lon:<?= (float)$D['lon'] ?>,lat:<?= (float)$D['lat'] ?>};
  function hav(a,b){var R=6371,t=Math.PI/180,dLa=(b.lat-a.lat)*t,dLo=(b.lon-a.lon)*t;var x=Math.sin(dLa/2)*Math.sin(dLa/2)+Math.cos(a.lat*t)*Math.cos(b.lat*t)*Math.sin(dLo/2)*Math.sin(dLo/2);return 2*R*Math.asin(Math.sqrt(x))}
  var fr=function(n){return String(Math.round(n*10)/10).replace('.',',')};
  function distance(pt){var info=$('dist-info');info.textContent='Calcul de la distance depuis Cambes…';
    var base='https://data.geopf.fr/navigation/itineraire?profile=car&optimization=fastest&getBbox=false&distanceUnit=kilometer&timeUnit=hour&start='+D.lon+','+D.lat+'&end='+pt.lon+','+pt.lat;
    function fin(km,kp,estime,h){h=h>0?h:km/75;kp=Math.min(kp,km);$('km').value=fr(km);$('km_peage').value=kp>0?fr(kp):'';$('duree_h').value=String(Math.round(h*100)/100).replace('.',',');
      info.textContent=(estime?'Distance estimée (itinéraire indisponible) : environ ':'Par la route depuis Cambes : ')+fr(km)+' km'+(kp>0?', dont '+fr(kp)+' km à péage':'')+', '+Math.floor(h)+' h '+String(Math.round((h%1)*60)).padStart(2,'0')+' de trajet aller.';calculer();}
    fetch(base+'&resource=bdtopo-pgr&getSteps=true&waysAttributes=acces_vehicule_leger').then(function(r){if(!r.ok)throw 0;return r.json()}).then(function(d){
      var km=parseFloat(d.distance);if(!(km>0))throw 0;var kp=0;
      (d.portions||[]).forEach(function(po){(po.steps||[]).forEach(function(st){var a=JSON.stringify(st.attributes||{}).toLowerCase();if(a.indexOf('péage')>-1||a.indexOf('peage')>-1||a.indexOf('p\\u00e9age')>-1)kp+=parseFloat(st.distance)||0})});
      if(kp>km*1.05)kp=kp/1000;var h=parseFloat(d.duration);if(h>30)h=h/60;fin(km,kp,false,h);
    }).catch(function(){fetch(base+'&resource=bdtopo-valhalla&getSteps=false').then(function(r){if(!r.ok)throw 0;return r.json()}).then(function(d){var km=parseFloat(d.distance);if(!(km>0))throw 0;var h=parseFloat(d.duration);if(h>30)h=h/60;fin(km,0,false,h)}).catch(function(){fin(hav(D,pt)*1.3,0,true,0)})});}
  var ptClient=null;
  if(window.autoAdresseBAN){
    autoAdresseBAN($('adresse'),function(p,c){$('adresse').value=p.name||p.label;$('cp').value=p.postcode||'';$('ville').value=p.city||'';ptClient={lon:c[0],lat:c[1]};if(!$('site_adresse').value.trim())distance(ptClient);calculer()});
    autoAdresseBAN($('site_adresse'),function(p,c){$('site_adresse').value=p.label;if(!$('lieu_label').value.trim())$('lieu_label').value=p.city||'';distance({lon:c[0],lat:c[1]});calculer()});
  }

  /* ----- Articles Pennylane : recherche dans les lignes libres ----- */
  var etat=$('pl-etat');
  function brancher(tr){var inp=tr.querySelector('.l-label');if(!inp||inp.dataset.pl)return;inp.dataset.pl=1;inp.setAttribute('autocomplete','off');
    var box=document.createElement('div');box.className='pl-box';inp.parentNode.insertBefore(box,inp);box.appendChild(inp);
    var ul=document.createElement('ul');ul.className='pl-liste';ul.hidden=true;box.appendChild(ul);var res=[],idx=-1,t=null,n=0;
    var pid=tr.querySelector('.l-produit'),lie=tr.querySelector('.l-lie'),cat=tr.querySelector('.l-cat');
    function lier(id){pid.value=id||'';lie.hidden=!id;cat.hidden=!!id;if(id)cat.querySelector('input').checked=false}
    function prendre(i){var p=res[i];if(!p)return;ul.hidden=true;inp.value=p.label;var c=tr.cells;
      if(p.description)c[0].querySelector('[name="l_desc[]"]').value=p.description;
      var sel=c[2].querySelector('select'),u=(p.unit||'').toLowerCase(),map={hour:'heure',heure:'heure',day:'journée',jour:'journée','journée':'journée',piece:'unité',unit:'unité','unité':'unité',forfait:'forfait',km:'km',arbre:'arbre',test:'test'};
      u=map[u]||u;if(u){if(![].some.call(sel.options,function(o){return o.value===u})){var o=document.createElement('option');o.textContent=u;sel.appendChild(o)}sel.value=u}
      if(p.prix!=null)c[3].querySelector('input').value=String(p.prix).replace('.',',');if(!c[1].querySelector('input').value)c[1].querySelector('input').value='1';
      lier(p.id);calculer()}
    function afficher(){ul.innerHTML='';if(!res.length){ul.innerHTML='<li class="vide">Aucun article de ce nom : coche « Enregistrer comme article » pour le créer.</li>'}
      res.forEach(function(p,i){var li=document.createElement('li');li.innerHTML='<span></span><small></small>';li.firstChild.textContent=p.label;li.lastChild.textContent=(p.prix!=null?eur(p.prix)+' HT':'')+(p.unit?' / '+p.unit:'');
        li.addEventListener('mousedown',function(e){e.preventDefault();prendre(i)});ul.appendChild(li)});ul.hidden=false;idx=-1}
    inp.addEventListener('input',function(){lier('');clearTimeout(t);var v=inp.value.trim();if(v.length<2){ul.hidden=true;return}
      t=setTimeout(function(){var k=++n;fetch('./?p=devis&produits='+encodeURIComponent(v),{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){if(k!==n)return;if(!d.ok){etat.textContent=d.erreur;ul.hidden=true;return}res=d.produits;afficher()}).catch(function(){})},220)});
    inp.addEventListener('keydown',function(e){if(ul.hidden)return;var li=ul.querySelectorAll('li:not(.vide)');
      if(e.key==='ArrowDown'){e.preventDefault();idx=Math.min(idx+1,li.length-1)}else if(e.key==='ArrowUp'){e.preventDefault();idx=Math.max(idx-1,0)}
      else if(e.key==='Enter'&&idx>=0){e.preventDefault();prendre(idx);return}else if(e.key==='Escape'){ul.hidden=true;return}else return;
      li.forEach(function(x,i){x.classList.toggle('actif',i===idx)})});
    inp.addEventListener('blur',function(){setTimeout(function(){ul.hidden=true},150)});}
  var tb=document.querySelector('#t-lignes tbody');[].forEach.call(tb.rows,brancher);
  $('ajout-ligne').addEventListener('click',function(){var src=tb.rows[tb.rows.length-1],tr=src.cloneNode(true);
    var box=tr.querySelector('.pl-box');if(box){var inp=box.querySelector('input');box.parentNode.insertBefore(inp,box);box.remove();delete inp.dataset.pl}
    tr.querySelectorAll('input').forEach(function(i){if(i.type==='checkbox'){i.checked=false;i.value=tb.rows.length}else i.value=''});
    tr.querySelector('.l-lie').hidden=true;tr.querySelector('.l-cat').hidden=false;tb.appendChild(tr);brancher(tr);tr.querySelector('.l-label').focus()});
  $('pl-rafraichir').addEventListener('click',function(){etat.textContent='Actualisation…';fetch('./?p=devis&rafraichir=1&produits=a',{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){etat.textContent=d.ok?'Catalogue Pennylane à jour.':d.erreur})});
})();
</script>
<?php }

espace_rendu('Nouveau devis', $html);
