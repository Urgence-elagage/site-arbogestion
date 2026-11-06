<?php
// Déclenchement de la tâche planifiée par adresse web : https://arbogestion.fr/cron.php?token=…
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
require dirname(__DIR__) . '/app/signatures.php';
if (!hash_equals((string)cfg('cron_token'), (string)($_GET['token'] ?? ''))) { http_response_code(403); exit("Accès refusé\n"); }
$cr = array_merge(traiter_signatures(), traiter_paiements());
journal('cron (web) : ' . ($cr ? implode(' | ', $cr) : 'rien à faire'));
echo $cr ? implode("\n", $cr) . "\n" : "rien à faire\n";
