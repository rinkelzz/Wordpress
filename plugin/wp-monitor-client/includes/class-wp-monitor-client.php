<?php
/**
 * Hauptklasse für den WP Monitor Client.
 *
 * @package WP_Monitor_Client
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Monitor_Client {

    /**
     * Cache der Einstellungen.
     *
     * @var array|null
     */
    private $settings_cache = null;

    /**
     * Konstruktor – registriert Hooks.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_notices', [ $this, 'maybe_render_admin_notice' ] );
        add_action( WP_MONITOR_CLIENT_CRON_HOOK, [ $this, 'send_snapshot' ] );
        add_action( 'init', [ $this, 'maybe_schedule_cron' ] );
        add_filter( 'cron_schedules', [ $this, 'register_custom_interval' ] );
    }

    /**
     * Wird bei Aktivierung ausgeführt.
     */
    public static function activate() {
        $instance = wp_monitor_client();
        $instance->settings_cache = null;
        $instance->maybe_schedule_cron( true );
    }

    /**
     * Wird bei Deaktivierung ausgeführt.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( WP_MONITOR_CLIENT_CRON_HOOK );
    }

    /**
     * Registriert den Menüeintrag in den Einstellungen.
     */
    public function register_admin_page() {
        add_options_page(
            __( 'WP Monitor Client', 'wp-monitor-client' ),
            __( 'WP Monitor', 'wp-monitor-client' ),
            'manage_options',
            'wp-monitor-client',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Registriert die Einstellungen.
     */
    public function register_settings() {
        register_setting( 'wp_monitor_client', WP_MONITOR_CLIENT_OPTION, [ $this, 'sanitize_settings' ] );

        add_settings_section(
            'wp_monitor_client_connection',
            __( 'Verbindungseinstellungen', 'wp-monitor-client' ),
            '__return_false',
            'wp-monitor-client'
        );

        add_settings_field(
            'endpoint',
            __( 'Server-Endpunkt', 'wp-monitor-client' ),
            [ $this, 'render_endpoint_field' ],
            'wp-monitor-client',
            'wp_monitor_client_connection'
        );

        add_settings_field(
            'api_key',
            __( 'API-Schlüssel', 'wp-monitor-client' ),
            [ $this, 'render_api_key_field' ],
            'wp-monitor-client',
            'wp_monitor_client_connection'
        );

        add_settings_field(
            'http_username',
            __( 'HTTP-Benutzername', 'wp-monitor-client' ),
            [ $this, 'render_http_username_field' ],
            'wp-monitor-client',
            'wp_monitor_client_connection'
        );

        add_settings_field(
            'http_password',
            __( 'HTTP-Passwort', 'wp-monitor-client' ),
            [ $this, 'render_http_password_field' ],
            'wp-monitor-client',
            'wp_monitor_client_connection'
        );

        add_settings_field(
            'site_token',
            __( 'Installationskennung', 'wp-monitor-client' ),
            [ $this, 'render_site_token_field' ],
            'wp-monitor-client',
            'wp_monitor_client_connection'
        );

        add_settings_section(
            'wp_monitor_client_schedule',
            __( 'Übertragung', 'wp-monitor-client' ),
            '__return_false',
            'wp-monitor-client'
        );

        add_settings_field(
            'interval',
            __( 'Intervall (Stunden)', 'wp-monitor-client' ),
            [ $this, 'render_interval_field' ],
            'wp-monitor-client',
            'wp_monitor_client_schedule'
        );
    }

    /**
     * Validiert die Einstellungen.
     */
    public function sanitize_settings( $settings ) {
        $defaults = [
            'endpoint'    => '',
            'api_key'     => '',
            'http_username' => '',
            'http_password' => '',
            'site_token'  => '',
            'interval'    => 6,
        ];

        $existing_settings = $this->get_settings();
        $raw_settings      = isset( $_POST[ WP_MONITOR_CLIENT_OPTION ] ) ? (array) wp_unslash( $_POST[ WP_MONITOR_CLIENT_OPTION ] ) : [];

        $settings = wp_parse_args( $settings, $defaults );

        $settings['endpoint']   = esc_url_raw( trim( $settings['endpoint'] ) );
        $settings['api_key']    = sanitize_text_field( $settings['api_key'] );
        $settings['http_username'] = sanitize_text_field( $settings['http_username'] );
        $settings['site_token'] = sanitize_text_field( $settings['site_token'] );
        $settings['interval']   = max( 1, absint( $settings['interval'] ) );

        $submitted_password = isset( $settings['http_password'] ) ? (string) $settings['http_password'] : '';
        if ( ! empty( $raw_settings['http_password_clear'] ) ) {
            $settings['http_password'] = '';
        } elseif ( '' === $submitted_password ) {
            $settings['http_password'] = $existing_settings['http_password'] ?? '';
        } else {
            $settings['http_password'] = sanitize_text_field( $submitted_password );
        }

        unset( $settings['http_password_clear'] );

        $this->settings_cache = $settings;
        $this->maybe_reschedule_cron();

        return $settings;
    }

    /**
     * Rendert das Einstellungsformular.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->get_settings();

        if ( isset( $_POST['wp_monitor_client_send_now'] ) && check_admin_referer( 'wp_monitor_client_send_now' ) ) {
            $result = $this->send_snapshot();
            $this->store_admin_notice( $result );
            wp_safe_redirect( add_query_arg( 'settings-updated', 'true', menu_page_url( 'wp-monitor-client', false ) ) );
            exit;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP Monitor Client', 'wp-monitor-client' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'wp_monitor_client' );
                do_settings_sections( 'wp-monitor-client' );
                submit_button();
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Manuelle Übertragung', 'wp-monitor-client' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'wp_monitor_client_send_now' ); ?>
                <p><?php esc_html_e( 'Sendet sofort einen aktuellen Statusbericht an den Überwachungsserver.', 'wp-monitor-client' ); ?></p>
                <?php submit_button( __( 'Jetzt senden', 'wp-monitor-client' ), 'secondary', 'wp_monitor_client_send_now' ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Feld: Endpunkt.
     */
    public function render_endpoint_field() {
        $settings = $this->get_settings();
        ?>
        <input type="url" class="regular-text code" name="<?php echo esc_attr( WP_MONITOR_CLIENT_OPTION ); ?>[endpoint]" value="<?php echo esc_attr( $settings['endpoint'] ); ?>" placeholder="https://monitor.example.com/api.php" />
        <?php
    }

    /**
     * Feld: API-Key.
     */
    public function render_api_key_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( WP_MONITOR_CLIENT_OPTION ); ?>[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" />
        <p class="description"><?php esc_html_e( 'Der auf dem Server konfigurierte API-Schlüssel.', 'wp-monitor-client' ); ?></p>
        <?php
    }

    /**
     * Feld: HTTP-Benutzername.
     */
    public function render_http_username_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( WP_MONITOR_CLIENT_OPTION ); ?>[http_username]" value="<?php echo esc_attr( $settings['http_username'] ); ?>" autocomplete="username" />
        <p class="description"><?php esc_html_e( 'Optionaler Benutzername für HTTP-Basic-Auth auf dem Server.', 'wp-monitor-client' ); ?></p>
        <?php
    }

    /**
     * Feld: HTTP-Passwort.
     */
    public function render_http_password_field() {
        $settings = $this->get_settings();
        ?>
        <input type="password" class="regular-text" name="<?php echo esc_attr( WP_MONITOR_CLIENT_OPTION ); ?>[http_password]" value="" autocomplete="new-password" />
        <?php if ( ! empty( $settings['http_password'] ) ) : ?>
            <p class="description"><?php esc_html_e( 'Leer lassen, um das gespeicherte Passwort zu behalten, oder ein neues Passwort eingeben.', 'wp-monitor-client' ); ?></p>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( WP_MONITOR_CLIENT_OPTION ); ?>[http_password_clear]" value="1" />
                <?php esc_html_e( 'Gespeichertes Passwort löschen', 'wp-monitor-client' ); ?>
            </label>
        <?php else : ?>
            <p class="description"><?php esc_html_e( 'Passwort für HTTP-Basic-Auth auf dem Server.', 'wp-monitor-client' ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Feld: Site Token.
     */
    public function render_site_token_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( WP_MONITOR_CLIENT_OPTION ); ?>[site_token]" value="<?php echo esc_attr( $settings['site_token'] ); ?>" />
        <p class="description"><?php esc_html_e( 'Individuelle Kennung dieser Installation (optional).', 'wp-monitor-client' ); ?></p>
        <?php
    }

    /**
     * Feld: Intervall.
     */
    public function render_interval_field() {
        $settings = $this->get_settings();
        ?>
        <input type="number" min="1" class="small-text" name="<?php echo esc_attr( WP_MONITOR_CLIENT_OPTION ); ?>[interval]" value="<?php echo esc_attr( $settings['interval'] ); ?>" />
        <p class="description"><?php esc_html_e( 'In welchem Abstand (in Stunden) Statusdaten versendet werden sollen.', 'wp-monitor-client' ); ?></p>
        <?php
    }

    /**
     * Gibt die Einstellungen zurück.
     */
    public function get_settings() {
        if ( null !== $this->settings_cache ) {
            return $this->settings_cache;
        }

        $defaults = [
            'endpoint'   => '',
            'api_key'    => '',
            'site_token' => '',
            'interval'   => 6,
        ];

        $this->settings_cache = wp_parse_args( get_option( WP_MONITOR_CLIENT_OPTION, [] ), $defaults );

        return $this->settings_cache;
    }

    /**
     * Plant den Cronjob, falls nötig.
     *
     * @param bool $force_schedule Erzwingt eine Neuplanung.
     */
    public function maybe_schedule_cron( $force_schedule = false ) {
        if ( $force_schedule ) {
            $timestamp = wp_next_scheduled( WP_MONITOR_CLIENT_CRON_HOOK );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, WP_MONITOR_CLIENT_CRON_HOOK );
            }
        }

        if ( ! wp_next_scheduled( WP_MONITOR_CLIENT_CRON_HOOK ) ) {
            $this->schedule_event();
        }
    }

    /**
     * Plant den Cronjob neu, falls sich das Intervall geändert hat.
     */
    private function maybe_reschedule_cron() {
        $timestamp = wp_next_scheduled( WP_MONITOR_CLIENT_CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, WP_MONITOR_CLIENT_CRON_HOOK );
        }

        $this->schedule_event();
    }

    /**
     * Plant das Cron-Event.
     */
    private function schedule_event() {
        wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wp_monitor_client_interval', WP_MONITOR_CLIENT_CRON_HOOK );
    }

    /**
     * Registriert das individuelle Cron-Intervall.
     */
    public function register_custom_interval( $schedules ) {
        $settings = $this->get_settings();
        $interval = max( 1, absint( $settings['interval'] ) );

        $schedules['wp_monitor_client_interval'] = [
            'interval' => HOUR_IN_SECONDS * $interval,
            'display'  => sprintf( _n( 'Alle %d Stunde', 'Alle %d Stunden', $interval, 'wp-monitor-client' ), $interval ),
        ];

        return $schedules;
    }

    /**
     * Sendet die gesammelten Daten an den Server.
     */
    public function send_snapshot() {
        $settings = $this->get_settings();

        if ( empty( $settings['endpoint'] ) || empty( $settings['api_key'] ) ) {
            return [
                'success' => false,
                'message' => __( 'Es ist kein Endpunkt oder API-Schlüssel konfiguriert.', 'wp-monitor-client' ),
            ];
        }

        $payload = apply_filters( 'wp_monitor_client_snapshot', $this->collect_snapshot() );

        $headers = [
            'Content-Type'      => 'application/json',
            'X-WP-Monitor-Key'  => $settings['api_key'],
            'X-WP-Monitor-Site' => $settings['site_token'] ? $settings['site_token'] : home_url(),
            'X-WP-Monitor-Version' => WP_MONITOR_CLIENT_VERSION,
        ];

        if ( ! empty( $settings['http_username'] ) && ! empty( $settings['http_password'] ) ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( $settings['http_username'] . ':' . $settings['http_password'] );
        }

        $response = wp_remote_post(
            $settings['endpoint'],
            [
                'timeout' => 20,
                'headers' => $headers,
                'body'    => wp_json_encode( $payload ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code >= 200 && $code < 300 ) {
            return [
                'success' => true,
                'message' => __( 'Status erfolgreich übertragen.', 'wp-monitor-client' ),
                'details' => $body,
            ];
        }

        return [
            'success' => false,
            'message' => sprintf( __( 'Fehlerhafte Antwort vom Server (%d).', 'wp-monitor-client' ), $code ),
            'details' => $body,
        ];
    }

    /**
     * Sammelt Statusinformationen der WordPress-Installation.
     */
    public function collect_snapshot() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        global $wpdb;

        $settings      = $this->get_settings();
        $plugins        = get_plugins();
        $active_plugins = get_option( 'active_plugins', [] );
        $plugin_updates = get_site_transient( 'update_plugins' );
        $plugin_updates = ( is_object( $plugin_updates ) && isset( $plugin_updates->response ) ) ? (array) $plugin_updates->response : [];

        $themes        = wp_get_themes();
        $theme_updates = get_site_transient( 'update_themes' );
        $theme_updates = ( is_object( $theme_updates ) && isset( $theme_updates->response ) ) ? (array) $theme_updates->response : [];
        $current_theme = wp_get_theme();

        $core_updates = get_site_transient( 'update_core' );
        $update_data  = wp_get_update_data();
        $core_channels_data = ( is_object( $core_updates ) && isset( $core_updates->updates ) ) ? $core_updates->updates : $core_updates;
        $comment_counts   = wp_count_comments();
        $user_counts      = count_users();
        $post_counts      = wp_count_posts();
        $multisite        = is_multisite();
        $page_counts      = function_exists( 'wp_count_posts' ) ? wp_count_posts( 'page' ) : null;
        $must_use_plugins = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : [];
        $dropins          = function_exists( 'get_dropins' ) ? get_dropins() : [];

        $plugin_payload = [];
        foreach ( $plugins as $file => $data ) {
            $update_version = '';
            $has_update     = false;

            if ( isset( $plugin_updates[ $file ] ) ) {
                $has_update = true;
                $update     = $plugin_updates[ $file ];

                if ( is_object( $update ) && isset( $update->new_version ) ) {
                    $update_version = $update->new_version;
                } elseif ( is_array( $update ) && isset( $update['new_version'] ) ) {
                    $update_version = $update['new_version'];
                }
            }

            $plugin_payload[] = [
                'file'           => $file,
                'name'           => $data['Name'],
                'version'        => $data['Version'],
                'is_active'      => in_array( $file, $active_plugins, true ),
                'has_update'     => $has_update,
                'update_version' => $update_version,
                'author'         => $data['AuthorName'] ?? '',
                'plugin_uri'     => $data['PluginURI'] ?? '',
            ];
        }

        $theme_payload = [];
        foreach ( $themes as $stylesheet => $theme ) {
            $update_version = '';
            $has_update     = false;

            if ( isset( $theme_updates[ $stylesheet ] ) ) {
                $has_update = true;
                $update     = $theme_updates[ $stylesheet ];

                if ( is_array( $update ) && isset( $update['new_version'] ) ) {
                    $update_version = $update['new_version'];
                } elseif ( is_object( $update ) && isset( $update->new_version ) ) {
                    $update_version = $update->new_version;
                }
            }

            $theme_payload[] = [
                'stylesheet'     => $stylesheet,
                'name'           => $theme->get( 'Name' ),
                'version'        => $theme->get( 'Version' ),
                'is_active'      => $current_theme->get_stylesheet() === $stylesheet,
                'has_update'     => $has_update,
                'update_version' => $update_version,
                'author'         => $theme->get( 'Author' ),
            ];
        }

        $core_security_updates = isset( $update_data['counts']['security'] ) ? (int) $update_data['counts']['security'] : 0;
        $core_total_updates    = isset( $update_data['counts']['total'] ) ? (int) $update_data['counts']['total'] : 0;
        $plugin_update_count   = isset( $update_data['counts']['plugins'] ) ? (int) $update_data['counts']['plugins'] : 0;
        $theme_update_count    = isset( $update_data['counts']['themes'] ) ? (int) $update_data['counts']['themes'] : 0;
        $translation_updates   = isset( $update_data['counts']['translations'] ) ? (int) $update_data['counts']['translations'] : 0;

        $php_version   = phpversion();
        $mysql_version = method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '';
        $core_last_checked = ( is_object( $core_updates ) && isset( $core_updates->last_checked ) ) ? (int) $core_updates->last_checked : 0;

        $automatic_updates = function_exists( 'wp_is_auto_update_enabled_for_type' ) ? (bool) wp_is_auto_update_enabled_for_type( 'core' ) : false;

        $content_insights = $this->calculate_word_counts();
        $attachment_total = $this->count_attachments();
        $uploads_dir      = wp_upload_dir();
        $uploads_writable = null;
        if ( ! empty( $uploads_dir['basedir'] ) ) {
            $uploads_writable = function_exists( 'wp_is_writable' ) ? wp_is_writable( $uploads_dir['basedir'] ) : is_writable( $uploads_dir['basedir'] );
        }
        $permalink        = get_option( 'permalink_structure', '' );
        $site_icon_id     = function_exists( 'get_option' ) ? (int) get_option( 'site_icon' ) : 0;

        $snapshot = [
            'site' => [
                'url'        => home_url(),
                'name'       => get_bloginfo( 'name' ),
                'language'   => get_bloginfo( 'language' ),
                'timezone'   => wp_timezone_string(),
                'token'      => $settings['site_token'],
            ],
            'environment' => [
                'php_version'         => $php_version,
                'mysql_version'       => $mysql_version,
                'wp_version'          => get_bloginfo( 'version' ),
                'is_multisite'        => $multisite,
                'php_memory_limit'    => ini_get( 'memory_limit' ),
                'php_max_execution'   => ini_get( 'max_execution_time' ),
                'wp_debug'            => defined( 'WP_DEBUG' ) ? WP_DEBUG : false,
            ],
            'updates' => [
                'core_available'   => $core_total_updates,
                'core_security'    => $core_security_updates,
                'plugins'          => $plugin_update_count,
                'themes'           => $theme_update_count,
                'translations'     => $translation_updates,
                'core_channels'    => $this->normalize_core_updates( $core_channels_data ),
            ],
            'plugins' => $plugin_payload,
            'themes'  => $theme_payload,
            'counts'  => [
                'pending_comments' => (int) $comment_counts->moderated,
                'spam_comments'    => (int) $comment_counts->spam,
                'registered_users' => (int) $user_counts['total_users'],
                'draft_posts'      => (int) $post_counts->draft,
                'published_posts'  => (int) $post_counts->publish,
            ],
            'security' => [
                'last_core_update_check' => $core_last_checked,
                'automatic_updates'      => $automatic_updates,
                'force_ssl_admin'        => function_exists( 'force_ssl_admin' ) ? (bool) force_ssl_admin() : false,
                'disallow_file_edit'     => defined( 'DISALLOW_FILE_EDIT' ) ? (bool) DISALLOW_FILE_EDIT : false,
                'disallow_file_mods'     => defined( 'DISALLOW_FILE_MODS' ) ? (bool) DISALLOW_FILE_MODS : false,
            ],
            'insights' => [
                'seo'      => [
                    'tagline'                  => get_bloginfo( 'description' ),
                    'search_engine_visibility' => (bool) get_option( 'blog_public', 1 ),
                    'permalink_structure'      => $permalink,
                    'home_url'                 => home_url(),
                    'site_icon_set'            => $site_icon_id > 0,
                ],
                'content'  => [
                    'total_words'             => $content_insights['total'] ?? 0,
                    'words_in_posts'          => $content_insights['posts'] ?? 0,
                    'words_in_pages'          => $content_insights['pages'] ?? 0,
                    'average_words_per_post'  => $content_insights['average_post'] ?? 0,
                    'published_posts'         => (int) $post_counts->publish,
                    'published_pages'         => $page_counts && isset( $page_counts->publish ) ? (int) $page_counts->publish : 0,
                ],
                'plugins'  => [
                    'total'          => count( $plugins ),
                    'active'         => count( $active_plugins ),
                    'inactive'       => max( 0, count( $plugins ) - count( $active_plugins ) ),
                    'must_use'       => is_array( $must_use_plugins ) ? count( $must_use_plugins ) : 0,
                    'dropins'        => is_array( $dropins ) ? count( $dropins ) : 0,
                    'updates'        => $plugin_update_count,
                ],
                'themes'   => [
                    'active'          => $current_theme->get( 'Name' ),
                    'template'        => $current_theme->get_template(),
                    'stylesheet'      => $current_theme->get_stylesheet(),
                    'is_child_theme'  => (bool) $current_theme->parent(),
                    'available'       => count( $themes ),
                ],
                'media'    => [
                    'attachments'      => $attachment_total,
                    'uploads_writable' => $uploads_writable,
                ],
                'environment' => [
                    'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
                    'is_ssl'           => is_ssl(),
                    'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) ? (bool) DISABLE_WP_CRON : false,
                ],
            ],
            'generated_at' => current_time( 'mysql' ),
        ];

        return $snapshot;
    }

    /**
     * Ermittelt Wortanzahlen für Beiträge und Seiten.
     *
     * @return array
     */
    private function calculate_word_counts() {
        global $wpdb;

        $counts = [
            'total'       => 0,
            'posts'       => 0,
            'pages'       => 0,
            'average_post'=> 0,
        ];

        if ( ! $wpdb instanceof wpdb ) {
            return $counts;
        }

        $posts_words = (int) $wpdb->get_var( "SELECT SUM(CHAR_LENGTH(post_content) - CHAR_LENGTH(REPLACE(post_content, ' ', '')) + 1) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post'" );
        $pages_words = (int) $wpdb->get_var( "SELECT SUM(CHAR_LENGTH(post_content) - CHAR_LENGTH(REPLACE(post_content, ' ', '')) + 1) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page'" );

        $published_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post'" );

        $counts['posts']       = max( 0, $posts_words );
        $counts['pages']       = max( 0, $pages_words );
        $counts['total']       = max( 0, $posts_words + $pages_words );
        $counts['average_post']= $published_posts > 0 ? (int) round( $counts['posts'] / $published_posts ) : 0;

        return $counts;
    }

    /**
     * Zählt vorhandene Medienanhänge.
     *
     * @return int
     */
    private function count_attachments() {
        global $wpdb;

        if ( ! $wpdb instanceof wpdb ) {
            return 0;
        }

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'" );

        return max( 0, $count );
    }

    /**
     * Normalisiert die Core-Update-Informationen für den Export.
     */
    private function normalize_core_updates( $core_updates ) {
        if ( ! is_array( $core_updates ) && ! is_object( $core_updates ) ) {
            return [];
        }

        $normalized = [];

        foreach ( (array) $core_updates as $update ) {
            if ( ! is_object( $update ) || empty( $update->version ) ) {
                continue;
            }

            $normalized[] = [
                'version'     => $update->version,
                'php_version' => $update->php_version ?? '',
                'packages'    => [
                    'full'    => $update->package ?? '',
                    'partial' => $update->partial_package ?? '',
                ],
                'dismissed'   => ! empty( $update->dismissed ),
                'current'     => ! empty( $update->current ),
                'locale'      => $update->locale ?? '',
            ];
        }

        return $normalized;
    }

    /**
     * Speichert einen Hinweis für den Admin-Bereich.
     */
    private function store_admin_notice( $result ) {
        set_transient( WP_MONITOR_CLIENT_TRANSIENT, $result, MINUTE_IN_SECONDS * 10 );
    }

    /**
     * Gibt gespeicherte Hinweise aus.
     */
    public function maybe_render_admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $notice = get_transient( WP_MONITOR_CLIENT_TRANSIENT );

        if ( ! $notice ) {
            return;
        }

        delete_transient( WP_MONITOR_CLIENT_TRANSIENT );

        $class = $notice['success'] ? 'updated' : 'error';
        ?>
        <div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
            <p><strong><?php echo esc_html( $notice['message'] ); ?></strong></p>
            <?php if ( ! empty( $notice['details'] ) ) : ?>
                <p><code><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $notice['details'] ), 40, '…' ) ); ?></code></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
