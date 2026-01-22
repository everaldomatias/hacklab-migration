<?php
/**
 * Plugin Name:       #Hacklab Migration
 * Plugin URI:        https://github.com/hacklabr
 * Description:       Plugin to migrate content WordPress.
 * Version:           0.0.16
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Hacklab Team
 * Author URI:        https://hacklab.com.br
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        hhttps://hacklab.com.br/plugins/hacklab-migration
 * Text Domain:       hacklab-migration
 * Domain Path:       /languages
 */

declare( strict_types = 1 );

namespace HacklabMigration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * -------------------------------------------------------------------------
 * Requisitos mínimos (PHP/WP)
 * -------------------------------------------------------------------------
 */
const HACKLAB_MIGRATION_MIN_PHP = '7.4';
const HACKLAB_MIGRATION_MIN_WP  = '5.2';

function check_requirements(): bool {
    global $wp_version;

    if ( version_compare( PHP_VERSION, HACKLAB_MIGRATION_MIN_PHP, '<' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
               . esc_html__('Hacklab Migration requires PHP ', 'hacklabr')
               . esc_html( HACKLAB_MIGRATION_MIN_PHP )
               . esc_html__(' or higher.', 'hacklabr')
               . '</p></div>';
        } );
        return false;
    }

    if ( is_admin() && isset( $wp_version ) && version_compare( $wp_version, HACKLAB_MIGRATION_MIN_WP, '<' ) ) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
               . esc_html__( 'Hacklab Migration requires WordPress ', 'hacklabr' )
               . esc_html( HACKLAB_MIGRATION_MIN_WP )
               . esc_html__( ' or higher.', 'hacklabr' )
               . '</p></div>';
        } );
        return false;
    }

    return true;
}

if ( ! check_requirements() ) {
    return;
}

/**
 * -------------------------------------------------------------------------
 * Constantes do plugin
 * -------------------------------------------------------------------------
 */
define( 'HACKLAB_MIGRATION_VERSION', '0.0.15' );
define( 'HACKLAB_MIGRATION_FILE', __FILE__ );
define( 'HACKLAB_MIGRATION_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'HACKLAB_MIGRATION_DIR_URL', plugin_dir_url( __FILE__ ) );
define( 'HACKLAB_MIGRATION_CAP', 'manage_options' );

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'hacklabr', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * -------------------------------------------------------------------------
 * Ativação / Desativação
 * -------------------------------------------------------------------------
 */
function plugin_activate(): void {
    flush_rewrite_rules();
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\\plugin_activate' );

function plugin_deactivate(): void {
    delete_option( HACKLAB_MIGRATION_DB_OPTIONS );
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\plugin_deactivate' );

/**
 * -------------------------------------------------------------------------
 * Includes
 * -------------------------------------------------------------------------
 */
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/resolve-tables.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/external-connection.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/callbacks/fpa.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/terms.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/users.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/coauthors.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/posts.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/attachments.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/functions.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/de-para.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/modify-callbacks.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/settings.php';
require_once HACKLAB_MIGRATION_DIR_PATH . 'includes/cli.php';
