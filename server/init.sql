-- Schema für den WP Monitor Server

CREATE TABLE IF NOT EXISTS installations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_token VARCHAR(191) NOT NULL,
    site_url VARCHAR(255) NOT NULL,
    site_name VARCHAR(191) NOT NULL,
    site_group VARCHAR(191) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    last_seen DATETIME NOT NULL,
    UNIQUE KEY uq_installations_token (site_token),
    KEY idx_installations_group (site_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installation_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id INT UNSIGNED NOT NULL,
    checked_at DATETIME NOT NULL,
    wp_version VARCHAR(32) NOT NULL,
    php_version VARCHAR(32) NOT NULL,
    mysql_version VARCHAR(64) DEFAULT '',
    core_updates SMALLINT UNSIGNED DEFAULT 0,
    core_security_updates SMALLINT UNSIGNED DEFAULT 0,
    plugin_updates SMALLINT UNSIGNED DEFAULT 0,
    theme_updates SMALLINT UNSIGNED DEFAULT 0,
    translation_updates SMALLINT UNSIGNED DEFAULT 0,
    pending_comments INT UNSIGNED DEFAULT 0,
    spam_comments INT UNSIGNED DEFAULT 0,
    registered_users INT UNSIGNED DEFAULT 0,
    draft_posts INT UNSIGNED DEFAULT 0,
    published_posts INT UNSIGNED DEFAULT 0,
    payload LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_checks_installation FOREIGN KEY (installation_id) REFERENCES installations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installation_plugins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    check_id BIGINT UNSIGNED NOT NULL,
    plugin_file VARCHAR(255) NOT NULL,
    plugin_name VARCHAR(191) NOT NULL,
    plugin_version VARCHAR(50) NOT NULL,
    is_active TINYINT(1) DEFAULT 0,
    has_update TINYINT(1) DEFAULT 0,
    update_version VARCHAR(50) DEFAULT '',
    CONSTRAINT fk_plugins_check FOREIGN KEY (check_id) REFERENCES installation_checks(id) ON DELETE CASCADE,
    KEY idx_plugin_file (plugin_file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installation_themes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    check_id BIGINT UNSIGNED NOT NULL,
    theme_stylesheet VARCHAR(191) NOT NULL,
    theme_name VARCHAR(191) NOT NULL,
    theme_version VARCHAR(50) NOT NULL,
    is_active TINYINT(1) DEFAULT 0,
    has_update TINYINT(1) DEFAULT 0,
    update_version VARCHAR(50) DEFAULT '',
    CONSTRAINT fk_themes_check FOREIGN KEY (check_id) REFERENCES installation_checks(id) ON DELETE CASCADE,
    KEY idx_theme_stylesheet (theme_stylesheet)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installation_security (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    check_id BIGINT UNSIGNED NOT NULL,
    automatic_updates TINYINT(1) DEFAULT 0,
    force_ssl_admin TINYINT(1) DEFAULT 0,
    disallow_file_edit TINYINT(1) DEFAULT 0,
    disallow_file_mods TINYINT(1) DEFAULT 0,
    core_last_check BIGINT UNSIGNED DEFAULT 0,
    CONSTRAINT fk_security_check FOREIGN KEY (check_id) REFERENCES installation_checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
