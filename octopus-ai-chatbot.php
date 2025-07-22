<?php
/*
Plugin Name: AI Chatbot
Description: Een AI Chatbot, volledig geïntegreerd in WordPress.
Version: 0.3
Author: Michaël Redant
*/

if (!defined('ABSPATH')) exit; // Veiligheid

// ✅ Admin pagina’s
require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/upload-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/logs-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/sitemap-handler.php';



// ✅ Includes
require_once plugin_dir_path(__FILE__) . 'includes/api-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/pdf-parser.php';
require_once plugin_dir_path(__FILE__) . 'includes/chunker.php';
require_once plugin_dir_path(__FILE__) . 'includes/context-retriever.php';
require_once plugin_dir_path(__FILE__) . 'includes/logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/sitemap-parser.php';



// ✅ Bepaal of de chatbot op deze pagina zichtbaar moet zijn
function octopus_ai_should_display_chatbot() {
    // 👨‍🔧 Alleen admins zien de chatbot als testmodus actief is
    $test_mode = get_option('octopus_ai_test_mode', 0);
    if ($test_mode && !current_user_can('manage_options')) {
        return false;
    }

    // 🧩 Normale weergavelogica
    $mode = get_option('octopus_ai_display_mode', 'all');
    if ($mode === 'all') return true;

    if ($mode === 'selected') {
        $selected_pages = get_option('octopus_ai_selected_pages', array());
        return is_page($selected_pages);
    }

    return true;
}

// ✅ Frontend scripts + wp_localize_script met settings
function octopus_ai_enqueue_frontend_assets() {
    wp_enqueue_style('octopus-ai-chatbot-style', plugin_dir_url(__FILE__) . 'assets/css/chatbot.css', array(), '1.0');
    wp_enqueue_script('octopus-ai-chatbot-script', plugin_dir_url(__FILE__) . 'assets/js/chatbot.js', array('jquery'), '1.0', true);

    // Chatbot settings beschikbaar maken in JS
   wp_localize_script('octopus-ai-chatbot-script', 'octopus_ai_chatbot_vars', array(
    'ajaxurl'           => admin_url('admin-ajax.php'),
    'brand_name'        => get_option('octopus_ai_brand_name', 'AI Chatbot'),
    'logo_url'          => esc_url(get_option('octopus_ai_logo_url')),
    'primary_color'     => get_option('octopus_ai_primary_color', '#0f6c95'),
    'header_text_color' => get_option('octopus_ai_header_text_color', '#ffffff'),
    'welcome_message'   => get_option('octopus_ai_welcome_message', 'Hallo! Hoe kan ik je helpen?'),
));

}

add_action('wp_enqueue_scripts', function () {
    if (octopus_ai_should_display_chatbot()) {
        octopus_ai_enqueue_frontend_assets();
    }
});

// ✅ Submenu “Logs” in admin
add_action('admin_menu', function () {

add_submenu_page(
        'octopus-ai-chatbot',
        'Chatbot Logs',
        'Chatbot Logs',
        'manage_options',
        'octopus-ai-chatbot-logs',
        'octopus_ai_logs_page_callback'
    );

});

// ✅ Database tabel voor logs bij activatie aanmaken
function octopus_ai_create_logs_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'octopus_ai_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        question TEXT,
        answer LONGTEXT,
        context_length INT,
        status VARCHAR(20),
        error_message TEXT,
        ip_address VARCHAR(45)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'octopus_ai_create_logs_table');
