<?php
declare(strict_types=1);

/**
 * Stellt Hilfsfunktionen für die HTTP-Authentifizierung bereit.
 */
function wp_monitor_require_basic_auth(array $config, string $responseType = 'json'): void
{
    $username     = $config['http_auth']['username'] ?? '';
    $passwordHash = $config['http_auth']['password_hash'] ?? '';

    if ($username === '' || $passwordHash === '') {
        return;
    }

    $realm = $config['http_auth']['realm'] ?? 'WP Monitor';

    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $providedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    $isValid = ($providedUser === $username) && ($providedPass !== '' && password_verify($providedPass, $passwordHash));

    if ($isValid) {
        return;
    }

    header('WWW-Authenticate: Basic realm="' . addslashes($realm) . '", charset="UTF-8"');
    http_response_code(401);

    if ($responseType === 'json') {
        if (! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'Unauthorized.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        if (! headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Unauthorized.';
    }

    exit;
}
