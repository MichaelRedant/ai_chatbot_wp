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
            'queryParamsToClear' => array('upload', 'delete', 'bulk_delete', 'chunks_deleted', 'chunks_cleared', 'sitemap_debug', 'pages', 'found', 'queued', 'sitemap_saved', 'sitemap_refreshed', 'sitemap_file', 'sitemap_error', 'pdf_queued', 'pdf_error', 'config_imported', 'config_updated', 'config_processed', 'config_unchanged', 'config_skipped_sensitive', 'config_skipped_unknown', 'config_format', 'config_import_error', 'config_export_error', 'regression_ran', 'regression_cases', 'regression_pass', 'regression_fail', 'regression_error', 'cleanup_purged', 'cleanup_options', 'cleanup_dirs', 'cleanup_tables', 'cleanup_error'),
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
    register_setting('octopus_ai_settings_group', 'octopus_ai_confidence_threshold', 'octopus_ai_sanitize_confidence_threshold');
    register_setting('octopus_ai_settings_group', 'octopus_ai_primary_color', 'sanitize_hex_color');
    register_setting('octopus_ai_settings_group', 'octopus_ai_brand_name', 'sanitize_text_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_logo_url', 'esc_url_raw');
    register_setting('octopus_ai_settings_group', 'octopus_ai_handoff_url_nl', 'octopus_ai_sanitize_manual_url');
    register_setting('octopus_ai_settings_group', 'octopus_ai_handoff_url_fr', 'octopus_ai_sanitize_manual_url');
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
    register_setting('octopus_ai_settings_group', 'octopus_ai_quality_gate_enabled', 'octopus_ai_sanitize_quality_gate_enabled');
    register_setting('octopus_ai_settings_group', 'octopus_ai_quality_gate_min_pdf_coverage', 'octopus_ai_sanitize_quality_percent');
    register_setting('octopus_ai_settings_group', 'octopus_ai_quality_gate_max_fallback_ratio', 'octopus_ai_sanitize_quality_percent');
    register_setting('octopus_ai_settings_group', 'octopus_ai_quality_gate_max_stale_chunks', 'octopus_ai_sanitize_quality_max_stale_chunks');
    register_setting('octopus_ai_settings_group', 'octopus_ai_quality_gate_min_sample_size', 'octopus_ai_sanitize_quality_min_sample_size');
    register_setting('octopus_ai_settings_group', 'octopus_ai_quality_gate_min_regression_pass_rate', 'octopus_ai_sanitize_quality_percent');
    register_setting('octopus_ai_settings_group', 'octopus_ai_quality_gate_max_regression_age_hours', 'octopus_ai_sanitize_quality_regression_age_hours');
    register_setting('octopus_ai_settings_group', 'octopus_ai_quality_gate_min_regression_cases', 'octopus_ai_sanitize_quality_regression_min_cases');
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

function octopus_ai_sanitize_confidence_threshold($value) {
    $threshold = is_numeric($value) ? (float) $value : 55.0;
    $threshold = max(0.0, min(100.0, $threshold));
    return round($threshold, 2);
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

function octopus_ai_pdf_filename_to_slug($filename) {
    $filename = sanitize_file_name((string) basename((string) $filename));
    $base = (string) pathinfo($filename, PATHINFO_FILENAME);
    if ($base === '') {
        $base = (string) $filename;
    }

    $slug = sanitize_title($base);
    if ($slug === '') {
        $slug = 'pdf-' . substr(md5((string) $filename), 0, 8);
    }

    return $slug;
}

if (!function_exists('octopus_ai_get_chunk_json_encode_flags')) {
    function octopus_ai_get_chunk_json_encode_flags() {
        $flags = JSON_UNESCAPED_UNICODE;
        $flags = (int) apply_filters('octopus_ai_chunk_json_encode_flags', $flags);
        return $flags > 0 ? $flags : JSON_UNESCAPED_UNICODE;
    }
}

if (!function_exists('octopus_ai_get_pdf_queue_batch_size')) {
    function octopus_ai_get_pdf_queue_batch_size($queue_size = 0) {
        $mb = defined('MB_IN_BYTES') ? (int) MB_IN_BYTES : (1024 * 1024);
        $memory_limit = function_exists('octopus_ai_get_memory_limit_bytes')
            ? (int) octopus_ai_get_memory_limit_bytes()
            : 0;

        $batch = 4;
        if ($memory_limit > 0 && $memory_limit < 256 * $mb) {
            $batch = 2;
        } elseif ($memory_limit >= 768 * $mb) {
            $batch = 8;
        } elseif ($memory_limit >= 512 * $mb) {
            $batch = 6;
        }

        $queue_size = max(0, (int) $queue_size);
        if ($queue_size >= 25) {
            $batch += 2;
        } elseif ($queue_size >= 10) {
            $batch += 1;
        }

        $batch = (int) apply_filters('octopus_ai_pdf_queue_batch_size', $batch, $queue_size, $memory_limit);
        return max(1, min(12, $batch));
    }
}

if (!function_exists('octopus_ai_get_pdf_queue_delay_seconds')) {
    function octopus_ai_get_pdf_queue_delay_seconds($context = 'default') {
        $context = sanitize_key((string) $context);
        $delay_map = array(
            'enqueue' => 2,
            'resume' => 2,
            'locked' => 8,
            'missing_dependency' => 25,
            'next_batch' => 3,
        );
        $delay = isset($delay_map[$context]) ? (int) $delay_map[$context] : 5;
        $delay = (int) apply_filters('octopus_ai_pdf_queue_delay_seconds', $delay, $context);
        return max(1, $delay);
    }
}

function octopus_ai_get_exportable_option_names() {
    return array(
        'octopus_ai_model',
        'octopus_ai_api_key',
        'octopus_ai_tone',
        'octopus_ai_header_text_color',
        'octopus_ai_test_mode',
        'octopus_ai_fallback',
        'octopus_ai_confidence_threshold',
        'octopus_ai_primary_color',
        'octopus_ai_brand_name',
        'octopus_ai_logo_url',
        'octopus_ai_handoff_url_nl',
        'octopus_ai_handoff_url_fr',
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
        'octopus_ai_quality_gate_enabled',
        'octopus_ai_quality_gate_min_pdf_coverage',
        'octopus_ai_quality_gate_max_fallback_ratio',
        'octopus_ai_quality_gate_max_stale_chunks',
        'octopus_ai_quality_gate_min_sample_size',
        'octopus_ai_quality_gate_min_regression_pass_rate',
        'octopus_ai_quality_gate_max_regression_age_hours',
        'octopus_ai_quality_gate_min_regression_cases',
    );
}

function octopus_ai_get_sensitive_exportable_option_names() {
    return array(
        'octopus_ai_api_key',
    );
}

function octopus_ai_get_config_export_schema_version() {
    return 2;
}

function octopus_ai_parse_checkbox_flag($value) {
    if (is_bool($value)) {
        return $value;
    }

    $value = strtolower(trim((string) $value));
    return in_array($value, array('1', 'true', 'yes', 'on'), true);
}

function octopus_ai_sanitize_quality_gate_enabled($value) {
    return octopus_ai_parse_checkbox_flag($value) ? 1 : 0;
}

function octopus_ai_sanitize_quality_percent($value) {
    $value = is_numeric($value) ? (int) round((float) $value) : 0;
    return max(0, min(100, $value));
}

function octopus_ai_sanitize_quality_max_stale_chunks($value) {
    $value = is_numeric($value) ? (int) round((float) $value) : 0;
    return max(0, min(200000, $value));
}

function octopus_ai_sanitize_quality_min_sample_size($value) {
    $value = is_numeric($value) ? (int) round((float) $value) : 0;
    return max(0, min(10000, $value));
}

function octopus_ai_sanitize_quality_regression_age_hours($value) {
    $value = is_numeric($value) ? (int) round((float) $value) : 0;
    return max(0, min(8760, $value));
}

function octopus_ai_sanitize_quality_regression_min_cases($value) {
    $value = is_numeric($value) ? (int) round((float) $value) : 0;
    return max(0, min(500, $value));
}

function octopus_ai_get_quality_gate_thresholds() {
    return array(
        'enabled' => ((int) get_option('octopus_ai_quality_gate_enabled', 1)) === 1,
        'min_pdf_coverage' => octopus_ai_sanitize_quality_percent(get_option('octopus_ai_quality_gate_min_pdf_coverage', 70)),
        'max_fallback_ratio' => octopus_ai_sanitize_quality_percent(get_option('octopus_ai_quality_gate_max_fallback_ratio', 35)),
        'max_stale_chunks' => octopus_ai_sanitize_quality_max_stale_chunks(get_option('octopus_ai_quality_gate_max_stale_chunks', 250)),
        'min_sample_size' => octopus_ai_sanitize_quality_min_sample_size(get_option('octopus_ai_quality_gate_min_sample_size', 50)),
        'min_regression_pass_rate' => octopus_ai_sanitize_quality_percent(get_option('octopus_ai_quality_gate_min_regression_pass_rate', 75)),
        'max_regression_age_hours' => octopus_ai_sanitize_quality_regression_age_hours(get_option('octopus_ai_quality_gate_max_regression_age_hours', 168)),
        'min_regression_cases' => octopus_ai_sanitize_quality_regression_min_cases(get_option('octopus_ai_quality_gate_min_regression_cases', 6)),
    );
}

if (!function_exists('octopus_ai_get_regression_snapshot')) {
    function octopus_ai_get_regression_snapshot() {
        $snapshot = get_option('octopus_ai_regression_snapshot', array());
        if (!is_array($snapshot)) {
            $snapshot = array();
        }

        $run_at_raw = sanitize_text_field((string) ($snapshot['run_at'] ?? ''));
        $run_at_ts = $run_at_raw !== '' ? strtotime($run_at_raw) : 0;
        if ($run_at_ts === false || $run_at_ts <= 0) {
            $run_at_ts = 0;
            $run_at_raw = '';
        }

        $total_cases = max(0, (int) ($snapshot['total_cases'] ?? 0));
        $pass_cases = max(0, (int) ($snapshot['pass_cases'] ?? 0));
        $fail_cases = max(0, (int) ($snapshot['fail_cases'] ?? 0));

        if ($total_cases <= 0) {
            $total_cases = $pass_cases + $fail_cases;
        }
        if ($total_cases < $pass_cases + $fail_cases) {
            $total_cases = $pass_cases + $fail_cases;
        }
        if ($fail_cases <= 0 && $total_cases > $pass_cases) {
            $fail_cases = $total_cases - $pass_cases;
        }

        $pass_rate = isset($snapshot['pass_rate']) && is_numeric($snapshot['pass_rate'])
            ? (float) $snapshot['pass_rate']
            : ($total_cases > 0 ? (($pass_cases / $total_cases) * 100.0) : 0.0);
        $pass_rate = max(0.0, min(100.0, round($pass_rate, 1)));

        $age_hours = 99999.0;
        if ($run_at_ts > 0) {
            $age_hours = max(0.0, round((time() - $run_at_ts) / HOUR_IN_SECONDS, 1));
        }

        $cases_raw = isset($snapshot['cases']) && is_array($snapshot['cases']) ? $snapshot['cases'] : array();
        $cases = array();
        foreach (array_slice($cases_raw, 0, 40) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cases[] = array(
                'question' => sanitize_text_field((string) ($row['question'] ?? '')),
                'expected_topic' => sanitize_key((string) ($row['expected_topic'] ?? '')),
                'detected_topic' => sanitize_key((string) ($row['detected_topic'] ?? '')),
                'context_length' => max(0, (int) ($row['context_length'] ?? 0)),
                'score' => round((float) ($row['score'] ?? 0.0), 2),
                'pass' => !empty($row['pass']) ? 1 : 0,
            );
        }

        return array(
            'run_at' => $run_at_raw,
            'run_at_ts' => (int) $run_at_ts,
            'age_hours' => (float) $age_hours,
            'total_cases' => (int) $total_cases,
            'pass_cases' => (int) $pass_cases,
            'fail_cases' => (int) $fail_cases,
            'pass_rate' => (float) $pass_rate,
            'cases' => $cases,
        );
    }
}

if (!function_exists('octopus_ai_build_regression_suite_cases')) {
    function octopus_ai_build_regression_suite_cases($max_cases = 12) {
        $max_cases = max(2, min(30, (int) $max_cases));
        $topic_terms_map = function_exists('octopus_ai_get_retriever_topic_terms_map')
            ? octopus_ai_get_retriever_topic_terms_map()
            : array();
        $allowed_topics = function_exists('octopus_ai_get_provider_allowed_topics')
            ? octopus_ai_get_provider_allowed_topics()
            : array();
        if (!is_array($allowed_topics) || empty($allowed_topics)) {
            $allowed_topics = array_keys(is_array($topic_terms_map) ? $topic_terms_map : array());
        }

        $cases = array();
        foreach ($allowed_topics as $topic_key) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '') {
                continue;
            }

            $label = function_exists('octopus_ai_get_topic_label')
                ? octopus_ai_get_topic_label($topic_key, 'NL')
                : ucfirst(str_replace('_', ' ', $topic_key));
            $terms = isset($topic_terms_map[$topic_key]) && is_array($topic_terms_map[$topic_key])
                ? $topic_terms_map[$topic_key]
                : array();

            $clean_terms = array();
            foreach ($terms as $term) {
                $term = sanitize_text_field((string) $term);
                if ($term === '' || strlen($term) < 4 || in_array($term, $clean_terms, true)) {
                    continue;
                }
                $clean_terms[] = $term;
                if (count($clean_terms) >= 3) {
                    break;
                }
            }

            if (empty($clean_terms)) {
                $clean_terms[] = $label;
            }

            foreach ($clean_terms as $term) {
                $cases[] = array(
                    'question' => 'Waar vind ik info over ' . $term . ' in ' . $label . '?',
                    'expected_topic' => $topic_key,
                );

                if (count($cases) >= $max_cases) {
                    break 2;
                }
            }
        }

        return $cases;
    }
}

if (!function_exists('octopus_ai_detect_regression_topic_from_metadata')) {
    function octopus_ai_detect_regression_topic_from_metadata($question, array $metadata_chunks) {
        $topic_terms_map = function_exists('octopus_ai_get_retriever_topic_terms_map')
            ? octopus_ai_get_retriever_topic_terms_map()
            : array();
        if (!is_array($topic_terms_map) || empty($topic_terms_map)) {
            return array('topic' => '', 'score' => 0.0, 'scores' => array());
        }

        $blob_parts = array((string) $question);
        foreach ($metadata_chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $blob_parts[] = (string) ($chunk['section_title'] ?? '');
            $blob_parts[] = (string) ($chunk['page_slug'] ?? '');
            $blob_parts[] = (string) ($chunk['source_url'] ?? '');
            $blob_parts[] = (string) ($chunk['manual_url'] ?? '');
        }

        $blob = function_exists('octopus_ai_normalize_search_text')
            ? octopus_ai_normalize_search_text(implode(' ', $blob_parts))
            : strtolower(implode(' ', $blob_parts));
        if ($blob === '') {
            return array('topic' => '', 'score' => 0.0, 'scores' => array());
        }

        $scores = array();
        foreach ($topic_terms_map as $topic_key => $terms) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '' || !is_array($terms)) {
                continue;
            }

            $score = 0.0;
            foreach ($terms as $term) {
                $term_norm = function_exists('octopus_ai_normalize_search_text')
                    ? octopus_ai_normalize_search_text((string) $term)
                    : strtolower((string) $term);
                if ($term_norm === '' || strlen($term_norm) < 3) {
                    continue;
                }
                $hits = substr_count($blob, $term_norm);
                if ($hits <= 0) {
                    continue;
                }
                $score += min(4.0, (float) $hits);
            }
            $scores[$topic_key] = round($score, 2);
        }

        if (empty($scores)) {
            return array('topic' => '', 'score' => 0.0, 'scores' => array());
        }

        arsort($scores);
        $best_topic = sanitize_key((string) array_key_first($scores));
        $best_score = isset($scores[$best_topic]) ? (float) $scores[$best_topic] : 0.0;

        return array(
            'topic' => $best_score > 0.0 ? $best_topic : '',
            'score' => $best_score,
            'scores' => $scores,
        );
    }
}

if (!function_exists('octopus_ai_run_regression_suite')) {
    function octopus_ai_run_regression_suite() {
        if (!function_exists('octopus_ai_retrieve_relevant_chunks')) {
            return new WP_Error('octopus_ai_regression_missing_retriever', 'Regressiesuite niet beschikbaar: retriever ontbreekt.');
        }

        $cases = octopus_ai_build_regression_suite_cases(12);
        if (empty($cases)) {
            return new WP_Error('octopus_ai_regression_no_cases', 'Regressiesuite bevat geen testcases.');
        }

        $results = array();
        $pass_cases = 0;
        $total_cases = 0;

        foreach ($cases as $case) {
            $question = sanitize_text_field((string) ($case['question'] ?? ''));
            $expected_topic = sanitize_key((string) ($case['expected_topic'] ?? ''));
            if ($question === '' || $expected_topic === '') {
                continue;
            }

            $retrieval = octopus_ai_retrieve_relevant_chunks($question, '');
            $metadata_chunks = isset($retrieval['metadata']['chunks']) && is_array($retrieval['metadata']['chunks'])
                ? $retrieval['metadata']['chunks']
                : array();
            $context_length = strlen((string) ($retrieval['context'] ?? ''));
            $detected = octopus_ai_detect_regression_topic_from_metadata($question, $metadata_chunks);
            $detected_topic = sanitize_key((string) ($detected['topic'] ?? ''));
            $detected_score = (float) ($detected['score'] ?? 0.0);

            $has_context = $context_length >= 80;
            $is_topic_match = $detected_topic !== '' && $detected_topic === $expected_topic;
            $pass = $has_context && $is_topic_match;

            if ($pass) {
                $pass_cases++;
            }
            $total_cases++;

            $results[] = array(
                'question' => $question,
                'expected_topic' => $expected_topic,
                'detected_topic' => $detected_topic,
                'context_length' => $context_length,
                'score' => round($detected_score, 2),
                'pass' => $pass ? 1 : 0,
            );
        }

        if ($total_cases <= 0) {
            return new WP_Error('octopus_ai_regression_no_valid_cases', 'Geen geldige regressietests uitgevoerd.');
        }

        $fail_cases = max(0, $total_cases - $pass_cases);
        $pass_rate = round(($pass_cases / $total_cases) * 100.0, 1);
        $snapshot = array(
            'run_at' => gmdate('c'),
            'total_cases' => $total_cases,
            'pass_cases' => $pass_cases,
            'fail_cases' => $fail_cases,
            'pass_rate' => $pass_rate,
            'cases' => $results,
        );

        update_option('octopus_ai_regression_snapshot', $snapshot);
        return $snapshot;
    }
}

function octopus_ai_collect_quality_gate_metrics() {
    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit((string) ($upload_dir['basedir'] ?? '')) . 'octopus-chatbot/';
    $chunk_dir = trailingslashit((string) ($upload_dir['basedir'] ?? '')) . 'octopus-ai-chunks/';

    $source_strategy = sanitize_key((string) get_option('octopus_ai_source_strategy', 'manual_upload'));
    $saved_sitemap_url = esc_url_raw((string) get_option('octopus_ai_sitemap_url', ''));

    $pdf_files = file_exists($upload_path)
        ? array_merge(glob($upload_path . '*.pdf') ?: array(), glob($upload_path . '*.PDF') ?: array())
        : array();
    $xml_files = file_exists($upload_path) ? (glob($upload_path . '*.xml') ?: array()) : array();
    $pdf_chunk_files = file_exists($chunk_dir) ? (glob($chunk_dir . '*_chunk_*.json') ?: array()) : array();
    $sitemap_chunk_files = file_exists($chunk_dir) ? (glob($chunk_dir . 'sitemap_*.json') ?: array()) : array();

    $pdf_files = is_array($pdf_files) ? $pdf_files : array();
    $xml_files = is_array($xml_files) ? $xml_files : array();
    $pdf_chunk_files = is_array($pdf_chunk_files) ? $pdf_chunk_files : array();
    $sitemap_chunk_files = is_array($sitemap_chunk_files) ? $sitemap_chunk_files : array();

    $pdf_chunk_files = array_values(array_filter($pdf_chunk_files, static function($file) {
        return strpos((string) basename((string) $file), 'sitemap_') !== 0;
    }));
    $all_chunk_files = array_values(array_merge($pdf_chunk_files, $sitemap_chunk_files));

    $chunked_pdf_slugs = array();
    foreach ($pdf_chunk_files as $chunk_file) {
        $name = (string) basename((string) $chunk_file);
        if (preg_match('/^(.+)_chunk_\d+\.json$/', $name, $matches)) {
            $chunked_pdf_slugs[] = (string) $matches[1];
        }
    }
    $chunked_pdf_slugs = array_values(array_unique($chunked_pdf_slugs));

    $pdf_file_slugs = array();
    foreach ($pdf_files as $pdf_file) {
        $pdf_file_slugs[] = octopus_ai_pdf_filename_to_slug((string) basename((string) $pdf_file));
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
        $modified = (int) @filemtime((string) $chunk_file);
        if ($modified > 0 && $modified < $stale_cutoff) {
            $stale_chunk_count++;
        }
    }

    $fallback_metrics = octopus_ai_get_fallback_metrics_snapshot(300);
    $regression_snapshot = octopus_ai_get_regression_snapshot();
    $source_health = array(
        'manual_upload' => (count($pdf_chunk_files) + count($sitemap_chunk_files)) > 0,
        'sitemap_online' => (($saved_sitemap_url !== '' || count($xml_files) > 0) && count($sitemap_chunk_files) > 0),
        'live_manual' => true,
    );

    return array(
        'source_strategy' => $source_strategy,
        'source_ready' => !empty($source_health[$source_strategy]) ? true : false,
        'pdf_coverage_pct' => (float) $pdf_coverage_pct,
        'pdf_file_count' => count($pdf_file_slugs),
        'pdf_chunk_count' => count($pdf_chunk_files),
        'sitemap_file_count' => count($xml_files),
        'sitemap_chunk_count' => count($sitemap_chunk_files),
        'stale_chunk_count' => (int) $stale_chunk_count,
        'stale_threshold_days' => (int) $stale_threshold_days,
        'fallback_ratio' => (float) ($fallback_metrics['overall_ratio'] ?? 0.0),
        'fallback_sample_size' => (int) ($fallback_metrics['sample_size'] ?? 0),
        'regression_pass_rate' => (float) ($regression_snapshot['pass_rate'] ?? 0.0),
        'regression_total_cases' => (int) ($regression_snapshot['total_cases'] ?? 0),
        'regression_fail_cases' => (int) ($regression_snapshot['fail_cases'] ?? 0),
        'regression_age_hours' => (float) ($regression_snapshot['age_hours'] ?? 99999.0),
        'regression_run_at' => (string) ($regression_snapshot['run_at'] ?? ''),
    );
}

function octopus_ai_build_quality_gate_report($metrics = array()) {
    $thresholds = octopus_ai_get_quality_gate_thresholds();
    if (!is_array($metrics) || empty($metrics)) {
        $metrics = octopus_ai_collect_quality_gate_metrics();
    }

    $source_strategy = sanitize_key((string) ($metrics['source_strategy'] ?? 'manual_upload'));
    $source_labels = array(
        'manual_upload' => 'Manueel uploaden',
        'sitemap_online' => 'Online sitemap',
        'live_manual' => 'Live handleiding',
    );
    $source_label = $source_labels[$source_strategy] ?? $source_strategy;

    $checks = array();
    $checks[] = array(
        'key' => 'source_ready',
        'label' => 'Actieve bron is operationeel',
        'status' => !empty($metrics['source_ready']) ? 'pass' : 'fail',
        'detail' => !empty($metrics['source_ready'])
            ? 'Bron ' . $source_label . ' is klaar voor antwoordgeneratie.'
            : 'Bron ' . $source_label . ' mist nog bruikbare data/chunks.',
    );

    $pdf_required = $source_strategy === 'manual_upload' && ((int) ($metrics['pdf_file_count'] ?? 0) > 0);
    if ($pdf_required) {
        $pdf_actual = (float) ($metrics['pdf_coverage_pct'] ?? 0.0);
        $pdf_target = (int) ($thresholds['min_pdf_coverage'] ?? 70);
        $checks[] = array(
            'key' => 'pdf_coverage',
            'label' => 'PDF-dekking',
            'status' => $pdf_actual >= $pdf_target ? 'pass' : 'fail',
            'detail' => 'Actueel: ' . $pdf_actual . '% (min: ' . $pdf_target . '%).',
        );
    } else {
        $checks[] = array(
            'key' => 'pdf_coverage',
            'label' => 'PDF-dekking',
            'status' => 'skip',
            'detail' => 'Niet van toepassing voor de huidige bronstrategie.',
        );
    }

    $chunks_required = in_array($source_strategy, array('manual_upload', 'sitemap_online'), true);
    if ($chunks_required) {
        $stale_actual = (int) ($metrics['stale_chunk_count'] ?? 0);
        $stale_target = (int) ($thresholds['max_stale_chunks'] ?? 250);
        $checks[] = array(
            'key' => 'stale_chunks',
            'label' => 'Stale chunks',
            'status' => $stale_actual <= $stale_target ? 'pass' : 'fail',
            'detail' => 'Actueel: ' . $stale_actual . ' (max: ' . $stale_target . ').',
        );
    } else {
        $checks[] = array(
            'key' => 'stale_chunks',
            'label' => 'Stale chunks',
            'status' => 'skip',
            'detail' => 'Niet van toepassing voor live-only bronstrategie.',
        );
    }

    $fallback_sample = (int) ($metrics['fallback_sample_size'] ?? 0);
    $fallback_min_sample = (int) ($thresholds['min_sample_size'] ?? 50);
    if ($fallback_sample >= $fallback_min_sample) {
        $fallback_actual = (float) ($metrics['fallback_ratio'] ?? 0.0);
        $fallback_target = (int) ($thresholds['max_fallback_ratio'] ?? 35);
        $checks[] = array(
            'key' => 'fallback_ratio',
            'label' => 'Fallback ratio',
            'status' => $fallback_actual <= $fallback_target ? 'pass' : 'fail',
            'detail' => 'Actueel: ' . $fallback_actual . '% (max: ' . $fallback_target . '%) over ' . $fallback_sample . ' gesprekken.',
        );
    } else {
        $checks[] = array(
            'key' => 'fallback_ratio',
            'label' => 'Fallback ratio',
            'status' => 'skip',
            'detail' => 'Nog onvoldoende data: ' . $fallback_sample . ' / ' . $fallback_min_sample . ' gesprekken.',
        );
    }

    $regression_min_cases = (int) ($thresholds['min_regression_cases'] ?? 6);
    $regression_target = (int) ($thresholds['min_regression_pass_rate'] ?? 75);
    $regression_total = (int) ($metrics['regression_total_cases'] ?? 0);
    $regression_rate = (float) ($metrics['regression_pass_rate'] ?? 0.0);
    $regression_age_hours = (float) ($metrics['regression_age_hours'] ?? 99999.0);
    $regression_max_age_hours = (int) ($thresholds['max_regression_age_hours'] ?? 168);
    $regression_required = $source_strategy !== 'live_manual';

    if (!$regression_required) {
        $checks[] = array(
            'key' => 'regression_suite',
            'label' => 'Regressiescore',
            'status' => 'skip',
            'detail' => 'Niet van toepassing voor live-only bronstrategie.',
        );
    } elseif ($regression_min_cases <= 0) {
        $checks[] = array(
            'key' => 'regression_suite',
            'label' => 'Regressiescore',
            'status' => 'skip',
            'detail' => 'Regressiecontrole is uitgeschakeld (minimum cases = 0).',
        );
    } elseif ($regression_total < $regression_min_cases) {
        $checks[] = array(
            'key' => 'regression_suite',
            'label' => 'Regressiescore',
            'status' => 'fail',
            'detail' => 'Onvoldoende regressietests: ' . $regression_total . ' / ' . $regression_min_cases . '.',
        );
    } elseif ($regression_max_age_hours > 0 && $regression_age_hours > $regression_max_age_hours) {
        $checks[] = array(
            'key' => 'regression_suite',
            'label' => 'Regressiescore',
            'status' => 'fail',
            'detail' => 'Laatste regressierun is te oud: ' . $regression_age_hours . 'u (max: ' . $regression_max_age_hours . 'u).',
        );
    } else {
        $checks[] = array(
            'key' => 'regression_suite',
            'label' => 'Regressiescore',
            'status' => $regression_rate >= $regression_target ? 'pass' : 'fail',
            'detail' => 'Actueel: ' . $regression_rate . '% (min: ' . $regression_target . '%) over ' . $regression_total . ' cases.',
        );
    }

    $failed_labels = array();
    foreach ($checks as $check) {
        if (($check['status'] ?? '') === 'fail') {
            $failed_labels[] = (string) ($check['label'] ?? 'Check');
        }
    }

    return array(
        'enabled' => !empty($thresholds['enabled']),
        'pass' => empty($failed_labels),
        'checks' => $checks,
        'failed_labels' => $failed_labels,
        'thresholds' => $thresholds,
        'metrics' => $metrics,
    );
}

function octopus_ai_get_exportable_option_names_for_mode($include_sensitive = false) {
    $all_option_names = octopus_ai_get_exportable_option_names();
    if ($include_sensitive) {
        return $all_option_names;
    }

    $sensitive = array_flip(octopus_ai_get_sensitive_exportable_option_names());
    return array_values(array_filter($all_option_names, static function($option_name) use ($sensitive) {
        return !isset($sensitive[$option_name]);
    }));
}

function octopus_ai_build_config_export_payload($include_sensitive = false) {
    $options = array();
    foreach (octopus_ai_get_exportable_option_names_for_mode($include_sensitive) as $option_name) {
        $options[$option_name] = get_option($option_name);
    }

    $payload = array(
        'schema_version' => octopus_ai_get_config_export_schema_version(),
        'generated_at' => gmdate('c'),
        'plugin' => 'ai-chatbot',
        'plugin_version' => defined('OCTOPUS_AI_VERSION') ? (string) OCTOPUS_AI_VERSION : '',
        'includes_sensitive' => $include_sensitive ? 1 : 0,
        'exported_from' => array(
            'site_url' => esc_url_raw((string) home_url('/')),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
        ),
        'options' => $options,
    );

    $options_json = wp_json_encode($options);
    if (is_string($options_json) && $options_json !== '' && function_exists('hash')) {
        $payload['options_checksum_sha256'] = hash('sha256', $options_json);
    }

    return $payload;
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

    $include_sensitive = octopus_ai_parse_checkbox_flag($_POST['octopus_ai_export_include_sensitive'] ?? '0');

    $quality_gate = function_exists('octopus_ai_build_quality_gate_report')
        ? octopus_ai_build_quality_gate_report()
        : array('enabled' => false, 'pass' => true, 'failed_labels' => array());
    $quality_enabled = !empty($quality_gate['enabled']);
    $quality_pass = !empty($quality_gate['pass']);

    if ($quality_enabled && !$quality_pass) {
        $failed_labels = isset($quality_gate['failed_labels']) && is_array($quality_gate['failed_labels'])
            ? array_values(array_filter(array_map('sanitize_text_field', $quality_gate['failed_labels'])))
            : array();
        $reason = !empty($failed_labels)
            ? implode(', ', $failed_labels)
            : 'kwaliteitschecks niet gehaald';

        octopus_ai_settings_admin_redirect(array(
            'config_export_error' => 'Export geblokkeerd door quality gate: ' . $reason . '.',
        ));
    }

    $payload = octopus_ai_build_config_export_payload($include_sensitive);

    $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        wp_die('Export kon niet worden opgebouwd.');
    }

    $suffix = $include_sensitive ? 'full' : 'deploy';
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="octopus-ai-config-' . $suffix . '-' . gmdate('Ymd-His') . '.json"');
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

    $plugin_marker = sanitize_key((string) ($decoded['plugin'] ?? ''));
    if ($plugin_marker !== '' && !in_array($plugin_marker, array('ai-chatbot', 'octopus-ai-chatbot', 'octopus_ai_chatbot'), true)) {
        octopus_ai_settings_admin_redirect(array(
            'config_import_error' => 'Dit configuratiebestand hoort niet bij deze plugin.',
        ));
    }

    $schema_version = isset($decoded['schema_version']) ? (int) $decoded['schema_version'] : 1;
    $supported_schema = octopus_ai_get_config_export_schema_version();
    if ($schema_version > $supported_schema) {
        octopus_ai_settings_admin_redirect(array(
            'config_import_error' => 'Configuratieformaat is nieuwer dan deze pluginversie. Werk eerst de plugin bij.',
        ));
    }

    if (isset($decoded['options_checksum_sha256']) && is_string($decoded['options_checksum_sha256'])) {
        $expected_checksum = strtolower(trim((string) $decoded['options_checksum_sha256']));
        $options_json = wp_json_encode($decoded['options']);
        $actual_checksum = (is_string($options_json) && $options_json !== '' && function_exists('hash'))
            ? hash('sha256', $options_json)
            : '';

        if ($expected_checksum !== '' && $actual_checksum !== '' && !hash_equals($expected_checksum, $actual_checksum)) {
            octopus_ai_settings_admin_redirect(array(
                'config_import_error' => 'Checksum mismatch: configuratiebestand lijkt gewijzigd of beschadigd.',
            ));
        }
    }

    $allowed = array_flip(octopus_ai_get_exportable_option_names());
    $sensitive = array_flip(octopus_ai_get_sensitive_exportable_option_names());
    $import_sensitive = octopus_ai_parse_checkbox_flag($_POST['octopus_ai_import_api_key'] ?? '0');

    $updated = 0;
    $processed = 0;
    $unchanged = 0;
    $skipped_sensitive = 0;
    $skipped_unknown = 0;

    foreach ($decoded['options'] as $option_name => $option_value) {
        $option_name = sanitize_key((string) $option_name);
        if ($option_name === '' || !isset($allowed[$option_name])) {
            $skipped_unknown++;
            continue;
        }

        if (isset($sensitive[$option_name]) && !$import_sensitive) {
            $skipped_sensitive++;
            continue;
        }

        if ($option_name === 'octopus_ai_provider_profile' && function_exists('octopus_ai_sanitize_provider_profile')) {
            $option_value = octopus_ai_sanitize_provider_profile($option_value);
        } else {
            $option_value = sanitize_option($option_name, $option_value);
        }

        $processed++;
        if (update_option($option_name, $option_value)) {
            $updated++;
        } else {
            $unchanged++;
        }
    }

    octopus_ai_settings_admin_redirect(array(
        'config_imported' => 1,
        'config_updated' => $updated,
        'config_processed' => $processed,
        'config_unchanged' => $unchanged,
        'config_skipped_sensitive' => $skipped_sensitive,
        'config_skipped_unknown' => $skipped_unknown,
        'config_format' => $schema_version >= 2 ? 'v2' : 'legacy',
    ));
}

add_action('admin_post_octopus_ai_run_regression_suite', 'octopus_ai_handle_run_regression_suite');
function octopus_ai_handle_run_regression_suite() {
    $nonce_value = '';
    if (isset($_REQUEST['octopus_ai_regression_nonce'])) {
        $nonce_value = (string) wp_unslash($_REQUEST['octopus_ai_regression_nonce']);
    } elseif (isset($_REQUEST['_wpnonce'])) {
        $nonce_value = (string) wp_unslash($_REQUEST['_wpnonce']);
    }

    if (
        !current_user_can('manage_options') ||
        $nonce_value === '' ||
        !wp_verify_nonce($nonce_value, 'octopus_ai_run_regression_suite')
    ) {
        wp_die('Beveiligingsfout bij regressiesuite.');
    }

    $snapshot = octopus_ai_run_regression_suite();
    if (is_wp_error($snapshot)) {
        octopus_ai_settings_admin_redirect(array(
            'regression_error' => $snapshot->get_error_message(),
        ));
    }

    octopus_ai_settings_admin_redirect(array(
        'regression_ran' => 1,
        'regression_cases' => (int) ($snapshot['total_cases'] ?? 0),
        'regression_pass' => (int) ($snapshot['pass_cases'] ?? 0),
        'regression_fail' => (int) ($snapshot['fail_cases'] ?? 0),
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
    $provider_defaults = function_exists('octopus_ai_get_default_provider_profile')
        ? octopus_ai_get_default_provider_profile()
        : array();
    $topic_terms = isset($profile['topic_terms']) && is_array($profile['topic_terms']) ? $profile['topic_terms'] : array();
    if (empty($topic_terms) && isset($provider_defaults['topic_terms']) && is_array($provider_defaults['topic_terms'])) {
        $topic_terms = $provider_defaults['topic_terms'];
    }

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
        wp_schedule_single_event(time() + octopus_ai_get_pdf_queue_delay_seconds('enqueue'), 'octopus_ai_process_pdf_queue');
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
        wp_schedule_single_event(time() + octopus_ai_get_pdf_queue_delay_seconds('resume'), 'octopus_ai_process_pdf_queue');
    }
}

function octopus_ai_process_pdf_queue() {
    $lock_key = 'octopus_ai_pdf_queue_lock';
    if (get_transient($lock_key)) {
        if (!wp_next_scheduled('octopus_ai_process_pdf_queue')) {
            wp_schedule_single_event(time() + octopus_ai_get_pdf_queue_delay_seconds('locked'), 'octopus_ai_process_pdf_queue');
        }
        return;
    }

    set_transient($lock_key, 1, 3 * MINUTE_IN_SECONDS);

    try {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

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
                wp_schedule_single_event(time() + octopus_ai_get_pdf_queue_delay_seconds('missing_dependency'), 'octopus_ai_process_pdf_queue');
            }
            return;
        }

        $upload_dir = wp_upload_dir();
        $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
        if (!file_exists($chunks_dir)) {
            wp_mkdir_p($chunks_dir);
        }

        $batch_size = octopus_ai_get_pdf_queue_batch_size(count($queue));
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
            $slug = octopus_ai_pdf_filename_to_slug($filename);

            $file_url = trailingslashit($upload_dir['baseurl']) . 'octopus-chatbot/' . rawurlencode($filename);

            try {
                $chunker = new Chunker();
                $chunks = $chunker->chunkPdfWithMetadata($file_path, $slug, $file_url);
                if (!is_array($chunks)) {
                    $chunks = array();
                }

                if (empty($chunks)) {
                    $failed_now++;
                    $chunker_reason = '';
                    if (method_exists($chunker, 'getLastError')) {
                        $chunker_reason = trim((string) $chunker->getLastError());
                    }
                    if ($chunker_reason === '') {
                        $chunker_reason = 'Geen bruikbare tekst gevonden (mogelijk gescande PDF zonder OCR-textlaag).';
                    }

                    $last_error_now = 'Geen chunks gemaakt voor PDF: ' . $filename;
                    if ($chunker_reason !== '') {
                        $last_error_now .= ' (' . $chunker_reason . ')';
                    }

                    error_log('[Octopus AI] ' . $last_error_now);
                    continue;
                }

                $cleanup_slugs = array($slug);
                if (preg_match('/^(.*)-([1-9]\d?)$/', $slug, $matches) && !empty($matches[1])) {
                    $cleanup_slugs[] = (string) $matches[1];
                }

                $variant_pattern = '/^(' . preg_quote($slug, '/') . '-([1-9]\d?))_chunk_\d+\.json$/';
                foreach (glob($chunks_dir . $slug . '-*_chunk_*.json') as $variant_chunk_file) {
                    $variant_name = (string) basename((string) $variant_chunk_file);
                    if (preg_match($variant_pattern, $variant_name, $variant_match) && !empty($variant_match[1])) {
                        $cleanup_slugs[] = (string) $variant_match[1];
                    }
                }

                if (preg_match('/^(.*)-([1-9]\d?)$/', $slug, $base_match) && !empty($base_match[1])) {
                    $base_slug = (string) $base_match[1];
                    $base_variant_pattern = '/^(' . preg_quote($base_slug, '/') . '-([1-9]\d?))_chunk_\d+\.json$/';
                    foreach (glob($chunks_dir . $base_slug . '-*_chunk_*.json') as $base_variant_chunk_file) {
                        $variant_name = (string) basename((string) $base_variant_chunk_file);
                        if (preg_match($base_variant_pattern, $variant_name, $variant_match) && !empty($variant_match[1])) {
                            $cleanup_slugs[] = (string) $variant_match[1];
                        }
                    }
                }

                $cleanup_slugs = array_values(array_unique(array_filter($cleanup_slugs)));

                foreach ($cleanup_slugs as $cleanup_slug) {
                    foreach (glob($chunks_dir . $cleanup_slug . '_chunk_*.json') as $old_file) {
                        unlink($old_file);
                    }
                }

                $chunk_index = 0;
                $total_chunk_count = count($chunks);
                $chunk_json_flags = octopus_ai_get_chunk_json_encode_flags();
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
                            'source_type' => $meta['source_type'] ?? 'pdf',
                            'chunk_index' => isset($meta['chunk_index']) ? (int) $meta['chunk_index'] : $chunk_index,
                            'total_chunks' => isset($meta['total_chunks']) ? (int) $meta['total_chunks'] : $total_chunk_count,
                            'index_terms' => isset($meta['index_terms']) && is_array($meta['index_terms'])
                                ? array_values(array_filter(array_map('strval', $meta['index_terms'])))
                                : array(),
                        ),
                    );
                    file_put_contents($chunk_file, wp_json_encode($data, $chunk_json_flags));
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
            wp_schedule_single_event(time() + octopus_ai_get_pdf_queue_delay_seconds('next_batch'), 'octopus_ai_process_pdf_queue');
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
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    if ($filename === '' || $ext !== 'pdf') {
        $filename = 'remote-' . substr(md5($validated_url), 0, 12) . '.pdf';
    }

    $base_name = sanitize_file_name((string) pathinfo($filename, PATHINFO_FILENAME));
    if ($base_name === '') {
        $base_name = 'remote-' . substr(md5($validated_url), 0, 12);
    }
    $filename = $base_name . '.pdf';
    $destination = $upload_path . $filename;
    if (file_exists($destination)) {
        @unlink($destination);
    }

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
        'filename' => $filename,
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
        $error_code = isset($files['error'][$index]) ? (int) $files['error'][$index] : UPLOAD_ERR_NO_FILE;
        if ($error_code === UPLOAD_ERR_OK) {
            $original = sanitize_file_name((string) $name);
            $ext = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                continue;
            }

            $base_name = sanitize_file_name((string) pathinfo($original, PATHINFO_FILENAME));
            if ($base_name === '') {
                $base_name = 'upload-' . substr(md5((string) $name . '|' . (string) $index), 0, 8);
            }

            $filename = $base_name . '.pdf';
            $filepath = $upload_path . $filename;
            if (file_exists($filepath)) {
                @unlink($filepath);
            }

            if (move_uploaded_file($files['tmp_name'][$index], $filepath) || @copy($files['tmp_name'][$index], $filepath)) {
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
        $ext = strtolower((string) pathinfo($safe_file, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $slug = octopus_ai_pdf_filename_to_slug($safe_file);

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
        if ($ext === 'xml' && function_exists('octopus_ai_unregister_sitemap_source')) {
            octopus_ai_unregister_sitemap_source($safe_file);
            $saved_sitemap_url = esc_url_raw((string) get_option('octopus_ai_sitemap_url', ''));
            if ($saved_sitemap_url !== '' && $safe_file === ('remote_' . md5($saved_sitemap_url) . '.xml')) {
                delete_option('octopus_ai_sitemap_url');
            }
        }
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
            $ext = strtolower((string) pathinfo($safe_name, PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $slug = octopus_ai_pdf_filename_to_slug($safe_name);

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
            if ($ext === 'xml' && function_exists('octopus_ai_unregister_sitemap_source')) {
                octopus_ai_unregister_sitemap_source($safe_name);
                $saved_sitemap_url = esc_url_raw((string) get_option('octopus_ai_sitemap_url', ''));
                if ($saved_sitemap_url !== '' && $safe_name === ('remote_' . md5($saved_sitemap_url) . '.xml')) {
                    delete_option('octopus_ai_sitemap_url');
                }
            }
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
    $manual_placeholder_nl = function_exists('octopus_ai_get_provider_manual_base_url')
        ? trim((string) octopus_ai_get_provider_manual_base_url('NL'))
        : '';
    $manual_placeholder_fr = function_exists('octopus_ai_get_provider_manual_base_url')
        ? trim((string) octopus_ai_get_provider_manual_base_url('FR'))
        : '';
    if ($manual_placeholder_nl === '' || $manual_placeholder_fr === '') {
        $provider_defaults = function_exists('octopus_ai_get_default_provider_profile')
            ? octopus_ai_get_default_provider_profile()
            : array();
        $default_manual = isset($provider_defaults['manual']) && is_array($provider_defaults['manual'])
            ? $provider_defaults['manual']
            : array();
        if ($manual_placeholder_nl === '') {
            $manual_placeholder_nl = trim((string) ($default_manual['base_url_nl'] ?? ''));
        }
        if ($manual_placeholder_fr === '') {
            $manual_placeholder_fr = trim((string) ($default_manual['base_url_fr'] ?? ''));
        }
    }
    if ($manual_placeholder_nl === '') {
        $manual_placeholder_nl = 'https://example.com/manual/nl/';
    }
    if ($manual_placeholder_fr === '') {
        $manual_placeholder_fr = 'https://example.com/manual/fr/';
    }
    $manual_priority_placeholder_nl = trailingslashit($manual_placeholder_nl) . 'voorbeeld.htm' . "\n" . trailingslashit($manual_placeholder_nl) . 'andere-pagina.htm';
    $manual_priority_placeholder_fr = trailingslashit($manual_placeholder_fr) . 'exemple.htm';
    $confidence_threshold = get_option('octopus_ai_confidence_threshold', 55);
    $handoff_url_nl = get_option('octopus_ai_handoff_url_nl', '');
    $handoff_url_fr = get_option('octopus_ai_handoff_url_fr', '');
    $welcome_message_nl = get_option('octopus_ai_welcome_message_nl', '');
    $welcome_message_fr = get_option('octopus_ai_welcome_message_fr', '');
    $saved_sitemap_url = get_option('octopus_ai_sitemap_url', '');
    $sitemap_sources = function_exists('octopus_ai_get_sitemap_sources') ? octopus_ai_get_sitemap_sources() : array();
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
    $pdf_files = array();
    if (file_exists($upload_path)) {
        $pdf_files = array_merge(
            glob($upload_path . '*.pdf') ?: array(),
            glob($upload_path . '*.PDF') ?: array()
        );
    }
    $xml_files = file_exists($upload_path) ? glob($upload_path . '*.xml') : array();
    $pdf_chunk_files = file_exists($chunk_dir_status) ? glob($chunk_dir_status . '*_chunk_*.json') : array();
    $sitemap_chunk_files = file_exists($chunk_dir_status) ? glob($chunk_dir_status . 'sitemap_*.json') : array();

    $pdf_files = is_array($pdf_files) ? $pdf_files : array();
    $xml_files = is_array($xml_files) ? $xml_files : array();
    $pdf_chunk_files = is_array($pdf_chunk_files) ? $pdf_chunk_files : array();
    $sitemap_chunk_files = is_array($sitemap_chunk_files) ? $sitemap_chunk_files : array();
    $pdf_chunk_files = array_values(array_filter($pdf_chunk_files, static function ($file) {
        return strpos((string) basename((string) $file), 'sitemap_') !== 0;
    }));
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
        $pdf_file_slugs[] = octopus_ai_pdf_filename_to_slug((string) basename((string) $pdf_file));
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
    $regression_snapshot = octopus_ai_get_regression_snapshot();
    $fallback_topic_labels = array();
    if (function_exists('octopus_ai_get_provider_topic_labels_map')) {
        $topic_labels_map = octopus_ai_get_provider_topic_labels_map();
        if (is_array($topic_labels_map)) {
            foreach ($topic_labels_map as $topic_key => $label_pair) {
                $topic_key = sanitize_key((string) $topic_key);
                if ($topic_key === '' || !is_array($label_pair)) {
                    continue;
                }

                $label_nl = sanitize_text_field((string) ($label_pair['nl'] ?? ''));
                $fallback_topic_labels[$topic_key] = $label_nl !== ''
                    ? $label_nl
                    : ucfirst(str_replace('_', ' ', $topic_key));
            }
        }
    }
    $fallback_topic_labels['unknown'] = 'Overig/onbekend';

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
        array(
            'title' => 'Regressiescore',
            'value' => (string) ($regression_snapshot['pass_rate'] ?? 0.0) . '%',
            'detail' => 'Laatste run: ' . (int) ($regression_snapshot['total_cases'] ?? 0) . ' cases, leeftijd ' . (float) ($regression_snapshot['age_hours'] ?? 0.0) . ' uur.',
        ),
    );

    $quality_gate_thresholds = octopus_ai_get_quality_gate_thresholds();
    $quality_gate_enabled = !empty($quality_gate_thresholds['enabled']) ? 1 : 0;

    $quality_gate_metrics = array(
        'source_strategy' => sanitize_key((string) $source_strategy),
        'source_ready' => !empty($source_health[$source_strategy]['ready']),
        'pdf_coverage_pct' => (float) $pdf_coverage_pct,
        'pdf_file_count' => count($pdf_file_slugs),
        'pdf_chunk_count' => count($pdf_chunk_files),
        'sitemap_file_count' => count($xml_files),
        'sitemap_chunk_count' => count($sitemap_chunk_files),
        'stale_chunk_count' => (int) $stale_chunk_count,
        'stale_threshold_days' => (int) $stale_threshold_days,
        'fallback_ratio' => (float) ($fallback_metrics['overall_ratio'] ?? 0.0),
        'fallback_sample_size' => (int) ($fallback_metrics['sample_size'] ?? 0),
        'regression_pass_rate' => (float) ($regression_snapshot['pass_rate'] ?? 0.0),
        'regression_total_cases' => (int) ($regression_snapshot['total_cases'] ?? 0),
        'regression_fail_cases' => (int) ($regression_snapshot['fail_cases'] ?? 0),
        'regression_age_hours' => (float) ($regression_snapshot['age_hours'] ?? 99999.0),
        'regression_run_at' => (string) ($regression_snapshot['run_at'] ?? ''),
    );
    $quality_gate_report = octopus_ai_build_quality_gate_report($quality_gate_metrics);
    $quality_gate_checks = isset($quality_gate_report['checks']) && is_array($quality_gate_report['checks'])
        ? $quality_gate_report['checks']
        : array();
    $quality_gate_failed = isset($quality_gate_report['failed_labels']) && is_array($quality_gate_report['failed_labels'])
        ? $quality_gate_report['failed_labels']
        : array();

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

        <?php if (isset($_GET['sitemap_refreshed']) && intval($_GET['sitemap_refreshed']) === 1) : ?>
            <?php $refreshed_file = isset($_GET['sitemap_file']) ? sanitize_file_name((string) wp_unslash($_GET['sitemap_file'])) : ''; ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    Sitemap bijgewerkt<?php echo $refreshed_file !== '' ? ': <code>' . esc_html($refreshed_file) . '</code>' : ''; ?>.
                    Nieuwe of gewijzigde pagina's staan in de achtergrondwachtrij.
                </p>
            </div>
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
            <?php
            $config_updated = intval($_GET['config_updated'] ?? 0);
            $config_processed = intval($_GET['config_processed'] ?? 0);
            $config_unchanged = intval($_GET['config_unchanged'] ?? 0);
            $config_skipped_sensitive = intval($_GET['config_skipped_sensitive'] ?? 0);
            $config_skipped_unknown = intval($_GET['config_skipped_unknown'] ?? 0);
            $config_format = sanitize_key((string) ($_GET['config_format'] ?? ''));
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    Configuratie geimporteerd.
                    Bijgewerkt: <?php echo $config_updated; ?>,
                    verwerkt: <?php echo $config_processed; ?>,
                    ongewijzigd: <?php echo $config_unchanged; ?>.
                    <?php if ($config_skipped_sensitive > 0) : ?>
                        Gevoelige opties overgeslagen: <?php echo $config_skipped_sensitive; ?>.
                    <?php endif; ?>
                    <?php if ($config_skipped_unknown > 0) : ?>
                        Onbekende sleutels overgeslagen: <?php echo $config_skipped_unknown; ?>.
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($config_format === 'legacy') : ?>
                <div class="notice notice-warning is-dismissible">
                    <p>Legacy configuratieformaat geimporteerd. Exporteer opnieuw in het nieuwe formaat voor toekomstige deployments.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($_GET['config_import_error']) && $_GET['config_import_error'] !== '') : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(sanitize_text_field((string) wp_unslash($_GET['config_import_error']))); ?></p></div>
        <?php endif; ?>

        <?php if (isset($_GET['config_export_error']) && $_GET['config_export_error'] !== '') : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(sanitize_text_field((string) wp_unslash($_GET['config_export_error']))); ?></p></div>
        <?php endif; ?>

        <?php if (isset($_GET['regression_ran']) && intval($_GET['regression_ran']) === 1) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    Regressiesuite uitgevoerd.
                    Cases: <?php echo intval($_GET['regression_cases'] ?? 0); ?>,
                    PASS: <?php echo intval($_GET['regression_pass'] ?? 0); ?>,
                    FAIL: <?php echo intval($_GET['regression_fail'] ?? 0); ?>.
                </p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['regression_error']) && $_GET['regression_error'] !== '') : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(sanitize_text_field((string) wp_unslash($_GET['regression_error']))); ?></p></div>
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
                    'description' => 'Zoek of bewaar een sitemap-URL en laat Octopus inhoud + menu-items (ook dropdowns) crawlen.',
                    'badge'       => 'Automatisch',
                    'cta'         => 'Gebruik uitsluitend de gevonden sitemapbron.',
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

            <h3 class="octopus-quality-title">Quality gate</h3>
            <div class="source-health-grid source-health-secondary octopus-quality-grid">
                <div class="source-health-card <?php echo (!empty($quality_gate_report['enabled']) && empty($quality_gate_report['pass'])) ? 'is-fail' : 'is-ready'; ?>">
                    <div class="source-health-header">
                        <span class="source-health-dot" aria-hidden="true"></span>
                        <span>Deployment gate</span>
                        <small>
                            <?php
                            if (empty($quality_gate_report['enabled'])) {
                                echo 'UIT';
                            } elseif (!empty($quality_gate_report['pass'])) {
                                echo 'PASS';
                            } else {
                                echo 'FAIL';
                            }
                            ?>
                        </small>
                    </div>
                    <p class="source-health-meta">
                        <?php if (empty($quality_gate_report['enabled'])) : ?>
                            Quality gate is uitgeschakeld. Exports worden niet geblokkeerd.
                        <?php elseif (!empty($quality_gate_report['pass'])) : ?>
                            Alle vereiste kwaliteitschecks zijn gehaald.
                        <?php else : ?>
                            Export wordt geblokkeerd tot de failing checks opgelost zijn.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if (!empty($quality_gate_checks)) : ?>
                <table class="widefat striped octopus-quality-table">
                    <thead>
                        <tr>
                            <th>Check</th>
                            <th>Status</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quality_gate_checks as $check) : ?>
                            <?php
                            $status = sanitize_key((string) ($check['status'] ?? 'skip'));
                            if ($status === 'pass') {
                                $status_label = 'PASS';
                            } elseif ($status === 'fail') {
                                $status_label = 'FAIL';
                            } else {
                                $status_label = 'SKIP';
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) ($check['label'] ?? 'Check')); ?></td>
                                <td><?php echo esc_html($status_label); ?></td>
                                <td><?php echo esc_html((string) ($check['detail'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($quality_gate_report['enabled']) && !empty($quality_gate_failed)) : ?>
                <div class="octopus-quality-alerts">
                    <div class="octopus-quality-alert octopus-quality-alert-critical">
                        Gate faalt op: <?php echo esc_html(implode(', ', array_map('sanitize_text_field', $quality_gate_failed))); ?>.
                    </div>
                </div>
            <?php endif; ?>

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

            <h2 style="margin-top:35px;">Kwaliteitsgate (deployment)</h2>
            <table class="form-table">
                <tr>
                    <th>Gate actief</th>
                    <td>
                        <input type="hidden" name="octopus_ai_quality_gate_enabled" value="0">
                        <label>
                            <input type="checkbox" name="octopus_ai_quality_gate_enabled" value="1" <?php checked($quality_gate_enabled, 1); ?>>
                            Blokkeer configuratie-export als kwaliteitsdrempels niet gehaald worden
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Min PDF dekking (%)</th>
                    <td>
                        <input type="number" name="octopus_ai_quality_gate_min_pdf_coverage" min="0" max="100" step="1" value="<?php echo esc_attr((string) ($quality_gate_thresholds['min_pdf_coverage'] ?? 70)); ?>" style="width: 120px;">
                        <p class="description">Enkel van toepassing in manuele upload-modus wanneer er PDF-bestanden zijn.</p>
                    </td>
                </tr>
                <tr>
                    <th>Max fallback ratio (%)</th>
                    <td>
                        <input type="number" name="octopus_ai_quality_gate_max_fallback_ratio" min="0" max="100" step="1" value="<?php echo esc_attr((string) ($quality_gate_thresholds['max_fallback_ratio'] ?? 35)); ?>" style="width: 120px;">
                        <p class="description">Pas gevalideerd wanneer minstens genoeg samplegesprekken beschikbaar zijn.</p>
                    </td>
                </tr>
                <tr>
                    <th>Max stale chunks</th>
                    <td>
                        <input type="number" name="octopus_ai_quality_gate_max_stale_chunks" min="0" max="200000" step="1" value="<?php echo esc_attr((string) ($quality_gate_thresholds['max_stale_chunks'] ?? 250)); ?>" style="width: 120px;">
                        <p class="description">Chunks ouder dan 45 dagen tellen als stale.</p>
                    </td>
                </tr>
                <tr>
                    <th>Min sample fallback</th>
                    <td>
                        <input type="number" name="octopus_ai_quality_gate_min_sample_size" min="0" max="10000" step="1" value="<?php echo esc_attr((string) ($quality_gate_thresholds['min_sample_size'] ?? 50)); ?>" style="width: 120px;">
                        <p class="description">Minimum aantal gesprekken voor fallback-ratio validatie.</p>
                    </td>
                </tr>
                <tr>
                    <th>Min regressie PASS (%)</th>
                    <td>
                        <input type="number" name="octopus_ai_quality_gate_min_regression_pass_rate" min="0" max="100" step="1" value="<?php echo esc_attr((string) ($quality_gate_thresholds['min_regression_pass_rate'] ?? 75)); ?>" style="width: 120px;">
                        <p class="description">Minimale PASS-score van de automatische regressiesuite.</p>
                    </td>
                </tr>
                <tr>
                    <th>Min regressie cases</th>
                    <td>
                        <input type="number" name="octopus_ai_quality_gate_min_regression_cases" min="0" max="500" step="1" value="<?php echo esc_attr((string) ($quality_gate_thresholds['min_regression_cases'] ?? 6)); ?>" style="width: 120px;">
                        <p class="description">Minimum aantal cases in de laatste regressierun (0 = check uit).</p>
                    </td>
                </tr>
                <tr>
                    <th>Max leeftijd regressie (uur)</th>
                    <td>
                        <input type="number" name="octopus_ai_quality_gate_max_regression_age_hours" min="0" max="8760" step="1" value="<?php echo esc_attr((string) ($quality_gate_thresholds['max_regression_age_hours'] ?? 168)); ?>" style="width: 120px;">
                        <p class="description">Hoe oud de laatste regressierun maximaal mag zijn (0 = ouderdom niet controleren).</p>
                    </td>
                </tr>
            </table>
            <p style="margin:8px 0 14px;">
                <strong>Laatste regressierun:</strong>
                <?php if (!empty($regression_snapshot['run_at'])) : ?>
                    <?php echo esc_html((string) $regression_snapshot['run_at']); ?> |
                    PASS <?php echo esc_html((string) ($regression_snapshot['pass_rate'] ?? 0.0)); ?>% |
                    <?php echo esc_html((string) ($regression_snapshot['total_cases'] ?? 0)); ?> cases
                <?php else : ?>
                    Nog niet uitgevoerd.
                <?php endif; ?>
            </p>
            <?php
            $regression_run_url = wp_nonce_url(
                admin_url('admin-post.php?action=octopus_ai_run_regression_suite'),
                'octopus_ai_run_regression_suite',
                'octopus_ai_regression_nonce'
            );
            ?>
            <p style="margin:0 0 10px;">
                <a class="button button-secondary" href="<?php echo esc_url($regression_run_url); ?>">Draai regressiesuite nu</a>
            </p>

            <h2 style="margin-top:35px;">Handleiding en voorkeuren</h2>
            <table class="form-table">
                <tr>
                    <th>Basis URL NL</th>
                    <td>
                        <input type="url" name="octopus_ai_manual_base_url_nl" value="<?php echo esc_attr($manual_base_nl); ?>" placeholder="<?php echo esc_attr($manual_placeholder_nl); ?>" />
                        <p class="description">Optioneel: wijzig de basis van de Nederlandstalige handleiding indien je een andere omgeving gebruikt. Laat leeg voor de standaard Octopus URL.</p>
                    </td>
                </tr>
                <tr>
                    <th>Basis URL FR</th>
                    <td>
                        <input type="url" name="octopus_ai_manual_base_url_fr" value="<?php echo esc_attr($manual_base_fr); ?>" placeholder="<?php echo esc_attr($manual_placeholder_fr); ?>" />
                        <p class="description">Optioneel: wijzig de basis van de Franstalige handleiding indien nodig.</p>
                    </td>
                </tr>
                <tr>
                    <th>Voorkeurspagina's NL</th>
                    <td>
                        <textarea name="octopus_ai_manual_priority_urls_nl" rows="3" placeholder="<?php echo esc_attr($manual_priority_placeholder_nl); ?>"><?php echo esc_textarea($manual_priority_nl); ?></textarea>
                        <p class="description">Geef één of meerdere URL's op (één per lijn) die eerst live opgehaald mogen worden wanneer de chatbot de handleiding raadpleegt.</p>
                    </td>
                </tr>
                <tr>
                    <th>Voorkeurspagina's FR</th>
                    <td>
                        <textarea name="octopus_ai_manual_priority_urls_fr" rows="3" placeholder="<?php echo esc_attr($manual_priority_placeholder_fr); ?>"><?php echo esc_textarea($manual_priority_fr); ?></textarea>
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
                <tr>
                    <th>Confidence drempel (%)</th>
                    <td>
                        <input type="number" name="octopus_ai_confidence_threshold" min="0" max="100" step="1" value="<?php echo esc_attr($confidence_threshold); ?>" style="width: 120px;" />
                        <p class="description">Bij lagere confidence geeft de chatbot geen gokantwoord, maar een veilige fallback met handleidinglinks.</p>
                    </td>
                </tr>
                <tr>
                    <th>Support link NL</th>
                    <td>
                        <input type="url" name="octopus_ai_handoff_url_nl" value="<?php echo esc_attr($handoff_url_nl); ?>" placeholder="https://..." style="width: 400px;" />
                        <p class="description">Optioneel: wordt getoond wanneer de chatbot geen betrouwbare oplossing heeft (Nederlands).</p>
                    </td>
                </tr>
                <tr>
                    <th>Support link FR</th>
                    <td>
                        <input type="url" name="octopus_ai_handoff_url_fr" value="<?php echo esc_attr($handoff_url_fr); ?>" placeholder="https://..." style="width: 400px;" />
                        <p class="description">Optioneel: wordt getoond wanneer de chatbot geen betrouwbare oplossing heeft (Frans).</p>
                    </td>
                </tr>
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
            <p class="section-description">Deployment-flow zonder nieuwe database: exporteer een herbruikbaar JSON-profiel, of importeer een bestaand profiel. Standaard gebeurt export zonder API key. Als de quality gate actief is, wordt export geblokkeerd bij FAIL.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field('octopus_ai_export_config', 'octopus_ai_export_nonce'); ?>
                <input type="hidden" name="action" value="octopus_ai_export_config">
                <input type="hidden" name="octopus_ai_export_include_sensitive" value="0">
                <?php submit_button('Exporteer deployment-config (zonder API key)', 'secondary', '', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field('octopus_ai_export_config', 'octopus_ai_export_nonce'); ?>
                <input type="hidden" name="action" value="octopus_ai_export_config">
                <input type="hidden" name="octopus_ai_export_include_sensitive" value="1">
                <?php submit_button('Exporteer volledige backup (met API key)', 'secondary', '', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('octopus_ai_import_config', 'octopus_ai_import_nonce'); ?>
                <input type="hidden" name="action" value="octopus_ai_import_config">
                <input type="file" name="octopus_ai_config_file" accept="application/json,.json" required>
                <p style="margin:8px 0 12px;">
                    <label>
                        <input type="checkbox" name="octopus_ai_import_api_key" value="1">
                        API key mee importeren indien aanwezig in het bestand
                    </label>
                </p>
                <?php submit_button('Importeer configuratie', 'secondary', '', false); ?>
            </form>
            <p class="description" style="margin-top:8px;">Formaat: versie 2 met checksum-validatie. Oudere exports blijven compatibel.</p>
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
                    <p class="section-description">Geef een sitemap-URL of webpagina-URL op. De plugin zoekt de sitemap, slaat die lokaal op en zet URL's in een achtergrondwachtrij voor chunking. Tijdens verwerking worden ook navigatie- en dropdown-termen op die pagina's mee geindexeerd.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('octopus_ai_import_sitemap_url', 'octopus_ai_sitemap_url_nonce'); ?>
                        <input type="hidden" name="action" value="octopus_ai_import_sitemap_url">
                        <input type="url" name="octopus_ai_sitemap_url_input" value="<?php echo esc_attr($saved_sitemap_url); ?>" style="width:500px;" placeholder="https://example.com/sitemap.xml of https://example.com/manual/" required />
                        <?php submit_button('Importeer en verwerk sitemap'); ?>
                    </form>
                    <?php if ($saved_sitemap_url !== '') : ?>
                        <p class="description">Actieve sitemapbron: <code><?php echo esc_html($saved_sitemap_url); ?></code></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                            <?php wp_nonce_field('octopus_ai_refresh_sitemap', 'octopus_ai_refresh_sitemap_nonce'); ?>
                            <input type="hidden" name="action" value="octopus_ai_refresh_sitemap">
                            <input type="hidden" name="sitemap_file" value="<?php echo esc_attr('remote_' . md5($saved_sitemap_url) . '.xml'); ?>">
                            <?php submit_button('Werk actieve sitemap nu bij', 'secondary', '', false); ?>
                        </form>
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
                                $filename = basename($file);
                                $source_entry = isset($sitemap_sources[$filename]) && is_array($sitemap_sources[$filename]) ? $sitemap_sources[$filename] : array();
                                $source_url = esc_url_raw((string) ($source_entry['source_url'] ?? ''));
                                if ($source_url === '' && function_exists('octopus_ai_resolve_sitemap_source_url')) {
                                    $source_url = octopus_ai_resolve_sitemap_source_url($filename, $sitemap_sources);
                                }
                                $updated_at = sanitize_text_field((string) ($source_entry['updated_at'] ?? ''));
                                $refresh_url = wp_nonce_url(
                                    admin_url('admin-post.php?action=octopus_ai_refresh_sitemap&sitemap_file=' . rawurlencode($filename)),
                                    'octopus_ai_refresh_sitemap'
                                );
                                ?>
                                <li>
                                    <label>
                                        <input type="checkbox" name="sitemap_files[]" value="<?php echo esc_attr($filename); ?>">
                                        <a href="<?php echo esc_url($sitemap_url_base . $filename); ?>" target="_blank"><?php echo esc_html($filename); ?></a>
                                    </label>
                                    <div class="description" style="margin:6px 0 0 22px;">
                                        <?php if ($source_url !== '') : ?>
                                            <span>Bron-URL: <code><?php echo esc_html($source_url); ?></code></span>
                                            <?php if ($updated_at !== '') : ?>
                                                <br><span>Laatst gesynchroniseerd: <?php echo esc_html($updated_at); ?></span>
                                            <?php endif; ?>
                                            <p style="margin:8px 0 0;">
                                                <a href="<?php echo esc_url($refresh_url); ?>" class="button button-secondary">Werk deze sitemap bij</a>
                                            </p>
                                        <?php else : ?>
                                            <span>Geen bron-URL gekend. Importeer deze sitemap opnieuw via URL om automatische updates te activeren.</span>
                                        <?php endif; ?>
                                    </div>
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
