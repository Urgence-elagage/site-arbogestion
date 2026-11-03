<?php
/*
 * Conditions générales de vente (CGV) Arbogestion — source unique.
 *
 * Les textes sont dans www/cgv/AAAA-MM-JJ/ (un dossier par version, jamais modifié une fois publié) :
 *   identite.txt, collectivites.txt, particuliers.txt, professionnels.txt
 * La version en vigueur est le dossier le plus récent. Pour changer les CGV : copier le dernier dossier
 * sous la date du jour, modifier les .txt, enregistrer (« Commit ») : la mise en ligne est automatique et
 * tous les nouveaux devis reçoivent la nouvelle version. Les anciennes versions restent consultables.
 *
 * Format des .txt : « # Titre », puis « ## Titre d’article » suivi d’un paragraphe par ligne,
 * « ### Titre » pour un encadré (formulaire), « > texte » pour la mention finale.
 */
declare(strict_types=1);
require_once __DIR__ . '/core.php';

const CGV_FICHIERS = ['collectivite' => 'collectivites', 'particulier' => 'particuliers', 'pro' => 'professionnels'];

function cgv_dossier(): string { return dirname(ARBO_APP) . '/www/cgv'; }

/** Versions publiées, de la plus récente à la plus ancienne (dates AAAA-MM-JJ). */
function cgv_versions(): array {
    $v = [];
    foreach ((array)glob(cgv_dossier() . '/*', GLOB_ONLYDIR) as $d) {
        $n = basename((string)$d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $n) && $n <= date('Y-m-d')) { $v[] = $n; }
    }
    rsort($v);
    return $v;
}

function cgv_version_courante(): string {
    $v = cgv_versions();
    if (!$v) { throw new RuntimeException('Aucune version des CGV dans www/cgv.'); }
    return $v[0];
}

/** Lit les CGV d'un profil (particulier, pro, collectivite) pour une version donnée. */
function cgv_lire(string $profil, ?string $version = null): array {
    if (!isset(CGV_FICHIERS[$profil])) { throw new RuntimeException("Profil CGV inconnu : $profil"); }
    $version = $version ?? cgv_version_courante();
    if (!in_array($version, cgv_versions(), true)) { throw new RuntimeException("Version des CGV inconnue : $version"); }
    $dir = cgv_dossier() . '/' . $version;
    $lignes = file($dir . '/' . CGV_FICHIERS[$profil] . '.txt', FILE_IGNORE_NEW_LINES) ?: [];
    $cgv = ['profil' => $profil, 'version' => $version, 'titre' => '', 'identite' => trim((string)@file_get_contents($dir . '/identite.txt')),
        'articles' => [], 'encadres' => [], 'mention' => ''];
    $cible = null;
    foreach ($lignes as $l) {
        $l = trim($l);
        if ($l === '') { continue; }
        if (strncmp($l, '### ', 4) === 0) { $cgv['encadres'][] = ['titre' => substr($l, 4), 'paras' => []]; $cible = &$cgv['encadres'][count($cgv['encadres']) - 1]['paras']; continue; }
        if (strncmp($l, '## ', 3) === 0) { $cgv['articles'][] = ['titre' => substr($l, 3), 'paras' => []]; $cible = &$cgv['articles'][count($cgv['articles']) - 1]['paras']; continue; }
        if (strncmp($l, '# ', 2) === 0) { $cgv['titre'] = substr($l, 2); continue; }
        if (strncmp($l, '> ', 2) === 0) { $cgv['mention'] = substr($l, 2); continue; }
        if ($cible !== null) { $cible[] = $l; }
    }
    unset($cible);
    return $cgv;
}

/** Numéro d'article dont le titre contient $mot (ex. « Rétractation »), ou null. */
function cgv_article(string $profil, string $mot): ?int {
    foreach (cgv_lire($profil)['articles'] as $i => $a) {
        if (mb_stripos($a['titre'], $mot) !== false) { return $i + 1; }
    }
    return null;
}

/** Bloc HTML des CGV, à insérer dans un PDF (dompdf) ou une page. */
function cgv_fragment_html(string $profil, ?string $version = null): string {
    $c = cgv_lire($profil, $version);
    $o = '<div class="cgv"><h1 class="cgv-t">Conditions générales de vente – ' . h($c['titre']) . '</h1>'
        . '<p class="cgv-id">' . h($c['identite']) . ' Version du ' . h(date_fr($c['version'])) . '.</p>';
    foreach ($c['articles'] as $i => $a) {
        foreach ($a['paras'] as $j => $p) {
            $o .= '<p>' . ($j === 0 ? '<b>Art. ' . ($i + 1) . ' – ' . h($a['titre']) . '.</b> ' : '') . h($p) . '</p>';
        }
    }
    foreach ($c['encadres'] as $e) {
        $o .= '<div class="cgv-enc"><b>' . h($e['titre']) . '</b>';
        foreach ($e['paras'] as $p) { $o .= '<p>' . h($p) . '</p>'; }
        $o .= '</div>';
    }
    if ($c['mention'] !== '') { $o .= '<p class="cgv-m">' . h($c['mention']) . '</p>'; }
    return $o . '</div>';
}

/** Styles du bloc CGV pour les PDF (petits caractères, pleine largeur). */
const CGV_STYLE_PDF = '.cgv{font-size:7pt;line-height:1.32}.cgv p{margin:0 0 3px;text-align:justify}.cgv b{color:#1e3d33}'
    . '.cgv-t{font-size:11pt;color:#1e3d33;margin:0 0 3px;border-bottom:1.5px solid #b7c93b;padding-bottom:2px}'
    . '.cgv-id{color:#5f655f;font-size:6.6pt}.cgv-enc{border:0.6px dashed #8a8f88;padding:4px 6px;margin:5px 0}'
    . '.cgv-m{font-weight:bold;border-top:1px solid #b7c93b;padding-top:3px;margin-top:5px}';

/** PDF autonome des CGV (page publique « Télécharger »). Renvoie le contenu binaire. */
function cgv_pdf_binaire(string $profil, ?string $version = null): string {
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
    $pdf->loadHtml('<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>@page{margin:14mm 14mm 14mm 14mm}body{font-family:\'DejaVu Sans\',sans-serif;color:#2b2b2b}'
        . CGV_STYLE_PDF . '</style></head><body>' . cgv_fragment_html($profil, $version) . '</body></html>', 'UTF-8');
    $pdf->setPaper('A4');
    $pdf->render();
    return (string)$pdf->output();
}
