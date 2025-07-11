<?php
/*
Plugin Name: Octopus AI Chatbot
Description: Een AI Chatbot voor Octopus, volledig geïntegreerd in WordPress.
Version: 0.1
Author: Michaël Redant
*/

// Admin settings page laden
require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php';

// REST API endpoint voor chatbot
require_once plugin_dir_path(__FILE__) . 'includes/api-handler.php';

// PDF parsing functies
require_once plugin_dir_path(__FILE__) . 'includes/pdf-parser.php';
require_once plugin_dir_path(__FILE__) . 'includes/chunker.php';

// Assets laden
function octopus_ai_enqueue_assets() {
    wp_enqueue_style('octopus-chatbot-css', plugin_dir_url(__FILE__) . 'assets/chatbot.css');
    wp_enqueue_script('octopus-chatbot-js', plugin_dir_url(__FILE__) . 'assets/chatbot.js', array('jquery'), false, true);
}
add_action('wp_enqueue_scripts', 'octopus_ai_enqueue_assets');
