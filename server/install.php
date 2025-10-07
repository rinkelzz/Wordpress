<?php
declare(strict_types=1);

session_start();

$configPath = __DIR__ . '/config.php';
$tokenKey   = 'wp_monitor_install_token';
$configExists = file_exists($configPath);

$currentConfig = [];
if ($configExists) {
    $maybeConfig = require $configPath;
    if (is_array($maybeConfig)) {
        $currentConfig = $maybeConfig;
    }
}

$existingApiKeys   = isset($currentConfig['api_keys']) && is_array($currentConfig['api_keys']) ? $currentConfig['api_keys'] : [];
$existingApiKey    = array_key_first($existingApiKeys);
$existingApiGroup  = (null !== $existingApiKey && isset($existingApiKeys[$existingApiKey])) ? (string) $existingApiKeys[$existingApiKey] : 'Standardgruppe';
$existingApiKeyVal = $existingApiKey ?? '';

if (empty($_SESSION[$tokenKey])) {
    $_SESSION[$tokenKey] = bin2hex(random_bytes(16));
}

$errors       = [];
$success      = false;
$apiKey       = '';
$message      = '';
$httpUsername = $currentConfig['http_auth']['username'] ?? '';
$existingPasswordHash = $currentConfig['http_auth']['password_hash'] ?? '';
$requirePasswordInput = $existingPasswordHash === '';
$existingPublicUrl      = $currentConfig['app']['public_url'] ?? '';
$existingEmailRecipients = $currentConfig['notifications']['email']['recipients'] ?? '';
$existingEmailFrom       = $currentConfig['notifications']['email']['from'] ?? '';
$existingEmailSubject    = $currentConfig['notifications']['email']['subject'] ?? 'WP Monitor Hinweis';
$existingSlackWebhook    = $currentConfig['notifications']['slack']['webhook_url'] ?? '';
$existingSerpConfig      = isset($currentConfig['serp']) && is_array($currentConfig['serp']) ? $currentConfig['serp'] : [];
$existingSerpApiKey      = (string)($existingSerpConfig['api_key'] ?? '');
$existingSerpKeywords    = $existingSerpConfig['keywords'] ?? [];
if (is_string($existingSerpKeywords)) {
    $existingSerpKeywords = array_filter(array_map('trim', preg_split('/\r?\n|,/', $existingSerpKeywords)));
}
if (! is_array($existingSerpKeywords)) {
    $existingSerpKeywords = [];
}
$existingSerpKeywordsText = implode("\n", $existingSerpKeywords);
$existingSerpResults       = (int)($existingSerpConfig['results'] ?? 10);
$existingSerpDomain        = (string)($existingSerpConfig['google_domain'] ?? 'google.com');
$existingSerpGl            = (string)($existingSerpConfig['gl'] ?? '');
$existingSerpHl            = (string)($existingSerpConfig['hl'] ?? '');
$existingSerpLocation      = (string)($existingSerpConfig['location'] ?? '');
$existingSerpTimeout       = (int)($existingSerpConfig['timeout'] ?? 10);
$publicUrl       = $existingPublicUrl;
$emailRecipients = $existingEmailRecipients;
$emailFrom       = $existingEmailFrom;
$emailSubject    = $existingEmailSubject;
$slackWebhook    = $existingSlackWebhook;
$serpApiKey      = $existingSerpApiKey;
$serpKeywordsRaw = $existingSerpKeywordsText;
$serpResults     = $existingSerpResults;
$serpDomain      = $existingSerpDomain;
$serpGl          = $existingSerpGl;
$serpHl          = $existingSerpHl;
$serpLocation    = $existingSerpLocation;
$serpTimeout     = $existingSerpTimeout;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['token'] ?? '';
    if (! hash_equals($_SESSION[$tokenKey], $submittedToken)) {
        $errors[] = 'Ungültiges Formular-Token. Bitte versuche es erneut.';
    } else {
        $dbHost = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
        $dbPort = (int)($_POST['db_port'] ?? 3306);
        $dbName = trim((string)($_POST['db_name'] ?? ''));
        $dbUser = trim((string)($_POST['db_user'] ?? ''));
        $dbPass = (string)($_POST['db_pass'] ?? '');

        $apiKey   = trim((string)($_POST['api_key'] ?? ''));
        $apiGroup = trim((string)($_POST['api_group'] ?? 'Standardgruppe'));

        $httpRealm     = trim((string)($_POST['http_realm'] ?? 'WP Monitor'));
        $httpUsername  = trim((string)($_POST['http_username'] ?? ''));
        $httpPassword  = (string)($_POST['http_password'] ?? '');
        $httpPassword2 = (string)($_POST['http_password_confirm'] ?? '');
        $passwordProvided     = ($httpPassword !== '' || $httpPassword2 !== '');

        $publicUrl       = trim((string)($_POST['public_url'] ?? $existingPublicUrl));
        $emailRecipients = trim((string)($_POST['email_recipients'] ?? $existingEmailRecipients));
        $emailFrom       = trim((string)($_POST['email_from'] ?? $existingEmailFrom));
        $emailSubject    = trim((string)($_POST['email_subject'] ?? $existingEmailSubject));
        $slackWebhook    = trim((string)($_POST['slack_webhook'] ?? $existingSlackWebhook));
        $serpApiKey      = trim((string)($_POST['serp_api_key'] ?? $existingSerpApiKey));
        $serpKeywordsRaw = (string)($_POST['serp_keywords'] ?? $existingSerpKeywordsText);
        $serpResults     = (int)($_POST['serp_results'] ?? $existingSerpResults);
        $serpDomain      = trim((string)($_POST['serp_domain'] ?? $existingSerpDomain));
        $serpGl          = trim((string)($_POST['serp_gl'] ?? $existingSerpGl));
        $serpHl          = trim((string)($_POST['serp_hl'] ?? $existingSerpHl));
        $serpLocation    = trim((string)($_POST['serp_location'] ?? $existingSerpLocation));
        $serpTimeout     = (int)($_POST['serp_timeout'] ?? $existingSerpTimeout);

        if ($emailSubject === '') {
            $emailSubject = $existingEmailSubject ?: 'WP Monitor Hinweis';
        }

        if ($serpResults < 1) {
            $serpResults = 10;
        }
        if ($serpResults > 100) {
            $serpResults = 100;
        }

        if ($serpTimeout < 3) {
            $serpTimeout = 3;
        } elseif ($serpTimeout > 60) {
            $serpTimeout = 60;
        }

        $serpKeywords = array_values(array_filter(array_map('trim', preg_split('/\r?\n|,/', $serpKeywordsRaw))));
        $serpKeywordsRaw = implode("\n", $serpKeywords);

        if ($dbName === '') {
            $errors[] = 'Der Datenbankname darf nicht leer sein.';
        }

        if ($dbUser === '') {
            $errors[] = 'Der Datenbankbenutzer darf nicht leer sein.';
        }

        if ($httpUsername === '') {
            $errors[] = 'Der HTTP-Benutzername darf nicht leer sein.';
        }

        if ($passwordProvided) {
            if ($httpPassword === '' || $httpPassword2 === '') {
                $errors[] = 'Bitte gib das HTTP-Passwort zweimal ein.';
            }
        } elseif ($existingPasswordHash === '') {
            $errors[] = 'Ein HTTP-Passwort ist erforderlich.';
        }

        if ($passwordProvided && $httpPassword !== $httpPassword2) {
            $errors[] = 'Die angegebenen HTTP-Passwörter stimmen nicht überein.';
        }

        if ($apiKey === '') {
            $apiKey = bin2hex(random_bytes(16));
        }

        if ($apiGroup === '') {
            $apiGroup = 'Standardgruppe';
        }

        if (empty($errors)) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $dbHost,
                    $dbPort,
                    $dbName
                );

                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } catch (Throwable $exception) {
                $errors[] = 'Datenbankverbindung fehlgeschlagen: ' . $exception->getMessage();
            }
        }

        if (empty($errors)) {
            $schema = @file_get_contents(__DIR__ . '/init.sql');
            if ($schema === false) {
                $errors[] = 'Schema-Datei init.sql konnte nicht gelesen werden.';
            } else {
                try {
                    $statements = array_filter(array_map('trim', explode(';', $schema)));
                    foreach ($statements as $statement) {
                        if ($statement !== '') {
                            $pdo->exec($statement);
                        }
                    }
                } catch (Throwable $exception) {
                    $errors[] = 'Tabellen konnten nicht angelegt werden: ' . $exception->getMessage();
                }
            }
        }

        if (empty($errors)) {
            $config = [
                'db' => [
                    'host'     => $dbHost,
                    'port'     => $dbPort,
                    'name'     => $dbName,
                    'user'     => $dbUser,
                    'password' => $dbPass,
                    'charset'  => 'utf8mb4',
                ],
                'app' => [
                    'public_url' => $publicUrl,
                ],
                'api_keys' => [
                    $apiKey => $apiGroup,
                ],
                'http_auth' => [
                    'realm'         => $httpRealm !== '' ? $httpRealm : 'WP Monitor',
                    'username'      => $httpUsername,
                    'password_hash' => $passwordProvided ? password_hash($httpPassword, PASSWORD_DEFAULT) : $existingPasswordHash,
                ],
                'notifications' => [
                    'email' => [
                        'recipients' => $emailRecipients,
                        'from'       => $emailFrom,
                        'subject'    => $emailSubject,
                    ],
                    'slack' => [
                        'webhook_url' => $slackWebhook,
                    ],
                ],
                'serp' => [
                    'api_key'       => $serpApiKey,
                    'keywords'      => $serpKeywords,
                    'results'       => $serpResults,
                    'google_domain' => $serpDomain !== '' ? $serpDomain : 'google.com',
                    'gl'            => $serpGl,
                    'hl'            => $serpHl,
                    'location'      => $serpLocation,
                    'timeout'       => $serpTimeout,
                ],
            ];

            $configCode = "<?php\nreturn " . var_export($config, true) . ";\n";

            try {
                if (file_put_contents($configPath, $configCode) === false) {
                    $errors[] = 'config.php konnte nicht geschrieben werden. Prüfe die Schreibrechte.';
                } else {
                    $success = true;
                    $message = 'Die Installation wurde erfolgreich abgeschlossen.';
                }
            } catch (Throwable $exception) {
                $errors[] = 'config.php konnte nicht geschrieben werden: ' . $exception->getMessage();
            }
        }
    }
}

if ($success) {
    $_POST = [];
    $requirePasswordInput = false;
    $serpConfig    = isset($config['serp']) && is_array($config['serp']) ? $config['serp'] : [];
    $serpApiKey    = (string)($serpConfig['api_key'] ?? '');
    $serpKeywords  = $serpConfig['keywords'] ?? [];
    if (is_string($serpKeywords)) {
        $serpKeywords = array_filter(array_map('trim', preg_split('/\r?\n|,/', $serpKeywords)));
    }
    if (! is_array($serpKeywords)) {
        $serpKeywords = [];
    }
    $serpKeywordsRaw = implode("\n", $serpKeywords);
    $serpResults  = (int)($serpConfig['results'] ?? 10);
    $serpDomain   = (string)($serpConfig['google_domain'] ?? 'google.com');
    $serpGl       = (string)($serpConfig['gl'] ?? '');
    $serpHl       = (string)($serpConfig['hl'] ?? '');
    $serpLocation = (string)($serpConfig['location'] ?? '');
    $serpTimeout  = (int)($serpConfig['timeout'] ?? 10);
}

?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8" />
    <title>WP Monitor Server – Installation</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; line-height: 1.5; }
        .container { max-width: 720px; margin: 0 auto; }
        .success { padding: 1rem; background: #d1fae5; border: 1px solid #10b981; border-radius: 6px; }
        .error { padding: 1rem; background: #fee2e2; border: 1px solid #ef4444; border-radius: 6px; }
        form { margin-top: 1.5rem; }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input, textarea { width: 100%; padding: 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 1rem; }
        textarea { min-height: 120px; }
        .actions { margin-top: 1.5rem; }
        button { padding: 0.8rem 1.4rem; background: #2563eb; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .note { margin-top: 1rem; font-size: 0.95rem; color: #4b5563; }
        .readonly { background: #f3f4f6; }
    </style>
</head>
<body>
<div class="container">
    <h1>WP Monitor Server – Installation</h1>

    <?php if ($success): ?>
        <div class="success">
            <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
            <p>API-Schlüssel: <code><?php echo htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8'); ?></code></p>
            <p>HTTP-Benutzername: <code><?php echo htmlspecialchars($httpUsername, ENT_QUOTES, 'UTF-8'); ?></code></p>
            <p>Dashboard: <a href="dashboard.php" target="_blank" rel="noopener">dashboard.php</a></p>
            <p>Plugin-Download: <a href="plugin-download.php" target="_blank" rel="noopener">plugin-download.php</a></p>
            <p>Backup Export: <a href="backup.php?download=1" target="_blank" rel="noopener">backup.php?download=1</a></p>
            <p class="note">Der Download und die API sind über HTTP-Auth geschützt. Bewahre Benutzername und Passwort sicher auf.</p>
        </div>
    <?php endif; ?>

    <?php if (! empty($errors)): ?>
        <div class="error">
            <p><strong>Es sind Fehler aufgetreten:</strong></p>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($configExists && ! $success): ?>
        <p class="note">
            Eine bestehende <code>config.php</code> wurde gefunden. Du kannst die Werte aktualisieren und erneut speichern.
        </p>
    <?php endif; ?>

    <form method="post" novalidate>
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION[$tokenKey], ENT_QUOTES, 'UTF-8'); ?>" />

        <h2>Datenbank</h2>
        <label for="db_host">Host</label>
        <input id="db_host" name="db_host" type="text" required value="<?php echo htmlspecialchars($_POST['db_host'] ?? ($currentConfig['db']['host'] ?? '127.0.0.1'), ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="db_port">Port</label>
        <input id="db_port" name="db_port" type="number" min="1" max="65535" value="<?php echo htmlspecialchars((string)($_POST['db_port'] ?? (string)($currentConfig['db']['port'] ?? '3306')), ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="db_name">Datenbankname</label>
        <input id="db_name" name="db_name" type="text" required value="<?php echo htmlspecialchars($_POST['db_name'] ?? ($currentConfig['db']['name'] ?? 'wp_monitor'), ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="db_user">Benutzer</label>
        <input id="db_user" name="db_user" type="text" required value="<?php echo htmlspecialchars($_POST['db_user'] ?? ($currentConfig['db']['user'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="db_pass">Passwort</label>
        <input id="db_pass" name="db_pass" type="password" value="<?php echo htmlspecialchars($_POST['db_pass'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />

        <h2>API-Konfiguration</h2>
        <label for="api_key">API-Schlüssel (leer lassen für automatischen Schlüssel)</label>
        <input id="api_key" name="api_key" type="text" value="<?php echo htmlspecialchars($_POST['api_key'] ?? $existingApiKeyVal, ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="api_group">Gruppe / Mandant</label>
        <input id="api_group" name="api_group" type="text" value="<?php echo htmlspecialchars($_POST['api_group'] ?? $existingApiGroup, ENT_QUOTES, 'UTF-8'); ?>" />

        <h2>HTTP-Authentifizierung</h2>
        <label for="http_realm">Realm</label>
        <input id="http_realm" name="http_realm" type="text" value="<?php echo htmlspecialchars($_POST['http_realm'] ?? ($currentConfig['http_auth']['realm'] ?? 'WP Monitor'), ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="http_username">Benutzername</label>
        <input id="http_username" name="http_username" type="text" required value="<?php echo htmlspecialchars($_POST['http_username'] ?? ($currentConfig['http_auth']['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="http_password">Passwort</label>
        <input id="http_password" name="http_password" type="password" <?php echo $requirePasswordInput ? 'required' : ''; ?> />

        <label for="http_password_confirm">Passwort bestätigen</label>
        <input id="http_password_confirm" name="http_password_confirm" type="password" <?php echo $requirePasswordInput ? 'required' : ''; ?> />

        <h2>Dashboard &amp; Benachrichtigungen</h2>
        <label for="public_url">Öffentliche URL des Dashboards</label>
        <input id="public_url" name="public_url" type="url" placeholder="https://monitor.example.com" value="<?php echo htmlspecialchars($_POST['public_url'] ?? $publicUrl, ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="email_recipients">E-Mail Empfänger (Komma getrennt)</label>
        <input id="email_recipients" name="email_recipients" type="text" value="<?php echo htmlspecialchars($_POST['email_recipients'] ?? $emailRecipients, ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="email_from">Absenderadresse</label>
        <input id="email_from" name="email_from" type="email" value="<?php echo htmlspecialchars($_POST['email_from'] ?? $emailFrom, ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="email_subject">E-Mail Betreff</label>
        <input id="email_subject" name="email_subject" type="text" value="<?php echo htmlspecialchars($_POST['email_subject'] ?? $emailSubject, ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="slack_webhook">Slack Webhook URL (optional)</label>
        <input id="slack_webhook" name="slack_webhook" type="url" value="<?php echo htmlspecialchars($_POST['slack_webhook'] ?? $slackWebhook, ENT_QUOTES, 'UTF-8'); ?>" />
        <p class="note">
            Hinterlege hier die von Slack generierte Incoming-Webhook-URL (z. B. <code>https://hooks.slack.com/services/…</code>),
            damit der Server Statusmeldungen direkt an einen Slack-Channel senden kann. Lässt du das Feld leer, bleibt Slack
            deaktiviert und alle Daten werden trotzdem nur auf deinem Überwachungsserver gespeichert.
        </p>

        <h2>Google Rankings (optional)</h2>
        <label for="serp_api_key">SerpAPI Schlüssel</label>
        <input id="serp_api_key" name="serp_api_key" type="text" value="<?php echo htmlspecialchars($_POST['serp_api_key'] ?? $serpApiKey, ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="serp_keywords">Suchbegriffe (ein Begriff pro Zeile)</label>
        <textarea id="serp_keywords" name="serp_keywords" placeholder="%domain%&#10;wordpress agentur berlin"><?php echo htmlspecialchars($_POST['serp_keywords'] ?? $serpKeywordsRaw, ENT_QUOTES, 'UTF-8'); ?></textarea>
        <p class="note">
            Nutze <code>%domain%</code> als Platzhalter für die Domain der jeweiligen Installation. Lässt du die Liste leer,
            wird automatisch nur nach der Domain gesucht.
        </p>

        <label for="serp_results">Anzahl der ausgewerteten Ergebnisse</label>
        <input id="serp_results" name="serp_results" type="number" min="1" max="100" value="<?php echo htmlspecialchars((string)($_POST['serp_results'] ?? (string)$serpResults), ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="serp_domain">Google-Domain (z. B. google.com)</label>
        <input id="serp_domain" name="serp_domain" type="text" value="<?php echo htmlspecialchars($_POST['serp_domain'] ?? $serpDomain, ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="serp_gl">Google Country Code (gl)</label>
        <input id="serp_gl" name="serp_gl" type="text" value="<?php echo htmlspecialchars($_POST['serp_gl'] ?? $serpGl, ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="serp_hl">Google Language (hl)</label>
        <input id="serp_hl" name="serp_hl" type="text" value="<?php echo htmlspecialchars($_POST['serp_hl'] ?? $serpHl, ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="serp_location">Standort (optional)</label>
        <input id="serp_location" name="serp_location" type="text" value="<?php echo htmlspecialchars($_POST['serp_location'] ?? $serpLocation, ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="serp_timeout">Timeout für API-Anfragen (Sekunden)</label>
        <input id="serp_timeout" name="serp_timeout" type="number" min="3" max="60" value="<?php echo htmlspecialchars((string)($_POST['serp_timeout'] ?? (string)$serpTimeout), ENT_QUOTES, 'UTF-8'); ?>" />

        <p class="note">
            Der Abruf der Google-Rankings erfolgt über den Drittanbieter <a href="https://serpapi.com/" target="_blank" rel="noopener">SerpAPI</a>.
            Hinterlege hier einen gültigen API-Schlüssel, falls Rankings automatisch ermittelt werden sollen. Ohne Schlüssel bleibt
            diese Funktion deaktiviert.
        </p>

        <div class="actions">
            <button type="submit">Installation ausführen</button>
        </div>

        <p class="note">
            Nach erfolgreicher Installation ist die Datei <code>install.php</code> nicht mehr erforderlich und kann entfernt oder
            zusätzlich durch den Webserver geschützt werden.
        </p>
    </form>
</div>
</body>
</html>
