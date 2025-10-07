<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$configFile = __DIR__ . '/config.php';
if (! file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server configuration missing.';
    exit;
}

$config = require $configFile;

wp_monitor_require_basic_auth($config, 'plain');

$pluginDir = realpath(__DIR__ . '/../plugin/wp-monitor-client');
if ($pluginDir === false || ! is_dir($pluginDir)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Plugin-Verzeichnis wurde nicht gefunden. Stelle sicher, dass "plugin/wp-monitor-client" vorhanden ist.';
    exit;
}

if (! class_exists(ZipArchive::class)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Die PHP ZipArchive-Erweiterung ist nicht verfügbar.';
    exit;
}

$tmpFile = tempnam(sys_get_temp_dir(), 'wpmc_');

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ZIP-Datei konnte nicht erstellt werden.';
    @unlink($tmpFile);
    exit;
}

$basePathLength = strlen($pluginDir);
$rootFolder     = basename($pluginDir);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $fileInfo) {
    /** @var SplFileInfo $fileInfo */
    $relativePath = substr($fileInfo->getPathname(), $basePathLength + 1);
    $localName    = $rootFolder . '/' . str_replace('\\', '/', $relativePath);

    if ($fileInfo->isDir()) {
        $zip->addEmptyDir($localName);
    } else {
        $zip->addFile($fileInfo->getPathname(), $localName);
    }
}

$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="wp-monitor-client.zip"');
header('Content-Length: ' . filesize($tmpFile));

$stream = fopen($tmpFile, 'rb');
if ($stream !== false) {
    fpassthru($stream);
    fclose($stream);
}

@unlink($tmpFile);
exit;
