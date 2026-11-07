<?php
// Agenda des interventions (inclus par index.php, utilisateur déjà authentifié).
declare(strict_types=1);
if (!function_exists('espace_connecte') || !espace_connecte()) { http_response_code(403); exit; }
require_once ARBO_APP . '/agenda.php';
installer_agenda();
agenda_synchroniser();

function rdv_pastille(string $s): string { $x = RDV_STATUTS[$s] ?? [$s, '']; return '<span class="pastille ' . $x[1] . '">' . h($x[0]) . '</span>'; }
function retour_agenda(array $r = [], string $semaine = ''): void {
    $s = $semaine ?: (!empty($r['debut']) ? substr($r['debut'], 0, 10) : '');
    aller('agenda', $s ? ['s' => $s] : []);
}

/* ---------- Actions ---------- */
if ($post) {
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $r = $id ? rdv($id) : null;
    try {
        if ($a === 'nouvelle') {
            $duree = (float)str_replace(',', '.', (string)($_POST['duree'] ?? '2')); $trajet = (float)str_replace(',', '.', (string)($_POST['trajet'] ?? '0.5'));
            $client = nettoyer((string)($_POST['client'] ?? ''), 200);
            if ($client === '') { throw new ErreurDemande('Indiquez le client.'); }
            db()->prepare('INSERT INTO interventions (titre, client, email, tel, adresse, nb_arbres, duree_h, trajet_h, date_min, statut, notes, cree_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([nettoyer((string)($_POST['titre'] ?? ''), 200) ?: 'Intervention', $client, nettoyer((string)($_POST['email'] ?? ''), 200), nettoyer((string)($_POST['tel'] ?? ''), 60),
                    nettoyer((string)($_POST['adresse'] ?? ''), 400), (int)($_POST['nb'] ?? 0), max(0.25, min(12, $duree)), max(0, min(6, $trajet)), date('Y-m-d', strtotime('+1 day')), 'a_planifier',
                    nettoyer((string)($_POST['notes'] ?? ''), 1000), date('Y-m-d H:i:s')]);
            message('Intervention ajoutée à la liste « À planifier ».'); aller('agenda');
        }
        if (!$r) { throw new ErreurDemande('Intervention introuvable.'); }
        if ($a === 'placer' || $a === 'suggere') {
            $debut = $a === 'suggere' ? (string)($_POST['creneau'] ?? '') : trim((string)($_POST['date'] ?? '')) . ' ' . trim((string)($_POST['heure'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $debut)) { throw new ErreurDemande('Indiquez une date et une heure d’arrivée.'); }
            $st = ($_POST['statut'] ?? 'pre') === 'planifie' ? 'planifie' : 'pre';
            $chg = ['debut' => $debut, 'statut' => $st];
            if (isset($_POST['duree']) && is_numeric(str_replace(',', '.', (string)$_POST['duree']))) { $chg['duree_h'] = max(0.25, min(12, (float)str_replace(',', '.', (string)$_POST['duree']))); }
            if (isset($_POST['trajet']) && is_numeric(str_replace(',', '.', (string)$_POST['trajet']))) { $chg['trajet_h'] = max(0, min(6, (float)str_replace(',', '.', (string)$_POST['trajet']))); }
            rdv_maj($id, $chg);
            $r = rdv($id);
            $c = conflits($r, $debut);
            $avert = '';
            if ($c) { $avert = ' Attention : chevauchement avec ' . implode(', ', array_map(fn($o) => $o['client'], $c)) . ' (trajets compris).'; }
            if ($debut < (string)$r['date_min']) { $avert .= ' Attention : avant la date au plus tôt (' . date_fr((string)$r['date_min']) . ($r['date_min'] > date('Y-m-d', strtotime('+2 days')) ? ', délai de rétractation ou date souhaitée' : '') . ').'; }
            message(($avert ? '!' : '') . ($st === 'pre' ? 'Pré-planifié le ' : 'Planifié le ') . jour_fr($debut) . ' à ' . heure_fr($debut) . '.' . $avert);
            retour_agenda($r);
        }
        if ($a === 'definitif') { rdv_maj($id, ['statut' => 'planifie']); message('Planification définitive. Tu peux maintenant proposer la date au client.'); retour_agenda($r); }
        if ($a === 'proposer') { proposer_au_client($id); message('Proposition envoyée à ' . $r['email'] . '. Tu seras prévenu de sa réponse par e-mail.'); retour_agenda($r); }
        if ($a === 'confirmer') { rdv_maj($id, ['statut' => 'confirme', 'reponse_le' => date('Y-m-d H:i:s'), 'reponse_msg' => 'Confirmé par téléphone ou de vive voix.']); message('Rendez-vous marqué confirmé.'); retour_agenda($r); }
        if ($a === 'realise') {
            rdv_maj($id, ['statut' => 'realise']);
            $txt = 'Intervention marquée réalisée. Pense à facturer le solde dans Pennylane.';
            if ($r['demande_id']) { // dernière journée du recensement : l'abonnement démarre à cette date
                $q = db()->prepare("SELECT COUNT(*) FROM interventions WHERE demande_id = ? AND statut NOT IN ('realise','annule')"); $q->execute([(int)$r['demande_id']]);
                if ((int)$q->fetchColumn() === 0) {
                    require_once ARBO_APP . '/signatures.php';
                    try { $res = demarrer_abonnement((int)$r['demande_id'], substr((string)$r['debut'], 0, 10)); if ($res !== 'pas d’abonnement à créer' && $res !== 'abonnement déjà créé') { $txt .= ' Abonnement créé dans Pennylane : ' . $res . '.'; } }
                    catch (Throwable $e) { $txt = '!' . $txt . ' L’abonnement n’a pas pu être créé : ' . $e->getMessage(); }
                }
            }
            message($txt); retour_agenda($r);
        }
        if ($a === 'retirer') { rdv_maj($id, ['statut' => 'a_planifier', 'debut' => null, 'propose_le' => null]); message('Remis dans « À planifier ».'); retour_agenda([], (string)($_POST['s'] ?? '')); }
        if ($a === 'annuler') { rdv_maj($id, ['statut' => 'annule']); message('Intervention annulée.'); retour_agenda([], (string)($_POST['s'] ?? '')); }
        if ($a === 'en_route') {
            $eta = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['eta'] ?? '')) ? (string)$_POST['eta'] : '';
            $par = espace_nom(espace_admin());
            rdv_maj($id, ['notes' => trim((string)$r['notes'] . "\n" . date('d/m H:i') . " : $par en route" . ($eta ? ", arrivée prévue vers $eta" : '') . ' (SMS au client).')]);
            header('Content-Type: application/json'); echo '{"ok":true}'; exit;
        }
        if ($a === 'notes') { rdv_maj($id, ['notes' => nettoyer((string)($_POST['notes'] ?? ''), 1000)]); message('Notes enregistrées.'); retour_agenda($r); }
    } catch (ErreurDemande $e) { message('!' . $e->getMessage()); retour_agenda($r ?? []); }
    catch (Throwable $e) { journal('Agenda : ' . $e->getMessage()); message('!' . $e->getMessage()); retour_agenda($r ?? []); }
}

/* ---------- Semaine affichée ---------- */
$ref = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['s'] ?? '')) ? (string)$_GET['s'] : date('Y-m-d');
$lundi = date('Y-m-d', strtotime($ref . ' -' . ((int)date('N', strtotime($ref)) - 1) . ' days'));
$jours = []; for ($i = 0; $i < 6; $i++) { $jours[] = date('Y-m-d', strtotime($lundi . " +$i days")); }
$s = db()->prepare("SELECT * FROM interventions WHERE debut >= ? AND debut < ? AND statut <> 'annule' ORDER BY debut");
$s->execute([$lundi, date('Y-m-d', strtotime($lundi . ' +7 days'))]);
$par_jour = []; foreach ($s->fetchAll() as $r) { $par_jour[substr($r['debut'], 0, 10)][] = $r; }
// Trajets enchaînés : le chantier suivant part du lieu du précédent ; retour à Cambes après le dernier
$ENCH = [];
foreach (array_keys($par_jour) as $d) { foreach (journee($d) as $x) { $ENCH[(int)$x['r']['id']] = $x; } }
function ench(array $r): array { global $ENCH; return $ENCH[(int)$r['id']] ?? ['avant' => (float)$r['trajet_h'], 'retour' => (float)$r['trajet_h'], 'depuis' => 'Cambes']; }

$H0 = 7; $H1 = 19; $PX = 44; // grille 7 h – 19 h, 44 px par heure
$mois = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
$noms = ['Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.'];

function bouton_action(string $a, array $r, string $libelle, string $classe = 'btn sec petit', string $conf = '', array $extra = []): string {
    global $lundi;
    $x = ''; foreach ($extra as $k => $v) { $x .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">'; }
    return '<form method="post" action="./?p=agenda" style="display:inline"' . ($conf ? ' data-confirmer="' . h($conf) . '"' : '') . '>' . espace_csrf_champ()
        . '<input type="hidden" name="a" value="' . h($a) . '"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="s" value="' . h($lundi) . '">' . $x
        . '<button class="' . $classe . '" type="submit">' . h($libelle) . '</button></form>';
}

/** Bouton « Je pars » : position du téléphone → heure d'arrivée → SMS prérempli au client, depuis le téléphone. */
function bloc_je_pars(array $r): string {
    if (!$r['debut'] || !in_array($r['statut'], ['planifie', 'propose', 'confirme'], true) || substr((string)$r['debut'], 0, 10) !== date('Y-m-d')) { return ''; }
    $moi = espace_admin(); $prenom = explode(' ', espace_nom($moi))[0];
    $tel = preg_replace('/[^\d+]/', '', (string)$r['tel']);
    if (str_starts_with($tel, '0') && strlen($tel) === 10) { $tel = '+33' . substr($tel, 1); }
    return '<div class="je-pars" data-id="' . (int)$r['id'] . '" data-lat="' . h((string)($r['lat'] ?? '')) . '" data-lon="' . h((string)($r['lon'] ?? '')) . '" data-adresse="' . h((string)$r['adresse']) . '" data-tel="' . h($tel) . '" data-prenom="' . h($prenom) . '" data-rdv="' . h(heure_fr((string)$r['debut'])) . '">'
        . '<button type="button" class="btn petit jp-go"><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11 21 3l-8 18-2-8-8-2z"/></svg>Je pars</button>'
        . '<span class="jp-info aide">' . ($tel ? 'Calcule l’heure d’arrivée depuis ta position et prépare un SMS au client.' : 'Pas de numéro de téléphone : l’heure d’arrivée sera calculée, sans SMS.') . '</span>'
        . '<a class="btn sec petit jp-sms" hidden href="#">Renvoyer un SMS</a></div>';
}

function detail_rdv(array $r): string {
    $fin = rdv_fin($r);
    $multi = (int)$r['jours_total'] > 1 ? ' · jour ' . (int)$r['jour_num'] . '/' . (int)$r['jours_total'] : '';
    $act = '';
    if ($r['statut'] === 'pre') { $act .= bouton_action('definitif', $r, 'Passer en définitif', 'btn petit'); }
    if (in_array($r['statut'], ['pre', 'planifie', 'refuse'], true) && $r['email']) { $act .= bouton_action('proposer', $r, $r['statut'] === 'pre' ? 'Définitif + proposer au client' : 'Proposer au client par e-mail', $r['statut'] === 'pre' ? 'btn sec petit' : 'btn petit', 'Envoyer la proposition à ' . $r['email']); }
    if (in_array($r['statut'], ['propose', 'planifie'], true)) { $act .= bouton_action('confirmer', $r, 'Confirmé par téléphone'); }
    if (in_array($r['statut'], ['confirme', 'planifie', 'propose'], true) && $r['debut'] <= date('Y-m-d H:i')) { $act .= bouton_action('realise', $r, 'Marquer réalisé', 'btn petit'); }
    if ($r['statut'] !== 'realise') { $act .= bouton_action('retirer', $r, 'Déplacer / déplanifier') . bouton_action('annuler', $r, 'Annuler', 'btn sec petit', 'Annuler cette intervention'); }
    return '<div class="carte rdv-detail" id="rdv-' . (int)$r['id'] . '"><div class="actions" style="justify-content:space-between;margin:0 0 6px"><h2 style="margin:0">' . h($r['client']) . '</h2>' . rdv_pastille($r['statut']) . '</div>'
        . '<p style="margin:0">' . h($r['titre']) . $multi . '<br>' . ($r['debut'] ? '<b>' . h(ucfirst(jour_fr($r['debut']))) . '</b>, sur place ' . h(heure_fr($r['debut'])) . ' – ' . h(heure_fr((string)$fin)) . '<br>Départ ' . (ench($r)['depuis'] === 'Cambes' ? 'de Cambes' : 'du chantier ' . h(ench($r)['depuis'])) . ' vers ' . h(heure_fr(date('Y-m-d H:i', (int)(strtotime($r['debut']) - ench($r)['avant'] * 3600)))) . ' (' . h(duree_fr(ench($r)['avant'])) . ' de route)'
            . (ench($r)['retour'] > 0 ? ' · retour à Cambes vers ' . h(heure_fr(date('Y-m-d H:i', (int)(strtotime((string)$fin) + ench($r)['retour'] * 3600)))) : ' · puis chantier suivant') : '')
        . '<br><span class="aide">' . h($r['adresse']) . ($r['km'] ? ' · ' . h(str_replace('.', ',', (string)(float)$r['km'])) . ' km' : '') . ($r['tel'] ? ' · ' . h($r['tel']) : '') . ($r['email'] ? ' · ' . h($r['email']) : '') . '</span></p>'
        . ($r['statut'] === 'propose' ? '<p class="aide">Proposé le ' . h(date('d/m/Y à H:i', strtotime((string)$r['propose_le']))) . ', en attente de la réponse du client.</p>' : '')
        . ($r['reponse_msg'] ? '<p class="msg' . ($r['statut'] === 'refuse' ? ' err' : '') . '" style="margin:8px 0">Réponse du client : ' . h($r['reponse_msg']) . '</p>' : '')
        . ($r['demande_id'] ? '<p class="aide"><a href="./?p=fiche&amp;id=' . (int)$r['demande_id'] . '">Voir le devis</a></p>' : '')
        . bloc_je_pars($r)
        . '<div class="actions">' . $act . '</div>'
        . '<details style="margin-top:10px"><summary class="aide">Notes</summary><form method="post" action="./?p=agenda">' . espace_csrf_champ() . '<input type="hidden" name="a" value="notes"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><textarea name="notes">' . h((string)$r['notes']) . '</textarea><button class="btn sec petit" type="submit">Enregistrer</button></form></details></div>';
}

/* ---------- À planifier ---------- */
$attente = db()->query("SELECT * FROM interventions WHERE statut IN ('a_planifier','refuse') ORDER BY date_min, id")->fetchAll();
$lst = '';
$dernier = [];
foreach ($attente as $r) {
    $apres = $r['demande_id'] && isset($dernier[$r['demande_id']]) ? date('Y-m-d', strtotime($dernier[$r['demande_id']] . ' +1 day')) : null;
    $sug = creneau_suggere($r, $apres);
    if ($r['demande_id'] && $sug) { $dernier[$r['demande_id']] = substr($sug, 0, 10); }
    $multi = (int)$r['jours_total'] > 1 ? ' · jour ' . (int)$r['jour_num'] . '/' . (int)$r['jours_total'] : '';
    $lst .= '<div class="carte a-planifier"><div><b>' . h($r['client']) . '</b> ' . rdv_pastille($r['statut']) . '<br><span class="aide">' . h($r['titre']) . $multi . '<br>Sur place ' . h(duree_fr((float)$r['duree_h'])) . ' · trajet ' . h(duree_fr((float)$r['trajet_h'])) . ' aller<br>' . h($r['adresse']) . '</span>'
        . '<br><span class="aide">' . ($r['date_min'] > date('Y-m-d', strtotime('+1 day')) ? 'Au plus tôt le ' . h(date_fr((string)$r['date_min'])) : 'Dès que possible') . '</span>'
        . ($r['reponse_msg'] ? '<br><span class="aide" style="color:var(--rouge)">Client : ' . h($r['reponse_msg']) . '</span>' : '') . ($r['notes'] ? '<br><span class="aide">' . h($r['notes']) . '</span>' : '') . '</div>'
        . ($sug ? '<div class="sugg"><span class="aide">Créneau libre suggéré</span><b>' . h(ucfirst(jour_fr($sug))) . ', ' . h(heure_fr($sug)) . '</b>' . bouton_action('suggere', $r, 'Pré-planifier ce créneau', 'btn petit', '', ['creneau' => $sug, 'statut' => 'pre', 's' => substr($sug, 0, 10)]) . '</div>' : '')
        . '<details style="margin-top:8px"><summary class="aide">Choisir une autre date</summary><form method="post" action="./?p=agenda" class="grille">' . espace_csrf_champ()
        . '<input type="hidden" name="a" value="placer"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
        . '<div><label>Date</label><input type="date" name="date" required min="' . date('Y-m-d') . '" value="' . h($sug ? substr($sug, 0, 10) : '') . '"></div>'
        . '<div><label>Arrivée sur place</label><input type="time" name="heure" required step="900" value="' . h($sug ? substr($sug, 11, 5) : '09:00') . '"></div>'
        . '<div><label>Durée sur place (h)</label><input type="text" name="duree" inputmode="decimal" value="' . h(str_replace('.', ',', (string)(float)$r['duree_h'])) . '"></div>'
        . '<div><label>Trajet aller (h)</label><input type="text" name="trajet" inputmode="decimal" value="' . h(str_replace('.', ',', (string)(float)$r['trajet_h'])) . '"></div>'
        . '<div><label>Statut</label><select name="statut"><option value="pre">Pré-planifié (en attente)</option><option value="planifie">Planification définitive</option></select></div>'
        . '<div style="align-self:end"><button class="btn sec" type="submit">Placer dans l’agenda</button></div></form></details></div>';
}

/* ---------- Grille de la semaine ---------- */
$cols = '';
foreach ($jours as $i => $d) {
    $blocs = '';
    foreach ($par_jour[$d] ?? [] as $r) {
        $h = (float)date('G', strtotime($r['debut'])) + (int)date('i', strtotime($r['debut'])) / 60;
        $e = ench($r); $t = $e['avant']; $rt = $e['retour']; $du = (float)$r['duree_h'];
        $top = max(0, ($h - $t - $H0) * $PX); $haut = ($du + $t + $rt) * $PX;
        $blocs .= '<a class="ev ev-' . h($r['statut']) . '" href="#rdv-' . (int)$r['id'] . '" title="Trajet ' . h(duree_fr($t)) . ' depuis ' . h($e['depuis']) . ($rt > 0 ? ', retour ' . h(duree_fr($rt)) : '') . '" style="top:' . round($top) . 'px;height:' . max(28, round($haut)) . 'px;--tr:' . round($t * $PX) . 'px;--rt:' . round($rt * $PX) . 'px">'
            . '<span class="ev-h">' . h(heure_fr($r['debut'])) . ' – ' . h(heure_fr((string)rdv_fin($r))) . '</span><b>' . h($r['client']) . '</b><span>' . (int)$r['nb_arbres'] . ' arbres · ' . h(RDV_STATUTS[$r['statut']][0] ?? '') . '</span></a>';
    }
    $auj = $d === date('Y-m-d') ? ' auj' : '';
    $cols .= '<div class="jour' . $auj . '"><div class="jt">' . $noms[$i] . ' ' . (int)date('j', strtotime($d)) . ' ' . $mois[(int)date('n', strtotime($d))] . '</div><div class="jc" style="height:' . (($H1 - $H0) * $PX) . 'px">' . $blocs
        . (empty($par_jour[$d]) ? '<span class="vide">Libre</span>' : '') . '</div></div>';
}
$heures = ''; for ($x = $H0; $x < $H1; $x++) { $heures .= '<div style="height:' . $PX . 'px">' . $x . ' h</div>'; }
$details = '';
foreach ($jours as $d) { foreach ($par_jour[$d] ?? [] as $r) { $details .= detail_rdv($r); } }

$prec = date('Y-m-d', strtotime($lundi . ' -7 days')); $suiv = date('Y-m-d', strtotime($lundi . ' +7 days'));
$ics = rtrim((string)cfg('url_site', 'https://arbogestion.fr'), '/') . '/agenda.ics.php?k=' . agenda_ics_cle();
$webcal = preg_replace('~^https?://~', 'webcal://', $ics);

$s = db()->prepare("SELECT * FROM interventions WHERE debut LIKE ? AND statut IN ('planifie','propose','confirme') ORDER BY debut"); $s->execute([date('Y-m-d') . '%']);
$auj = $s->fetchAll(); $aujourdhui = '';
if ($auj) {
    $aujourdhui = '<div class="carte auj"><h2>Aujourd’hui</h2>';
    foreach ($auj as $r) { $aujourdhui .= '<div class="auj-l"><div><b>' . h(heure_fr((string)$r['debut'])) . ' · ' . h($r['client']) . '</b><br><span class="aide">' . h($r['titre']) . ' · ' . h($r['adresse']) . '</span></div>' . bloc_je_pars($r) . '</div>'; }
    $aujourdhui .= '</div>';
}
$html = '<style>
.je-pars{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;margin:10px 0 0;padding:10px 12px;background:#f1f5e0;border-radius:10px}.je-pars .jp-info{flex:1 1 220px}.je-pars .jp-info b{color:var(--vert)}
.auj .auj-l{padding:10px 0;border-top:1px solid var(--ligne)}.auj .auj-l:first-of-type{border-top:0}
main.in{max-width:100rem}
.ag-tete{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:0 0 14px}
.vues{display:inline-flex;border:1px solid var(--vert);border-radius:8px;overflow:hidden}.vues button{font:inherit;font-size:.88rem;font-weight:700;background:#fff;color:var(--vert);border:0;padding:8px 12px;cursor:pointer}.vues button+button{border-left:1px solid var(--vert)}.vues button[aria-pressed=true]{background:var(--vert);color:#fff}
.ag{display:grid;grid-template-columns:minmax(260px,330px) minmax(0,1fr);gap:18px;align-items:start}
.ag[data-vue=planning]{grid-template-columns:minmax(0,1fr)}.ag[data-vue=planning] .ag-g{display:none}
.ag[data-vue=liste]{grid-template-columns:minmax(0,1fr)}.ag[data-vue=liste] .ag-d{display:none}.ag[data-vue=liste] .ag-g{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;align-items:stretch}.ag[data-vue=liste] .ag-g>.carte{margin:0}.ag[data-vue=liste] .ag-g>h2,.ag[data-vue=liste] .ag-g>details{grid-column:1/-1}.ag[data-vue=liste] .ag-g>h2{order:-2}.ag[data-vue=liste] .ag-g>details{order:-1}
.ag-g{position:sticky;top:70px;max-height:calc(100vh - 86px);overflow:auto;padding-right:4px}.ag[data-vue=liste] .ag-g{position:static;max-height:none}
.ag-titre{margin:0 0 10px}.a-planifier{padding:12px 14px;display:flex;flex-direction:column}.a-planifier>.sugg{margin-top:auto}.a-planifier>div:first-child{margin-bottom:10px}.sugg{margin-top:10px;padding:8px 10px;background:#fafbf6;border:1px solid var(--ligne);border-radius:8px;display:flex;flex-direction:column;gap:4px}
.ag-nav{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px}
@media (max-width:900px){.ag,.ag[data-vue]{grid-template-columns:1fr}.ag-g{position:static;max-height:none}}
.vues .nb{display:inline-block;min-width:1.4em;margin-left:4px;padding:0 5px;border-radius:999px;background:var(--lime);color:var(--vert);font-size:.78rem}
@media (max-width:760px){.ag-tete{display:block;margin-bottom:10px}.ag-tete .sous,.legende{display:none!important}.vues{display:flex;width:100%}.vues button{flex:1;padding:10px 6px}.vues [data-vue=double]{display:none}
.ag[data-vue=double] .ag-d{display:none}.ag-titre{display:none}.ag-nav{position:sticky;top:56px;z-index:5;background:var(--creme);padding:6px 0}.ag[data-vue=liste] .ag-g{grid-template-columns:1fr}}
.sem{display:grid;grid-template-columns:44px repeat(6,minmax(0,1fr));gap:6px;background:#fff;border:1px solid var(--ligne);border-radius:10px;padding:10px;overflow-x:auto}
.hcol{padding-top:30px;font-size:.75rem;color:var(--gris)}.hcol div{border-top:1px solid #eef0ea}
.jt{height:30px;font-weight:700;font-size:.88rem;color:var(--vert);text-align:center}.auj .jt{background:var(--lime);border-radius:6px}
.jc{position:relative;background:repeating-linear-gradient(#fff 0 43px,#eef0ea 43px 44px);border-radius:6px;border:1px solid #eef0ea}
.vide{position:absolute;top:45%;left:0;right:0;text-align:center;color:#b9bdb4;font-size:.8rem}
.ev{position:absolute;left:2px;right:2px;border-radius:6px;padding:calc(var(--tr) + 2px) 6px 4px;font-size:.78rem;line-height:1.25;text-decoration:none;color:var(--vert);overflow:hidden;
  background:linear-gradient(rgba(0,0,0,.07) 0 var(--tr),transparent var(--tr) calc(100% - var(--rt)),rgba(0,0,0,.07) calc(100% - var(--rt)));border:1px solid var(--ligne)}
.ev b{display:block}.ev span{display:block}.ev-h{font-weight:700}
.ev-pre{background-color:var(--jaunef);border:1px dashed var(--jaune)}.ev-planifie,.ev-propose{background-color:var(--bleuf);border-color:#b7cbe0}
.ev-confirme{background-color:var(--okf);border-color:#a9cda5}.ev-realise{background-color:#eef0ea;color:var(--gris)}
.legende{display:flex;gap:10px;flex-wrap:wrap;font-size:.85rem;margin:8px 0 18px}.legende span{display:inline-flex;align-items:center;gap:6px}.legende i{width:14px;height:14px;border-radius:3px;display:inline-block;border:1px solid var(--ligne)}
@media (max-width:760px){.sem{grid-template-columns:1fr}.hcol{display:none}.jc{height:auto!important;background:#fff;min-height:40px;padding:4px}.ev{position:static;display:block;margin-bottom:4px;height:auto!important;padding:6px}.vide{position:static;display:block;padding:8px}}
</style>'
    . '<div class="ag-tete"><div><h1>Agenda des interventions</h1><p class="sous" style="margin:0">Semaine du ' . h(date_fr($lundi)) . ' · zones grisées = route (depuis Cambes ou le chantier précédent, retour à Cambes le soir)</p></div>'
    . '<div class="vues" role="group" aria-label="Affichage"><button type="button" data-vue="double">Double affichage</button><button type="button" data-vue="planning">Planning</button><button type="button" data-vue="liste">À planifier <b class="nb">' . count($attente) . '</b></button></div></div>'
    . $aujourdhui
    . '<div class="ag" id="ag" data-vue="double"><aside class="ag-g">'
    . '<h2 class="ag-titre">À planifier (' . count($attente) . ')</h2>' . ($lst ?: '<div class="carte"><p class="aide" style="margin:0">Rien à planifier. Les interventions apparaissent ici dès qu’un devis comportant du terrain est signé.</p></div>')
    . '<details class="carte"><summary><b>Ajouter une intervention à la main</b></summary><form method="post" action="./?p=agenda">' . espace_csrf_champ() . '<input type="hidden" name="a" value="nouvelle"><div class="grille">'
    . '<div><label>Client</label><input type="text" name="client" required></div><div><label>Objet</label><input type="text" name="titre" placeholder="Ex. appui technique haubanage"></div>'
    . '<div><label>Adresse</label><input type="text" name="adresse"></div><div><label>E-mail du client</label><input type="email" name="email"></div><div><label>Téléphone</label><input type="tel" name="tel"></div>'
    . '<div><label>Nombre d’arbres</label><input type="number" name="nb" min="0" value="0"></div><div><label>Durée sur place (h)</label><input type="text" name="duree" value="2" inputmode="decimal"></div><div><label>Trajet aller (h)</label><input type="text" name="trajet" value="0,5" inputmode="decimal"></div></div>'
    . '<label>Notes</label><textarea name="notes"></textarea><div class="actions"><button class="btn" type="submit">Ajouter</button></div></form></details>'
    . '</aside><section class="ag-d"><div class="ag-nav"><a class="btn sec petit" href="./?p=agenda&amp;s=' . $prec . '">←</a><a class="btn sec petit" href="./?p=agenda">Cette semaine</a><a class="btn sec petit" href="./?p=agenda&amp;s=' . $suiv . '">→</a><span class="aide">Semaine du ' . h(date_fr($lundi)) . '</span></div>'
    . '<div class="legende"><span><i style="background:var(--jaunef);border-style:dashed"></i>Pré-planifié</span><span><i style="background:var(--bleuf)"></i>Planifié / proposé au client</span><span><i style="background:var(--okf)"></i>Confirmé</span><span><i style="background:#eef0ea"></i>Réalisé</span></div>'
    . '<div class="sem"><div class="hcol">' . $heures . '</div>' . $cols . '</div>'
    . '</section></div>'
    . ($details ? '<h2 style="margin-top:22px">Détail de la semaine</h2>' . $details : '')
    . '<div class="carte" style="margin-top:22px"><h2>Voir l’agenda sur ton téléphone</h2><p class="aide">Abonne ton calendrier (iPhone : Réglages → Calendrier → Comptes → Ajouter un compte → Autre → Ajouter un calendrier avec abonnement) à cette adresse personnelle, à ne pas partager :</p><p class="cle">' . h($webcal) . '</p><p class="aide">Les rendez-vous apparaissent avec le trajet compris ; la mise à jour se fait automatiquement (selon le téléphone, toutes les 15 minutes à quelques heures).</p></div>';

$html .= '<script nonce="' . h($nonce) . '">' . <<<'JS'
(function(){
  function hm(d){return d.getHours()+' h '+String(d.getMinutes()).padStart(2,'0')}
  function hhmm(d){return String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0')}
  function dest(b){var la=parseFloat(b.dataset.lat),lo=parseFloat(b.dataset.lon);if(la&&lo)return Promise.resolve({lat:la,lon:lo});
    return fetch('https://api-adresse.data.gouv.fr/search/?limit=1&q='+encodeURIComponent(b.dataset.adresse)).then(function(r){return r.json()}).then(function(d){var c=d.features[0].geometry.coordinates;return{lat:c[1],lon:c[0]}})}
  function route(a,c){return fetch('https://data.geopf.fr/navigation/itineraire?resource=bdtopo-osrm&profile=car&optimization=fastest&getBbox=false&timeUnit=minute&distanceUnit=kilometer&start='+a.lon+','+a.lat+'&end='+c.lon+','+c.lat)
    .then(function(r){if(!r.ok)throw 0;return r.json()}).then(function(d){return{min:parseFloat(d.duration),km:parseFloat(d.distance)}})}
  function position(){return new Promise(function(ok,ko){if(!navigator.geolocation)return ko('Ce téléphone ne donne pas sa position.');navigator.geolocation.getCurrentPosition(function(p){ok({lat:p.coords.latitude,lon:p.coords.longitude})},function(){ko('Position refusée : autorise la localisation pour ce site dans les réglages du téléphone.')},{enableHighAccuracy:true,timeout:15000,maximumAge:60000})})}
  function sms(b,eta,retard){var t='Bonjour, c’est '+b.dataset.prenom+' d’Arbogestion. '+(retard?'Petit retard : ':'Je suis en route pour notre rendez-vous de '+b.dataset.rdv+'. ')+'Arrivée prévue vers '+hm(eta)+'. À tout à l’heure.';return 'sms:'+b.dataset.tel+'?&body='+encodeURIComponent(t)}
  document.querySelectorAll('.je-pars').forEach(function(b){var go=b.querySelector('.jp-go'),info=b.querySelector('.jp-info'),re=b.querySelector('.jp-sms'),envoye=null,c=null,tmr=null;
    function calc(premier){return position().then(function(p){return(c?Promise.resolve(c):dest(b)).then(function(d){c=d;return route(p,d)})}).then(function(r){
      var eta=new Date(Date.now()+r.min*60000);eta.setSeconds(0);var m=eta.getMinutes();eta.setMinutes(Math.ceil(m/5)*5);
      info.innerHTML='Arrivée prévue vers <b>'+hm(eta)+'</b> · encore '+Math.round(r.min)+' min de route ('+String(Math.round(r.km)).replace('.',',')+' km). Mise à jour toutes les 3 min tant que la page reste ouverte.';
      if(b.dataset.tel){if(premier){envoye=eta;var f=new FormData();f.set('a','en_route');f.set('id',b.dataset.id);f.set('eta',hhmm(eta));f.set('jeton',document.querySelector('input[name=jeton]').value);fetch('./?p=agenda',{method:'POST',body:f,credentials:'same-origin'});location.href=sms(b,eta,false)}
        var retard=envoye&&eta-envoye>=15*60000;re.hidden=false;re.textContent=retard?'Prévenir du retard (SMS)':'Renvoyer un SMS';re.className='btn petit jp-sms'+(retard?'':' sec');re.href=sms(b,eta,retard);if(retard)re.onclick=function(){envoye=eta}}
    }).catch(function(e){info.textContent=typeof e==='string'?e:'Itinéraire indisponible pour l’instant, réessaie dans un instant.'})}
    go.addEventListener('click',function(){info.textContent='Recherche de ta position…';go.disabled=true;calc(true).then(function(){go.disabled=false;clearInterval(tmr);tmr=setInterval(function(){calc(false)},180000)})});
  });
})();
JS
. '(function(){var ag=document.getElementById(\'ag\'),bs=document.querySelectorAll(\'.vues button\');function vue(v){ag.dataset.vue=v;bs.forEach(function(b){b.setAttribute(\'aria-pressed\',b.dataset.vue===v)});try{localStorage.setItem(\'arbo_vue_agenda\',v)}catch(e){}}
bs.forEach(function(b){b.addEventListener(\'click\',function(){vue(b.dataset.vue)})});var v=\'double\';try{v=localStorage.getItem(\'arbo_vue_agenda\')||v}catch(e){}if(v===\'double\'&&matchMedia(\'(max-width:760px)\').matches)v=\'planning\';vue(v);})();</script>';
espace_rendu('Agenda', $html);
