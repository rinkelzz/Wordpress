<?php
declare(strict_types=1);

/**
 * Determine if the provided snapshot should trigger notifications.
 *
 * @param array $summary Summary data extracted from the snapshot.
 * @return bool
 */
function wp_monitor_should_notify(array $summary): bool
{
    if (! empty($summary['core_security_updates'])) {
        return true;
    }

    $updateTotals = (
        (int) ($summary['core_updates'] ?? 0) +
        (int) ($summary['plugin_updates'] ?? 0) +
        (int) ($summary['theme_updates'] ?? 0) +
        (int) ($summary['translation_updates'] ?? 0)
    );

    if ($updateTotals > 0) {
        return true;
    }

    if (! empty($summary['security_flags']) && in_array(false, $summary['security_flags'], true)) {
        return true;
    }

    if (! empty($summary['pending_comments'])) {
        return true;
    }

    return false;
}

/**
 * Send configured notifications for the provided snapshot summary.
 *
 * @param array $config  Global configuration.
 * @param array $summary Snapshot summary.
 * @return void
 */
function wp_monitor_dispatch_notifications(array $config, array $summary): void
{
    if (! wp_monitor_should_notify($summary)) {
        return;
    }

    $notifications = $config['notifications'] ?? [];

    if (! empty($notifications['email']['recipients'])) {
        wp_monitor_send_email_notification($notifications['email'], $summary, $config);
    }

    if (! empty($notifications['slack']['webhook_url'])) {
        wp_monitor_send_slack_notification($notifications['slack'], $summary, $config);
    }
}

/**
 * Compose a textual summary for notifications.
 *
 * @param array $summary Snapshot summary data.
 * @return string
 */
function wp_monitor_render_notification_message(array $summary): string
{
    $lines   = [];
    $lines[] = sprintf('Installation: %s', $summary['site_name'] ?: $summary['site_url']);
    if (! empty($summary['site_group'])) {
        $lines[] = sprintf('Gruppe: %s', $summary['site_group']);
    }
    $lines[] = sprintf('URL: %s', $summary['site_url']);
    $lines[] = sprintf('Zeitpunkt: %s', $summary['checked_at']);

    $lines[] = sprintf(
        'Core Updates: %d (davon Sicherheitsupdates: %d)',
        (int) $summary['core_updates'],
        (int) $summary['core_security_updates']
    );
    $lines[] = sprintf('Plugin Updates: %d', (int) $summary['plugin_updates']);
    $lines[] = sprintf('Theme Updates: %d', (int) $summary['theme_updates']);
    $lines[] = sprintf('Übersetzungen: %d', (int) $summary['translation_updates']);
    $lines[] = sprintf('Offene Kommentare: %d', (int) $summary['pending_comments']);
    $lines[] = sprintf('Spam Kommentare: %d', (int) $summary['spam_comments']);

    if (! empty($summary['security_flags'])) {
        $lines[] = 'Sicherheitschecks:';
        foreach ($summary['security_flags'] as $flag => $status) {
            $lines[] = sprintf(' - %s: %s', $flag, $status ? 'OK' : 'Achtung');
        }
    }

    if (! empty($summary['dashboard_url'])) {
        $lines[] = 'Dashboard: ' . $summary['dashboard_url'];
    }

    return implode("\n", $lines);
}

/**
 * Send an email notification using PHP's mail().
 *
 * @param array $emailConfig Email configuration.
 * @param array $summary     Snapshot summary.
 * @param array $config      Global configuration.
 * @return void
 */
function wp_monitor_send_email_notification(array $emailConfig, array $summary, array $config): void
{
    $recipients = array_filter(array_map('trim', explode(',', (string) $emailConfig['recipients'])));
    if (empty($recipients)) {
        return;
    }

    $subject = $emailConfig['subject'] ?? 'WP Monitor Benachrichtigung';
    $message = wp_monitor_render_notification_message($summary);

    $headers = [];
    if (! empty($emailConfig['from'])) {
        $headers[] = 'From: ' . $emailConfig['from'];
    }

    foreach ($recipients as $recipient) {
        @mail($recipient, $subject, $message, implode("\r\n", $headers));
    }
}

/**
 * Send a Slack notification through an incoming webhook.
 *
 * @param array $slackConfig Slack configuration.
 * @param array $summary     Snapshot summary.
 * @param array $config      Global configuration.
 * @return void
 */
function wp_monitor_send_slack_notification(array $slackConfig, array $summary, array $config): void
{
    $webhook = trim((string) ($slackConfig['webhook_url'] ?? ''));
    if ($webhook === '') {
        return;
    }

    $payload = [
        'text' => wp_monitor_render_notification_message($summary),
    ];

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => 5,
        ],
    ]);

    @file_get_contents($webhook, false, $context);
}
