Turnier-Anmeldeplattform für ianseo / Tournament registration platform for ianseo
Copyright (C) 2026 Peter Führer – Version 2026.11.08-d

Dieses Projekt ist freie Software und wird unter der GNU Affero General Public License v3 (AGPLv3) veröffentlicht. Die AGPLv3 ist eine von der
Open Source Initiative (OSI) anerkannte Lizenz; das Projekt entspricht den Kriterien der Open Source Definition. Lizenztext: Datei LICENSE.
This project is free software released under the GNU Affero General Public License v3 (AGPLv3), an OSI-approved licence; the project meets the
criteria of the Open Source Definition. Licence text: file LICENSE.

=== DEUTSCH =====================================================================================================

INSTALLATION (grafischer Assistent)
1. Ordner auf den Webserver kopieren (PHP 8.1+, MySQL/MariaDB mit der ianseo-Datenbank). Ordner "uploads" muss beschreibbar sein.
2. install.php im Browser öffnen: Systemprüfung -> Datenbank-Verbindung (wird getestet, config.php wird automatisch geschrieben)
   -> Administrator anlegen -> fertig. Danach install.php löschen (Button im Assistenten).
3. Backend (admin.php) -> Einstellungen: Mailserver, Impressum, Datenschutz, Logo. -> Stammdaten (CSV) -> Turnier anlegen.

UPDATE
- Alle Dateien ersetzen, AUSSER config.php und uploads/. Danach einmal admin.php aufrufen: neue Tabellen/Spalten werden automatisch angelegt.
- Der Mailserver wird jetzt im Backend unter Einstellungen gepflegt (Passwörter verschlüsselt). Eine alte Mail-Konfiguration in config.php wird weiter
  verwendet, solange dort nichts gespeichert ist.

ROLLEN
- Administrator: alles. Organisationsleitung: Turnieranmeldungen anlegen, EIGENE Turniere bearbeiten/löschen, Kassiere freigeben, Kassier-Rechte für eigene Turniere.
- Kassier: nur für freigegebene Turniere – bezahlt setzen, zurücksetzen, Anmeldungen bearbeiten, verschieben, löschen.

STAMMDATEN-CSV (Backend -> Stammdaten): Lizenznummer; Vorname; Nachname; Vereinsnummer; Vereinsname; Bundeslandkürzel; Bundeslandname
- Daraus entstehen Schützen- und Vereinsliste. Anmeldung: Lizenznummer wird geprüft (Name UND Verein; Groß-/Kleinschreibung, Akzente und Leerzeichen
  werden ignoriert). Optional (Einstellungen -> Funktionen): Name und Verein nach Eingabe der Lizenznummer automatisch ergänzen.
- Vereine werden in ianseo als "BL-Kürzel/Vereinsname" mit der Vereinsnummer als Code angelegt.

ANMELDUNGEN: Export/Import je Turnier (CSV, gleiches Format). Lizenzfreie Teilnahme (LL1, LL2 …) je Turnier aktivierbar. Ausländische Schützen: Haken im Formular, Land (IOC-Code).
PAYPAL (optional): je Turnier "PayPal.Me-Name" und Startgeld – es wird nur ein Link angezeigt (keine Skripte, keine Cookies).
SPRACHE: DE | EN (Flaggen rechts oben). Übersetzungen: lang_en.php. Das Backend ist deutsch.
DATENSCHUTZ: datenschutz.php (DE/EN, österreichisches Recht), Cookie-Hinweis (nur technisch notwendige Cookies), Impressum (HTML erlaubt). Bitte rechtlich prüfen lassen.
LIZENZHINWEIS im Footer: Text mit Link zur AGPLv3 (ohne Logo). Für das OSI-Logo die Datei von https://opensource.org/osi-logo-files herunterladen und als
  assets/osi-approved-license.svg (oder .png) ablegen; es erscheint dann im Footer (Hinweise zur Markennutzung: https://opensource.org/trademark-guidelines).
QUELLCODE (AGPL §13): Footer-Link "Quellcode" (source.php, ZIP ohne config.php/Uploads) oder eigener Link unter Einstellungen.
ANMELDUNG SCHLIESSEN: Organisationsleitung und Administrator können die Anmeldung für das ganze Turnier und je Durchgang schließen/öffnen (Turnierliste und Seite "Anmeldungen");
  je Durchgang zusätzlich ein eigener, früherer Anmeldeschluss. Der Anmeldeschluss des Turniers schließt die Anmeldung automatisch (bis einschließlich 23:59 Uhr dieses Tages).
STAMMDATEN BEARBEITEN: Backend -> Stammdaten -> Lizenznummer anklicken = Schützen ändern oder löschen; "+ Schützen hinzufügen" legt einzelne Schützen an.
LIZENZ BEI DER INSTALLATION: Der Installationsassistent verlangt die ausdrückliche Zustimmung zur GNU AGPLv3 (Zeitpunkt wird gespeichert).
EXTERNE LIZENZPRÜFUNG (API, optional): Backend -> Einstellungen -> "Externe Lizenzprüfung (API)": Basis-URL, Client-ID und -Geheimnis (OAuth2 client_credentials, z. B.
  Archery Austria "API-Zugänge für Anwendungen"), Pfad, Parametername und Feldnamen der Antwort. Der Test zeigt die Rohantwort. Wirkt in der Anmeldung und im Autofill (lookup.php).
  Limits (5/s, 100/min) werden eingehalten, Antworten 24 h zwischengespeichert, das Geheimnis liegt verschlüsselt in der Datenbank.
SCHUTZ DER ZUGANGSDATEN: (1) Der Installer legt die Konfiguration bevorzugt EINE EBENE ÜBER dem Plattform-Ordner ab (anmeldung-config-xxxx.php, Rechte 0640). Bestehende config.php: Datei dorthin verschieben.
  (2) Die mitgelieferte .htaccess sperrt config.php, lib.php u. a. für den Abruf per Browser. (3) Eigenen Datenbank-Benutzer mit minimalen Rechten nur für diese Datenbank verwenden.
  (4) HTTPS erzwingen. (5) diag.php zeigt unter "Abruf von außen", ob die Dateien lesbar wären – danach diag.php löschen.
GESCHLECHT IM ABGLEICH: Die Stammdaten-CSV kann optional die Spalte "Geschlecht" (M/W, männlich/weiblich) enthalten; auch API (Feld "Geschlecht") und SQL-Datenbank (Spalte) liefern es.
  Bei der Anmeldung wird das Geschlecht gegen die Lizenz geprüft (Abweichung = Fehlermeldung), beim Autofill wird es übernommen.
EXTERNE SQL-DATENBANK (optional, nur Lesen): Backend -> Einstellungen -> "Externe SQL-Datenbank": Typ (MySQL/MariaDB, PostgreSQL, SQL Server), Server, Datenbank, Benutzer, Passwort, Tabelle/View und Spalten
  (Lizenznummer, Vor-/Nachname, Verein, Geschlecht, gültig). Reihenfolge der Quellen: lokale Stammdaten -> SQL -> API. Nur ein SELECT mit gebundenem Parameter; Bezeichner werden geprüft. Eigenen Lese-Benutzer verwenden!
PROTOKOLL: Backend -> Protokoll: Anmeldungen, Zahlungen, Änderungen, Verschiebungen, Löschungen, Importe, ianseo-Übertragungen, Öffnen/Schließen (mit Zeit und Benutzer). Filter, CSV-Export, automatische Löschung nach 24 Monaten.
  Sichtbar: Administrator (alles), Organisationsleitung und Kassier (nur eigene bzw. freigegebene Turniere).
E-MAIL-TEXTE JE TURNIER: Turnier-Einstellungen -> "E-Mail-Texte": eigener Betreff und Text (Deutsch/Englisch) für die Eingangsbestätigung und die Startplatz-Bestätigung, mit Platzhaltern wie {vorname}, {turnier}, {verwendungszweck}, {paypal}. Leer = Standardtext. Testmail-Knopf vorhanden.

=== ENGLISH =====================================================================================================

INSTALLATION (graphical wizard)
1. Copy the folder to the web server (PHP 8.1+, MySQL/MariaDB holding the ianseo database). The "uploads" folder must be writable.
2. Open install.php in the browser: system check -> database connection (tested, config.php is written automatically) -> create administrator -> done.
   Then delete install.php (button in the wizard).
3. Back office (admin.php) -> Settings: mail server, legal notice, privacy, logo. -> Master data (CSV) -> create a tournament.

UPDATE
- Replace all files EXCEPT config.php and uploads/. Then open admin.php once: new tables/columns are created automatically.
- The mail server is now managed in the back office under Settings (passwords encrypted). An old mail configuration in config.php keeps working until settings are saved.

ROLES
- Administrator: everything. Organiser: create registrations, edit/delete OWN tournaments, release treasurers, treasurer rights for own tournaments.
- Treasurer: only released tournaments – mark paid, reset, edit, move, delete registrations.

MASTER DATA CSV: licence number; first name; last name; club number; club name; state code; state name. Registration checks licence number, name AND club
(case, accents and spaces are ignored). Optional auto-fill of name and club after entering the licence number (Settings -> Features).
Registrations: export/import per tournament (CSV). Licence-free participation (LL1, LL2 …) per tournament. Foreign archers: tick-box, country (IOC code).
PayPal (optional): per tournament "PayPal.Me name" and entry fee – a plain link only (no scripts, no cookies).
LANGUAGE: DE | EN (flags top right). Translations: lang_en.php. The back office is in German.
PRIVACY: datenschutz.php (DE/EN, Austrian law), cookie notice (technically necessary cookies only), legal notice (HTML allowed). Please have it reviewed legally.
LICENCE NOTICE in the footer: text with link to the AGPLv3 (no logo). For the OSI logo download the file from https://opensource.org/osi-logo-files and save it as
  assets/osi-approved-license.svg (or .png); it then appears in the footer (trademark guidelines: https://opensource.org/trademark-guidelines).
SOURCE CODE (AGPL §13): footer link "Source code" (source.php, ZIP without config.php/uploads) or your own link under Settings.
CLOSING REGISTRATION: Organisers and administrators can close/open registration for the whole tournament and per session (tournament list and "Registrations" page);
  each session can also have its own, earlier deadline. The tournament deadline closes registration automatically (up to and including 23:59 on that day).
EDITING MASTER DATA: Back office -> Master data -> click the licence number = edit or delete an archer; "+ Add archer" creates single archers.
LICENCE DURING INSTALLATION: The installation wizard requires explicit agreement to the GNU AGPLv3 (the time is stored).
EXTERNAL LICENCE CHECK (API, optional): Back office -> Settings -> "External licence check (API)": base URL, client ID and secret (OAuth2 client_credentials, e.g. Archery Austria
  "API access for applications"), path, parameter name and response field names. The test shows the raw response. Used in registration and autofill (lookup.php).
  Limits (5/s, 100/min) are respected, responses cached for 24 h, the secret is stored encrypted in the database.
PROTECTING CREDENTIALS: (1) The installer stores the configuration preferably ONE LEVEL ABOVE the platform folder (anmeldung-config-xxxx.php, permissions 0640). Existing config.php: move the file there.
  (2) The supplied .htaccess blocks browser access to config.php, lib.php etc. (3) Use a dedicated database user with minimal privileges for this database only.
  (4) Enforce HTTPS. (5) diag.php shows under "Request from outside" whether the files would be readable – delete diag.php afterwards.
GENDER IN THE CHECK: The master data CSV may optionally contain a "Gender" column (M/W, male/female); the API (field "Gender") and the SQL database (column) can supply it too.
  Registration checks the gender against the licence (mismatch = error message); autofill takes it over.
EXTERNAL SQL DATABASE (optional, read only): Back office -> Settings -> "External SQL database": type (MySQL/MariaDB, PostgreSQL, SQL Server), server, database, user, password, table/view and columns
  (licence number, first/last name, club, gender, valid). Source order: local master data -> SQL -> API. A single SELECT with a bound parameter; identifiers are validated. Use a dedicated read-only user!
LOG: Back office -> Log: registrations, payments, changes, moves, deletions, imports, ianseo transfers, opening/closing (with time and user). Filters, CSV export, automatic deletion after 24 months.
  Visible: administrator (all), organiser and treasurer (own or released tournaments only).
E-MAIL TEXTS PER TOURNAMENT: Tournament settings -> "E-mail texts": own subject and text (German/English) for the registration confirmation and the starting-place confirmation, with placeholders such as {vorname}, {turnier}, {verwendungszweck}, {paypal}. Empty = default text. A test-mail button is provided.
