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
    'app' => [
        'public_url' => 'https://monitor.example.com',
    ],
    'api_keys' => [
        'mein-api-schluessel' => 'Standardgruppe',
    ],
    'http_auth' => [
        'realm'         => 'WP Monitor',
        'username'      => 'monitor',
        'password_hash' => password_hash('ein-sicheres-passwort', PASSWORD_DEFAULT),
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
