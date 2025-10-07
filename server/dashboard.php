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

$groups = $pdo->query('SELECT DISTINCT site_group FROM installations ORDER BY site_group ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];

$sql = "
    SELECT i.id, i.site_name, i.site_url, i.site_group, i.last_seen,
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
    </style>
</head>
<body>
    <h1>WP Monitor – Dashboard</h1>

    <div class="actions-bar">
        <a class="action-link" href="plugin-download.php">Plugin herunterladen</a>
        <a class="action-link secondary" href="backup.php?download=1">Backup herunterladen</a>
    </div>

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
    </div>

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
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="11">Keine Installationen gefunden.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php [$badges, $state] = wp_monitor_row_status($row); ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($row['site_name'] ?: $row['site_url'], ENT_QUOTES, 'UTF-8'); ?></strong>
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
                        <td><?php echo htmlspecialchars($row['wp_version'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
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
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
