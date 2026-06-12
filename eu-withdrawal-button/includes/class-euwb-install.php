<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class EUWB_Install {

    public static function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'euwb_withdrawals';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id      BIGINT(20) UNSIGNED NOT NULL,
            user_id       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            first_name    VARCHAR(100) NOT NULL DEFAULT '',
            last_name     VARCHAR(100) NOT NULL DEFAULT '',
            email         VARCHAR(200) NOT NULL DEFAULT '',
            reason        TEXT,
            status        VARCHAR(50) NOT NULL DEFAULT 'pending',
            ip_address    VARCHAR(45) DEFAULT '',
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at  DATETIME DEFAULT NULL,
            order_deleted TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY user_id  (user_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        add_option( 'euwb_version', EUWB_VERSION );
    }

    public static function maybe_upgrade() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'euwb_withdrawals';

        $col = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'order_deleted'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE $table_name ADD COLUMN order_deleted TINYINT(1) NOT NULL DEFAULT 0" );
        }
    }

    public static function deactivate() {
        // We keep the table on deactivation to preserve records.
    }
}
