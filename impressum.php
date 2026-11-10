<?php
/* Turnier-Anmeldeplattform für ianseo – Copyright (C) 2026 Peter Führer – GNU AGPLv3 (OSI-approved), siehe LICENSE – Version 2026.11.08-d */
require __DIR__ . '/lib.php';
page_head(t('Impressum'), '<a href="index.php">' . e(t('Zur Anmeldung')) . '</a>');
$tx = trim(setting('impressum'));
echo '<h2>' . e(t('Impressum')) . '</h2>';
if ($tx === '') echo '<p class="info">' . e(t('Das Impressum wurde noch nicht hinterlegt.')) . '</p>';
else echo '<div class="box">' . sanitize_html($tx) . '</div>';
page_foot();
