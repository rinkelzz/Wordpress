# WP Monitor Server

Dieses Verzeichnis enthält ein schlankes PHP-Skript, das Statusdaten aus dem „WP Monitor Client“-Plugin entgegennimmt und in einer MySQL-Datenbank speichert. Es ist als Ausgangspunkt gedacht und kann nach Bedarf erweitert werden (z. B. um ein Dashboard oder Benachrichtigungen).

## Installation

1. Lade den Ordner `server` auf deinen Webserver hoch.
2. Rufe `install.php` im Browser auf (z. B. `https://monitor.example.com/install.php`).
3. Trage die Datenbankzugangsdaten, einen API-Schlüssel (optional) sowie Zugangsdaten für die HTTP-Authentifizierung ein.
4. Die Installation legt die Tabellen an, erstellt `config.php` und zeigt dir den generierten API-Schlüssel an.
5. Nach erfolgreicher Einrichtung kannst du das Plugin direkt über `plugin-download.php` herunterladen.
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
    'api_keys' => [
        'mein-api-schluessel' => 'Kundenname oder Standort',
    ],
    'http_auth' => [
        'realm'         => 'WP Monitor',
        'username'      => 'monitor',
        'password_hash' => '$2y$10$....',
    ],
];
```

* Jeder API-Schlüssel ordnet eingehende Daten einer Gruppe zu (z. B. Kundenname oder Standort).
* Weitere Installationen können denselben Schlüssel teilen, um sie logisch zusammenzufassen.
* Die Basic-Auth-Zugangsdaten sichern sowohl die API als auch den direkten Plugin-Download (`plugin-download.php`).

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

## Sicherheitshinweise

* Verwende ausschließlich HTTPS, damit API-Schlüssel und Daten geschützt sind.
* Vergib starke, eindeutige API-Schlüssel und ändere sie regelmäßig.
* Bewahre die Zugangsdaten für die HTTP-Authentifizierung sicher auf und ändere sie bei Bedarf.
* Beschränke den Zugriff auf `api.php` zusätzlich (z. B. per Firewall), falls der Endpunkt nicht öffentlich erreichbar sein soll.
* Überwache die Größe der `payload`-Spalte und rotiere alte Datensätze, um die Datenbank schlank zu halten.

## Erweiterungsideen

* E-Mail- oder Slack-Benachrichtigungen, wenn Sicherheitsupdates verfügbar sind.
* Dashboard zur Visualisierung der Daten (z. B. mit Laravel, Symfony oder einem JavaScript-Frontend).
* Zusätzliche Prüfungen wie SSL-Zertifikatslaufzeit, verfügbare Backups oder Server-Ressourcenauslastung.
