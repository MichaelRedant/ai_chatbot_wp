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
$GLOBALS['octopus_ai_repair_report'] = array();

if (!function_exists('octopus_ai_get_backslash_entries')) {
    function octopus_ai_get_backslash_entries($base_path)
    {
        $base_path = trailingslashit(wp_normalize_path((string) $base_path));
        if ($base_path === '' || !is_dir($base_path)) {
            return array();
        }

        $entries = @scandir($base_path);
        if (!is_array($entries)) {
            return array();
        }

        $backslash_entries = array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strpos((string) $entry, '\\') !== false) {
                $backslash_entries[] = (string) $entry;
            }
        }

        return $backslash_entries;
    }
}

if (!function_exists('octopus_ai_repair_backslash_entries')) {
    function octopus_ai_repair_backslash_entries($base_path)
    {
        $base_path = trailingslashit(wp_normalize_path((string) $base_path));
        $report = array(
            'attempted' => false,
            'moved' => 0,
            'failed' => 0,
            'remaining' => 0,
        );

        if ($base_path === '' || !is_dir($base_path)) {
            return $report;
        }

        for ($pass = 0; $pass < 4; $pass++) {
            $entries = octopus_ai_get_backslash_entries($base_path);
            if (empty($entries)) {
                break;
            }

            $report['attempted'] = true;

            foreach ($entries as $entry) {
                $source = $base_path . $entry;
                if (!file_exists($source)) {
                    continue;
                }

                $normalized = str_replace('\\', '/', $entry);
                $normalized = trim((string) $normalized, '/');
                if ($normalized === '') {
                    $report['failed']++;
                    continue;
                }

                $target = $base_path . $normalized;
                $target_dir = dirname($target);
                if (!is_dir($target_dir)) {
                    wp_mkdir_p($target_dir);
                }

                $moved = false;
                if (!file_exists($target)) {
                    $moved = @rename($source, $target);
                    if (!$moved && is_file($source)) {
                        $moved = @copy($source, $target);
                        if ($moved) {
                            @unlink($source);
                        }
                    }
                } else {
                    // Bestemming bestaat al: probeer bron op te ruimen.
                    if (is_file($source)) {
                        $moved = @unlink($source);
                    } elseif (is_dir($source)) {
                        $moved = @rmdir($source);
                    }
                }

                if ($moved) {
                    $report['moved']++;
                } else {
                    $report['failed']++;
                }
            }
        }

        $report['remaining'] = count(octopus_ai_get_backslash_entries($base_path));
        return $report;
    }
}

if (!function_exists('octopus_ai_maybe_repair_flattened_install')) {
    function octopus_ai_maybe_repair_flattened_install()
    {
        $base_path = trailingslashit(wp_normalize_path(plugin_dir_path(__FILE__)));
        $needs_structure = !is_dir($base_path . 'includes') || !is_dir($base_path . 'admin') || !is_dir($base_path . 'vendor');
        $has_backslash_entries = !empty(octopus_ai_get_backslash_entries($base_path));

        if ($needs_structure && $has_backslash_entries) {
            $GLOBALS['octopus_ai_repair_report'] = octopus_ai_repair_backslash_entries($base_path);
        } else {
            $GLOBALS['octopus_ai_repair_report'] = array(
                'attempted' => false,
                'moved' => 0,
                'failed' => 0,
                'remaining' => $has_backslash_entries ? count(octopus_ai_get_backslash_entries($base_path)) : 0,
            );
        }
    }
}

octopus_ai_maybe_repair_flattened_install();

if (!function_exists('octopus_ai_is_runtime_candidate_path')) {
    function octopus_ai_is_runtime_candidate_path($candidate_path)
    {
        $candidate_path = trailingslashit(wp_normalize_path((string) $candidate_path));
        if ($candidate_path === '' || !is_dir($candidate_path)) {
            return false;
        }

        $required_files = array(
            'octopus-ai-chatbot.php',
            'includes/api-handler.php',
            'admin/settings-page.php',
        );

        foreach ($required_files as $required_file) {
            if (!file_exists($candidate_path . $required_file)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('octopus_ai_find_runtime_path_from_root')) {
    function octopus_ai_find_runtime_path_from_root($root_path, $max_depth = 4)
    {
        $root_path = trailingslashit(wp_normalize_path((string) $root_path));
        $max_depth = max(0, (int) $max_depth);

        if ($root_path === '' || !is_dir($root_path)) {
            return '';
        }

        for ($depth = 0; $depth <= $max_depth; $depth++) {
            $pattern = $root_path . str_repeat('*/', $depth) . 'octopus-ai-chatbot.php';
            $matches = glob($pattern);
            if (!is_array($matches) || empty($matches)) {
                continue;
            }

            sort($matches, SORT_STRING);
            foreach ($matches as $main_file) {
                $candidate = trailingslashit(wp_normalize_path(dirname((string) $main_file)));
                if (octopus_ai_is_runtime_candidate_path($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }
}

if (!function_exists('octopus_ai_get_runtime_base_path')) {
    function octopus_ai_get_runtime_base_path()
    {
        static $resolved_path = '';
        if ($resolved_path !== '') {
            return $resolved_path;
        }

        $base_path = trailingslashit(wp_normalize_path(plugin_dir_path(__FILE__)));
        if (octopus_ai_is_runtime_candidate_path($base_path)) {
            $resolved_path = $base_path;
            return $resolved_path;
        }

        $known_folder_names = array(
            'ai-chatbot',
            'ai_chatbot_wp',
            'ai-chatbot-recovery',
            'ai_chatbot_wp_recovery',
            'ai_chatbot_wp_flat',
            'ai_chatbot_wp_flat_v2',
            'ai_chatbot_wp_hotfix_flat',
            'ai-chatbot-hotfix',
        );

        $candidates = array($base_path);
        foreach ($known_folder_names as $folder_name) {
            $candidates[] = trailingslashit($base_path . $folder_name);
        }

        $children = glob($base_path . '*', GLOB_ONLYDIR);
        if (is_array($children)) {
            foreach (array_slice($children, 0, 40) as $child_path) {
                $candidates[] = trailingslashit(wp_normalize_path($child_path));
            }
        }

        $parent_path = trailingslashit(wp_normalize_path(dirname(rtrim($base_path, '/'))));
        $candidates[] = $parent_path;
        foreach ($known_folder_names as $folder_name) {
            $candidates[] = trailingslashit($parent_path . $folder_name);
        }

        $candidates = array_values(array_unique(array_map(static function ($path) {
            return trailingslashit(wp_normalize_path((string) $path));
        }, $candidates)));

        foreach ($candidates as $candidate) {
            if (octopus_ai_is_runtime_candidate_path($candidate)) {
                $resolved_path = $candidate;
                return $resolved_path;
            }
        }

        $search_roots = array(
            $base_path,
            $parent_path,
            trailingslashit(wp_normalize_path(WP_PLUGIN_DIR)),
        );
        foreach ($search_roots as $search_root) {
            $found = octopus_ai_find_runtime_path_from_root($search_root, 8);
            if ($found !== '') {
                $resolved_path = $found;
                return $resolved_path;
            }
        }

        $resolved_path = $base_path;
        return $resolved_path;
    }
}

if (!function_exists('octopus_ai_get_runtime_base_url')) {
    function octopus_ai_get_runtime_base_url()
    {
        static $resolved_url = '';
        if ($resolved_url !== '') {
            return $resolved_url;
        }

        $runtime_path = trailingslashit(wp_normalize_path(octopus_ai_get_runtime_base_path()));
        $plugins_dir = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
        if (strpos($runtime_path, $plugins_dir) === 0) {
            $relative = trim(str_replace('\\', '/', substr($runtime_path, strlen($plugins_dir))), '/');
            if ($relative !== '') {
                $resolved_url = trailingslashit(WP_PLUGIN_URL . '/' . $relative);
                return $resolved_url;
            }
        }

        $resolved_url = plugin_dir_url(__FILE__);
        return $resolved_url;
    }
}

if (!function_exists('octopus_ai_safe_require')) {
    function octopus_ai_safe_require($relative_path)
    {
        $relative_path = ltrim((string) $relative_path, '/\\');
        $absolute_path = octopus_ai_get_runtime_base_path() . $relative_path;

        if (!file_exists($absolute_path)) {
            $GLOBALS['octopus_ai_missing_bootstrap_files'][] = $relative_path;
            error_log('[Octopus AI] Vereist bestand ontbreekt: ' . $relative_path);
            return false;
        }

        require_once $absolute_path;
        return true;
    }
}

octopus_ai_safe_require('includes/cleanup.php');
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
        $repair_report = isset($GLOBALS['octopus_ai_repair_report']) && is_array($GLOBALS['octopus_ai_repair_report'])
            ? $GLOBALS['octopus_ai_repair_report']
            : array();
        if (!empty($missing_bootstrap)) {
            $report['critical'][] = 'Ontbrekende pluginbestanden: ' . implode(', ', $missing_bootstrap);
            $report['warning'][] = 'Diagnose runtime pad: ' . (string) octopus_ai_get_runtime_base_path();
            $report['warning'][] = 'Diagnose plugin_dir_path: ' . (string) plugin_dir_path(__FILE__);
        } else {
            $report['ok'][] = 'Alle kernbestanden van de plugin zijn gevonden.';
        }

        if (!empty($repair_report['attempted'])) {
            $report['warning'][] = sprintf(
                'Auto-repair uitgevoerd: verplaatst/opgeruimd=%d, mislukt=%d, resterend met backslashes=%d',
                (int) ($repair_report['moved'] ?? 0),
                (int) ($repair_report['failed'] ?? 0),
                (int) ($repair_report['remaining'] ?? 0)
            );
        } elseif (!empty($repair_report['remaining'])) {
            $report['warning'][] = 'Backslash-bestandsnamen gedetecteerd in pluginroot: ' . (int) $repair_report['remaining'] . '.';
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

        $vendor_autoload = octopus_ai_get_runtime_base_path() . 'vendor/autoload.php';
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
                $plugin_root = octopus_ai_get_runtime_base_path();
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

if (!function_exists('octopus_ai_register_recovery_menu')) {
    function octopus_ai_register_recovery_menu()
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $missing_bootstrap = isset($GLOBALS['octopus_ai_missing_bootstrap_files']) && is_array($GLOBALS['octopus_ai_missing_bootstrap_files'])
            ? array_values(array_unique(array_filter($GLOBALS['octopus_ai_missing_bootstrap_files'])))
            : array();

        if (empty($missing_bootstrap) && function_exists('octopus_ai_settings_page')) {
            return;
        }

        add_menu_page(
            'Octopus AI Herstel',
            'Octopus AI Herstel',
            'manage_options',
            'octopus-ai-chatbot-recovery',
            'octopus_ai_render_recovery_page',
            'dashicons-warning',
            27
        );
    }

    add_action('admin_menu', 'octopus_ai_register_recovery_menu', 1);
}

if (!function_exists('octopus_ai_render_recovery_page')) {
    function octopus_ai_render_recovery_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $missing_bootstrap = isset($GLOBALS['octopus_ai_missing_bootstrap_files']) && is_array($GLOBALS['octopus_ai_missing_bootstrap_files'])
            ? array_values(array_unique(array_filter($GLOBALS['octopus_ai_missing_bootstrap_files'])))
            : array();
        $runtime_path = (string) octopus_ai_get_runtime_base_path();
        $plugin_path = (string) plugin_dir_path(__FILE__);

        $runtime_items = array();
        if (is_dir($runtime_path)) {
            $entries = scandir($runtime_path);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $runtime_items[] = (string) $entry;
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>Octopus AI Herstel</h1>';

        if (empty($missing_bootstrap)) {
            echo '<div class="notice notice-success"><p>Geen ontbrekende bootstrapbestanden gedetecteerd.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p><strong>Ontbrekende bestanden:</strong> ' . esc_html(implode(', ', $missing_bootstrap)) . '</p></div>';
        }

        echo '<p><strong>Runtime pad:</strong> <code>' . esc_html($runtime_path) . '</code></p>';
        echo '<p><strong>Plugin pad (__FILE__):</strong> <code>' . esc_html($plugin_path) . '</code></p>';

        if (!empty($runtime_items)) {
            echo '<h2>Bestanden/mappen in runtime pad</h2><ul>';
            foreach (array_slice($runtime_items, 0, 60) as $item) {
                echo '<li><code>' . esc_html($item) . '</code></li>';
            }
            echo '</ul>';
        } else {
            echo '<p>Geen mappen of bestanden gevonden in het runtime pad.</p>';
        }

        echo '<p><strong>Advies:</strong> activeer de pluginrij waarvan de activatielink exact eindigt op <code>/octopus-ai-chatbot.php</code> met slechts een mapniveau.</p>';
        echo '</div>';
    }
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
    $base_path = octopus_ai_get_runtime_base_path();
    $base_url = octopus_ai_get_runtime_base_url();
    $css_path = $base_path . 'assets/css/chatbot.css';
    $js_path = $base_path . 'assets/js/chatbot.js';
    $css_ver = file_exists($css_path) ? filemtime($css_path) : '1.0';
    $js_ver = file_exists($js_path) ? filemtime($js_path) : '1.0';

    wp_enqueue_style(
        'octopus-ai-chatbot-style',
        $base_url . 'assets/css/chatbot.css',
        array(),
        $css_ver
    );

    wp_enqueue_script(
        'octopus-ai-chatbot-script',
        $base_url . 'assets/js/chatbot.js',
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
            'topic_terms' => (function () {
                if (!function_exists('octopus_ai_get_topic_terms_map')) {
                    return array();
                }

                $raw_map = octopus_ai_get_topic_terms_map();
                if (!is_array($raw_map)) {
                    return array();
                }

                $allowed_topics = array('klantenportaal', 'boekhoudprogramma');
                $sanitized = array();

                foreach ($allowed_topics as $topic_key) {
                    if (!isset($raw_map[$topic_key]) || !is_array($raw_map[$topic_key])) {
                        continue;
                    }

                    $terms = array();
                    foreach ($raw_map[$topic_key] as $term) {
                        $term = sanitize_text_field((string) $term);
                        if ($term === '' || in_array($term, $terms, true)) {
                            continue;
                        }
                        $terms[] = $term;
                    }

                    if (!empty($terms)) {
                        $sanitized[$topic_key] = $terms;
                    }
                }

                return $sanitized;
            })(),
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
                'switch_yes' => $is_french ? 'Oui, basculer' : 'Ja, overschakelen',
                'switch_no' => $is_french ? 'Non, rester ici' : 'Nee, hier blijven',
                'switch_stay_notice' => $is_french ? "D'accord, je reste dans le flux actuel." : 'Prima, ik blijf in je huidige flow.',
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
        'font_family' => '',
        'header_font_size' => 16,
        'header_font_weight' => '600',
        'body_font_size' => 14,
        'message_radius' => 12,
        'user_message_bg' => '',
        'user_message_text' => '',
        'bot_message_bg' => '',
        'bot_message_text' => '',
        'input_bg_color' => '',
        'input_text_color' => '',
        'input_border_color' => '',
        'button_bg_color' => '',
        'button_text_color' => '',
        'button_radius' => 20,
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
    $normalized['font_family'] = substr(sanitize_text_field((string) $normalized['font_family']), 0, 120);
    $normalized['header_font_size'] = min(24, max(12, (int) $normalized['header_font_size']));
    $normalized['body_font_size'] = min(18, max(12, (int) $normalized['body_font_size']));
    $normalized['message_radius'] = min(24, max(6, (int) $normalized['message_radius']));
    $normalized['button_radius'] = min(26, max(8, (int) $normalized['button_radius']));

    $allowed_header_weights = array('400', '500', '600', '700', '800');
    $header_weight = (string) $normalized['header_font_weight'];
    $normalized['header_font_weight'] = in_array($header_weight, $allowed_header_weights, true) ? $header_weight : '600';

    $primary = sanitize_hex_color((string) $normalized['primary_color']);
    $header = sanitize_hex_color((string) $normalized['header_text_color']);
    $normalized['primary_color'] = is_string($primary) ? $primary : '';
    $normalized['header_text_color'] = is_string($header) ? $header : '';

    $color_keys = array(
        'user_message_bg',
        'user_message_text',
        'bot_message_bg',
        'bot_message_text',
        'input_bg_color',
        'input_text_color',
        'input_border_color',
        'button_bg_color',
        'button_text_color',
    );

    foreach ($color_keys as $color_key) {
        $sanitized = sanitize_hex_color((string) $normalized[$color_key]);
        $normalized[$color_key] = is_string($sanitized) ? $sanitized : '';
    }

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
        'data-widget-header-font-size="' . (int) $config['header_font_size'] . '"',
        'data-widget-header-font-weight="' . esc_attr($config['header_font_weight']) . '"',
        'data-widget-body-font-size="' . (int) $config['body_font_size'] . '"',
        'data-widget-message-radius="' . (int) $config['message_radius'] . '"',
        'data-widget-button-radius="' . (int) $config['button_radius'] . '"',
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
    if ($config['font_family'] !== '') {
        $attributes[] = 'data-widget-font-family="' . esc_attr($config['font_family']) . '"';
    }
    if ($config['user_message_bg'] !== '') {
        $attributes[] = 'data-widget-user-message-bg="' . esc_attr($config['user_message_bg']) . '"';
    }
    if ($config['user_message_text'] !== '') {
        $attributes[] = 'data-widget-user-message-text="' . esc_attr($config['user_message_text']) . '"';
    }
    if ($config['bot_message_bg'] !== '') {
        $attributes[] = 'data-widget-bot-message-bg="' . esc_attr($config['bot_message_bg']) . '"';
    }
    if ($config['bot_message_text'] !== '') {
        $attributes[] = 'data-widget-bot-message-text="' . esc_attr($config['bot_message_text']) . '"';
    }
    if ($config['input_bg_color'] !== '') {
        $attributes[] = 'data-widget-input-bg-color="' . esc_attr($config['input_bg_color']) . '"';
    }
    if ($config['input_text_color'] !== '') {
        $attributes[] = 'data-widget-input-text-color="' . esc_attr($config['input_text_color']) . '"';
    }
    if ($config['input_border_color'] !== '') {
        $attributes[] = 'data-widget-input-border-color="' . esc_attr($config['input_border_color']) . '"';
    }
    if ($config['button_bg_color'] !== '') {
        $attributes[] = 'data-widget-button-bg-color="' . esc_attr($config['button_bg_color']) . '"';
    }
    if ($config['button_text_color'] !== '') {
        $attributes[] = 'data-widget-button-text-color="' . esc_attr($config['button_text_color']) . '"';
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
            'font_family' => '',
            'header_font_size' => '16',
            'header_font_weight' => '600',
            'body_font_size' => '14',
            'message_radius' => '12',
            'user_message_bg' => '',
            'user_message_text' => '',
            'bot_message_bg' => '',
            'bot_message_text' => '',
            'input_bg_color' => '',
            'input_text_color' => '',
            'input_border_color' => '',
            'button_bg_color' => '',
            'button_text_color' => '',
            'button_radius' => '20',
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
        'font_family' => $atts['font_family'],
        'header_font_size' => (int) $atts['header_font_size'],
        'header_font_weight' => (string) $atts['header_font_weight'],
        'body_font_size' => (int) $atts['body_font_size'],
        'message_radius' => (int) $atts['message_radius'],
        'user_message_bg' => $atts['user_message_bg'],
        'user_message_text' => $atts['user_message_text'],
        'bot_message_bg' => $atts['bot_message_bg'],
        'bot_message_text' => $atts['bot_message_text'],
        'input_bg_color' => $atts['input_bg_color'],
        'input_text_color' => $atts['input_text_color'],
        'input_border_color' => $atts['input_border_color'],
        'button_bg_color' => $atts['button_bg_color'],
        'button_text_color' => $atts['button_text_color'],
        'button_radius' => (int) $atts['button_radius'],
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

if (!function_exists('octopus_ai_on_plugin_deactivation')) {
    function octopus_ai_on_plugin_deactivation()
    {
        if (function_exists('octopus_ai_clear_background_jobs')) {
            octopus_ai_clear_background_jobs();
        }
    }
}

register_deactivation_hook(__FILE__, 'octopus_ai_on_plugin_deactivation');
