<?php
// ✅ Hook om sitemap-upload te verwerken
add_action('admin_post_octopus_ai_upload_sitemap', 'octopus_ai_handle_sitemap_upload');
// 🔎 Automatisch sitemap ophalen via website-URL
add_action('admin_post_octopus_ai_auto_fetch_sitemap', 'octopus_ai_auto_fetch_sitemap');
/**
 * ✅ Verwerkt sitemap-upload of remote-URL en parseert URL's
 */
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
    if (!file_exists($upload_path)) wp_mkdir_p($upload_path);

    $all_urls = [];

    // 📥 Meerdere sitemap-bestanden
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

    // 🌐 Optioneel: URL invoer
    if (!empty($_POST['octopus_ai_sitemap_url'])) {
        $remote_url = esc_url_raw(trim($_POST['octopus_ai_sitemap_url']));
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

    if (empty($all_urls)) {
        wp_die('⚠️ Geen URL’s gevonden. Controleer of het een geldige sitemap is.');
    }

    update_option('octopus_ai_sitemap_urls', array_unique($all_urls));
    wp_redirect(admin_url('admin.php?page=octopus-ai-chatbot&upload=sitemap&found=' . count($all_urls)));
    exit;
}

/**
 * 🔎 Zoek automatisch de sitemap van een website en parseer URL's
 */
function octopus_ai_auto_fetch_sitemap() {
    if (
        !current_user_can('manage_options') ||
        !isset($_POST['octopus_ai_auto_sitemap_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_auto_sitemap_nonce'], 'octopus_ai_auto_sitemap')
    ) {
        wp_die('Beveiligingsfout bij automatische sitemap.');
    }

    $site_url = esc_url_raw(trim($_POST['octopus_ai_site_url'] ?? ''));
    if (empty($site_url)) {
        wp_die('Geen website opgegeven.');
    }

    $sitemap_url = octopus_ai_find_sitemap_url($site_url);
    if (!$sitemap_url) {
        wp_die('Geen sitemap gevonden bij de opgegeven website.');
    }

    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    if (!file_exists($upload_path)) wp_mkdir_p($upload_path);

    $response = wp_remote_get($sitemap_url);
    if (is_wp_error($response)) {
        wp_die('Kon sitemap niet ophalen.');
    }

    $content = wp_remote_retrieve_body($response);
    if (!$content) {
        wp_die('Sitemap is leeg.');
    }

    $filename = 'auto_' . md5($sitemap_url) . '.xml';
    $destination = $upload_path . $filename;
    file_put_contents($destination, $content);

    $urls = octopus_ai_parse_sitemap($destination);
    if (empty($urls)) {
        wp_die('Geen URL\'s gevonden in de sitemap.');
    }

    update_option('octopus_ai_sitemap_url', $sitemap_url);
    update_option('octopus_ai_sitemap_urls', array_unique($urls));

    wp_redirect(admin_url('admin.php?page=octopus-ai-chatbot&upload=sitemap&found=' . count($urls)));
    exit;
}

/**
 * ✅ Parse een sitemap of indexbestand (ook met sub-sitemaps)
 */
function octopus_ai_parse_sitemap($source, &$visited = []) {
    if (isset($visited[$source])) return [];
    $visited[$source] = true;

    if (filter_var($source, FILTER_VALIDATE_URL)) {
        $response = wp_remote_get($source);
        if (is_wp_error($response)) return [];
        $sitemap_xml = wp_remote_retrieve_body($response);
    } else {
        if (!file_exists($source)) return [];
        $sitemap_xml = file_get_contents($source);
    }
    if (!$sitemap_xml) return [];

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($sitemap_xml);
    if (!$xml) return [];

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

    return $urls;
}

/**
 * 📡 Vind een sitemap-URL via robots.txt of standaard paden
 */
function octopus_ai_find_sitemap_url($site_url) {
    $site_url = trailingslashit($site_url);

    // Eerst robots.txt raadplegen
    $robots_url = $site_url . 'robots.txt';
    $response = wp_remote_get($robots_url);
    if (!is_wp_error($response)) {
        $body = wp_remote_retrieve_body($response);
        if ($body) {
            foreach (explode("\n", $body) as $line) {
                if (stripos($line, 'sitemap:') === 0) {
                    $candidate = trim(substr($line, 8));
                    if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                        return $candidate;
                    }
                }
            }
        }
    }

    // Fallback naar standaard locaties
    $candidates = [
        $site_url . 'sitemap.xml',
        $site_url . 'sitemap_index.xml'
    ];
    foreach ($candidates as $url) {
        $head = wp_remote_head($url);
        if (!is_wp_error($head) && wp_remote_retrieve_response_code($head) == 200) {
            return $url;
        }
    }

    return '';
}

// ✅ Crawlen & chunks genereren uit sitemap-URLs
add_action('admin_post_octopus_ai_crawl_sitemap', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('octopus_ai_crawl_sitemap')) {
        wp_die('Beveiligingsfout bij crawlen.');
    }

    $upload_dir = wp_upload_dir();
    $urls = get_option('octopus_ai_sitemap_urls', []);
    $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
    if (!file_exists($chunks_dir)) wp_mkdir_p($chunks_dir);

    $count = 0;

    foreach ($urls as $url) {
        if ($count >= 25) break; // Max 25 pagina's

        $response = wp_remote_get($url);
        if (is_wp_error($response)) continue;

        $html = wp_remote_retrieve_body($response);
        if (!$html) continue;

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $mainNode = $xpath->query('//main')->item(0) ?? $xpath->query('//body')->item(0);
        if (!$mainNode) continue;

        $text = trim($mainNode->textContent);
        if (strlen($text) < 50) continue;

        $slug = sanitize_title(basename(parse_url($url, PHP_URL_PATH))) ?: 'pagina-' . $count;
        file_put_contents($chunks_dir . 'sitemap_' . $slug . '.txt', $text);
        $count++;
    }

    wp_redirect(admin_url('admin.php?page=octopus-ai-chatbot&crawl=done&pages=' . $count));
    exit;
});

// ✅ Wissen van sitemap-URL lijst
add_action('admin_post_octopus_ai_clear_sitemap_urls', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('octopus_ai_clear_sitemap_urls')) {
        wp_die('Beveiligingsfout bij wissen.');
    }

    delete_option('octopus_ai_sitemap_urls');
    wp_redirect(admin_url('admin.php?page=octopus-ai-chatbot&cleared=1'));
    exit;
});

// ✅ Verwijderen van individuele chunks
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

    wp_redirect(admin_url('admin.php?page=octopus-ai-chatbot&chunks_deleted=' . $count));
    exit;
});

// ✅ Alles wissen
add_action('admin_post_octopus_ai_clear_all_chunks', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('octopus_ai_clear_all_chunks')) {
        wp_die('Beveiligingsfout bij verwijderen van alle chunks.');
    }

    $upload_dir = wp_upload_dir();
    $chunk_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
    $deleted = 0;

    if (file_exists($chunk_dir)) {
        foreach (glob($chunk_dir . 'sitemap_*.txt') as $file) {
            unlink($file);
            $deleted++;
        }
    }

    wp_redirect(admin_url('admin.php?page=octopus-ai-chatbot&chunks_cleared=1'));
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
                unlink($full_path);
                $deleted++;
            }
        }
    }

    wp_redirect(admin_url('admin.php?page=octopus-ai-chatbot&sitemaps_deleted=' . $deleted));
    exit;
});
