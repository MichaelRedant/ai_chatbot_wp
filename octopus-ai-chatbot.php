<?php
/*
Plugin Name: AI Chatbot
Description: Een AI Chatbot, volledig geïntegreerd in WordPress.
Version: 0.4
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
    'Logging',
    'Logging',
    'manage_options',
    'octopus-ai-logs',
    'octopus_ai_logs_page'
    );

});

function octopus_ai_logs_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'octopus_ai_logs';
    $logs = $wpdb->get_results("SELECT * FROM $table ORDER BY datum DESC LIMIT 100");

    echo '<div class="wrap"><h1>Octopus AI Logs (laatste 100)</h1><table class="widefat"><thead><tr>
        <th>Datum</th><th>Vraag</th><th>Status</th><th>Context</th><th>Fout</th>
    </tr></thead><tbody>';

    foreach ($logs as $log) {
        echo '<tr>';
        echo '<td>' . esc_html($log->datum) . '</td>';
        echo '<td>' . esc_html(wp_trim_words($log->vraag, 15)) . '</td>';
        echo '<td>' . esc_html($log->status) . '</td>';
        echo '<td>' . esc_html($log->context_lengte) . '</td>';
        echo '<td>' . esc_html(wp_trim_words($log->foutmelding, 10)) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

// ✅ Database tabel voor logs bij activatie aanmaken


function octopus_ai_create_log_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'octopus_ai_logs';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        vraag text NOT NULL,
        antwoord text,
        context_lengte int DEFAULT 0,
        status varchar(20),
        foutmelding text,
        datum datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'octopus_ai_create_log_table');


