<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';

header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/config.php';
if ( ! file_exists( $configFile ) ) {
    http_response_code( 500 );
    echo json_encode( [ 'error' => 'Server configuration missing.' ] );
    exit;
}

$config = require $configFile;

wp_monitor_require_basic_auth( $config, 'json' );

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
    http_response_code( 405 );
    header( 'Allow: POST' );
    echo json_encode( [ 'error' => 'Method not allowed.' ] );
    exit;
}

$apiKey = $_SERVER['HTTP_X_WP_MONITOR_KEY'] ?? '';
if ( empty( $apiKey ) || ! isset( $config['api_keys'][ $apiKey ] ) ) {
    http_response_code( 401 );
    echo json_encode( [ 'error' => 'Unauthorized.' ] );
    exit;
}

$group = $config['api_keys'][ $apiKey ];
$siteIdentifier = $_SERVER['HTTP_X_WP_MONITOR_SITE'] ?? '';
$siteIdentifier = trim( $siteIdentifier );

$rawBody = file_get_contents( 'php://input' );
$data    = json_decode( $rawBody, true );

if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
    http_response_code( 400 );
    echo json_encode( [ 'error' => 'Invalid JSON payload.' ] );
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

    $pdo = new PDO( $dsn, $config['db']['user'], $config['db']['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ] );
} catch ( PDOException $exception ) {
    http_response_code( 500 );
    echo json_encode( [ 'error' => 'Database connection failed.', 'message' => $exception->getMessage() ] );
    exit;
}

$siteData      = $data['site'] ?? [];
$environment   = $data['environment'] ?? [];
$updates       = $data['updates'] ?? [];
$counts        = $data['counts'] ?? [];
$security      = $data['security'] ?? [];
$plugins       = $data['plugins'] ?? [];
$themes        = $data['themes'] ?? [];
$generatedAt   = $data['generated_at'] ?? gmdate( 'Y-m-d H:i:s' );
$siteUrl       = isset( $siteData['url'] ) ? trim( (string) $siteData['url'] ) : '';
$siteName      = isset( $siteData['name'] ) ? trim( (string) $siteData['name'] ) : '';
$siteToken     = $siteIdentifier ?: ( isset( $siteData['token'] ) ? (string) $siteData['token'] : '' );
$siteToken     = $siteToken ?: hash( 'sha256', $siteUrl ?: uniqid( 'site_', true ) );

if ( empty( $siteUrl ) ) {
    http_response_code( 400 );
    echo json_encode( [ 'error' => 'Site URL missing in payload.' ] );
    exit;
}

$pdo->beginTransaction();

try {
    $installationId = null;

    $selectStmt = $pdo->prepare( 'SELECT id FROM installations WHERE site_token = :token LIMIT 1' );
    $selectStmt->execute( [ ':token' => $siteToken ] );
    $existing = $selectStmt->fetch();

    $now = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );

    if ( $existing ) {
        $installationId = (int) $existing['id'];
        $updateStmt     = $pdo->prepare( 'UPDATE installations SET site_url = :url, site_name = :name, site_group = :grp, last_seen = :seen WHERE id = :id' );
        $updateStmt->execute( [
            ':url'  => $siteUrl,
            ':name' => $siteName,
            ':grp'  => $group,
            ':seen' => $now,
            ':id'   => $installationId,
        ] );
    } else {
        $insertStmt = $pdo->prepare( 'INSERT INTO installations (site_token, site_url, site_name, site_group, created_at, last_seen) VALUES (:token, :url, :name, :grp, :created, :seen)' );
        $insertStmt->execute( [
            ':token'   => $siteToken,
            ':url'     => $siteUrl,
            ':name'    => $siteName,
            ':grp'     => $group,
            ':created' => $now,
            ':seen'    => $now,
        ] );
        $installationId = (int) $pdo->lastInsertId();
    }

    $insertCheck = $pdo->prepare(
        'INSERT INTO installation_checks (
            installation_id,
            checked_at,
            wp_version,
            php_version,
            mysql_version,
            core_updates,
            core_security_updates,
            plugin_updates,
            theme_updates,
            translation_updates,
            pending_comments,
            spam_comments,
            registered_users,
            draft_posts,
            published_posts,
            payload
        ) VALUES (
            :installation_id,
            :checked_at,
            :wp_version,
            :php_version,
            :mysql_version,
            :core_updates,
            :core_security_updates,
            :plugin_updates,
            :theme_updates,
            :translation_updates,
            :pending_comments,
            :spam_comments,
            :registered_users,
            :draft_posts,
            :published_posts,
            :payload
        )'
    );

    $insertCheck->execute( [
        ':installation_id'       => $installationId,
        ':checked_at'            => $generatedAt,
        ':wp_version'            => (string) ( $environment['wp_version'] ?? '' ),
        ':php_version'           => (string) ( $environment['php_version'] ?? '' ),
        ':mysql_version'         => (string) ( $environment['mysql_version'] ?? '' ),
        ':core_updates'          => (int) ( $updates['core_available'] ?? 0 ),
        ':core_security_updates' => (int) ( $updates['core_security'] ?? 0 ),
        ':plugin_updates'        => (int) ( $updates['plugins'] ?? 0 ),
        ':theme_updates'         => (int) ( $updates['themes'] ?? 0 ),
        ':translation_updates'   => (int) ( $updates['translations'] ?? 0 ),
        ':pending_comments'      => (int) ( $counts['pending_comments'] ?? 0 ),
        ':spam_comments'         => (int) ( $counts['spam_comments'] ?? 0 ),
        ':registered_users'      => (int) ( $counts['registered_users'] ?? 0 ),
        ':draft_posts'           => (int) ( $counts['draft_posts'] ?? 0 ),
        ':published_posts'       => (int) ( $counts['published_posts'] ?? 0 ),
        ':payload'               => json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
    ] );

    $checkId = (int) $pdo->lastInsertId();

    $pluginStmt = $pdo->prepare( 'INSERT INTO installation_plugins (check_id, plugin_file, plugin_name, plugin_version, is_active, has_update, update_version) VALUES (:check_id, :file, :name, :version, :is_active, :has_update, :update_version)' );
    foreach ( $plugins as $plugin ) {
        $pluginStmt->execute( [
            ':check_id'      => $checkId,
            ':file'          => substr( (string) ( $plugin['file'] ?? '' ), 0, 255 ),
            ':name'          => substr( (string) ( $plugin['name'] ?? '' ), 0, 190 ),
            ':version'       => substr( (string) ( $plugin['version'] ?? '' ), 0, 50 ),
            ':is_active'     => ! empty( $plugin['is_active'] ) ? 1 : 0,
            ':has_update'    => ! empty( $plugin['has_update'] ) ? 1 : 0,
            ':update_version'=> substr( (string) ( $plugin['update_version'] ?? '' ), 0, 50 ),
        ] );
    }

    $themeStmt = $pdo->prepare( 'INSERT INTO installation_themes (check_id, theme_stylesheet, theme_name, theme_version, is_active, has_update, update_version) VALUES (:check_id, :stylesheet, :name, :version, :is_active, :has_update, :update_version)' );
    foreach ( $themes as $theme ) {
        $themeStmt->execute( [
            ':check_id'      => $checkId,
            ':stylesheet'    => substr( (string) ( $theme['stylesheet'] ?? '' ), 0, 191 ),
            ':name'          => substr( (string) ( $theme['name'] ?? '' ), 0, 190 ),
            ':version'       => substr( (string) ( $theme['version'] ?? '' ), 0, 50 ),
            ':is_active'     => ! empty( $theme['is_active'] ) ? 1 : 0,
            ':has_update'    => ! empty( $theme['has_update'] ) ? 1 : 0,
            ':update_version'=> substr( (string) ( $theme['update_version'] ?? '' ), 0, 50 ),
        ] );
    }

    $securityStmt = $pdo->prepare( 'INSERT INTO installation_security (check_id, automatic_updates, force_ssl_admin, disallow_file_edit, disallow_file_mods, core_last_check) VALUES (:check_id, :automatic_updates, :force_ssl_admin, :disallow_file_edit, :disallow_file_mods, :core_last_check)' );
    $securityStmt->execute( [
        ':check_id'           => $checkId,
        ':automatic_updates'  => ! empty( $security['automatic_updates'] ) ? 1 : 0,
        ':force_ssl_admin'    => ! empty( $security['force_ssl_admin'] ) ? 1 : 0,
        ':disallow_file_edit' => ! empty( $security['disallow_file_edit'] ) ? 1 : 0,
        ':disallow_file_mods' => ! empty( $security['disallow_file_mods'] ) ? 1 : 0,
        ':core_last_check'    => (int) ( $security['last_core_update_check'] ?? 0 ),
    ] );

    $pdo->commit();
} catch ( Throwable $throwable ) {
    $pdo->rollBack();
    http_response_code( 500 );
    echo json_encode( [ 'error' => 'Failed to persist payload.', 'message' => $throwable->getMessage() ] );
    exit;
}

try {
    wp_monitor_refresh_google_rankings( $pdo, $installationId, $siteUrl, $config );
} catch ( Throwable $exception ) {
    error_log( '[WP Monitor] Google-Ranking konnte nicht aktualisiert werden: ' . $exception->getMessage() );
}

$dashboardBase = '';
if ( ! empty( $config['app']['public_url'] ) ) {
    $dashboardBase = rtrim( (string) $config['app']['public_url'], '/' );
}

$securityFlags = [
    'Automatische Updates'        => ! empty( $security['automatic_updates'] ),
    'Force SSL Admin'             => ! empty( $security['force_ssl_admin'] ),
    'Dateieditor deaktiviert'     => ! empty( $security['disallow_file_edit'] ),
    'Dateimodifikationen gesperrt'=> ! empty( $security['disallow_file_mods'] ),
];

$summary = [
    'site_token'            => $siteToken,
    'site_url'              => $siteUrl,
    'site_name'             => $siteName,
    'site_group'            => $group,
    'checked_at'            => $generatedAt,
    'core_updates'          => (int) ( $updates['core_available'] ?? 0 ),
    'core_security_updates' => (int) ( $updates['core_security'] ?? 0 ),
    'plugin_updates'        => (int) ( $updates['plugins'] ?? 0 ),
    'theme_updates'         => (int) ( $updates['themes'] ?? 0 ),
    'translation_updates'   => (int) ( $updates['translations'] ?? 0 ),
    'pending_comments'      => (int) ( $counts['pending_comments'] ?? 0 ),
    'spam_comments'         => (int) ( $counts['spam_comments'] ?? 0 ),
    'security_flags'        => $securityFlags,
];

if ( $dashboardBase !== '' ) {
    $summary['dashboard_url'] = $dashboardBase . '/dashboard.php?site=' . urlencode( $siteToken );
}

wp_monitor_dispatch_notifications( $config, $summary );

echo json_encode( [
    'status'           => 'ok',
    'installation_id'  => $installationId,
    'check_id'         => $checkId,
    'received_at'      => gmdate( 'c' ),
] );

/**
 * Aktualisiert die gespeicherten Google-Rankings für eine Installation, sofern SerpAPI konfiguriert ist.
 */
function wp_monitor_refresh_google_rankings( PDO $pdo, int $installationId, string $siteUrl, array $config ): void {
    $serpConfig = isset( $config['serp'] ) && is_array( $config['serp'] ) ? $config['serp'] : [];
    $apiKey     = trim( (string) ( $serpConfig['api_key'] ?? '' ) );

    if ( $apiKey === '' ) {
        return;
    }

    $host = parse_url( $siteUrl, PHP_URL_HOST );
    if ( ! is_string( $host ) || $host === '' ) {
        return;
    }

    $rawKeywords = $serpConfig['keywords'] ?? [];
    if ( is_string( $rawKeywords ) ) {
        $rawKeywords = array_filter( array_map( 'trim', preg_split( '/\r?\n|,/', $rawKeywords ) ) );
    }

    if ( ! is_array( $rawKeywords ) ) {
        $rawKeywords = [];
    }

    $keywords = [];
    foreach ( $rawKeywords as $keyword ) {
        $keyword = str_replace( '%domain%', $host, trim( (string) $keyword ) );
        if ( $keyword !== '' ) {
            $keywords[] = $keyword;
        }
    }

    if ( empty( $keywords ) ) {
        $keywords = [ $host ];
    }

    $limit   = (int) ( $serpConfig['results'] ?? 10 );
    $limit   = max( 1, min( 100, $limit ) );
    $timeout = (int) ( $serpConfig['timeout'] ?? 10 );
    $timeout = max( 3, min( 60, $timeout ) );

    $options = [
        'results'       => $limit,
        'google_domain' => (string) ( $serpConfig['google_domain'] ?? 'google.com' ),
        'gl'            => (string) ( $serpConfig['gl'] ?? '' ),
        'hl'            => (string) ( $serpConfig['hl'] ?? '' ),
        'location'      => (string) ( $serpConfig['location'] ?? '' ),
        'timeout'       => $timeout,
    ];

    $results = [];
    foreach ( $keywords as $keyword ) {
        try {
            $results[] = wp_monitor_fetch_serp_ranking( $apiKey, $keyword, $options, $host );
        } catch ( Throwable $exception ) {
            $results[] = [
                'keyword'      => $keyword,
                'position'     => null,
                'title'        => '',
                'link'         => '',
                'retrieved_at' => gmdate( 'Y-m-d H:i:s' ),
                'raw_response' => json_encode( [ 'error' => $exception->getMessage() ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            ];
            error_log( '[WP Monitor] Ranking für "' . $keyword . '" konnte nicht geladen werden: ' . $exception->getMessage() );
        }
    }

    $pdo->beginTransaction();

    try {
        $delete = $pdo->prepare( 'DELETE FROM installation_rankings WHERE installation_id = :id' );
        $delete->execute( [ ':id' => $installationId ] );

        if ( ! empty( $results ) ) {
            $insert = $pdo->prepare( 'INSERT INTO installation_rankings (installation_id, keyword, position, title, link, retrieved_at, raw_response) VALUES (:installation_id, :keyword, :position, :title, :link, :retrieved_at, :raw_response)' );

            foreach ( $results as $result ) {
                $insert->execute( [
                    ':installation_id' => $installationId,
                    ':keyword'         => substr( (string) ( $result['keyword'] ?? '' ), 0, 255 ),
                    ':position'        => isset( $result['position'] ) && $result['position'] !== null ? max( 0, (int) $result['position'] ) : null,
                    ':title'           => substr( (string) ( $result['title'] ?? '' ), 0, 255 ),
                    ':link'            => substr( (string) ( $result['link'] ?? '' ), 0, 255 ),
                    ':retrieved_at'    => (string) ( $result['retrieved_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
                    ':raw_response'    => (string) ( $result['raw_response'] ?? '' ),
                ] );
            }
        }

        $pdo->commit();
    } catch ( Throwable $exception ) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * Ruft eine SERP-Position über die SerpAPI ab.
 */
function wp_monitor_fetch_serp_ranking( string $apiKey, string $keyword, array $options, string $expectedHost ): array {
    $params = [
        'engine'  => 'google',
        'q'       => $keyword,
        'api_key' => $apiKey,
        'num'     => max( 1, min( 100, (int) ( $options['results'] ?? 10 ) ) ),
    ];

    if ( ! empty( $options['google_domain'] ) ) {
        $params['google_domain'] = $options['google_domain'];
    }
    if ( ! empty( $options['gl'] ) ) {
        $params['gl'] = $options['gl'];
    }
    if ( ! empty( $options['hl'] ) ) {
        $params['hl'] = $options['hl'];
    }
    if ( ! empty( $options['location'] ) ) {
        $params['location'] = $options['location'];
    }

    $url = 'https://serpapi.com/search.json?' . http_build_query( $params );

    $timeout = max( 3, min( 60, (int) ( $options['timeout'] ?? 10 ) ) );
    $body    = null;
    $status  = 0;

    if ( function_exists( 'curl_init' ) ) {
        $handle = curl_init( $url );
        if ( false === $handle ) {
            throw new RuntimeException( 'cURL konnte nicht initialisiert werden.' );
        }

        curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $handle, CURLOPT_TIMEOUT, $timeout );
        curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, min( $timeout, 5 ) );

        $body = curl_exec( $handle );
        if ( false === $body ) {
            $error = curl_error( $handle );
            curl_close( $handle );
            throw new RuntimeException( 'HTTP-Anfrage fehlgeschlagen: ' . $error );
        }

        $status = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
        curl_close( $handle );
    } else {
        $context = stream_context_create( [
            'http' => [
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ] );

        $body   = @file_get_contents( $url, false, $context );
        $status = 0;

        if ( isset( $http_response_header ) && is_array( $http_response_header ) ) {
            foreach ( $http_response_header as $headerLine ) {
                if ( preg_match( '/^HTTP\/\S+\s+(\d+)/', $headerLine, $matches ) ) {
                    $status = (int) $matches[1];
                    break;
                }
            }
        }

        if ( false === $body ) {
            throw new RuntimeException( 'HTTP-Anfrage konnte nicht ausgeführt werden.' );
        }
    }

    if ( $status >= 400 ) {
        throw new RuntimeException( 'SerpAPI meldet HTTP-Status ' . $status );
    }

    $decoded = json_decode( (string) $body, true );
    if ( ! is_array( $decoded ) ) {
        throw new RuntimeException( 'Ungültige JSON-Antwort von SerpAPI.' );
    }

    $position = null;
    $title    = '';
    $link     = '';

    $organicResults = $decoded['organic_results'] ?? [];
    if ( is_array( $organicResults ) ) {
        foreach ( $organicResults as $result ) {
            if ( ! is_array( $result ) ) {
                continue;
            }

            $resultLink = isset( $result['link'] ) ? (string) $result['link'] : '';
            if ( $resultLink === '' ) {
                continue;
            }

            if ( ! wp_monitor_hosts_match( $resultLink, $expectedHost ) ) {
                continue;
            }

            $position = isset( $result['position'] ) ? (int) $result['position'] : null;
            $title    = isset( $result['title'] ) ? (string) $result['title'] : '';
            $link     = $resultLink;
            break;
        }
    }

    return [
        'keyword'      => $keyword,
        'position'     => $position,
        'title'        => $title,
        'link'         => $link,
        'retrieved_at' => gmdate( 'Y-m-d H:i:s' ),
        'raw_response' => is_string( $body ) ? $body : json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
    ];
}

function wp_monitor_hosts_match( string $url, string $expectedHost ): bool {
    $actualHost = parse_url( $url, PHP_URL_HOST );

    if ( ! is_string( $actualHost ) || $actualHost === '' ) {
        return false;
    }

    $normalize = static function ( string $host ): string {
        $host = strtolower( $host );
        if ( strpos( $host, 'www.' ) === 0 ) {
            $host = substr( $host, 4 );
        }

        return $host;
    };

    return $normalize( $actualHost ) === $normalize( $expectedHost );
}
