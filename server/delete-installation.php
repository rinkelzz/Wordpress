<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$configFile = __DIR__ . '/config.php';
if (! file_exists($configFile)) {
    http_response_code(500);
    echo 'Konfiguration nicht gefunden.';
    exit;
}

$config = require $configFile;

wp_monitor_require_basic_auth($config, 'html');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Nur POST-Anfragen erlaubt.';
    exit;
}

$installationId = (int)($_POST['installation_id'] ?? 0);
$redirect      = (string)($_POST['redirect'] ?? 'dashboard.php');

if ($installationId <= 0) {
    http_response_code(400);
    echo 'Ungültige Installation.';
    exit;
}

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
    echo 'Datenbankverbindung fehlgeschlagen: ' . htmlspecialchars($throwable->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

$deleteStmt = $pdo->prepare('DELETE FROM installations WHERE id = :id');
$deleteStmt->execute([':id' => $installationId]);

if ($deleteStmt->rowCount() === 0) {
    http_response_code(404);
    echo 'Installation wurde nicht gefunden oder bereits entfernt.';
    exit;
}

// Nur relative Weiterleitungen erlauben
if ($redirect === '' || preg_match('#^(?:[a-z][a-z0-9+\-.]*:)?//#i', $redirect)) {
    $redirect = 'dashboard.php';
}

if (strpos($redirect, '?') === false) {
    $redirect .= '?deleted=1';
} else {
    $redirect .= '&deleted=1';
}

header('Location: ' . $redirect);
exit;
