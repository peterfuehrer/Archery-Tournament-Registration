<?php
/* Turnier-Anmeldeplattform für ianseo – Copyright (C) 2026 Peter Führer – GNU AGPLv3 (OSI-approved), siehe LICENSE – Version 2026.11.15-7 */
const ADMIN_VERSION = '2026.11.15-7';
require __DIR__ . '/lib.php';
$p = (string)($_GET['p'] ?? 'events');
if ($p === 'logout') { session_destroy(); go('admin.php'); }

// ---- Login ----------------------------------------------------------------
if (!uid()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        post_guard();
        $u = q("SELECT * FROM reg_users WHERE username=?", [trim($_POST['u'] ?? '')])->fetch();
        if ($u && password_verify($_POST['p'] ?? '', $u['pw'])) { session_regenerate_id(true); $_SESSION['uid'] = (int)$u['id']; $_SESSION['un'] = $u['username']; $_SESSION['role'] = $u['role']; go('admin.php'); }
        sleep(1); flash('Benutzername oder Passwort falsch.', 'err');
    }
    page_head('Login'); ?>
    <h2>Backend-Login</h2>
    <form method="post" class="stack"><?= tf() ?>
      <label>Benutzername<input name="u" required autofocus></label>
      <label>Passwort<input name="p" type="password" required></label>
      <button>Anmelden</button></form>
    <?php page_foot(); exit;
}
if (role() === '') { session_destroy(); go('admin.php'); }
register_shutdown_function(function () {            // fatale PHP-Fehler für Administratoren sichtbar machen
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && ($_SESSION['role'] ?? '') === 'admin')
        echo '<div class="msg err"><strong>PHP-Fehler:</strong> ' . htmlspecialchars($e['message']) . ' (' . htmlspecialchars(basename($e['file'])) . ':' . (int)$e['line'] . ')</div>';
});

function get_event(int $id): array { return q("SELECT * FROM reg_events WHERE id=?", [$id])->fetch() ?: exit('Turnier nicht gefunden'); }
function statuses(int $eid): array {
    $st = [];
    foreach (q("SELECT id FROM reg_sessions WHERE event_id=?", [$eid]) as $s) { [$a, $b] = lists((int)$s['id']); foreach ($a as $r) $st[$r['id']] = 'Starter'; foreach ($b as $r) $st[$r['id']] = 'Warteliste'; }
    return $st;
}
// Protokoll: Filter und Zugriff (Administrator: alles; Organisationsleitung/Auswertung/Kassier:in: nur ihre Turniere)
function log_scope(): array {
    $sqlE = "SELECT id,title FROM reg_events";
    if (role() === 'orga') $evs = q("$sqlE WHERE owner_id=? ORDER BY id DESC", [uid()])->fetchAll();
    elseif (role() === 'auswerter') $evs = q("$sqlE WHERE owner_id=? OR id IN (SELECT event_id FROM reg_event_scorers WHERE user_id=?) ORDER BY id DESC", [uid(), uid()])->fetchAll();
    elseif (role() === 'kassier') $evs = q("$sqlE WHERE id IN (SELECT event_id FROM reg_event_users WHERE user_id=?) ORDER BY id DESC", [uid()])->fetchAll();
    else $evs = q("$sqlE ORDER BY id DESC")->fetchAll();
    $ids = array_map('intval', array_column($evs, 'id'));
    $fe = (int)($_GET['id'] ?? 0); if ($fe && !in_array($fe, $ids, true)) deny();
    $fa = (string)($_GET['act'] ?? ''); if (!isset(LOG_ACTIONS[$fa])) $fa = '';
    $fq = trim((string)($_GET['q'] ?? ''));
    $w = []; $par = [];
    if ($fe) { $w[] = 'l.event_id=?'; $par[] = $fe; } elseif (!is_admin()) $w[] = $ids ? 'l.event_id IN (' . implode(',', $ids) . ')' : '1=0';
    if ($fa !== '') { $w[] = 'l.action=?'; $par[] = $fa; }
    if ($fq !== '') { $w[] = '(l.details LIKE ? OR l.actor LIKE ?)'; $par[] = "%$fq%"; $par[] = "%$fq%"; }
    return [$evs, $fe, $fa, $fq, $w ? 'WHERE ' . implode(' AND ', $w) : '', $par];
}
function dob_val($d): string { return ($d === null || $d === '0000-00-00') ? '' : (string)$d; }

// Rückkehr zur Anmeldeliste mit dem zuletzt gesetzten Filter (Feld "ret" = Filter-Query, wird neu aufgebaut und geprüft)
function back_entries(array $ev): string {
    parse_str((string)($_POST['ret'] ?? ''), $q); $qs = entry_filter_query(entry_filter_params(is_array($q) ? $q : []));
    return 'admin.php?p=entries&id=' . (int)$ev['id'] . ($qs !== '' ? '&' . $qs : '');
}
// Filterleiste: Suche, Durchgang, Bogenklasse, Altersklasse, Zahlung, Status. $page = 'entries' oder 'mail'
function filter_form(array $ev, string $page, array $all, array $f, string $btn): string {
    $opt = ianseo_opts((int)$ev['to_id']);
    $sel = function (string $name, string $label, array $items, string $cur): string {
        $h = '<label>' . $label . '<select name="' . $name . '"><option value="">alle</option>';
        foreach ($items as $k => $v) $h .= '<option value="' . e($k) . '"' . ((string)$k === $cur ? ' selected' : '') . '>' . e($v) . '</option>';
        return $h . '</select></label>';
    };
    $ses = []; foreach (q("SELECT * FROM reg_sessions WHERE event_id=? ORDER BY no", [$ev['id']]) as $x) $ses[$x['id']] = $x['no'] . ($x['name'] !== '' ? ' – ' . $x['name'] : '');
    $div = []; foreach (stat_keys(array_column($all, 'division'), $opt['div']) as $k) $div[$k] = stat_label($k, $opt['div']);
    $cls = []; foreach (stat_keys(array_column($all, 'class'), $opt['cls']) as $k) $cls[$k] = stat_label($k, $opt['cls']);
    $on = entry_filter_query($f) !== '';
    return '<form method="get" class="box filt"><input type="hidden" name="p" value="' . e($page) . '"><input type="hidden" name="id" value="' . (int)$ev['id'] . '">'
        . '<label class="grow">Suche<input type="search" name="q" value="' . e($f['q']) . '" placeholder="Name, Verein, Lizenz, E-Mail, Referenz …"></label>'
        . $sel('ses', 'Durchgang', $ses, (string)$f['ses']) . $sel('div', 'Bogenklasse', $div, (string)$f['div']) . $sel('cls', 'Altersklasse', $cls, (string)$f['cls'])
        . $sel('z', 'Zahlung', ['1' => 'bezahlt', '0' => 'nicht bezahlt'], (string)$f['z']) . $sel('st', 'Status', ['Starter' => 'Starter', 'Warteliste' => 'Warteliste'], (string)$f['st'])
        . '<button class="sm">' . e($btn) . '</button>' . ($on ? ' <a href="?p=' . e($page) . '&id=' . (int)$ev['id'] . '">Zurücksetzen</a>' : '') . '</form>';
}
// Daten für Statistik und Statistik-CSV: [Zähler, Klassen-Bezeichnungen aus ianseo, Durchgänge, gefilterte Anmeldungen]
function stats_data(array $ev, array $f): array {
    $rows = filter_entries($ev, event_entries($ev), $f);
    return [event_stats($rows, statuses((int)$ev['id'])), ianseo_opts((int)$ev['to_id']), q("SELECT * FROM reg_sessions WHERE event_id=? ORDER BY no", [$ev['id']])->fetchAll(), $rows];
}

// ---- Aktionen ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0);
    $report = function (array $m) { foreach ($m as $x) flash('ianseo: ' . $x, 'err'); };
    $entry = function (int $i): array { $en = q("SELECT * FROM reg_entries WHERE id=?", [$i])->fetch(); if (!$en) { flash('Anmeldung nicht gefunden.', 'err'); go('admin.php'); } return $en; };
    switch ($a) {
        case 'add_event':
            if (!can_create_event()) deny();
            $t = q("SELECT ToId,ToName FROM Tournament WHERE ToId=?", [(int)($_POST['to_id'] ?? 0)])->fetch();
            if ($t) {
                q("INSERT INTO reg_events (to_id,title,kind,owner_id) VALUES (?,?,?,?)", [$t['ToId'], $t['ToName'], ($_POST['kind'] ?? '') === 'oem' ? 'oem' : 'normal', uid()]);
                go('admin.php?p=event&id=' . db()->lastInsertId());
            }
            go('admin.php');
        case 'save_event':
            $ev = get_event($id); if (!can_event($ev)) deny();
            $owner = $ev['owner_id'];
            if (is_admin() && (int)($_POST['owner_id'] ?? 0) > 0) $owner = (int)$_POST['owner_id'];
            $tpl = [];                                                              // E-Mail-Texte je Turnier
            foreach (['reg', 'ok'] as $k) foreach (['de', 'en'] as $l) {
                $ts = trim((string)($_POST['tpl'][$k][$l]['s'] ?? '')); $tb = trim((string)($_POST['tpl'][$k][$l]['b'] ?? ''));
                if ($ts !== '' || $tb !== '') $tpl[$k][$l] = ['s' => mb_substr($ts, 0, 200), 'b' => mb_substr($tb, 0, 5000)];
            }
            $tpl = $tpl ? json_encode($tpl, JSON_UNESCAPED_UNICODE) : null;
            $doc = $ev['doc']; $logo = $ev['logo'];
            try {
                if (!empty($_POST['rm_doc'])) { delete_upload($doc); $doc = ''; }
                if (($_FILES['doc']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) { $new = save_upload($_FILES['doc'], 'pdf'); delete_upload($doc); $doc = $new; }
                if (!empty($_POST['rm_logo'])) { delete_upload($logo); $logo = ''; }
                if (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) { $new = save_upload($_FILES['logo'], 'logo'); delete_upload($logo); $logo = $new; }
            } catch (Throwable $x) { flash($x->getMessage(), 'err'); }
            $url = trim((string)($_POST['doc_url'] ?? ''));
            if ($url !== '' && !preg_match('#^https?://#i', $url)) { flash('Link zur Ausschreibung muss mit http:// oder https:// beginnen.', 'err'); $url = ''; }
            $rs = trim((string)($_POST['reg_start'] ?? '')); $rs = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rs) ? $rs : null;       // Anmeldestart
            $dlv = trim((string)($_POST['deadline'] ?? ''));
            if ($rs && $dlv !== '' && $rs > $dlv) { flash('Der Anmeldestart liegt nach dem Anmeldeschluss und wurde nicht gespeichert.', 'err'); $rs = null; }
            $autoOpen = (isset($_POST['auto_open']) && !isset($_POST['is_open'])) ? 1 : 0;
            if ($autoOpen && (!$rs || $rs < date('Y-m-d'))) { if (isset($_POST['auto_open'])) flash('Automatisches Öffnen braucht einen Anmeldestart (heute oder später) und wurde nicht aktiviert.', 'err'); $autoOpen = 0; }
            q("UPDATE reg_events SET title=?,info=?,pay_info=?,ref_text=?,is_open=?,published=?,deadline=?,kind=?,owner_id=?,doc=?,doc_url=?,logo=?,allow_nolic=?,paypal_me=?,fee=?,mail_tpl=?,reg_start=?,auto_open=? WHERE id=?",
              [trim((string)$_POST['title']), trim((string)$_POST['info']), trim((string)$_POST['pay_info']), trim((string)$_POST['ref_text']), isset($_POST['is_open']) ? 1 : 0, isset($_POST['published']) ? 1 : 0,
               ($_POST['deadline'] ?? '') ?: null, ($_POST['kind'] ?? '') === 'oem' ? 'oem' : 'normal', $owner, $doc, $url, $logo, isset($_POST['allow_nolic']) ? 1 : 0, mb_substr((string)preg_replace('~[^A-Za-z0-9._/:-]~', '', trim((string)($_POST['paypal_me'] ?? ''))), 0, 80), mb_substr((string)preg_replace('/[^0-9.,]/', '', (string)($_POST['fee'] ?? '')), 0, 12), $tpl, $rs, $autoOpen, $id]);
            $kz = array_values(array_unique(array_map('intval', (array)($_POST['kassier'] ?? []))));                // Kassier:innen freigeben (höchstens 2)
            if (count($kz) > 2) { flash('Höchstens 2 Kassier:innen je Turnier – es wurden nur die ersten 2 übernommen.', 'err'); $kz = array_slice($kz, 0, 2); }
            q("DELETE FROM reg_event_users WHERE event_id=?", [$id]);
            foreach ($kz as $k)
                if (q("SELECT 1 FROM reg_users WHERE id=? AND role='kassier'", [$k])->fetch()) q("INSERT IGNORE INTO reg_event_users (event_id,user_id) VALUES (?,?)", [$id, $k]);
            if (can_event($ev)) {                                                          // Auswertung (höchstens 3) vergeben: Administrator, Organisationsleitung, Auswertung
                $aw = array_values(array_unique(array_map('intval', (array)($_POST['auswerter'] ?? []))));
                if (count($aw) > 3) { flash('Höchstens 3 Personen der Auswertung je Turnier – es wurden nur die ersten 3 übernommen.', 'err'); $aw = array_slice($aw, 0, 3); }
                q("DELETE FROM reg_event_scorers WHERE event_id=?", [$id]);
                foreach ($aw as $k) if (q("SELECT 1 FROM reg_users WHERE id=? AND role='auswerter'", [$k])->fetch()) q("INSERT IGNORE INTO reg_event_scorers (event_id,user_id) VALUES (?,?)", [$id, $k]);
            }
            $o = ianseo_opts((int)$ev['to_id']);
            foreach (range(1, 10) as $n) {
                $row = $_POST['s'][$n] ?? [];
                $ex = q("SELECT id FROM reg_sessions WHERE event_id=? AND no=?", [$id, $n])->fetchColumn();
                if (!empty($row['on'])) {
                    $divs = implode(',', array_filter((array)($row['div'] ?? []), fn($d) => isset($o['div'][$d])));
                    $nm = trim((string)($row['name'] ?? '')); $cap = max(0, (int)($row['cap'] ?? 0));
                    $cl = !empty($row['closed']) ? 1 : 0; $sd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($row['dl'] ?? '')) ? $row['dl'] : null;
                    if ($ex) q("UPDATE reg_sessions SET name=?,capacity=?,divisions=?,closed=?,deadline=? WHERE id=?", [$nm, $cap, $divs, $cl, $sd, $ex]);
                    else q("INSERT INTO reg_sessions (event_id,no,name,capacity,divisions,closed,deadline) VALUES (?,?,?,?,?,?,?)", [$id, $n, $nm, $cap, $divs, $cl, $sd]);
                } elseif ($ex) {
                    if (q("SELECT COUNT(*) FROM reg_entries WHERE session_id=?", [$ex])->fetchColumn() > 0) flash("Durchgang $n hat Anmeldungen und bleibt bestehen.", 'err');
                    else q("DELETE FROM reg_sessions WHERE id=?", [$ex]);
                }
            }
            $report(sync_event($id)); flash('Gespeichert.'); go('admin.php?p=event&id=' . $id);
        case 'del_event':          // entfernt nur Turnier, Durchgänge und Anmeldungen aus der Plattform – ianseo bleibt unberührt
            $ev = get_event($id); if (!can_event($ev)) deny();
            log_event('turnier_geloescht', null, null, $ev['title']);
            delete_upload($ev['doc']); delete_upload($ev['logo']);
            q("DELETE FROM reg_entries WHERE event_id=?", [$id]); q("DELETE FROM reg_sessions WHERE event_id=?", [$id]);
            q("DELETE FROM reg_event_users WHERE event_id=?", [$id]); q("DELETE FROM reg_event_scorers WHERE event_id=?", [$id]); q("DELETE FROM reg_events WHERE id=?", [$id]);
            flash('Turnier aus der Anmeldung entfernt. Die Daten in ianseo wurden nicht verändert.'); go('admin.php');
        case 'paid':
            $en = $entry($id); $ev = get_event((int)$en['event_id']); if (!can_cash($ev)) deny();
            $paid = (int)($_POST['v'] ?? 0) ? 1 : 0;
            q("UPDATE reg_entries SET paid=?, paid_at=? WHERE id=?", [$paid, $paid ? date('Y-m-d H:i:s') : null, $id]);
            log_event($paid ? 'bezahlt' : 'zurueck', (int)$ev['id'], $id, $en['lastname'] . ' ' . $en['firstname']);
            $report(sync_event((int)$ev['id'])); go(back_entries($ev));
        case 'del_entry':
            $en = $entry($id); $ev = get_event((int)$en['event_id']); if (!can_cash($ev)) deny();
            try {
                if ($en['en_id']) ianseo_remove((int)$ev['to_id'], (int)$en['en_id']);
                q("DELETE FROM reg_entries WHERE id=?", [$id]); log_event('geloescht', (int)$ev['id'], $id, $en['lastname'] . ' ' . $en['firstname'] . ' (' . ref_code((int)$ev['id'], $id) . ')'); $report(sync_event((int)$ev['id'])); flash('Gelöscht, Nachrücker abgeglichen.');
            } catch (Throwable $x) { flash($x->getMessage(), 'err'); }
            go(back_entries($ev));
        case 'move':
            $en = $entry($id); $ev = get_event((int)$en['event_id']); if (!can_cash($ev)) deny();
            $to = q("SELECT * FROM reg_sessions WHERE id=? AND event_id=?", [(int)($_POST['sid'] ?? 0), $ev['id']])->fetch();
            if ($to && $to['id'] != $en['session_id']) {
                try {
                    if ($to['divisions'] !== '' && !in_array($en['division'], explode(',', $to['divisions']), true)) throw new RuntimeException('Diese Bogenklasse ist im Ziel-Durchgang nicht zugelassen.');
                    if ($en['en_id']) {
                        $sc = q("SELECT QuScore FROM Qualifications WHERE QuId=?", [$en['en_id']])->fetchColumn();
                        if ($sc !== false && (int)$sc > 0) throw new RuntimeException('Hat bereits Ergebnisse in ianseo – nicht verschoben.');
                        q("UPDATE Qualifications SET QuSession=? WHERE QuId=?", [$to['no'], $en['en_id']]);
                    }
                    q("UPDATE reg_entries SET session_id=? WHERE id=?", [$to['id'], $id]);
                    log_event('verschoben', (int)$ev['id'], $id, $en['lastname'] . ' ' . $en['firstname'] . ': Durchgang ' . (int)q("SELECT no FROM reg_sessions WHERE id=?", [$en['session_id']])->fetchColumn() . ' → ' . $to['no']);
                    $report(sync_event((int)$ev['id'])); flash('Verschoben.');
                } catch (Throwable $x) { flash($x->getMessage(), 'err'); }
            }
            go(back_entries($ev));
        case 'save_entry':
            $en = $entry($id); $ev = get_event((int)$en['event_id']); if (!can_cash($ev)) deny();
            $n = fn($k) => trim((string)($_POST[$k] ?? ''));
            $o = ianseo_opts((int)$ev['to_id']);
            $dob = $n('dob'); $d = DateTime::createFromFormat('Y-m-d', $dob);
            $bad = $n('firstname') === '' || $n('lastname') === '' || !filter_var($n('email'), FILTER_VALIDATE_EMAIL) || !in_array((int)$n('sex'), [0, 1], true)
                || ($dob !== '' && (!$d || $d->format('Y-m-d') !== $dob))
                || ($o['div'] && !isset($o['div'][$n('division')]) && $n('division') !== $en['division'])
                || ($o['cls'] && !isset($o['cls'][$n('class')]) && $n('class') !== $en['class']);
            if ($bad) { flash('Eingaben ungültig (Name, E-Mail, Geschlecht, Datum, Bogenklasse oder Altersklasse prüfen).', 'err'); go('admin.php?p=edit&id=' . $id); }
            $ccode = club_code_by_name($n('club')); $lchk = lic_status($n('license'), $n('lastname'), $n('firstname'));
            $country = strtoupper($n('country'));
            if ($country !== '' && !preg_match('/^[A-Z]{3}$/', $country)) { flash('Land: bitte 3-Buchstaben-IOC-Code (z. B. GER) oder leer lassen.', 'err'); go('admin.php?p=edit&id=' . $id); }
            if ($country !== '' && $lchk !== 'frei') $lchk = 'ausland';
            $chg = [];
            foreach (['firstname' => $n('firstname'), 'lastname' => $n('lastname'), 'sex' => (string)(int)$n('sex'), 'dob' => $dob, 'club' => $n('club'), 'license' => $n('license'), 'email' => $n('email'), 'division' => $n('division'), 'class' => $n('class')] as $k => $nv) {
                $ov = (string)($en[$k] ?? ''); if ($k === 'dob' && $ov === '0000-00-00') $ov = '';
                if ($ov !== (string)$nv) $chg[] = "$k: $ov → $nv";
            }
            $qt = $ev['kind'] === 'oem' ? (in_array((int)$n('qtype'), [1, 2], true) ? (int)$n('qtype') : null) : null;
            q("UPDATE reg_entries SET firstname=?,lastname=?,sex=?,dob=?,club=?,license=?,email=?,division=?,class=?,qtype=?,q1=?,q2=?,q3=?,qy=?,qe=?,club_code=?,lic_check=?,country=? WHERE id=?",
              [$n('firstname'), $n('lastname'), (int)$n('sex'), $dob !== '' ? $dob : null, $n('club'), $n('license'), $n('email'), $n('division'), $n('class'),
               $qt, $n('q1'), $n('q2'), $n('q3'), $n('qy'), $n('qe'), $ccode, $lchk, $country, $id]);
            log_event('bearbeitet', (int)$ev['id'], $id, $en['lastname'] . ' ' . $en['firstname'] . ($chg ? ' – ' . implode('; ', $chg) : ' – keine Änderung'));
            if ($en['en_id']) {
                try { ianseo_update((int)$ev['to_id'], q("SELECT * FROM reg_entries WHERE id=?", [$id])->fetch()); flash('Gespeichert und in ianseo aktualisiert. Falls schon gewertet wurde: Ranglisten in ianseo neu berechnen.'); }
                catch (Throwable $x) { flash('Gespeichert, aber ianseo-Update fehlgeschlagen: ' . $x->getMessage(), 'err'); }
            } else flash('Gespeichert.');
            go('admin.php?p=entries&id=' . $ev['id']);
        case 'send_mass':        // Rundmail an ausgewählte Anmeldungen (Administrator, Organisationsleitung, freigegebene Kassier:in)
            $ev = get_event($id); if (!can_cash($ev)) deny();
            $f = entry_filter_params($_POST); $fq = entry_filter_query($f);
            $back = 'admin.php?p=mail&id=' . $id . ($fq !== '' ? '&' . $fq : '');
            $tpl = ['de' => ['s' => trim((string)($_POST['s_de'] ?? '')), 'b' => trim((string)($_POST['b_de'] ?? ''))],
                    'en' => ['s' => trim((string)($_POST['s_en'] ?? '')), 'b' => trim((string)($_POST['b_en'] ?? ''))]];
            $_SESSION['mass_draft'][$id] = ['tpl' => $tpl, 'dedupe' => !empty($_POST['dedupe']), 'copy' => !empty($_POST['copy'])];     // Entwurf bleibt bei Fehlern erhalten
            if ($tpl['de']['s'] === '' || $tpl['de']['b'] === '') { flash('Betreff und Text (Deutsch) sind nötig.', 'err'); go($back); }
            if (($tpl['en']['s'] === '') !== ($tpl['en']['b'] === '')) { flash('Englische Fassung: Betreff UND Text ausfüllen oder beides leer lassen.', 'err'); go($back); }
            if (empty(mail_cfg()['enabled'])) { flash('E-Mail-Versand ist nicht aktiviert (Einstellungen → Mailserver).', 'err'); go($back); }
            $ids = array_flip(array_map('intval', (array)($_POST['sel'] ?? [])));
            $sel = array_values(array_filter(event_entries($ev), fn($r) => isset($ids[(int)$r['id']])));
            if (!$sel) { flash('Keine Empfänger ausgewählt.', 'err'); go($back); }
            if (count($sel) > 500) { flash('Höchstens 500 Anmeldungen pro Rundmail – bitte nach Durchgang oder Bogenklasse aufteilen.', 'err'); go($back); }
            $me = q("SELECT email FROM reg_users WHERE id=?", [uid()])->fetch();
            $own = ($me && filter_var($me['email'], FILTER_VALIDATE_EMAIL)) ? (string)$me['email'] : null;
            $ow = owner_of($ev);
            $reply = $own ?? (($ow && filter_var($ow['email'], FILTER_VALIDATE_EMAIL)) ? (string)$ow['email'] : null);       // Antworten gehen an dich bzw. die Organisationsleitung
            $pre = fn(string $x) => ['de' => ['s' => $x . $tpl['de']['s'], 'b' => $tpl['de']['b']], 'en' => ['s' => $tpl['en']['s'] !== '' ? $x . $tpl['en']['s'] : '', 'b' => $tpl['en']['b']]];
            if (!empty($_POST['test'])) {        // nur Testmail an die eigene Adresse (mit den Daten des ersten Empfängers)
                if (!$own) { flash('In deinem Konto ist keine gültige E-Mail-Adresse hinterlegt (Mein Konto).', 'err'); go($back); }
                $t = $sel[0]; $t['email'] = $own;
                [$n, $er] = send_mass($ev, [$t], $pre('[Test] '), null, false);
                flash($n ? 'Testmail an ' . $own . ' gesendet (Platzhalter mit den Daten von ' . $t['firstname'] . ' ' . $t['lastname'] . ').' : 'Testmail fehlgeschlagen: ' . implode(' ', $er), $n ? 'ok' : 'err'); go($back);
            }
            [$n, $er] = send_mass($ev, $sel, $tpl, $reply, !empty($_POST['dedupe']));
            if ($n > 0 && !empty($_POST['copy']) && $own) { $t = $sel[0]; $t['email'] = $own; send_mass($ev, [$t], $pre('[Kopie] '), null, false); }
            log_event('rundmail', (int)$ev['id'], null, '„' . mb_substr($tpl['de']['s'], 0, 80) . '“ an ' . $n . ' Empfänger' . ($fq !== '' ? ' (Filter: ' . urldecode($fq) . ')' : '') . ($er ? ', ' . count($er) . ' Fehler' : ''));
            flash('Rundmail: ' . $n . ($n === 1 ? ' E-Mail' : ' E-Mails') . ' gesendet' . ($er ? ', ' . count($er) . ' fehlgeschlagen' : '') . '.', $er ? 'err' : 'ok');
            foreach (array_slice($er, 0, 5) as $x) flash($x, 'err');
            if (!$er) unset($_SESSION['mass_draft'][$id]);
            go($back);
        case 'sync':
            $ev = get_event($id); if (!can_view($ev)) deny();
            $report(sync_event($id)); flash('Abgleich mit ianseo durchgeführt.'); go('admin.php?p=entries&id=' . $id);
        case 'add_user':
            if (!is_admin()) deny();
            $u = trim((string)($_POST['u'] ?? '')); $fn = trim((string)($_POST['fullname'] ?? '')); $m = trim((string)($_POST['email'] ?? '')); $r = (string)($_POST['role'] ?? '');
            if ($u === '' || $fn === '' || strlen((string)($_POST['pw'] ?? '')) < 6 || !isset(ROLES[$r]) || !filter_var($m, FILTER_VALIDATE_EMAIL)) flash('Bitte alle Felder ausfüllen (Passwort min. 6 Zeichen, gültige E-Mail).', 'err');
            else try { q("INSERT INTO reg_users (username,pw,role,fullname,email) VALUES (?,?,?,?,?)", [$u, password_hash($_POST['pw'], PASSWORD_DEFAULT), $r, $fn, $m]); flash('Benutzer angelegt.'); }
            catch (Throwable $x) { flash('Benutzername existiert bereits.', 'err'); }
            go('admin.php?p=users');
        case 'save_user':
            $target = is_admin() ? $id : uid();
            $u = q("SELECT * FROM reg_users WHERE id=?", [$target])->fetch(); if (!$u) go('admin.php');
            $fn = trim((string)($_POST['fullname'] ?? '')); $m = trim((string)($_POST['email'] ?? '')); $pw = (string)($_POST['pw'] ?? '');
            $r = $u['role']; if (is_admin() && $target !== uid() && isset(ROLES[$_POST['role'] ?? ''])) $r = (string)$_POST['role'];
            if ($fn === '' || !filter_var($m, FILTER_VALIDATE_EMAIL) || ($pw !== '' && strlen($pw) < 6)) { flash('Klarname, gültige E-Mail und (falls gesetzt) Passwort mit min. 6 Zeichen nötig.', 'err'); go('admin.php?p=user&id=' . $target); }
            q("UPDATE reg_users SET fullname=?,email=?,role=? WHERE id=?", [$fn, $m, $r, $target]);
            if ($pw !== '') q("UPDATE reg_users SET pw=? WHERE id=?", [password_hash($pw, PASSWORD_DEFAULT), $target]);
            flash('Gespeichert.'); go(is_admin() ? 'admin.php?p=users' : 'admin.php');
        case 'del_user':
            if (!is_admin()) deny();
            if ($id === uid()) flash('Du kannst dich nicht selbst löschen.', 'err');
            else { q("UPDATE reg_events SET owner_id=NULL WHERE owner_id=?", [$id]); q("DELETE FROM reg_event_users WHERE user_id=?", [$id]); q("DELETE FROM reg_event_scorers WHERE user_id=?", [$id]); q("DELETE FROM reg_users WHERE id=?", [$id]); }
            go('admin.php?p=users');
        case 'import_master':
            if (!is_admin()) deny();
            try {
                $up = $_FILES['csv'] ?? [];
                if (($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) throw new RuntimeException('Bitte eine CSV-Datei auswählen (Upload-Fehler ' . ($up['error'] ?? '?') . ', ggf. Limit upload_max_filesize).');
                $rep = ($_POST['mode'] ?? '') === 'replace';
                [$na, $nc] = import_master($up['tmp_name'], $rep);
                flash("$na Schützen und $nc Vereine importiert" . ($rep ? ' (bisherige Listen ersetzt).' : ' (ergänzt/aktualisiert).'));
            } catch (Throwable $x) { flash($x->getMessage(), 'err'); }
            go('admin.php?p=master');
        case 'import_entries':
            $ev = get_event($id); if (!can_event($ev)) deny();
            try {
                $up = $_FILES['csv'] ?? [];
                if (($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) throw new RuntimeException('Bitte eine CSV-Datei auswählen.');
                [$ok, $dup, $errs] = import_entries($ev, $up['tmp_name']);
                log_event('import', $id, null, "$ok importiert, $dup Duplikate, " . count($errs) . ' Hinweise/Fehler');
                flash("$ok Anmeldungen importiert, $dup Duplikate übersprungen" . ($errs ? ', ' . count($errs) . ' Hinweise/Fehler.' : '.'), $errs ? 'err' : 'ok');
                foreach (array_slice($errs, 0, 8) as $m) flash($m, 'err');
                if (count($errs) > 8) flash('… und ' . (count($errs) - 8) . ' weitere.', 'err');
            } catch (Throwable $x) { flash($x->getMessage(), 'err'); }
            go('admin.php?p=entries&id=' . $id);
        case 'clubs_to_ianseo':      // fehlende Vereine aus der CSV-Liste als Countries im ianseo-Turnier anlegen
            $ev = get_event($id); if (!can_event($ev)) deny();
            $n = 0;
            foreach (q("SELECT * FROM reg_clubs")->fetchAll() as $c) {
                if (q("SELECT 1 FROM Countries WHERE CoTournament=? AND CoCode=?", [$ev['to_id'], substr($c['code'], 0, 10)])->fetch()) continue;
                $full = ($c['state_code'] !== '' ? $c['state_code'] . '/' : '') . $c['name'];          // ianseo-Schema "B/BC-Wiesfleck"
                ins('Countries', ['CoTournament' => $ev['to_id'], 'CoCode' => substr($c['code'], 0, 10), 'CoName' => mb_substr($full, 0, 30), 'CoNameComplete' => mb_substr($full, 0, 80)]); $n++;
            }
            flash($n . ' fehlende Vereine in ianseo angelegt.'); go('admin.php?p=event&id=' . $id);
        case 'athlete_sync':
            $ev = get_event($id); if (!can_cash($ev)) deny();
            $msg = athlete_sync_event($id); log_event('abgleich', $id, null, $msg); flash($msg); go('admin.php?p=entries&id=' . $id);
        case 'toggle_event':
            $ev = get_event($id); if (!can_event($ev)) deny();
            $wasWaiting = event_starts_later($ev);                          // Anmeldestart noch nicht erreicht: manuelles Öffnen = jetzt öffnen
            $open = ($ev['is_open'] && !$wasWaiting) ? 0 : 1;
            q("UPDATE reg_events SET is_open=?, auto_open=0" . ($open && $wasWaiting ? ", reg_start=NULL" : "") . " WHERE id=?", [$open, $id]);
            log_event($open ? 'turnier_auf' : 'turnier_zu', $id, null, $ev['title']);
            flash($open ? 'Anmeldung für das Turnier geöffnet.' . ($wasWaiting ? ' Der Anmeldestart (' . fmt_date($ev['reg_start']) . ') wurde entfernt.' : '') : 'Anmeldung für das Turnier geschlossen.');
            go(($_POST['back'] ?? '') === 'entries' ? 'admin.php?p=entries&id=' . $id : 'admin.php');
        case 'toggle_session':
            $sx = q("SELECT * FROM reg_sessions WHERE id=?", [$id])->fetch(); if (!$sx) go('admin.php');
            $ev = get_event((int)$sx['event_id']); if (!can_event($ev)) deny();
            q("UPDATE reg_sessions SET closed=? WHERE id=?", [$sx['closed'] ? 0 : 1, $id]); log_event($sx['closed'] ? 'durchgang_auf' : 'durchgang_zu', (int)$ev['id'], null, 'Durchgang ' . $sx['no'] . ($sx['name'] ? ' – ' . $sx['name'] : ''));
            flash('Durchgang ' . $sx['no'] . ($sx['closed'] ? ': Anmeldung geöffnet.' : ': Anmeldung geschlossen.')); go('admin.php?p=entries&id=' . $ev['id']);
        case 'save_athlete':
            if (!is_admin()) deny();
            $orig = trim((string)($_POST['orig'] ?? '')); $lic = trim((string)($_POST['license'] ?? '')); $ln = trim((string)($_POST['lastname'] ?? '')); $fn = trim((string)($_POST['firstname'] ?? '')); $cc = trim((string)($_POST['club_code'] ?? ''));
            $back = 'admin.php?p=athlete' . ($orig !== '' ? '&l=' . rawurlencode($orig) : '');
            if ($lic === '' || $ln === '' || $fn === '') { flash('Lizenznummer, Vorname und Nachname sind Pflicht.', 'err'); go($back); }
            if (($orig === '' || $lic !== $orig) && athlete_by_license($lic)) { flash('Diese Lizenznummer existiert bereits.', 'err'); go($back); }
            if ($cc !== '' && !club_by_code($cc)) { flash('Verein unbekannt – bitte aus der Liste wählen.', 'err'); go($back); }
            $sxv = ($_POST['sex'] ?? '') === '' ? null : ((int)$_POST['sex'] ? 1 : 0);
            if ($orig === '') q("INSERT INTO reg_athletes (license,lastname,firstname,club_code,sex) VALUES (?,?,?,?,?)", [mb_substr($lic, 0, 25), mb_substr($ln, 0, 60), mb_substr($fn, 0, 60), $cc, $sxv]);
            else q("UPDATE reg_athletes SET license=?,lastname=?,firstname=?,club_code=?,sex=? WHERE license=?", [mb_substr($lic, 0, 25), mb_substr($ln, 0, 60), mb_substr($fn, 0, 60), $cc, $sxv, $orig]);
            flash('Schütze gespeichert.'); go('admin.php?p=master&q=' . rawurlencode($lic));
        case 'del_athlete':
            if (!is_admin()) deny();
            q("DELETE FROM reg_athletes WHERE license=?", [trim((string)($_POST['orig'] ?? ''))]); flash('Schütze gelöscht.'); go('admin.php?p=master');
        case 'set_api':
            if (!is_admin()) deny();
            $old = api_cfg(); $g = fn(string $k) => trim((string)($_POST[$k] ?? ''));
            foreach (['a_base', 'a_token'] as $u) if ($g($u) !== '' && !preg_match('~^https://~i', $g($u))) { flash('Die Adressen der Schnittstelle müssen mit https:// beginnen.', 'err'); go('admin.php?p=settings&open=api'); }
            $cfg = ['enabled' => isset($_POST['a_on']), 'base' => rtrim($g('a_base'), '/'), 'token_url' => $g('a_token'), 'auth' => $g('a_auth') === 'basic' ? 'basic' : 'form', 'client_id' => $g('a_id'),
                'secret' => enc($g('a_secret') !== '' ? (string)$_POST['a_secret'] : (string)$old['secret']), 'path' => $g('a_path'), 'method' => $g('a_method') === 'POST' ? 'POST' : 'GET', 'param' => $g('a_param'),
                'found_key' => $g('a_found'), 'first_key' => $g('a_first'), 'last_key' => $g('a_last'), 'club_code_key' => $g('a_cc'), 'club_name_key' => $g('a_cn'), 'sex_key' => $g('a_sex'), 'check_club' => isset($_POST['a_club'])];
            set_setting('api_config', json_encode($cfg, JSON_UNESCAPED_UNICODE)); set_setting('api_token', ''); set_setting('api_token_exp', '0');
            q("DELETE FROM reg_api_cache"); flash('Einstellungen der Lizenz-Schnittstelle gespeichert. Mit dem Test prüfen.'); go('admin.php?p=settings&open=api');
        case 'api_test':
            if (!is_admin()) deny();
            $lic = trim((string)($_POST['lic'] ?? '')); $c = api_cfg(); $res = ['token' => '', 'status' => 0, 'error' => '', 'map' => null, 'raw' => ''];
            if (!api_enabled()) $res['token'] = 'Schnittstelle nicht aktiv oder unvollständig (Basis-URL mit https, Client-ID und Geheimnis nötig).';
            else {
                $t = api_token(true); $res['token'] = $t['token'] ? 'OK (Token erhalten)' : $t['error'];
                if ($t['token'] && $lic !== '') {
                    $r = api_call($c['path'], [$c['param'] => $lic]);
                    $pretty = is_array($r['json']) ? json_encode($r['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $r['raw'];
                    $res = array_merge($res, ['status' => $r['status'], 'error' => $r['error'], 'raw' => mb_substr((string)$pretty, 0, 4000), 'map' => is_array($r['json']) ? api_map($r['json'], $c) : null]);
                }
            }
            $_SESSION['api_test'] = $res; go('admin.php?p=settings&open=api');
        case 'mail_tpl_test':
            $ev = get_event($id); if (!can_event($ev)) deny();
            $me = q("SELECT email FROM reg_users WHERE id=?", [uid()])->fetch();
            if (!$me || $me['email'] === '') { flash('In deinem Konto ist keine E-Mail-Adresse hinterlegt (Mein Konto).', 'err'); go('admin.php?p=event&id=' . $id); }
            $lang = ($_POST['lang'] ?? 'de') === 'en' ? 'en' : 'de'; $kind = ($_POST['kind'] ?? 'reg') === 'ok' ? 'ok' : 'reg';
            $e = ['firstname' => 'Max', 'lastname' => 'Mustermann', 'club' => 'BC Beispiel', 'license' => '12345', 'division' => 'R', 'class' => 'M'];
            $sx = q("SELECT * FROM reg_sessions WHERE event_id=? ORDER BY no LIMIT 1", [$id])->fetch() ?: ['name' => '', 'no' => 1];
            [$ms, $mb] = mail_render($ev, $kind, $lang, mail_vars($ev, $e, $sx, ref_code((int)$id, 1), $lang));
            $err = send_mail($me['email'], '[Test] ' . $ms, $mb);
            flash($err ?? 'Testmail an ' . $me['email'] . ' gesendet.', $err === null ? 'ok' : 'err'); go('admin.php?p=event&id=' . $id);
        case 'set_sql':
            if (!is_admin()) deny();
            $old = sql_cfg(); $g = fn(string $k) => trim((string)($_POST[$k] ?? ''));
            foreach (['s_table', 's_lic', 's_first', 's_last', 's_cc', 's_cn', 's_sex', 's_valid'] as $k)
                if ($g($k) !== '' && !sql_ident_ok($g($k))) { flash('Tabellen- und Spaltennamen dürfen nur Buchstaben, Ziffern und _ enthalten (optional Schema.Tabelle).', 'err'); go('admin.php?p=settings&open=sql'); }
            $cfg = ['enabled' => isset($_POST['s_on']), 'driver' => in_array($g('s_driver'), sql_drivers(), true) ? $g('s_driver') : ($old['driver'] ?: 'mysql'), 'host' => $g('s_host'), 'port' => preg_replace('/\D/', '', $g('s_port')), 'db' => $g('s_db'), 'user' => $g('s_user'),
                'pass' => enc($g('s_pass') !== '' ? (string)$_POST['s_pass'] : (string)$old['pass']), 'table' => $g('s_table'), 'license_col' => $g('s_lic'), 'first_col' => $g('s_first'), 'last_col' => $g('s_last'),
                'club_code_col' => $g('s_cc'), 'club_name_col' => $g('s_cn'), 'sex_col' => $g('s_sex'), 'valid_col' => $g('s_valid'), 'check_club' => isset($_POST['s_club'])];
            set_setting('sql_config', json_encode($cfg, JSON_UNESCAPED_UNICODE)); flash('Einstellungen der SQL-Abfrage gespeichert. Mit dem Test prüfen.'); go('admin.php?p=settings&open=sql');
        case 'sql_test':
            if (!is_admin()) deny();
            $lic = trim((string)($_POST['lic'] ?? '')); $res = ['conn' => '', 'result' => null];
            if (!sql_enabled()) $res['conn'] = 'SQL-Abfrage nicht aktiv oder unvollständig (Typ, Server, Datenbank, Benutzer, Tabelle und Lizenz-Spalte nötig).';
            else try {
                sql_pdo(sql_cfg())->query('SELECT 1'); $res['conn'] = 'Verbindung OK';
                if ($lic !== '') $res['result'] = sql_licence_lookup($lic);
            } catch (Throwable $x) { $res['conn'] = 'Verbindung fehlgeschlagen: ' . $x->getMessage(); }
            $_SESSION['sql_test'] = $res; go('admin.php?p=settings&open=sql');
        case 'set_source':
            if (!is_admin()) deny();
            $u = trim((string)($_POST['source_url'] ?? ''));
            if ($u !== '' && !preg_match('#^https?://#i', $u)) flash('Quellcode-Link muss mit http:// oder https:// beginnen.', 'err'); else { set_setting('source_url', $u); flash('Gespeichert.'); }
            go('admin.php?p=settings&open=source');
        case 'set_general':
            if (!is_admin()) deny();
            $ti = trim((string)($_POST['site_title'] ?? '')); set_setting('site_title', mb_substr($ti, 0, 100)); set_setting('site_title_hide', $ti === '' ? '1' : '0');      // leer = nur Logo
            flash('Gespeichert.'); go('admin.php?p=settings&open=general');
        case 'set_features':
            if (!is_admin()) deny();
            set_setting('autofill', isset($_POST['autofill']) ? '1' : '0'); set_setting('media_required', isset($_POST['media_required']) ? '1' : '0');
            flash('Gespeichert.'); go('admin.php?p=settings&open=features');
        case 'set_legal':
            if (!is_admin()) deny();
            foreach (['impressum' => 20000, 'privacy_controller' => 5000, 'privacy_hosting' => 300, 'privacy_extra' => 20000] as $k => $max) set_setting($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max));
            flash('Gespeichert.'); go('admin.php?p=settings&open=legal');
        case 'set_mail':
            if (!is_admin()) deny();
            $old = mail_cfg(); $g = fn(string $k) => trim((string)($_POST[$k] ?? ''));
            $sec = fn(string $v, string $d) => in_array($v, ['tls', 'ssl', 'none'], true) ? ($v === 'none' ? '' : $v) : $d;
            $cfg = ['enabled' => isset($_POST['m_on']), 'driver' => $g('m_driver') === 'mail' ? 'mail' : 'smtp', 'from' => $g('m_from'), 'from_name' => $g('m_from_name'), 'fallback_mail' => isset($_POST['m_fallback']),
                'smtp' => ['host' => $g('s_host'), 'port' => (int)($g('s_port') ?: 587), 'secure' => $sec($g('s_secure'), 'tls'), 'user' => $g('s_user'), 'pass' => enc($g('s_pass') !== '' ? (string)$_POST['s_pass'] : (string)($old['smtp']['pass'] ?? ''))],
                'imap' => ['enabled' => isset($_POST['i_on']), 'host' => $g('i_host'), 'port' => (int)($g('i_port') ?: 993), 'secure' => $sec($g('i_secure'), 'ssl'), 'user' => $g('i_user'), 'pass' => enc($g('i_pass') !== '' ? (string)$_POST['i_pass'] : (string)($old['imap']['pass'] ?? '')), 'folder' => $g('i_folder') ?: 'Sent']];
            if ($cfg['enabled'] && !filter_var($cfg['from'], FILTER_VALIDATE_EMAIL)) { flash('Bitte eine gültige Absender-Adresse eintragen.', 'err'); go('admin.php?p=settings&open=mail'); }
            if ($cfg['enabled'] && $cfg['driver'] === 'smtp' && $cfg['smtp']['host'] === '') { flash('Bitte den SMTP-Server eintragen (oder PHP mail() wählen).', 'err'); go('admin.php?p=settings&open=mail'); }
            set_setting('mail_config', json_encode($cfg, JSON_UNESCAPED_UNICODE)); flash('Mail-Einstellungen gespeichert. Mit der Testmail prüfen.'); go('admin.php?p=settings&open=mail');
        case 'set_logo':
            if (!is_admin()) deny();
            try {
                if (!empty($_POST['rm_logo'])) { delete_upload(setting('logo')); set_setting('logo', ''); flash('Logo entfernt.'); }
                elseif (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) { $new = save_upload($_FILES['logo'], 'logo'); delete_upload(setting('logo')); set_setting('logo', $new); flash('Logo gespeichert.'); }
            } catch (Throwable $x) { flash($x->getMessage(), 'err'); }
            go('admin.php?p=settings&open=general');
        case 'mailtest':
            if (!is_admin()) deny();
            $err = send_mail(trim((string)($_POST['to'] ?? '')), 'Testmail Anmeldeplattform', 'Dies ist eine Testmail der Anmeldeplattform.');
            flash($err ?? 'Testmail wurde versendet.', $err === null ? 'ok' : 'err'); go('admin.php?p=settings&open=mail');
    }
    go('admin.php');
}

// ---- CSV-Export ---------------------------------------------------------------
if ($p === 'csv') {
    $ev = get_event((int)($_GET['id'] ?? 0)); if (!can_view($ev)) deny();
    $status = statuses((int)$ev['id']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="anmeldungen-turnier' . $ev['id'] . '-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    $cell = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v;      // CSV-Injection vermeiden
    fputcsv($o, ['Referenz', 'Status', 'Durchgang', 'Nachname', 'Vorname', 'Geschlecht', 'Geburtsdatum', 'Verein', 'Vereinsnummer', 'Land', 'Lizenz', 'E-Mail', 'Bogenklasse', 'Altersklasse', 'Bezahlt', 'Bezahlt am', 'Nachweis-Typ', 'Turnier 1', 'Turnier 2', 'Turnier 3', 'ÖM-Jahr', 'ÖM-Turnier', 'Angemeldet am', 'Zustimmung Datenschutz am', 'Zustimmung Foto/Film'], ';', '"', '');
    foreach (filter_entries($ev, event_entries($ev), entry_filter_params($_GET)) as $r)
        fputcsv($o, array_map($cell, [ref_code((int)$ev['id'], (int)$r['id']), $status[$r['id']] ?? '', $r['no'], $r['lastname'], $r['firstname'], $r['sex'] ? 'W' : 'M', dob_val($r['dob']), $r['club'], $r['club_code'], $r['country'], $r['license'], $r['email'],
            $r['division'], $r['class'], $r['paid'] ? 'ja' : 'nein', $r['paid_at'], $r['qtype'], $r['q1'], $r['q2'], $r['q3'], $r['qy'], $r['qe'], $r['created'], $r['consent_at'], $r['consent_media'] ? 'ja' : 'nein']), ';', '"', '');
    exit;
}
if ($p === 'statscsv') {
    $ev = get_event((int)($_GET['id'] ?? 0)); if (!can_view($ev)) deny();
    [$st, $opt, $sl] = stats_data($ev, entry_filter_params($_GET));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="statistik-turnier' . $ev['id'] . '-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    $cl = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v;      // CSV-Injection vermeiden
    $line = fn(string $what, string $ses, string $div, string $cls, array $c) => fputcsv($o, array_map($cl, [$what, $ses, $div, $cls, $c['n'], $c['paid'], $c['open'], $c['starter'], $c['wait']]), ';', '"', '');
    fputcsv($o, ['Auswertung', 'Durchgang', 'Bogenklasse', 'Altersklasse', 'Anmeldungen', 'Bezahlt', 'Nicht bezahlt', 'Starter', 'Warteliste'], ';', '"', '');
    $line('Gesamt', '', '', '', $st['total']);
    foreach ($sl as $x) if (isset($st['ses'][$x['id']])) $line('Durchgang', $x['no'] . ($x['name'] !== '' ? ' – ' . $x['name'] : ''), '', '', $st['ses'][$x['id']]);
    $dk = stat_keys(array_keys($st['div']), $opt['div']); $ck = stat_keys(array_keys($st['cls']), $opt['cls']);
    foreach ($dk as $d) $line('Bogenklasse', '', stat_label($d, $opt['div']), '', $st['div'][$d]);
    foreach ($ck as $c) $line('Altersklasse', '', '', stat_label($c, $opt['cls']), $st['cls'][$c]);
    foreach ($dk as $d) foreach (stat_keys(array_keys($st['combo'][$d]), $opt['cls']) as $c) $line('Kombination', '', stat_label($d, $opt['div']), stat_label($c, $opt['cls']), $st['combo'][$d][$c]);
    exit;
}
$nav = '<a href="admin.php">Turniere</a><a href="admin.php?p=log">Protokoll</a>' . (is_admin() ? '<a href="admin.php?p=users">Benutzer</a><a href="admin.php?p=master">Stammdaten</a><a href="admin.php?p=settings">Einstellungen</a><a href="admin.php?p=check">Schema-Check</a><a href="diag.php">Diagnose</a>' : '')
     . '<a href="admin.php?p=user&id=' . uid() . '">Mein Konto</a><a href="index.php">Öffentliche Seite</a><a href="admin.php?p=logout">Logout (' . e($_SESSION['un']) . ', ' . e(ROLES[role()] ?? '') . ')</a>';
if ($p === 'logcsv') {
    [$evs, $fe, $fa, $fq, $w, $par] = log_scope();
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="protokoll-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Zeit', 'Turnier', 'Aktion', 'Wer', 'Details'], ';', '"', '');
    foreach (q("SELECT l.*, e.title FROM reg_log l LEFT JOIN reg_events e ON e.id=l.event_id $w ORDER BY l.id DESC LIMIT 5000", $par) as $r)
        fputcsv($o, array_map(fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v, [$r['ts'], $r['title'] ?? '', LOG_ACTIONS[$r['action']] ?? $r['action'], $r['actor'], $r['details']]), ';', '"', '');
    exit;
}
$navKey = in_array($p, ['events', 'event', 'entries', 'edit', 'stats', 'mail'], true) ? '' : ($p === 'athlete' ? 'master' : $p);               // aktuelle Seite im Menü hervorheben
$nav = $navKey === '' ? preg_replace('~<a href="admin\.php">~', '<a class="on" href="admin.php">', $nav, 1)
     : preg_replace('~<a href="admin\.php\?p=' . preg_quote($navKey, '~') . '([&"])~', '<a class="on" href="admin.php?p=' . $navKey . '$1', $nav, 1);
page_head('Backend', $nav);
try {

// ---- Turnierliste -----------------------------------------------------------
if ($p === 'events') {
    $sql = "SELECT v.*,u.username,u.fullname FROM reg_events v LEFT JOIN reg_users u ON u.id=v.owner_id";
    if (role() === 'orga') $rows = q("$sql WHERE v.owner_id=? ORDER BY v.id DESC", [uid()])->fetchAll();
    elseif (role() === 'auswerter') $rows = q("$sql WHERE v.owner_id=? OR v.id IN (SELECT event_id FROM reg_event_scorers WHERE user_id=?) ORDER BY v.id DESC", [uid(), uid()])->fetchAll();
    elseif (role() === 'kassier') $rows = q("$sql WHERE v.id IN (SELECT event_id FROM reg_event_users WHERE user_id=?) ORDER BY v.id DESC", [uid()])->fetchAll();
    else $rows = q("$sql ORDER BY v.id DESC")->fetchAll();
    echo '<h2>Turniere</h2><div class="box scroll"><table><thead><tr><th>Titel</th><th>Status</th><th>Organisationsleitung</th><th></th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="4" class="empty">Keine Turniere' . (in_array(role(), ['kassier', 'auswerter'], true) ? ' zugewiesen' : '') . '.</td></tr>';
    foreach ($rows as $r) {
        echo '<tr><td>' . e($r['title']) . ($r['kind'] === 'oem' ? ' <span class="tag">ÖM/ÖSTM</span>' : '') . '</td><td><span class="tag">' . ($r['published'] ? 'öffentlich' : 'unveröffentlicht') . '</span> <span class="tag ' . (event_is_open($r) ? 'paid' : 'open') . '">' . (event_state($r) === 'offen' ? 'Anmeldung offen' : event_state($r)) . '</span></td>'
           . '<td>' . e($r['fullname'] ?: $r['username'] ?: '–') . '</td><td>'
           . (can_event($r) ? '<a href="?p=event&id=' . $r['id'] . '">Einstellungen</a> · ' : '') . '<a href="?p=entries&id=' . $r['id'] . '">Anmeldungen</a> · <a href="?p=stats&id=' . $r['id'] . '">Statistik</a> · <a href="?p=csv&id=' . $r['id'] . '">CSV</a>' . (can_cash($r) ? ' · <a href="?p=mail&id=' . $r['id'] . '">Rundmail</a>' : '')
           . (can_event($r) ? ' · <form method="post" class="inl">' . tf() . '<input type="hidden" name="a" value="toggle_event"><input type="hidden" name="id" value="' . $r['id'] . '"><button class="sm ghost">' . (event_starts_later($r) ? 'Jetzt öffnen' : ($r['is_open'] ? 'Anmeldung schließen' : 'Anmeldung öffnen')) . '</button></form>' : '')
           . (can_event($r) ? ' · <form method="post" class="inl">' . tf() . '<input type="hidden" name="a" value="del_event"><input type="hidden" name="id" value="' . $r['id'] . '"><button class="sm dng" onclick="return confirm(\'Turnier samt allen Anmeldungen aus der Anmeldung entfernen? Daten in ianseo bleiben unverändert. Vorher CSV exportieren!\')">Entfernen</button></form>' : '')
           . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if (can_create_event()) {
        echo '<h3>Turnieranmeldung anlegen</h3><form method="post" class="stack">' . tf() . '<input type="hidden" name="a" value="add_event"><label>ianseo-Turnier<select name="to_id" required>';
        foreach (q("SELECT ToId,ToCode,ToName,ToWhenFrom FROM Tournament WHERE ToId NOT IN (SELECT to_id FROM reg_events) ORDER BY ToWhenFrom DESC") as $t)
            echo '<option value="' . $t['ToId'] . '">' . e($t['ToName'] . ' (' . $t['ToCode'] . ', ' . $t['ToWhenFrom'] . ')') . '</option>';
        echo '</select></label><label>Art<select name="kind"><option value="normal">Normales Turnier</option><option value="oem">ÖM/ÖSTM (mit Qualifikationsnachweis)</option></select></label><button>Anlegen</button></form>';
    }
}

// ---- Turnier-Einstellungen ---------------------------------------------------
if ($p === 'event') {
    $ev = get_event((int)($_GET['id'] ?? 0)); if (!can_event($ev)) deny();
    $o = ianseo_opts((int)$ev['to_id']);
    $ses = []; foreach (q("SELECT * FROM reg_sessions WHERE event_id=?", [$ev['id']]) as $s) $ses[$s['no']] = $s;
    $assigned = q("SELECT user_id FROM reg_event_users WHERE event_id=?", [$ev['id']])->fetchAll(PDO::FETCH_COLUMN);
    event_head($ev); ?>
    <form method="post" enctype="multipart/form-data"><?= tf() ?><input type="hidden" name="a" value="save_event"><input type="hidden" name="id" value="<?= $ev['id'] ?>">
    <h3>Grunddaten</h3>
    <div class="box grid">
      <label class="wide">Titel<input name="title" value="<?= e($ev['title']) ?>" required></label>
      <label>Art<select name="kind"><option value="normal" <?= $ev['kind'] === 'oem' ? '' : 'selected' ?>>Normales Turnier</option><option value="oem" <?= $ev['kind'] === 'oem' ? 'selected' : '' ?>>ÖM/ÖSTM (mit Nachweis)</option></select></label>
      <label>Anmeldestart (optional)<input type="date" name="reg_start" value="<?= e($ev['reg_start'] ?? '') ?>"><small class="hint">Vor diesem Tag ist keine Anmeldung möglich.</small></label>
      <label>Anmeldeschluss<input type="date" name="deadline" value="<?= e($ev['deadline']) ?>"><small class="hint">Anmeldung möglich bis einschließlich 23:59 Uhr dieses Tages.</small></label>
      <div class="wide checks col">
        <label class="chk"><input type="checkbox" name="is_open" <?= $ev['is_open'] ? 'checked' : '' ?>> Anmeldung offen</label>
        <label class="chk"><input type="checkbox" name="auto_open" <?= !empty($ev['auto_open']) ? 'checked' : '' ?>> Zum Anmeldestart automatisch auf „Anmeldung offen“ schalten</label>
        <label class="chk"><input type="checkbox" name="published" <?= $ev['published'] ? 'checked' : '' ?>> Öffentlich sichtbar</label>
        <label class="chk"><input type="checkbox" name="allow_nolic" <?= $ev['allow_nolic'] ? 'checked' : '' ?>> Lizenzfreie Teilnahme möglich</label>
      </div>
      <p class="info wide">Mit Anmeldestart ist die Anmeldung erst ab diesem Tag möglich. „Automatisch auf offen schalten“ setzt „Anmeldung offen“ am Anmeldestart selbst (einmalig, ein späteres manuelles Schließen bleibt bestehen); die Option gilt, solange „Anmeldung offen“ nicht angehakt ist. Lizenzfreie Teilnahme: Haken „Keine Lizenz“ im Formular, Nummern LL1, LL2 …</p>
    </div>
    <h3>Ausschreibung und Logo</h3>
    <div class="box grid">
      <label>Ausschreibung hochladen (PDF, max. 10 MB)<input type="file" name="doc" accept="application/pdf"></label>
      <label>Oder Link zur Ausschreibung<input type="url" name="doc_url" maxlength="300" value="<?= e($ev['doc_url']) ?>" placeholder="https://…"></label>
      <?php if ($ev['doc']) { ?><label class="chk"><input type="checkbox" name="rm_doc"> Hochgeladene Ausschreibung entfernen (<a href="uploads/<?= e($ev['doc']) ?>" target="_blank" rel="noopener">ansehen</a>)</label><?php } ?>
      <label>Turnierlogo (PNG/JPG/WebP, max. 2 MB) – erscheint neben den Turnierdaten<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
      <?php if ($ev['logo']) { ?><label class="chk"><input type="checkbox" name="rm_logo"> Turnier-Logo entfernen</label><?php } ?>
    </div>
    <h3>Verantwortliche</h3>
    <div class="box grid">
      <?php if (is_admin()) { ?><label>Organisationsleitung<select name="owner_id"><option value="0">–</option>
        <?php foreach (q("SELECT id,username,fullname FROM reg_users WHERE role IN ('admin','orga') ORDER BY fullname") as $u) echo '<option value="' . $u['id'] . '"' . ($u['id'] == $ev['owner_id'] ? ' selected' : '') . '>' . e($u['fullname'] ?: $u['username']) . '</option>'; ?>
      </select></label><?php } ?>
      <div class="wide"><strong>Kassier:innen für dieses Turnier freigeben (höchstens 2)</strong><div class="divs">
        <?php $ks = q("SELECT id,username,fullname FROM reg_users WHERE role='kassier' ORDER BY fullname")->fetchAll();
        if (!$ks) echo '<span class="empty">Noch keine Benutzer mit der Rolle Kassier:in angelegt.</span>';
        foreach ($ks as $u) echo '<label class="chk"><input type="checkbox" class="ksc" name="kassier[]" value="' . $u['id'] . '"' . (in_array($u['id'], $assigned) ? ' checked' : '') . ' onchange="if(document.querySelectorAll(\'.ksc:checked\').length>2){this.checked=false;alert(\'Höchstens 2 Kassier:innen je Turnier.\')}"> ' . e($u['fullname'] ?: $u['username']) . '</label>'; ?>
      </div></div>
      <?php if (can_event($ev)) { ?><div class="wide"><strong>Auswertung für dieses Turnier (höchstens 3)</strong>
        <p class="info">Gleiche Rechte wie die Organisationsleitung für dieses Turnier. Die Auswertung wird auf der öffentlichen Seite nicht angezeigt.</p><div class="divs">
        <?php $au = q("SELECT id,username,fullname FROM reg_users WHERE role='auswerter' ORDER BY fullname")->fetchAll();
        $asg = array_map('intval', q("SELECT user_id FROM reg_event_scorers WHERE event_id=?", [$ev['id']])->fetchAll(PDO::FETCH_COLUMN));
        if (!$au) echo '<span class="empty">Noch keine Benutzer mit der Rolle Auswertung angelegt (Benutzer → Neuen Benutzer anlegen).</span>';
        foreach ($au as $u) echo '<label class="chk"><input type="checkbox" class="awc" name="auswerter[]" value="' . $u['id'] . '"' . (in_array((int)$u['id'], $asg, true) ? ' checked' : '') . ' onchange="if(document.querySelectorAll(\'.awc:checked\').length>3){this.checked=false;alert(\'Höchstens 3 Personen der Auswertung je Turnier.\')}"> ' . e($u['fullname'] ?: $u['username']) . '</label>'; ?>
      </div></div><?php } ?>
    </div>
    <h3>Texte</h3>
    <div class="box grid">
      <label class="wide">Infotext (öffentlich)<textarea name="info" rows="3"><?= e($ev['info']) ?></textarea></label>
      <label class="wide">Verwendungszweck<input name="ref_text" maxlength="200" value="<?= e($ev['ref_text']) ?>" placeholder="z. B. Krampusturnier 2026 {nachname} {vorname}"><small class="hint">Freitext mit den Platzhaltern {ref} {vorname} {nachname}. Leer = Referenznummer.</small></label>
      <label class="wide">Zahlungsinfo<textarea name="pay_info" rows="3"><?= e($ev['pay_info']) ?></textarea><small class="hint">IBAN, Betrag … – wird nach der Anmeldung gezeigt.</small></label>
      <label>Nenngeld in EUR<input name="fee" maxlength="12" inputmode="decimal" value="<?= e($ev['fee']) ?>" placeholder="z. B. 25,00"><small class="hint">Optional. Für den PayPal-Link und die Mail-Variable {nenngeld}.</small></label>
      <label>PayPal.Me-Name<input name="paypal_me" maxlength="80" value="<?= e($ev['paypal_me']) ?>" placeholder="z. B. paypal.me/meinverein"><small class="hint">Optional, alternative Zahlung.</small></label>
    </div>
    <h3>Durchgänge (max. 10)</h3>
    <?php $ia = q("SELECT SesOrder FROM Session WHERE SesTournament=? AND SesType='Q' ORDER BY SesOrder", [$ev['to_id']])->fetchAll(PDO::FETCH_COLUMN); ?>
    <div class="kv"><span>In ianseo angelegte Qualifikations-Durchgänge</span><div><?= $ia ? e(implode(', ', $ia)) : 'keine' ?></div></div><p class="info">Aktiviere hier nur Nummern, die es in ianseo gibt. Ohne Haken bei den Bogenklassen sind alle zugelassen.</p>
    <div class="box scroll"><table><thead><tr><th>Nr.</th><th>Aktiv</th><th>Bezeichnung</th><th>Plätze (0 = unbegrenzt)</th><th>Anmeldung geschlossen</th><th>Eigener Anmeldeschluss (optional, nur früher)</th><th>Zugelassene Bogenklassen</th></tr></thead><tbody>
    <?php foreach (range(1, 10) as $n) { $s = $ses[$n] ?? null; $sel = $s && $s['divisions'] !== '' ? explode(',', $s['divisions']) : []; ?>
      <tr><td><?= $n ?></td><td><input type="checkbox" name="s[<?= $n ?>][on]" value="1" <?= $s ? 'checked' : '' ?>></td>
      <td><input name="s[<?= $n ?>][name]" value="<?= e($s['name'] ?? '') ?>" placeholder="z. B. Samstag Vormittag"></td>
      <td><input type="number" min="0" name="s[<?= $n ?>][cap]" value="<?= e($s['capacity'] ?? 0) ?>"></td>
      <td><input type="checkbox" name="s[<?= $n ?>][closed]" value="1" <?= !empty($s['closed']) ? 'checked' : '' ?>></td>
      <td><input type="date" name="s[<?= $n ?>][dl]" value="<?= e($s['deadline'] ?? '') ?>"></td>
      <td><div class="divs"><?php foreach ($o['div'] as $k => $d) echo '<label class="chk" title="' . e($d) . '"><input type="checkbox" name="s[' . $n . '][div][]" value="' . e($k) . '"' . (in_array((string)$k, $sel, true) ? ' checked' : '') . '> ' . e($k . ' ' . $d) . '</label>'; ?></div></td></tr>
    <?php } ?></tbody></table></div>
    <h3>E-Mail-Texte (optional)</h3>
    <?php $tp = json_decode((string)($ev['mail_tpl'] ?? ''), true) ?: []; $tv = fn($k, $l, $f) => e($tp[$k][$l][$f] ?? ''); ?>
    <div class="box">
      <p class="info">Leer = Standardtext. Platzhalter: <code>{vorname}</code> <code>{nachname}</code> <code>{name}</code> <code>{turnier}</code> <code>{durchgang}</code> <code>{ref}</code> <code>{verwendungszweck}</code> <code>{zahlungsinfo}</code> <code>{paypal}</code> <code>{verein}</code> <code>{lizenz}</code> <code>{bogenklasse}</code> <code>{altersklasse}</code> <code>{anmeldeschluss}</code> <code>{anmeldestart}</code> <code>{nenngeld}</code> <code>{organisation}</code> (Groß-/Kleinschreibung egal). Ist nur eine Sprache ausgefüllt, wird sie auch für die andere verwendet. Reiner Text, Zeilenumbrüche bleiben erhalten. Nach dem Speichern kannst du unten eine Testmail an dich senden.</p>
      <?php foreach (['reg' => 'E-Mail nach der Anmeldung (Eingangsbestätigung)', 'ok' => 'E-Mail bei bestätigtem Startplatz (nach Zahlungseingang)'] as $k => $lbl) { ?>
      <fieldset><legend><?= e($lbl) ?></legend><div class="grid">
        <label>Betreff (Deutsch)<input name="tpl[<?= $k ?>][de][s]" maxlength="200" value="<?= $tv($k, 'de', 's') ?>" placeholder="Standard: Anmeldung eingegangen: {turnier}"></label>
        <label>Betreff (Englisch)<input name="tpl[<?= $k ?>][en][s]" maxlength="200" value="<?= $tv($k, 'en', 's') ?>"></label>
        <label>Text (Deutsch)<textarea rows="7" name="tpl[<?= $k ?>][de][b]"><?= $tv($k, 'de', 'b') ?></textarea></label>
        <label>Text (Englisch)<textarea rows="7" name="tpl[<?= $k ?>][en][b]"><?= $tv($k, 'en', 'b') ?></textarea></label>
      </div></fieldset>
      <?php } ?>
    </div>
    <button>Speichern und mit ianseo abgleichen</button></form>
    <h3>Testmail</h3>
    <form method="post" class="box grid"><?= tf() ?><input type="hidden" name="a" value="mail_tpl_test"><input type="hidden" name="id" value="<?= $ev['id'] ?>">
      <label>Testmail: Art<select name="kind"><option value="reg">Nach der Anmeldung</option><option value="ok">Startplatz bestätigt</option></select></label>
      <label>Sprache<select name="lang"><option value="de">Deutsch</option><option value="en">Englisch</option></select></label>
      <div class="checks"><button>Testmail an meine Adresse senden</button></div><p class="info wide">Es werden die zuletzt gespeicherten Texte mit Beispieldaten verwendet. Ohne eigenen Text erscheint der Standardtext.</p></form>
    <?php $nc = (int)q("SELECT COUNT(*) FROM reg_clubs")->fetchColumn();
    if ($nc) { $have = array_flip(array_column(q("SELECT CoCode FROM Countries WHERE CoTournament=?", [$ev['to_id']])->fetchAll(), 'CoCode')); $miss = 0;
        foreach (q("SELECT code FROM reg_clubs")->fetchAll() as $c) if (!isset($have[substr($c['code'], 0, 10)])) $miss++; ?>
    <h3>Vereine</h3>
    <form method="post" class="box"><?= tf() ?><input type="hidden" name="a" value="clubs_to_ianseo"><input type="hidden" name="id" value="<?= $ev['id'] ?>">
      <div class="kv"><span>Vereinsliste</span><div><?= $nc ?> Vereine, davon fehlen <?= $miss ?> in diesem ianseo-Turnier</div></div>
      <button<?= $miss ? '' : ' disabled' ?>>Fehlende Vereine in ianseo anlegen</button></form>
    <?php } ?>
    <h3>Turnier entfernen</h3>
    <form method="post" class="inl"><?= tf() ?><input type="hidden" name="a" value="del_event"><input type="hidden" name="id" value="<?= $ev['id'] ?>">
      <p class="info">Entfernt Turnier, Durchgänge, Anmeldungen und hochgeladene Dateien aus der Anmeldeplattform. In ianseo und auf dem Server (außer den hochgeladenen Dateien dieses Turniers) wird nichts verändert. <a href="?p=csv&id=<?= $ev['id'] ?>">Vorher CSV exportieren</a>.</p>
      <button class="dng" onclick="return confirm('Wirklich aus der Anmeldung entfernen?')">Aus der Anmeldung entfernen</button></form>
    <?php
}

// ---- Anmeldungen --------------------------------------------------------------
if ($p === 'entries') {
    $ev = get_event((int)($_GET['id'] ?? 0)); if (!can_view($ev)) deny();
    $cash = can_cash($ev); $oem = $ev['kind'] === 'oem';
    $status = statuses((int)$ev['id']);
    event_head($ev);
    $f = entry_filter_params($_GET); $fq = entry_filter_query($f);                    // Filter und Suche (GET)
    $allRows = event_entries($ev); $rows = filter_entries($ev, $allRows, $f);
    if (can_event($ev)) {                                               // Anmeldung je Durchgang öffnen/schließen (Administrator, Organisationsleitung)
        $sx = q("SELECT * FROM reg_sessions WHERE event_id=? ORDER BY no", [$ev['id']])->fetchAll();
        $tg = fn($a, $i, $label) => '<form method="post" class="inl">' . tf() . '<input type="hidden" name="a" value="' . $a . '"><input type="hidden" name="id" value="' . $i . '"><input type="hidden" name="back" value="entries"><button class="sm ghost">' . $label . '</button></form>';
        echo '<div class="box"><h4>Anmeldung öffnen / schließen</h4><div class="kv"><span>Gesamtes Turnier</span><div><span class="tag ' . (event_is_open($ev) ? 'paid' : 'open') . '">' . event_state($ev) . '</span> ' . $tg('toggle_event', $ev['id'], event_starts_later($ev) ? 'Jetzt öffnen' : ($ev['is_open'] ? 'Anmeldung schließen' : 'Anmeldung öffnen')) . '</div></div>'
           . '<div class="scroll"><table><thead><tr><th>Durchgang</th><th>Anmeldung</th><th></th></tr></thead><tbody>';
        foreach ($sx as $x) echo '<tr><td>' . $x['no'] . ($x['name'] ? ' – ' . e($x['name']) : '') . '</td><td><span class="tag ' . (session_is_open($ev, $x) ? 'paid' : 'open') . '">' . (session_is_open($ev, $x) ? 'offen' : (!empty($x['closed']) ? 'geschlossen' : (event_is_open($ev) ? 'geschlossen (Anmeldeschluss)' : event_state($ev)))) . '</span></td><td>' . $tg('toggle_session', $x['id'], !empty($x['closed']) ? 'Anmeldung öffnen' : 'Anmeldung schließen') . '</td></tr>';
        echo '</tbody></table></div></div>';
    }
    echo '<form method="post" class="inl">' . tf() . '<input type="hidden" name="a" value="sync"><input type="hidden" name="id" value="' . $ev['id'] . '"><button class="sm">Mit ianseo abgleichen</button></form> '
       . ($cash && athletes_loaded() ? '<form method="post" class="inl">' . tf() . '<input type="hidden" name="a" value="athlete_sync"><input type="hidden" name="id" value="' . $ev['id'] . '"><button class="sm">Schützenabgleich (Lizenzen)</button></form> ' : '')
       . '<a href="?p=csv&id=' . $ev['id'] . ($fq !== '' ? '&' . $fq : '') . '">CSV exportieren' . ($fq !== '' ? ' (gefiltert)' : '') . '</a> · <a href="?p=stats&id=' . $ev['id'] . '">Statistik</a> · '
       . ($cash ? '<a href="?p=mail&id=' . $ev['id'] . ($fq !== '' ? '&' . $fq : '') . '">Rundmail' . ($fq !== '' ? ' an Filterauswahl' : '') . '</a> · ' : '') . '<a href="?p=log&id=' . $ev['id'] . '">Protokoll</a>'
       . filter_form($ev, 'entries', $allRows, $f, 'Filtern')
       . '<p class="info">' . ($fq !== '' ? count($rows) . ' von ' . count($allRows) . ' Anmeldungen (gefiltert)' : count($allRows) . ' Anmeldungen') . '</p>'
       . '<div class="box scroll"><table><thead><tr><th>Durchgang</th><th>Name</th><th>Verein</th><th>Lizenz</th><th>Bogen-/Altersklasse</th><th>E-Mail</th>' . ($oem ? '<th>Nachweis</th>' : '') . '<th>Status</th><th>Zahlung</th><th>in ianseo</th>' . ($cash ? '<th></th>' : '') . '</tr></thead><tbody>';
    $sl = q("SELECT * FROM reg_sessions WHERE event_id=? ORDER BY no", [$ev['id']])->fetchAll();
    if (!$rows) echo '<tr><td colspan="11" class="empty">' . ($allRows ? 'Keine Anmeldung entspricht dem Filter.' : 'Noch keine Anmeldungen.') . '</td></tr>';
    foreach ($rows as $r) {
        $btn = fn($act, $v, $label, $cls = '') => '<form method="post" class="inl">' . tf() . '<input type="hidden" name="a" value="' . $act . '"><input type="hidden" name="id" value="' . $r['id'] . '"><input type="hidden" name="v" value="' . $v . '"><input type="hidden" name="ret" value="' . e($fq) . '"><button class="sm ' . $cls . '"' . ($act === 'del_entry' ? ' onclick="return confirm(\'Wirklich löschen?\')"' : '') . '>' . $label . '</button></form>';
        if ($cash) {
            $mv = '<form method="post" class="inl">' . tf() . '<input type="hidden" name="a" value="move"><input type="hidden" name="id" value="' . $r['id'] . '"><input type="hidden" name="ret" value="' . e($fq) . '"><select name="sid" onchange="this.form.submit()" aria-label="Durchgang ändern">';
            foreach ($sl as $s) $mv .= '<option value="' . $s['id'] . '"' . ($s['id'] == $r['session_id'] ? ' selected' : '') . '>' . $s['no'] . ($s['name'] ? ' – ' . e($s['name']) : '') . '</option>';
            $mv .= '</select></form>';
        } else $mv = $r['no'] . ($r['sname'] ? ' – ' . e($r['sname']) : '');
        echo '<tr><td>' . $mv . '</td><td>' . e($r['lastname'] . ' ' . $r['firstname']) . '<br><small>' . e(dob_val($r['dob']) ?: '–') . ' · ' . ref_code((int)$ev['id'], (int)$r['id']) . '</small></td><td>' . e($r['club'] . ($r['country'] !== '' ? ' (' . $r['country'] . ')' : '')) . '</td><td>' . e($r['license'] ?: '–') . lic_tag((string)$r['lic_check']) . '</td><td>' . e($r['division'] . ' / ' . $r['class']) . '</td><td>' . e($r['email']) . '</td>'
           . ($oem ? '<td><small>' . e(qual_text($r)) . '</small></td>' : '')
           . '<td><span class="tag ' . (($status[$r['id']] ?? '') === 'Starter' ? 'paid' : '') . '">' . ($status[$r['id']] ?? '?') . '</span></td>'
           . '<td>' . ($cash ? ($r['paid'] ? $btn('paid', 0, 'bezahlt ✓ (zurücksetzen)') : $btn('paid', 1, 'als bezahlt markieren')) : ($r['paid'] ? 'bezahlt' : 'offen')) . '</td>'
           . '<td>' . ($r['en_id'] ? 'ja (EnId ' . $r['en_id'] . ')' : '–') . '</td>'
           . ($cash ? '<td><a href="?p=edit&id=' . $r['id'] . '">Bearbeiten</a> ' . $btn('del_entry', 0, 'Löschen', 'dng') . '</td>' : '') . '</tr>';
    }
    echo '</tbody></table></div>';
    if (can_event($ev)) echo '<h3>Anmeldungen importieren</h3><form method="post" enctype="multipart/form-data" class="box stack">' . tf() . '<input type="hidden" name="a" value="import_entries"><input type="hidden" name="id" value="' . $ev['id'] . '">'
       . '<p class="info">CSV im Format des Exports (Spalten u. a. Durchgang, Nachname, Vorname, Geschlecht, Geburtsdatum, Verein, Vereinsnummer, Lizenz, E-Mail, Bogenklasse, Altersklasse, Bezahlt). Der Import gilt nur für dieses Turnier, Duplikate (gleicher Name, Geburtsdatum und Durchgang) werden übersprungen, es werden keine Mails versendet. Bezahlte Starter werden mit vorhandenen ianseo-Einträgen verknüpft, sonst neu angelegt.</p>'
       . '<label>CSV-Datei<input type="file" name="csv" accept=".csv,.txt,text/csv" required></label><button>Importieren</button></form>';
}

// ---- Anmeldung bearbeiten ----------------------------------------------------
if ($p === 'edit') {
    $r = q("SELECT * FROM reg_entries WHERE id=?", [(int)($_GET['id'] ?? 0)])->fetch() ?: exit('Anmeldung nicht gefunden');
    $ev = get_event((int)$r['event_id']); if (!can_cash($ev)) deny();
    $o = ianseo_opts((int)$ev['to_id']);
    $pick = function (string $name, array $list, string $cur): string {
        if (!$list) return '<input name="' . $name . '" value="' . e($cur) . '" maxlength="10" required>';
        if ($cur !== '' && !isset($list[$cur])) $list[$cur] = $cur . ' (nicht in ianseo)';
        $h = '<select name="' . $name . '">';
        foreach ($list as $k => $d) $h .= '<option value="' . e($k) . '"' . ((string)$k === $cur ? ' selected' : '') . '>' . e($k . ' – ' . $d) . '</option>';
        return $h . '</select>';
    };
    echo '<h2>Anmeldung bearbeiten</h2><p class="info">' . e($ev['title']) . ($r['en_id'] ? ' · bereits in ianseo (Änderungen werden dorthin übernommen)' : '') . '</p>'
       . '<form method="post" class="box grid">' . tf() . '<input type="hidden" name="a" value="save_entry"><input type="hidden" name="id" value="' . $r['id'] . '">'
       . '<label>Vorname<input name="firstname" maxlength="30" value="' . e($r['firstname']) . '" required></label>'
       . '<label>Nachname<input name="lastname" maxlength="30" value="' . e($r['lastname']) . '" required></label>'
       . '<label>Geschlecht<select name="sex"><option value="0"' . ($r['sex'] ? '' : ' selected') . '>Männlich</option><option value="1"' . ($r['sex'] ? ' selected' : '') . '>Weiblich</option></select></label>'
       . '<label>Geburtsdatum (leer = unbekannt)<input type="date" name="dob" value="' . e(dob_val($r['dob'])) . '"></label>'
       . '<label>Verein<input name="club" list="clubs" maxlength="80" value="' . e($r['club']) . '"></label><datalist id="clubs">';
    $names = array_column(q("SELECT name FROM reg_clubs ORDER BY name")->fetchAll(), 'name');
    foreach (q("SELECT DISTINCT CoName FROM Countries WHERE CoTournament=? AND CoName<>'' ORDER BY CoName", [$ev['to_id']]) as $c) $names[] = $c['CoName'];
    foreach (array_unique($names) as $nm) echo '<option value="' . e($nm) . '">';
    echo '</datalist><label>Lizenznummer<input name="license" maxlength="25" value="' . e($r['license']) . '"></label>'
       . '<label>Land (IOC-Code, nur bei Ausländern)<input name="country" maxlength="3" value="' . e($r['country']) . '" style="text-transform:uppercase"></label>'
       . '<label>E-Mail<input type="email" name="email" value="' . e($r['email']) . '" required></label>'
       . '<label>Bogenklasse' . $pick('division', $o['div'], $r['division']) . '</label>'
       . '<label>Altersklasse' . $pick('class', $o['cls'], $r['class']) . '</label>';
    if ($ev['kind'] === 'oem') echo '<label>Nachweis-Option<select name="qtype"><option value="0">–</option><option value="1"' . ((int)$r['qtype'] === 1 ? ' selected' : '') . '>1: drei Turniere</option><option value="2"' . ((int)$r['qtype'] === 2 ? ' selected' : '') . '>2: Teilnahme an ÖM/ÖSTM</option></select></label>'
       . '<label>Turnier 1<input name="q1" maxlength="150" value="' . e($r['q1']) . '"></label><label>Turnier 2<input name="q2" maxlength="150" value="' . e($r['q2']) . '"></label><label>Turnier 3<input name="q3" maxlength="150" value="' . e($r['q3']) . '"></label>'
       . '<label>ÖM/ÖSTM Jahr<input name="qy" maxlength="4" value="' . e($r['qy']) . '"></label><label>ÖM/ÖSTM Turnier<input name="qe" maxlength="150" value="' . e($r['qe']) . '"></label>';
    echo '<button class="wide">Speichern</button></form><p><a href="?p=entries&id=' . $ev['id'] . '">Zurück zur Liste</a></p>';
}

// ---- Benutzer ---------------------------------------------------------------
if ($p === 'users') {
    if (!is_admin()) deny();
    echo '<h2>Benutzer</h2><div class="box scroll"><table><thead><tr><th>Benutzername</th><th>Klarname</th><th>E-Mail</th><th>Rolle</th><th></th></tr></thead><tbody>';
    foreach (q("SELECT * FROM reg_users ORDER BY fullname, username") as $u)
        echo '<tr><td>' . e($u['username']) . '</td><td>' . e($u['fullname']) . '</td><td>' . e($u['email']) . '</td><td>' . e(ROLES[$u['role']] ?? $u['role']) . '</td><td><a href="?p=user&id=' . $u['id'] . '">Bearbeiten</a> '
           . ($u['id'] == uid() ? '(du)' : '<form method="post" class="inl">' . tf() . '<input type="hidden" name="a" value="del_user"><input type="hidden" name="id" value="' . $u['id'] . '"><button class="sm dng" onclick="return confirm(\'Benutzer löschen?\')">Löschen</button></form>') . '</td></tr>';
    echo '</tbody></table></div><h3>Neuen Benutzer anlegen</h3><form method="post" class="stack">' . tf() . '<input type="hidden" name="a" value="add_user">'
       . '<label>Benutzername<input name="u" required></label><label>Vor- und Nachname (Klarname)<input name="fullname" required></label><label>E-Mail<input type="email" name="email" required></label>'
       . '<label>Passwort (min. 6 Zeichen)<input type="password" name="pw" minlength="6" required></label><label>Rolle<select name="role">';
    foreach (ROLES as $k => $l) echo '<option value="' . $k . '">' . e($l) . '</option>';
    echo '</select></label><button>Anlegen</button></form>'
       . '<p class="info">Administrator: darf alles. Organisationsleitung: legt Turnieranmeldungen an, bearbeitet und löscht eigene Turniere, gibt Kassier:innen frei (höchstens 2 je Turnier) und vergibt die Auswertung (höchstens 3 je Turnier). Auswertung: gleiche Rechte wie die Organisationsleitung. Kassier:in: nur für freigegebene Turniere – bezahlt setzen, zurücksetzen, Starter bearbeiten und löschen.</p>';
}
if ($p === 'user') {
    $tid = is_admin() ? (int)($_GET['id'] ?? uid()) : uid();
    $u = q("SELECT * FROM reg_users WHERE id=?", [$tid])->fetch() ?: exit('Benutzer nicht gefunden');
    echo '<h2>' . ($tid === uid() ? 'Mein Konto' : 'Benutzer bearbeiten') . '</h2><form method="post" class="stack">' . tf() . '<input type="hidden" name="a" value="save_user"><input type="hidden" name="id" value="' . $u['id'] . '">'
       . '<label>Benutzername<input value="' . e($u['username']) . '" disabled></label><label>Vor- und Nachname (Klarname)<input name="fullname" value="' . e($u['fullname']) . '" required></label>'
       . '<label>E-Mail<input type="email" name="email" value="' . e($u['email']) . '" required></label>';
    if (is_admin() && $tid !== uid()) { echo '<label>Rolle<select name="role">'; foreach (ROLES as $k => $l) echo '<option value="' . $k . '"' . ($k === $u['role'] ? ' selected' : '') . '>' . e($l) . '</option>'; echo '</select></label>'; }
    echo '<label>Neues Passwort (leer = unverändert, min. 6 Zeichen)<input type="password" name="pw" minlength="6" autocomplete="new-password"></label><button>Speichern</button></form>';
}

// ---- Stammdaten: Schützen und Vereine (eine CSV) ---------------------------------------
if ($p === 'master') {
    if (!is_admin()) deny();
    $na = (int)q("SELECT COUNT(*) FROM reg_athletes")->fetchColumn(); $nc = (int)q("SELECT COUNT(*) FROM reg_clubs")->fetchColumn(); $sq = trim((string)($_GET['q'] ?? ''));
    echo '<h2>Stammdaten: Schützen und Vereine</h2><div class="box"><div class="kv"><span>Bestand</span><div><strong>' . $na . '</strong> Schützen, <strong>' . $nc . '</strong> Vereine</div></div>'
       . '<div class="kv"><span>Spalten der CSV</span><div><code>Lizenznummer; Vorname; Nachname; Vereinsnummer; Vereinsname; Bundeslandkürzel; Bundeslandname; Geschlecht (optional: M/W)</code></div></div>'
       . '<p class="info">Trennzeichen ; , oder Tab, UTF-8 oder Windows-1252, erste Zeile = Kopfzeile, Reihenfolge der Spalten egal; „Erf.Nr.“ und „VNR“ werden ebenfalls erkannt. Die Vereine ergeben sich aus den Zeilen der Schützen und erscheinen im Anmeldeformular als Auswahl. Die Vereinsnummer wird als Vereinscode in ianseo verwendet, der Name als „BL-Kürzel/Vereinsname“ (z. B. B/BC-Wiesfleck).</p>'
       . '<form method="post" enctype="multipart/form-data" class="stack">' . tf() . '<input type="hidden" name="a" value="import_master">'
       . '<label>CSV-Datei<input type="file" name="csv" accept=".csv,.txt,text/csv" required></label>'
       . '<label class="chk"><input type="radio" name="mode" value="merge" checked> Ergänzen / aktualisieren (bestehende Einträge bleiben)</label>'
       . '<label class="chk"><input type="radio" name="mode" value="replace"> Bisherige Listen komplett ersetzen</label><button>Importieren</button></form></div>'
       . '<form method="get" class="inl"><input type="hidden" name="p" value="master"><input name="q" value="' . e($sq) . '" placeholder="Lizenz, Name oder Verein suchen …"> <button class="sm">Suchen</button></form> <a class="btn" href="?p=athlete">+ Schützen hinzufügen</a>'
       . '<div class="box scroll"><table><thead><tr><th>Lizenz (anklicken = bearbeiten)</th><th>Nachname</th><th>Vorname</th><th>Geschl.</th><th>Vereinsnr.</th><th>Verein</th><th>BL-Kürzel</th><th>Bundesland</th></tr></thead><tbody>';
    $like = '%' . $sq . '%';
    $rows = q("SELECT a.license,a.lastname,a.firstname,a.sex,a.club_code,c.name,c.state_code,c.state FROM reg_athletes a LEFT JOIN reg_clubs c ON c.code=a.club_code
               WHERE ?='' OR a.license LIKE ? OR a.lastname LIKE ? OR a.firstname LIKE ? OR c.name LIKE ? ORDER BY a.lastname,a.firstname LIMIT 300", [$sq, $like, $like, $like, $like])->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as $r) { $lic = array_shift($r); $r[2] = $r[2] === null ? '–' : ((int)$r[2] ? 'W' : 'M'); echo '<tr><td><a href="?p=athlete&l=' . rawurlencode((string)$lic) . '">' . e($lic) . '</a></td><td>' . implode('</td><td>', array_map('e', $r)) . '</td></tr>'; }
    if (!$rows) echo '<tr><td colspan="8" class="empty">Keine Einträge.</td></tr>';
    echo '</tbody></table></div><p class="info">Angezeigt werden maximal 300 Einträge – die Suche filtert die gesamte Liste.</p>';
}

// ---- Protokoll der Anmeldungen ---------------------------------------------------------------
if ($p === 'log') {
    [$evs, $fe, $fa, $fq, $w, $par] = log_scope();
    $pg = max(1, (int)($_GET['pg'] ?? 1)); $per = 100;
    $total = (int)q("SELECT COUNT(*) FROM reg_log l $w", $par)->fetchColumn();
    $rows = q("SELECT l.*, e.title FROM reg_log l LEFT JOIN reg_events e ON e.id=l.event_id $w ORDER BY l.id DESC LIMIT $per OFFSET " . (($pg - 1) * $per), $par)->fetchAll();
    $qs = fn(array $o = []) => http_build_query(array_filter(['p' => 'log', 'id' => $fe ?: '', 'act' => $fa, 'q' => $fq] + $o, fn($v) => $v !== '' && $v !== null));
    echo '<h2>Protokoll der Anmeldungen</h2><form method="get" class="box grid"><input type="hidden" name="p" value="log">'
       . '<label>Turnier<select name="id"><option value="">Alle Turniere</option>';
    foreach ($evs as $x) echo '<option value="' . $x['id'] . '"' . ($fe == $x['id'] ? ' selected' : '') . '>' . e($x['title']) . '</option>';
    echo '</select></label><label>Aktion<select name="act"><option value="">Alle Aktionen</option>';
    foreach (LOG_ACTIONS as $k => $l) echo '<option value="' . $k . '"' . ($fa === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    echo '</select></label><label>Suche (Name, Benutzer, Details)<input name="q" value="' . e($fq) . '"></label><div class="checks"><button>Filtern</button> <a href="?' . e($qs(['p' => 'logcsv'])) . '">CSV exportieren</a></div></form>'
       . '<p class="info">' . $total . ' Einträge' . ($total > $per ? ', Seite ' . $pg . ' von ' . (int)ceil($total / $per) : '') . '. Das Protokoll wird nach spätestens 24 Monaten automatisch gelöscht.</p>'
       . '<div class="box scroll"><table><thead><tr><th>Zeit</th><th>Turnier</th><th>Aktion</th><th>Wer</th><th>Details</th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="5" class="empty">Keine Einträge.</td></tr>';
    foreach ($rows as $r) echo '<tr><td>' . e(date('d.m.Y H:i:s', (int)strtotime($r['ts']))) . '</td><td>' . e($r['title'] ?? '–') . '</td><td><span class="tag">' . e(LOG_ACTIONS[$r['action']] ?? $r['action']) . '</span></td><td>' . e($r['actor']) . '</td><td>' . e($r['details']) . '</td></tr>';
    echo '</tbody></table></div><p>' . ($pg > 1 ? '<a href="?' . e($qs(['pg' => $pg - 1])) . '">← neuere</a> ' : '') . ($pg * $per < $total ? '<a href="?' . e($qs(['pg' => $pg + 1])) . '">ältere →</a>' : '') . '</p>';
}

// ---- Schützen bearbeiten / hinzufügen ---------------------------------------------------
if ($p === 'athlete') {
    if (!is_admin()) deny();
    $l = trim((string)($_GET['l'] ?? '')); $a = $l !== '' ? athlete_by_license($l) : null;
    if ($l !== '' && !$a) echo '<p class="msg err">Schütze nicht gefunden.</p><p><a href="admin.php?p=master">Zurück zu den Stammdaten</a></p>';
    else {
        $cur = $a['club_code'] ?? '';
        echo '<h2>' . ($a ? 'Schützen bearbeiten' : 'Schützen hinzufügen') . '</h2><form method="post" class="box grid">' . tf() . '<input type="hidden" name="a" value="save_athlete"><input type="hidden" name="orig" value="' . e($l) . '">'
           . '<label>Lizenznummer<input name="license" maxlength="25" value="' . e($a['license'] ?? '') . '" required></label>'
           . '<label>Verein<select name="club_code"><option value="">– kein Verein –</option>';
        $known = false;
        foreach (q("SELECT code,name,state_code FROM reg_clubs ORDER BY name")->fetchAll() as $c) { if ((string)$c['code'] === (string)$cur) $known = true; echo '<option value="' . e($c['code']) . '"' . ((string)$c['code'] === (string)$cur ? ' selected' : '') . '>' . e($c['name'] . ($c['state_code'] !== '' ? ' (' . $c['state_code'] . ')' : '') . ' – ' . $c['code']) . '</option>'; }
        if ($cur !== '' && !$known) echo '<option value="' . e($cur) . '" selected>' . e($cur) . ' (nicht in der Vereinsliste)</option>';
        echo '</select></label><label>Vorname<input name="firstname" maxlength="60" value="' . e($a['firstname'] ?? '') . '" required></label>'
           . '<label>Nachname<input name="lastname" maxlength="60" value="' . e($a['lastname'] ?? '') . '" required></label>'
           . '<label>Geschlecht<select name="sex"><option value="">– unbekannt –</option><option value="0"' . (($a['sex'] ?? null) !== null && (int)$a['sex'] === 0 ? ' selected' : '') . '>Männlich</option><option value="1"' . (($a['sex'] ?? null) !== null && (int)$a['sex'] === 1 ? ' selected' : '') . '>Weiblich</option></select></label>'
           . '<p class="info wide">Die Änderung gilt für künftige Anmeldungen und Abgleiche. Bereits gespeicherte Anmeldungen bleiben unverändert (im Turnier kann der „Schützenabgleich“ sie aktualisieren).</p><button class="wide">Speichern</button></form>';
        if ($a) echo '<form method="post" class="box">' . tf() . '<input type="hidden" name="a" value="del_athlete"><input type="hidden" name="orig" value="' . e($l) . '"><button class="dng" onclick="return confirm(\'Schützen aus den Stammdaten löschen?\')">Schützen löschen</button></form>';
        echo '<p><a href="admin.php?p=master">Zurück zu den Stammdaten</a></p>';
    }
}

// ---- Einstellungen (Admin) ------------------------------------------------------------
if ($p === 'settings') {
    if (!is_admin()) deny();
    $mc = mail_cfg(); $sm = $mc['smtp'] ?? []; $im = $mc['imap'] ?? [];
    $ck = fn($b) => $b ? ' checked' : ''; $sl = fn($v, $o) => $v === $o ? ' selected' : '';
    $secSel = fn(string $name, string $cur) => '<select name="' . $name . '"><option value="tls"' . $sl($cur, 'tls') . '>TLS (STARTTLS)</option><option value="ssl"' . $sl($cur, 'ssl') . '>SSL</option><option value="none"' . $sl($cur, '') . '>keine</option></select>';
    echo '<h2>Einstellungen</h2>';
    $so = fn(string $k) => (($_GET['open'] ?? 'general') === $k) ? ' open' : '';
    echo '<details class="sect"' . $so('general') . '><summary>Allgemein</summary><div class="sect-body"><form method="post" class="box grid">' . tf() . '<input type="hidden" name="a" value="set_general"><label class="wide">Name der Plattform (Seitentitel in der Kopfzeile)<input name="site_title" maxlength="100" value="' . e(setting('site_title_hide') === '1' ? '' : site_title()) . '" placeholder="optional"><small class="hint">Optional. Leer = in der Kopfzeile erscheint nur das Logo (ohne Logo wird der Standardname gezeigt).</small></label><button class="wide">Speichern</button></form>'
       . '<form method="post" enctype="multipart/form-data" class="box stack">' . tf() . '<input type="hidden" name="a" value="set_logo"><div class="kv"><span>Seitenlogo (Kopfzeile, max. 100 px hoch und 500 px breit)</span><div>' . (setting('logo') ? logo_html() : '<span class="info">Kein Logo hochgeladen – es werden die Ringe angezeigt.</span>') . '</div></div>'
       . '<label>Logo hochladen (PNG/JPG/WebP, max. 2 MB)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label><button>Hochladen</button>' . (setting('logo') ? '<button name="rm_logo" value="1" class="dng">Logo entfernen (Ringe verwenden)</button>' : '') . '</form>';

    echo '</div></details>';
    echo '<details class="sect"' . $so('features') . '><summary>Funktionen</summary><div class="sect-body"><form method="post" class="box stack" style="max-width:none">' . tf() . '<input type="hidden" name="a" value="set_features">'
       . '<label class="chk"><input type="checkbox" name="autofill"' . $ck(setting('autofill') === '1') . '> Anmeldemaske: Name und Verein nach Eingabe der Lizenznummer automatisch ergänzen (benötigt die Stammdaten-CSV; Abfragen sind je IP begrenzt; wird in der Datenschutzerklärung erwähnt)</label>'
       . '<label class="chk"><input type="checkbox" name="media_required"' . $ck(setting('media_required') !== '0') . '> Zustimmung zu Foto-/Film-/Social-Media-Nutzung ist Pflicht für die Anmeldung (ohne Haken: freiwillig)</label>'
       . '<p class="info">Hinweis: Eine Einwilligung, die zur Bedingung der Teilnahme gemacht wird, kann datenschutzrechtlich angreifbar sein (freiwillige Einwilligung). Alternativ „freiwillig“ wählen und die Nutzung in der Ausschreibung regeln.</p><button>Speichern</button></form>';

    $ac = api_cfg();
    $ai = fn(string $name, string $k, string $label, string $ph = '') => '<label>' . $label . '<input name="' . $name . '" value="' . e($ac[$k]) . '" placeholder="' . e($ph) . '" autocomplete="off"></label>';
    echo '</div></details>';
    echo '<details class="sect"' . $so('api') . '><summary>Externe Lizenzprüfung (API)</summary><div class="sect-body"><form method="post" class="box" style="max-width:none">' . tf() . '<input type="hidden" name="a" value="set_api">'
       . '<p class="info">Optional: Lizenznummern werden nach der lokalen Stammdaten-Liste zusätzlich bei einer externen Lizenzdatenbank nachgeschlagen (Schnittstelle mit OAuth2 „client_credentials“, z. B. Archery Austria „API-Zugänge für Anwendungen“). Es gelten max. 5 Anfragen pro Sekunde und 100 pro Minute je Zugang – die Plattform hält das ein und speichert Antworten bis zu 24 Stunden zwischen. Bei Störungen bleibt die Anmeldung möglich (die Lizenz wird dann als „nicht in Liste“ markiert). Das Geheimnis wird verschlüsselt gespeichert.</p>'
       . '<div class="grid"><label class="chk wide"><input type="checkbox" name="a_on"' . ($ac['enabled'] ? ' checked' : '') . '> Externe Lizenzprüfung aktivieren</label>'
       . $ai('a_base', 'base', 'Basis-URL der Schnittstelle', 'https://www.archeryaustria.net/api/v2') . $ai('a_token', 'token_url', 'Token-Adresse (leer = Basis-URL + /oauth/token)', 'https://…/api/v2/oauth/token')
       . $ai('a_id', 'client_id', 'Client-ID') . '<label>Client-Geheimnis<input type="password" name="a_secret" autocomplete="new-password" placeholder="' . ($ac['secret'] !== '' ? 'unverändert' : '') . '"></label>'
       . '<label>Anmeldung am Token-Dienst<select name="a_auth"><option value="form"' . ($ac['auth'] === 'form' ? ' selected' : '') . '>Formularfelder (client_id / client_secret)</option><option value="basic"' . ($ac['auth'] === 'basic' ? ' selected' : '') . '>HTTP Basic</option></select></label>'
       . $ai('a_path', 'path', 'Pfad der Lizenzprüfung', '/ext/licence/check') . '<label>Methode<select name="a_method"><option value="GET"' . ($ac['method'] === 'GET' ? ' selected' : '') . '>GET</option><option value="POST"' . ($ac['method'] === 'POST' ? ' selected' : '') . '>POST</option></select></label>'
       . $ai('a_param', 'param', 'Parametername für die Lizenznummer', 'licence') . '</div>'
       . '<h4>Felder der Antwort (JSON, Punktschreibweise z. B. data.firstname)</h4><div class="grid">' . $ai('a_found', 'found_key', 'Feld „Lizenz gültig/gefunden“ (leer = gefunden, wenn Name/Verein geliefert wird)', 'valid') . $ai('a_first', 'first_key', 'Vorname', 'firstname') . $ai('a_last', 'last_key', 'Nachname', 'lastname')
       . $ai('a_cc', 'club_code_key', 'Vereinsnummer', 'club_id') . $ai('a_cn', 'club_name_key', 'Vereinsname', 'club_name') . $ai('a_sex', 'sex_key', 'Geschlecht (m/w, männlich/weiblich, male/female)', 'gender')
       . '<label class="chk wide"><input type="checkbox" name="a_club"' . ($ac['check_club'] ? ' checked' : '') . '> Verein auch gegen die Schnittstelle prüfen (nur ankreuzen, wenn deren Vereinsnummern mit deiner Vereinsliste übereinstimmen)</label></div>'
       . '<button>Speichern</button></form>'
       . '<form method="post" class="box stack">' . tf() . '<input type="hidden" name="a" value="api_test"><label>Test: Lizenznummer abfragen (leer = nur Token holen)<input name="lic" autocomplete="off"></label><button>Verbindung und Abfrage testen</button>'
       . '<p class="info">Der Test zeigt die Rohantwort der Schnittstelle. Passe danach die Feldnamen oben an, bis „Abbildung“ Name und Verein richtig zeigt.</p></form>';
    if (!empty($_SESSION['api_test'])) {
        $t = $_SESSION['api_test']; unset($_SESSION['api_test']);
        echo '<div class="box"><h4>Testergebnis</h4><div class="kv"><span>Token</span><div>' . e($t['token']) . '</div></div>'
           . ($t['status'] ? '<div class="kv"><span>Antwort der Schnittstelle</span><div>HTTP ' . e($t['status']) . ($t['error'] ? ' · ' . e($t['error']) : '') . '</div></div>' : '')
           . ($t['map'] !== null ? '<div class="kv"><span>Abbildung (so wird die Antwort verwendet)</span><div><code>' . e(json_encode($t['map'], JSON_UNESCAPED_UNICODE)) . '</code></div></div>' : '')
           . ($t['raw'] !== '' ? '<div class="kv"><span>Rohantwort</span></div><div class="licbox"><pre>' . e($t['raw']) . '</pre></div>' : '') . '</div>';
    }
    $sc = sql_cfg(); $drvs = sql_drivers(); $dl = ['mysql' => 'MySQL / MariaDB', 'pgsql' => 'PostgreSQL', 'sqlsrv' => 'Microsoft SQL Server'];
    $si = fn(string $name, string $k, string $label, string $ph = '') => '<label>' . $label . '<input name="' . $name . '" value="' . e($sc[$k]) . '" placeholder="' . e($ph) . '" autocomplete="off"></label>';
    echo '</div></details>';
    echo '<details class="sect"' . $so('sql') . '><summary>Externe SQL-Datenbank (Lesezugriff)</summary><div class="sect-body"><form method="post" class="box" style="max-width:none">' . tf() . '<input type="hidden" name="a" value="set_sql">'
       . '<p class="info">Optional: Lizenznummern werden zusätzlich direkt in einer externen SQL-Datenbank nachgeschlagen (nur Lesen, ein SELECT mit gebundenem Parameter je Anfrage). Reihenfolge: lokale Stammdaten → SQL-Datenbank → API. Bitte einen eigenen Benutzer nur mit Leserechten auf die benötigte Tabelle/View verwenden, den Zugriff per IP-Freigabe beschränken und die Verbindung absichern (VPN/TLS). Das Passwort wird verschlüsselt gespeichert.</p>'
       . ($drvs ? '' : '<p class="msg err">Auf diesem Server ist kein passender PDO-Treiber (mysql, pgsql, sqlsrv) verfügbar.</p>')
       . '<div class="grid"><label class="chk wide"><input type="checkbox" name="s_on"' . ($sc['enabled'] ? ' checked' : '') . '> Externe SQL-Abfrage aktivieren</label><label>Datenbanktyp<select name="s_driver">'
       . implode('', array_map(fn($d) => '<option value="' . $d . '"' . ($sc['driver'] === $d ? ' selected' : '') . '>' . $dl[$d] . '</option>', $drvs)) . '</select></label>'
       . $si('s_host', 'host', 'Server (Host)', 'db.example.at') . $si('s_port', 'port', 'Port (optional)', '3306') . $si('s_db', 'db', 'Datenbankname') . $si('s_user', 'user', 'Benutzer (nur Lesen)')
       . '<label>Passwort<input type="password" name="s_pass" autocomplete="new-password" placeholder="' . ($sc['pass'] !== '' ? 'unverändert' : '') . '"></label></div>'
       . '<h4>Tabelle/View und Spalten</h4><div class="grid">' . $si('s_table', 'table', 'Tabelle oder View', 'schuetzenlizenzen') . $si('s_lic', 'license_col', 'Spalte Lizenznummer (Pflicht)') . $si('s_first', 'first_col', 'Spalte Vorname') . $si('s_last', 'last_col', 'Spalte Nachname')
       . $si('s_cc', 'club_code_col', 'Spalte Vereinsnummer') . $si('s_cn', 'club_name_col', 'Spalte Vereinsname') . $si('s_sex', 'sex_col', 'Spalte Geschlecht (m/w, männlich/weiblich)') . $si('s_valid', 'valid_col', 'Spalte „gültig/aktiv“ (optional; 1/ja/aktiv = gültig)')
       . '<label class="chk wide"><input type="checkbox" name="s_club"' . ($sc['check_club'] ? ' checked' : '') . '> Verein auch gegen diese Datenbank prüfen (nur wenn deren Vereinsnummern mit deiner Vereinsliste übereinstimmen)</label></div><button>Speichern</button></form>'
       . '<form method="post" class="box stack">' . tf() . '<input type="hidden" name="a" value="sql_test"><label>Test: Lizenznummer abfragen (leer = nur Verbindung testen)<input name="lic" autocomplete="off"></label><button>Verbindung und Abfrage testen</button></form>';
    if (!empty($_SESSION['sql_test'])) {
        $t = $_SESSION['sql_test']; unset($_SESSION['sql_test']);
        echo '<div class="box"><h4>Testergebnis</h4><div class="kv"><span>Verbindung</span><div>' . e($t['conn']) . '</div></div>'
           . ($t['result'] !== null ? '<div class="kv"><span>Abfrage</span><div>' . ($t['result']['error'] !== '' ? e($t['result']['error']) : ($t['result']['found'] ? 'gefunden: <code>' . e(json_encode($t['result']['data'], JSON_UNESCAPED_UNICODE)) . '</code>' : 'nicht gefunden (oder nicht gültig)')) . '</div></div>' : '') . '</div>';
    }
    echo '</div></details>';
    echo '<details class="sect"' . $so('legal') . '><summary>Impressum und Datenschutz</summary><div class="sect-body"><form method="post" class="box stack" style="max-width:none">' . tf() . '<input type="hidden" name="a" value="set_legal">'
       . '<label>Impressum (einfacher Text oder HTML: p, br, a, strong, em, ul/li, h3, table … ; Skripte werden entfernt)<textarea name="impressum" rows="9">' . e(setting('impressum')) . '</textarea></label>'
       . '<label>Datenschutz: Verantwortlicher (Name, Anschrift, E-Mail, Telefon; leer = Impressum wird verwendet)<textarea name="privacy_controller" rows="4">' . e(setting('privacy_controller')) . '</textarea></label>'
       . '<label>Datenschutz: Hosting-Anbieter (optional)<input name="privacy_hosting" maxlength="300" value="' . e(setting('privacy_hosting')) . '" placeholder="z. B. World4You Internet Services GmbH, Linz"></label>'
       . '<label>Datenschutz: Ergänzende Hinweise (optional, HTML erlaubt)<textarea name="privacy_extra" rows="4">' . e(setting('privacy_extra')) . '</textarea></label>'
       . '<p class="info">Die Datenschutzerklärung (<a href="datenschutz.php" target="_blank" rel="noopener">ansehen</a>) liegt auf Deutsch und Englisch vor und folgt österreichischem Recht. Bitte vor dem Livebetrieb rechtlich prüfen lassen.</p><button>Speichern</button></form>';

    echo '</div></details>';
    echo '<details class="sect"' . $so('mail') . '><summary>E-Mail-Server</summary><div class="sect-body"><form method="post" class="box" style="max-width:none">' . tf() . '<input type="hidden" name="a" value="set_mail">'
       . '<div class="kv"><span>Status</span><div>' . (empty($mc['enabled']) ? '<span class="tag open">Versand aus</span>' : '<span class="tag paid">Versand an</span> über ' . e(($mc['driver'] ?? 'mail') === 'smtp' ? 'SMTP ' . ($sm['host'] ?? '') . ':' . ($sm['port'] ?? '') . (!empty($mc['fallback_mail']) ? ' (Rückfall: PHP mail())' : '') : 'PHP mail()')) . '</div></div>'
       . '<div class="grid"><label class="chk wide"><input type="checkbox" name="m_on"' . $ck(!empty($mc['enabled'])) . '> E-Mail-Versand aktivieren</label>'
       . '<label>Versandart<select name="m_driver"><option value="smtp"' . $sl($mc['driver'] ?? 'smtp', 'smtp') . '>SMTP-Server</option><option value="mail"' . $sl($mc['driver'] ?? 'smtp', 'mail') . '>PHP mail() des Webservers</option></select></label>'
       . '<label>Absender-Adresse<input type="email" name="m_from" value="' . e($mc['from'] ?? '') . '" placeholder="anmeldung@meinverein.at"></label>'
       . '<label>Absender-Name<input name="m_from_name" value="' . e($mc['from_name'] ?? '') . '"></label>'
       . '<label class="chk"><input type="checkbox" name="m_fallback"' . $ck(!empty($mc['fallback_mail'])) . '> Bei SMTP-Fehler PHP mail() versuchen</label></div>'
       . '<h4>SMTP (Postausgang)</h4><div class="grid"><label>Server<input name="s_host" value="' . e($sm['host'] ?? '') . '" placeholder="smtp.example.at"></label><label>Port<input name="s_port" inputmode="numeric" value="' . e($sm['port'] ?? 587) . '"></label>'
       . '<label>Verschlüsselung' . $secSel('s_secure', (string)($sm['secure'] ?? 'tls')) . '</label><label>Benutzer<input name="s_user" value="' . e($sm['user'] ?? '') . '" autocomplete="off"></label>'
       . '<label>Passwort<input type="password" name="s_pass" autocomplete="new-password" placeholder="' . (!empty($sm['pass']) ? 'unverändert' : '') . '"></label></div>'
       . '<h4>IMAP (optional: gesendete Mails im Ordner „Gesendet“ ablegen)</h4><div class="grid"><label class="chk wide"><input type="checkbox" name="i_on"' . $ck(!empty($im['enabled'])) . '> IMAP-Ablage aktivieren</label>'
       . '<label>Server<input name="i_host" value="' . e($im['host'] ?? '') . '" placeholder="imap.example.at"></label><label>Port<input name="i_port" inputmode="numeric" value="' . e($im['port'] ?? 993) . '"></label>'
       . '<label>Verschlüsselung' . $secSel('i_secure', (string)($im['secure'] ?? 'ssl')) . '</label><label>Benutzer<input name="i_user" value="' . e($im['user'] ?? '') . '" autocomplete="off"></label>'
       . '<label>Passwort<input type="password" name="i_pass" autocomplete="new-password" placeholder="' . (!empty($im['pass']) ? 'unverändert' : '') . '"></label><label>Ordner<input name="i_folder" value="' . e($im['folder'] ?? 'Sent') . '"></label></div>'
       . '<p class="info">Passwörter werden verschlüsselt in der Datenbank gespeichert (benötigt die PHP-Erweiterung openssl).</p><button>Speichern</button></form>'
       . '<form method="post" class="box stack">' . tf() . '<input type="hidden" name="a" value="mailtest"><label>Testmail senden an<input type="email" name="to" required></label><button>Testmail senden</button></form>';

    echo '</div></details>';
    echo '<details class="sect"' . $so('source') . '><summary>Quellcode (AGPLv3)</summary><div class="sect-body"><form method="post" class="box stack" style="max-width:none">' . tf() . '<input type="hidden" name="a" value="set_source">'
       . '<p class="info">Die AGPL verlangt, dass alle Nutzer den Quellcode der laufenden Version erhalten können. Standard: Footer-Link „Quellcode“ lädt ein ZIP dieser Installation (ohne config.php und Uploads, benötigt die PHP-Erweiterung zip). Alternativ hier einen eigenen Link (z. B. Code-Repository) eintragen.</p>'
       . '<label>Eigener Quellcode-Link (optional)<input type="url" name="source_url" value="' . e(setting('source_url')) . '" placeholder="https://…"></label><button>Speichern</button></form>';
    echo '</div></details>';
}

// ---- Schema-Check -------------------------------------------------------------
if ($p === 'check') {
    if (!is_admin()) deny();
    $need = [
        'Tournament' => ['ToId', 'ToCode', 'ToName', 'ToWhenFrom', 'ToWhenTo', 'ToWhere'],
        'Divisions' => ['DivTournament', 'DivId', 'DivDescription', 'DivAthlete', 'DivViewOrder'],
        'Classes' => ['ClTournament', 'ClId', 'ClDescription', 'ClAthlete', 'ClViewOrder'],
        'Countries' => ['CoId', 'CoTournament', 'CoCode', 'CoName', 'CoNameComplete'],
        'Entries' => ['EnId', 'EnTournament', 'EnDivision', 'EnClass', 'EnAgeClass', 'EnSubClass', 'EnCountry', 'EnCode', 'EnName', 'EnFirstName', 'EnSex', 'EnDob', 'EnStatus', 'EnIndClEvent', 'EnTeamClEvent', 'EnIndFEvent', 'EnTeamFEvent', 'EnTeamMixEvent', 'EnAthlete', 'EnOdfShortname'],
        'Qualifications' => ['QuId', 'QuSession', 'QuScore'],
        'Session' => ['SesTournament', 'SesOrder', 'SesType', 'SesName'],
    ];
    echo '<h2>Schema-Check gegen die ianseo-Datenbank</h2><p class="info">Fehlende Spalten werden beim Einfügen übersprungen; fehlende Pflichtspalten (Id/Tournament/Name) müssten angepasst werden.</p><div class="box"><table><tbody>';
    foreach ($need as $t => $cols) {
        try { $have = array_column(db()->query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field'); $miss = array_diff($cols, $have);
              echo '<tr><td>' . $t . '</td><td>' . ($miss ? '<span class="tag open">fehlt: ' . e(implode(', ', $miss)) . '</span>' : '<span class="tag paid">OK</span>') . '</td></tr>'; }
        catch (Throwable $x) { echo '<tr><td>' . $t . '</td><td><span class="tag open">Tabelle nicht gefunden</span></td></tr>'; }
    }
    echo '</tbody></table></div>';
}
// ---- Statistik je Turnier -----------------------------------------------------------------------------
if ($p === 'stats') {
    $ev = get_event((int)($_GET['id'] ?? 0)); if (!can_view($ev)) deny();
    $f = entry_filter_params($_GET); $f['q'] = $f['div'] = $f['cls'] = $f['st'] = ''; $f['ses'] = $f['ses'] ?: 0;     // Statistik: nur Durchgang und Zahlung filterbar
    $fq = entry_filter_query($f);
    [$st, $opt, $sl] = stats_data($ev, $f);
    event_head($ev);
    $t = $st['total']; $zero = ['n' => 0, 'paid' => 0, 'open' => 0, 'starter' => 0, 'wait' => 0];
    $pct = fn(array $c) => $c['n'] ? (int)round(100 * $c['paid'] / $c['n']) : 0;
    $tr = fn(string $label, array $c, bool $b = false) => '<tr' . ($b ? ' class="sum"' : '') . '><td>' . $label . '</td><td class="sn">' . $c['n'] . '</td><td class="sn">' . $c['paid'] . '</td><td class="sn">' . $c['open'] . '</td>'
        . '<td><span class="bar" title="' . $pct($c) . ' % bezahlt"><i style="width:' . $pct($c) . '%"></i></span> <small>' . $pct($c) . ' %</small></td></tr>';
    $th = fn(string $first) => '<thead><tr><th>' . $first . '</th><th class="sn">Anmeldungen</th><th class="sn">bezahlt</th><th class="sn">nicht bezahlt</th><th>Anteil bezahlt</th></tr></thead>';
    $sesOpts = ''; foreach ($sl as $x) $sesOpts .= '<option value="' . $x['id'] . '"' . ((int)$f['ses'] === (int)$x['id'] ? ' selected' : '') . '>' . e($x['no'] . ($x['name'] !== '' ? ' – ' . $x['name'] : '')) . '</option>';
    echo '<h3>Statistik</h3><p><a href="?p=entries&id=' . $ev['id'] . '">← Anmeldungen</a> · <a href="?p=statscsv&id=' . $ev['id'] . ($fq !== '' ? '&' . $fq : '') . '">Statistik als CSV</a>'
       . (can_cash($ev) ? ' · <a href="?p=mail&id=' . $ev['id'] . '">Rundmail</a>' : '') . '</p>'
       . '<form method="get" class="box filt"><input type="hidden" name="p" value="stats"><input type="hidden" name="id" value="' . $ev['id'] . '">'
       . '<label>Durchgang<select name="ses"><option value="">alle</option>' . $sesOpts . '</select></label>'
       . '<label>Zahlung<select name="z"><option value="">alle</option><option value="1"' . ($f['z'] === '1' ? ' selected' : '') . '>nur bezahlt</option><option value="0"' . ($f['z'] === '0' ? ' selected' : '') . '>nur nicht bezahlt</option></select></label>'
       . '<button class="sm">Anzeigen</button>' . ($fq !== '' ? ' <a href="?p=stats&id=' . $ev['id'] . '">Zurücksetzen</a>' : '') . '</form>';
    if ($t['n'] === 0) echo '<p class="empty">' . ($fq !== '' ? 'Keine Anmeldungen für diese Auswahl.' : 'Noch keine Anmeldungen.') . '</p>';
    else {
        $dk = stat_keys(array_keys($st['div']), $opt['div']); $ck = stat_keys(array_keys($st['cls']), $opt['cls']);
        echo '<div class="box scroll"><table class="stat"><thead><tr><th class="sn">Anmeldungen</th><th class="sn">bezahlt</th><th class="sn">nicht bezahlt</th><th class="sn">Starter</th><th class="sn">Warteliste</th><th>Anteil bezahlt</th></tr></thead><tbody><tr>'
           . '<td class="sn">' . $t['n'] . '</td><td class="sn">' . $t['paid'] . '</td><td class="sn">' . $t['open'] . '</td><td class="sn">' . $t['starter'] . '</td><td class="sn">' . $t['wait'] . '</td>'
           . '<td><span class="bar"><i style="width:' . $pct($t) . '%"></i></span> <small>' . $pct($t) . ' %</small></td></tr></tbody></table></div>';
        echo '<h4>Je Durchgang</h4><div class="box scroll"><table class="stat"><thead><tr><th>Durchgang</th><th class="sn">Anmeldungen</th><th class="sn">bezahlt</th><th class="sn">nicht bezahlt</th><th class="sn">Starter / Plätze</th><th class="sn">Warteliste</th></tr></thead><tbody>';
        foreach ($sl as $x) {
            if ($f['ses'] && (int)$x['id'] !== (int)$f['ses']) continue;
            $c = $st['ses'][$x['id']] ?? $zero;
            echo '<tr><td>' . e($x['no'] . ($x['name'] !== '' ? ' – ' . $x['name'] : '')) . '</td><td class="sn">' . $c['n'] . '</td><td class="sn">' . $c['paid'] . '</td><td class="sn">' . $c['open'] . '</td><td class="sn">' . $c['starter'] . ((int)$x['capacity'] > 0 ? ' / ' . (int)$x['capacity'] : '') . '</td><td class="sn">' . $c['wait'] . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<h4>Je Bogenklasse</h4><div class="box scroll"><table class="stat">' . $th('Bogenklasse') . '<tbody>';
        foreach ($dk as $d) echo $tr(e(stat_label($d, $opt['div'])), $st['div'][$d]);
        echo $tr('Gesamt', $t, true) . '</tbody></table></div>';
        echo '<h4>Je Altersklasse</h4><div class="box scroll"><table class="stat">' . $th('Altersklasse') . '<tbody>';
        foreach ($ck as $c) echo $tr(e(stat_label($c, $opt['cls'])), $st['cls'][$c]);
        echo $tr('Gesamt', $t, true) . '</tbody></table></div>';
        echo '<h4>Bogenklasse × Altersklasse (Anzahl Schützen)</h4><div class="box scroll"><table class="stat"><thead><tr><th>Bogenklasse</th>';
        foreach ($ck as $c) echo '<th class="sn" title="' . e(stat_label($c, $opt['cls'])) . '">' . e($c) . '</th>';
        echo '<th class="sn">Gesamt</th></tr></thead><tbody>';
        foreach ($dk as $d) {
            echo '<tr><td>' . e(stat_label($d, $opt['div'])) . '</td>';
            foreach ($ck as $c) { $n = $st['combo'][$d][$c]['n'] ?? 0; echo '<td class="sn' . ($n ? '' : ' z') . '">' . ($n ?: '·') . '</td>'; }
            echo '<td class="sn"><strong>' . $st['div'][$d]['n'] . '</strong></td></tr>';
        }
        echo '<tr class="sum"><td>Gesamt</td>';
        foreach ($ck as $c) echo '<td class="sn">' . $st['cls'][$c]['n'] . '</td>';
        echo '<td class="sn">' . $t['n'] . '</td></tr></tbody></table></div>';
        echo '<h4>Kombinationen im Detail (bezahlt / nicht bezahlt)</h4><div class="box scroll"><table class="stat">' . $th('Bogenklasse / Altersklasse') . '<tbody>';
        foreach ($dk as $d) foreach (stat_keys(array_keys($st['combo'][$d]), $opt['cls']) as $c) echo $tr(e(stat_label($d, $opt['div'])) . ' / ' . e(stat_label($c, $opt['cls'])), $st['combo'][$d][$c]);
        echo '</tbody></table></div>';
    }
}

// ---- Rundmail (alle, je Durchgang, je Bogenklasse/Altersklasse, bezahlt / nicht bezahlt) -----------------
if ($p === 'mail') {
    $ev = get_event((int)($_GET['id'] ?? 0)); if (!can_cash($ev)) deny();
    $f = entry_filter_params($_GET); $fq = entry_filter_query($f);
    $all = event_entries($ev); $rows = filter_entries($ev, $all, $f);
    $dr = $_SESSION['mass_draft'][(int)$ev['id']] ?? []; $tp = $dr['tpl'] ?? [];
    $emails = []; foreach ($rows as $r) if (filter_var($r['email'], FILTER_VALIDATE_EMAIL)) $emails[mb_strtolower((string)$r['email'])] = 1;
    event_head($ev);
    $chip = fn(string $label, string $q2) => '<a class="tag' . ($fq === $q2 ? ' paid' : '') . '" href="?p=mail&id=' . $ev['id'] . ($q2 !== '' ? '&' . $q2 : '') . '">' . $label . '</a>';
    echo '<h3>Rundmail</h3><p><a href="?p=entries&id=' . $ev['id'] . '">← Anmeldungen</a> · <a href="?p=stats&id=' . $ev['id'] . '">Statistik</a></p>';
    if (empty(mail_cfg()['enabled'])) echo '<p class="msg err">Der E-Mail-Versand ist nicht aktiviert (Administrator: Einstellungen → Mailserver). Ohne Mailserver können keine Rundmails gesendet werden.</p>';
    echo '<p>Schnellauswahl: ' . $chip('alle', '') . ' ' . $chip('nicht bezahlt', 'z=0') . ' ' . $chip('bezahlt', 'z=1') . '</p>' . filter_form($ev, 'mail', $all, $f, 'Empfänger auswählen');
    ?>
    <form method="post" class="box stack" style="max-width:none"><?= tf() ?>
      <input type="hidden" name="a" value="send_mass"><input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
      <?php foreach ($f as $k => $v) if ($v !== '' && $v !== 0) echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">'; ?>
      <h4>Empfänger: <?= count($rows) ?> Anmeldung<?= count($rows) === 1 ? '' : 'en' ?> (<?= count($emails) ?> verschiedene E-Mail-Adressen)</h4>
      <?php if (!$rows): ?>
        <p class="empty">Keine Anmeldungen für diese Auswahl.</p>
      <?php else: ?>
        <label><input type="checkbox" checked onclick="var a=this.checked;document.querySelectorAll('.selc:not(:disabled)').forEach(function(c){c.checked=a})"> alle auswählen / abwählen</label>
        <div class="scroll" style="max-height:22rem;overflow:auto"><table><thead><tr><th></th><th>Name</th><th>Durchgang</th><th>Bogen-/Altersklasse</th><th>E-Mail</th><th>Zahlung</th></tr></thead><tbody>
        <?php foreach ($rows as $r): $ok = (bool)filter_var($r['email'], FILTER_VALIDATE_EMAIL); ?>
          <tr><td><input type="checkbox" class="selc" name="sel[]" value="<?= (int)$r['id'] ?>"<?= $ok ? ' checked' : ' disabled' ?>></td><td><?= e($r['lastname'] . ' ' . $r['firstname']) ?></td><td><?= e($r['no']) ?></td><td><?= e($r['division'] . ' / ' . $r['class']) ?></td><td><?= e($r['email']) ?><?= $ok ? '' : ' <span class="tag open">ungültig</span>' ?></td><td><span class="tag <?= $r['paid'] ? 'paid' : 'open' ?>"><?= $r['paid'] ? 'bezahlt' : 'offen' ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <h4>Nachricht</h4>
        <label>Betreff<input name="s_de" required maxlength="200" value="<?= e($tp['de']['s'] ?? '') ?>"></label>
        <label>Text<textarea name="b_de" rows="10" required><?= e($tp['de']['b'] ?? '') ?></textarea></label>
        <details><summary>Englische Fassung (optional – wird für Anmeldungen in englischer Sprache verwendet, sonst der deutsche Text)</summary>
          <label>Subject<input name="s_en" maxlength="200" value="<?= e($tp['en']['s'] ?? '') ?>"></label>
          <label>Text<textarea name="b_en" rows="8"><?= e($tp['en']['b'] ?? '') ?></textarea></label>
        </details>
        <p class="info">Platzhalter (je Empfänger ersetzt): {vorname} {nachname} {name} {turnier} {durchgang} {verein} {lizenz} {bogenklasse} {altersklasse} {ref} {verwendungszweck} {zahlungsinfo} {paypal} {anmeldeschluss} {anmeldestart} {nenngeld} {organisation} (Groß-/Kleinschreibung egal).<br>
          Beispiel Zahlungserinnerung: „Hallo {vorname}, für „{turnier}“ ist noch keine Zahlung eingegangen. Nenngeld: {nenngeld}, Verwendungszweck: {verwendungszweck} {zahlungsinfo}“</p>
        <label><input type="checkbox" name="dedupe" value="1"<?= ($dr['dedupe'] ?? true) ? ' checked' : '' ?>> Pro E-Mail-Adresse nur eine Mail senden (Platzhalter beziehen sich dann auf die erste Anmeldung dieser Adresse)</label>
        <label><input type="checkbox" name="copy" value="1"<?= ($dr['copy'] ?? true) ? ' checked' : '' ?>> Kopie an mich senden</label>
        <p class="info">Antworten der Empfänger gehen an die E-Mail-Adresse deines Kontos (Mein Konto), sonst an die Organisationsleitung. Jede Rundmail wird im Protokoll vermerkt (höchstens 500 Anmeldungen pro Versand).</p>
        <div><button name="test" value="1" class="ghost">Testmail an mich</button>
        <button onclick="var n=document.querySelectorAll('.selc:checked').length;return n>0&amp;&amp;confirm('Rundmail jetzt an '+n+' ausgewählte Anmeldung(en) senden?')">Rundmail senden</button></div>
      <?php endif; ?>
    </form>
    <?php
}

if (!in_array($p, ['events', 'event', 'entries', 'edit', 'stats', 'mail', 'users', 'user', 'master', 'athlete', 'settings', 'check', 'log'], true)) echo '<h2>Seite nicht gefunden</h2><p><a href="admin.php">Zur Turnierliste</a></p>';
} catch (Throwable $x) {
    error_log('[anmeldung] ' . $x);
    echo '<div class="msg err"><strong>Fehler:</strong> ' . e($x->getMessage()) . ' (' . e(basename($x->getFile())) . ':' . (int)$x->getLine() . ')</div>';
}
page_foot();
