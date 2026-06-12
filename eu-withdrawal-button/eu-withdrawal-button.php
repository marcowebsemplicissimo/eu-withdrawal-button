<?php
/**
 * Plugin Name: EU Withdrawal Button
 * Plugin URI:  https://example.com/eu-withdrawal-button
 * Description: Implements the EU mandatory withdrawal button as required by Directive (EU) 2023/2673, effective 19 June 2026. Adds a compliant withdrawal function to WooCommerce order pages with email notifications and a full audit log.
 * Version:     1.1.0
 * Author:      Marco D'Agostino
 * License:     GPL-2.0+
 * Text Domain: eu-withdrawal-button
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EUWB_VERSION', '1.1.0' );
define( 'EUWB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EUWB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EUWB_WITHDRAWAL_WINDOW_DAYS', 14 );

// Autoload includes
require_once EUWB_PLUGIN_DIR . 'includes/class-euwb-install.php';
require_once EUWB_PLUGIN_DIR . 'includes/class-euwb-withdrawal.php';
require_once EUWB_PLUGIN_DIR . 'includes/class-euwb-emails.php';
require_once EUWB_PLUGIN_DIR . 'includes/class-euwb-admin.php';
require_once EUWB_PLUGIN_DIR . 'includes/class-euwb-frontend.php';

register_activation_hook( __FILE__, array( 'EUWB_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EUWB_Install', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'EUWB_Install', 'maybe_upgrade' ), 5 );

add_action( 'woocommerce_delete_order', 'euwb_on_order_deleted' );
add_action( 'before_delete_post',       'euwb_on_order_deleted' );

function euwb_on_order_deleted( $order_id ) {
    if ( function_exists( 'wc_get_order' ) ) {
        EUWB_Withdrawal::mark_order_deleted( $order_id );
    }
}

add_action( 'init', 'euwb_register_order_status' );

function euwb_register_order_status() {
    register_post_status( 'wc-pending-withdraw', array(
        'label'                     => _x( 'In attesa di conferma recesso', 'Order status', 'eu-withdrawal-button' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'In attesa di conferma recesso <span class="count">(%s)</span>', 'In attesa di conferma recesso <span class="count">(%s)</span>', 'eu-withdrawal-button' ),
    ) );
    register_post_status( 'wc-pending-refund', array(
        'label'                     => _x( 'In attesa di rimborso', 'Order status', 'eu-withdrawal-button' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'In attesa di rimborso <span class="count">(%s)</span>', 'In attesa di rimborso <span class="count">(%s)</span>', 'eu-withdrawal-button' ),
    ) );
}

add_filter( 'wc_order_statuses', 'euwb_add_order_status_to_wc' );

function euwb_add_order_status_to_wc( $statuses ) {
    $new = array();
    foreach ( $statuses as $key => $label ) {
        $new[ $key ] = $label;
        if ( 'wc-processing' === $key ) {
            $new['wc-pending-withdraw'] = _x( 'In attesa di conferma recesso', 'Order status', 'eu-withdrawal-button' );
            $new['wc-pending-refund']     = _x( 'In attesa di rimborso', 'Order status', 'eu-withdrawal-button' );
        }
    }
    if ( ! isset( $new['wc-pending-withdraw'] ) ) {
        $new['wc-pending-withdraw'] = _x( 'In attesa di conferma recesso', 'Order status', 'eu-withdrawal-button' );
    }
    if ( ! isset( $new['wc-pending-refund'] ) ) {
        $new['wc-pending-refund'] = _x( 'In attesa di rimborso', 'Order status', 'eu-withdrawal-button' );
    }
    return $new;
}

add_filter( 'woocommerce_locate_template', 'euwb_locate_template', 10, 3 );

function euwb_locate_template( $template, $template_name, $template_path ) {
    $plugin_template = EUWB_PLUGIN_DIR . 'includes/emails/templates/' . $template_name;
    if ( file_exists( $plugin_template ) ) {
        return $plugin_template;
    }
    return $template;
}

add_action( 'plugins_loaded', 'euwb_init' );

function euwb_init() {
    load_plugin_textdomain( 'eu-withdrawal-button', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'euwb_woocommerce_missing_notice' );
        return;
    }

    new EUWB_Frontend();
    new EUWB_Admin();
}

function euwb_woocommerce_missing_notice() {
    $msg = __('WooCommerce deve essere attivo per usare questo plugin.', 'eu-withdrawal-button');
    echo '<div class="notice notice-error"><p><strong>EU Withdrawal Button:</strong> ' . $msg . '</p></div>';
}
