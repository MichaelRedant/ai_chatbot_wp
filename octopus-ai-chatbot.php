<?php
/*
Plugin Name: AI Chatbot
Description: Een AI Chatbot, volledig geïntegreerd in WordPress.
Version: 0.8
Author: Michaël Redant
*/

if (!defined('ABSPATH')) exit; // Veiligheid

// ✅ Admin pagina’s
require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php';
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

    $is_french  = preg_match('#^/fr(/|$)#', $_SERVER['REQUEST_URI']);
    $lang_header = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $lang_code  = ($is_french || strpos($lang_header, 'fr') === 0) ? 'FR' : 'NL';


    wp_localize_script('octopus-ai-chatbot-script', 'octopus_ai_chatbot_vars', array(
        'ajaxurl'           => admin_url('admin-ajax.php'),
         'lang'              => $lang_code,
        'brand_name'        => get_option('octopus_ai_brand_name', 'AI Chatbot'),
        'logo_url'          => esc_url(get_option('octopus_ai_logo_url')),
        'primary_color'     => get_option('octopus_ai_primary_color', '#0f6c95'),
        'header_text_color' => get_option('octopus_ai_header_text_color', '#ffffff'),
        'welcome_message' => (function () use ($is_french) {
    $custom_nl = get_option('octopus_ai_welcome_message_nl');
    $custom_fr = get_option('octopus_ai_welcome_message_fr');

    if ($is_french && !empty($custom_fr)) return $custom_fr;
    if (!$is_french && !empty($custom_nl)) return $custom_nl;

    return $is_french
        ? "👋 Bonjour ! Comment puis-je t’aider aujourd’hui ?"
        : "👋 Hallo! Hoe kan ik je vandaag helpen?";
})(),
        // Dynamische vertalingen voor gebruik in JS
        'i18n' => array(
    'placeholder'      => $is_french ? "Tape ta question..." : "Typ je vraag...",
    'send'             => $is_french ? "Envoyer" : "Verstuur",
    'reset_title'      => $is_french ? "Réinitialiser la conversation" : "Reset gesprek",
    'reset_button'     => $is_french ? "Réinitialiser" : "Vernieuw",
    'reset_confirm'    => $is_french ? "Es-tu sûr(e) de vouloir recommencer la conversation ?" : "Weet je zeker dat je het gesprek wilt vernieuwen?",
    'feedback_up'      => $is_french ? "Merci pour ton retour positif !" : "Bedankt voor je positieve feedback!",
    'feedback_down'    => $is_french ? "Merci pour ton retour, nous allons l'examiner." : "We bekijken je feedback – dank je!",
    'fallback_prefix'  => $is_french ? "ℹ️ Tu trouveras peut-être la réponse dans notre manuel :" : "ℹ️ Misschien vind je het antwoord wel in onze handleiding:",
    'fallback_button'  => $is_french ? "Voir dans le manuel" : "Bekijk dit in de handleiding",
    'fallback_trigger' => $is_french ? "Désolé, je ne peux pas t’aider avec ça." : "Sorry, daar kan ik je niet mee helpen.",
    'api_error'        => $is_french ? "❌ Une erreur s'est produite lors de la récupération de la réponse." : "❌ Er ging iets mis met het ophalen van het antwoord.",
)

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
    'octopus-ai-chatbot-logs', // <- deze slug wordt ook gebruikt voor filters
    'octopus_ai_logs_page_callback' // <- correcte callback naar je nieuwe UI
);

});



// ✅ Database tabel voor logs bij activatie aanmaken


function octopus_ai_create_log_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'octopus_ai_logs';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        vraag TEXT NOT NULL,
        antwoord TEXT,
        context_lengte INT DEFAULT 0,
        status VARCHAR(20),
        foutmelding TEXT,
        feedback VARCHAR(10),
        ip_address VARCHAR(45),
        datum DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

register_activation_hook(__FILE__, 'octopus_ai_create_log_table');


