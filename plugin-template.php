<?php
/**
 * Plugin Name:       Plugin Template por EveraldoDev
 * Plugin URI:        https://everaldo.dev/plugins/
 * Description:       Plugin to custom and improvements WordPress.
 * Version:           0.0.3
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Everaldo Matias
 * Author URI:        https://everaldo.dev/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://everaldo.dev/plugins/plugin-template
 * Text Domain:       plugin-template
 * Domain Path:       /languages
 */

function plugin_template_plugin_activate() {
    flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'plugin_template_plugin_activate' );

define( 'PLUGIN_TEMPLATE_VERSION', '0.0.3' );
define( 'PLUGIN_TEMPLATE_PATH', plugins_url( '/', __FILE__ ) );

require_once( 'includes/enqueues.php' );
require_once( 'includes/functions.php' );
