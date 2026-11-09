<?php
/* Turnier-Anmeldeplattform für ianseo – Copyright (C) 2026 Peter Führer – GNU AGPLv3 (OSI-approved), siehe LICENSE – Version 2026.11.08-a */
// Automatisches Ausfüllen: liefert zu einer Lizenznummer NUR Vorname, Nachname und Verein (nur wenn in den Einstellungen aktiviert; Abfragen sind je IP begrenzt).
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = ['ok' => false, 'msg' => ''];
if (setting('autofill') !== '1' || !athletes_available()) { echo json_encode($out); exit; }
$lic = trim((string)($_GET['lic'] ?? ''));
if ($lic === '' || strlen($lic) > 25) { echo json_encode($out); exit; }
if (!rate_limit('lookup', 15, 600)) { $out['msg'] = t('Zu viele Anfragen – bitte später erneut versuchen.'); http_response_code(429); echo json_encode($out, JSON_UNESCAPED_UNICODE); exit; }
$a = athlete_lookup($lic);
if (!$a) { $out['msg'] = t('Lizenznummer nicht in der Lizenzliste gefunden. Bitte Daten selbst eingeben.'); echo json_encode($out, JSON_UNESCAPED_UNICODE); exit; }
echo json_encode(['ok' => true, 'msg' => t('Daten aus der Lizenzliste übernommen – bitte prüfen.')] + $a, JSON_UNESCAPED_UNICODE);
