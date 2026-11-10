<?php
/* Turnier-Anmeldeplattform für ianseo – Copyright (C) 2026 Peter Führer – GNU AGPLv3 (OSI-approved), siehe LICENSE – Version 2026.11.08-d */
// AGPLv3 §13: bietet allen Nutzern den vollständigen Quellcode dieser Version zum Download an (ohne config.php und Uploads).
declare(strict_types=1);
if (!class_exists('ZipArchive')) { http_response_code(501); exit('ZipArchive (PHP-Erweiterung zip) ist nicht verfügbar – Quellcode siehe LICENSE/README bzw. beim Betreiber anfragen.'); }
$root = __DIR__;
$files = ['LICENSE', 'README.txt', 'config.sample.php', 'style.css', 'lang_en.php'];
foreach (glob($root . '/*.php') ?: [] as $p) { $b = basename($p); if ($b !== 'config.php') $files[] = $b; }
foreach (glob($root . '/assets/*') ?: [] as $p) if (is_file($p)) $files[] = 'assets/' . basename($p);
$files[] = 'uploads/.htaccess'; $files[] = '.htaccess';
$tmp = tempnam(sys_get_temp_dir(), 'src');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
foreach (array_unique($files) as $f) if (is_file($root . '/' . $f)) $zip->addFile($root . '/' . $f, 'anmeldung/' . $f);
$zip->close();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="anmeldeplattform-quellcode.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
