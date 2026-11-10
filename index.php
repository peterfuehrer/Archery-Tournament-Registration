<?php
/* Turnier-Anmeldeplattform für ianseo – Copyright (C) 2026 Peter Führer – GNU AGPLv3 (OSI-approved), siehe LICENSE – Version 2026.11.08-d */
require __DIR__ . '/lib.php';
$events = q("SELECT * FROM reg_events WHERE published=1 ORDER BY id DESC")->fetchAll();
$ev = null; $eid = (int)($_GET['e'] ?? 0);
foreach ($events as $x) if ($x['id'] == $eid || (!$eid && count($events) == 1)) $ev = $x;

$err = []; $done = null; $purpose = ''; $f = []; $foreign = false;
$mediaReq = setting('media_required') !== '0';                                   // Foto-/Filmzustimmung verpflichtend (Standard)
$autofill = setting('autofill') === '1' && athletes_available();
if ($ev) {
    $sessions = q("SELECT * FROM reg_sessions WHERE event_id=? ORDER BY no", [$ev['id']])->fetchAll();
    $opts = ianseo_opts((int)$ev['to_id']);
    $oem = $ev['kind'] === 'oem';
    $clubs = q("SELECT * FROM reg_clubs ORDER BY name")->fetchAll();
    $clubMap = array_column($clubs, null, 'code');
    $isOpen = event_is_open($ev);
    if ($isOpen && $_SERVER['REQUEST_METHOD'] === 'POST') {
        post_guard();
        if (($_POST['website'] ?? '') !== '') exit;                              // Honeypot
        foreach (['firstname', 'lastname', 'dob', 'club', 'license', 'email', 'division', 'class', 'q1', 'q2', 'q3', 'qy', 'qe'] as $k) $f[$k] = trim((string)($_POST[$k] ?? ''));
        $f['sex'] = (int)($_POST['sex'] ?? -1); $f['sid'] = (int)($_POST['sid'] ?? 0); $f['qtype'] = (int)($_POST['qtype'] ?? 0);
        $foreign = !empty($_POST['foreign']);                                     // Ausländer: keine österreichische Lizenzprüfung
        $f['country'] = $foreign ? strtoupper(trim((string)($_POST['country'] ?? ''))) : '';
        $f['club_sel'] = trim((string)($_POST['club_code'] ?? '')); $f['club_code'] = '';
        if ($clubs && !$foreign) {
            if ($f['club_sel'] === '') $err[] = t('Bitte einen Verein wählen (oder „Anderer Verein“).');
            elseif ($f['club_sel'] !== 'other') {
                if (isset($clubMap[$f['club_sel']])) { $f['club_code'] = $f['club_sel']; $f['club'] = $clubMap[$f['club_sel']]['name']; } else $err[] = t('Verein ungültig.');
            }
        }
        if ($foreign) {
            if (!preg_match('/^[A-Z]{3}$/', $f['country'])) $err[] = t('Bitte das Land als 3-Buchstaben-Code angeben (z. B. GER).');
            elseif ($f['country'] === 'AUT') $err[] = t('Für österreichische Schützen bitte das Häkchen „Ausländische/r Schütze/in“ entfernen.');
        }
        $ses = null; foreach ($sessions as $s) if ($s['id'] == $f['sid']) $ses = $s;
        if ($ses && !session_is_open($ev, $ses)) $err[] = t('Für diesen Durchgang ist die Anmeldung geschlossen.');
        $nolic = !empty($ev['allow_nolic']) && !empty($_POST['nolic']);
        $f['lic_check'] = $foreign ? 'ausland' : '';
        if ($nolic) $f['license'] = '';
        elseif (!empty($ev['allow_nolic']) && !$foreign && $f['license'] === '') $err[] = t('Bitte Lizenznummer eingeben oder „Keine Lizenz“ ankreuzen.');
        if ($f['firstname'] === '' || $f['lastname'] === '') $err[] = t('Vor- und Nachname sind Pflicht.');
        if (!in_array($f['sex'], [0, 1], true)) $err[] = t('Bitte Geschlecht wählen.');
        if ($f['dob'] !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $f['dob']);
            if (!$d || $d->format('Y-m-d') !== $f['dob'] || $f['dob'] > date('Y-m-d')) $err[] = t('Geburtsdatum ungültig.');
        }
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err[] = t('E-Mail-Adresse ungültig.');
        if (!$ses) $err[] = t('Bitte einen Durchgang wählen.');
        if ($opts['div'] && !isset($opts['div'][$f['division']])) $err[] = t('Bitte Bogenklasse wählen.');
        if ($opts['cls'] && !isset($opts['cls'][$f['class']])) $err[] = t('Bitte Altersklasse wählen.');
        if ($ses && $ses['divisions'] !== '' && !in_array($f['division'], explode(',', $ses['divisions']), true)) $err[] = t('Diese Bogenklasse ist in diesem Durchgang nicht zugelassen.');
        if ($oem) {
            if ($f['qtype'] === 1) {
                if ($f['q1'] === '' || $f['q2'] === '' || $f['q3'] === '') $err[] = t('Bitte alle 3 Turniere angeben.');
                $f['qy'] = $f['qe'] = '';
            } elseif ($f['qtype'] === 2) {
                if (!preg_match('/^(19|20)\d\d$/', $f['qy']) || (int)$f['qy'] > (int)date('Y') || $f['qe'] === '') $err[] = t('Bitte Jahr (4-stellig) und Turnier der ÖM/ÖSTM angeben.');
                $f['q1'] = $f['q2'] = $f['q3'] = '';
            } else $err[] = t('Bitte eine der beiden Nachweis-Optionen wählen.');
        } else { $f['qtype'] = 0; $f['q1'] = $f['q2'] = $f['q3'] = $f['qy'] = $f['qe'] = ''; }
        if (empty($_POST['privacy'])) $err[] = t('Bitte der Datenschutzerklärung und der Datenverarbeitung zustimmen.');
        $media = !empty($_POST['media']) ? 1 : 0;
        if ($mediaReq && !$media) $err[] = t('Bitte der Nutzung von Foto-, Bild- und Filmmaterial gemäß Ausschreibung zustimmen.');
        $dob = $f['dob'] !== '' ? $f['dob'] : null;
        if (!$err && !$nolic && !$foreign && ($m = athlete_check($f))) $err[] = $m;   // Lizenz, Name UND Verein gegen die Schützenliste
        if (!$err && $ses && q("SELECT 1 FROM reg_entries WHERE event_id=? AND session_id=? AND lower(firstname)=lower(?) AND lower(lastname)=lower(?) AND dob <=> ?",
                [$ev['id'], $ses['id'], $f['firstname'], $f['lastname'], $dob])->fetch()) $err[] = t('Diese Person ist für diesen Durchgang bereits angemeldet.');
        if (!$err && $ses) {
            if ($foreign && $f['club'] === '') { $f['club'] = ioc_name($f['country'], 'de'); $f['club_code'] = $f['country']; }
            if ($nolic) { $f['license'] = next_nolic((int)$ev['id']); $f['lic_check'] = 'frei'; }
            q("INSERT INTO reg_entries (event_id,session_id,firstname,lastname,sex,dob,club,license,email,division,class,qtype,q1,q2,q3,qy,qe,club_code,lic_check,country,lang,consent_at,consent_media) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [$ev['id'], $ses['id'], $f['firstname'], $f['lastname'], $f['sex'], $dob, $f['club'], $f['license'], $f['email'], $f['division'], $f['class'],
               $oem ? $f['qtype'] : null, $f['q1'], $f['q2'], $f['q3'], $f['qy'], $f['qe'], $f['club_code'], $f['lic_check'], $f['country'], lang(), date('Y-m-d H:i:s'), $media]);
            $newId = (int)db()->lastInsertId();
            $done = ref_code((int)$ev['id'], $newId);
            log_event('anmeldung', (int)$ev['id'], $newId, $f['lastname'] . ' ' . $f['firstname'] . ', ' . ($ses['name'] ?: 'Durchgang ' . $ses['no']) . ', ' . $f['division'] . '/' . $f['class'] . ($f['club'] !== '' ? ', ' . $f['club'] : ''), 'Teilnehmer/in');
            $purpose = purpose_text($ev, $f, $done);
            $pp = paypal_url($ev);
            [$ms, $mb] = mail_render($ev, 'reg', lang(), mail_vars($ev, $f, $ses, $done, lang()));
            send_mail($f['email'], $ms, $mb);
            $o = owner_of($ev);
            if ($o && $o['email'] !== '') send_mail($o['email'], 'Neue Anmeldung: ' . $ev['title'],
                "Neue Anmeldung zu \"{$ev['title']}\" ($done)\n\n{$f['lastname']} {$f['firstname']}\nVerein: {$f['club']}" . ($f['country'] ? " ({$f['country']})" : '') . "\nLizenz: {$f['license']}\nBogenklasse/Altersklasse: {$f['division']} / {$f['class']}\nDurchgang: "
                . ($ses['name'] ?: $ses['no']) . "\nE-Mail: {$f['email']}" . ($oem ? "\nNachweis: " . qual_text($f) : '') . "\n\nZahlung ist noch offen (Warteliste).");
            $f = []; $foreign = false;
        }
    }
}
page_head(t('Anmeldung'), $ev && count($events) > 1 ? '<a href="index.php">' . e(t('Alle Turniere')) . '</a>' : '');

if (!$ev) {
    echo '<h2>' . e(t('Turniere')) . '</h2>';
    if (!$events) echo '<p>' . e(t('Derzeit sind keine Turniere veröffentlicht.')) . '</p>';
    foreach ($events as $x) echo '<p><a class="big" href="?e=' . $x['id'] . '">' . e($x['title']) . '</a>' . ($x['kind'] === 'oem' ? ' <span class="tag">ÖM/ÖSTM</span>' : '') . ($x['is_open'] ? '' : ' <span class="tag">' . e(t('Anmeldung geschlossen')) . '</span>') . '</p>';
    page_foot(); exit;
}
event_head($ev);
if ($ev['info']) echo '<p class="info">' . nl2br(e($ev['info'])) . '</p>';
$divName = fn(string $d) => $opts['div'][$d] ?? $d;
$allowed = fn(array $s) => implode(', ', array_map($divName, explode(',', $s['divisions'])));

if ($done) {
    $pp = paypal_url($ev);
    echo '<section class="box ok"><h3>' . e(t('Anmeldung eingegangen')) . '</h3><p>' . e(t('Du stehst auf der Warteliste, bis die Zahlung verbucht ist. Erst danach rückst du in die Starterliste.')) . '</p>'
       . '<div class="kv"><span>' . e(t('Verwendungszweck')) . '</span><strong>' . e($purpose) . '</strong></div>' . ($ev['pay_info'] ? '<p>' . nl2br(e($ev['pay_info'])) . '</p>' : '')
       . ($pp !== '' ? '<p><a class="btn" href="' . e($pp) . '" target="_blank" rel="noopener noreferrer">' . e(t('Alternativ: mit PayPal bezahlen')) . '</a></p><p class="hint">' . e(t('Externer Link zu PayPal. Bitte den Verwendungszweck in der PayPal-Nachricht angeben. Die Zahlung wird manuell bestätigt.')) . '</p>' : '')
       . '</section>';
}
if (!$isOpen) echo '<p class="msg err">' . e(t('Die Anmeldung ist geschlossen.')) . '</p>';
elseif (!$sessions) echo '<p class="msg err">' . e(t('Für dieses Turnier sind noch keine Durchgänge angelegt.')) . '</p>';
elseif (!array_filter($sessions, fn($s) => session_is_open($ev, $s))) echo '<p class="msg err">' . e(t('Für alle Durchgänge ist die Anmeldung geschlossen.')) . '</p>';
else {
    foreach ($err as $m) echo '<p class="msg err">' . e($m) . '</p>';
    $v = fn($k) => e($f[$k] ?? '');
    $sel = fn(array $o, string $k) => implode('', array_map(fn($id, $d) => '<option value="' . e($id) . '"' . (($f[$k] ?? '') === (string)$id ? ' selected' : '') . '>' . e($id . ' – ' . $d) . '</option>', array_keys($o), $o));
    $cs = $f['club_sel'] ?? '';
    $docLink = doc_link($ev);
    ?>
    <details class="box regbox" <?= $err || !$done ? 'open' : '' ?>><summary><?= e(t('Jetzt anmelden')) ?></summary>
    <form method="post" class="reg"><?= tf() ?>
      <input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">

      <section class="sec"><h3><span class="num">1</span><?= e(t('Lizenz')) ?></h3>
        <div class="grid">
          <label class="wide chk"><input type="checkbox" name="foreign" id="foreign" value="1" <?= $foreign ? 'checked' : '' ?>> <?= e(t('Ausländische/r Schütze/in (keine österreichische Lizenz)')) ?></label>
          <div><label><?= e($ev['allow_nolic'] ? t('Lizenznummer') : t('Lizenznummer (optional)')) ?><input name="license" id="licin" maxlength="25" autocomplete="off" value="<?= $v('license') ?>"></label>
            <?php if ($autofill) { ?><p class="hint" id="lichint"><?= e(t('Lizenznummer eingeben – Name und Verein werden automatisch ergänzt.')) ?></p><?php } ?>
            <?php if ($ev['allow_nolic']) { ?><label class="chk"><input type="checkbox" name="nolic" id="nolic" value="1" <?= !empty($_POST['nolic']) ? 'checked' : '' ?>> <?= e(t('Keine Lizenz – Nummer wird automatisch vergeben (LL1, LL2, …)')) ?></label><?php } ?></div>
          <label id="countrybox"><?= e(t('Land (3-Buchstaben-Code, z. B. GER)')) ?><input name="country" id="country" list="countries" maxlength="3" style="text-transform:uppercase" value="<?= $v('country') ?>"></label>
          <datalist id="countries"><?php foreach (ioc_countries() as $code => $nm) echo '<option value="' . $code . '">' . e($nm[lang() === 'en' ? 1 : 0]) . '</option>'; ?></datalist>
        </div></section>

      <section class="sec"><h3><span class="num">2</span><?= e(t('Persönliche Daten')) ?></h3>
        <div class="grid">
          <label><?= e(t('Vorname')) ?><input name="firstname" id="fn" maxlength="30" value="<?= $v('firstname') ?>" required></label>
          <label><?= e(t('Nachname')) ?><input name="lastname" id="ln" maxlength="30" value="<?= $v('lastname') ?>" required></label>
          <label><?= e(t('Geschlecht')) ?><select name="sex" required><option value="">–</option><option value="0" <?= ($f['sex'] ?? -1) === 0 ? 'selected' : '' ?>><?= e(t('Männlich')) ?></option><option value="1" <?= ($f['sex'] ?? -1) === 1 ? 'selected' : '' ?>><?= e(t('Weiblich')) ?></option></select></label>
          <label><?= e(t('Geburtsdatum (optional)')) ?><input type="date" name="dob" value="<?= $v('dob') ?>"></label>
          <label class="wide"><?= e(t('E-Mail (nicht öffentlich)')) ?><input type="email" name="email" value="<?= $v('email') ?>" required></label>
        </div></section>

      <section class="sec"><h3><span class="num">3</span><?= e(t('Verein')) ?></h3>
        <div class="grid">
        <?php if ($clubs) { ?>
          <label><?= e(t('Verein')) ?><select name="club_code" id="clubsel" required><option value="">–</option>
            <?php foreach ($clubs as $c) echo '<option value="' . e($c['code']) . '"' . ($cs === (string)$c['code'] ? ' selected' : '') . '>' . e($c['name'] . ($c['state_code'] !== '' ? ' (' . $c['state_code'] . ')' : '')) . '</option>'; ?>
            <option value="other"<?= $cs === 'other' ? ' selected' : '' ?>><?= e(t('Anderer Verein / Einzelstarter')) ?></option></select></label>
          <label id="clubother"><?= e(t('Vereinsname (bei „Anderer Verein“ bzw. Ausland)')) ?><input name="club" id="clubname" maxlength="80" value="<?= $v('club') ?>"></label>
        <?php } else { ?>
          <label class="wide"><?= e(t('Verein')) ?><input name="club" list="clubs" maxlength="80" value="<?= $v('club') ?>"></label>
          <datalist id="clubs"><?php try { foreach (q("SELECT DISTINCT CoName FROM Countries WHERE CoTournament=? AND CoName<>'' ORDER BY CoName", [$ev['to_id']]) as $c) echo '<option value="' . e($c['CoName']) . '">'; } catch (Throwable $x) {} ?></datalist>
        <?php } ?>
        </div></section>

      <section class="sec"><h3><span class="num">4</span><?= e(t('Turnier')) ?></h3>
        <div class="grid">
          <label class="wide"><?= e(t('Durchgang')) ?><select name="sid" required><option value="">–</option>
            <?php foreach ($sessions as $s) { [$st, , $cap] = lists((int)$s['id']);
                echo '<option value="' . $s['id'] . '" data-div="' . e($s['divisions']) . '"' . (session_is_open($ev, $s) ? '' : ' disabled') . (($f['sid'] ?? 0) == $s['id'] ? ' selected' : '') . '>'
                   . e(($s['name'] ?: t('Durchgang') . ' ' . $s['no']) . ' (' . count($st) . ($cap ? '/' . $cap : '') . ' ' . t('Starter') . ')' . (session_is_open($ev, $s) ? '' : ' – ' . t('Anmeldung geschlossen'))) . '</option>'; } ?>
          </select></label>
          <label><?= e(t('Bogenklasse')) ?><select name="division" required><option value="">–</option><?= $sel($opts['div'], 'division') ?></select></label>
          <label><?= e(t('Altersklasse')) ?><select name="class" required><option value="">–</option><?= $sel($opts['cls'], 'class') ?></select></label>
          <?php if ($oem) { $qt = $f['qtype'] ?? 0; ?>
          <fieldset class="wide"><legend><?= e(t('Qualifikation für die ÖM/ÖSTM')) ?></legend>
            <label class="chk"><input type="radio" name="qtype" value="1" <?= $qt === 1 ? 'checked' : '' ?>> <?= e(t('Option 1: Bitte die 3 für die Teilnahme notwendigen Turniere angeben')) ?></label>
            <div class="grid"><input name="q1" maxlength="150" placeholder="<?= e(t('Turnier 1')) ?>" value="<?= $v('q1') ?>"><input name="q2" maxlength="150" placeholder="<?= e(t('Turnier 2')) ?>" value="<?= $v('q2') ?>"><input name="q3" maxlength="150" placeholder="<?= e(t('Turnier 3')) ?>" value="<?= $v('q3') ?>"></div>
            <label class="chk"><input type="radio" name="qtype" value="2" <?= $qt === 2 ? 'checked' : '' ?>> <?= e(t('Option 2: Teilnahme an einer ÖM/ÖSTM')) ?></label>
            <div class="grid"><input name="qy" maxlength="4" inputmode="numeric" placeholder="<?= e(t('Jahr (z. B. 2025)')) ?>" value="<?= $v('qy') ?>"><input name="qe" maxlength="150" placeholder="<?= e(t('Turnier')) ?>" value="<?= $v('qe') ?>"></div>
          </fieldset>
          <?php } ?>
        </div></section>

      <section class="sec"><h3><span class="num">5</span><?= e(t('Zustimmung')) ?></h3>
        <div class="grid">
          <label class="wide chk"><input type="checkbox" name="privacy" value="1" required> <span><?= str_replace('%s', '<a href="datenschutz.php" target="_blank" rel="noopener">' . e(t('Datenschutzerklärung')) . '</a>', e(t('Ich habe die %s gelesen und stimme zu, dass meine Angaben zur Durchführung des Turniers gespeichert, im Ergebnissystem ianseo erfasst und – Name, Verein, Bogenklasse und Altersklasse – in der öffentlichen Liste angezeigt werden. Bei Teilnehmer/innen unter 14 Jahren stimmen die Erziehungsberechtigten zu.'))) ?></span></label>
          <label class="wide chk"><input type="checkbox" name="media" value="1" <?= $mediaReq ? 'required' : '' ?>> <span><?= str_replace('%s', $docLink !== '' ? $docLink : e(t('Ausschreibung')), e(t('Ich stimme der Nutzung von Foto-, Bild- und Filmmaterial (auch in sozialen Medien) durch den Veranstalter bzw. den jeweiligen Verein gemäß der %s zu.'))) ?><?= $mediaReq ? '' : ' <em>' . e(t('(freiwillig)')) . '</em>' ?></span></label>
        </div></section>

      <button class="submit"><?= e(t('Verbindlich anmelden')) ?></button>
    </form>
    <script>
    (function(){
      var q=function(i){return document.getElementById(i)},fo=q('foreign'),cs=q('clubsel'),co=q('clubother'),cb=q('countrybox'),cn=q('country'),nl=q('nolic'),li=q('licin');
      function a(){var F=fo.checked;cb.style.display=F?'':'none';cn.required=F;if(!F)cn.value='';
        if(cs){cs.disabled=F;cs.required=!F;if(co)co.style.display=(F||cs.value==='other')?'':'none';}
        if(nl){li.required=!nl.checked&&!F;li.disabled=nl.checked;if(nl.checked)li.value='';}}
      fo.addEventListener('change',a);if(cs)cs.addEventListener('change',a);if(nl)nl.addEventListener('change',a);a();
      var s=document.querySelector('select[name=sid]'),d=document.querySelector('select[name=division]');
      function f(){var o=s.options[s.selectedIndex],x=o&&o.dataset.div?o.dataset.div.split(','):null;
        for(var i=0;i<d.options.length;i++){var v=d.options[i].value,ok=!x||!v||x.indexOf(v)>-1;d.options[i].hidden=!ok;d.options[i].disabled=!ok;}
        if(d.selectedOptions[0]&&d.selectedOptions[0].disabled)d.value='';}
      s.addEventListener('change',f);f();
      <?php if ($autofill) { ?>
      var h=q('lichint');
      li.addEventListener('change',function(){var v=li.value.trim();if(!v||fo.checked||li.disabled)return;
        fetch('lookup.php?lic='+encodeURIComponent(v)+'&lang=<?= lang() ?>',{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(r){
          h.textContent=r.msg||'';h.className='hint'+(r.ok?' ok':'');if(!r.ok)return;
          q('fn').value=r.first;q('ln').value=r.last;if(r.sex===0||r.sex===1){var sx=document.querySelector('select[name=sex]');if(sx)sx.value=String(r.sex);}
          if(cs){if(r.club_code&&cs.querySelector('option[value="'+r.club_code+'"]'))cs.value=r.club_code;else if(r.club){cs.value='other';q('clubname').value=r.club;}a();}
        }).catch(function(){});});
      <?php } ?>
    })();
    </script></details>
    <?php
}

foreach ($sessions as $s) {
    [$st, $wl, $cap] = lists((int)$s['id']);
    echo '<section class="ses"><h3>' . e($s['name'] ?: t('Durchgang') . ' ' . $s['no']) . ' <span class="tag">' . count($st) . ($cap ? ' ' . t('von') . ' ' . $cap : '') . ' ' . e(t('Starter')) . '</span>' . ($isOpen && !session_is_open($ev, $s) ? ' <span class="tag open">' . e(t('Anmeldung geschlossen')) . '</span>' : '') . '</h3>'
       . ($s['divisions'] !== '' ? '<div class="kv"><span>' . e(t('Zugelassen')) . '</span><div>' . e($allowed($s)) . '</div></div>' : '') . '<div class="two">';
    foreach ([[t('Starterliste'), $st, false], [t('Warteliste'), $wl, true]] as [$title, $rows, $isW]) {
        echo '<div><h4>' . e($title) . ' (' . count($rows) . ')</h4>';
        if (!$rows) echo '<p class="empty">' . e(t('Noch niemand.')) . '</p>';
        else {
            echo '<div class="scroll"><table><thead><tr><th>#</th><th>' . e(t('Name')) . '</th><th>' . e(t('Verein')) . '</th><th>' . e(t('Bogenkl.')) . '</th><th>' . e(t('Altersk.')) . '</th>' . ($isW ? '<th>' . e(t('Zahlung')) . '</th>' : '') . '</tr></thead><tbody>';
            foreach ($rows as $i => $r) echo '<tr><td>' . ($i + 1) . '</td><td>' . e($r['lastname'] . ' ' . $r['firstname']) . '</td><td>' . e($r['club'] . ($r['country'] !== '' ? ' (' . $r['country'] . ')' : '')) . '</td><td>' . e($r['division']) . '</td><td>' . e($r['class']) . '</td>'
                . ($isW ? '<td><span class="tag ' . ($r['paid'] ? 'paid' : 'open') . '">' . e($r['paid'] ? t('bezahlt, Nachrücker') : t('offen')) . '</span></td>' : '') . '</tr>';
            echo '</tbody></table></div>';
        }
        echo '</div>';
    }
    echo '</div></section>';
}
page_foot();
