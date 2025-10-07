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
5. (Optional) Für Slack-Benachrichtigungen kannst du in der Installation die Slack Webhook URL eines bestehenden Incoming-Webhooks hinterlegen (siehe <https://api.slack.com/messaging/webhooks>). Lässt du das Feld leer, werden einfach keine Slack-Nachrichten versendet – alle Statusdaten landen trotzdem ausschließlich auf deinem eigenen Überwachungsserver.

> **Hinweis:** Slack wird nur benötigt, wenn du Meldungen zusätzlich in einem Slack-Channel empfangen möchtest. Für den normalen Datenfluss zwischen WordPress-Installationen und deinem Monitoring-Server ist keine Slack-Registrierung erforderlich.

## Funktionsumfang

* Überwachung von WordPress-Version, Plugins, Themes und verfügbaren Updates.
* Zentrales Dashboard mit Filter- und Übersichtskennzahlen für alle Installationen.
* Anzeige der aktuellsten verfügbaren WordPress-Version inklusive Markierung veralteter Installationen.
* Direkter Zugriff auf das jeweilige `wp-admin` aus dem Dashboard und Option zum Entfernen veralteter Sites.
* Erfassung von Sicherheitsindikatoren (z. B. automatische Updates, `DISALLOW_FILE_EDIT`).
* Automatische Benachrichtigungen per E-Mail und Slack bei kritischen Zuständen.
* Detailansicht je Site mit SEO-Einstellungen, Wortstatistiken sowie Plugin- und Theme-Zusammenfassungen.
* Statistiken zu Benutzern, Kommentaren und Beiträgen.
* Optionales SERP-Tracking via [SerpAPI](https://serpapi.com/) mit Anzeige der besten Google-Position pro Installation.
* JSON-Backups aller gespeicherten Daten auf Knopfdruck.
* Erweiterbar über WordPress-Hooks und serverseitige Auswertungen.

### Optional: Slack-Benachrichtigungen

* Slack ist ein zusätzlicher Kanal, falls du Warnungen direkt in einem Slack-Channel sehen möchtest.
* Ohne eingetragene Webhook-URL bleibt Slack deaktiviert – die WordPress-Installationen senden ihre Daten weiterhin nur an deinen Überwachungsserver.
* Wenn du Slack nutzen willst, benötigst du einen bestehenden Slack-Workspace mit einem [Incoming-Webhook](https://api.slack.com/messaging/webhooks). Die generierte URL trägst du in der Server-Installation ein.

### Optional: Google-Rankings

* Die SERP-Auswertung nutzt den Drittanbieter SerpAPI. Du hinterlegst den API-Schlüssel einmalig im Server-Installer oder direkt in der `config.php`.
* Für jede überwachte Installation werden die konfigurierten Suchbegriffe (standardmäßig die Domain) geprüft. Das Dashboard zeigt die beste gefundene Position, die Anzahl erfolgreicher Treffer sowie den Zeitpunkt der letzten Aktualisierung.
* Lässt du den API-Schlüssel leer, bleibt die Funktion vollständig deaktiviert – alle Monitoring-Daten verbleiben weiterhin ausschließlich auf deinem Server.

## Weiterführende Informationen

* [Plugin-Dokumentation](plugin/wp-monitor-client/README.md)
* [Server-Dokumentation](server/README.md)

Verbesserungen wie Dashboards, zusätzliche Prüfungen (Backups, SSL-Zertifikate etc.) oder Benachrichtigungen können auf Basis dieser Grundstruktur ergänzt werden.
