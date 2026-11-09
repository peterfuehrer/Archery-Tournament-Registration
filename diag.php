<?php
/* Turnier-Anmeldeplattform für ianseo – Copyright (C) 2026 Peter Führer – GNU AGPLv3 (OSI-approved), siehe LICENSE – Version 2026.11.08-a */
// Diagnose für Administratoren: prüft Dateistand, Datenbank und ruft admin.php?p=master selbst auf.
require __DIR__ . '/lib.php';
if (role() !== 'admin') { http_response_code(403); exit('Nur für Administratoren (bitte zuerst im Backend anmelden).'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    if (function_exists('opcache_reset')) { opcache_reset(); flash('OPcache geleert.'); } else flash('OPcache nicht verfügbar.', 'err');
    go('diag.php');
}
$rows = [];
$add = function (string $k, string $v, ?bool $ok = null) use (&$rows) { $rows[] = [$k, $v, $ok]; };
$add('Version lib.php', APP_VERSION);
$add('PHP', PHP_VERSION . ' (' . PHP_SAPI . '), memory_limit ' . ini_get('memory_limit') . ', display_errors=' . ini_get('display_errors'));
$add('error_log', (string)(ini_get('error_log') ?: 'Standard des Servers'));
$add('OPcache', (function_exists('opcache_get_status') && ($st = @opcache_get_status(false))) ? 'aktiv, validate_timestamps=' . ini_get('opcache.validate_timestamps') . ', revalidate_freq=' . ini_get('opcache.revalidate_freq') : 'nicht aktiv');
foreach (['admin.php', 'lib.php', 'index.php', 'style.css', 'lang_en.php'] as $f) {
    $p = __DIR__ . '/' . $f;
    $c = is_file($p) ? (string)file_get_contents($p) : '';
    $extra = $f === 'admin.php' ? ', Stammdaten-Block: ' . (str_contains($c, "p === 'master'") ? 'JA' : 'NEIN') . ', ADMIN_VERSION: ' . (preg_match("/ADMIN_VERSION = '([^']+)'/", $c, $m) ? $m[1] : 'fehlt') : '';
    $add('Datei ' . $f, is_file($p) ? filesize($p) . ' Byte, ' . date('d.m.Y H:i', (int)filemtime($p)) . $extra : 'FEHLT', is_file($p) && ($f !== 'admin.php' || str_contains($c, "p === 'master'")));
}
$cf = config_file();
$add('Konfigurationsdatei (Datenbank-Zugangsdaten)', $cf ? (str_starts_with($cf, __DIR__) ? 'im Plattform-Ordner (' . basename($cf) . ') – besser außerhalb des Web-Ordners, siehe README' : 'außerhalb des Plattform-Ordners') . ', Rechte ' . substr(sprintf('%o', (int)@fileperms($cf)), -4) : 'nicht gefunden', $cf && !str_starts_with($cf, __DIR__) ? true : null);
$add('Empfohlener Ablageort der Konfiguration', dirname(__DIR__) . '/anmeldung-config-' . substr(md5(__DIR__), 0, 8) . '.php' . (is_writable(dirname(__DIR__)) ? '' : ' (Ordner darüber ist nicht beschreibbar)'));
$add('Schutz-Datei .htaccess', is_file(__DIR__ . '/.htaccess') ? 'vorhanden' : 'FEHLT (Apache: schützt config.php u. a. vor direktem Abruf)', is_file(__DIR__ . '/.htaccess'));
$base0 = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname((string)$_SERVER['SCRIPT_NAME']), '/');
$ctx0 = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
foreach (['config.php', 'config.sample.php', 'lib.php'] as $f0) {
    $b0 = @file_get_contents($base0 . '/' . $f0, false, $ctx0); $st0 = '?';
    foreach ($http_response_header ?? [] as $h0) if (preg_match('~^HTTP/\S+\s+(\d+)~', $h0, $m0)) $st0 = $m0[1];
    $leak = $b0 !== false && (str_contains($b0, 'HIER_PASSWORT') || preg_match("/'pass'\s*=>/", $b0));
    $add('Abruf von außen: /' . $f0, $b0 === false ? 'nicht möglich (Selbstaufruf gesperrt)' : 'HTTP ' . $st0 . ($leak ? ' – INHALT SICHTBAR!' : ' – Inhalt nicht lesbar'), $b0 === false ? null : !$leak);
}
foreach (['reg_users', 'reg_events', 'reg_sessions', 'reg_entries', 'reg_clubs', 'reg_athletes', 'reg_settings', 'reg_event_users', 'reg_ratelimit', 'reg_api_cache'] as $t) {
    try { $add('Tabelle ' . $t, (string)(int)q("SELECT COUNT(*) FROM `$t`")->fetchColumn() . ' Einträge', true); } catch (Throwable $x) { $add('Tabelle ' . $t, 'FEHLER: ' . $x->getMessage(), false); }
}
try {
    $n = q("SELECT a.license,a.lastname,a.firstname,a.club_code,c.name,c.state_code,c.state FROM reg_athletes a LEFT JOIN reg_clubs c ON c.code=a.club_code WHERE ?='' OR a.license LIKE ? ORDER BY a.lastname,a.firstname LIMIT 300", ['', '%'])->fetchAll(PDO::FETCH_NUM);
    $add('Abfrage der Stammdaten-Seite', count($n) . ' Zeilen gelesen', true);
} catch (Throwable $x) { $add('Abfrage der Stammdaten-Seite', 'FEHLER: ' . $x->getMessage(), false); }
// Selbsttest: admin.php?p=master mit der eigenen Sitzung abrufen
$sid = session_id(); $sn = session_name(); session_write_close();
$url = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname((string)$_SERVER['SCRIPT_NAME']), '/') . '/admin.php?p=master';
$ctx = stream_context_create(['http' => ['header' => "Cookie: $sn=$sid\r\n", 'timeout' => 10, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
$body = @file_get_contents($url, false, $ctx);
if ($body === false) $add('Selbsttest admin.php?p=master', 'nicht möglich (allow_url_fopen oder Selbstaufruf gesperrt)', null);
else {
    $main = preg_match('~<main[^>]*>(.*?)</main>~s', $body, $mm) ? trim(strip_tags($mm[1])) : '(kein <main> gefunden)';
    $add('Selbsttest admin.php?p=master', ($http_response_header[0] ?? '?') . ' · Seite ' . strlen($body) . ' Byte · Inhalt: ' . (str_contains($body, 'Stammdaten: Schützen und Vereine') ? 'Überschrift vorhanden' : 'Überschrift FEHLT') . ' · Textauszug: „' . mb_substr($main, 0, 160) . '“', str_contains($body, 'Stammdaten: Schützen und Vereine'));
}
@session_start();
page_head('Diagnose', '<a href="admin.php">Zum Backend</a>');
echo '<h2>Diagnose</h2><div class="box scroll"><table><tbody>';
foreach ($rows as [$k, $v, $ok]) echo '<tr><th>' . e($k) . '</th><td>' . ($ok === null ? '' : ($ok ? '<span class="tag paid">OK</span> ' : '<span class="tag open">Problem</span> ')) . e($v) . '</td></tr>';
echo '</tbody></table></div>';
if (function_exists('opcache_reset')) echo '<form method="post" class="box stack">' . tf() . '<p class="info">Falls hochgeladene Dateien nicht übernommen werden (Zwischenspeicher des Servers):</p><button>OPcache leeren</button></form>';
echo '<p class="info">Diese Seite nach der Fehlersuche bitte löschen (diag.php).</p>';
page_foot();
