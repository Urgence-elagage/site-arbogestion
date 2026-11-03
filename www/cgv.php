<?php
// Conditions générales de vente Arbogestion : version en vigueur, archives et téléchargement PDF.
// Les textes sont dans www/cgv/AAAA-MM-JJ/ (voir app/cgv.php).
declare(strict_types=1);
require dirname(__DIR__) . '/app/cgv.php';

$publics = ['collectivites' => 'collectivite', 'particuliers' => 'particulier', 'professionnels' => 'pro'];
$noms = ['collectivites' => 'Collectivités', 'particuliers' => 'Particuliers', 'professionnels' => 'Professionnels'];
$versions = cgv_versions();
$v = (string)($_GET['v'] ?? '');
if (!in_array($v, $versions, true)) { $v = $versions[0] ?? ''; }
$pub = (string)($_GET['public'] ?? '');
if (!isset($publics[$pub])) { $pub = 'collectivites'; }

if (!empty($_GET['pdf'])) {
    try {
        $bin = cgv_pdf_binaire($publics[$pub], $v);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="CGV-Arbogestion-' . $pub . '-' . $v . '.pdf"');
        echo $bin;
    } catch (Throwable $e) {
        journal('cgv.php PDF : ' . $e->getMessage());
        http_response_code(500);
        echo 'Le PDF n’a pas pu être généré. Les conditions restent consultables sur cette page.';
    }
    exit;
}
$courante = $v === ($versions[0] ?? '');
$cgv = []; foreach ($publics as $k => $p) { $cgv[$k] = cgv_lire($p, $v); }
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Conditions générales de vente — Arbogestion</title>
<meta name="description" content="Conditions générales de vente d’Arbogestion, arboriste conseil à Cambes (Lot) : collectivités, particuliers et professionnels.">
<?php if (!$courante): ?><meta name="robots" content="noindex"><?php endif; ?>
<link rel="canonical" href="https://arbogestion.fr/cgv.php">
<style>
:root{--vert:#1e3d33;--vert2:#163028;--lime:#b7c93b;--creme:#f6f5f0;--texte:#2b2b2b;--gris:#5f655f;--ligne:#dcdfd6;--fond:#fff;--surli:#f2f6dc}
*{box-sizing:border-box}
body{margin:0;font-family:Lato,system-ui,sans-serif;color:var(--texte);background:var(--creme);line-height:1.6;font-size:17px}
a{color:var(--vert)}
.haut{background:var(--vert2);color:#fff;padding-block:18px}
.haut .in{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.haut a{color:#fff;text-decoration:none;font-weight:900;letter-spacing:.04em}
.in{max-width:46rem;margin:0 auto;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px))}
h1{color:var(--vert);font-size:1.7rem;line-height:1.2;margin:28px 0 6px;text-wrap:balance}
.info{color:var(--gris);font-size:.92rem;margin:0 0 14px}
.archive{background:#fff4d6;border-left:4px solid #c99a1a;padding:10px 14px;margin:10px 0 16px;font-size:.95rem}
.onglets{position:sticky;top:0;z-index:5;background:var(--creme);display:flex;gap:8px;padding-block:12px;border-bottom:1px solid var(--ligne);overflow-x:auto}
.onglets button{font:inherit;font-size:.95rem;font-weight:700;border:1px solid var(--ligne);background:var(--fond);color:var(--vert);border-radius:999px;padding:7px 16px;cursor:pointer;white-space:nowrap}
.onglets button[aria-selected="true"]{background:var(--vert);border-color:var(--vert);color:#fff}
button:focus-visible,a:focus-visible,select:focus-visible{outline:3px solid var(--lime);outline-offset:2px}
.outils{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:16px 0 4px}
.pdf{display:inline-block;background:var(--lime);color:var(--vert);font-weight:900;text-decoration:none;padding:9px 16px;border-radius:6px}
.ident{font-size:.85rem;color:var(--gris);margin:12px 0}
.sommaire{background:var(--fond);border:1px solid var(--ligne);border-radius:8px;padding:12px 16px 12px 36px;columns:2;column-gap:24px;font-size:.92rem;margin:12px 0}
@media (max-width:560px){.sommaire{columns:1}}
.sommaire a{text-decoration:none}.sommaire a:hover{text-decoration:underline}
article{border-top:1px solid var(--ligne);padding-block:14px 2px;scroll-margin-top:70px}
h2{font-size:1.08rem;color:var(--vert);margin:0 0 6px;display:flex;gap:10px;align-items:baseline}
h2 span{color:var(--gris);font-size:.8rem;font-weight:400;min-width:3.4em;font-variant-numeric:tabular-nums}
p{margin:0 0 .8rem}
.encadre{border:1px dashed var(--gris);border-radius:8px;background:var(--fond);padding:12px 16px;margin:16px 0}
.mention{font-weight:700;border-top:2px solid var(--lime);padding-top:10px;margin-top:18px}
.versions{margin:30px 0 40px;font-size:.92rem;color:var(--gris)}
.versions select{font:inherit;padding:4px 8px}
</style></head><body>
<div class="haut"><div class="in"><a href="/">ARBOGESTION</a><span>Arboriste conseil</span></div></div>
<main class="in">
<h1>Conditions générales de vente</h1>
<p class="info">Version du <?= h(date_fr($v)) ?>. La signature d’un devis Arbogestion vaut acceptation des conditions générales de vente qui lui sont jointes.</p>
<?php if (!$courante): ?><p class="archive">Ancienne version, conservée pour les devis signés lorsqu’elle était en vigueur. <a href="cgv.php">Voir la version en vigueur</a>.</p><?php endif; ?>
<div class="onglets" role="tablist" aria-label="Public">
<?php foreach ($noms as $k => $n): ?><button type="button" role="tab" id="tab-<?= $k ?>" data-k="<?= $k ?>" aria-controls="<?= $k ?>" aria-selected="<?= $k === $pub ? 'true' : 'false' ?>"><?= $n ?></button><?php endforeach; ?>
</div>
<?php foreach ($cgv as $k => $c): ?>
<section id="<?= $k ?>" role="tabpanel" aria-labelledby="tab-<?= $k ?>"<?= $k === $pub ? '' : ' hidden' ?>>
<div class="outils"><a class="pdf" href="cgv.php?public=<?= $k ?>&amp;v=<?= h($v) ?>&amp;pdf=1">Télécharger en PDF</a></div>
<p class="ident"><?= h($c['identite']) ?></p>
<ol class="sommaire"><?php foreach ($c['articles'] as $i => $a): ?><li><a href="#<?= $k ?>-<?= $i + 1 ?>"><?= h($a['titre']) ?></a></li><?php endforeach; ?></ol>
<?php foreach ($c['articles'] as $i => $a): ?>
<article id="<?= $k ?>-<?= $i + 1 ?>"><h2><span>Art. <?= $i + 1 ?></span><?= h($a['titre']) ?></h2>
<?php foreach ($a['paras'] as $para): ?><p><?= h($para) ?></p><?php endforeach; ?></article>
<?php endforeach; ?>
<?php foreach ($c['encadres'] as $e): ?><div class="encadre"><h2><?= h($e['titre']) ?></h2><?php foreach ($e['paras'] as $para): ?><p><?= h($para) ?></p><?php endforeach; ?></div><?php endforeach; ?>
<?php if ($c['mention'] !== ''): ?><p class="mention"><?= h($c['mention']) ?></p><?php endif; ?>
</section>
<?php endforeach; ?>
<?php if (count($versions) > 1): ?>
<form class="versions" method="get" action="cgv.php"><label for="v">Versions précédentes :</label>
<select id="v" name="v" onchange="this.form.submit()"><?php foreach ($versions as $x): ?><option value="<?= h($x) ?>"<?= $x === $v ? ' selected' : '' ?>><?= h(date_fr($x)) ?><?= $x === $versions[0] ? ' (en vigueur)' : '' ?></option><?php endforeach; ?></select>
<noscript><button type="submit">Afficher</button></noscript></form>
<?php else: ?><p class="versions">Aucune version antérieure.</p><?php endif; ?>
</main>
<script>
document.querySelectorAll('.onglets button').forEach(function(b){b.addEventListener('click',function(){
  document.querySelectorAll('.onglets button').forEach(function(x){var on=x===b;x.setAttribute('aria-selected',on?'true':'false');document.getElementById(x.dataset.k).hidden=!on;});
});});
var h=(location.hash||'').slice(1).split('-')[0];if(document.getElementById('tab-'+h)){document.getElementById('tab-'+h).click();}
</script>
</body></html>
