<?php
// Point d'entrée public : reçoit la demande de devis du site (JSON) et la transmet au serveur Arbogestion.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function repondre(int $code, array $corps): void { http_response_code($code); echo json_encode($corps, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { repondre(405, ['ok' => false, 'message' => 'Méthode non autorisée.']); }
$brut = file_get_contents('php://input', false, null, 0, 100000);
$in = json_decode((string)$brut, true);
if (!is_array($in)) { repondre(400, ['ok' => false, 'message' => 'Demande illisible.']); }
if (!empty($in['site_web'])) { repondre(200, ['ok' => true, 'id' => 0]); } // piège à robots

require dirname(__DIR__, 2) . '/app/demande.php';

// Limite simple : 5 demandes par heure et par adresse IP
$ip = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . cfg('secret')), 0, 16);
$f = ARBO_APP . '/storage/limite-' . $ip . '.txt';
$liste = array_filter(explode("\n", (string)@file_get_contents($f)), fn($t) => (int)$t > time() - 3600);
if (count($liste) >= 5) { repondre(429, ['ok' => false, 'message' => 'Trop de demandes. Réessayez dans une heure.']); }
$liste[] = (string)time();
@file_put_contents($f, implode("\n", $liste));

try {
    $r = traiter_demande($in);
    repondre(200, ['ok' => true, 'id' => $r['id']]);
} catch (ErreurDemande $e) {
    repondre(422, ['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    journal('api/demande : ' . $e->getMessage());
    repondre(500, ['ok' => false, 'message' => 'Erreur technique. Votre demande n’a pas pu être enregistrée ; écrivez-nous via le formulaire de contact.']);
}
