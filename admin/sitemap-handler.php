<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_post_octopus_ai_upload_sitemap', 'octopus_ai_handle_sitemap_upload');
add_action('admin_post_octopus_ai_auto_fetch_sitemap', 'octopus_ai_auto_fetch_sitemap');
add_action('admin_post_octopus_ai_import_sitemap_url', 'octopus_ai_import_sitemap_url');
add_action('admin_post_octopus_ai_refresh_sitemap', 'octopus_ai_refresh_sitemap');
add_action('octopus_ai_process_sitemap_queue', 'octopus_ai_process_sitemap_queue');
add_action('admin_init', 'octopus_ai_maybe_schedule_sitemap_queue');

function octopus_ai_get_sitemap_queue() {
    $queue = get_option('octopus_ai_sitemap_queue', []);
    if (!is_array($queue)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map('esc_url_raw', $queue))));
}

function octopus_ai_set_sitemap_queue(array $queue) {
    update_option('octopus_ai_sitemap_queue', array_values($queue), false);
}

if (!function_exists('octopus_ai_get_sitemap_queue_batch_size')) {
    function octopus_ai_get_sitemap_queue_batch_size($queue_size = 0) {
        $mb = defined('MB_IN_BYTES') ? (int) MB_IN_BYTES : (1024 * 1024);
        $memory_limit = function_exists('octopus_ai_get_memory_limit_bytes')
            ? (int) octopus_ai_get_memory_limit_bytes()
            : 0;

        $batch = 20;
        if ($memory_limit > 0 && $memory_limit < 256 * $mb) {
            $batch = 10;
        } elseif ($memory_limit >= 768 * $mb) {
            $batch = 40;
        } elseif ($memory_limit >= 512 * $mb) {
            $batch = 30;
        }

        $queue_size = max(0, (int) $queue_size);
        if ($queue_size >= 120) {
            $batch += 10;
        } elseif ($queue_size >= 50) {
            $batch += 5;
        }

        $batch = (int) apply_filters('octopus_ai_sitemap_queue_batch_size', $batch, $queue_size, $memory_limit);
        return max(5, min(80, $batch));
    }
}

if (!function_exists('octopus_ai_get_sitemap_queue_delay_seconds')) {
    function octopus_ai_get_sitemap_queue_delay_seconds($context = 'default') {
        $context = sanitize_key((string) $context);
        $delay_map = [
            'enqueue' => 2,
            'resume' => 2,
            'locked' => 8,
            'missing_dependency' => 25,
            'next_batch' => 3,
        ];
        $delay = isset($delay_map[$context]) ? (int) $delay_map[$context] : 5;
        $delay = (int) apply_filters('octopus_ai_sitemap_queue_delay_seconds', $delay, $context);
        return max(1, $delay);
    }
}

function octopus_ai_enqueue_sitemap_urls(array $urls) {
    $urls = array_values(array_unique(array_filter(array_map('esc_url_raw', $urls))));
    if (empty($urls)) {
        return 0;
    }

    $queue = octopus_ai_get_sitemap_queue();
    $combined = array_values(array_unique(array_merge($queue, $urls)));
    octopus_ai_set_sitemap_queue($combined);

    $status = get_option('octopus_ai_sitemap_queue_status', []);
    if (!is_array($status)) {
        $status = [];
    }
    $processed = isset($status['processed']) ? max(0, (int) $status['processed']) : 0;

    update_option('octopus_ai_sitemap_queue_status', [
        'queued_total' => $processed + count($combined),
        'remaining' => count($combined),
        'processed' => $processed,
        'last_run' => current_time('mysql'),
    ], false);

    if (!wp_next_scheduled('octopus_ai_process_sitemap_queue')) {
        wp_schedule_single_event(time() + octopus_ai_get_sitemap_queue_delay_seconds('enqueue'), 'octopus_ai_process_sitemap_queue');
    }

    return count($combined);
}

function octopus_ai_maybe_schedule_sitemap_queue() {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    $queue = octopus_ai_get_sitemap_queue();
    if (empty($queue)) {
        return;
    }

    if (!wp_next_scheduled('octopus_ai_process_sitemap_queue')) {
        wp_schedule_single_event(time() + octopus_ai_get_sitemap_queue_delay_seconds('resume'), 'octopus_ai_process_sitemap_queue');
    }
}

function octopus_ai_process_sitemap_queue() {
    $lock_key = 'octopus_ai_sitemap_queue_lock';
    if (get_transient($lock_key)) {
        if (!wp_next_scheduled('octopus_ai_process_sitemap_queue')) {
            wp_schedule_single_event(time() + octopus_ai_get_sitemap_queue_delay_seconds('locked'), 'octopus_ai_process_sitemap_queue');
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

        $queue = octopus_ai_get_sitemap_queue();
        if (empty($queue)) {
            delete_option('octopus_ai_sitemap_queue_status');
            return;
        }

        $batch_size = octopus_ai_get_sitemap_queue_batch_size(count($queue));
        $batch = array_slice($queue, 0, $batch_size);
        $remaining = array_slice($queue, $batch_size);
        octopus_ai_set_sitemap_queue($remaining);

        if (!class_exists(\OctopusAI\Includes\SitemapParser::class)) {
            error_log('[Octopus AI] SitemapParser class ontbreekt, sitemap queue kan niet verwerkt worden.');
            if (!wp_next_scheduled('octopus_ai_process_sitemap_queue')) {
                wp_schedule_single_event(time() + octopus_ai_get_sitemap_queue_delay_seconds('missing_dependency'), 'octopus_ai_process_sitemap_queue');
            }
            return;
        }

        $parser = new \OctopusAI\Includes\SitemapParser();
        $processed_now = (int) $parser->fetchAndSaveHtmlFromUrls(0, $batch);

        $status = get_option('octopus_ai_sitemap_queue_status', []);
        if (!is_array($status)) {
            $status = [];
        }

        $status['processed'] = isset($status['processed']) ? (int) $status['processed'] + $processed_now : $processed_now;
        $status['remaining'] = count($remaining);
        $status['queued_total'] = isset($status['queued_total'])
            ? max((int) $status['queued_total'], $status['processed'] + $status['remaining'])
            : ($status['processed'] + $status['remaining']);
        $status['last_run'] = current_time('mysql');
        update_option('octopus_ai_sitemap_queue_status', $status, false);

        if (!empty($remaining) && !wp_next_scheduled('octopus_ai_process_sitemap_queue')) {
            wp_schedule_single_event(time() + octopus_ai_get_sitemap_queue_delay_seconds('next_batch'), 'octopus_ai_process_sitemap_queue');
        }
    } finally {
        delete_transient($lock_key);
    }
}

function octopus_ai_sitemap_admin_redirect(array $params = []) {
    $base = admin_url('admin.php?page=octopus-ai-chatbot');
    wp_safe_redirect(add_query_arg($params, $base));
    exit;
}

function octopus_ai_is_direct_sitemap_url($url) {
    $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
    if ($path === '') {
        return false;
    }

    return substr($path, -4) === '.xml' || strpos($path, 'sitemap') !== false;
}

function octopus_ai_get_site_root_url($url) {
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    $scheme = !empty($parts['scheme']) ? $parts['scheme'] : 'https';
    $port = !empty($parts['port']) ? ':' . (int) $parts['port'] : '';

    return trailingslashit($scheme . '://' . $parts['host'] . $port);
}

function octopus_ai_get_sitemap_sources() {
    $raw_sources = get_option('octopus_ai_sitemap_sources', []);
    if (!is_array($raw_sources)) {
        return [];
    }

    $normalized = [];
    foreach ($raw_sources as $key => $entry) {
        $filename = basename((string) $key);
        if (is_int($key) && is_array($entry) && !empty($entry['filename'])) {
            $filename = basename((string) $entry['filename']);
        }
        if ($filename === '' || !preg_match('/\.xml$/i', $filename)) {
            continue;
        }

        $source_url = '';
        $input_url = '';
        $updated_at = '';
        $last_urls_count = null;

        if (is_array($entry)) {
            $source_url = esc_url_raw((string) ($entry['source_url'] ?? ''));
            $input_url = esc_url_raw((string) ($entry['input_url'] ?? ''));
            $updated_at = sanitize_text_field((string) ($entry['updated_at'] ?? ''));
            if (isset($entry['last_urls_count'])) {
                $last_urls_count = max(0, (int) $entry['last_urls_count']);
            }
        } elseif (is_string($entry)) {
            $source_url = esc_url_raw($entry);
        }

        $normalized[$filename] = [
            'source_url' => $source_url,
            'input_url' => $input_url,
            'updated_at' => $updated_at,
            'last_urls_count' => $last_urls_count,
        ];
    }

    return $normalized;
}

function octopus_ai_set_sitemap_sources(array $sources) {
    update_option('octopus_ai_sitemap_sources', $sources, false);
}

function octopus_ai_register_sitemap_source($filename, $source_url, array $meta = []) {
    $filename = basename((string) $filename);
    if ($filename === '' || !preg_match('/\.xml$/i', $filename)) {
        return;
    }

    $source_url = esc_url_raw((string) $source_url);
    $input_url = esc_url_raw((string) ($meta['input_url'] ?? ''));
    $last_urls_count = isset($meta['last_urls_count']) ? max(0, (int) $meta['last_urls_count']) : null;

    $sources = octopus_ai_get_sitemap_sources();
    $sources[$filename] = [
        'source_url' => $source_url,
        'input_url' => $input_url,
        'updated_at' => current_time('mysql'),
        'last_urls_count' => $last_urls_count,
    ];
    octopus_ai_set_sitemap_sources($sources);
}

function octopus_ai_unregister_sitemap_source($filename) {
    $filename = basename((string) $filename);
    if ($filename === '') {
        return;
    }

    $sources = octopus_ai_get_sitemap_sources();
    if (!isset($sources[$filename])) {
        return;
    }

    unset($sources[$filename]);
    octopus_ai_set_sitemap_sources($sources);
}

function octopus_ai_resolve_sitemap_source_url($filename, array $sources = []) {
    $filename = basename((string) $filename);
    if ($filename === '' || !preg_match('/\.xml$/i', $filename)) {
        return '';
    }

    if (empty($sources)) {
        $sources = octopus_ai_get_sitemap_sources();
    }

    if (isset($sources[$filename])) {
        $entry = is_array($sources[$filename]) ? $sources[$filename] : [];
        $source_url = esc_url_raw((string) ($entry['source_url'] ?? ''));
        if ($source_url !== '') {
            return $source_url;
        }
    }

    if (preg_match('/^remote_([a-f0-9]{32})\.xml$/i', $filename, $matches)) {
        $saved_sitemap_url = esc_url_raw((string) get_option('octopus_ai_sitemap_url', ''));
        if ($saved_sitemap_url !== '' && md5($saved_sitemap_url) === strtolower((string) $matches[1])) {
            return $saved_sitemap_url;
        }
    }

    return '';
}

function octopus_ai_import_sitemap_from_input_url($input_url, array $args = []) {
    $args = wp_parse_args($args, [
        'force_filename' => '',
        'save_active' => true,
        'register_source' => true,
    ]);

    $input_url = esc_url_raw(trim((string) $input_url));
    if ($input_url === '') {
        return new WP_Error('octopus_ai_sitemap_invalid_url', 'Geef een geldige URL op naar een sitemap of website.');
    }

    if (function_exists('wp_http_validate_url') && !wp_http_validate_url($input_url)) {
        return new WP_Error('octopus_ai_sitemap_invalid_url', 'De opgegeven URL is niet geldig.');
    }

    $sitemap_url = '';
    if (octopus_ai_is_direct_sitemap_url($input_url)) {
        $sitemap_url = $input_url;
    } else {
        $site_candidates = [
            trailingslashit($input_url),
            octopus_ai_get_site_root_url($input_url),
        ];
        $site_candidates = array_values(array_unique(array_filter($site_candidates)));

        foreach ($site_candidates as $candidate) {
            $sitemap_url = octopus_ai_find_sitemap_url($candidate);
            if ($sitemap_url !== '') {
                break;
            }
        }
    }

    if ($sitemap_url === '') {
        return new WP_Error('octopus_ai_sitemap_not_found', 'Geen sitemap gevonden voor de opgegeven URL.');
    }

    $response = wp_remote_get($sitemap_url, [
        'timeout'     => 20,
        'redirection' => 5,
    ]);
    if (is_wp_error($response)) {
        return new WP_Error('octopus_ai_sitemap_fetch_failed', 'Sitemap kon niet worden opgehaald. Controleer de URL en probeer opnieuw.');
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    if ($status_code < 200 || $status_code >= 300) {
        return new WP_Error('octopus_ai_sitemap_fetch_failed', 'Sitemap kon niet worden opgehaald (HTTP ' . $status_code . ').');
    }

    $content = trim((string) wp_remote_retrieve_body($response));
    if ($content === '') {
        return new WP_Error('octopus_ai_sitemap_empty', 'De sitemap is leeg.');
    }

    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    if (!file_exists($upload_path)) {
        wp_mkdir_p($upload_path);
    }

    $forced_filename = basename((string) ($args['force_filename'] ?? ''));
    if ($forced_filename !== '' && preg_match('/\.xml$/i', $forced_filename)) {
        $filename = $forced_filename;
    } else {
        $filename = 'remote_' . md5($sitemap_url) . '.xml';
    }

    $destination = $upload_path . $filename;
    if (file_put_contents($destination, $content) === false) {
        return new WP_Error('octopus_ai_sitemap_store_failed', 'De sitemap kon niet lokaal worden opgeslagen.');
    }

    $urls = array_unique(octopus_ai_parse_sitemap($destination));
    if (empty($urls)) {
        return new WP_Error('octopus_ai_sitemap_no_urls', 'Geen URL\'s gevonden in de sitemap.');
    }

    $queued = octopus_ai_enqueue_sitemap_urls($urls);

    if (!empty($args['save_active'])) {
        update_option('octopus_ai_sitemap_url', $sitemap_url);
    }

    if (!empty($args['register_source'])) {
        octopus_ai_register_sitemap_source($filename, $sitemap_url, [
            'input_url' => $input_url,
            'last_urls_count' => count($urls),
        ]);
    }

    return [
        'sitemap_url' => $sitemap_url,
        'urls_count'  => count($urls),
        'pages_count' => 0,
        'queued_count' => (int) $queued,
        'filename' => $filename,
    ];
}

function octopus_ai_refresh_sitemap() {
    $nonce = '';
    if (isset($_REQUEST['octopus_ai_refresh_sitemap_nonce'])) {
        $nonce = (string) wp_unslash($_REQUEST['octopus_ai_refresh_sitemap_nonce']);
    } elseif (isset($_REQUEST['_wpnonce'])) {
        $nonce = (string) wp_unslash($_REQUEST['_wpnonce']);
    }

    if (
        !current_user_can('manage_options') ||
        $nonce === '' ||
        !wp_verify_nonce($nonce, 'octopus_ai_refresh_sitemap')
    ) {
        wp_die('Beveiligingsfout bij sitemap-update.');
    }

    $filename = isset($_REQUEST['sitemap_file']) ? basename((string) wp_unslash($_REQUEST['sitemap_file'])) : '';
    if ($filename === '' || !preg_match('/\.xml$/i', $filename)) {
        octopus_ai_sitemap_admin_redirect([
            'sitemap_error' => 'Geen geldig sitemap-bestand geselecteerd voor update.',
        ]);
    }

    $source_url = octopus_ai_resolve_sitemap_source_url($filename);
    if ($source_url === '') {
        octopus_ai_sitemap_admin_redirect([
            'sitemap_error' => 'Deze sitemap heeft geen bekende bron-URL en kan niet automatisch bijgewerkt worden.',
        ]);
    }

    $result = octopus_ai_import_sitemap_from_input_url($source_url, [
        'force_filename' => $filename,
        'save_active' => true,
        'register_source' => true,
    ]);

    if (is_wp_error($result)) {
        octopus_ai_sitemap_admin_redirect([
            'sitemap_error' => $result->get_error_message(),
        ]);
    }

    octopus_ai_sitemap_admin_redirect([
        'upload' => 'sitemap',
        'found' => (int) ($result['urls_count'] ?? 0),
        'pages' => (int) ($result['pages_count'] ?? 0),
        'queued' => (int) ($result['queued_count'] ?? 0),
        'sitemap_saved' => 1,
        'sitemap_refreshed' => 1,
        'sitemap_file' => sanitize_file_name($filename),
    ]);
}

function octopus_ai_handle_sitemap_upload() {
    if (
        !current_user_can('manage_options') ||
        !isset($_POST['octopus_ai_sitemap_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_sitemap_nonce'], 'octopus_ai_upload_sitemap')
    ) {
        wp_die('Beveiligingsfout bij sitemap-upload.');
    }

    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    if (!file_exists($upload_path)) {
        wp_mkdir_p($upload_path);
    }

    $all_urls = [];

    if (!empty($_FILES['octopus_ai_sitemap_file']['name'][0])) {
        $files = $_FILES['octopus_ai_sitemap_file'];

        foreach ($files['name'] as $i => $name) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $filename = sanitize_file_name($name);
                $tmp_path = $files['tmp_name'][$i];
                $dest_path = $upload_path . $filename;

                if (move_uploaded_file($tmp_path, $dest_path)) {
                    $urls = octopus_ai_parse_sitemap($dest_path);
                    $all_urls = array_merge($all_urls, $urls);
                    octopus_ai_register_sitemap_source($filename, '', [
                        'last_urls_count' => count($urls),
                    ]);
                }
            }
        }
    }

    if (!empty($_POST['octopus_ai_sitemap_url'])) {
        $remote_url = esc_url_raw(trim((string) $_POST['octopus_ai_sitemap_url']));
        $response = wp_remote_get($remote_url);
        if (!is_wp_error($response)) {
            $content = wp_remote_retrieve_body($response);
            if ($content) {
                $filename = 'remote_' . md5($remote_url) . '.xml';
                $destination = $upload_path . $filename;
                file_put_contents($destination, $content);
                $urls = octopus_ai_parse_sitemap($destination);
                $all_urls = array_merge($all_urls, $urls);
                octopus_ai_register_sitemap_source($filename, $remote_url, [
                    'input_url' => $remote_url,
                    'last_urls_count' => count($urls),
                ]);
            }
        }
    }

    $all_urls = array_values(array_unique($all_urls));
    if (empty($all_urls)) {
        octopus_ai_sitemap_admin_redirect([
            'sitemap_error' => 'Geen URL\'s gevonden. Controleer of het een geldige sitemap is.',
        ]);
    }

    $queued = octopus_ai_enqueue_sitemap_urls($all_urls);

    octopus_ai_sitemap_admin_redirect([
        'upload' => 'sitemap',
        'found'  => count($all_urls),
        'pages'  => 0,
        'queued' => (int) $queued,
    ]);
}

function octopus_ai_auto_fetch_sitemap() {
    if (
        !current_user_can('manage_options') ||
        !isset($_POST['octopus_ai_auto_sitemap_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_auto_sitemap_nonce'], 'octopus_ai_auto_sitemap')
    ) {
        wp_die('Beveiligingsfout bij automatische sitemap.');
    }

    $site_url = esc_url_raw(trim((string) ($_POST['octopus_ai_site_url'] ?? '')));
    if ($site_url === '') {
        octopus_ai_sitemap_admin_redirect([
            'sitemap_error' => 'Geen website opgegeven.',
        ]);
    }

    $result = octopus_ai_import_sitemap_from_input_url($site_url);
    if (is_wp_error($result)) {
        octopus_ai_sitemap_admin_redirect([
            'sitemap_error' => $result->get_error_message(),
        ]);
    }

    octopus_ai_sitemap_admin_redirect([
        'upload'        => 'sitemap',
        'found'         => (int) $result['urls_count'],
        'pages'         => (int) $result['pages_count'],
        'queued'        => isset($result['queued_count']) ? (int) $result['queued_count'] : 0,
        'sitemap_saved' => 1,
    ]);
}

function octopus_ai_import_sitemap_url() {
    if (
        !current_user_can('manage_options') ||
        !isset($_POST['octopus_ai_sitemap_url_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_sitemap_url_nonce'], 'octopus_ai_import_sitemap_url')
    ) {
        wp_die('Beveiligingsfout bij sitemap-URL import.');
    }

    $input_url = isset($_POST['octopus_ai_sitemap_url_input'])
        ? wp_unslash($_POST['octopus_ai_sitemap_url_input'])
        : '';

    $result = octopus_ai_import_sitemap_from_input_url($input_url);
    if (is_wp_error($result)) {
        octopus_ai_sitemap_admin_redirect([
            'sitemap_error' => $result->get_error_message(),
        ]);
    }

    octopus_ai_sitemap_admin_redirect([
        'upload'        => 'sitemap',
        'found'         => (int) $result['urls_count'],
        'pages'         => (int) $result['pages_count'],
        'queued'        => isset($result['queued_count']) ? (int) $result['queued_count'] : 0,
        'sitemap_saved' => 1,
    ]);
}

function octopus_ai_parse_sitemap($source, &$visited = []) {
    if (isset($visited[$source])) {
        return [];
    }

    $visited[$source] = true;
    $sitemap_xml = '';

    if (filter_var($source, FILTER_VALIDATE_URL)) {
        $response = wp_remote_get($source, [
            'timeout'     => 15,
            'redirection' => 5,
        ]);
        if (is_wp_error($response)) {
            return [];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            return [];
        }

        $sitemap_xml = (string) wp_remote_retrieve_body($response);
    } elseif (file_exists($source)) {
        $sitemap_xml = (string) file_get_contents($source);
    }

    if ($sitemap_xml === '') {
        return [];
    }

    if (!function_exists('simplexml_load_string')) {
        error_log('[Octopus AI] SimpleXML extensie ontbreekt: sitemap parsing overgeslagen.');
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($sitemap_xml);
    if (!$xml) {
        return [];
    }

    $namespaces = $xml->getDocNamespaces(true);
    $root = $xml->getName();
    $urls = [];

    if ($root === 'urlset') {
        if (isset($namespaces[''])) {
            $xml->registerXPathNamespace('ns', $namespaces['']);
            $entries = $xml->xpath('//ns:url/ns:loc');
        } else {
            $entries = $xml->xpath('//url/loc');
        }

        foreach ($entries as $loc) {
            $urls[] = (string) $loc;
        }
    } elseif ($root === 'sitemapindex') {
        if (isset($namespaces[''])) {
            $xml->registerXPathNamespace('ns', $namespaces['']);
            $entries = $xml->xpath('//ns:sitemap/ns:loc');
        } else {
            $entries = $xml->xpath('//sitemap/loc');
        }

        foreach ($entries as $loc) {
            $child = (string) $loc;
            $urls = array_merge($urls, octopus_ai_parse_sitemap($child, $visited));
        }
    }

    return array_values(array_unique($urls));
}

function octopus_ai_find_sitemap_url($site_url) {
    $site_url = esc_url_raw(trim((string) $site_url));
    if ($site_url === '') {
        return '';
    }

    if (function_exists('wp_http_validate_url') && !wp_http_validate_url($site_url)) {
        return '';
    }

    $site_url = trailingslashit($site_url);

    $robots_url = $site_url . 'robots.txt';
    $response = wp_remote_get($robots_url, [
        'timeout'     => 8,
        'redirection' => 3,
    ]);

    if (!is_wp_error($response)) {
        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code >= 200 && $status_code < 300) {
            $body = (string) wp_remote_retrieve_body($response);
            if ($body !== '') {
                foreach (preg_split('/\R+/', $body) as $line) {
                    $line = trim((string) $line);
                    if (stripos($line, 'sitemap:') !== 0) {
                        continue;
                    }

                    $candidate = trim(substr($line, 8));
                    if ($candidate !== '' && (!function_exists('wp_http_validate_url') || wp_http_validate_url($candidate))) {
                        return $candidate;
                    }
                }
            }
        }
    }

    $candidates = [
        $site_url . 'sitemap.xml',
        $site_url . 'sitemap_index.xml',
        $site_url . 'sitemap-index.xml',
    ];

    foreach ($candidates as $url) {
        $head = wp_remote_head($url, [
            'timeout'     => 8,
            'redirection' => 3,
        ]);

        if (!is_wp_error($head)) {
            $status_code = (int) wp_remote_retrieve_response_code($head);
            if ($status_code >= 200 && $status_code < 300) {
                return $url;
            }
        }

        $get = wp_remote_get($url, [
            'timeout'     => 8,
            'redirection' => 3,
        ]);

        if (!is_wp_error($get)) {
            $status_code = (int) wp_remote_retrieve_response_code($get);
            if ($status_code >= 200 && $status_code < 300) {
                return $url;
            }
        }
    }

    return '';
}

add_action('admin_post_octopus_ai_delete_chunks', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('octopus_ai_delete_chunks')) {
        wp_die('Beveiligingsfout bij verwijderen van chunks.');
    }

    $upload_dir = wp_upload_dir();
    $chunk_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

    $count = 0;
    if (!empty($_POST['chunk_files']) && is_array($_POST['chunk_files'])) {
        foreach ($_POST['chunk_files'] as $file) {
            $full_path = $chunk_dir . basename($file);
            if (file_exists($full_path)) {
                unlink($full_path);
                $count++;
            }
        }
    }

    wp_safe_redirect(admin_url('admin.php?page=octopus-ai-chatbot&chunks_deleted=' . $count));
    exit;
});

add_action('admin_post_octopus_ai_clear_all_chunks', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('octopus_ai_clear_all_chunks')) {
        wp_die('Beveiligingsfout bij verwijderen van alle chunks.');
    }

    $upload_dir = wp_upload_dir();
    $chunk_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

    if (file_exists($chunk_dir)) {
        foreach (glob($chunk_dir . 'sitemap_*.json') as $file) {
            unlink($file);
        }
    }

    wp_safe_redirect(admin_url('admin.php?page=octopus-ai-chatbot&chunks_cleared=1'));
    exit;
});

add_action('admin_post_octopus_ai_delete_sitemaps', function () {
    if (
        !current_user_can('manage_options') ||
        !check_admin_referer('octopus_ai_delete_sitemaps')
    ) {
        wp_die('Beveiligingsfout bij verwijderen van sitemap-bestanden.');
    }

    $upload_dir = wp_upload_dir();
    $sitemap_dir = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    $deleted = 0;

    if (!empty($_POST['sitemap_files']) && is_array($_POST['sitemap_files'])) {
        foreach ($_POST['sitemap_files'] as $file) {
            $safe_file = basename($file);
            $full_path = $sitemap_dir . $safe_file;
            if (file_exists($full_path)) {
                $urls = octopus_ai_parse_sitemap($full_path);
                if ($urls && class_exists(\OctopusAI\Includes\SitemapParser::class)) {
                    $parser = new \OctopusAI\Includes\SitemapParser();
                    $parser->deleteChunksForUrls($urls);
                }

                unlink($full_path);
                octopus_ai_unregister_sitemap_source($safe_file);

                $saved_sitemap_url = esc_url_raw((string) get_option('octopus_ai_sitemap_url', ''));
                if ($saved_sitemap_url !== '' && $safe_file === ('remote_' . md5($saved_sitemap_url) . '.xml')) {
                    delete_option('octopus_ai_sitemap_url');
                }

                $deleted++;
            }
        }
    }

    wp_safe_redirect(admin_url('admin.php?page=octopus-ai-chatbot&sitemaps_deleted=' . $deleted));
    exit;
});
