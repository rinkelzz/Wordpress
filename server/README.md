# WP Monitor Server

Dieses Verzeichnis enthält einen leichtgewichtigen Überwachungsserver, der Statusdaten aus dem „WP Monitor Client“-Plugin entgegennimmt, speichert und als Dashboard aufbereitet. Neben der API sind ein Web-Dashboard, Benachrichtigungen per E-Mail/Slack sowie ein JSON-Backup-Export bereits integriert.

## Installation

1. Lade den Ordner `server` auf deinen Webserver hoch.
2. Rufe `install.php` im Browser auf (z. B. `https://monitor.example.com/install.php`).
3. Trage die Datenbankzugangsdaten, einen API-Schlüssel (optional), Zugangsdaten für die HTTP-Authentifizierung, die öffentliche Basis-URL des Dashboards sowie Empfänger für Benachrichtigungen ein. Optional kannst du hier auch die Slack Webhook URL eines Incoming-Webhooks hinterlegen, damit Benachrichtigungen in Slack erscheinen. Ebenfalls optional ist der SerpAPI-Schlüssel für die Google-Ranking-Auswertung. Lässt du eines der Felder leer, bleibt die jeweilige Integration vollständig deaktiviert.
4. Die Installation legt die Tabellen an, erstellt `config.php` und zeigt dir den generierten API-Schlüssel an.
5. Nach erfolgreicher Einrichtung kannst du das Plugin direkt über `plugin-download.php` herunterladen, das Dashboard unter `dashboard.php` aufrufen und jederzeit ein Backup über `backup.php` exportieren.
6. Entferne oder schütze `install.php` zusätzlich, sobald die Installation abgeschlossen ist.

## Konfiguration

```php
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'wp_monitor',
        'user'     => 'wp_monitor',
        'password' => 'geheimes-passwort',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'public_url' => 'https://monitor.example.com',
    ],
    'api_keys' => [
        'mein-api-schluessel' => 'Kundenname oder Standort',
    ],
    'http_auth' => [
        'realm'         => 'WP Monitor',
        'username'      => 'monitor',
        'password_hash' => '$2y$10$....',
    ],
    'notifications' => [
        'email' => [
            'recipients' => 'admin@example.com,team@example.com',
            'from'       => 'monitor@example.com',
            'subject'    => 'WP Monitor Hinweis',
        ],
        'slack' => [
            'webhook_url' => 'https://hooks.slack.com/services/...',
        ],
    ],
    'serp' => [
        'api_key'       => 'serp-api-key',
        'keywords'      => ['%domain%'],
        'results'       => 10,
        'google_domain' => 'google.com',
        'gl'            => '',
        'hl'            => '',
        'location'      => '',
        'timeout'       => 10,
    ],
];
```

* Jeder API-Schlüssel ordnet eingehende Daten einer Gruppe zu (z. B. Kundenname oder Standort).
* Weitere Installationen können denselben Schlüssel teilen, um sie logisch zusammenzufassen.
* Die Basic-Auth-Zugangsdaten sichern API, Dashboard (`dashboard.php`), Plugin-Download (`plugin-download.php`) und Backup-Export (`backup.php`).
* Die Slack Webhook URL ist die Incoming-Webhook-Adresse deines Slack-Workspaces (siehe <https://api.slack.com/messaging/webhooks>). Darüber sendet der Server Statusmeldungen direkt in den ausgewählten Channel.
* **Keine Pflicht:** Ohne Slack Webhook URL verschickt der Server weiterhin alle E-Mails und nimmt Daten vom Plugin entgegen – Slack ist ein reiner Zusatzkanal.
* Die SerpAPI-Integration prüft optional die Google-Suchergebnisse für jede Site. Hinterlege dazu deinen SerpAPI-Schlüssel und (optional) eigene Suchbegriffe. Der Platzhalter `%domain%` wird automatisch durch die Domain der jeweiligen Installation ersetzt.

## Erwartetes Datenformat

Das Plugin sendet JSON-Daten mit u. a. folgenden Feldern:

```json
{
  "site": {
    "url": "https://example.com",
    "name": "Beispielseite",
    "language": "de-DE",
    "token": "installation-123"
  },
  "environment": {
    "wp_version": "6.4.3",
    "php_version": "8.2.7",
    "mysql_version": "10.11.2-MariaDB",
    "php_memory_limit": "256M"
  },
  "updates": {
    "core_available": 1,
    "plugins": 2,
    "themes": 0
  },
  "counts": {
    "pending_comments": 5,
    "registered_users": 47
  },
  "plugins": [
    {
      "file": "akismet/akismet.php",
      "name": "Akismet",
      "version": "5.3",
      "is_active": true,
      "has_update": false
    }
  ],
  "themes": [],
  "insights": {
    "seo": {
      "tagline": "Meine Seite",
      "search_engine_visibility": true
    },
    "content": {
      "total_words": 12345,
      "published_posts": 42
    },
    "plugins": {
      "total": 27,
      "active": 18
    },
    "media": {
      "attachments": 312
    }
  },
  "generated_at": "2024-04-30 13:37:00"
}
```

Alle empfangenen Daten werden zusätzlich als JSON-Blob in der Tabelle `installation_checks.payload` gespeichert, damit später neue Auswertungen möglich sind.

## Antwort des Servers

Bei Erfolg gibt `api.php` eine JSON-Antwort aus:

```json
{
  "status": "ok",
  "installation_id": 1,
  "check_id": 42,
  "received_at": "2024-04-30T11:37:22+00:00"
}
```

Schlägt etwas fehl, erhält der Client eine passende Fehlermeldung und einen HTTP-Statuscode (z. B. `401 Unauthorized` oder `500 Internal Server Error`).

## Dashboard & Benachrichtigungen

* `dashboard.php` listet alle Installationen mit Filteroptionen für Gruppen und Freitextsuche auf.
* Die Kopfzeile blendet die von WordPress aktuell veröffentlichte Version ein und markiert Installationen, die hinterherhinken.
* Der Seitenname ist mit dem jeweiligen `wp-admin` verknüpft, sodass du Updates mit einem Klick im Backend anstoßen kannst.
* Über den Link **Details** erhältst du pro Installation zusätzliche Kennzahlen (SEO-Einstellungen, Wortanzahl, Plugin-/Theme-Zusammenfassung, Medienstatus usw.).
* Nicht mehr benötigte Installationen lassen sich direkt im Dashboard löschen – sämtliche zugehörigen Prüfungen werden dabei mit entfernt.
* Kennzahlen zu offenen Updates, kritischen Core-Sicherheitsupdates und überfälligen Checks werden aggregiert angezeigt.
* Optional zeigt das Dashboard pro Site den besten gefundenen Google-Rang inklusive Anzahl erfolgreicher Keywords und Zeitpunkt der letzten Aktualisierung an.
* Beim Eingang einer neuen Meldung verschickt der Server (sofern konfiguriert) Benachrichtigungen per E-Mail und/oder Slack, sobald Sicherheitsupdates, offene Updates oder viele Kommentare vorliegen.
* `backup.php` liefert einen JSON-Export sämtlicher Tabellen – optional direkt als Download.

### Slack-Benachrichtigungen (optional)

* Die Slack-Anbindung ist ein zusätzlicher Komfortkanal. Die Statusdaten werden weiterhin ausschließlich zwischen Plugin und deinem eigenen Server übertragen.
* Nur wenn du Slack-Meldungen erhalten möchtest, musst du in deinem Workspace einen Incoming-Webhook anlegen. Eine Anleitung findest du in der [Slack-Dokumentation](https://api.slack.com/messaging/webhooks).
* Kopiere die erzeugte Webhook-URL in das Installer-Feld oder direkt in `config.php`. Ohne diesen Schritt bleibt Slack deaktiviert, alles andere funktioniert unverändert.

### Google-Rankings (optional)

* Für die SERP-Auswertung nutzt der Server [SerpAPI](https://serpapi.com/). Die Abfragen werden ausschließlich durchgeführt, wenn ein gültiger API-Schlüssel hinterlegt ist.
* Du kannst im Installer mehrere Suchbegriffe (ein Begriff pro Zeile) angeben. Der Platzhalter `%domain%` ersetzt der Server automatisch durch die Domain jeder überwachten Installation.
* Die Ergebnisse speichert der Server in der Tabelle `installation_rankings` und zeigt im Dashboard die beste Position pro Site an. Bei Fehlern oder fehlenden Treffern bleibt die Installation weiterhin sichtbar, es werden jedoch keine Ranking-Werte angezeigt.

## Sicherheitshinweise

* Verwende ausschließlich HTTPS, damit API-Schlüssel und Daten geschützt sind.
* Vergib starke, eindeutige API-Schlüssel und ändere sie regelmäßig.
* Bewahre die Zugangsdaten für die HTTP-Authentifizierung sicher auf und ändere sie bei Bedarf.
* Beschränke den Zugriff auf `api.php` zusätzlich (z. B. per Firewall), falls der Endpunkt nicht öffentlich erreichbar sein soll.
* Überwache die Größe der `payload`-Spalte und rotiere alte Datensätze, um die Datenbank schlank zu halten.

## Erweiterungsideen

* Weitere Benachrichtigungskanäle wie Microsoft Teams oder Telegram.
* Erweiterte Dashboards mit Diagrammen oder Drill-down in einzelne Checks.
* Zusätzliche Prüfungen wie SSL-Zertifikatslaufzeit, verfügbare Backups oder Server-Ressourcenauslastung.
