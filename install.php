<?php
/* Turnier-Anmeldeplattform für ianseo – Copyright (C) 2026 Peter Führer – GNU AGPLv3 (OSI-approved), siehe LICENSE – Version 2026.11.08-a */
define('INSTALLING', true);
require __DIR__ . '/lib.php';

function mk_pdo(array $d): PDO {
    return new PDO("mysql:host={$d['host']};" . ($d['port'] !== '' ? "port={$d['port']};" : '') . "dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 6]);
}
function write_config(array $db, string $title): bool {
    global $CFG;
    $cfg = ['site_title' => $title, 'debug' => false, 'secret' => $CFG['secret'] ?? bin2hex(random_bytes(16)), 'db' => $db, 'mail' => $CFG['mail'] ?? []];
    $par = dirname(__DIR__) . '/anmeldung-config-' . substr(md5(__DIR__), 0, 8) . '.php';       // bevorzugt eine Ebene über dem Plattform-Ordner
    foreach ([is_writable(dirname(__DIR__)) ? $par : '', __DIR__ . '/config.php'] as $target) {
        if ($target === '') continue;
        if (@file_put_contents($target, "<?php\n// Von install.php erzeugt (enthält Zugangsdaten – nicht weitergeben). Mailserver und weitere Einstellungen: Backend -> Einstellungen.\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX) !== false) {
            @chmod($target, 0640);
            if ($target === $par && is_file(__DIR__ . '/config.php')) @unlink(__DIR__ . '/config.php');                // alte, weniger geschützte Datei entfernen
            return true;
        }
    }
    return false;
}
function create_tables(): void {
    foreach ([
        "CREATE TABLE IF NOT EXISTS reg_users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE, pw VARCHAR(255) NOT NULL, created DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS reg_events (id INT AUTO_INCREMENT PRIMARY KEY, to_id INT NOT NULL UNIQUE, title VARCHAR(150) NOT NULL, info TEXT, pay_info TEXT, is_open TINYINT NOT NULL DEFAULT 0, published TINYINT NOT NULL DEFAULT 0, deadline DATE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS reg_sessions (id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, no TINYINT NOT NULL, name VARCHAR(100) NOT NULL DEFAULT '', capacity INT NOT NULL DEFAULT 0, UNIQUE KEY ev_no (event_id,no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS reg_entries (id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, session_id INT NOT NULL, firstname VARCHAR(60) NOT NULL, lastname VARCHAR(60) NOT NULL, sex TINYINT NOT NULL, dob DATE NULL, club VARCHAR(100) NOT NULL DEFAULT '', license VARCHAR(25) NOT NULL DEFAULT '', email VARCHAR(150) NOT NULL, division VARCHAR(10) NOT NULL, class VARCHAR(10) NOT NULL, paid TINYINT NOT NULL DEFAULT 0, paid_at DATETIME NULL, en_id INT NULL, created DATETIME DEFAULT CURRENT_TIMESTAMP, KEY ses (session_id), KEY ev (event_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $sql) db()->exec($sql);
    unset($_SESSION['schema_v']); ensure_schema();
}

// ---- Zustand ermitteln ---------------------------------------------------------
$cfgExists = config_file() !== null; $dbOk = false; $hasUsers = false;
if ($cfgExists) {
    try { db(); $dbOk = true; $hasUsers = (int)q("SELECT COUNT(*) FROM reg_users")->fetchColumn() > 0; } catch (Throwable $x) { $hasUsers = false; }
}
$step = (int)($_GET['step'] ?? 1);
$licOk = !empty($_SESSION['inst_lic']);
if ($hasUsers) $step = 4; elseif (!$licOk) $step = 1; elseif (!$dbOk) $step = min($step, 2); elseif ($step < 3) $step = max($step, 1);

// ---- Aktionen ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $a = (string)($_POST['a'] ?? '');
    if ($a === 'license') {
        if (!empty($_POST['agree'])) { $_SESSION['inst_lic'] = date('Y-m-d H:i:s'); go('install.php?step=2'); }
        flash(t('Bitte zuerst den Lizenzbedingungen zustimmen.'), 'err'); go('install.php');
    }
    if (!$licOk && !$hasUsers) { flash(t('Bitte zuerst den Lizenzbedingungen zustimmen.'), 'err'); go('install.php'); }
    if ($a === 'db' && !$hasUsers) {
        $d = ['host' => trim((string)$_POST['host']), 'port' => trim((string)($_POST['port'] ?? '')), 'name' => trim((string)$_POST['name']), 'user' => trim((string)$_POST['user']), 'pass' => (string)$_POST['pass']];
        if (($_POST['pass'] ?? '') === '' && $dbOk) $d['pass'] = $CFG['db']['pass'];
        $_SESSION['inst_db'] = array_diff_key($d, ['pass' => 1]);
        try {
            $pdo = mk_pdo($d);
            $n = null; try { $n = (int)$pdo->query("SELECT COUNT(*) FROM Tournament")->fetchColumn(); } catch (Throwable $x) {}
            flash(t('Verbindung erfolgreich.') . ' ' . ($n === null ? t('Keine ianseo-Tabellen gefunden – bitte prüfen, ob dies die ianseo-Datenbank ist.') : t('ianseo-Tabellen gefunden (%d Turniere).', $n)), $n === null ? 'err' : 'ok');
            if (write_config($d, $CFG['site_title'] ?? 'Turnier-Anmeldung')) go('install.php?step=3');
            $_SESSION['inst_cfg'] = "<?php\nreturn " . var_export(['site_title' => 'Turnier-Anmeldung', 'debug' => false, 'secret' => bin2hex(random_bytes(16)), 'db' => $d, 'mail' => []], true) . ";\n";
            go('install.php?step=2&manual=1');
        } catch (Throwable $x) { flash(t('Verbindung fehlgeschlagen: %s', $x->getMessage()), 'err'); go('install.php?step=2'); }
    }
    if ($a === 'admin' && $dbOk && !$hasUsers) {
        $u = trim((string)$_POST['u']); $n = trim((string)$_POST['n']); $m = trim((string)$_POST['m']); $p = (string)$_POST['p']; $ti = trim((string)$_POST['title']);
        if ($u === '' || $n === '' || strlen($p) < 6 || $p !== (string)$_POST['p2'] || !filter_var($m, FILTER_VALIDATE_EMAIL)) { flash(t('Bitte alle Felder ausfüllen (Passwort min. 6 Zeichen und identisch, gültige E-Mail).'), 'err'); go('install.php?step=3'); }
        try {
            create_tables();
            q("INSERT INTO reg_users (username,pw,role,fullname,email) VALUES (?,?,'admin',?,?)", [$u, password_hash($p, PASSWORD_DEFAULT), $n, $m]);
            if ($ti !== '') set_setting('site_title', $ti);
            set_setting('agpl_accepted', (string)($_SESSION['inst_lic'] ?? date('Y-m-d H:i:s')));
            go('install.php?step=4&ok=1');
        } catch (Throwable $x) { flash($x->getMessage(), 'err'); go('install.php?step=3'); }
    }
    if ($a === 'delete' && $hasUsers) { @unlink(__FILE__); go('admin.php'); }
    go('install.php');
}

// ---- Anzeige --------------------------------------------------------------------------
page_head(t('Installation'));
$labels = [t('Prüfung'), t('Datenbank'), t('Administrator'), t('Fertig')];
echo '<div class="wiz"><ol class="steps">';
foreach ($labels as $i => $l) echo '<li class="' . ($i + 1 < $step ? 'done' : ($i + 1 === $step ? 'on' : '')) . '">' . ($i + 1 < $step ? '✓ ' : ($i + 1) . '. ') . e($l) . '</li>';
echo '</ol>';

if ($step === 1) {
    $chk = [
        [t('PHP 8.1 oder neuer') . ' (' . PHP_VERSION . ')', PHP_VERSION_ID >= 80100, true],
        [t('Erweiterung %s', 'pdo_mysql'), extension_loaded('pdo_mysql'), true],
        [t('Erweiterung %s', 'mbstring'), extension_loaded('mbstring'), true],
        [t('Erweiterung %s', 'fileinfo'), extension_loaded('fileinfo'), true],
        [t('Erweiterung %s', 'openssl') . ' (' . t('optional') . ': ' . t('verschlüsselte Mail-Passwörter') . ')', extension_loaded('openssl'), false],
        [t('Erweiterung %s', 'dom') . ' (' . t('optional') . ': ' . t('HTML im Impressum') . ')', extension_loaded('dom'), false],
        [t('Erweiterung %s', 'zip') . ' (' . t('optional') . ': ' . t('Quellcode-Download') . ')', extension_loaded('zip'), false],
        [t('Ordner beschreibbar: %s', '/ (config.php)') . ' (' . t('optional') . ': ' . t('sonst manuell speichern') . ')', is_writable(__DIR__), false],
        [t('Ordner beschreibbar: %s', 'uploads/'), is_dir(__DIR__ . '/uploads') && is_writable(__DIR__ . '/uploads'), true],
    ];
    $block = false;
    echo '<div class="box"><h2>' . e(t('Willkommen')) . '</h2><p>' . e(t('Dieser Assistent verbindet die Plattform mit der ianseo-Datenbank und legt den ersten Administrator an.')) . '</p><ul class="chkl">';
    foreach ($chk as [$l, $ok, $req]) { if (!$ok && $req) $block = true; echo '<li><span class="' . ($ok ? 'y' : ($req ? 'n' : 'w')) . '">' . ($ok ? '✓' : ($req ? '✗' : '!')) . '</span><span>' . e($l) . '</span></li>'; }
    echo '</ul>' . ($block ? '<p class="msg err">' . e(t('Bitte zuerst die rot markierten Punkte beheben.')) . '</p>' : '') . '</div>';
    if (!$block) {
        $lt = is_file(__DIR__ . '/LICENSE') ? (string)file_get_contents(__DIR__ . '/LICENSE') : '';
        echo '<div class="box"><h2>' . e(t('Lizenzbedingungen')) . '</h2><p>' . e(t('Diese Software steht unter der GNU Affero General Public License v3 (AGPLv3), einer von der Open Source Initiative (OSI) anerkannten Open-Source-Lizenz. Bitte die Lizenz lesen und zustimmen, bevor die Installation fortgesetzt wird.')) . '</p>'
           . ($lt !== '' ? '<div class="licbox" tabindex="0"><pre>' . e($lt) . '</pre></div>' : '') . '<p><a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener">' . e(t('Lizenztext online')) . '</a></p>'
           . ($licOk ? '<p class="msg ok">' . e(t('Zustimmung zur Lizenz erteilt.')) . '</p><a class="btn" href="install.php?step=2">' . e(t('Weiter')) . '</a>'
                     : '<form method="post">' . tf() . '<input type="hidden" name="a" value="license"><label class="chk"><input type="checkbox" name="agree" value="1" required> <span>' . e(t('Ich habe die GNU Affero General Public License v3 gelesen und stimme den Lizenzbedingungen zu.')) . '</span></label><p><button>' . e(t('Zustimmen und weiter')) . '</button></p></form>')
           . '</div>';
    }
} elseif ($step === 2) {
    $d = ($CFG['db']['name'] ?? '') !== '' ? $CFG['db'] : ($_SESSION['inst_db'] ?? ['host' => 'localhost', 'port' => '', 'name' => '', 'user' => '']);
    $v = fn($k) => e($d[$k] ?? '');
    echo '<div class="box"><h2>' . e(t('Datenbank-Verbindung')) . '</h2><p class="info">' . e(t('Zugangsdaten der ianseo-Datenbank (bei World4You im Kundenbereich unter „Datenbanken“; bei ianseo in Common/config.inc.php).')) . '</p>'
       . '<form method="post" class="grid">' . tf() . '<input type="hidden" name="a" value="db">'
       . '<label>' . e(t('Server (Host)')) . '<input name="host" value="' . $v('host') . '" placeholder="localhost" required></label>'
       . '<label>' . e(t('Port (optional)')) . '<input name="port" value="' . $v('port') . '" inputmode="numeric" placeholder="3306"></label>'
       . '<label>' . e(t('Datenbankname')) . '<input name="name" value="' . $v('name') . '" required></label>'
       . '<label>' . e(t('Benutzer')) . '<input name="user" value="' . $v('user') . '" required autocomplete="off"></label>'
       . '<label class="wide">' . e(t('Passwort')) . '<input type="password" name="pass" autocomplete="new-password"' . ($dbOk ? ' placeholder="' . e(t('unverändert')) . '"' : ' required') . '></label>'
       . '<button class="wide">' . e(t('Verbindung testen und speichern')) . '</button></form></div>';
    if (!empty($_GET['manual']) && !empty($_SESSION['inst_cfg'])) echo '<div class="box"><p class="msg err">' . e(t('config.php konnte nicht geschrieben werden. Bitte den folgenden Inhalt als config.php im Plattform-Ordner speichern und danach „Weiter“ klicken.')) . '</p><textarea rows="12" readonly onclick="this.select()">' . e($_SESSION['inst_cfg']) . '</textarea><p><a class="btn" href="install.php?step=3">' . e(t('Weiter')) . '</a></p></div>';
} elseif ($step === 3) {
    echo '<div class="box"><h2>' . e(t('Administrator anlegen')) . '</h2><form method="post" class="grid">' . tf() . '<input type="hidden" name="a" value="admin">'
       . '<label class="wide">' . e(t('Name der Plattform (Seitentitel)')) . '<input name="title" value="' . e($CFG['site_title'] ?? 'Turnier-Anmeldung') . '" required></label>'
       . '<label>' . e(t('Benutzername')) . '<input name="u" required autocomplete="off"></label>'
       . '<label>' . e(t('Vor- und Nachname (Klarname)')) . '<input name="n" required></label>'
       . '<label class="wide">' . e(t('E-Mail')) . '<input type="email" name="m" required></label>'
       . '<label>' . e(t('Passwort (min. 6 Zeichen)')) . '<input type="password" name="p" minlength="6" required autocomplete="new-password"></label>'
       . '<label>' . e(t('Passwort wiederholen')) . '<input type="password" name="p2" minlength="6" required autocomplete="new-password"></label>'
       . '<button class="wide">' . e(t('Installieren')) . '</button></form></div>';
} else {
    echo '<div class="box ok"><h2>' . e(!empty($_GET['ok']) ? t('Installation abgeschlossen') : t('Bereits installiert')) . '</h2><ul class="chkl"><li><span class="y">✓</span><span>' . e(t('Datenbank verbunden, Tabellen angelegt')) . '</span></li><li><span class="y">✓</span><span>' . e(t('Administrator erstellt')) . '</span></li></ul>'
       . '<p>' . e(t('Als Nächstes im Backend unter „Einstellungen“ Mailserver, Impressum und Datenschutz ausfüllen und danach das erste Turnier anlegen.')) . '</p>'
       . '<form method="post">' . tf() . '<input type="hidden" name="a" value="delete"><button>' . e(t('install.php löschen und zum Backend')) . '</button></form>'
       . '<p class="hint">' . e(t('Aus Sicherheitsgründen sollte install.php nach der Installation gelöscht werden.')) . '</p></div>';
}
echo '</div>';
page_foot();
