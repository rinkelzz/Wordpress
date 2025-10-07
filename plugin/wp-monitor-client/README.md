# WP Monitor Client

Dieses WordPress-Plugin sendet regelmäßig Statusinformationen (Core-, Plugin- und Theme-Versionen, verfügbare Updates, Benutzerzahlen u. v. m.) an einen zentralen WP Monitor Server. Auf dem Server stehen ein Dashboard, Benachrichtigungen und Backup-Exporte bereit, sodass alle angebundenen Installationen auf einen Blick verwaltet werden können.

## Installation

1. Kopiere den Ordner `wp-monitor-client` in das Verzeichnis `wp-content/plugins/` deiner WordPress-Installation.
2. Aktiviere das Plugin im WordPress-Backend unter **Plugins → Installierte Plugins**.
3. Öffne anschließend **Einstellungen → WP Monitor** und trage den URL deines Servers sowie den API-Schlüssel ein.
4. Wenn der Server per HTTP-Authentifizierung geschützt ist, hinterlege dort auch Benutzername und Passwort.
5. Optional kannst du eine eigene Installationskennung (z. B. Kundenname oder Standort) vergeben und das Sendeintervall anpassen.
6. Alternativ kann das Plugin direkt von `plugin-download.php` des Servers installiert werden (z. B. via WP-CLI `wp plugin install https://monitor.example.com/plugin-download.php --activate`).

## Funktionsumfang

* Überträgt folgende Informationen:
  * WordPress-Version und Serverumgebung (PHP, MySQL, Memory-Limits).
  * Anzahl verfügbarer Core-, Plugin- und Theme-Updates (inkl. Sicherheitsupdates).
  * Liste aller Plugins/Themes inkl. Update-Status und Aktivierung.
  * Kommentar- und Benutzerzahlen sowie veröffentlichte/Entwurf-Posts.
  * Sicherheitsrelevante Einstellungen (z. B. automatische Updates, `DISALLOW_FILE_EDIT`).
* Sendet Daten automatisch im gewählten Intervall per WP-Cron.
* Manuelle Übertragung direkt aus der Einstellungsseite möglich.
* API-Header (`X-WP-Monitor-*`) helfen dem Server bei der Zuordnung.

## Cron & Debugging

Das Plugin nutzt den WordPress-Cron. Damit automatische Übertragungen funktionieren, muss die Seite regelmäßig aufgerufen werden (oder es wird ein Server-Cronjob eingerichtet, der `wp-cron.php` anstößt).

Bei Problemen findest du in der Browser-Konsole des Adminbereichs und im Fehlerlog deiner WordPress-Instanz weitere Hinweise. Außerdem kannst du die manuelle Übertragung nutzen, um sofortige Rückmeldungen vom Server zu erhalten.

## Erweiterbarkeit

Der gesammelte Datensatz kann über den Filter `wp_monitor_client_snapshot` angepasst werden:

```php
add_filter( 'wp_monitor_client_snapshot', function ( array $snapshot ) {
    $snapshot['custom']['health_status'] = get_option( 'health-check-status', 'unknown' );
    return $snapshot;
} );
```

So können projektspezifische Kennzahlen ergänzt werden.
