<?php

namespace PluginTemplate;

add_action( 'wp_enqueue_scripts', 'PluginTemplate\\frontend_enqueue_scripts' );

function frontend_enqueue_scripts() {
    wp_enqueue_style( 'plugin-template-style', PLUGIN_TEMPLATE_PATH . 'dist/style.css', [], PLUGIN_TEMPLATE_VERSION, 'all' );
    // wp_enqueue_script( 'plugin-template-script', PLUGIN_TEMPLATE_PATH . 'dist/main.js', [], PLUGIN_TEMPLATE_VERSION, true );
}
