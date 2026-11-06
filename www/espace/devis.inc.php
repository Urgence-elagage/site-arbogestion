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
function lire_formulaire(): array {
    $profil = v('profil'); $formule = v('formule', 'libre');
    if (!isset(FORMULES[$formule])) { throw new ErreurDemande('Formule inconnue.'); }
    $grille = $formule !== 'libre';
    $nb = (int)v('nb', '0');
    if ($grille && $nb < 1) { throw new ErreurDemande('Indiquez le nombre d’arbres pour un calcul selon la grille.'); }
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
        if ($label === '' || $qte === null || $qte <= 0 || $pu === null || $pu < 0) { throw new ErreurDemande('Ligne libre n° ' . ($i + 1) . ' incomplète : libellé, quantité et prix unitaire HT sont nécessaires.'); }
        $unite = (string)($_POST['l_unite'][$i] ?? 'forfait'); if (!in_array($unite, UNITES, true)) { $unite = 'forfait'; }
        $calc['lignes_demarrage'][] = ['label' => $label, 'desc' => nettoyer((string)($_POST['l_desc'][$i] ?? ''), 300), 'qte' => round($qte, 2), 'unite' => $unite, 'pu' => round($pu, 2)];
        $nlib++;
    }
    if (!$grille && !$nlib) { throw new ErreurDemande('Ajoutez au moins une ligne libre, ou choisissez un calcul selon la grille.'); }
    // Totaux et acompte (même règle que le site)
    $calc['demarrage_ht'] = round(array_sum(array_map(fn($l) => round($l['qte'] * $l['pu'], 2), $calc['lignes_demarrage'])), 2);
    $A = tarifs()['acompte'] ?? null;
    $calc['acompte_ht'] = ($A && in_array($dem['profil'], $A['profils'], true) && $calc['demarrage_ht'] > 0) ? round($calc['demarrage_ht'] * $A['pourcentage'] / 100, 2) : 0.0;
    $opt = ['origine' => 'espace', 'objet' => nettoyer(v('objet'), 150), 'mention' => nettoyer(v('mention'), 600), 'validite_mois' => max(1, min(12, (int)v('validite', '3')))];
    return [$dem, $calc, $opt];
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
    $lignes .= '<tr><td><input type="text" name="l_label[]" aria-label="Libellé" placeholder="Ex. Conseil en taille" value="' . h((string)($_POST['l_label'][$i] ?? '')) . '"><input type="text" name="l_desc[]" aria-label="Description" placeholder="Description (facultatif)" style="margin-top:4px" value="' . h((string)($_POST['l_desc'][$i] ?? '')) . '"></td>'
        . '<td style="width:90px"><input type="text" name="l_qte[]" inputmode="decimal" aria-label="Quantité" value="' . h((string)($_POST['l_qte'][$i] ?? '')) . '"></td>'
        . '<td style="width:140px"><select name="l_unite[]" aria-label="Unité">' . $u . '</select></td>'
        . '<td style="width:110px"><input type="text" name="l_pu[]" inputmode="decimal" aria-label="Prix unitaire HT" value="' . h((string)($_POST['l_pu'][$i] ?? '')) . '"></td></tr>';
}
$T = tarifs();
$html = '<p><a href="./">← Tous les devis</a></p><h1>Nouveau devis</h1><p class="sous">Le devis est créé dans Pennylane avec le modèle « Devis Arbogestion », l’annexe et les CGV du bon public. Taux horaire de la grille : ' . eur((float)$T['tauxHoraire']) . ' HT.</p>'
    . ($erreur ? '<div class="msg err" role="alert">' . h($erreur) . '</div>' : '') . $apercu
    . '<form method="post" action="./?p=devis#apercu" id="f-devis">' . espace_csrf_champ()
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
    . '<div data-formule="ponctuel demarrage">' . champ('km', 'Distance depuis Cambes par la route (km, aller)', 'text', ['inputmode' => 'decimal'], 'Facturée aller-retour. Vide = pas de frais de déplacement.') . '</div><div data-formule="ponctuel demarrage">' . champ('km_peage', 'Dont km d’autoroute à péage (aller)', 'text', ['inputmode' => 'decimal']) . '</div><div data-formule="ponctuel demarrage">' . champ('duree_h', 'Temps de trajet aller (h)', 'text', ['inputmode' => 'decimal'], 'Au-delà de ' . (int)(tarifs()['eloignement']['heuresTrajetMax'] ?? 3) . ' h : hébergement et repas ajoutés.') . '</div>'
    . '<div data-formule="demarrage abonnement" data-pour="particulier pro">' . choix('paiement', 'Paiement de l’abonnement', ['annuel' => 'Annuel', 'mensuel' => 'Mensuel (prélèvement SEPA)'], 'annuel') . '</div>'
    . champ('date_debut', 'Date de début souhaitée', 'date') . '</div>'
    . '<div data-pour="particulier"><label class="coche"><input type="checkbox" name="execution_anticipee" value="1"' . (v('execution_anticipee') === '1' ? ' checked' : '') . '> Le client demande que la prestation commence avant la fin du délai de rétractation de 14 jours</label></div></div>'

    . '<div class="carte"><h2>Lignes libres</h2><p class="aide" style="margin-top:-6px">Conseil, appui technique sur chantier, réunion, vacation… Elles s’ajoutent au calcul de la grille. Prix HT, TVA 20 %.</p>'
    . '<div class="tab lignes"><table id="t-lignes"><thead><tr><th>Libellé et description</th><th>Qté</th><th>Unité</th><th>PU HT (€)</th></tr></thead><tbody>' . $lignes . '</tbody></table></div>'
    . '<div class="actions" style="margin-top:8px"><button type="button" class="btn sec petit" id="ajout-ligne">Ajouter une ligne</button></div></div>'

    . '<div class="carte"><h2>Présentation</h2><div class="grille">' . champ('objet', 'Objet du devis (facultatif)', 'text', ['placeholder' => 'Par défaut : Diagnostic ponctuel, Prestation de conseil…'])
    . choix('validite', 'Validité', ['1' => '1 mois', '2' => '2 mois', '3' => '3 mois', '6' => '6 mois'], '3') . '</div>'
    . '<label for="mention">Mention particulière (facultatif, en tête des conditions du devis)</label><textarea id="mention" name="mention" maxlength="600">' . h(v('mention')) . '</textarea>'
    . '<div data-pour="pro">' . '<label for="contraintes">Contraintes d’exploitation (saison, horaires d’accès)</label><input type="text" id="contraintes" name="contraintes" value="' . h(v('contraintes')) . '"></div>'
    . '<div class="actions"><button class="btn" type="submit" name="action" value="apercu">Voir l’aperçu</button><span class="aide">Rien n’est créé avant ta validation de l’aperçu.</span></div></div>'
    . '</form>';

function script_devis(): void { global $nonce; ?>
<script nonce="<?= h($nonce) ?>">
(function(){
  var prof=document.getElementById('profil'),form=document.getElementById('formule');if(!prof)return;
  function maj(){var p=prof.value,f=form.value;var ld=document.querySelector('label[for=denomination]');if(ld)ld.textContent=p==='collectivite'?'Nom de la collectivité':'Raison sociale';
    document.querySelectorAll('[data-pour]').forEach(function(el){var ok=el.dataset.pour.split(' ').indexOf(p)>=0;
      if(el.dataset.formule){var fs=el.dataset.formule.split(' ');ok=ok&&(fs.indexOf(f)>=0||(fs.indexOf('grille')>=0&&f!=='libre'));}el.hidden=!ok;});
    document.querySelectorAll('[data-formule]:not([data-pour])').forEach(function(el){var fs=el.dataset.formule.split(' ');el.hidden=!(fs.indexOf(f)>=0||(fs.indexOf('grille')>=0&&f!=='libre'));});}
  prof.addEventListener('change',maj);form.addEventListener('change',maj);maj();
  document.getElementById('ajout-ligne').addEventListener('click',function(){var tb=document.querySelector('#t-lignes tbody'),tr=tb.rows[tb.rows.length-1].cloneNode(true);
    tr.querySelectorAll('input').forEach(function(i){i.value='';});tb.appendChild(tr);tr.querySelector('input').focus();});
})();
</script>
<?php }

espace_rendu('Nouveau devis', $html);
