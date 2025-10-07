<?php
/**
 * Beispielkonfiguration für den WP Monitor Server.
 */

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
        'mein-api-schluessel' => 'Standardgruppe',
    ],
    'http_auth' => [
        'realm'         => 'WP Monitor',
        'username'      => 'monitor',
        'password_hash' => password_hash('ein-sicheres-passwort', PASSWORD_DEFAULT),
    ],
];
