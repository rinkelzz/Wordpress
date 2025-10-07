<?php
/**
 * Plugin Name:       WP Monitor Client
 * Plugin URI:        https://example.com/
 * Description:       Sendet Zustandsdaten der Installation an einen zentralen Überwachungsserver.
 * Version:           1.0.0
 * Author:            WP Monitoring
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-monitor-client
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const WP_MONITOR_CLIENT_VERSION      = '1.0.0';
const WP_MONITOR_CLIENT_OPTION       = 'wp_monitor_client_settings';
const WP_MONITOR_CLIENT_CRON_HOOK    = 'wp_monitor_client_send_snapshot';
const WP_MONITOR_CLIENT_TRANSIENT    = 'wp_monitor_client_admin_notice';

require_once __DIR__ . '/includes/class-wp-monitor-client.php';

function wp_monitor_client() {
    static $instance = null;

    if ( null === $instance ) {
        $instance = new WP_Monitor_Client();
    }

    return $instance;
}

wp_monitor_client();

register_activation_hook( __FILE__, [ 'WP_Monitor_Client', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WP_Monitor_Client', 'deactivate' ] );
