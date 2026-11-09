<?php
/* Turnier-Anmeldeplattform für ianseo – Copyright (C) 2026 Peter Führer – GNU AGPLv3 (OSI-approved), siehe LICENSE – Version 2026.11.08-a */
// Normalerweise erzeugt install.php diese Datei automatisch (grafischer Assistent). Manuell: als config.php speichern.
// Mailserver, Impressum, Datenschutz und Logo werden im Backend unter "Einstellungen" gepflegt.
return [
    'site_title' => 'Turnier-Anmeldung',
    'debug' => false,                      // true: Fehlermeldungen im Browser anzeigen (nur zur Fehlersuche)
    'secret' => 'ZUFAELLIGE_ZEICHENFOLGE', // verschlüsselt Mail-Passwörter in der Datenbank
    'db' => [
        'host' => 'localhost',             // bei World4You z. B. 'mysqlsvr80.world4you.com'
        'port' => '',                      // optional
        'name' => 'ianseo',
        'user' => 'ianseo',
        'pass' => 'HIER_PASSWORT',
    ],
];
