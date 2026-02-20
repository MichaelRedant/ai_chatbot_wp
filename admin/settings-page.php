<?php
// Veiligheid
if (!defined('ABSPATH')) exit;


use OctopusAI\Includes\Chunker;
use OctopusAI\Includes\SitemapParser;

// --- ADMIN MENU ---
add_action('admin_menu', 'octopus_ai_add_admin_menu');
function octopus_ai_add_admin_menu() {
    add_menu_page(
        'Octopus AI Chatbot',
        'Octopus AI Chatbot',
        'manage_options',
        'octopus-ai-chatbot',
        'octopus_ai_settings_page',
        'dashicons-format-chat',
        26
    );
}

// --- ADMIN SCRIPTS ---
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'toplevel_page_octopus-ai-chatbot') {
        $css_path = plugin_dir_path(__FILE__) . '../assets/css/admin-settings.css';
        $js_path = plugin_dir_path(__FILE__) . '../assets/js/admin-settings.js';
        $css_ver = file_exists($css_path) ? filemtime($css_path) : '1.0';
        $js_ver = file_exists($js_path) ? filemtime($js_path) : '1.0';

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style('octopus-ai-admin-settings', plugin_dir_url(__FILE__) . '../assets/css/admin-settings.css', array(), $css_ver);
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();
        wp_enqueue_script('octopus-ai-admin-settings', plugin_dir_url(__FILE__) . '../assets/js/admin-settings.js', array(), $js_ver, true);
        wp_enqueue_script('octopus-ai-admin-color-picker', plugin_dir_url(__FILE__) . '../assets/js/admin-color-picker.js', array('wp-color-picker'), false, true);
        wp_enqueue_script('octopus-ai-admin-media', plugin_dir_url(__FILE__) . '../assets/js/admin-media-uploader.js', array('jquery'), '1.0', true);

        wp_localize_script('octopus-ai-admin-settings', 'octopusAiAdminSettingsVars', array(
            'queryParamsToClear' => array('upload', 'delete', 'bulk_delete', 'chunks_deleted', 'chunks_cleared', 'sitemap_debug', 'pages', 'found', 'queued', 'sitemap_saved', 'sitemap_error', 'pdf_queued', 'pdf_error', 'config_imported', 'config_updated', 'config_import_error', 'cleanup_purged', 'cleanup_options', 'cleanup_dirs', 'cleanup_tables', 'cleanup_error'),
        ));
    }
});

// --- INSTELLINGEN ---
add_action('admin_init', 'octopus_ai_register_settings');
function octopus_ai_register_settings() {
    register_setting('octopus_ai_settings_group', 'octopus_ai_model', 'sanitize_text_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_api_key', function($value) {
    $existing = get_option('octopus_ai_api_key');
    // Als het veld is gemaskeerd, wijzig dan niet
    if (strpos($value, '*****') !== false || empty(trim($value))) {
        return $existing;
    }
    return sanitize_text_field($value);
});
    register_setting('octopus_ai_settings_group', 'octopus_ai_tone', 'sanitize_textarea_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_header_text_color', 'sanitize_hex_color');
    register_setting('octopus_ai_settings_group', 'octopus_ai_test_mode', 'intval');
    register_setting('octopus_ai_settings_group', 'octopus_ai_fallback', 'sanitize_text_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_primary_color', 'sanitize_hex_color');
    register_setting('octopus_ai_settings_group', 'octopus_ai_brand_name', 'sanitize_text_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_logo_url', 'esc_url_raw');
    register_setting('octopus_ai_settings_group', 'octopus_ai_welcome_message_nl', 'sanitize_textarea_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_welcome_message_fr', 'sanitize_textarea_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_display_mode', 'sanitize_text_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_render_mode', 'octopus_ai_sanitize_render_mode');
    register_setting('octopus_ai_settings_group', 'octopus_ai_selected_pages', function($value){
        return array_map('intval', (array) $value);
    });
    register_setting('octopus_ai_settings_group', 'octopus_ai_source_strategy', 'octopus_ai_sanitize_source_strategy');
    register_setting('octopus_ai_settings_group', 'octopus_ai_manual_mode', 'octopus_ai_sanitize_manual_mode');
    register_setting('octopus_ai_settings_group', 'octopus_ai_manual_base_url_nl', 'octopus_ai_sanitize_manual_url');
    register_setting('octopus_ai_settings_group', 'octopus_ai_manual_base_url_fr', 'octopus_ai_sanitize_manual_url');
    register_setting('octopus_ai_settings_group', 'octopus_ai_manual_priority_urls_nl', 'octopus_ai_sanitize_manual_url_list');
    register_setting('octopus_ai_settings_group', 'octopus_ai_manual_priority_urls_fr', 'octopus_ai_sanitize_manual_url_list');
    register_setting('octopus_ai_settings_group', 'octopus_ai_sitemap_url', 'octopus_ai_sanitize_manual_url');
    register_setting('octopus_ai_settings_group', 'octopus_ai_provider_profile', 'octopus_ai_sanitize_provider_profile');
}

add_action('admin_init', 'octopus_ai_migrate_legacy_welcome_message', 20);
function octopus_ai_migrate_legacy_welcome_message() {
    $legacy = trim((string) get_option('octopus_ai_welcome_message', ''));
    if ($legacy === '') {
        return;
    }

    $welcome_nl = trim((string) get_option('octopus_ai_welcome_message_nl', ''));
    $welcome_fr = trim((string) get_option('octopus_ai_welcome_message_fr', ''));

    if ($welcome_nl === '') {
        update_option('octopus_ai_welcome_message_nl', $legacy);
    }

    if ($welcome_fr === '') {
        update_option('octopus_ai_welcome_message_fr', "Bonjour ! Comment puis-je t'aider aujourd'hui ?");
    }
}

function octopus_ai_sanitize_source_strategy($value) {
    $allowed = ['manual_upload', 'sitemap_online', 'live_manual'];
    $value = is_string($value) ? strtolower($value) : '';

    if (!in_array($value, $allowed, true)) {
        return 'manual_upload';
    }

    return $value;
}

function octopus_ai_sanitize_manual_mode($value) {
    $allowed = ['local', 'hybrid', 'live'];
    $value = is_string($value) ? strtolower($value) : '';
    if (!in_array($value, $allowed, true)) {
        return 'hybrid';
    }
    return $value;
}

function octopus_ai_sanitize_render_mode($value) {
    $allowed = ['floating', 'elementor_widget'];
    $value = is_string($value) ? strtolower($value) : '';

    if (!in_array($value, $allowed, true)) {
        return 'floating';
    }

    return $value;
}

function octopus_ai_sanitize_manual_url($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $sanitized = esc_url_raw($value);
    if ($sanitized === '') {
        return '';
    }

    if (function_exists('wp_http_validate_url') && !wp_http_validate_url($sanitized)) {
        return '';
    }

    return $sanitized;
}

function octopus_ai_sanitize_manual_url_list($value) {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }

    $parts = preg_split('/[\r\n,]+/', $value);
    $valid = [];

    if (is_array($parts)) {
        foreach ($parts as $part) {
            $sanitized = octopus_ai_sanitize_manual_url($part);
            if ($sanitized !== '' && !in_array($sanitized, $valid, true)) {
                $valid[] = $sanitized;
            }
        }
    }

    return implode("\n", $valid);
}

function octopus_ai_settings_admin_redirect(array $params = array()) {
    $base = admin_url('admin.php?page=octopus-ai-chatbot');
    wp_safe_redirect(add_query_arg($params, $base));
    exit;
}

function octopus_ai_pdf_admin_redirect(array $params = array()) {
    $requested_page = '';
    if (isset($_REQUEST['octopus_ai_return_page'])) {
        $requested_page = sanitize_key((string) wp_unslash($_REQUEST['octopus_ai_return_page']));
    }

    $allowed_pages = array(
        'octopus-ai-chatbot',
        'octopus_ai_pdf_beheer',
    );

    if (!in_array($requested_page, $allowed_pages, true)) {
        $requested_page = 'octopus-ai-chatbot';
    }

    $base = admin_url('admin.php?page=' . rawurlencode($requested_page));
    wp_safe_redirect(add_query_arg($params, $base));
    exit;
}

function octopus_ai_get_exportable_option_names() {
    return array(
        'octopus_ai_model',
        'octopus_ai_api_key',
        'octopus_ai_tone',
        'octopus_ai_header_text_color',
        'octopus_ai_test_mode',
        'octopus_ai_fallback',
        'octopus_ai_primary_color',
        'octopus_ai_brand_name',
        'octopus_ai_logo_url',
        'octopus_ai_welcome_message_nl',
        'octopus_ai_welcome_message_fr',
        'octopus_ai_display_mode',
        'octopus_ai_render_mode',
        'octopus_ai_selected_pages',
        'octopus_ai_source_strategy',
        'octopus_ai_manual_mode',
        'octopus_ai_manual_base_url_nl',
        'octopus_ai_manual_base_url_fr',
        'octopus_ai_manual_priority_urls_nl',
        'octopus_ai_manual_priority_urls_fr',
        'octopus_ai_sitemap_url',
        'octopus_ai_provider_profile',
    );
}

add_action('admin_post_octopus_ai_export_config', 'octopus_ai_handle_config_export');
function octopus_ai_handle_config_export() {
    if (
        !current_user_can('manage_options') ||
        !isset($_POST['octopus_ai_export_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_export_nonce'], 'octopus_ai_export_config')
    ) {
        wp_die('Beveiligingsfout bij export.');
    }

    $payload = array(
        'generated_at' => gmdate('c'),
        'plugin' => 'ai-chatbot',
        'options' => array(),
    );

    foreach (octopus_ai_get_exportable_option_names() as $option_name) {
        $payload['options'][$option_name] = get_option($option_name);
    }

    $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        wp_die('Export kon niet worden opgebouwd.');
    }

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="octopus-ai-config-' . gmdate('Ymd-His') . '.json"');
    echo $json;
    exit;
}

add_action('admin_post_octopus_ai_import_config', 'octopus_ai_handle_config_import');
function octopus_ai_handle_config_import() {
    if (
        !current_user_can('manage_options') ||
        !isset($_POST['octopus_ai_import_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_import_nonce'], 'octopus_ai_import_config')
    ) {
        wp_die('Beveiligingsfout bij import.');
    }

    if (
        !isset($_FILES['octopus_ai_config_file']) ||
        !is_array($_FILES['octopus_ai_config_file']) ||
        (int) ($_FILES['octopus_ai_config_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    ) {
        octopus_ai_settings_admin_redirect(array(
            'config_import_error' => 'Geen geldig configuratiebestand ontvangen.',
        ));
    }

    $tmp_path = (string) ($_FILES['octopus_ai_config_file']['tmp_name'] ?? '');
    $filename = sanitize_file_name((string) ($_FILES['octopus_ai_config_file']['name'] ?? ''));
    if ($tmp_path === '' || !is_uploaded_file($tmp_path) || !preg_match('/\.json$/i', $filename)) {
        octopus_ai_settings_admin_redirect(array(
            'config_import_error' => 'Upload een geldig JSON-bestand.',
        ));
    }

    $raw = file_get_contents($tmp_path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || !isset($decoded['options']) || !is_array($decoded['options'])) {
        octopus_ai_settings_admin_redirect(array(
            'config_import_error' => 'Het JSON-bestand bevat geen geldige configuratie.',
        ));
    }

    $allowed = array_flip(octopus_ai_get_exportable_option_names());
    $updated = 0;

    foreach ($decoded['options'] as $option_name => $option_value) {
        $option_name = sanitize_key((string) $option_name);
        if ($option_name === '' || !isset($allowed[$option_name])) {
            continue;
        }

        if ($option_name === 'octopus_ai_provider_profile' && function_exists('octopus_ai_sanitize_provider_profile')) {
            $option_value = octopus_ai_sanitize_provider_profile($option_value);
        } else {
            $option_value = sanitize_option($option_name, $option_value);
        }

        update_option($option_name, $option_value);
        $updated++;
    }

    octopus_ai_settings_admin_redirect(array(
        'config_imported' => 1,
        'config_updated' => $updated,
    ));
}

add_action('admin_post_octopus_ai_purge_data', 'octopus_ai_handle_purge_data');
function octopus_ai_handle_purge_data() {
    if (
        !current_user_can('manage_options') ||
        !isset($_POST['octopus_ai_purge_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_purge_nonce'], 'octopus_ai_purge_data')
    ) {
        wp_die('Beveiligingsfout bij opschonen van plugindata.');
    }

    if (!function_exists('octopus_ai_cleanup_plugin_data')) {
        octopus_ai_settings_admin_redirect(array(
            'cleanup_error' => 'Cleanup module niet beschikbaar in deze plugininstallatie.',
        ));
    }

    $summary = octopus_ai_cleanup_plugin_data(array(
        'drop_log_table' => true,
        'delete_upload_dirs' => true,
        'network_wide' => false,
    ));

    octopus_ai_settings_admin_redirect(array(
        'cleanup_purged' => 1,
        'cleanup_options' => isset($summary['options_deleted']) ? (int) $summary['options_deleted'] : 0,
        'cleanup_dirs' => isset($summary['upload_dirs_deleted']) ? (int) $summary['upload_dirs_deleted'] : 0,
        'cleanup_tables' => isset($summary['log_tables_dropped']) ? (int) $summary['log_tables_dropped'] : 0,
    ));
}

function octopus_ai_normalize_admin_match_text($value) {
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return '';
    }

    if (function_exists('remove_accents')) {
        $value = strtolower(remove_accents($value));
    }

    $value = preg_replace('/\s+/u', ' ', $value);
    return trim((string) $value);
}

function octopus_ai_guess_topic_from_question($question, array $topic_terms) {
    $normalized_question = octopus_ai_normalize_admin_match_text($question);
    if ($normalized_question === '') {
        return 'unknown';
    }

    $best_topic = 'unknown';
    $best_score = 0;

    foreach ($topic_terms as $topic => $terms) {
        if (!is_array($terms)) {
            continue;
        }

        $score = 0;
        foreach ($terms as $term) {
            $term = octopus_ai_normalize_admin_match_text($term);
            if ($term === '') {
                continue;
            }

            if (strpos($normalized_question, $term) !== false) {
                $score++;
            }
        }

        if ($score > $best_score) {
            $best_score = $score;
            $best_topic = sanitize_key((string) $topic);
        }
    }

    return $best_topic !== '' ? $best_topic : 'unknown';
}

function octopus_ai_get_fallback_metrics_snapshot($sample_size = 250) {
    global $wpdb;

    $table = $wpdb->prefix . 'octopus_ai_logs';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ((string) $exists !== (string) $table) {
        return array(
            'sample_size' => 0,
            'overall_ratio' => 0.0,
            'per_topic' => array(),
        );
    }

    $sample_size = max(50, (int) $sample_size);
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT vraag, status FROM {$table} ORDER BY id DESC LIMIT %d", $sample_size),
        ARRAY_A
    );

    if (!is_array($rows) || empty($rows)) {
        return array(
            'sample_size' => 0,
            'overall_ratio' => 0.0,
            'per_topic' => array(),
        );
    }

    $profile = function_exists('octopus_ai_get_provider_profile') ? octopus_ai_get_provider_profile() : array();
    $topic_terms = isset($profile['topic_terms']) && is_array($profile['topic_terms']) ? $profile['topic_terms'] : array(
        'klantenportaal' => array('klantenportaal', 'portal', 'portail', 'klant', 'client', 'factuur', 'betaling'),
        'boekhoudprogramma' => array('boekhoud', 'compta', 'btw', 'tva', 'journaal', 'rapport'),
    );

    $total = 0;
    $fallback = 0;
    $per_topic = array();

    foreach ($rows as $row) {
        $question = isset($row['vraag']) ? (string) $row['vraag'] : '';
        $status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
        if ($question === '' && $status === '') {
            continue;
        }

        $total++;
        $is_fallback = $status !== 'success';
        if ($is_fallback) {
            $fallback++;
        }

        $topic = octopus_ai_guess_topic_from_question($question, $topic_terms);
        if (!isset($per_topic[$topic])) {
            $per_topic[$topic] = array(
                'total' => 0,
                'fallback' => 0,
                'ratio' => 0.0,
            );
        }

        $per_topic[$topic]['total']++;
        if ($is_fallback) {
            $per_topic[$topic]['fallback']++;
        }
    }

    foreach ($per_topic as $topic => $stats) {
        $count = max(1, (int) $stats['total']);
        $per_topic[$topic]['ratio'] = round(((int) $stats['fallback'] / $count) * 100, 1);
    }

    return array(
        'sample_size' => $total,
        'overall_ratio' => $total > 0 ? round(($fallback / $total) * 100, 1) : 0.0,
        'per_topic' => $per_topic,
    );
}

add_action('octopus_ai_process_pdf_queue', 'octopus_ai_process_pdf_queue');
add_action('admin_init', 'octopus_ai_maybe_schedule_pdf_queue');

function octopus_ai_get_pdf_queue() {
    $queue = get_option('octopus_ai_pdf_queue', array());
    if (!is_array($queue)) {
        return array();
    }

    $upload_dir = wp_upload_dir();
    $allowed_dir = wp_normalize_path(trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/');
    $clean = array();

    foreach ($queue as $file_path) {
        $file_path = wp_normalize_path((string) $file_path);
        if ($file_path === '' || strpos($file_path, $allowed_dir) !== 0) {
            continue;
        }
        if (!preg_match('/\.pdf$/i', $file_path)) {
            continue;
        }
        if (!in_array($file_path, $clean, true)) {
            $clean[] = $file_path;
        }
    }

    return $clean;
}

function octopus_ai_set_pdf_queue(array $queue) {
    update_option('octopus_ai_pdf_queue', array_values($queue), false);
}

function octopus_ai_enqueue_pdf_jobs(array $file_paths) {
    $file_paths = array_values(array_unique(array_filter(array_map('wp_normalize_path', $file_paths))));
    if (empty($file_paths)) {
        return 0;
    }

    $queue = octopus_ai_get_pdf_queue();
    $combined = array_values(array_unique(array_merge($queue, $file_paths)));
    octopus_ai_set_pdf_queue($combined);

    $status = get_option('octopus_ai_pdf_queue_status', array());
    if (!is_array($status)) {
        $status = array();
    }

    $processed_files = isset($status['processed_files']) ? max(0, (int) $status['processed_files']) : 0;
    $processed_chunks = isset($status['processed_chunks']) ? max(0, (int) $status['processed_chunks']) : 0;
    $failed = isset($status['failed']) ? max(0, (int) $status['failed']) : 0;

    update_option('octopus_ai_pdf_queue_status', array(
        'queued_total' => $processed_files + count($combined),
        'remaining' => count($combined),
        'processed_files' => $processed_files,
        'processed_chunks' => $processed_chunks,
        'failed' => $failed,
        'last_run' => current_time('mysql'),
    ), false);

    if (!wp_next_scheduled('octopus_ai_process_pdf_queue')) {
        wp_schedule_single_event(time() + 10, 'octopus_ai_process_pdf_queue');
    }

    return count($combined);
}

function octopus_ai_maybe_schedule_pdf_queue() {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    $queue = octopus_ai_get_pdf_queue();
    if (empty($queue)) {
        return;
    }

    if (!wp_next_scheduled('octopus_ai_process_pdf_queue')) {
        wp_schedule_single_event(time() + 5, 'octopus_ai_process_pdf_queue');
    }
}

function octopus_ai_process_pdf_queue() {
    $lock_key = 'octopus_ai_pdf_queue_lock';
    if (get_transient($lock_key)) {
        if (!wp_next_scheduled('octopus_ai_process_pdf_queue')) {
            wp_schedule_single_event(time() + 20, 'octopus_ai_process_pdf_queue');
        }
        return;
    }

    set_transient($lock_key, 1, 3 * MINUTE_IN_SECONDS);

    try {
        $queue = octopus_ai_get_pdf_queue();
        if (empty($queue)) {
            delete_option('octopus_ai_pdf_queue_status');
            return;
        }

        if (!class_exists(Chunker::class)) {
            $chunker_file = plugin_dir_path(__FILE__) . '../includes/pdf-chunker.php';
            if (file_exists($chunker_file)) {
                require_once $chunker_file;
            }
        }

        if (!class_exists(Chunker::class)) {
            error_log('[Octopus AI] Chunker class ontbreekt, PDF queue kan niet verwerkt worden.');
            if (!wp_next_scheduled('octopus_ai_process_pdf_queue')) {
                wp_schedule_single_event(time() + 60, 'octopus_ai_process_pdf_queue');
            }
            return;
        }

        $upload_dir = wp_upload_dir();
        $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
        if (!file_exists($chunks_dir)) {
            wp_mkdir_p($chunks_dir);
        }

        $batch_size = 2;
        $batch = array_slice($queue, 0, $batch_size);
        $remaining = array_slice($queue, $batch_size);
        octopus_ai_set_pdf_queue($remaining);

        $processed_files_now = 0;
        $processed_chunks_now = 0;
        $failed_now = 0;
        $last_error_now = '';

        foreach ($batch as $file_path) {
            $file_path = (string) $file_path;
            if ($file_path === '' || !file_exists($file_path)) {
                $failed_now++;
                continue;
            }

            $filename = sanitize_file_name((string) basename($file_path));
            $slug = sanitize_title((string) basename($filename, '.pdf'));
            if ($slug === '') {
                $slug = 'pdf-' . substr(md5($filename), 0, 8);
            }

            $file_url = trailingslashit($upload_dir['baseurl']) . 'octopus-chatbot/' . $filename;

            try {
                $chunker = new Chunker();
                $chunks = $chunker->chunkPdfWithMetadata($file_path, $slug, $file_url);
                if (!is_array($chunks)) {
                    $chunks = array();
                }

                if (empty($chunks)) {
                    $failed_now++;
                    $last_error_now = 'Geen chunks gemaakt voor PDF: ' . $filename;
                    error_log('[Octopus AI] Geen chunks gemaakt voor PDF: ' . $filename);
                    continue;
                }

                foreach (glob($chunks_dir . $slug . '_chunk_*.json') as $old_file) {
                    unlink($old_file);
                }

                $chunk_index = 0;
                foreach ($chunks as $chunk) {
                    $chunk_index++;
                    $meta = isset($chunk['metadata']) && is_array($chunk['metadata']) ? $chunk['metadata'] : array();
                    $chunk_file = $chunks_dir . $slug . '_chunk_' . $chunk_index . '.json';
                    $data = array(
                        'content' => isset($chunk['content']) ? (string) $chunk['content'] : '',
                        'metadata' => array(
                            'source_title' => $meta['source_title'] ?? '',
                            'page_slug' => $meta['page_slug'] ?? '',
                            'original_page' => $meta['original_page'] ?? '',
                            'section_title' => $meta['section_title'] ?? '',
                            'source_url' => $meta['source_url'] ?? '',
                            'manual_url' => $meta['manual_url'] ?? '',
                        ),
                    );
                    file_put_contents($chunk_file, wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }

                $processed_files_now++;
                $processed_chunks_now += $chunk_index;
            } catch (Throwable $e) {
                $failed_now++;
                $last_error_now = 'PDF verwerking mislukt voor ' . $filename . ': ' . $e->getMessage();
                error_log('[Octopus AI] ' . $last_error_now);
            }
        }

        $status = get_option('octopus_ai_pdf_queue_status', array());
        if (!is_array($status)) {
            $status = array();
        }

        $status['processed_files'] = (int) ($status['processed_files'] ?? 0) + $processed_files_now;
        $status['processed_chunks'] = (int) ($status['processed_chunks'] ?? 0) + $processed_chunks_now;
        $status['failed'] = (int) ($status['failed'] ?? 0) + $failed_now;
        $status['remaining'] = count($remaining);
        $status['queued_total'] = max((int) ($status['queued_total'] ?? 0), (int) $status['processed_files'] + (int) $status['remaining']);
        $status['last_run'] = current_time('mysql');
        if ($last_error_now !== '') {
            $status['last_error'] = $last_error_now;
        } elseif ($processed_files_now > 0) {
            $status['last_error'] = '';
        }
        update_option('octopus_ai_pdf_queue_status', $status, false);

        if (!empty($remaining) && !wp_next_scheduled('octopus_ai_process_pdf_queue')) {
            wp_schedule_single_event(time() + 15, 'octopus_ai_process_pdf_queue');
        }
    } finally {
        delete_transient($lock_key);
    }
}

function octopus_ai_get_remote_pdf_max_bytes() {
    $mb = defined('MB_IN_BYTES') ? (int) MB_IN_BYTES : (1024 * 1024);
    // Remote import dient uploadlimieten te omzeilen; hou een ruimere default aan.
    $default_max = 100 * $mb;

    if (function_exists('octopus_ai_get_safe_pdf_max_bytes')) {
        $safe_max = (int) octopus_ai_get_safe_pdf_max_bytes();
        if ($safe_max > 0) {
            // Safe Smalot limiet kan laag zijn op kleine servers (bv. 4MB).
            // Voor remote import mag de limiet niet onnodig dalen, omdat fallback parsing mogelijk is.
            $default_max = max($default_max, $safe_max);
        }
    }

    $max_bytes = (int) apply_filters('octopus_ai_remote_pdf_max_bytes', $default_max);
    return max(2 * $mb, $max_bytes);
}

function octopus_ai_is_private_or_reserved_ip($ip) {
    $ip = trim((string) $ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return true;
    }

    $public_ip = filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );

    return $public_ip === false;
}

function octopus_ai_host_resolves_private_ip($host) {
    $host = strtolower(trim((string) $host));
    if ($host === '' || $host === 'localhost') {
        return true;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return octopus_ai_is_private_or_reserved_ip($host);
    }

    $ips = array();
    if (function_exists('dns_get_record') && defined('DNS_A') && defined('DNS_AAAA')) {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }
    }

    if (empty($ips)) {
        $ipv4 = @gethostbyname($host);
        if (is_string($ipv4) && $ipv4 !== '' && $ipv4 !== $host) {
            $ips[] = $ipv4;
        }
    }

    if (empty($ips)) {
        return false;
    }

    foreach (array_unique($ips) as $ip) {
        if (octopus_ai_is_private_or_reserved_ip($ip)) {
            return true;
        }
    }

    return false;
}

function octopus_ai_validate_remote_pdf_url($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return new WP_Error('octopus_ai_pdf_url_missing', 'Geef een geldige PDF-URL op.');
    }

    $sanitized = esc_url_raw($url, array('http', 'https'));
    if ($sanitized === '') {
        return new WP_Error('octopus_ai_pdf_url_invalid', 'De opgegeven PDF-URL is ongeldig.');
    }

    $scheme = strtolower((string) wp_parse_url($sanitized, PHP_URL_SCHEME));
    if (!in_array($scheme, array('http', 'https'), true)) {
        return new WP_Error('octopus_ai_pdf_url_scheme', 'Alleen http(s)-URL\'s zijn toegestaan.');
    }

    if (function_exists('wp_http_validate_url') && !wp_http_validate_url($sanitized)) {
        return new WP_Error('octopus_ai_pdf_url_invalid', 'De opgegeven PDF-URL is ongeldig.');
    }

    $host = (string) wp_parse_url($sanitized, PHP_URL_HOST);
    if ($host === '') {
        return new WP_Error('octopus_ai_pdf_url_host', 'De PDF-URL bevat geen geldige hostnaam.');
    }

    if (octopus_ai_host_resolves_private_ip($host)) {
        return new WP_Error('octopus_ai_pdf_url_private', 'Interne of lokale hostnamen zijn niet toegestaan.');
    }

    return $sanitized;
}

function octopus_ai_download_remote_pdf_to_uploads($url) {
    $validated_url = octopus_ai_validate_remote_pdf_url($url);
    if (is_wp_error($validated_url)) {
        return $validated_url;
    }

    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    if (!file_exists($upload_path) && !wp_mkdir_p($upload_path)) {
        return new WP_Error('octopus_ai_pdf_storage_failed', 'Uploadmap kon niet worden aangemaakt.');
    }

    $max_bytes = octopus_ai_get_remote_pdf_max_bytes();
    $temp_file = wp_tempnam('octopus-ai-remote-pdf');
    if (!is_string($temp_file) || $temp_file === '') {
        return new WP_Error('octopus_ai_pdf_temp_failed', 'Tijdelijk bestand kon niet worden aangemaakt.');
    }

    $response = wp_safe_remote_get($validated_url, array(
        'timeout' => 30,
        'redirection' => 5,
        'stream' => true,
        'filename' => $temp_file,
        'limit_response_size' => $max_bytes + 1024,
    ));

    if (is_wp_error($response)) {
        @unlink($temp_file);
        return new WP_Error('octopus_ai_pdf_fetch_failed', 'PDF kon niet worden opgehaald: ' . $response->get_error_message());
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    if ($status_code < 200 || $status_code >= 300) {
        @unlink($temp_file);
        return new WP_Error('octopus_ai_pdf_fetch_http', 'PDF kon niet worden opgehaald (HTTP ' . $status_code . ').');
    }

    $content_length = (int) wp_remote_retrieve_header($response, 'content-length');
    if ($content_length > 0 && $content_length > $max_bytes) {
        @unlink($temp_file);
        $max_human = function_exists('size_format') ? size_format($max_bytes, 2) : ($max_bytes . ' bytes');
        return new WP_Error('octopus_ai_pdf_too_large', 'PDF is groter dan toegelaten limiet (' . $max_human . ').');
    }

    $downloaded_size = (int) @filesize($temp_file);
    if ($downloaded_size <= 0) {
        @unlink($temp_file);
        return new WP_Error('octopus_ai_pdf_empty', 'Leeg PDF-bestand ontvangen.');
    }

    if ($downloaded_size > $max_bytes) {
        @unlink($temp_file);
        $max_human = function_exists('size_format') ? size_format($max_bytes, 2) : ($max_bytes . ' bytes');
        return new WP_Error('octopus_ai_pdf_too_large', 'PDF is groter dan toegelaten limiet (' . $max_human . ').');
    }

    $sample = '';
    $handle = @fopen($temp_file, 'rb');
    if (is_resource($handle)) {
        $sample = (string) fread($handle, 1024);
        fclose($handle);
    }

    if ($sample === '' || strpos($sample, '%PDF') === false) {
        @unlink($temp_file);
        return new WP_Error('octopus_ai_pdf_not_pdf', 'De URL levert geen geldig PDF-bestand op.');
    }

    $remote_path = (string) wp_parse_url($validated_url, PHP_URL_PATH);
    $decoded_basename = rawurldecode((string) basename($remote_path));
    $filename = sanitize_file_name($decoded_basename);
    if ($filename === '' || !preg_match('/\.pdf$/i', $filename)) {
        $filename = 'remote-' . substr(md5($validated_url), 0, 12) . '.pdf';
    }

    $unique_filename = wp_unique_filename($upload_path, $filename);
    $destination = $upload_path . $unique_filename;

    $moved = @rename($temp_file, $destination);
    if (!$moved) {
        $moved = @copy($temp_file, $destination);
        @unlink($temp_file);
    }

    if (!$moved || !file_exists($destination)) {
        @unlink($temp_file);
        return new WP_Error('octopus_ai_pdf_store_failed', 'PDF kon niet lokaal opgeslagen worden.');
    }

    return array(
        'path' => wp_normalize_path($destination),
        'filename' => $unique_filename,
        'size' => (int) @filesize($destination),
    );
}

// --- PDF UPLOAD + CHUNKING ---
add_action('admin_post_octopus_ai_pdf_upload', 'octopus_ai_handle_pdf_upload');
add_action('admin_post_octopus_ai_pdf_import_url', 'octopus_ai_handle_pdf_import_url');
function octopus_ai_handle_pdf_upload() {
    if (
        !current_user_can('manage_options') ||
        !isset($_FILES['octopus_ai_pdf_upload']) ||
        !isset($_POST['octopus_ai_pdf_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_pdf_nonce'], 'octopus_ai_upload_pdf')
    ) {
        wp_die('Beveiligingsfout bij upload.');
    }

    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    if (!file_exists($upload_path)) {
        wp_mkdir_p($upload_path);
    }

    $files = $_FILES['octopus_ai_pdf_upload'];
    $queued_files = array();

    foreach ($files['name'] as $index => $name) {
        if ($files['error'][$index] === UPLOAD_ERR_OK) {
            $filename = sanitize_file_name($name);
            $filepath = $upload_path . $filename;

            if (move_uploaded_file($files['tmp_name'][$index], $filepath)) {
                $queued_files[] = wp_normalize_path($filepath);
            }
        }
    }

    if (empty($queued_files)) {
        $error_message = 'Geen PDF-bestanden geupload of bestand kon niet opgeslagen worden.';
        octopus_ai_settings_admin_redirect(array(
            'pdf_error' => $error_message,
        ));
    }

    $queued_total = octopus_ai_enqueue_pdf_jobs($queued_files);

    octopus_ai_settings_admin_redirect(array(
        'upload' => 'success',
        'pdf_queued' => $queued_total,
    ));
}

function octopus_ai_handle_pdf_import_url() {
    if (
        !current_user_can('manage_options') ||
        !isset($_POST['octopus_ai_pdf_url_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_pdf_url_nonce'], 'octopus_ai_import_pdf_url')
    ) {
        wp_die('Beveiligingsfout bij PDF-import.');
    }

    $input_url = isset($_POST['octopus_ai_pdf_url']) ? (string) wp_unslash($_POST['octopus_ai_pdf_url']) : '';
    $download = octopus_ai_download_remote_pdf_to_uploads($input_url);
    if (is_wp_error($download)) {
        octopus_ai_pdf_admin_redirect(array(
            'pdf_error' => $download->get_error_message(),
        ));
    }

    $pdf_path = isset($download['path']) ? (string) $download['path'] : '';
    if ($pdf_path === '' || !file_exists($pdf_path)) {
        octopus_ai_pdf_admin_redirect(array(
            'pdf_error' => 'PDF kon niet lokaal opgeslagen worden.',
        ));
    }

    $queued_total = octopus_ai_enqueue_pdf_jobs(array($pdf_path));
    octopus_ai_pdf_admin_redirect(array(
        'upload' => 'success',
        'pdf_queued' => $queued_total,
    ));
}

// --- BESTAND VERWIJDEREN ---
add_action('admin_post_octopus_ai_delete_file', 'octopus_ai_handle_delete_file');
function octopus_ai_handle_delete_file() {
    if (
        !current_user_can('manage_options') ||
        !isset($_GET['file']) ||
        !isset($_GET['_wpnonce']) ||
        !wp_verify_nonce($_GET['_wpnonce'], 'octopus_ai_delete_file')
    ) {
        wp_die('Beveiligingsfout bij verwijderen.');
    }

    $upload_dir = wp_upload_dir();
    $safe_file = basename($_GET['file']);
    $file_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/' . $safe_file;
    $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

    if (file_exists($file_path)) {
        $ext = pathinfo($safe_file, PATHINFO_EXTENSION);
        if ($ext === 'pdf') {
            $slug = basename($safe_file, '.pdf');

            foreach (glob($chunks_dir . $slug . '_chunk_*.json') as $chunk) {

                unlink($chunk);
            }
        } elseif ($ext === 'xml') {
            $urls = octopus_ai_parse_sitemap($file_path);
            if ($urls && class_exists(\OctopusAI\Includes\SitemapParser::class)) {
                $parser = new \OctopusAI\Includes\SitemapParser();
                $parser->deleteChunksForUrls($urls);
            }
        }

        unlink($file_path);
        wp_redirect(add_query_arg('delete', 'success', admin_url('admin.php?page=octopus-ai-chatbot')));
    } else {
        wp_redirect(add_query_arg('delete', 'error', admin_url('admin.php?page=octopus-ai-chatbot')));
    }
    exit;
}

// --- BULK DELETE ---
add_action('admin_post_octopus_ai_bulk_delete', 'octopus_ai_handle_bulk_delete');
function octopus_ai_handle_bulk_delete() {
    if (
        !current_user_can('manage_options') ||
        !isset($_POST['octopus_ai_bulk_delete_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_bulk_delete_nonce'], 'octopus_ai_bulk_delete')
    ) {
        wp_die('Beveiligingsfout bij bulk verwijderen.');
    }

    $files_to_delete = isset($_POST['octopus_ai_files']) ? (array) $_POST['octopus_ai_files'] : array();
    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

    $deleted_count = 0;
    foreach ($files_to_delete as $filename) {
        $safe_name = basename($filename);
        $file_path = $upload_path . $safe_name;
        if (file_exists($file_path)) {
            $ext = pathinfo($safe_name, PATHINFO_EXTENSION);
            if ($ext === 'pdf') {
                $slug = basename($safe_name, '.pdf');

                foreach (glob($chunks_dir . $slug . '_chunk_*.json') as $chunk) {

                    unlink($chunk);
                }
            } elseif ($ext === 'xml') {
                $urls = octopus_ai_parse_sitemap($file_path);
                if ($urls && class_exists(\OctopusAI\Includes\SitemapParser::class)) {
                    $parser = new \OctopusAI\Includes\SitemapParser();
                    $parser->deleteChunksForUrls($urls);
                }
            }

            unlink($file_path);
            $deleted_count++;
        }
    }

    wp_redirect(add_query_arg('bulk_delete', $deleted_count, admin_url('admin.php?page=octopus-ai-chatbot')));
    exit;
}


// --- ADMIN PAGE HTML ---

function octopus_ai_settings_page() {
    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    $upload_url = trailingslashit($upload_dir['baseurl']) . 'octopus-chatbot/';
    $mode = get_option('octopus_ai_display_mode', 'all');
    $render_mode = get_option('octopus_ai_render_mode', 'floating');
    $is_floating_mode = $render_mode === 'floating';
    $selected_pages = get_option('octopus_ai_selected_pages', array());
    $pages = get_pages();
    $api_key = get_option('octopus_ai_api_key');
    if ($api_key) {
        $api_key_len = strlen($api_key);
        $masked_key = $api_key_len > 10
            ? substr($api_key, 0, 5) . str_repeat('*', $api_key_len - 10) . substr($api_key, -5)
            : str_repeat('*', $api_key_len);
    } else {
        $masked_key = '';
    }
    $selected_model = get_option('octopus_ai_model', 'gpt-4.1-mini');
    $source_strategy = get_option('octopus_ai_source_strategy', 'manual_upload');
    $manual_mode = get_option('octopus_ai_manual_mode', 'hybrid');
    $manual_base_nl = get_option('octopus_ai_manual_base_url_nl', '');
    $manual_base_fr = get_option('octopus_ai_manual_base_url_fr', '');
    $manual_priority_nl = get_option('octopus_ai_manual_priority_urls_nl', '');
    $manual_priority_fr = get_option('octopus_ai_manual_priority_urls_fr', '');
    $welcome_message_nl = get_option('octopus_ai_welcome_message_nl', '');
    $welcome_message_fr = get_option('octopus_ai_welcome_message_fr', '');
    $saved_sitemap_url = get_option('octopus_ai_sitemap_url', '');
    $sitemap_queue_status = get_option('octopus_ai_sitemap_queue_status', array());
    $pdf_queue_status = get_option('octopus_ai_pdf_queue_status', array());
    if (!is_array($sitemap_queue_status)) {
        $sitemap_queue_status = array();
    }
    if (!is_array($pdf_queue_status)) {
        $pdf_queue_status = array();
    }

    $elementor_active = did_action('elementor/loaded') || class_exists('\Elementor\Plugin');
    $provider_profile = function_exists('octopus_ai_get_provider_profile') ? octopus_ai_get_provider_profile() : array();
    $provider_profile_json = wp_json_encode($provider_profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($provider_profile_json) || $provider_profile_json === '') {
        $provider_profile_json = '{}';
    }

    if (!class_exists(SitemapParser::class)) {
        $sitemap_parser_file = plugin_dir_path(__FILE__) . '../includes/sitemap-parser.php';
        if (file_exists($sitemap_parser_file)) {
            require_once $sitemap_parser_file;
        }
    }

    $parser = class_exists(SitemapParser::class) ? new SitemapParser() : null;
    $chunk_dir_status = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
    $pdf_files = file_exists($upload_path) ? glob($upload_path . '*.pdf') : array();
    $xml_files = file_exists($upload_path) ? glob($upload_path . '*.xml') : array();
    $pdf_chunk_files = file_exists($chunk_dir_status) ? glob($chunk_dir_status . '*_chunk_*.json') : array();
    $sitemap_chunk_files = file_exists($chunk_dir_status) ? glob($chunk_dir_status . 'sitemap_*.json') : array();

    $pdf_files = is_array($pdf_files) ? $pdf_files : array();
    $xml_files = is_array($xml_files) ? $xml_files : array();
    $pdf_chunk_files = is_array($pdf_chunk_files) ? $pdf_chunk_files : array();
    $sitemap_chunk_files = is_array($sitemap_chunk_files) ? $sitemap_chunk_files : array();
    $all_chunk_files = array_values(array_merge($pdf_chunk_files, $sitemap_chunk_files));

    $chunked_pdf_slugs = array();
    foreach ($pdf_chunk_files as $chunk_file) {
        $name = (string) basename($chunk_file);
        if (preg_match('/^(.+)_chunk_\d+\.json$/', $name, $matches)) {
            $chunked_pdf_slugs[] = (string) $matches[1];
        }
    }
    $chunked_pdf_slugs = array_values(array_unique($chunked_pdf_slugs));

    $pdf_file_slugs = array();
    foreach ($pdf_files as $pdf_file) {
        $pdf_file_slugs[] = (string) basename((string) $pdf_file, '.pdf');
    }
    $pdf_file_slugs = array_values(array_unique($pdf_file_slugs));

    $pdf_covered = 0;
    foreach ($pdf_file_slugs as $slug) {
        if (in_array($slug, $chunked_pdf_slugs, true)) {
            $pdf_covered++;
        }
    }
    $pdf_coverage_pct = count($pdf_file_slugs) > 0
        ? round(($pdf_covered / count($pdf_file_slugs)) * 100, 1)
        : 0.0;

    $stale_threshold_days = 45;
    $stale_cutoff = time() - ($stale_threshold_days * DAY_IN_SECONDS);
    $stale_chunk_count = 0;
    foreach ($all_chunk_files as $chunk_file) {
        $modified = (int) @filemtime($chunk_file);
        if ($modified > 0 && $modified < $stale_cutoff) {
            $stale_chunk_count++;
        }
    }

    $fallback_metrics = octopus_ai_get_fallback_metrics_snapshot(300);
    $fallback_topic_labels = array(
        'klantenportaal' => 'Klantenportaal',
        'boekhoudprogramma' => 'Boekhoudprogramma',
        'unknown' => 'Overig/onbekend',
    );

    $source_health = array(
        'manual_upload' => array(
            'ready' => (count($pdf_chunk_files) + count($sitemap_chunk_files)) > 0,
            'label' => (count($pdf_chunk_files) + count($sitemap_chunk_files)) > 0 ? 'Klaar' : 'Nog data nodig',
            'detail' => count($pdf_files) . ' PDF-bestand(en), ' . count($pdf_chunk_files) . ' PDF-chunk(s), ' . count($sitemap_chunk_files) . ' sitemap-chunk(s)',
        ),
        'sitemap_online' => array(
            'ready' => ($saved_sitemap_url !== '' || count($xml_files) > 0) && count($sitemap_chunk_files) > 0,
            'label' => (($saved_sitemap_url !== '' || count($xml_files) > 0) && count($sitemap_chunk_files) > 0) ? 'Klaar' : 'Nog data nodig',
            'detail' => count($xml_files) . ' sitemap-bestand(en), ' . count($sitemap_chunk_files) . ' sitemap-chunk(s)',
        ),
        'live_manual' => array(
            'ready' => true,
            'label' => 'Klaar',
            'detail' => ($manual_base_nl !== '' || $manual_base_fr !== '')
                ? 'Aangepaste handleiding-URL ingesteld'
                : 'Standaard handleiding actief',
        ),
    );

    $health_cards = array(
        array(
            'title' => 'PDF-dekking',
            'value' => $pdf_coverage_pct . '%',
            'detail' => $pdf_covered . ' van ' . count($pdf_file_slugs) . ' PDF-bestanden hebben chunks.',
        ),
        array(
            'title' => 'Stale chunks',
            'value' => (string) $stale_chunk_count,
            'detail' => 'Chunks ouder dan ' . $stale_threshold_days . ' dagen.',
        ),
        array(
            'title' => 'Fallback ratio',
            'value' => (string) ($fallback_metrics['overall_ratio'] ?? 0.0) . '%',
            'detail' => 'Over laatste ' . (int) ($fallback_metrics['sample_size'] ?? 0) . ' gesprekken.',
        ),
    );

    ?>
    <div class="wrap octopus-settings">
        <h1>AI Chatbot Instellingen</h1>

        <?php if (isset($_GET['upload']) && sanitize_key((string) wp_unslash($_GET['upload'])) === 'success') : ?>
            <?php if (isset($_GET['pdf_queued']) && intval($_GET['pdf_queued']) > 0) : ?>
                <div class="notice notice-success is-dismissible"><p>PDF(s) succesvol geupload. Wachtrij voor achtergrondverwerking: <?php echo intval($_GET['pdf_queued']); ?> bestand(en).</p></div>
            <?php else : ?>
                <div class="notice notice-success is-dismissible"><p>PDF-bestanden succesvol geupload.</p></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($_GET['upload']) && sanitize_key((string) wp_unslash($_GET['upload'])) === 'sitemap' && isset($_GET['found']) && isset($_GET['pages'])) : ?>
            <?php if (isset($_GET['queued']) && intval($_GET['queued']) > 0) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['found']); ?> URL(s) gevonden en in wachtrij geplaatst voor achtergrondverwerking. Wachtrij: <?php echo intval($_GET['queued']); ?> URL(s).</p></div>
            <?php else : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['found']); ?> URL(s) gevonden en <?php echo intval($_GET['pages']); ?> pagina(s) verwerkt.</p></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($_GET['sitemap_saved']) && intval($_GET['sitemap_saved']) === 1 && $saved_sitemap_url !== '') : ?>
            <div class="notice notice-info is-dismissible"><p>Actieve sitemapbron: <code><?php echo esc_html($saved_sitemap_url); ?></code></p></div>
        <?php endif; ?>

        <?php if (!empty($sitemap_queue_status) && isset($sitemap_queue_status['remaining']) && intval($sitemap_queue_status['remaining']) > 0) : ?>
            <div class="notice notice-info is-dismissible">
                <p>Sitemap verwerking actief. Verwerkt: <?php echo intval($sitemap_queue_status['processed'] ?? 0); ?>, resterend: <?php echo intval($sitemap_queue_status['remaining']); ?>.</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['sitemap_error']) && $_GET['sitemap_error'] !== '') : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(sanitize_text_field((string) wp_unslash($_GET['sitemap_error']))); ?></p></div>
        <?php endif; ?>

        <?php if (!empty($pdf_queue_status) && isset($pdf_queue_status['remaining']) && intval($pdf_queue_status['remaining']) > 0) : ?>
            <div class="notice notice-info is-dismissible">
                <p>PDF verwerking actief. Verwerkt: <?php echo intval($pdf_queue_status['processed_files'] ?? 0); ?> bestand(en), resterend: <?php echo intval($pdf_queue_status['remaining']); ?>.</p>
            </div>
        <?php endif; ?>

        <?php if (!empty($pdf_queue_status) && !empty($pdf_queue_status['last_error'])) : ?>
            <div class="notice notice-warning is-dismissible">
                <p>Laatste PDF-fout: <?php echo esc_html((string) $pdf_queue_status['last_error']); ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['pdf_error']) && $_GET['pdf_error'] !== '') : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(sanitize_text_field((string) wp_unslash($_GET['pdf_error']))); ?></p></div>
        <?php endif; ?>

        <?php if (isset($_GET['config_imported']) && intval($_GET['config_imported']) === 1) : ?>
            <div class="notice notice-success is-dismissible"><p>Configuratie geimporteerd. Bijgewerkte instellingen: <?php echo intval($_GET['config_updated'] ?? 0); ?>.</p></div>
        <?php endif; ?>

        <?php if (isset($_GET['config_import_error']) && $_GET['config_import_error'] !== '') : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(sanitize_text_field((string) wp_unslash($_GET['config_import_error']))); ?></p></div>
        <?php endif; ?>

        <?php if (isset($_GET['cleanup_purged']) && intval($_GET['cleanup_purged']) === 1) : ?>
            <div class="notice notice-success is-dismissible">
                <p>Plugindata opgeschoond. Verwijderde opties: <?php echo intval($_GET['cleanup_options'] ?? 0); ?>, verwijderde upload-mappen: <?php echo intval($_GET['cleanup_dirs'] ?? 0); ?>, verwijderde logtabellen: <?php echo intval($_GET['cleanup_tables'] ?? 0); ?>.</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['cleanup_error']) && $_GET['cleanup_error'] !== '') : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(sanitize_text_field((string) wp_unslash($_GET['cleanup_error']))); ?></p></div>
        <?php endif; ?>

        <?php if (isset($_GET['delete']) && sanitize_key((string) wp_unslash($_GET['delete'])) === 'success') : ?>
            <div class="notice notice-success is-dismissible"><p>Bestand succesvol verwijderd.</p></div>
        <?php endif; ?>

        <form id="octopus-ai-settings-form" method="post" action="options.php">
            <?php settings_fields('octopus_ai_settings_group'); ?>
            <input type="hidden" name="octopus_ai_manual_mode" id="octopus_ai_manual_mode_hidden" value="<?php echo esc_attr($manual_mode); ?>">

            <h2>API instellingen</h2>
            <table class="form-table">
                <tr><th>API Key</th>
                    <td>
                        <input type="text" name="octopus_ai_api_key" value="<?php echo esc_attr($masked_key); ?>" style="width: 400px;" placeholder="Voer je OpenAI API key in" />
                        <p class="description">De key wordt gemaskeerd weergegeven. Vul opnieuw in om te wijzigen.</p>
                    </td>
                </tr>
            </table>

            <h2>AI model</h2>
            <table class="form-table">
                <tr>
                    <th>Modelkeuze</th>
                    <td>
                        <select name="octopus_ai_model" style="width: 400px;">
                            <?php
                            $models = [
                                'gpt-4o-mini'            => [
                                    'name'        => 'GPT-4o Mini',
                                    'description' => 'Betaalbaar, snel en perfect voor dagelijkse supportvragen.',
                                    'pricing'     => '$0.15 / $0.60',
                                ],
                                'gpt-4o-2024-11-20'      => [
                                    'name'        => 'GPT-4o (2024-11-20)',
                                    'description' => 'Geoptimaliseerde release voor betrouwbaarheid op langere gesprekken.',
                                    'pricing'     => '$0.22 / $0.88',
                                ],
                                'gpt-4.1'                => [
                                    'name'        => 'GPT-4.1',
                                    'description' => 'Maximale nauwkeurigheid voor complexe procesvragen.',
                                    'pricing'     => '$2.00 / $8.00',
                                ],
                                'gpt-3.5-turbo'          => [
                                    'name'        => 'GPT-3.5 Turbo',
                                    'description' => 'Budgetvriendelijk voor eenvoudige Q&A en standaardflows.',
                                    'pricing'     => '$0.50 / $1.50',
                                ],
                                'gpt-4o'                 => [
                                    'name'        => 'GPT-4o',
                                    'description' => 'Snel en accuraat voor intensief dagelijks gebruik.',
                                    'pricing'     => '$0.50 / $1.50',
                                ],
                                'gpt-4.1-nano-2025-04-14' => [
                                    'name'        => 'GPT-4.1 Nano (2025-04-14)',
                                    'description' => 'Supersnelle nano-variant voor korte hints en checks.',
                                    'pricing'     => '$0.08 / $0.32',
                                ],
                                'gpt-4.1-mini'           => [
                                    'name'        => 'GPT-4.1 Mini',
                                    'description' => 'Allround balans tussen kwaliteit en prijs, aanbevolen.',
                                    'pricing'     => '$0.40 / $1.60',
                                ],
                                'gpt-5-nano'             => [
                                    'name'        => 'GPT-5 Nano',
                                    'description' => 'Nieuwste nano-upgrade met betere contextbehoud.',
                                    'pricing'     => '$0.12 / $0.48',
                                ],
                                'gpt-5'                  => [
                                    'name'        => 'GPT-5',
                                    'description' => 'Premiummodel voor diepgaande dossieranalyses.',
                                    'pricing'     => '$2.50 / $10.00',
                                ],
                                'gpt-5.1-2025-11-13'     => [
                                    'name'        => 'GPT-5.1 (2025-11-13)',
                                    'description' => 'Langetermijnrelease met focus op stabiliteit en audit trails.',
                                    'pricing'     => '$3.00 / $12.00',
                                ],
                                'gpt-5-codex'            => [
                                    'name'        => 'GPT-5 Codex',
                                    'description' => 'Codex-variant met uitstekende stappenplannen en scripts.',
                                    'pricing'     => '$1.20 / $4.80',
                                ],
                                'gpt-4'                  => [
                                    'name'        => 'GPT-4',
                                    'description' => 'Bewezen klasieker voor nauwkeurige antwoorden.',
                                    'pricing'     => '$1.50 / $6.00',
                                ],
                                'gpt-5.1-codex'          => [
                                    'name'        => 'GPT-5.1 Codex',
                                    'description' => 'Meest recente Codex-versie met verbeterde taakautomatisering.',
                                    'pricing'     => '$1.60 / $6.40',
                                ],
                                'gpt-5.1'                => [
                                    'name'        => 'GPT-5.1',
                                    'description' => 'Topmodel voor kritieke klantcases en escalaties.',
                                    'pricing'     => '$2.80 / $11.20',
                                ],
                            ];

                            foreach ($models as $value => $info) {
                                $option_label = sprintf(
                                    '%s – %s (± %s per 1K tokens)',
                                    $info['name'],
                                    $info['description'],
                                    $info['pricing']
                                );

                                echo '<option value="' . esc_attr($value) . '" ' . selected($selected_model, $value, false) . '>' . esc_html($option_label) . '</option>';
                            }
                            ?>
                        </select>

                        <p style="margin-top:10px;">
                            <a href="#" id="octopus-ai-toggle-model-info">Bekijk vergelijking van modellen en prijzen</a>
                        </p>

                        <div id="model-info-table" class="octopus-model-info" style="display:none;">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th>Model</th>
                                        <th>Korte uitleg</th>
                                        <th>Prijs (prompt/completion per 1K tokens)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($models as $info) : ?>
                                        <tr>
                                            <td><?php echo esc_html($info['name']); ?></td>
                                            <td><?php echo esc_html($info['description']); ?></td>
                                            <td><?php echo esc_html($info['pricing']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
            </table>

            <h2>Bronmateriaal</h2>
            <p class="section-description">Kies één bron. De chatbot zal uitsluitend deze methode gebruiken om context en links te tonen.</p>
            <?php
            $source_cards = [
                'manual_upload' => [
                    'title'       => 'Manueel uploaden',
                    'description' => 'Upload PDF- of sitemapbestanden en bouw zelf chunks op.',
                    'badge'       => 'Offline',
                    'cta'         => 'Gebruik uitsluitend geüploade bestanden.',
                ],
                'sitemap_online' => [
                    'title'       => 'Online sitemap gebruiken',
                    'description' => 'Zoek of bewaar een sitemap-URL en laat Octopus de inhoud crawlen.',
                    'badge'       => 'Automatisch',
                    'cta'         => 'Gebruik uitsluitend de gevonden sitemap.',
                ],
                'live_manual' => [
                    'title'       => 'Live handleiding',
                    'description' => 'Haalt realtime informatie uit de handleiding en toont exacte links.',
                    'badge'       => 'Realtime',
                    'cta'         => 'Gebruik enkel live opgehaalde pagina’s.',
                ],
            ];
            ?>
            <div class="source-mode-grid">
                <?php foreach ($source_cards as $value => $card): ?>
                    <div class="source-mode-card <?php echo $source_strategy === $value ? 'is-active' : ''; ?>" data-target="<?php echo esc_attr($value); ?>">
                        <div>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
                                <input type="radio" name="octopus_ai_source_strategy" value="<?php echo esc_attr($value); ?>" <?php checked($source_strategy, $value); ?>>
                                <?php echo esc_html($card['title']); ?>
                            </label>
                            <small><?php echo esc_html($card['badge']); ?></small>
                        </div>
                        <p><?php echo esc_html($card['description']); ?></p>
                        <p class="description" style="margin:0;color:#444;"><?php echo esc_html($card['cta']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="source-health-grid">
                <?php foreach ($source_health as $key => $health): ?>
                    <div class="source-health-card <?php echo $health['ready'] ? 'is-ready' : ''; ?>">
                        <div class="source-health-header">
                            <span class="source-health-dot" aria-hidden="true"></span>
                            <span><?php echo esc_html($source_cards[$key]['title']); ?></span>
                            <small><?php echo esc_html($health['label']); ?></small>
                        </div>
                        <p class="source-health-meta"><?php echo esc_html($health['detail']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="source-health-grid source-health-secondary">
                <?php foreach ($health_cards as $card): ?>
                    <div class="source-health-card is-ready">
                        <div class="source-health-header">
                            <span class="source-health-dot" aria-hidden="true"></span>
                            <span><?php echo esc_html($card['title']); ?></span>
                            <small><?php echo esc_html($card['value']); ?></small>
                        </div>
                        <p class="source-health-meta"><?php echo esc_html($card['detail']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($fallback_metrics['per_topic'])): ?>
                <table class="widefat striped octopus-fallback-table">
                    <thead>
                        <tr>
                            <th>Topic</th>
                            <th>Fallback ratio</th>
                            <th>Aantal gesprekken</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fallback_metrics['per_topic'] as $topic_key => $stats): ?>
                            <tr>
                                <td><?php echo esc_html($fallback_topic_labels[$topic_key] ?? ucfirst((string) $topic_key)); ?></td>
                                <td><?php echo esc_html((string) ($stats['ratio'] ?? 0)); ?>%</td>
                                <td><?php echo intval($stats['total'] ?? 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="description" style="margin-top:10px;">Tip: kies de bron hierboven, klik op <strong>Instellingen opslaan</strong>, en laad daarna data in onder de panelen hieronder.</p>

            <h2 style="margin-top:35px;">Handleiding en voorkeuren</h2>
            <table class="form-table">
                <tr>
                    <th>Basis URL NL</th>
                    <td>
                        <input type="url" name="octopus_ai_manual_base_url_nl" value="<?php echo esc_attr($manual_base_nl); ?>" placeholder="https://login.octopus.be/manual/NL/" />
                        <p class="description">Optioneel: wijzig de basis van de Nederlandstalige handleiding indien je een andere omgeving gebruikt. Laat leeg voor de standaard Octopus URL.</p>
                    </td>
                </tr>
                <tr>
                    <th>Basis URL FR</th>
                    <td>
                        <input type="url" name="octopus_ai_manual_base_url_fr" value="<?php echo esc_attr($manual_base_fr); ?>" placeholder="https://login.octopus.be/manual/FR/" />
                        <p class="description">Optioneel: wijzig de basis van de Franstalige handleiding indien nodig.</p>
                    </td>
                </tr>
                <tr>
                    <th>Voorkeurspagina's NL</th>
                    <td>
                        <textarea name="octopus_ai_manual_priority_urls_nl" rows="3" placeholder="https://login.octopus.be/manual/NL/voorbeeld.htm&#10;https://login.octopus.be/manual/NL/andere-pagina.htm"><?php echo esc_textarea($manual_priority_nl); ?></textarea>
                        <p class="description">Geef één of meerdere URL's op (één per lijn) die eerst live opgehaald mogen worden wanneer de chatbot de handleiding raadpleegt.</p>
                    </td>
                </tr>
                <tr>
                    <th>Voorkeurspagina's FR</th>
                    <td>
                        <textarea name="octopus_ai_manual_priority_urls_fr" rows="3" placeholder="https://login.octopus.be/manual/FR/exemple.htm"><?php echo esc_textarea($manual_priority_fr); ?></textarea>
                        <p class="description">Worden gebruikt voor Franstalige sessies. Laat leeg om enkel de metadata van chunks te volgen.</p>
                    </td>
                </tr>
            </table>

            <h2>Provider profiel (herbruikbaar)</h2>
            <table class="form-table">
                <tr>
                    <th>Provider profiel JSON</th>
                    <td>
                        <textarea name="octopus_ai_provider_profile" rows="14" class="octopus-provider-profile"><?php echo esc_textarea($provider_profile_json); ?></textarea>
                        <p class="description">Bevat merktermen, domeintermen, off-topic termen, topic-termen en taalgebonden fallback-teksten.</p>
                        <p class="description">Hierdoor blijft de core-code herbruikbaar voor andere accountancy software bedrijven.</p>
                    </td>
                </tr>
            </table>

            <h2>Taal en weergave</h2>
            <table class="form-table">
                <tr><th>Tone of Voice</th><td><textarea name="octopus_ai_tone" rows="3" style="width: 400px;"><?php echo esc_textarea(get_option('octopus_ai_tone')); ?></textarea></td></tr>
                <tr><th>Fallback tekst</th><td><input type="text" name="octopus_ai_fallback" value="<?php echo esc_attr(get_option('octopus_ai_fallback')); ?>" style="width: 400px;" /></td></tr>
                <tr><th>Verwelkomingstekst NL</th><td><textarea name="octopus_ai_welcome_message_nl" rows="2" style="width: 400px;"><?php echo esc_textarea($welcome_message_nl); ?></textarea></td></tr>
                <tr><th>Verwelkomingstekst FR</th><td><textarea name="octopus_ai_welcome_message_fr" rows="2" style="width: 400px;"><?php echo esc_textarea($welcome_message_fr); ?></textarea></td></tr>
                <tr><th>Merknaam</th><td><input type="text" name="octopus_ai_brand_name" value="<?php echo esc_attr(get_option('octopus_ai_brand_name')); ?>" style="width: 400px;" /></td></tr>
            </table>

            <h2>Uiterlijk</h2>
            <table class="form-table">
                <tr><th>Primaire kleur</th><td><input type="text" name="octopus_ai_primary_color" class="wp-color-picker-field" data-default-color="#0f6c95" value="<?php echo esc_attr(get_option('octopus_ai_primary_color', '#0f6c95')); ?>" /></td></tr>
                <tr>
    <th>Header tekstkleur</th>
    <td><input type="text" name="octopus_ai_header_text_color" class="wp-color-picker-field" data-default-color="#ffffff" value="<?php echo esc_attr(get_option('octopus_ai_header_text_color', '#ffffff')); ?>" /></td>
</tr>

                <tr><th>Logo-URL</th>
                    <td>
                        <?php $logo = get_option('octopus_ai_logo_url'); ?>
                        <img src="<?php echo esc_url($logo); ?>" id="octopus-ai-logo-preview" class="octopus-logo-preview">
                        <input type="url" name="octopus_ai_logo_url" id="octopus-ai-logo-url" value="<?php echo esc_attr($logo); ?>" style="width:400px;" readonly>
                        <button type="button" class="button" id="octopus-ai-upload-logo-button">Upload / Selecteer logo</button>
                    </td>
                </tr>
            </table>

            <h2>Zichtbaarheid</h2>
            <table class="form-table">
                <tr>
                    <th>Plaatsing</th>
                    <td>
                        <select name="octopus_ai_render_mode" id="octopus_ai_render_mode">
                            <option value="floating" <?php selected($render_mode, 'floating'); ?>>Floating pill rechtsonder</option>
                            <option value="elementor_widget" <?php selected($render_mode, 'elementor_widget'); ?>>Elementor widget op pagina</option>
                        </select>
                        <p class="description">In Elementor-modus plaats je de widget <strong>Octopus AI Chatbot</strong> op de gewenste pagina.</p>
                        <p class="description">Je past gedrag en stijl per widget aan in de Elementor-widgetinstellingen.</p>
                        <p class="description">Shortcode fallback: <code>[octopus_ai_chatbot]</code>.</p>
                        <?php if (!$elementor_active): ?>
                            <p class="description" style="color:#b32d2e;">Elementor is niet gedetecteerd. Gebruik voorlopig de shortcode in een shortcodeblok.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr id="octopus_ai_display_mode_row" style="<?php echo $is_floating_mode ? '' : 'display:none'; ?>">
                    <th>Chatbot weergave</th>
                    <td>
                        <select name="octopus_ai_display_mode" id="octopus_ai_display_mode">
                            <option value="all" <?php selected($mode, 'all'); ?>>Op alle pagina's tonen</option>
                            <option value="selected" <?php selected($mode, 'selected'); ?>>Alleen op geselecteerde pagina's tonen</option>
                        </select>
                    </td>
                </tr>
                <tr id="octopus_ai_page_selector_row" style="<?php echo ($is_floating_mode && $mode === 'selected') ? '' : 'display:none'; ?>">
                    <th>Selecteer pagina's</th>
                    <td>
                        <select name="octopus_ai_selected_pages[]" multiple style="width: 400px;">
                            <?php foreach ($pages as $page): ?>
                                <option value="<?php echo esc_attr($page->ID); ?>" <?php selected(in_array($page->ID, $selected_pages)); ?>>
                                    <?php echo esc_html($page->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
    <th>Testmodus</th>
    <td>
        <label>
            <input type="checkbox" name="octopus_ai_test_mode" value="1" <?php checked(get_option('octopus_ai_test_mode'), 1); ?>>
            Alleen zichtbaar voor beheerders
        </label>
    </td>
</tr>

            </table>

            <?php submit_button('Instellingen opslaan'); ?>
        </form>

        <div class="upload-box octopus-credits-box">
            <h3>Credits</h3>
            <p class="section-description">
                Plugin ontwikkeld en onderhouden door <strong>Micha&euml;l Redant</strong>.
            </p>
            <p class="octopus-easter-egg">
                Kleine easter egg: alles rond 3D printen?
                <a href="https://x3dprints.be" target="_blank" rel="noopener noreferrer">X3DPrints.be</a>.
            </p>
        </div>

        <div class="upload-box octopus-config-box">
            <h3>Configuratie export/import</h3>
            <p class="section-description">Export naar JSON voor hergebruik bij andere klanten, of importeer een bestaande profielconfiguratie.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field('octopus_ai_export_config', 'octopus_ai_export_nonce'); ?>
                <input type="hidden" name="action" value="octopus_ai_export_config">
                <?php submit_button('Exporteer configuratie (JSON)', 'secondary', '', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('octopus_ai_import_config', 'octopus_ai_import_nonce'); ?>
                <input type="hidden" name="action" value="octopus_ai_import_config">
                <input type="file" name="octopus_ai_config_file" accept="application/json,.json" required>
                <?php submit_button('Importeer configuratie', 'secondary', '', false); ?>
            </form>
        </div>

        <div class="upload-box octopus-cleanup-box">
            <h3>Opschonen bij verwijderen</h3>
            <p class="section-description">Als de host pluginmappen niet laat verwijderen, kan je hier alle plugin-data veilig opschonen (opties, logtabel, chunks, sitemap-cache).</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('octopus_ai_purge_data', 'octopus_ai_purge_nonce'); ?>
                <input type="hidden" name="action" value="octopus_ai_purge_data">
                <input
                    type="submit"
                    class="button button-secondary"
                    value="Wis plugin-data nu"
                    onclick="return confirm('Dit verwijdert plugin-opties, logs en chunks. Zeker doorgaan?');"
                >
            </form>
            <p class="description" style="margin-top:8px;">Daarna kan je de plugin deactiveren/verwijderen zodra bestandsrechten dat toelaten.</p>
        </div>

        <?php
        $sitemap_dir = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
        $sitemap_url_base = trailingslashit($upload_dir['baseurl']) . 'octopus-chatbot/';
        $sitemaps = glob($sitemap_dir . '*.xml');
        $chunk_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
        $chunk_url = trailingslashit($upload_dir['baseurl']) . 'octopus-ai-chunks/';
        $shared_active = in_array($source_strategy, ['manual_upload', 'sitemap_online'], true);
        ?>

        <div class="source-mode-details">
            <div class="source-mode-panel <?php echo $source_strategy === 'manual_upload' ? 'is-active' : ''; ?>" data-mode="manual_upload">
                <div class="upload-box">
                    <h3>PDF-handleidingen uploaden</h3>
                    <p class="section-description">Upload een of meerdere PDF-bestanden. Bestanden worden in een achtergrondwachtrij geplaatst voor chunking.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('octopus_ai_upload_pdf', 'octopus_ai_pdf_nonce'); ?>
                        <input type="hidden" name="action" value="octopus_ai_pdf_upload">
                        <input type="file" name="octopus_ai_pdf_upload[]" accept="application/pdf" multiple required>
                        <?php submit_button('Upload PDF'); ?>
                    </form>
                    <hr style="margin:16px 0;">
                    <p class="section-description">Of haal een PDF rechtstreeks op via URL (handig wanneer uploadlimieten te streng zijn).</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('octopus_ai_import_pdf_url', 'octopus_ai_pdf_url_nonce'); ?>
                        <input type="hidden" name="action" value="octopus_ai_pdf_import_url">
                        <input
                            type="url"
                            name="octopus_ai_pdf_url"
                            style="width:500px;"
                            placeholder="https://example.com/handleiding.pdf"
                            required
                        >
                        <?php submit_button('Haal PDF op via URL', 'secondary', '', false); ?>
                    </form>
                </div>

                <div class="upload-box">
                    <h3>Sitemap handmatig uploaden</h3>
                    <p class="section-description">Gebruik dit als je een lokale sitemap.xml wilt uploaden en verwerken.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('octopus_ai_upload_sitemap', 'octopus_ai_sitemap_nonce'); ?>
                        <input type="hidden" name="action" value="octopus_ai_upload_sitemap">
                        <input type="file" name="octopus_ai_sitemap_file[]" accept=".xml" multiple required>
                        <?php submit_button('Upload sitemap.xml', 'secondary'); ?>
                    </form>
                </div>
            </div>

            <div class="source-mode-panel <?php echo $source_strategy === 'sitemap_online' ? 'is-active' : ''; ?>" data-mode="sitemap_online">
                <div class="upload-box">
                    <h3>Sitemap via URL</h3>
                    <p class="section-description">Geef een sitemap-URL of webpagina-URL op. De plugin zoekt de sitemap, slaat die lokaal op en zet URL's in een achtergrondwachtrij voor chunking.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('octopus_ai_import_sitemap_url', 'octopus_ai_sitemap_url_nonce'); ?>
                        <input type="hidden" name="action" value="octopus_ai_import_sitemap_url">
                        <input type="url" name="octopus_ai_sitemap_url_input" value="<?php echo esc_attr($saved_sitemap_url); ?>" style="width:500px;" placeholder="https://example.com/sitemap.xml of https://example.com/manual/" required />
                        <?php submit_button('Importeer en verwerk sitemap'); ?>
                    </form>
                    <?php if ($saved_sitemap_url !== '') : ?>
                        <p class="description">Actieve sitemapbron: <code><?php echo esc_html($saved_sitemap_url); ?></code></p>
                    <?php endif; ?>
                </div>

                <div class="upload-box">
                    <h3>Zoek sitemap automatisch</h3>
                    <p class="section-description">Geef een domein op. De crawler zoekt naar bekende sitemap-bestandsnamen en verwerkt ze meteen.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('octopus_ai_auto_sitemap', 'octopus_ai_auto_sitemap_nonce'); ?>
                        <input type="hidden" name="action" value="octopus_ai_auto_fetch_sitemap">
                        <input type="url" name="octopus_ai_site_url" style="width:400px;" placeholder="https://example.com" required />
                        <?php submit_button('Zoek sitemap automatisch', 'secondary'); ?>
                    </form>
                </div>

                <div class="upload-box">
                    <h3>Sitemap-voorbeeld</h3>
                    <?php if (isset($_GET['sitemap_debug'])) : ?>
                        <?php $urls = ($parser instanceof SitemapParser) ? $parser->getUrlsFromSitemap() : array(); ?>
                        <p><strong><?php echo intval(count($urls)); ?> URL(s)</strong> gevonden in de sitemap.</p>
                        <ul>
                            <?php foreach (array_slice($urls, 0, 10) as $url_item) : ?>
                                <li><a href="<?php echo esc_url($url_item); ?>" target="_blank"><?php echo esc_html($url_item); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p class="description">Toon een voorbeeld van de eerste gevonden URL's om te controleren of de juiste sitemap wordt gelezen.</p>
                    <?php endif; ?>
                    <p><a href="<?php echo esc_url(add_query_arg('sitemap_debug', '1')); ?>" class="button">Toon sitemap-URL's</a></p>
                </div>
            </div>

            <div class="source-mode-panel <?php echo $source_strategy === 'live_manual' ? 'is-active' : ''; ?>" data-mode="live_manual">
                <div class="upload-box">
                    <h3>Live handleiding modus</h3>
                    <p>De chatbot haalt voor elke vraag rechtstreeks tekst op uit de Octopus-handleiding. Het antwoord bevat altijd een exacte link naar de gebruikte pagina.</p>
                    <ul class="octopus-disc-list">
                        <li>Controleer dat de basis-URL's hierboven juist ingesteld zijn.</li>
                        <li>Gebruik de velden voor voorkeurspagina's om belangrijke topics te boosten.</li>
                        <li>Omdat enkel live data gebruikt wordt, hoef je geen PDF's of sitemaps te beheren.</li>
                    </ul>
                </div>
            </div>

            <div class="source-mode-panel <?php echo $shared_active ? 'is-active' : ''; ?>" data-mode-group="manual_upload,sitemap_online">
                <h3>Geuploade bestanden</h3>
                <?php if (isset($_GET['bulk_delete'])) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['bulk_delete']); ?> bestand(en) succesvol verwijderd.</p></div>
                <?php endif; ?>
                <?php if (file_exists($upload_path)) : ?>
                    <?php $files = glob($upload_path . '*'); ?>
                    <?php if ($files) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('octopus_ai_bulk_delete', 'octopus_ai_bulk_delete_nonce'); ?>
                            <input type="hidden" name="action" value="octopus_ai_bulk_delete">
                            <ul>
                                <?php foreach ($files as $file) :
                                    $filename = basename($file);
                                    $delete_url = wp_nonce_url(admin_url('admin-post.php?action=octopus_ai_delete_file&file=' . urlencode($filename)), 'octopus_ai_delete_file'); ?>
                                    <li>
                                        <label>
                                            <input type="checkbox" name="octopus_ai_files[]" value="<?php echo esc_attr($filename); ?>">
                                            <a href="<?php echo esc_url($upload_url . $filename); ?>" target="_blank"><?php echo esc_html($filename); ?></a>
                                        </label>
                                        <a href="<?php echo esc_url($delete_url); ?>" style="color:red;margin-left:10px;" onclick="return confirm('Weet je zeker dat je dit bestand wilt verwijderen?');">Verwijderen</a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p><input type="submit" class="button button-secondary" value="Geselecteerde bestanden verwijderen" onclick="return confirm('Weet je zeker dat je deze bestanden wilt verwijderen?');"></p>
                        </form>
                    <?php else : ?>
                        <p>Er zijn nog geen bestanden geüpload.</p>
                    <?php endif; ?>
                <?php else : ?>
                    <p>Er zijn nog geen bestanden geüpload.</p>
                <?php endif; ?>
            </div>

            <div class="source-mode-panel <?php echo $shared_active ? 'is-active' : ''; ?>" data-mode-group="manual_upload,sitemap_online">
                <h3>Geuploade sitemap-bestanden</h3>
                <?php if (isset($_GET['sitemaps_deleted'])) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['sitemaps_deleted']); ?> sitemap-bestand(en) verwijderd.</p></div>
                <?php endif; ?>
                <?php if ($sitemaps) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('octopus_ai_delete_sitemaps'); ?>
                        <input type="hidden" name="action" value="octopus_ai_delete_sitemaps">
                        <ul class="octopus-list-box">
                            <?php foreach ($sitemaps as $file) :
                                $filename = basename($file); ?>
                                <li>
                                    <label>
                                        <input type="checkbox" name="sitemap_files[]" value="<?php echo esc_attr($filename); ?>">
                                        <a href="<?php echo esc_url($sitemap_url_base . $filename); ?>" target="_blank"><?php echo esc_html($filename); ?></a>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p style="margin-top:10px;">
                            <input type="submit" class="button button-secondary" value="Verwijder geselecteerde sitemaps" onclick="return confirm('Weet je zeker dat je deze sitemap-bestanden wilt verwijderen?');">
                        </p>
                    </form>
                <?php else : ?>
                    <p><em>Er zijn momenteel geen sitemap-bestanden geüpload.</em></p>
                <?php endif; ?>
            </div>

            <div class="source-mode-panel <?php echo $shared_active ? 'is-active' : ''; ?>" data-mode-group="manual_upload,sitemap_online">
                <h3>Sitemap chunks beheren</h3>
                <?php if (isset($_GET['chunks_deleted'])) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['chunks_deleted']); ?> chunk(s) verwijderd.</p></div>
                <?php endif; ?>
                <?php if (isset($_GET['chunks_cleared'])) : ?>
                    <div class="notice notice-success is-dismissible"><p>Alle sitemap chunks verwijderd.</p></div>
                <?php endif; ?>
                <?php if (file_exists($chunk_dir)) : ?>
                    <?php $chunk_files = glob($chunk_dir . 'sitemap_*.json'); ?>
                    <?php if ($chunk_files) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('octopus_ai_delete_chunks'); ?>
                            <input type="hidden" name="action" value="octopus_ai_delete_chunks">
                            <ul class="octopus-list-box">
                                <?php foreach ($chunk_files as $file) :
                                    $filename = basename($file); ?>
                                    <li>
                                        <label>
                                            <input type="checkbox" name="chunk_files[]" value="<?php echo esc_attr($filename); ?>">
                                            <a href="<?php echo esc_url($chunk_url . $filename); ?>" target="_blank"><?php echo esc_html($filename); ?></a>
                                        </label>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin-top:10px;">
                                <input type="submit" class="button button-secondary" value="Geselecteerde chunks verwijderen" onclick="return confirm('Weet je zeker dat je deze bestanden wilt verwijderen?');">
                            </p>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                            <?php wp_nonce_field('octopus_ai_clear_all_chunks'); ?>
                            <input type="hidden" name="action" value="octopus_ai_clear_all_chunks">
                            <?php submit_button('Verwijder ALLE sitemap chunks', 'delete', '', false); ?>
                        </form>
                    <?php else : ?>
                        <p><em>Er zijn momenteel geen sitemap chunks opgeslagen.</em></p>
                    <?php endif; ?>
                <?php else : ?>
                    <p><em>De chunks-folder bestaat nog niet.</em></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php
}
