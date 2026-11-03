<?php
// Tâche planifiée Arbogestion (OVH > Hébergement > Tâches planifiées - Cron : arbogestion/app/cron.php, toutes les heures).
declare(strict_types=1);
require __DIR__ . '/signatures.php';
$cr = traiter_signatures();
journal('cron : ' . ($cr ? implode(' | ', $cr) : 'rien à faire'));
if (PHP_SAPI === 'cli') { echo ($cr ? implode("\n", $cr) : 'rien à faire') . "\n"; }
