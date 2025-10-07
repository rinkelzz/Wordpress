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

$groupFilter  = trim((string)($_GET['group'] ?? ''));
$searchFilter = trim((string)($_GET['q'] ?? ''));
$statusNotice = '';

if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $statusNotice = 'Installation erfolgreich gelöscht.';
}

$groups = $pdo->query('SELECT DISTINCT site_group FROM installations ORDER BY site_group ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];

$sql = "
    SELECT i.id, i.site_name, i.site_url, i.site_group, i.site_token, i.last_seen,
           c.id AS check_id, c.checked_at, c.wp_version, c.php_version, c.mysql_version,
           c.core_updates, c.core_security_updates, c.plugin_updates, c.theme_updates, c.translation_updates,
           c.pending_comments, c.spam_comments, c.registered_users, c.draft_posts, c.published_posts,
           s.automatic_updates, s.force_ssl_admin, s.disallow_file_edit, s.disallow_file_mods
    FROM installations i
    LEFT JOIN (
        SELECT ic.*
        FROM installation_checks ic
        INNER JOIN (
            SELECT installation_id, MAX(checked_at) AS checked_at
            FROM installation_checks
            GROUP BY installation_id
        ) latest ON latest.installation_id = ic.installation_id AND latest.checked_at = ic.checked_at
    ) c ON c.installation_id = i.id
    LEFT JOIN installation_security s ON s.check_id = c.id
";

$conditions = [];
$params     = [];

if ($groupFilter !== '') {
    $conditions[]      = 'i.site_group = :group';
    $params[':group']  = $groupFilter;
}

if ($searchFilter !== '') {
    $conditions[] = '(i.site_name LIKE :search OR i.site_url LIKE :search)';
    $params[':search'] = '%' . $searchFilter . '%';
}

if (! empty($conditions)) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}

$sql .= ' ORDER BY i.site_group ASC, i.site_name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function wp_monitor_row_status(array $row): array
{
    $labels = [];
    $level  = 'ok';

    if (empty($row['check_id'])) {
        $labels[] = ['label' => 'Keine Daten', 'class' => 'status-missing'];
        $level    = 'missing';
        return [$labels, $level];
    }

    try {
        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $checkedAt = new DateTimeImmutable($row['checked_at'] ?? 'now', new DateTimeZone('UTC'));
        $diffHours = ($now->getTimestamp() - $checkedAt->getTimestamp()) / 3600;
    } catch (Exception $exception) {
        $diffHours = 0;
    }

    if ($diffHours > 24) {
        $labels[] = ['label' => 'Überfällig', 'class' => 'status-stale'];
        $level    = 'stale';
    }

    if ((int)($row['core_security_updates'] ?? 0) > 0) {
        $labels[] = ['label' => 'Core Sicherheitsupdates', 'class' => 'status-critical'];
        $level    = 'critical';
    }

    $updateTotal = (int)($row['core_updates'] ?? 0) + (int)($row['plugin_updates'] ?? 0) + (int)($row['theme_updates'] ?? 0);
    if ($updateTotal > 0 && $level !== 'critical') {
        $labels[] = ['label' => 'Updates verfügbar', 'class' => 'status-warning'];
        $level    = $level === 'ok' ? 'warning' : $level;
    }

    if ((int)($row['pending_comments'] ?? 0) > 0 && $level === 'ok') {
        $labels[] = ['label' => 'Kommentare offen', 'class' => 'status-info'];
        $level    = 'info';
    }

    if (empty($labels)) {
        $labels[] = ['label' => 'Alles aktuell', 'class' => 'status-ok'];
    }

    return [$labels, $level];
}

function wp_monitor_fetch_latest_wp_version(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cacheFile = sys_get_temp_dir() . '/wp-monitor-latest-version.json';
    $cacheTtl  = 3600;

    if (is_readable($cacheFile)) {
        $content = file_get_contents($cacheFile);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && isset($decoded['version'], $decoded['timestamp'])) {
                if ((time() - (int)$decoded['timestamp']) < $cacheTtl) {
                    $cache = [
                        'version'    => (string)$decoded['version'],
                        'fetched_at' => (int)$decoded['timestamp'],
                        'failed'     => (bool)($decoded['failed'] ?? false),
                    ];

                    return $cache;
                }
            }
        }
    }

    $latestVersion = '';
    $failed        = false;

    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'user_agent' => 'WP-Monitor-Dashboard',
        ],
    ]);

    $response = @file_get_contents('https://api.wordpress.org/core/version-check/1.7/', false, $context);

    if ($response !== false) {
        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['offers'][0]['current'])) {
            $latestVersion = (string)$decoded['offers'][0]['current'];
        } else {
            $failed = true;
        }
    } else {
        $failed = true;
    }

    $data = [
        'version'    => $latestVersion,
        'timestamp'  => time(),
        'failed'     => $failed,
    ];

    @file_put_contents($cacheFile, json_encode($data));

    $cache = [
        'version'    => $latestVersion,
        'fetched_at' => $data['timestamp'],
        'failed'     => $failed,
    ];

    return $cache;
}

function wp_monitor_human_bool($value): string
{
    if ($value === null) {
        return 'Unbekannt';
    }

    return $value ? 'Ja' : 'Nein';
}

$latestVersionInfo = wp_monitor_fetch_latest_wp_version();
$latestWpVersion   = $latestVersionInfo['version'] ?? '';

$detailToken = trim((string)($_GET['site'] ?? ''));
$detailRow   = null;
$detailPayload = [];

if ($detailToken !== '') {
    $detailStmt = $pdo->prepare(
        "SELECT i.*, c.*, s.automatic_updates, s.force_ssl_admin, s.disallow_file_edit, s.disallow_file_mods, s.core_last_check
         FROM installations i
         LEFT JOIN installation_checks c ON c.installation_id = i.id
         LEFT JOIN installation_security s ON s.check_id = c.id
         WHERE i.site_token = :token
         ORDER BY c.checked_at DESC
         LIMIT 1"
    );
    $detailStmt->execute([':token' => $detailToken]);
    $detailRow = $detailStmt->fetch();

    if ($detailRow && ! empty($detailRow['payload'])) {
        $decoded = json_decode((string)$detailRow['payload'], true);
        if (is_array($decoded)) {
            $detailPayload = $decoded;
        }
    }
}

$detailSite        = $detailPayload['site'] ?? [];
$detailEnvironment = $detailPayload['environment'] ?? [];
$detailUpdates     = $detailPayload['updates'] ?? [];
$detailCounts      = $detailPayload['counts'] ?? [];
$detailSecurity    = $detailPayload['security'] ?? [];
$detailInsights    = $detailPayload['insights'] ?? [];

?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8" />
    <title>WP Monitor – Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        :root {
            color-scheme: light dark;
        }
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 2rem; background: #f9fafb; color: #111827; }
        h1 { margin-bottom: 1.5rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; background: #fff; border-radius: 8px; overflow: hidden; }
        th, td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        .filters { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem; }
        .filters label { display: flex; flex-direction: column; font-size: 0.9rem; color: #374151; }
        .filters select, .filters input { padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; min-width: 12rem; }
        button { padding: 0.55rem 1.1rem; border-radius: 6px; border: none; background: #2563eb; color: #fff; font-weight: 600; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .status-badge { display: inline-block; padding: 0.35rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; margin-right: 0.25rem; }
        .status-ok { background: #dcfce7; color: #166534; }
        .status-warning { background: #fef3c7; color: #92400e; }
        .status-critical { background: #fee2e2; color: #b91c1c; }
        .status-info { background: #e0f2fe; color: #1d4ed8; }
        .status-stale { background: #ede9fe; color: #6d28d9; }
        .status-missing { background: #f3f4f6; color: #4b5563; }
        .summary { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-top: 1rem; }
        .summary-card { background: #fff; padding: 1rem 1.25rem; border-radius: 10px; border: 1px solid #e5e7eb; min-width: 180px; }
        .summary-card strong { display: block; font-size: 1.8rem; margin-bottom: 0.25rem; }
        .site-url { font-size: 0.85rem; color: #4b5563; }
        .security-list { list-style: none; margin: 0.5rem 0 0; padding: 0; font-size: 0.85rem; }
        .security-list li { margin-bottom: 0.2rem; }
        a { color: inherit; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .actions-bar { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .action-link { padding: 0.55rem 1.1rem; border-radius: 6px; background: #111827; color: #f9fafb; font-weight: 600; }
        .action-link.secondary { background: #4b5563; }
        .notice { margin-bottom: 1.25rem; padding: 0.75rem 1rem; border-radius: 6px; background: #ecfdf5; color: #166534; border: 1px solid #a7f3d0; }
        .button-link { background: none; border: none; padding: 0; font-weight: 600; cursor: pointer; }
        .button-link:hover { text-decoration: underline; }
        .button-delete { color: #dc2626; }
        .actions-cell form { margin: 0; display: inline; }
        .version-outdated { color: #b91c1c; font-weight: 600; }
        .latest-version-hint { margin-top: 0.5rem; font-size: 0.95rem; color: #1f2937; }
        .detail-card { background: #fff; border-radius: 10px; border: 1px solid #e5e7eb; padding: 1.5rem; margin-top: 1.5rem; }
        .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-top: 1rem; }
        .detail-box { background: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb; padding: 1rem; }
        .detail-box h3 { margin-top: 0; font-size: 1rem; margin-bottom: 0.75rem; }
        .detail-list { list-style: none; padding: 0; margin: 0; font-size: 0.9rem; }
        .detail-list li { margin-bottom: 0.4rem; }
        .back-link { display: inline-block; margin-bottom: 1rem; }
        .detail-highlight { font-weight: 600; }
        .detail-meta { font-size: 0.85rem; color: #4b5563; margin-top: 0.25rem; }
    </style>
</head>
<body>
    <h1>WP Monitor – Dashboard</h1>

    <div class="actions-bar">
        <a class="action-link" href="plugin-download.php">Plugin herunterladen</a>
        <a class="action-link secondary" href="backup.php?download=1">Backup herunterladen</a>
    </div>

    <?php if ($statusNotice !== ''): ?>
        <div class="notice">
            <?php echo htmlspecialchars($statusNotice, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="get" class="filters">
        <label>
            Gruppe
            <select name="group">
                <option value="">Alle Gruppen</option>
                <?php foreach ($groups as $groupOption): ?>
                    <option value="<?php echo htmlspecialchars($groupOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $groupOption === $groupFilter ? 'selected' : ''; ?>><?php echo htmlspecialchars($groupOption, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Suche
            <input type="search" name="q" value="<?php echo htmlspecialchars($searchFilter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Domain oder Name" />
        </label>
        <div style="align-self: flex-end;">
            <button type="submit">Filtern</button>
            <a href="dashboard.php" style="margin-left: 0.5rem;">Zurücksetzen</a>
        </div>
    </form>

    <?php
    $totalInstalls = count($rows);
    $totalUpdates  = 0;
    $critical      = 0;
    $stale         = 0;

    foreach ($rows as $row) {
        $totalUpdates += (int)($row['core_updates'] ?? 0) + (int)($row['plugin_updates'] ?? 0) + (int)($row['theme_updates'] ?? 0) + (int)($row['translation_updates'] ?? 0);
        if ((int)($row['core_security_updates'] ?? 0) > 0) {
            $critical++;
        }
        if (! empty($row['checked_at'])) {
            try {
                $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $last = new DateTimeImmutable($row['checked_at'], new DateTimeZone('UTC'));
                if ((($now->getTimestamp() - $last->getTimestamp()) / 3600) > 24) {
                    $stale++;
                }
            } catch (Exception $exception) {
                $stale++;
            }
        } else {
            $stale++;
        }
    }
    ?>

    <div class="summary">
        <div class="summary-card">
            <strong><?php echo $totalInstalls; ?></strong>
            Installationen
        </div>
        <div class="summary-card">
            <strong><?php echo $totalUpdates; ?></strong>
            Offene Updates
        </div>
        <div class="summary-card">
            <strong><?php echo $critical; ?></strong>
            Kritische Core Updates
        </div>
        <div class="summary-card">
            <strong><?php echo $stale; ?></strong>
            Überfällige Checks
        </div>
        <div class="summary-card">
            <strong><?php echo $latestWpVersion !== '' ? htmlspecialchars($latestWpVersion, ENT_QUOTES, 'UTF-8') : '–'; ?></strong>
            Aktuelle WP-Version
        </div>
    </div>

    <?php if ($latestWpVersion === '' && ($latestVersionInfo['failed'] ?? false)): ?>
        <p class="latest-version-hint">Die aktuelle WordPress-Version konnte nicht automatisch abgefragt werden.</p>
    <?php elseif ($latestWpVersion !== ''): ?>
        <p class="latest-version-hint">Letzte Abfrage: <?php echo gmdate('d.m.Y H:i', $latestVersionInfo['fetched_at'] ?? time()); ?> UTC.</p>
    <?php endif; ?>

    <?php if ($detailToken !== ''): ?>
        <div class="detail-card">
            <a class="back-link" href="dashboard.php">&larr; Zurück zur Übersicht</a>
            <?php if (! $detailRow): ?>
                <p>Für die ausgewählte Installation liegen noch keine Detaildaten vor.</p>
            <?php else: ?>
                <?php
                $detailName      = $detailRow['site_name'] ?: $detailRow['site_url'];
                $detailUrl       = $detailRow['site_url'];
                $checkedAt       = $detailRow['checked_at'] ?? '';
                $detailWpVersion = (string)($detailEnvironment['wp_version'] ?? $detailRow['wp_version'] ?? '');
                $detailPhp       = (string)($detailEnvironment['php_version'] ?? $detailRow['php_version'] ?? '');
                $detailMysql     = (string)($detailEnvironment['mysql_version'] ?? $detailRow['mysql_version'] ?? '');
                $isOutdated      = $latestWpVersion !== '' && $detailWpVersion !== '' && version_compare($detailWpVersion, $latestWpVersion, '<');
                $seoInfo         = $detailInsights['seo'] ?? [];
                $contentInfo     = $detailInsights['content'] ?? [];
                $pluginsInfo     = $detailInsights['plugins'] ?? [];
                $themesInfo      = $detailInsights['themes'] ?? [];
                $mediaInfo       = $detailInsights['media'] ?? [];
                $envInfo         = $detailInsights['environment'] ?? [];
                ?>
                <h2>Details f&uuml;r <?php echo htmlspecialchars($detailName, ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="detail-meta">
                    URL: <a href="<?php echo htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8'); ?></a><br />
                    Letzter Check: <?php echo htmlspecialchars($checkedAt ?: 'Unbekannt', ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <div class="detail-grid">
                    <div class="detail-box">
                        <h3>System</h3>
                        <ul class="detail-list">
                            <li><span class="detail-highlight">WordPress:</span> <?php echo htmlspecialchars($detailWpVersion ?: '-', ENT_QUOTES, 'UTF-8'); ?><?php if ($isOutdated): ?> <span class="version-outdated">(Update empfohlen)</span><?php endif; ?></li>
                            <li><span class="detail-highlight">PHP:</span> <?php echo htmlspecialchars($detailPhp ?: '-', ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><span class="detail-highlight">MySQL:</span> <?php echo htmlspecialchars($detailMysql ?: '-', ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><span class="detail-highlight">Sprache:</span> <?php echo htmlspecialchars($detailSite['language'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><span class="detail-highlight">Zeitzone:</span> <?php echo htmlspecialchars($detailSite['timezone'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><span class="detail-highlight">Umgebung:</span> <?php echo htmlspecialchars($envInfo['environment_type'] ?? 'production', ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><span class="detail-highlight">SSL aktiv:</span> <?php echo wp_monitor_human_bool($envInfo['is_ssl'] ?? null); ?></li>
                            <li><span class="detail-highlight">WP-Cron deaktiviert:</span> <?php echo wp_monitor_human_bool($envInfo['cron_disabled'] ?? null); ?></li>
                        </ul>
                    </div>
                    <div class="detail-box">
                        <h3>Updates &amp; Benutzer</h3>
                        <ul class="detail-list">
                            <li><span class="detail-highlight">Core-Updates:</span> <?php echo (int)($detailUpdates['core_available'] ?? $detailRow['core_updates'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Core-Security:</span> <?php echo (int)($detailUpdates['core_security'] ?? $detailRow['core_security_updates'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Plugin-Updates:</span> <?php echo (int)($detailUpdates['plugins'] ?? $detailRow['plugin_updates'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Theme-Updates:</span> <?php echo (int)($detailUpdates['themes'] ?? $detailRow['theme_updates'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Übersetzungen:</span> <?php echo (int)($detailUpdates['translations'] ?? $detailRow['translation_updates'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Offene Kommentare:</span> <?php echo (int)($detailCounts['pending_comments'] ?? $detailRow['pending_comments'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Spam-Kommentare:</span> <?php echo (int)($detailCounts['spam_comments'] ?? $detailRow['spam_comments'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Registrierte Benutzer:</span> <?php echo (int)($detailCounts['registered_users'] ?? $detailRow['registered_users'] ?? 0); ?></li>
                        </ul>
                    </div>
                    <div class="detail-box">
                        <h3>SEO &amp; Sichtbarkeit</h3>
                        <ul class="detail-list">
                            <li><span class="detail-highlight">Untertitel:</span> <?php echo htmlspecialchars($seoInfo['tagline'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><span class="detail-highlight">Suchmaschinen:</span> <?php echo ($seoInfo !== [] && isset($seoInfo['search_engine_visibility'])) ? ($seoInfo['search_engine_visibility'] ? 'Indexierung erlaubt' : 'Suchmaschinen blockiert') : 'Unbekannt'; ?></li>
                            <li><span class="detail-highlight">Permalink-Struktur:</span> <?php echo $seoInfo && ($seoInfo['permalink_structure'] ?? '') !== '' ? htmlspecialchars($seoInfo['permalink_structure'], ENT_QUOTES, 'UTF-8') : 'Standard'; ?></li>
                            <li><span class="detail-highlight">Site Icon gesetzt:</span> <?php echo wp_monitor_human_bool($seoInfo['site_icon_set'] ?? null); ?></li>
                        </ul>
                    </div>
                    <div class="detail-box">
                        <h3>Inhalte</h3>
                        <ul class="detail-list">
                            <li><span class="detail-highlight">Veröffentlichte Beiträge:</span> <?php echo (int)($contentInfo['published_posts'] ?? $detailRow['published_posts'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Veröffentlichte Seiten:</span> <?php echo (int)($contentInfo['published_pages'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Wörter gesamt:</span> <?php echo (int)($contentInfo['total_words'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Wörter in Beiträgen:</span> <?php echo (int)($contentInfo['words_in_posts'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Wörter in Seiten:</span> <?php echo (int)($contentInfo['words_in_pages'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Ø Wörter pro Beitrag:</span> <?php echo (int)($contentInfo['average_words_per_post'] ?? 0); ?></li>
                        </ul>
                    </div>
                    <div class="detail-box">
                        <h3>Plugins &amp; Themes</h3>
                        <ul class="detail-list">
                            <li><span class="detail-highlight">Plugins gesamt:</span> <?php echo (int)($pluginsInfo['total'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Aktiv:</span> <?php echo (int)($pluginsInfo['active'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Inaktiv:</span> <?php echo (int)($pluginsInfo['inactive'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Must-Use:</span> <?php echo (int)($pluginsInfo['must_use'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Drop-ins:</span> <?php echo (int)($pluginsInfo['dropins'] ?? 0); ?></li>
                            <li><span class="detail-highlight">Aktives Theme:</span> <?php echo htmlspecialchars($themesInfo['active'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><span class="detail-highlight">Kind-Theme:</span> <?php echo wp_monitor_human_bool($themesInfo['is_child_theme'] ?? null); ?></li>
                            <li><span class="detail-highlight">Themes installiert:</span> <?php echo (int)($themesInfo['available'] ?? 0); ?></li>
                        </ul>
                    </div>
                    <div class="detail-box">
                        <h3>Sicherheit &amp; Dateien</h3>
                        <ul class="detail-list">
                            <li><span class="detail-highlight">Auto-Updates:</span> <?php echo wp_monitor_human_bool($detailSecurity['automatic_updates'] ?? null); ?></li>
                            <li><span class="detail-highlight">SSL im Backend:</span> <?php echo wp_monitor_human_bool($detailSecurity['force_ssl_admin'] ?? null); ?></li>
                            <li><span class="detail-highlight">Dateieditor gesperrt:</span> <?php echo wp_monitor_human_bool($detailSecurity['disallow_file_edit'] ?? null); ?></li>
                            <li><span class="detail-highlight">Dateimodifikationen gesperrt:</span> <?php echo wp_monitor_human_bool($detailSecurity['disallow_file_mods'] ?? null); ?></li>
                            <li><span class="detail-highlight">Uploads beschreibbar:</span> <?php echo wp_monitor_human_bool($mediaInfo['uploads_writable'] ?? null); ?></li>
                            <li><span class="detail-highlight">Medien gesamt:</span> <?php echo (int)($mediaInfo['attachments'] ?? 0); ?></li>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Website</th>
                <th>Gruppe</th>
                <th>WP Version</th>
                <th>PHP Version</th>
                <th>Core</th>
                <th>Plugins</th>
                <th>Themes</th>
                <th>Übersetzungen</th>
                <th>Kommentare</th>
                <th>Letzter Check</th>
                <th>Status</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="12">Keine Installationen gefunden.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php [$badges, $state] = wp_monitor_row_status($row); ?>
                    <tr>
                        <td>
                            <?php
                            $adminUrl = '';
                            if (! empty($row['site_url'])) {
                                $adminUrl = rtrim($row['site_url'], '/') . '/wp-admin/';
                            }
                            ?>
                            <strong>
                                <?php if ($adminUrl !== ''): ?>
                                    <a href="<?php echo htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                        <?php echo htmlspecialchars($row['site_name'] ?: $row['site_url'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($row['site_name'] ?: $row['site_url'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </strong>
                            <div class="site-url">
                                <a href="<?php echo htmlspecialchars($row['site_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($row['site_url'], ENT_QUOTES, 'UTF-8'); ?></a>
                            </div>
                            <ul class="security-list">
                                <li>Auto-Updates: <?php echo ! empty($row['automatic_updates']) ? 'aktiv' : 'aus'; ?></li>
                                <li>SSL im Backend: <?php echo ! empty($row['force_ssl_admin']) ? 'aktiv' : 'aus'; ?></li>
                                <li>Dateieditor: <?php echo ! empty($row['disallow_file_edit']) ? 'gesperrt' : 'erlaubt'; ?></li>
                                <li>Dateimodifikationen: <?php echo ! empty($row['disallow_file_mods']) ? 'gesperrt' : 'erlaubt'; ?></li>
                            </ul>
                        </td>
                        <td><?php echo htmlspecialchars($row['site_group'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php
                            $wpVersionValue = $row['wp_version'] ?? '';
                            $versionHtml    = htmlspecialchars($wpVersionValue !== '' ? $wpVersionValue : '-', ENT_QUOTES, 'UTF-8');
                            $isRowOutdated  = $latestWpVersion !== '' && $wpVersionValue !== '' && version_compare($wpVersionValue, $latestWpVersion, '<');
                            ?>
                            <?php if ($isRowOutdated): ?>
                                <span class="version-outdated"><?php echo $versionHtml; ?></span>
                            <?php else: ?>
                                <?php echo $versionHtml; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['php_version'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int)($row['core_updates'] ?? 0); ?><?php if ((int)($row['core_security_updates'] ?? 0) > 0): ?> <span class="status-badge status-critical">Sicherheit</span><?php endif; ?></td>
                        <td><?php echo (int)($row['plugin_updates'] ?? 0); ?></td>
                        <td><?php echo (int)($row['theme_updates'] ?? 0); ?></td>
                        <td><?php echo (int)($row['translation_updates'] ?? 0); ?></td>
                        <td><?php echo (int)($row['pending_comments'] ?? 0); ?> / <?php echo (int)($row['spam_comments'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($row['checked_at'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php foreach ($badges as $badge): ?>
                                <span class="status-badge <?php echo htmlspecialchars($badge['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="actions-cell">
                            <a href="dashboard.php?site=<?php echo urlencode($row['site_token']); ?>">Details</a>
                            <form method="post" action="delete-installation.php" onsubmit="return confirm('Installation wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.');">
                                <input type="hidden" name="installation_id" value="<?php echo (int)$row['id']; ?>" />
                                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'dashboard.php', ENT_QUOTES, 'UTF-8'); ?>" />
                                <button type="submit" class="button-link button-delete">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
