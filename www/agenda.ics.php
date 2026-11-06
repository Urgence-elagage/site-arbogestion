<?php
// Abonnement à l'agenda des interventions depuis le calendrier du téléphone (adresse secrète, lecture seule).
declare(strict_types=1);
require dirname(__DIR__) . '/app/agenda.php';
if (!hash_equals(agenda_ics_cle(), (string)($_GET['k'] ?? ''))) { http_response_code(403); exit; }
header('Content-Type: text/calendar; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
echo agenda_ics();
