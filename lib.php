<?php
/*
 * Turnier-Anmeldeplattform für ianseo – Copyright (C) 2026 Peter Führer – Version 2026.11.08-d
 *
 * This program is free software: you can redistribute it and/or modify it under the terms of the
 * GNU Affero General Public License as published by the Free Software Foundation, either version 3
 * of the License, or (at your option) any later version. It is released under the GNU AGPLv3, an
 * OSI-approved open source license (https://opensource.org/license/agpl-v3), and meets the
 * Open Source Definition. See the file LICENSE. / Veröffentlicht unter der GNU AGPLv3 (OSI-konform).
 */
declare(strict_types=1);
// Copyright (C) 2026 Peter Führer – nur als Vermerk im Quelltext, wird nicht auf den Seiten angezeigt.
const APP_VERSION = '2026.11.08-d';
// Konfiguration (enthält die Datenbank-Zugangsdaten): bevorzugt außerhalb des Web-Ordners, sonst config.php im Plattform-Ordner
function config_file(): ?string {
    foreach ([(string)getenv('ANMELDUNG_CONFIG'), dirname(__DIR__) . '/anmeldung-config-' . substr(md5(__DIR__), 0, 8) . '.php', __DIR__ . '/config.php'] as $p)
        if ($p !== '' && is_file($p)) return $p;
    return null;
}
$CFGFILE = config_file();
if ($CFGFILE === null) {
    if (!defined('INSTALLING')) {
        if (is_file(__DIR__ . '/install.php')) { header('Location: install.php'); exit; }
        exit('config.php fehlt – config.sample.php nach config.php kopieren und anpassen.');
    }
    $CFG = ['site_title' => 'Turnier-Anmeldung', 'debug' => false, 'db' => ['host' => 'localhost', 'name' => '', 'user' => '', 'pass' => ''], 'mail' => []];
} else $CFG = require $CFGFILE;
date_default_timezone_set('Europe/Vienna');
set_exception_handler(function (Throwable $x) {
    global $CFG; error_log('[anmeldung] ' . $x);
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><p style="font:16px sans-serif;padding:1rem">Es ist ein Fehler aufgetreten.'
       . (!empty($CFG['debug']) ? '<br><code>' . htmlspecialchars($x->getMessage()) . '</code>' : ' Bitte später erneut versuchen.') . '</p>';
});
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

// ---- Sprache (Deutsch = Originaltext, Englisch über lang_en.php) -------------------------
function lang(): string {
    static $l = null;
    if ($l !== null) return $l;
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['de', 'en'], true)) {
        $_SESSION['lang'] = $_GET['lang'];
        setcookie('lang', $_GET['lang'], ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
    }
    $l = (string)($_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'de'));
    return $l = in_array($l, ['de', 'en'], true) ? $l : 'de';
}
function tl(string $lang, string $de, ...$args): string {
    static $en = null;
    $s = $de;
    if ($lang === 'en') { $en ??= require __DIR__ . '/lang_en.php'; $s = $en[$de] ?? $de; }
    return $args ? vsprintf($s, $args) : $s;
}
function t(string $de, ...$args): string { return tl(lang(), $de, ...$args); }
function lang_url(string $l): string { $q = $_GET; $q['lang'] = $l; return strtok((string)$_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($q); }

// ---- Basis --------------------------------------------------------------
function db(): PDO {
    static $pdo = null; global $CFG;
    if (!$pdo) {
        $d = $CFG['db'];
        $pdo = new PDO("mysql:host={$d['host']};" . (!empty($d['port']) ? "port={$d['port']};" : '') . "dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec("SET SESSION sql_mode=''");   // wie ianseo: sonst scheitern Inserts an NOT-NULL-Spalten ohne Default
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['t'] ??= bin2hex(random_bytes(16)); }
function tf(): string { return '<input type="hidden" name="_t" value="' . csrf() . '">'; }
function post_guard(): void {
    if (!hash_equals(csrf(), (string)($_POST['_t'] ?? ''))) { http_response_code(400); exit('Ungültiges Formular-Token. Seite neu laden.'); }
}
function flash(string $m, string $type = 'ok'): void { $_SESSION['fl'][] = [$type, $m]; }
function show_flash(): void {
    foreach ($_SESSION['fl'] ?? [] as [$t, $m]) echo '<p class="msg ' . $t . '">' . e($m) . '</p>';
    unset($_SESSION['fl']);
}
function go(string $url): never { header('Location: ' . $url); exit; }
function fmt_date($d): string {
    $d = (string)$d; if ($d === '' || str_starts_with($d, '0000')) return '';
    $t = strtotime($d); return $t ? date('d.m.Y', $t) : '';
}

// ---- Schema (Updates laufen automatisch) -----------------------------------
function ensure_schema(): void {
    if (($_SESSION['schema_v'] ?? 0) === 13) return;
    try {
        $db = db();
        $db->exec("CREATE TABLE IF NOT EXISTS reg_settings (k VARCHAR(40) PRIMARY KEY, v TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $t = $db->query("SHOW COLUMNS FROM reg_settings LIKE 'v'")->fetch();                 // Impressum braucht mehr als 255 Zeichen
        if ($t && stripos($t['Type'], 'text') === false) $db->exec("ALTER TABLE reg_settings MODIFY v TEXT NOT NULL");
        $db->exec("CREATE TABLE IF NOT EXISTS reg_ratelimit (k VARCHAR(40) NOT NULL, ts INT NOT NULL, KEY kt (k, ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS reg_api_cache (k VARCHAR(40) PRIMARY KEY, data TEXT NOT NULL, ts INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS reg_log (id INT AUTO_INCREMENT PRIMARY KEY, ts DATETIME NOT NULL, event_id INT NULL, entry_id INT NULL, user_id INT NULL, actor VARCHAR(80) NOT NULL DEFAULT '', action VARCHAR(30) NOT NULL, details VARCHAR(500) NOT NULL DEFAULT '', KEY ev (event_id, ts), KEY ts (ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS reg_clubs (code VARCHAR(20) PRIMARY KEY, name VARCHAR(150) NOT NULL, state_code VARCHAR(10) NOT NULL DEFAULT '', state VARCHAR(60) NOT NULL DEFAULT '', KEY nm (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS reg_athletes (license VARCHAR(25) PRIMARY KEY, lastname VARCHAR(60) NOT NULL, firstname VARCHAR(60) NOT NULL, club_code VARCHAR(20) NOT NULL DEFAULT '', KEY nm (lastname, firstname)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS reg_event_users (event_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (event_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $add = [
            ['reg_users', 'role', "VARCHAR(12) NOT NULL DEFAULT 'admin'"],
            ['reg_users', 'fullname', "VARCHAR(100) NOT NULL DEFAULT ''"],
            ['reg_users', 'email', "VARCHAR(150) NOT NULL DEFAULT ''"],
            ['reg_events', 'kind', "VARCHAR(10) NOT NULL DEFAULT 'normal'"],
            ['reg_events', 'owner_id', "INT NULL"],
            ['reg_events', 'ref_text', "VARCHAR(200) NOT NULL DEFAULT ''"],
            ['reg_events', 'doc', "VARCHAR(120) NOT NULL DEFAULT ''"],
            ['reg_events', 'doc_url', "VARCHAR(300) NOT NULL DEFAULT ''"],
            ['reg_events', 'logo', "VARCHAR(120) NOT NULL DEFAULT ''"],
            ['reg_athletes', 'sex', "TINYINT NULL"],
            ['reg_sessions', 'closed', "TINYINT NOT NULL DEFAULT 0"],
            ['reg_sessions', 'deadline', "DATE NULL"],
            ['reg_entries', 'consent_at', "DATETIME NULL"],
            ['reg_entries', 'consent_media', "TINYINT NOT NULL DEFAULT 0"],
            ['reg_events', 'mail_tpl', "TEXT NULL"],
            ['reg_events', 'paypal_me', "VARCHAR(80) NOT NULL DEFAULT ''"],
            ['reg_events', 'fee', "VARCHAR(12) NOT NULL DEFAULT ''"],
            ['reg_entries', 'country', "VARCHAR(3) NOT NULL DEFAULT ''"],
            ['reg_entries', 'lang', "VARCHAR(2) NOT NULL DEFAULT 'de'"],
            ['reg_clubs', 'short', "VARCHAR(30) NOT NULL DEFAULT ''"],
            ['reg_events', 'allow_nolic', "TINYINT NOT NULL DEFAULT 0"],
            ['reg_events', 'nolic_next', "INT NOT NULL DEFAULT 1"],
            ['reg_sessions', 'divisions', "VARCHAR(255) NOT NULL DEFAULT ''"],
            ['reg_entries', 'qtype', "TINYINT NULL"],
            ['reg_entries', 'q1', "VARCHAR(150) NOT NULL DEFAULT ''"],
            ['reg_entries', 'q2', "VARCHAR(150) NOT NULL DEFAULT ''"],
            ['reg_entries', 'q3', "VARCHAR(150) NOT NULL DEFAULT ''"],
            ['reg_entries', 'qy', "VARCHAR(4) NOT NULL DEFAULT ''"],
            ['reg_entries', 'qe', "VARCHAR(150) NOT NULL DEFAULT ''"],
            ['reg_entries', 'club_code', "VARCHAR(20) NOT NULL DEFAULT ''"],
            ['reg_entries', 'lic_check', "VARCHAR(12) NOT NULL DEFAULT ''"],
        ];
        foreach ($add as [$t, $c, $def])
            if (!$db->query("SHOW COLUMNS FROM `$t` LIKE " . $db->quote($c))->fetch()) $db->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        $c = $db->query("SHOW COLUMNS FROM reg_entries LIKE 'dob'")->fetch();   // Geburtsdatum optional -> NULL statt Nulldatum
        if ($c && $c['Null'] === 'NO') $db->exec("ALTER TABLE reg_entries MODIFY dob DATE NULL");
        $db->exec("UPDATE reg_entries SET dob=NULL WHERE dob='0000-00-00'");
        $_SESSION['schema_v'] = 13;
    } catch (Throwable $x) { error_log('[anmeldung] Schema: ' . $x->getMessage()); }
}
function setting(string $k): string {
    static $c = null;
    if ($c === null) { try { $c = array_column(db()->query("SELECT k,v FROM reg_settings")->fetchAll(), 'v', 'k'); } catch (Throwable $x) { $c = []; } }
    return (string)($c[$k] ?? '');
}
function site_title(): string { global $CFG; return setting('site_title') !== '' ? setting('site_title') : (string)($CFG['site_title'] ?? 'Turnier-Anmeldung'); }
function set_setting(string $k, string $v): void { q("REPLACE INTO reg_settings (k,v) VALUES (?,?)", [$k, $v]); }

// ---- Rollen ---------------------------------------------------------------
const ROLES = ['admin' => 'Administrator', 'orga' => 'Organisationsleitung', 'kassier' => 'Kassier'];
function uid(): int { return (int)($_SESSION['uid'] ?? 0); }
function role(): string {
    if (!uid()) return '';
    if (empty($_SESSION['role'])) $_SESSION['role'] = (string)q("SELECT role FROM reg_users WHERE id=?", [uid()])->fetchColumn();
    return (string)$_SESSION['role'];
}
function is_admin(): bool { return role() === 'admin'; }
function can_create_event(): bool { return in_array(role(), ['admin', 'orga'], true); }
function can_event(array $ev): bool { return is_admin() || (role() === 'orga' && (int)$ev['owner_id'] === uid()); }
function is_kassier_of(array $ev): bool {
    return role() === 'kassier' && (bool)q("SELECT 1 FROM reg_event_users WHERE event_id=? AND user_id=?", [$ev['id'], uid()])->fetch();
}
function can_cash(array $ev): bool { return can_event($ev) || is_kassier_of($ev); }   // bezahlt / zurücksetzen / bearbeiten / löschen (Admin, Organisationsleitung des Turniers, freigegebener Kassier)
function can_view(array $ev): bool { return can_event($ev) || is_kassier_of($ev); }
function deny(): never { http_response_code(403); exit('Keine Berechtigung.'); }

// ---- Layout -------------------------------------------------------------
function logo_html(?string $evLogo = null): string {
    foreach ([$evLogo, setting('logo')] as $l)
        if ($l && preg_match('/^[a-z]+-[0-9a-f]{16}\.(png|jpg|webp)$/', $l) && is_file(__DIR__ . '/uploads/' . $l))
            return '<img class="logo" src="uploads/' . e($l) . '" alt="Logo">';
    return '<span class="rings" aria-hidden="true"></span>';
}
function flag_svg(string $c): string {      // DE = österreichische Flagge, EN = britische Flagge (Union Jack)
    if ($c === 'at') return '<svg class="flag" viewBox="0 0 9 6" width="30" height="20" aria-hidden="true"><rect width="9" height="6" fill="#ed2939"/><rect y="2" width="9" height="2" fill="#fff"/></svg>';
    return '<svg class="flag" viewBox="0 0 60 30" width="40" height="20" aria-hidden="true"><clipPath id="ukS"><path d="M0,0 v30 h60 v-30 z"/></clipPath><clipPath id="ukT"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath><g clip-path="url(#ukS)"><path d="M0,0 v30 h60 v-30 z" fill="#012169"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/><path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#ukT)" stroke="#c8102e" stroke-width="4"/><path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/><path d="M30,0 v30 M0,15 h60" stroke="#c8102e" stroke-width="6"/></g></svg>';
}
function is_public_page(): bool { return basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'admin.php'; }
function page_head(string $title, string $nav = '', ?string $logo = null): void {
    $sw = !is_public_page() ? '' : '<div class="lang" role="group" aria-label="Sprache / Language">'
        . '<a href="' . e(lang_url('de')) . '" lang="de" hreflang="de" title="Deutsch" aria-label="Deutsch"' . (lang() === 'de' ? ' class="on" aria-current="true"' : '') . '>' . flag_svg('at') . '</a>'
        . '<a href="' . e(lang_url('en')) . '" lang="en" hreflang="en" title="English" aria-label="English"' . (lang() === 'en' ? ' class="on" aria-current="true"' : '') . '>' . flag_svg('gb') . '</a></div>';
    echo '<!doctype html><html lang="' . lang() . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
       . '<meta name="theme-color" content="#005f6a"><title>' . e($title) . '</title><link rel="stylesheet" href="style.css?v=' . (int)@filemtime(__DIR__ . '/style.css') . '"></head><body>'
       . '<a class="skip" href="#main">' . e(t('Zum Inhalt springen')) . '</a>'
       . '<header class="top"><div class="top-in"><a class="brand" href="index.php">' . logo_html() . '<strong>' . e(site_title()) . '</strong></a>'
       . ($nav !== '' ? '<nav class="topnav">' . $nav . '</nav>' : '') . $sw . '</div></header><main id="main">';
    show_flash();
}
function page_foot(): void {
    $pub = is_public_page();
    $osiFile = ''; foreach (['osi-approved-license.svg', 'osi-approved-license.png'] as $f) if (is_file(__DIR__ . '/assets/' . $f)) { $osiFile = $f; break; }
    $osi = $osiFile !== '' ? '<a class="gplink" href="https://opensource.org/" target="_blank" rel="noopener" title="Open Source Initiative"><img src="assets/' . $osiFile . '" alt="OSI Approved License" height="51"></a>' : '';   // nur wenn die Logo-Datei vorhanden ist
    $src = setting('source_url') !== '' ? setting('source_url') : (is_file(__DIR__ . '/source.php') ? 'source.php' : '');
    echo '</main><footer class="foot"><div class="foot-in"><div class="foot-l"><span class="ver">Version ' . APP_VERSION . (defined('ADMIN_VERSION') ? ' · admin.php ' . ADMIN_VERSION : '') . '</span>'
       . '<nav class="foot-nav"><a href="impressum.php">' . e(t('Impressum')) . '</a><a href="datenschutz.php">' . e(t('Datenschutzerklärung')) . '</a>'
       . ($pub ? '<a href="#" id="cookie-open">' . e(t('Cookie-Einstellungen')) . '</a>' : '') . ($src !== '' ? '<a href="' . e($src) . '">' . e(t('Quellcode')) . '</a>' : '') . '</nav></div>'
       . '<div class="foot-r">' . ($osi !== '' ? '<div class="lic-logos">' . $osi . '</div>' : '')
       . '<p><a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener license" title="GNU Affero General Public License v3">' . e(t('Veröffentlicht unter der GNU Affero General Public License v3 (OSI-konforme Open-Source-Lizenz).')) . '</a></p>'
       . ($osi !== '' ? '<p class="tm">' . e(t('OSI Approved License® – Das OSI-Logo ist eine Marke der Open Source Initiative. Dieses Projekt ist nicht mit der OSI verbunden und wird von ihr nicht unterstützt.')) . '</p>' : '') . '</div></div></footer>';
    if ($pub) {
        echo '<div id="cookiebar" class="cookiebar" role="dialog" aria-modal="false" aria-labelledby="cb-t" hidden><div class="cookiebar-in"><div class="cb-text"><strong id="cb-t">' . e(t('Cookies und Datenschutz')) . '</strong>'
           . '<p>' . str_replace('%s', '<a href="datenschutz.php">' . e(t('Datenschutzerklärung')) . '</a>', e(t('Diese Website verwendet ausschließlich technisch notwendige Cookies (Sitzung, Sprache, Hinweis-Bestätigung). Es gibt keine Tracking-, Analyse- oder Werbe-Cookies und keine Einbindung externer Dienste. Details in der %s.'))) . '</p>'
           . '<details><summary>' . e(t('Welche Cookies werden gesetzt?')) . '</summary><div class="scroll"><table><thead><tr><th>' . e(t('Name')) . '</th><th>' . e(t('Zweck')) . '</th><th>' . e(t('Dauer')) . '</th></tr></thead><tbody>'
           . '<tr><td>PHPSESSID</td><td>' . e(t('Sitzung, Schutz vor Formularmissbrauch (notwendig)')) . '</td><td>' . e(t('bis zum Schließen des Browsers')) . '</td></tr>'
           . '<tr><td>lang</td><td>' . e(t('gewählte Sprache (notwendig)')) . '</td><td>' . e(t('12 Monate')) . '</td></tr>'
           . '<tr><td>cookie_consent</td><td>' . e(t('Bestätigung dieses Hinweises (notwendig)')) . '</td><td>' . e(t('12 Monate')) . '</td></tr></tbody></table></div></details></div>'
           . '<div class="cb-btns"><button type="button" id="cookie-ok">' . e(t('Verstanden')) . '</button><a href="datenschutz.php">' . e(t('Datenschutzerklärung')) . '</a></div></div></div>'
           . '<script>(function(){var b=document.getElementById("cookiebar");if(!b)return;function has(){return document.cookie.split("; ").some(function(c){return c.indexOf("cookie_consent=")===0})}'
           . 'if(!has())b.hidden=false;document.getElementById("cookie-ok").addEventListener("click",function(){document.cookie="cookie_consent=1; Max-Age=31536000; Path=/; SameSite=Lax"+(location.protocol==="https:"?"; Secure":"");b.hidden=true});'
           . 'var o=document.getElementById("cookie-open");if(o)o.addEventListener("click",function(e){e.preventDefault();b.hidden=false})})();</script>';
    }
    echo '</body></html>';
}

function owner_of(array $ev): ?array {
    if (empty($ev['owner_id'])) return null;
    return q("SELECT username,fullname,email FROM reg_users WHERE id=?", [$ev['owner_id']])->fetch() ?: null;
}
function doc_link(array $ev): string {
    $h = '';
    if (!empty($ev['doc']) && preg_match('/^pdf-[0-9a-f]{16}\.pdf$/', $ev['doc']) && is_file(__DIR__ . '/uploads/' . $ev['doc']))
        $h .= '<a href="uploads/' . e($ev['doc']) . '" target="_blank" rel="noopener">' . e(t('Ausschreibung (PDF)')) . '</a>';
    if (!empty($ev['doc_url']) && preg_match('#^https?://#i', $ev['doc_url']))
        $h .= ($h ? ' · ' : '') . '<a href="' . e($ev['doc_url']) . '" target="_blank" rel="noopener">' . e(t('Ausschreibung online')) . '</a>';
    return $h;
}
// Eckdaten des Turniers (Kopfbereich)
function event_head(array $ev): void {
    $t = [];
    try { $t = q("SELECT * FROM Tournament WHERE ToId=?", [$ev['to_id']])->fetch() ?: []; } catch (Throwable $x) {}
    $from = fmt_date($t['ToWhenFrom'] ?? ''); $to = fmt_date($t['ToWhenTo'] ?? '');
    $date = ($from === $to) ? $from : trim($from . ' – ' . $to, ' –');
    $o = owner_of($ev); $items = [];
    if ($date !== '') $items[] = [t('Datum'), e($date)];
    if (!empty($t['ToWhere'])) $items[] = [t('Ort'), e($t['ToWhere'])];
    if (!empty($ev['deadline'])) $items[] = [t('Anmeldeschluss'), e(fmt_date($ev['deadline']))];
    if ($o) $items[] = [t('Organisation'), e($o['fullname'] !== '' ? $o['fullname'] : $o['username']) . ($o['email'] !== '' ? ' · <a href="mailto:' . e($o['email']) . '">' . e($o['email']) . '</a>' : '')];
    if (($d = doc_link($ev)) !== '') $items[] = [t('Ausschreibung'), $d];
    $img = (!empty($ev['logo']) && preg_match('/^logo-[0-9a-f]{16}\.(png|jpg|webp)$/', $ev['logo']) && is_file(__DIR__ . '/uploads/' . $ev['logo']))
        ? '<img class="evlogo" src="uploads/' . e($ev['logo']) . '" alt="Turnierlogo">' : '';
    echo '<section class="evhead"><div class="evbody"><h2>' . e($ev['title']) . ($ev['kind'] === 'oem' ? ' <span class="tag">ÖM/ÖSTM</span>' : '') . '</h2><dl>';
    foreach ($items as [$k, $v]) echo '<div><dt>' . $k . '</dt><dd>' . $v . '</dd></div>';
    echo '</dl></div>' . $img . '</section>';
}

// ---- Uploads (Logo, Ausschreibung) -----------------------------------------
function save_upload(array $f, string $kind): string {          // $kind: 'logo' | 'pdf'
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'Datei ist größer als das Server-Limit (upload_max_filesize).' : 'Upload fehlgeschlagen (Code ' . $err . ').');
    if ($f['size'] > ($kind === 'pdf' ? 10 : 2) * 1048576) throw new RuntimeException('Datei zu groß (max. ' . ($kind === 'pdf' ? '10' : '2') . ' MB).');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ($kind === 'pdf' ? ['application/pdf' => 'pdf'] : ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'])[$mime] ?? null;
    if (!$ext) throw new RuntimeException($kind === 'pdf' ? 'Nur PDF-Dateien erlaubt.' : 'Nur PNG, JPG oder WebP erlaubt.');
    if ($kind === 'logo' && !@getimagesize($f['tmp_name'])) throw new RuntimeException('Bild ungültig.');
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)\$\">\n    Require all denied\n</FilesMatch>\n");
    $name = $kind . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Datei konnte nicht gespeichert werden (Schreibrechte für uploads/ prüfen).');
    return $name;
}
function delete_upload(?string $name): void {
    if ($name && preg_match('/^(logo|pdf)-[0-9a-f]{16}\.(pdf|png|jpg|webp)$/', $name)) @unlink(__DIR__ . '/uploads/' . $name);
}

// ---- Sicheres HTML (Impressum, Zusatztexte): Nur harmlose Tags, keine Skripte/Attribute -------------------
function sanitize_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    $mail = fn(string $h) => preg_replace('/([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', '<a href="mailto:$1">$1</a>', $h);
    if (!preg_match('~</?[a-z][^>]*>~i', $html)) return $mail(nl2br(e($html)));                // reiner Text
    if (!class_exists('DOMDocument')) return nl2br(e(strip_tags($html)));
    $ok = ['p', 'br', 'a', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'h5', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'div', 'span', 'hr', 'small', 'address', 'blockquote', 'sup', 'sub'];
    $drop = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'svg', 'math', 'base'];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('root');
    if (!$root) return nl2br(e(strip_tags($html)));
    $clean = function (DOMNode $n) use (&$clean, $ok, $drop): void {
        foreach (iterator_to_array($n->childNodes) as $c) {
            if ($c instanceof DOMComment || $c instanceof DOMProcessingInstruction) { $n->removeChild($c); continue; }
            if (!($c instanceof DOMElement)) continue;
            $tag = strtolower($c->tagName);
            if (in_array($tag, $drop, true)) { $n->removeChild($c); continue; }
            $clean($c);
            if (!in_array($tag, $ok, true)) { while ($c->firstChild) $n->insertBefore($c->firstChild, $c); $n->removeChild($c); continue; }
            foreach (iterator_to_array($c->attributes) as $a) {
                $name = strtolower($a->name);
                if ($tag === 'a' && $name === 'href' && preg_match('~^(https?://|mailto:|tel:|/|#)~i', trim($a->value))) continue;
                if (in_array($name, ['colspan', 'rowspan'], true)) continue;
                $c->removeAttribute($a->name);
            }
            if ($tag === 'a') { $c->setAttribute('rel', 'noopener noreferrer'); if (stripos((string)$c->getAttribute('href'), 'http') === 0) $c->setAttribute('target', '_blank'); }
        }
    };
    $clean($root);
    $out = ''; foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}
// Begrenzt Anfragen je IP (z. B. Lizenz-Abfrage): true = erlaubt
function rate_limit(string $key, int $max, int $window, bool $perIp = true): bool {
    $k = substr(hash('sha256', $key . '|' . ($perIp ? ($_SERVER['REMOTE_ADDR'] ?? '') : 'global')), 0, 40);
    q("DELETE FROM reg_ratelimit WHERE ts < ?", [time() - 3600]);
    if ((int)q("SELECT COUNT(*) FROM reg_ratelimit WHERE k=? AND ts > ?", [$k, time() - $window])->fetchColumn() >= $max) return false;
    q("INSERT INTO reg_ratelimit (k,ts) VALUES (?,?)", [$k, time()]);
    return true;
}
// PayPal.Me-Link (optional, ohne Skripte/Cookies – nur ein Link)
function paypal_url(array $ev): string {
    $h = trim((string)($ev['paypal_me'] ?? '')); if ($h === '') return '';
    $h = trim((string)preg_replace('~^(https?://)?(www\.)?paypal\.me/~i', '', $h), '/');
    if (!preg_match('/^[A-Za-z0-9._-]{1,60}$/', $h)) return '';
    $fee = str_replace(',', '.', (string)preg_replace('/[^0-9.,]/', '', (string)($ev['fee'] ?? '')));
    return 'https://www.paypal.me/' . $h . (is_numeric($fee) && (float)$fee > 0 ? '/' . number_format((float)$fee, 2, '.', '') . 'EUR' : '');
}

// ---- Länder (IOC-Codes) für ausländische Schützen -----------------------------------------
function ioc_countries(): array {
    return ['GER' => ['Deutschland', 'Germany'], 'SUI' => ['Schweiz', 'Switzerland'], 'ITA' => ['Italien', 'Italy'], 'CZE' => ['Tschechien', 'Czechia'], 'SVK' => ['Slowakei', 'Slovakia'],
        'HUN' => ['Ungarn', 'Hungary'], 'SLO' => ['Slowenien', 'Slovenia'], 'CRO' => ['Kroatien', 'Croatia'], 'POL' => ['Polen', 'Poland'], 'LIE' => ['Liechtenstein', 'Liechtenstein'],
        'FRA' => ['Frankreich', 'France'], 'NED' => ['Niederlande', 'Netherlands'], 'BEL' => ['Belgien', 'Belgium'], 'LUX' => ['Luxemburg', 'Luxembourg'], 'DEN' => ['Dänemark', 'Denmark'],
        'SWE' => ['Schweden', 'Sweden'], 'NOR' => ['Norwegen', 'Norway'], 'FIN' => ['Finnland', 'Finland'], 'GBR' => ['Großbritannien', 'Great Britain'], 'IRL' => ['Irland', 'Ireland'],
        'ESP' => ['Spanien', 'Spain'], 'POR' => ['Portugal', 'Portugal'], 'GRE' => ['Griechenland', 'Greece'], 'TUR' => ['Türkei', 'Türkiye'], 'ROU' => ['Rumänien', 'Romania'],
        'BUL' => ['Bulgarien', 'Bulgaria'], 'SRB' => ['Serbien', 'Serbia'], 'BIH' => ['Bosnien und Herzegowina', 'Bosnia and Herzegovina'], 'MKD' => ['Nordmazedonien', 'North Macedonia'],
        'MNE' => ['Montenegro', 'Montenegro'], 'UKR' => ['Ukraine', 'Ukraine'], 'LTU' => ['Litauen', 'Lithuania'], 'LAT' => ['Lettland', 'Latvia'], 'EST' => ['Estland', 'Estonia'],
        'ISR' => ['Israel', 'Israel'], 'USA' => ['USA', 'USA'], 'CAN' => ['Kanada', 'Canada'], 'JPN' => ['Japan', 'Japan'], 'KOR' => ['Südkorea', 'South Korea'], 'CHN' => ['China', 'China'],
        'IND' => ['Indien', 'India'], 'AUS' => ['Australien', 'Australia'], 'BRA' => ['Brasilien', 'Brazil']];
}
function ioc_name(string $code, ?string $lang = null): string { $c = ioc_countries()[$code] ?? null; return $c ? $c[($lang ?? lang()) === 'en' ? 1 : 0] : $code; }

// ---- Texte ------------------------------------------------------------------
function ref_code(int $eventId, int $entryId): string { return sprintf('A%d-%05d', $eventId, $entryId); }
// Verwendungszweck: Freitext des Veranstalters, Platzhalter {ref} {vorname} {nachname}
function purpose_text(array $ev, array $e, string $ref): string {
    $t = trim((string)($ev['ref_text'] ?? ''));
    return $t === '' ? $ref : strtr($t, ['{ref}' => $ref, '{vorname}' => $e['firstname'], '{nachname}' => $e['lastname']]);
}
function qual_text(array $r): string {
    if ((int)($r['qtype'] ?? 0) === 1) return '3 Turniere: ' . implode('; ', array_filter([$r['q1'], $r['q2'], $r['q3']]));
    if ((int)($r['qtype'] ?? 0) === 2) return 'ÖM/ÖSTM ' . $r['qy'] . ': ' . $r['qe'];
    return '';
}

// ---- Protokoll der Anmeldungen (Backend -> Protokoll) -------------------------------------------------
const LOG_ACTIONS = ['anmeldung' => 'Neue Anmeldung', 'bezahlt' => 'Als bezahlt markiert', 'zurueck' => 'Zahlung zurückgesetzt', 'bearbeitet' => 'Anmeldung bearbeitet', 'verschoben' => 'Durchgang gewechselt',
    'geloescht' => 'Anmeldung gelöscht', 'import' => 'Import', 'ianseo_neu' => 'Nach ianseo übertragen', 'ianseo_entfernt' => 'Aus ianseo entfernt', 'abgleich' => 'Lizenzabgleich',
    'turnier_auf' => 'Turnier geöffnet', 'turnier_zu' => 'Turnier geschlossen', 'durchgang_auf' => 'Durchgang geöffnet', 'durchgang_zu' => 'Durchgang geschlossen', 'turnier_geloescht' => 'Turnier entfernt'];
function log_event(string $action, ?int $eventId = null, ?int $entryId = null, string $details = '', ?string $actor = null): void {
    try {
        q("INSERT INTO reg_log (ts,event_id,entry_id,user_id,actor,action,details) VALUES (?,?,?,?,?,?,?)",
          [date('Y-m-d H:i:s'), $eventId, $entryId, uid() ?: null, mb_substr($actor ?? (uid() ? (string)($_SESSION['un'] ?? '') : 'Teilnehmer/in'), 0, 80), $action, mb_substr($details, 0, 500)]);
        if (mt_rand(1, 100) === 1) q("DELETE FROM reg_log WHERE ts < ?", [date('Y-m-d H:i:s', time() - 730 * 86400)]);      // Aufbewahrung höchstens 24 Monate
    } catch (Throwable $x) { error_log('[anmeldung] Protokoll: ' . $x->getMessage()); }
}

// ---- Mail-Konfiguration aus den Einstellungen (Passwörter verschlüsselt in der DB) ------------------
function secret_key(): string { global $CFG; return hash('sha256', ($CFG['secret'] ?? '') . '|' . ($CFG['db']['pass'] ?? '') . '|reg', true); }
function enc(string $s): string {
    if ($s === '' || !function_exists('openssl_encrypt')) return $s;
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($s, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $c === false ? $s : 'v1:' . base64_encode($iv . $tag . $c);
}
function dec(string $s): string {
    if (!str_starts_with($s, 'v1:') || !function_exists('openssl_decrypt')) return $s;
    $raw = (string)base64_decode(substr($s, 3));
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}
function mail_cfg(): array {
    global $CFG;
    $j = setting('mail_config');
    if ($j !== '' && is_array($c = json_decode($j, true))) {
        foreach (['smtp', 'imap'] as $k) if (isset($c[$k]['pass'])) $c[$k]['pass'] = dec((string)$c[$k]['pass']);
        return $c;
    }
    return $CFG['mail'] ?? [];                // Rückfall: config.php (ältere Installationen)
}

// ---- Mail (SMTP, optional IMAP-Ablage, oder PHP mail()) -------------------------
function mail_build(string $from, string $name, string $to, string $subject, string $body): string {
    $enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $h = ['Date: ' . date('r'), 'From: ' . ($name !== '' ? $enc($name) . ' ' : '') . '<' . $from . '>', 'To: <' . $to . '>',
          'Subject: ' . $enc($subject), 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . preg_replace('/^.*@/', '', $from) . '>',
          'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
    return implode("\r\n", $h) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
}
function smtp_send(array $c, string $from, string $to, string $raw): void {
    $sec = $c['secure'] ?? 'tls';
    $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . (int)$c['port'], $en, $es, 15);
    if (!$fp) throw new RuntimeException("SMTP-Verbindung fehlgeschlagen: $es");
    stream_set_timeout($fp, 15);
    $read = function () use ($fp): string { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = function (string $c2, array $ok) use ($fp, $read): string {
        if ($c2 !== '') fwrite($fp, $c2 . "\r\n");
        $r = $read(); if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim($r)); return $r;
    };
    $host = gethostname() ?: 'localhost';
    $cmd('', [220]); $cmd('EHLO ' . $host, [250]);
    if ($sec === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: TLS-Verhandlung fehlgeschlagen.');
        $cmd('EHLO ' . $host, [250]);
    }
    if (!empty($c['user'])) { $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($c['user']), [334]); $cmd(base64_encode((string)$c['pass']), [235]); }
    $cmd('MAIL FROM:<' . $from . '>', [250]); $cmd('RCPT TO:<' . $to . '>', [250, 251]); $cmd('DATA', [354]);
    fwrite($fp, $raw . ".\r\n"); $cmd('', [250]);
    try { $cmd('QUIT', [221]); } catch (Throwable $x) {}
    fclose($fp);
}
function imap_save_sent(array $c, string $raw): void {
    $sec = $c['secure'] ?? 'ssl';
    $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . (int)$c['port'], $en, $es, 15);
    if (!$fp) throw new RuntimeException("IMAP-Verbindung fehlgeschlagen: $es");
    stream_set_timeout($fp, 15);
    fgets($fp);                                                          // Begrüßung
    $n = 0;
    $run = function (string $cmd, ?string $literal = null) use (&$n, $fp): void {
        $t = 'A' . ++$n; fwrite($fp, "$t $cmd\r\n");
        if ($literal !== null) {
            $l = fgets($fp); if ($l === false || $l[0] !== '+') throw new RuntimeException('IMAP: ' . trim((string)$l));
            fwrite($fp, $literal . "\r\n");
        }
        while (($l = fgets($fp)) !== false) {
            if (str_starts_with($l, $t . ' ')) { if (!str_starts_with($l, $t . ' OK')) throw new RuntimeException('IMAP: ' . trim($l)); return; }
        }
        throw new RuntimeException('IMAP: keine Antwort');
    };
    if ($sec === 'tls') { $run('STARTTLS'); if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('IMAP: TLS fehlgeschlagen.'); }
    $q = fn(string $s) => '"' . addcslashes($s, '"\\') . '"';
    $run('LOGIN ' . $q((string)$c['user']) . ' ' . $q((string)$c['pass']));
    $run('APPEND ' . $q((string)($c['folder'] ?? 'Sent')) . ' (\\Seen) {' . strlen($raw) . '}', $raw);
    try { $run('LOGOUT'); } catch (Throwable $x) {}
    fclose($fp);
}
// Rückgabe: null = ok, sonst Fehlertext. driver 'smtp' (optional mit Rückfall auf mail()) oder 'mail'.
function send_mail(string $to, string $subject, string $body): ?string {
    $m = mail_cfg();
    if (!empty($GLOBALS['no_mail'])) return null;                       // z. B. während eines Imports
    if (empty($m['enabled'])) return 'E-Mail-Versand ist in config.php nicht aktiviert.';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'Ungültige Empfängeradresse.';
    $from = (string)($m['from'] ?? ''); $name = str_replace(["\r", "\n"], '', (string)($m['from_name'] ?? ''));
    $note = null;
    if (($m['driver'] ?? 'mail') === 'smtp') {
        try {
            $raw = mail_build($from, $name, $to, $subject, $body);
            smtp_send($m['smtp'] ?? [], $from, $to, $raw);
            if (!empty($m['imap']['enabled'])) {
                try { imap_save_sent($m['imap'], $raw); }
                catch (Throwable $x) { return 'Mail gesendet, aber Ablage per IMAP fehlgeschlagen: ' . $x->getMessage(); }
            }
            return null;
        } catch (Throwable $x) {
            if (empty($m['fallback_mail'])) return $x->getMessage();
            $note = 'SMTP fehlgeschlagen (' . $x->getMessage() . '), Rückfall auf mail()';
            error_log('[anmeldung] ' . $note);
        }
    }
    $h = 'From: ' . $name . ' <' . $from . ">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    if (!@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $h, '-f' . $from)) return ($note ? $note . ' – ' : '') . 'mail() fehlgeschlagen.';
    return $note ? 'Mail wurde per mail() gesendet. Hinweis: ' . $note : null;
}

// ---- Anmeldung offen/geschlossen (Turnier und Durchgänge) -------------------------------------------
// Turnier offen = freigegeben UND Anmeldeschluss (einschließlich dieses Tages, 23:59 Uhr) noch nicht überschritten
function event_is_open(array $ev): bool { return !empty($ev['is_open']) && (empty($ev['deadline']) || $ev['deadline'] >= date('Y-m-d')); }
// Durchgang offen = Turnier offen, nicht manuell geschlossen und ein eigener Anmeldeschluss (nur früher möglich) nicht überschritten
function session_is_open(array $ev, array $s): bool {
    if (!event_is_open($ev) || !empty($s['closed'])) return false;
    return empty($s['deadline']) || $s['deadline'] >= date('Y-m-d');
}

// ---- E-Mail-Texte je Turnier (Platzhalter in {geschweiften Klammern}) ---------------------------------
function mail_vars(array $ev, array $e, array $s, string $ref, string $lang): array {
    $o = owner_of($ev);
    return ['vorname' => (string)$e['firstname'], 'nachname' => (string)$e['lastname'], 'name' => $e['firstname'] . ' ' . $e['lastname'], 'turnier' => (string)$ev['title'],
        'durchgang' => (string)(($s['name'] ?? '') !== '' ? $s['name'] : tl($lang, 'Durchgang') . ' ' . ($s['no'] ?? '')), 'ref' => $ref, 'verwendungszweck' => purpose_text($ev, $e, $ref),
        'zahlungsinfo' => (string)($ev['pay_info'] ?? ''), 'paypal' => paypal_url($ev), 'verein' => (string)($e['club'] ?? ''), 'lizenz' => (string)($e['license'] ?? ''),
        'bogenklasse' => (string)($e['division'] ?? ''), 'altersklasse' => (string)($e['class'] ?? ''), 'anmeldeschluss' => fmt_date($ev['deadline'] ?? ''),
        'organisation' => $o ? trim(($o['fullname'] !== '' ? $o['fullname'] : $o['username']) . ($o['email'] !== '' ? ' <' . $o['email'] . '>' : '')) : ''];
}
// Eigener Text des Turniers (Sprache des Teilnehmers, sonst die andere Sprache); null = Standardtext
function mail_tpl(array $ev, string $kind, string $lang, array $vars): ?array {
    $t = json_decode((string)($ev['mail_tpl'] ?? ''), true);
    if (!is_array($t) || empty($t[$kind])) return null;
    $map = []; foreach ($vars as $k => $v) $map['{' . $k . '}'] = (string)$v;
    foreach ([$lang, $lang === 'en' ? 'de' : 'en'] as $l) {
        $b = trim((string)($t[$kind][$l]['b'] ?? ''));
        if ($b !== '') return [strtr(trim((string)($t[$kind][$l]['s'] ?? '')), $map), strtr($b, $map)];
    }
    return null;
}
// Betreff und Text: eigener Turnier-Text oder Standardtext. $kind: 'reg' = Anmeldung eingegangen, 'ok' = Startplatz bestätigt
function mail_render(array $ev, string $kind, string $lang, array $v): array {
    $custom = mail_tpl($ev, $kind, $lang, $v);
    if ($kind === 'reg') {
        $subj = tl($lang, 'Anmeldung eingegangen: %s', $ev['title']);
        $body = tl($lang, 'Hallo %s,', $v['vorname']) . "\n\n" . tl($lang, 'deine Anmeldung zu „%s“ ist eingegangen. Du stehst auf der Warteliste, bis die Zahlung verbucht ist.', $ev['title']) . "\n\n"
              . tl($lang, 'Verwendungszweck: %s', $v['verwendungszweck']) . "\n\n" . ($v['zahlungsinfo'] !== '' ? $v['zahlungsinfo'] . "\n\n" : '')
              . ($v['paypal'] !== '' ? tl($lang, 'Alternativ per PayPal: %s', $v['paypal']) . "\n\n" : '') . tl($lang, 'Sobald die Zahlung verbucht ist, bekommst du eine Bestätigung.');
    } else {
        $subj = tl($lang, 'Startplatz bestätigt: %s', $ev['title']);
        $body = tl($lang, 'Hallo %s,', $v['vorname']) . "\n\n" . tl($lang, 'deine Zahlung ist verbucht. Du stehst in der Starterliste von „%s“ (%s).', $ev['title'], $v['durchgang'])
              . "\n" . tl($lang, 'Referenz: %s', $v['ref']) . "\n\n" . tl($lang, 'Schöne Grüße') . "\n" . tl($lang, 'das Organisationsteam');
    }
    return [$custom !== null && $custom[0] !== '' ? $custom[0] : $subj, $custom !== null ? $custom[1] : $body];
}

// ---- Starter-/Warteliste ------------------------------------------------
// Starter = bezahlt, in Zahlungsreihenfolge, bis zur Kapazität (0 = unbegrenzt).
// Warteliste = bezahlt über Kapazität (zuerst), danach unbezahlt in Anmeldereihenfolge.
function lists(int $sid): array {
    $cap = (int)q("SELECT capacity FROM reg_sessions WHERE id=?", [$sid])->fetchColumn();
    $paid = q("SELECT * FROM reg_entries WHERE session_id=? AND paid=1 ORDER BY paid_at, id", [$sid])->fetchAll();
    $open = q("SELECT * FROM reg_entries WHERE session_id=? AND paid=0 ORDER BY created, id", [$sid])->fetchAll();
    $starter = $cap > 0 ? array_slice($paid, 0, $cap) : $paid;
    $wait = array_merge($cap > 0 ? array_slice($paid, $cap) : [], $open);
    return [$starter, $wait, $cap];
}

// ---- ianseo-Zugriff -----------------------------------------------------
function ins(string $table, array $row): int {
    static $cols = [];
    if (!isset($cols[$table])) foreach (db()->query("SHOW COLUMNS FROM `$table`")->fetchAll() as $c) $cols[$table][$c['Field']] = $c;
    $row = array_intersect_key($row, $cols[$table]);               // nur Spalten, die es in dieser ianseo-Version gibt
    foreach ($cols[$table] as $f => $c) {                          // NOT NULL ohne Default -> neutral füllen
        if (array_key_exists($f, $row) || $c['Null'] === 'YES' || $c['Default'] !== null || stripos((string)$c['Extra'], 'auto_increment') !== false) continue;
        $t = strtolower($c['Type']);
        $row[$f] = str_contains($t, 'datetime') ? '0000-00-00 00:00:00' : (str_contains($t, 'timestamp') ? date('Y-m-d H:i:s')
                 : (str_contains($t, 'date') ? '0000-00-00' : (preg_match('/int|decimal|float|double/', $t) ? 0 : '')));
    }
    $names = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
    q("INSERT INTO `$table` ($names) VALUES (" . implode(',', array_fill(0, count($row), '?')) . ")", array_values($row));
    return (int)db()->lastInsertId();
}
function ianseo_opts(int $to): array {
    $d = $c = [];
    try {
        foreach (q("SELECT DivId,DivDescription FROM Divisions WHERE DivTournament=? AND DivAthlete=1 ORDER BY DivViewOrder", [$to]) as $r) $d[$r['DivId']] = $r['DivDescription'];
        foreach (q("SELECT ClId,ClDescription FROM Classes WHERE ClTournament=? AND ClAthlete=1 ORDER BY ClViewOrder", [$to]) as $r) $c[$r['ClId']] = $r['ClDescription'];
    } catch (Throwable $x) {}
    return ['div' => $d, 'cls' => $c];
}
function ianseo_country(int $to, string $club, string $code = ''): int {
    $club = trim($club) !== '' ? trim($club) : 'Einzel';
    $co = $code !== '' ? q("SELECT CoId FROM Countries WHERE CoTournament=? AND CoCode=? LIMIT 1", [$to, substr($code, 0, 10)])->fetchColumn() : false;
    if (!$co) {
        $gen = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $club), 0, 10)) ?: 'EINZEL';
        $co = q("SELECT CoId FROM Countries WHERE CoTournament=? AND (CoName=? OR CoNameComplete=? OR CoCode=? OR CoCode=?) ORDER BY CoId LIMIT 1", [$to, $club, $club, $club, $gen])->fetchColumn();
    }
    if (!$co) {
        $c = $code !== '' ? club_by_code($code) : null;                    // ianseo-Schema: "B/BC-Wiesfleck" = Bundesland-Kürzel / Vereinsname
        $full = $c ? ($c['state_code'] !== '' ? $c['state_code'] . '/' : '') . $c['name'] : $club;
        $co = ins('Countries', ['CoTournament' => $to, 'CoCode' => substr($code !== '' ? $code : $gen, 0, 10), 'CoName' => mb_substr($full, 0, 30), 'CoNameComplete' => mb_substr($full, 0, 80)]);
    }
    return (int)$co;
}
// Felder für Anmeldung UND spätere Änderungen (ianseo: EnName = Vorname, EnFirstName = Familienname)
function ianseo_fields(array $e): array {
    $dob = !empty($e['dob']) && $e['dob'] !== '0000-00-00' ? $e['dob'] : '0000-00-00';
    return [
        'EnDivision' => $e['division'], 'EnClass' => $e['class'], 'EnAgeClass' => $e['class'],
        'EnCode' => $e['license'] !== '' ? $e['license'] : 'R' . $e['id'],
        'EnName' => mb_substr($e['firstname'], 0, 30), 'EnFirstName' => mb_substr($e['lastname'], 0, 30),
        'EnOdfShortname' => mb_substr(mb_substr($e['firstname'], 0, 1) . '. ' . strtoupper(strtr($e['lastname'], ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ß' => 'ss'])), 0, 18),
        'EnSex' => (int)$e['sex'], 'EnDob' => $dob,
    ] + (!empty($e['country']) ? ['EnIocCode' => $e['country']] : []);
}
function ianseo_add(int $to, array $e, int $sesNo): int {
    db()->beginTransaction();
    try {
        $id = ins('Entries', ianseo_fields($e) + [
            'EnTournament' => $to, 'EnSubClass' => '', 'EnCountry' => ianseo_country($to, $e['club'], (string)($e['club_code'] ?? '')), 'EnStatus' => 0,
            'EnIndClEvent' => 1, 'EnTeamClEvent' => 0, 'EnIndFEvent' => 1, 'EnTeamFEvent' => 0, 'EnTeamMixEvent' => 0, 'EnAthlete' => 1,
        ]);
        ins('Qualifications', ['QuId' => $id, 'QuSession' => $sesNo]);
        db()->commit();
        return $id;
    } catch (Throwable $x) { db()->rollBack(); throw $x; }
}
function ianseo_update(int $to, array $e): void {
    $f = ianseo_fields($e) + ['EnCountry' => ianseo_country($to, $e['club'], (string)($e['club_code'] ?? ''))];
    q("UPDATE Entries SET " . implode(',', array_map(fn($c) => "`$c`=?", array_keys($f))) . " WHERE EnId=? AND EnTournament=?",
      [...array_values($f), (int)$e['en_id'], $to]);
}
function ianseo_remove(int $to, int $enId): void {
    $score = q("SELECT QuScore FROM Qualifications WHERE QuId=?", [$enId])->fetchColumn();
    if ($score !== false && (int)$score > 0) throw new RuntimeException("hat bereits Ergebnisse in ianseo – nicht gelöscht");
    q("DELETE FROM Qualifications WHERE QuId=?", [$enId]);
    q("DELETE FROM Entries WHERE EnId=? AND EnTournament=?", [$enId, $to]);
}
// Gleicht ianseo mit der aktuellen Starterliste ab: Starter rein (+ Bestätigungsmail), Wartende raus.
function sync_event(int $eid): array {
    $ev = q("SELECT * FROM reg_events WHERE id=?", [$eid])->fetch(); $msg = [];
    foreach (q("SELECT * FROM reg_sessions WHERE event_id=? ORDER BY no", [$eid])->fetchAll() as $s) {
        [$st, $wl] = lists((int)$s['id']);
        foreach ($st as $e) if (!$e['en_id']) {
            try {
                q("UPDATE reg_entries SET en_id=? WHERE id=?", [ianseo_add((int)$ev['to_id'], $e, (int)$s['no']), $e['id']]);
                log_event('ianseo_neu', (int)$ev['id'], (int)$e['id'], $e['lastname'] . ' ' . $e['firstname'] . ', Durchgang ' . $s['no']);
                $lg = $e['lang'] ?: 'de';
                [$ms, $mb] = mail_render($ev, 'ok', $lg, mail_vars($ev, $e, $s, ref_code((int)$ev['id'], (int)$e['id']), $lg));
                send_mail($e['email'], $ms, $mb);
            } catch (Throwable $x) { $msg[] = "{$e['lastname']} {$e['firstname']}: " . $x->getMessage(); }
        }
        foreach ($wl as $e) if ($e['en_id']) {
            try { ianseo_remove((int)$ev['to_id'], (int)$e['en_id']); q("UPDATE reg_entries SET en_id=NULL WHERE id=?", [$e['id']]); log_event('ianseo_entfernt', (int)$ev['id'], (int)$e['id'], $e['lastname'] . ' ' . $e['firstname']); }
            catch (Throwable $x) { $msg[] = "{$e['lastname']} {$e['firstname']}: " . $x->getMessage(); }
        }
    }
    return $msg;
}

// Nächste lizenzfreie Nummer (LL1, LL2, …) je Turnier; atomar über einen Zähler am Turnier
function next_nolic(int $eid): string {
    $max = 0;
    foreach (q("SELECT license FROM reg_entries WHERE event_id=? AND license LIKE 'LL%'", [$eid])->fetchAll() as $r)
        if (preg_match('/^LL(\d+)$/', $r['license'], $m)) $max = max($max, (int)$m[1]);
    q("UPDATE reg_events SET nolic_next = GREATEST(nolic_next, ?) WHERE id=?", [$max + 1, $eid]);
    q("UPDATE reg_events SET nolic_next = LAST_INSERT_ID(nolic_next) + 1 WHERE id=?", [$eid]);
    return 'LL' . (int)q("SELECT LAST_INSERT_ID()")->fetchColumn();
}

// ---- Vereins- und Schützenliste (CSV) ---------------------------------------------
function norm_name(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower(trim($s)), ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a', 'č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'ñ' => 'n']));
}
// Liest CSV (Trennzeichen ; , oder Tab automatisch; UTF-8 oder Windows-1252). $cols: Feld => Liste normalisierter Spaltennamen
function csv_rows(string $path, array $cols, array $required): array {
    $raw = (string)file_get_contents($path);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $first = (string)strtok($raw, "\n"); $delim = ';'; $max = -1;
    foreach ([';', ',', "\t"] as $d) { $c = substr_count($first, $d); if ($c > $max) { $max = $c; $delim = $d; } }
    $h = fopen('php://temp', 'r+'); fwrite($h, $raw); rewind($h);
    $head = fgetcsv($h, 0, $delim, '"', '');
    if (!$head) throw new RuntimeException('Datei ist leer.');
    $idx = [];
    foreach ($cols as $name => $aliases) foreach ($head as $i => $hc) if (in_array(norm_name((string)$hc), $aliases, true)) { $idx[$name] = $i; break; }
    $miss = array_diff($required, array_keys($idx));
    if ($miss) throw new RuntimeException('Spalte(n) nicht gefunden: ' . implode(', ', $miss) . '. Kopfzeile der Datei: ' . implode(' | ', array_map('strval', $head)));
    $rows = [];
    while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
        if ($r === [null]) continue;
        $row = []; foreach ($idx as $name => $i) $row[$name] = trim((string)($r[$i] ?? ''));
        $rows[] = $row;
    }
    fclose($h);
    return $rows;
}
function import_table(string $table, array $fields, array $rows, bool $replace): int {
    set_time_limit(300);
    db()->beginTransaction();
    try {
        if ($replace) db()->exec("DELETE FROM `$table`");
        $st = db()->prepare("REPLACE INTO `$table` (" . implode(',', $fields) . ") VALUES (" . implode(',', array_fill(0, count($fields), '?')) . ")");
        $n = 0; foreach ($rows as $r) { $st->execute(array_map(fn($f) => $r[$f], $fields)); $n++; }
        db()->commit(); return $n;
    } catch (Throwable $x) { db()->rollBack(); throw $x; }
}
// Gemeinsame Stammdaten-CSV: Lizenznummer, Vorname, Nachname, Vereinsnummer, Vereinsname, Bundeslandkürzel, Bundeslandname (+ optional Geschlecht)
// Liefert [Anzahl Schützen, Anzahl Vereine]
function import_master(string $path, bool $replace): array {
    $rows = csv_rows($path, [
        'license' => ['erfnr', 'erfnummer', 'lizenz', 'lizenznr', 'lizenznummer'],
        'firstname' => ['vorname', 'schutzenvorname', 'schuetzenvorname'],
        'lastname' => ['nachname', 'schutzennachname', 'schuetzennachname', 'familienname'],
        'club_code' => ['vereinsnummer', 'vereinsnr', 'vnr'],
        'sex' => ['geschlecht', 'sex', 'gender'],
        'short' => ['vereinskurzel', 'vereinskuerzel', 'vereinsk'],
        'club' => ['vereinsname', 'verein'],
        'state_code' => ['bundeslandkurzel', 'bundeslandkuerzel', 'blkurzl', 'blkuerzl', 'blk'],
        'state' => ['bundeslandname', 'bundesland'],
    ], ['license', 'firstname', 'lastname']);
    $ath = []; $clubs = [];
    foreach ($rows as $r) {
        if ($r['license'] === '' || $r['lastname'] === '') continue;
        $code = mb_substr($r['club_code'] ?? ($r['short'] ?? ''), 0, 20);         // ohne Spalte "Vereinsnummer": Kürzel als Code
        $ath[] = ['license' => mb_substr($r['license'], 0, 25), 'lastname' => mb_substr($r['lastname'], 0, 60), 'firstname' => mb_substr($r['firstname'], 0, 60), 'club_code' => $code, 'sex' => norm_sex($r['sex'] ?? '')];
        if ($code !== '' && ($r['club'] ?? '') !== '' && !isset($clubs[$code]))
            $clubs[$code] = ['code' => $code, 'name' => mb_substr($r['club'], 0, 150), 'short' => mb_substr($r['short'] ?? '', 0, 30), 'state_code' => mb_substr($r['state_code'] ?? '', 0, 10), 'state' => mb_substr($r['state'] ?? '', 0, 60)];
    }
    $na = import_table('reg_athletes', ['license', 'lastname', 'firstname', 'club_code', 'sex'], $ath, $replace);
    $nc = $clubs ? import_table('reg_clubs', ['code', 'name', 'short', 'state_code', 'state'], array_values($clubs), $replace) : 0;
    return [$na, $nc];
}
// ---- Anmeldungen: Import (Format = Export) ----------------------------------------
function parse_date(string $s): ?string {
    $s = trim($s); if ($s === '' || str_starts_with($s, '0000')) return null;
    foreach (['Y-m-d H:i:s', 'Y-m-d', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y'] as $f) {
        $d = DateTime::createFromFormat('!' . $f, $s);
        if ($d && !DateTime::getLastErrors()) return $d->format('Y-m-d H:i:s');
    }
    return null;
}
function ianseo_find(int $to, string $ln, string $fn, string $lic): ?int {          // vorhandenen ianseo-Eintrag finden (kein Duplikat anlegen)
    $cand = null;
    if ($lic !== '') { $by = q("SELECT EnId FROM Entries WHERE EnTournament=? AND EnCode=?", [$to, $lic])->fetchAll(PDO::FETCH_COLUMN); if (count($by) === 1) $cand = (int)$by[0]; }
    if ($cand === null) { $ids = q("SELECT EnId FROM Entries WHERE EnTournament=? AND EnFirstName=? AND EnName=?", [$to, $ln, $fn])->fetchAll(PDO::FETCH_COLUMN); if (count($ids) === 1) $cand = (int)$ids[0]; }
    if ($cand !== null && q("SELECT 1 FROM reg_entries WHERE en_id=?", [$cand])->fetch()) return null;
    return $cand;
}
// Rückgabe: [importiert, übersprungen (Duplikate), Fehlerliste]. Keine Mails; bezahlte Starter werden mit ianseo verknüpft bzw. angelegt.
function import_entries(array $ev, string $path): array {
    $rows = csv_rows($path, [
        'session' => ['durchgang'], 'lastname' => ['nachname'], 'firstname' => ['vorname'], 'sex' => ['geschlecht'], 'dob' => ['geburtsdatum'],
        'club' => ['verein', 'vereinsname'], 'club_code' => ['vereinsnummer'], 'license' => ['lizenz', 'lizenznummer'], 'email' => ['email', 'emailadresse'],
        'country' => ['land', 'ioccode', 'nation'], 'division' => ['bogenklasse', 'division'], 'class' => ['altersklasse', 'klasse'], 'paid' => ['bezahlt'], 'paid_at' => ['bezahltam'],
        'qtype' => ['nachweistyp'], 'q1' => ['turnier1'], 'q2' => ['turnier2'], 'q3' => ['turnier3'], 'qy' => ['omjahr'], 'qe' => ['omturnier'], 'created' => ['angemeldetam'],
    ], ['session', 'lastname', 'firstname', 'division', 'class']);
    $o = ianseo_opts((int)$ev['to_id']); $ses = [];
    foreach (q("SELECT * FROM reg_sessions WHERE event_id=?", [$ev['id']])->fetchAll() as $s) $ses[(int)$s['no']] = $s;
    $ok = 0; $dup = 0; $err = []; $GLOBALS['no_mail'] = true;
    try {
        foreach ($rows as $i => $r) {
            $n = $i + 1; $g = fn(string $k) => trim((string)($r[$k] ?? ''));
            $s = $ses[(int)$g('session')] ?? null;
            if (!$s) { $err[] = "Datensatz $n: Durchgang '{$g('session')}' existiert nicht."; continue; }
            if ($g('lastname') === '' || $g('firstname') === '') { $err[] = "Datensatz $n: Name fehlt."; continue; }
            $sx = mb_strtolower($g('sex')); $sex = in_array($sx, ['m', 'männlich', 'maennlich', '0'], true) ? 0 : (in_array($sx, ['w', 'f', 'weiblich', '1'], true) ? 1 : null);
            if ($sex === null) { $err[] = "Datensatz $n: Geschlecht muss M oder W sein."; continue; }
            if (($o['div'] && !isset($o['div'][$g('division')])) || ($o['cls'] && !isset($o['cls'][$g('class')]))) { $err[] = "Datensatz $n: Bogenklasse '{$g('division')}' oder Altersklasse '{$g('class')}' unbekannt."; continue; }
            if ($s['divisions'] !== '' && !in_array($g('division'), explode(',', $s['divisions']), true)) { $err[] = "Datensatz $n: Bogenklasse im Durchgang {$g('session')} nicht zugelassen."; continue; }
            if ($g('email') !== '' && !filter_var($g('email'), FILTER_VALIDATE_EMAIL)) { $err[] = "Datensatz $n: E-Mail ungültig."; continue; }
            $dob = $g('dob') !== '' ? parse_date($g('dob')) : null;
            if ($g('dob') !== '' && !$dob) { $err[] = "Datensatz $n: Geburtsdatum ungültig."; continue; }
            $dob = $dob ? substr($dob, 0, 10) : null;
            $club = $g('club'); $cc = $g('club_code');
            if ($cc !== '' && ($c = club_by_code($cc))) { if ($club === '') $club = $c['name']; } elseif ($cc === '') $cc = club_code_by_name($club);
            if (q("SELECT 1 FROM reg_entries WHERE event_id=? AND session_id=? AND lower(firstname)=lower(?) AND lower(lastname)=lower(?) AND dob <=> ?", [$ev['id'], $s['id'], $g('firstname'), $g('lastname'), $dob])->fetch()) { $dup++; continue; }
            $paid = in_array(mb_strtolower($g('paid')), ['ja', 'j', '1', 'x', 'yes', 'true'], true) ? 1 : 0;
            $paidAt = $paid ? (parse_date($g('paid_at')) ?? date('Y-m-d H:i:s')) : null;
            $qt = $ev['kind'] === 'oem' && in_array((int)$g('qtype'), [1, 2], true) ? (int)$g('qtype') : null;
            $lic = $g('license'); $ctry = strtoupper($g('country')); if ($ctry === 'AUT' || !preg_match('/^[A-Z]{3}$/', $ctry)) $ctry = '';
            $lc = lic_status($lic, $g('lastname'), $g('firstname')); if ($ctry !== '' && $lc !== 'frei') $lc = 'ausland';
            q("INSERT INTO reg_entries (event_id,session_id,firstname,lastname,sex,dob,club,license,email,division,class,paid,paid_at,qtype,q1,q2,q3,qy,qe,club_code,lic_check,created,country) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [$ev['id'], $s['id'], $g('firstname'), $g('lastname'), $sex, $dob, $club, $lic, $g('email'), $g('division'), $g('class'), $paid, $paidAt, $qt, $g('q1'), $g('q2'), $g('q3'), $g('qy'), $g('qe'),
               $cc, $lc, parse_date($g('created')) ?? date('Y-m-d H:i:s'), $ctry]);
            $newId = (int)db()->lastInsertId();
            if ($paid && ($en = ianseo_find((int)$ev['to_id'], $g('lastname'), $g('firstname'), $lic))) q("UPDATE reg_entries SET en_id=? WHERE id=?", [$en, $newId]);
            $ok++;
        }
        $err = array_merge($err, array_map(fn($m) => 'ianseo: ' . $m, sync_event((int)$ev['id'])));
    } finally { unset($GLOBALS['no_mail']); }
    return [$ok, $dup, $err];
}
function club_by_code(string $c): ?array { return $c === '' ? null : (q("SELECT * FROM reg_clubs WHERE code=?", [$c])->fetch() ?: null); }
function club_code_by_name(string $n): string { return $n === '' ? '' : (string)q("SELECT code FROM reg_clubs WHERE name=? LIMIT 1", [$n])->fetchColumn(); }
function athletes_loaded(): bool { static $v = null; return $v ??= (bool)q("SELECT 1 FROM reg_athletes LIMIT 1")->fetch(); }
function athlete_by_license(string $lic): ?array { return q("SELECT * FROM reg_athletes WHERE license=?", [$lic])->fetch() ?: null; }
function athletes_by_name(string $ln, string $fn): array { return q("SELECT * FROM reg_athletes WHERE lastname=? AND firstname=?", [$ln, $fn])->fetchAll(); }
function same_person(array $a, string $ln, string $fn): bool { return norm_name($a['lastname']) === norm_name($ln) && norm_name($a['firstname']) === norm_name($fn); }
// Status der Lizenznummer: ok | unbekannt | abweichend | '' (keine Liste / keine Nummer)
function lic_status(string $lic, string $ln, string $fn): string {
    if (preg_match('/^LL\d+$/', $lic)) return 'frei';                      // automatisch vergebene Nummer (lizenzfrei)
    if ($lic === '' || !athletes_loaded()) return '';
    $a = athlete_by_license($lic);
    return !$a ? 'unbekannt' : (same_person($a, $ln, $fn) ? 'ok' : 'abweichend');
}
// ---- Externe Lizenz-Schnittstelle (API, OAuth2 client_credentials) – optional, in den Einstellungen aktivierbar --------
function api_cfg(): array {
    $d = ['enabled' => false, 'base' => 'https://www.archeryaustria.net/api/v2', 'token_url' => '', 'auth' => 'form', 'client_id' => '', 'secret' => '',
          'path' => '/ext/licence/check', 'method' => 'GET', 'param' => 'licence', 'found_key' => 'valid', 'first_key' => 'firstname', 'last_key' => 'lastname',
          'club_code_key' => 'club_id', 'club_name_key' => 'club_name', 'sex_key' => 'gender', 'check_club' => false];
    $j = setting('api_config'); $c = $j !== '' ? (json_decode($j, true) ?: []) : [];
    $c = array_merge($d, $c); $c['secret'] = dec((string)$c['secret']);
    return $c;
}
function api_enabled(): bool { $c = api_cfg(); return !empty($c['enabled']) && str_starts_with(strtolower($c['base']), 'https://') && $c['client_id'] !== '' && $c['secret'] !== ''; }
function athletes_available(): bool { return athletes_loaded() || api_enabled() || sql_enabled(); }
// Geschlecht normalisieren: 0 = männlich, 1 = weiblich, null = unbekannt (Ziffern werden bewusst nicht gedeutet)
function norm_sex($v): ?int {
    $v = mb_strtolower(trim((string)$v));
    if (in_array($v, ['m', 'männlich', 'maennlich', 'male', 'mann', 'herr', 'herren', 'h'], true)) return 0;
    if (in_array($v, ['w', 'weiblich', 'female', 'f', 'frau', 'dame', 'damen'], true)) return 1;
    return null;
}
function truthy($v): bool { return in_array(strtolower(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v), ['1', 'true', 'yes', 'ja', 'j', 'y', 'valid', 'active', 'aktiv', 'gültig'], true); }

// ---- Externe SQL-Datenbank (nur Lesezugriff) – optional, in den Einstellungen aktivierbar ----------------------------
function sql_cfg(): array {
    $d = ['enabled' => false, 'driver' => 'mysql', 'host' => '', 'port' => '', 'db' => '', 'user' => '', 'pass' => '', 'table' => '', 'license_col' => '', 'first_col' => '', 'last_col' => '',
          'club_code_col' => '', 'club_name_col' => '', 'sex_col' => '', 'valid_col' => '', 'check_club' => false];
    $j = setting('sql_config'); $c = $j !== '' ? (json_decode($j, true) ?: []) : [];
    $c = array_merge($d, $c); $c['pass'] = dec((string)$c['pass']);
    return $c;
}
function sql_drivers(): array { return array_values(array_intersect(['mysql', 'pgsql', 'sqlsrv'], PDO::getAvailableDrivers())); }
function sql_ident_ok(string $s): bool { return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $s); }
function sql_enabled(): bool {
    $c = sql_cfg();
    return !empty($c['enabled']) && in_array($c['driver'], sql_drivers(), true) && $c['host'] !== '' && $c['db'] !== '' && $c['user'] !== '' && sql_ident_ok($c['table']) && sql_ident_ok($c['license_col']);
}
function sql_qid(string $drv, string $id): string {
    [$l, $r] = $drv === 'mysql' ? ['`', '`'] : ($drv === 'sqlsrv' ? ['[', ']'] : ['"', '"']);
    return implode('.', array_map(fn($p) => $l . $p . $r, explode('.', $id)));
}
function sql_pdo(array $c): PDO {
    static $pdo = null; if ($pdo) return $pdo;
    $h = $c['host']; $p = $c['port'] !== '' ? (int)$c['port'] : 0;
    $dsn = match ($c['driver']) {
        'pgsql' => "pgsql:host=$h;" . ($p ? "port=$p;" : '') . "dbname={$c['db']}",
        'sqlsrv' => "sqlsrv:Server=$h" . ($p ? ",$p" : '') . ";Database={$c['db']}",
        default => "mysql:host=$h;" . ($p ? "port=$p;" : '') . "dbname={$c['db']};charset=utf8mb4",
    };
    return $pdo = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
// Schütze zur Lizenznummer in der externen Datenbank suchen (nur SELECT mit gebundenem Parameter; Tabellen-/Spaltennamen werden geprüft)
function sql_licence_lookup(string $lic): array {
    $c = sql_cfg();
    try {
        $drv = $c['driver']; $pdo = sql_pdo($c);
        $col = fn(string $k, string $alias) => $c[$k] !== '' && sql_ident_ok($c[$k]) ? sql_qid($drv, $c[$k]) . ' AS ' . $alias : 'NULL AS ' . $alias;
        $sel = implode(', ', [sql_qid($drv, $c['license_col']) . ' AS c_lic', $col('first_col', 'c_fn'), $col('last_col', 'c_ln'), $col('club_code_col', 'c_cc'), $col('club_name_col', 'c_cn'), $col('sex_col', 'c_sx'), $col('valid_col', 'c_vd')]);
        $st = $pdo->prepare('SELECT ' . ($drv === 'sqlsrv' ? 'TOP 1 ' : '') . $sel . ' FROM ' . sql_qid($drv, $c['table']) . ' WHERE ' . sql_qid($drv, $c['license_col']) . ' = ?' . ($drv === 'sqlsrv' ? '' : ' LIMIT 1'));
        $st->execute([$lic]); $r = $st->fetch();
        if (!$r) return ['found' => false, 'data' => null, 'error' => ''];
        $d = ['first' => (string)$r['c_fn'], 'last' => (string)$r['c_ln'], 'club_code' => (string)$r['c_cc'], 'club' => (string)$r['c_cn'], 'sex' => norm_sex($r['c_sx']), 'found' => $r['c_vd'] !== null ? truthy($r['c_vd']) : true];
        return ['found' => $d['found'], 'data' => $d, 'error' => ''];
    } catch (Throwable $x) { error_log('[anmeldung] SQL-Abfrage: ' . $x->getMessage()); return ['found' => false, 'data' => null, 'error' => $x->getMessage()]; }
}
function api_http(string $method, string $url, array $headers = [], ?string $body = null): array {
    if (!preg_match('~^https://~i', $url)) return ['status' => 0, 'body' => '', 'error' => 'Nur https:// ist erlaubt.'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $er = curl_error($ch); curl_close($ch);
        return ['status' => $st, 'body' => $out === false ? '' : (string)$out, 'error' => $out === false ? $er : ''];
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0],
                                  'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $out = @file_get_contents($url, false, $ctx); $st = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) $st = (int)$m[1];
    return ['status' => $st, 'body' => $out === false ? '' : $out, 'error' => $out === false ? 'Verbindung fehlgeschlagen' : ''];
}
// Zugriffs-Token (gilt ca. 15 Minuten) holen und – verschlüsselt – zwischenspeichern
function api_token(bool $force = false): array {
    $c = api_cfg();
    if (!$force && ($t = dec(setting('api_token'))) !== '' && (int)setting('api_token_exp') > time() + 30) return ['token' => $t, 'error' => ''];
    $url = $c['token_url'] !== '' ? $c['token_url'] : rtrim($c['base'], '/') . '/oauth/token';
    $h = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']; $body = 'grant_type=client_credentials';
    if ($c['auth'] === 'basic') $h[] = 'Authorization: Basic ' . base64_encode($c['client_id'] . ':' . $c['secret']);
    else $body .= '&client_id=' . rawurlencode($c['client_id']) . '&client_secret=' . rawurlencode($c['secret']);
    $r = api_http('POST', $url, $h, $body); $j = json_decode($r['body'], true);
    if ($r['status'] !== 200 || !is_array($j) || empty($j['access_token'])) return ['token' => null, 'error' => 'Token: HTTP ' . $r['status'] . ' ' . ($r['error'] ?: mb_substr(trim(strip_tags($r['body'])), 0, 150))];
    set_setting('api_token', enc((string)$j['access_token'])); set_setting('api_token_exp', (string)(time() + (int)($j['expires_in'] ?? 900)));
    return ['token' => (string)$j['access_token'], 'error' => ''];
}
// Abfrage mit Bearer-Token; hält die Grenzen der Schnittstelle ein (max. 5/Sekunde, 100/Minute) und erneuert abgelaufene Tokens
function api_call(string $path, array $query = []): array {
    $c = api_cfg();
    if (!rate_limit('api_s', 4, 1, false) || !rate_limit('api_m', 80, 60, false)) return ['status' => 429, 'json' => null, 'raw' => '', 'error' => 'Abfrage-Limit der Schnittstelle erreicht'];
    for ($try = 0; $try < 2; $try++) {
        $t = api_token($try === 1);
        if (!$t['token']) return ['status' => 0, 'json' => null, 'raw' => '', 'error' => $t['error']];
        $method = $c['method'] === 'POST' ? 'POST' : 'GET'; $url = rtrim($c['base'], '/') . '/' . ltrim($path, '/');
        $h = ['Accept: application/json', 'Authorization: Bearer ' . $t['token']]; $body = null;
        if ($method === 'GET' && $query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        if ($method === 'POST') { $h[] = 'Content-Type: application/x-www-form-urlencoded'; $body = http_build_query($query); }
        $r = api_http($method, $url, $h, $body);
        if ($r['status'] === 401 && $try === 0) continue;
        return ['status' => $r['status'], 'json' => json_decode($r['body'], true), 'raw' => $r['body'], 'error' => $r['error']];
    }
    return ['status' => 0, 'json' => null, 'raw' => '', 'error' => 'Anmeldung an der Schnittstelle fehlgeschlagen'];
}
function dot_get($a, string $path) { if ($path === '') return null; foreach (explode('.', $path) as $k) { if (!is_array($a) || !array_key_exists($k, $a)) return null; $a = $a[$k]; } return $a; }
// Antwort der Schnittstelle auf Name/Verein abbilden (Feldnamen in den Einstellungen, Punktschreibweise z. B. data.firstname)
function api_map($j, array $c): array {
    if (is_array($j) && isset($j[0]) && is_array($j[0])) $j = $j[0];
    $g = fn(string $k) => (string)(is_scalar($v = dot_get($j, $k)) ? $v : '');
    $d = ['first' => $g($c['first_key']), 'last' => $g($c['last_key']), 'club_code' => $g($c['club_code_key']), 'club' => $g($c['club_name_key']), 'sex' => norm_sex($g((string)($c['sex_key'] ?? '')))];
    $flag = dot_get($j, (string)$c['found_key']);
    $any = $d['first'] !== '' || $d['last'] !== '' || $d['club_code'] !== '' || $d['club'] !== '';
    $d['found'] = $flag !== null && is_scalar($flag) ? truthy($flag) : $any;
    return $d;
}
// Lizenz bei der Schnittstelle nachschlagen (24 Stunden zwischengespeichert). Bei Fehlern: nicht gefunden (die Anmeldung bleibt möglich).
function api_licence_lookup(string $lic): array {
    $c = api_cfg(); $k = sha1(mb_strtolower($lic));
    $row = q("SELECT data FROM reg_api_cache WHERE k=? AND ts > ?", [$k, time() - 86400])->fetchColumn();
    if ($row !== false && is_array($d = json_decode((string)$row, true))) return ['found' => !empty($d['found']), 'data' => $d, 'error' => ''];
    $r = api_call($c['path'], [$c['param'] => $lic]);
    if ($r['status'] === 404) $d = ['found' => false, 'first' => '', 'last' => '', 'club_code' => '', 'club' => '', 'sex' => null];
    elseif ($r['status'] < 200 || $r['status'] >= 300 || !is_array($r['json'])) return ['found' => false, 'data' => null, 'error' => $r['error'] ?: 'HTTP ' . $r['status']];
    else $d = api_map($r['json'], $c);
    q("DELETE FROM reg_api_cache WHERE ts < ?", [time() - 86400]);
    q("REPLACE INTO reg_api_cache (k,data,ts) VALUES (?,?,?)", [$k, json_encode($d, JSON_UNESCAPED_UNICODE), time()]);
    return ['found' => !empty($d['found']), 'data' => $d, 'error' => ''];
}
// Schütze zu einer Lizenznummer: zuerst die lokale Stammdaten-Liste, dann (falls aktiv) die externe SQL-Datenbank, dann die API
function athlete_find(string $lic, bool $ext = true): ?array {
    $lic = trim($lic); if ($lic === '') return null;
    if ($a = athlete_by_license($lic)) { $a['sex'] = ($a['sex'] ?? null) === null ? null : (int)$a['sex']; return $a + ['src' => 'local', 'club_name' => '']; }
    if (!$ext) return null;
    foreach (['sql' => sql_enabled(), 'api' => api_enabled()] as $src => $on) {
        if (!$on) continue;
        $r = $src === 'sql' ? sql_licence_lookup($lic) : api_licence_lookup($lic);
        if (!$r['found'] || !$r['data']) continue;
        $d = $r['data'];
        return ['license' => $lic, 'lastname' => $d['last'], 'firstname' => $d['first'], 'club_code' => $d['club_code'], 'club_name' => $d['club'], 'sex' => $d['sex'] ?? null, 'src' => $src];
    }
    return null;
}

// Vereinsvergleich: Groß-/Kleinschreibung, Akzente und Leerzeichen werden ignoriert
function club_matches(array $a, array $f): bool {
    if (in_array($a['src'] ?? '', ['api', 'sql'], true) && empty(($a['src'] === 'sql' ? sql_cfg() : api_cfg())['check_club'])) return true;     // Vereinscodes externer Quellen können abweichen
    if ($a['club_code'] === '') {
        $nm = (string)($a['club_name'] ?? ''); if ($nm === '') return true;
        $given = $f['club_code'] !== '' ? (club_by_code($f['club_code'])['name'] ?? $f['club']) : $f['club'];
        return trim((string)$given) === '' || norm_name((string)$given) === norm_name($nm);
    }
    if ($f['club_code'] !== '') return mb_strtolower(trim($f['club_code'])) === mb_strtolower(trim($a['club_code']));
    if (trim($f['club']) === '') return true;                                   // wird aus der Schützenliste ergänzt
    $c = club_by_code($a['club_code']);
    return !$c || norm_name($f['club']) === norm_name($c['name']);
}
// Bei der Anmeldung: Lizenz (Name UND Verein) prüfen bzw. aus der Schützenliste ergänzen. Rückgabe: Fehlertext oder null.
function athlete_check(array &$f, bool $api = true): ?string {
    $f['lic_check'] = '';
    if (!($api ? athletes_available() : athletes_loaded())) return null;
    $a = null;
    if ($f['license'] !== '') {
        $a = athlete_find($f['license'], $api);
        if (!$a) { $f['lic_check'] = 'unbekannt'; return null; }
        if ($a['lastname'] !== '' && !same_person($a, $f['lastname'], $f['firstname'])) return t('Die Lizenznummer gehört zu einer anderen Person. Bitte Lizenznummer sowie Vor- und Nachname prüfen.');
        $f['lic_check'] = 'ok';
        if (($a['sex'] ?? null) !== null && isset($f['sex']) && (int)$f['sex'] !== (int)$a['sex']) { $f['lic_check'] = 'geschlecht'; return t('Das Geschlecht passt nicht zur Lizenznummer. Bitte die Angabe prüfen.'); }
        if (!club_matches($a, $f)) { $f['lic_check'] = 'verein'; return t('Der Verein passt nicht zur Lizenznummer. Bitte den Verein prüfen.'); }
    } else {
        if (!athletes_loaded()) return null;
        $m = athletes_by_name($f['lastname'], $f['firstname']);
        if (isset($f['sex'])) $m = array_values(array_filter($m, fn($x) => ($x['sex'] ?? null) === null || (int)$x['sex'] === (int)$f['sex']));
        if (count($m) !== 1) return null;
        if ($f['club_code'] !== '' && $m[0]['club_code'] !== '' && !club_matches($m[0], $f)) return null;
        $a = $m[0]; $f['license'] = $a['license']; $f['lic_check'] = 'auto';
    }
    if ($f['club'] === '' && $f['club_code'] === '' && $a['club_code'] !== '' && ($c = club_by_code($a['club_code']))) { $f['club_code'] = $c['code']; $f['club'] = $c['name']; }
    return null;
}
// Für das automatische Ausfüllen in der Anmeldemaske (nur Name und Verein)
function athlete_lookup(string $lic): ?array {
    $a = athlete_find($lic); if (!$a) return null;
    $c = club_by_code((string)$a['club_code']);
    return ['first' => $a['firstname'], 'last' => $a['lastname'], 'sex' => $a['sex'] ?? null, 'club_code' => $c ? $a['club_code'] : '', 'club' => $c['name'] ?? (string)($a['club_name'] ?? '')];
}
// Backend: Schützenabgleich für alle Anmeldungen eines Turniers (nur wenn eine Schützenliste eingespielt ist)
function athlete_sync_event(int $eid): string {
    if (!athletes_loaded()) return 'Keine Schützenliste eingespielt.';
    $ev = q("SELECT * FROM reg_events WHERE id=?", [$eid])->fetch(); $cnt = ['ok' => 0, 'auto' => 0, 'unbekannt' => 0, 'abweichend' => 0, 'verein' => 0, 'geschlecht' => 0, 'frei' => 0, '' => 0];
    foreach (q("SELECT * FROM reg_entries WHERE event_id=?", [$eid])->fetchAll() as $r) {
        if (in_array($r['lic_check'], ['frei', 'ausland'], true)) { $cnt['frei']++; continue; }                    // lizenzfreie Teilnehmer nicht anfassen
        $f = ['license' => $r['license'], 'lastname' => $r['lastname'], 'firstname' => $r['firstname'], 'club' => $r['club'], 'club_code' => $r['club_code'], 'sex' => (int)$r['sex']];
        $a = $f['license'] !== '' ? athlete_by_license($f['license']) : null;
        if ($f['license'] !== '' && $a && !same_person($a, $f['lastname'], $f['firstname'])) $f['lic_check'] = 'abweichend';
        else athlete_check($f, false);
        $cnt[$f['lic_check']]++;
        if ($f['lic_check'] !== $r['lic_check'] || $f['license'] !== $r['license'] || $f['club_code'] !== $r['club_code']) {
            q("UPDATE reg_entries SET license=?,club=?,club_code=?,lic_check=? WHERE id=?", [$f['license'], $f['club'], $f['club_code'], $f['lic_check'], $r['id']]);
            if ($r['en_id'] && $ev) { try { ianseo_update((int)$ev['to_id'], q("SELECT * FROM reg_entries WHERE id=?", [$r['id']])->fetch()); } catch (Throwable $x) {} }
        }
    }
    return "Abgleich fertig: {$cnt['ok']} geprüft, {$cnt['auto']} Lizenz automatisch zugeordnet, {$cnt['unbekannt']} Lizenz nicht in Liste, {$cnt['abweichend']} Name abweicht, {$cnt['verein']} Verein abweicht, {$cnt['geschlecht']} Geschlecht abweicht, {$cnt['frei']} lizenzfrei/Ausland.";
}
function lic_tag(string $s): string {
    $m = ['ok' => ['paid', 'geprüft'], 'auto' => ['', 'automatisch zugeordnet'], 'unbekannt' => ['open', 'nicht in Schützenliste'], 'abweichend' => ['open', 'Name weicht ab'], 'verein' => ['open', 'Verein weicht ab'], 'geschlecht' => ['open', 'Geschlecht weicht ab'], 'frei' => ['', 'lizenzfrei'], 'ausland' => ['', 'Ausland']];
    return isset($m[$s]) ? ' <span class="tag ' . $m[$s][0] . '">' . $m[$s][1] . '</span>' : '';
}

if (!defined('INSTALLING')) ensure_schema();
