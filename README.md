# Wordpress Monitoring

Dieses Repository enthält zwei Komponenten, um mehrere WordPress-Installationen zentral zu überwachen:

1. **WP Monitor Client** – ein WordPress-Plugin, das regelmäßig Statusinformationen (Core-, Plugin- & Theme-Versionen, Updates, Benutzerzahlen usw.) sammelt und an einen Server sendet.
2. **WP Monitor Server** – ein schlankes PHP/MySQL-Skript, das die eingehenden Daten entgegennimmt und in einer Datenbank speichert.

## Schnellstart

### Client (WordPress-Plugin)

1. Kopiere `plugin/wp-monitor-client` in das Verzeichnis `wp-content/plugins/` einer WordPress-Installation.
2. Aktiviere das Plugin im Backend und öffne **Einstellungen → WP Monitor**.
3. Trage die URL des Servers sowie einen gültigen API-Schlüssel ein. Optional kannst du eine Installationskennung und das Intervall konfigurieren.
4. Speichere die Einstellungen. Das Plugin sendet anschließend automatisch Statusberichte im gewünschten Intervall. Eine manuelle Übertragung ist jederzeit möglich.

### Server (API)

1. Kopiere den Ordner `server` auf einen Webserver mit PHP 8.1+ und MySQL/MariaDB.
2. Rufe `install.php` im Browser auf, um Datenbanktabellen anzulegen, `config.php` zu generieren und HTTP-Auth-Zugangsdaten zu setzen.
3. Der Server stellt anschließend das Plugin-Paket über `plugin-download.php` bereit (z. B. für `wp plugin install <URL>`), bietet ein Dashboard unter `dashboard.php` und liefert Backups via `backup.php` aus.
4. Stelle sicher, dass `server/api.php`, `server/dashboard.php`, `server/plugin-download.php` und `server/backup.php` über HTTPS erreichbar sind und per HTTP-Auth geschützt werden.
5. Für Slack-Benachrichtigungen hinterlegst du in der Installation die Slack Webhook URL deines Incoming-Webhooks (siehe <https://api.slack.com/messaging/webhooks>), damit Meldungen direkt in einem Channel landen.

## Funktionsumfang

* Überwachung von WordPress-Version, Plugins, Themes und verfügbaren Updates.
* Zentrales Dashboard mit Filter- und Übersichtskennzahlen für alle Installationen.
* Erfassung von Sicherheitsindikatoren (z. B. automatische Updates, `DISALLOW_FILE_EDIT`).
* Automatische Benachrichtigungen per E-Mail und Slack bei kritischen Zuständen.
* Statistiken zu Benutzern, Kommentaren und Beiträgen.
* JSON-Backups aller gespeicherten Daten auf Knopfdruck.
* Erweiterbar über WordPress-Hooks und serverseitige Auswertungen.

## Weiterführende Informationen

* [Plugin-Dokumentation](plugin/wp-monitor-client/README.md)
* [Server-Dokumentation](server/README.md)

Verbesserungen wie Dashboards, zusätzliche Prüfungen (Backups, SSL-Zertifikate etc.) oder Benachrichtigungen können auf Basis dieser Grundstruktur ergänzt werden.
