<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_post_octopus_ai_upload_sitemap', 'octopus_ai_handle_sitemap_upload');
add_action('admin_post_octopus_ai_auto_fetch_sitemap', 'octopus_ai_auto_fetch_sitemap');
add_action('admin_post_octopus_ai_import_sitemap_url', 'octopus_ai_import_sitemap_url');
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
        wp_schedule_single_event(time() + 10, 'octopus_ai_process_sitemap_queue');
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
        wp_schedule_single_event(time() + 5, 'octopus_ai_process_sitemap_queue');
    }
}

function octopus_ai_process_sitemap_queue() {
    $lock_key = 'octopus_ai_sitemap_queue_lock';
    if (get_transient($lock_key)) {
        if (!wp_next_scheduled('octopus_ai_process_sitemap_queue')) {
            wp_schedule_single_event(time() + 20, 'octopus_ai_process_sitemap_queue');
        }
        return;
    }

    set_transient($lock_key, 1, 3 * MINUTE_IN_SECONDS);

    try {
        $queue = octopus_ai_get_sitemap_queue();
        if (empty($queue)) {
            delete_option('octopus_ai_sitemap_queue_status');
            return;
        }

        $batch_size = 15;
        $batch = array_slice($queue, 0, $batch_size);
        $remaining = array_slice($queue, $batch_size);
        octopus_ai_set_sitemap_queue($remaining);

        if (!class_exists(\OctopusAI\Includes\SitemapParser::class)) {
            error_log('[Octopus AI] SitemapParser class ontbreekt, sitemap queue kan niet verwerkt worden.');
            if (!wp_next_scheduled('octopus_ai_process_sitemap_queue')) {
                wp_schedule_single_event(time() + 60, 'octopus_ai_process_sitemap_queue');
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
            wp_schedule_single_event(time() + 15, 'octopus_ai_process_sitemap_queue');
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

function octopus_ai_import_sitemap_from_input_url($input_url) {
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

    $filename = 'remote_' . md5($sitemap_url) . '.xml';
    $destination = $upload_path . $filename;
    if (file_put_contents($destination, $content) === false) {
        return new WP_Error('octopus_ai_sitemap_store_failed', 'De sitemap kon niet lokaal worden opgeslagen.');
    }

    $urls = array_unique(octopus_ai_parse_sitemap($destination));
    if (empty($urls)) {
        return new WP_Error('octopus_ai_sitemap_no_urls', 'Geen URL\'s gevonden in de sitemap.');
    }

    $queued = octopus_ai_enqueue_sitemap_urls($urls);

    update_option('octopus_ai_sitemap_url', $sitemap_url);

    return [
        'sitemap_url' => $sitemap_url,
        'urls_count'  => count($urls),
        'pages_count' => 0,
        'queued_count' => (int) $queued,
    ];
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
                $deleted++;
            }
        }
    }

    wp_safe_redirect(admin_url('admin.php?page=octopus-ai-chatbot&sitemaps_deleted=' . $deleted));
    exit;
});
