<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$configFile = __DIR__ . '/config.php';
if (! file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Konfiguration nicht gefunden.']);
    exit;
}

$config = require $configFile;

wp_monitor_require_basic_auth($config, 'json');

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['db']['host'],
        $config['db']['port'],
        $config['db']['name'],
        $config['db']['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $throwable) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Datenbankverbindung fehlgeschlagen', 'message' => $throwable->getMessage()]);
    exit;
}

$download = isset($_GET['download']);
$timestamp = gmdate('Y-m-d_H-i-s');

$payload = [
    'generated_at' => gmdate('c'),
    'installations' => $pdo->query('SELECT * FROM installations ORDER BY site_group, site_name')->fetchAll() ?: [],
    'installation_checks' => $pdo->query('SELECT * FROM installation_checks ORDER BY checked_at DESC')->fetchAll() ?: [],
    'installation_plugins' => $pdo->query('SELECT * FROM installation_plugins')->fetchAll() ?: [],
    'installation_themes' => $pdo->query('SELECT * FROM installation_themes')->fetchAll() ?: [],
    'installation_security' => $pdo->query('SELECT * FROM installation_security')->fetchAll() ?: [],
];

$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
$json      = json_encode($payload, $jsonFlags);

if ($json === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Backup konnte nicht erstellt werden.']);
    exit;
}

if ($download) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="wp-monitor-backup-' . $timestamp . '.json"');
    echo $json;
    exit;
}

header('Content-Type: application/json; charset=utf-8');

echo $json;
