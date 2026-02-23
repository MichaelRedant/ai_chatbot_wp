<?php
/*
Plugin Name: AI Chatbot
Description: Een AI Chatbot, volledig geïntegreerd in WordPress.
Version: 0.8
Author: Michaël Redant
*/

/*
 * Credits
 * Developed and maintained by Michaël Redant.
 */

if (!defined('ABSPATH')) {
    exit;
}

$GLOBALS['octopus_ai_missing_bootstrap_files'] = array();

if (!function_exists('octopus_ai_safe_require')) {
    function octopus_ai_safe_require($relative_path)
    {
        $relative_path = ltrim((string) $relative_path, '/\\');
        $absolute_path = plugin_dir_path(__FILE__) . $relative_path;

        if (!file_exists($absolute_path)) {
            $GLOBALS['octopus_ai_missing_bootstrap_files'][] = $relative_path;
            error_log('[Octopus AI] Vereist bestand ontbreekt: ' . $relative_path);
            return false;
        }

        require_once $absolute_path;
        return true;
    }
}

octopus_ai_safe_require('includes/provider-profile.php');

octopus_ai_safe_require('admin/settings-page.php');
octopus_ai_safe_require('admin/upload-page.php');
octopus_ai_safe_require('admin/logs-page.php');
octopus_ai_safe_require('admin/sitemap-handler.php');

octopus_ai_safe_require('includes/api-handler.php');
octopus_ai_safe_require('includes/pdf-parser.php');
octopus_ai_safe_require('includes/pdf-chunker.php');
octopus_ai_safe_require('includes/context-retriever.php');
octopus_ai_safe_require('includes/logger.php');
octopus_ai_safe_require('includes/sitemap-parser.php');
octopus_ai_safe_require('includes/elementor-widget.php');

if (!function_exists('octopus_ai_get_preflight_pages')) {
    function octopus_ai_get_preflight_pages()
    {
        return array(
            'octopus-ai-chatbot',
            'octopus-ai-chatbot-logs',
            'octopus_ai_pdf_beheer',
        );
    }
}

if (!function_exists('octopus_ai_is_plugin_admin_page')) {
    function octopus_ai_is_plugin_admin_page()
    {
        if (!is_admin()) {
            return false;
        }

        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        return in_array($page, octopus_ai_get_preflight_pages(), true);
    }
}

if (!function_exists('octopus_ai_collect_preflight_report')) {
    function octopus_ai_collect_preflight_report()
    {
        global $wpdb;

        $report = array(
            'critical' => array(),
            'warning' => array(),
            'ok' => array(),
        );

        $missing_bootstrap = isset($GLOBALS['octopus_ai_missing_bootstrap_files']) && is_array($GLOBALS['octopus_ai_missing_bootstrap_files'])
            ? array_values(array_unique(array_filter(array_map('sanitize_text_field', $GLOBALS['octopus_ai_missing_bootstrap_files']))))
            : array();
        if (!empty($missing_bootstrap)) {
            $report['critical'][] = 'Ontbrekende pluginbestanden: ' . implode(', ', $missing_bootstrap);
        } else {
            $report['ok'][] = 'Alle kernbestanden van de plugin zijn gevonden.';
        }

        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            $report['critical'][] = 'PHP-versie is te oud (' . PHP_VERSION . '). Minimaal 7.4 is nodig.';
        } else {
            $report['ok'][] = 'PHP-versie: ' . PHP_VERSION . '.';
        }

        $upload_dir = wp_upload_dir();
        $upload_basedir = (string) ($upload_dir['basedir'] ?? '');
        if ($upload_basedir === '' || !is_dir($upload_basedir)) {
            $report['critical'][] = 'Uploads-map is niet beschikbaar.';
        } elseif (!is_writable($upload_basedir)) {
            $report['critical'][] = 'Uploads-map is niet schrijfbaar: ' . $upload_basedir;
        } else {
            $report['ok'][] = 'Uploads-map is schrijfbaar.';
        }

        $chunks_dir = trailingslashit($upload_basedir) . 'octopus-ai-chunks/';
        if ($upload_basedir !== '') {
            if (!file_exists($chunks_dir)) {
                $created = wp_mkdir_p($chunks_dir);
                if (!$created) {
                    $report['critical'][] = 'Chunks-map kan niet worden aangemaakt: ' . $chunks_dir;
                }
            }

            if (file_exists($chunks_dir) && !is_writable($chunks_dir)) {
                $report['critical'][] = 'Chunks-map is niet schrijfbaar: ' . $chunks_dir;
            } elseif (file_exists($chunks_dir)) {
                $report['ok'][] = 'Chunks-map is klaar voor gebruik.';
            }
        }

        $api_key = trim((string) get_option('octopus_ai_api_key', ''));
        if ($api_key === '') {
            $report['warning'][] = 'OpenAI API key is nog niet ingevuld.';
        } else {
            $report['ok'][] = 'OpenAI API key is ingesteld.';
        }

        if (!function_exists('wp_remote_post') || !function_exists('wp_remote_get')) {
            $report['critical'][] = 'HTTP-functies ontbreken (wp_remote_get/wp_remote_post).';
        } else {
            $report['ok'][] = 'HTTP-functies zijn beschikbaar.';
        }

        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            $report['warning'][] = 'DOM extensie ontbreekt. Sitemap HTML parsing kan beperkt zijn.';
        } else {
            $report['ok'][] = 'DOM extensie is beschikbaar.';
        }

        if (!function_exists('simplexml_load_string')) {
            $report['warning'][] = 'SimpleXML extensie ontbreekt. Sitemap XML parsing kan beperkt zijn.';
        } else {
            $report['ok'][] = 'SimpleXML extensie is beschikbaar.';
        }

        if (!function_exists('mb_strlen')) {
            $report['warning'][] = 'mbstring extensie ontbreekt. De plugin gebruikt fallback-logica, maar mbstring blijft aanbevolen.';
        } else {
            $report['ok'][] = 'mbstring extensie is beschikbaar.';
        }

        $vendor_autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
        if (!file_exists($vendor_autoload)) {
            $report['warning'][] = 'vendor/autoload.php ontbreekt. PDF parser-functies kunnen beperkt zijn.';
        } else {
            $report['ok'][] = 'Composer autoload is gevonden.';
        }

        if (isset($wpdb) && isset($wpdb->prefix)) {
            $table = $wpdb->prefix . 'octopus_ai_logs';
            $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($table_exists !== $table) {
                $report['warning'][] = 'Logtabel ontbreekt (' . $table . '). Activeer de plugin opnieuw om tabellen aan te maken.';
            } else {
                $report['ok'][] = 'Logtabel is beschikbaar.';
            }
        }

        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $report['warning'][] = 'WP-Cron staat uit. Achtergrondverwerking (PDF/Sitemap queue) vereist een externe cron trigger.';
        } else {
            $report['ok'][] = 'WP-Cron staat aan.';
        }

        return $report;
    }
}

if (!function_exists('octopus_ai_render_preflight_notice')) {
    function octopus_ai_render_preflight_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $missing_bootstrap = isset($GLOBALS['octopus_ai_missing_bootstrap_files']) && is_array($GLOBALS['octopus_ai_missing_bootstrap_files'])
            ? array_values(array_unique(array_filter($GLOBALS['octopus_ai_missing_bootstrap_files'])))
            : array();
        $force_global_notice = !empty($missing_bootstrap);

        if (!$force_global_notice && !octopus_ai_is_plugin_admin_page()) {
            return;
        }

        $report = octopus_ai_collect_preflight_report();
        $critical = isset($report['critical']) && is_array($report['critical']) ? $report['critical'] : array();
        $warnings = isset($report['warning']) && is_array($report['warning']) ? $report['warning'] : array();
        $ok = isset($report['ok']) && is_array($report['ok']) ? $report['ok'] : array();

        if (empty($critical) && empty($warnings)) {
            echo '<div class="notice notice-success"><p><strong>Preflight check:</strong> geen kritieke issues gevonden voor productie.</p></div>';
            return;
        }

        $notice_class = !empty($critical) ? 'notice notice-error' : 'notice notice-warning';
        echo '<div class="' . esc_attr($notice_class) . '">';
        echo '<p><strong>Preflight check voor productie</strong></p>';

        if (!empty($critical)) {
            echo '<p><strong>Kritiek:</strong></p><ul>';
            foreach ($critical as $line) {
                echo '<li>' . esc_html((string) $line) . '</li>';
            }
            echo '</ul>';
        }

        if (!empty($warnings)) {
            echo '<p><strong>Waarschuwingen:</strong></p><ul>';
            foreach ($warnings as $line) {
                echo '<li>' . esc_html((string) $line) . '</li>';
            }
            echo '</ul>';
        }

        if (!empty($ok)) {
            echo '<p><em>OK checks: ' . esc_html((string) count($ok)) . '</em></p>';
        }
        echo '</div>';
    }

    add_action('admin_notices', 'octopus_ai_render_preflight_notice');
}

if (!function_exists('octopus_ai_register_fatal_shutdown_logger')) {
    function octopus_ai_register_fatal_shutdown_logger()
    {
        register_shutdown_function(
            static function () {
                $error = error_get_last();
                if (!is_array($error)) {
                    return;
                }

                $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
                $type = isset($error['type']) ? (int) $error['type'] : 0;
                if (!in_array($type, $fatal_types, true)) {
                    return;
                }

                $file = isset($error['file']) ? (string) $error['file'] : '';
                $plugin_root = plugin_dir_path(__FILE__);
                if ($file === '' || strpos($file, $plugin_root) !== 0) {
                    return;
                }

                $line = isset($error['line']) ? (int) $error['line'] : 0;
                $message = isset($error['message']) ? (string) $error['message'] : 'onbekende fatale fout';
                error_log(sprintf('[Octopus AI] Fatale fout (%s:%d): %s', $file, $line, $message));
            }
        );
    }

    octopus_ai_register_fatal_shutdown_logger();
}

function octopus_ai_get_render_mode()
{
    $mode = get_option('octopus_ai_render_mode', 'floating');
    $allowed = array('floating', 'elementor_widget');

    if (!in_array($mode, $allowed, true)) {
        return 'floating';
    }

    return $mode;
}

function octopus_ai_is_frontend_access_allowed()
{
    $test_mode = (int) get_option('octopus_ai_test_mode', 0);
    if ($test_mode === 1 && !current_user_can('manage_options')) {
        return false;
    }

    return true;
}

function octopus_ai_should_display_chatbot()
{
    if (!octopus_ai_is_frontend_access_allowed()) {
        return false;
    }

    $mode = get_option('octopus_ai_display_mode', 'all');
    if ($mode === 'all') {
        return true;
    }

    if ($mode === 'selected') {
        $selected_pages = get_option('octopus_ai_selected_pages', array());
        return is_page($selected_pages);
    }

    return true;
}

function octopus_ai_enqueue_frontend_assets($render_context = null)
{
    $css_path = plugin_dir_path(__FILE__) . 'assets/css/chatbot.css';
    $js_path = plugin_dir_path(__FILE__) . 'assets/js/chatbot.js';
    $css_ver = file_exists($css_path) ? filemtime($css_path) : '1.0';
    $js_ver = file_exists($js_path) ? filemtime($js_path) : '1.0';

    wp_enqueue_style(
        'octopus-ai-chatbot-style',
        plugin_dir_url(__FILE__) . 'assets/css/chatbot.css',
        array(),
        $css_ver
    );

    wp_enqueue_script(
        'octopus-ai-chatbot-script',
        plugin_dir_url(__FILE__) . 'assets/js/chatbot.js',
        array('jquery'),
        $js_ver,
        true
    );

    $lang_header = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $is_french = (preg_match('#/fr(/|$)#', $request_uri) === 1) || strpos($lang_header, 'fr') === 0;
    $lang_code = $is_french ? 'FR' : 'NL';
    $mode_for_frontend = is_string($render_context) && $render_context !== '' ? $render_context : octopus_ai_get_render_mode();

    wp_localize_script(
        'octopus-ai-chatbot-script',
        'octopus_ai_chatbot_vars',
        array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'rest_url' => esc_url_raw(rest_url('octopus-ai/v1/chatbot')),
            'feedback_url' => esc_url_raw(rest_url('octopus-ai/v1/feedback')),
            'lang' => $lang_code,
            'render_mode' => $mode_for_frontend,
            'brand_name' => get_option('octopus_ai_brand_name', 'AI Chatbot'),
            'logo_url' => esc_url(get_option('octopus_ai_logo_url')),
            'primary_color' => get_option('octopus_ai_primary_color', '#0f6c95'),
            'header_text_color' => get_option('octopus_ai_header_text_color', '#ffffff'),
            'welcome_message' => (function () use ($is_french) {
                $custom_nl = get_option('octopus_ai_welcome_message_nl');
                $custom_fr = get_option('octopus_ai_welcome_message_fr');
                $legacy = get_option('octopus_ai_welcome_message');

                if ($is_french && !empty($custom_fr)) {
                    return $custom_fr;
                }
                if (!$is_french && !empty($custom_nl)) {
                    return $custom_nl;
                }
                if (!$is_french && !empty($legacy)) {
                    return $legacy;
                }

                return $is_french
                    ? "👋 Bonjour ! Comment puis-je t’aider aujourd’hui ?"
                    : "👋 Hallo! Hoe kan ik je vandaag helpen?";
            })(),
            'i18n' => array(
                'placeholder' => $is_french ? 'Tape ta question...' : 'Typ je vraag...',
                'send' => $is_french ? 'Envoyer' : 'Verstuur',
                'reset_title' => $is_french ? 'Réinitialiser la conversation' : 'Reset gesprek',
                'reset_button' => $is_french ? 'Réinitialiser' : 'Vernieuw',
                'reset_confirm' => $is_french ? 'Es-tu sûr(e) de vouloir recommencer la conversation ?' : 'Weet je zeker dat je het gesprek wilt vernieuwen?',
                'feedback_up' => $is_french ? 'Merci pour ton retour positif !' : 'Bedankt voor je positieve feedback!',
                'feedback_down' => $is_french ? "Merci pour ton retour, nous allons l'examiner." : 'We bekijken je feedback – dank je!',
                'fallback_prefix' => $is_french ? 'ℹ️ Tu trouveras peut-être la réponse dans notre manuel :' : 'ℹ️ Misschien vind je het antwoord wel in onze handleiding:',
                'fallback_button' => $is_french ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding',
                'fallback_trigger' => $is_french ? "Désolé, je ne peux pas t’aider avec ça." : 'Sorry, daar kan ik je niet mee helpen.',
                'api_error' => $is_french ? "❌ Une erreur s'est produite lors de la récupération de la réponse." : '❌ Er ging iets mis met het ophalen van het antwoord.',
            ),
        )
    );
}

function octopus_ai_normalize_embedded_widget_config($config = array())
{
    $defaults = array(
        'title' => '',
        'height' => 560,
        'radius' => 16,
        'show_topic_selector' => true,
        'show_reset_button' => true,
        'primary_color' => '',
        'header_text_color' => '',
        'welcome_message' => '',
    );

    if (!is_array($config)) {
        $config = array();
    }

    $normalized = array_merge($defaults, $config);

    $normalized['title'] = sanitize_text_field((string) $normalized['title']);
    $normalized['welcome_message'] = sanitize_textarea_field((string) $normalized['welcome_message']);
    $normalized['height'] = min(900, max(360, (int) $normalized['height']));
    $normalized['radius'] = min(28, max(8, (int) $normalized['radius']));
    $normalized['show_topic_selector'] = (bool) $normalized['show_topic_selector'];
    $normalized['show_reset_button'] = (bool) $normalized['show_reset_button'];

    $primary = sanitize_hex_color((string) $normalized['primary_color']);
    $header = sanitize_hex_color((string) $normalized['header_text_color']);
    $normalized['primary_color'] = is_string($primary) ? $primary : '';
    $normalized['header_text_color'] = is_string($header) ? $header : '';

    return $normalized;
}

function octopus_ai_render_embedded_chatbot_markup($config = array())
{
    if (octopus_ai_get_render_mode() !== 'elementor_widget') {
        return '';
    }

    if (!octopus_ai_is_frontend_access_allowed()) {
        return '';
    }

    octopus_ai_enqueue_frontend_assets('elementor_widget');

    $config = octopus_ai_normalize_embedded_widget_config($config);
    $attributes = array(
        'class="octopus-ai-chatbot-widget-host"',
        'data-octopus-chatbot-widget="1"',
        'data-widget-height="' . (int) $config['height'] . '"',
        'data-widget-radius="' . (int) $config['radius'] . '"',
        'data-show-topic-selector="' . ($config['show_topic_selector'] ? '1' : '0') . '"',
        'data-show-reset-button="' . ($config['show_reset_button'] ? '1' : '0') . '"',
    );

    if ($config['title'] !== '') {
        $attributes[] = 'data-widget-title="' . esc_attr($config['title']) . '"';
    }
    if ($config['welcome_message'] !== '') {
        $attributes[] = 'data-widget-welcome="' . esc_attr($config['welcome_message']) . '"';
    }
    if ($config['primary_color'] !== '') {
        $attributes[] = 'data-widget-primary-color="' . esc_attr($config['primary_color']) . '"';
    }
    if ($config['header_text_color'] !== '') {
        $attributes[] = 'data-widget-header-text-color="' . esc_attr($config['header_text_color']) . '"';
    }

    return '<div ' . implode(' ', $attributes) . '></div>';
}

function octopus_ai_chatbot_shortcode($atts = array())
{
    $atts = shortcode_atts(
        array(
            'title' => '',
            'height' => '560',
            'radius' => '16',
            'show_topic_selector' => 'yes',
            'show_reset_button' => 'yes',
            'primary_color' => '',
            'header_text_color' => '',
            'welcome_message' => '',
        ),
        is_array($atts) ? $atts : array(),
        'octopus_ai_chatbot'
    );

    $config = array(
        'title' => $atts['title'],
        'height' => (int) $atts['height'],
        'radius' => (int) $atts['radius'],
        'show_topic_selector' => in_array(strtolower((string) $atts['show_topic_selector']), array('1', 'true', 'yes', 'on'), true),
        'show_reset_button' => in_array(strtolower((string) $atts['show_reset_button']), array('1', 'true', 'yes', 'on'), true),
        'primary_color' => $atts['primary_color'],
        'header_text_color' => $atts['header_text_color'],
        'welcome_message' => $atts['welcome_message'],
    );

    return octopus_ai_render_embedded_chatbot_markup($config);
}

add_shortcode('octopus_ai_chatbot', 'octopus_ai_chatbot_shortcode');

add_action('wp_enqueue_scripts', function () {
    if (octopus_ai_get_render_mode() !== 'floating') {
        return;
    }

    if (!octopus_ai_should_display_chatbot()) {
        return;
    }

    octopus_ai_enqueue_frontend_assets('floating');
});

add_action('admin_menu', function () {
    if (!function_exists('octopus_ai_logs_page_callback')) {
        return;
    }

    add_submenu_page(
        'octopus-ai-chatbot',
        'Logging',
        'Logging',
        'manage_options',
        'octopus-ai-chatbot-logs',
        'octopus_ai_logs_page_callback'
    );
});

function octopus_ai_create_log_table()
{
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
