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
$publicUrl       = $existingPublicUrl;
$emailRecipients = $existingEmailRecipients;
$emailFrom       = $existingEmailFrom;
$emailSubject    = $existingEmailSubject;
$slackWebhook    = $existingSlackWebhook;

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

        if ($emailSubject === '') {
            $emailSubject = $existingEmailSubject ?: 'WP Monitor Hinweis';
        }

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

        <label for="slack_webhook">Slack Webhook URL</label>
        <input id="slack_webhook" name="slack_webhook" type="url" value="<?php echo htmlspecialchars($_POST['slack_webhook'] ?? $slackWebhook, ENT_QUOTES, 'UTF-8'); ?>" />

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
