<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', 'wns_enqueue_frontend_assets');

function wns_enqueue_frontend_assets() {
    wp_enqueue_style('wns-style', WNS_PLUGIN_URL . 'assets/css/style.css', array(), '1.0.0');
}