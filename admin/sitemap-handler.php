<?php
// ✅ Hook om sitemap-upload te verwerken
add_action('admin_post_octopus_ai_upload_sitemap', 'octopus_ai_handle_sitemap_upload');

/**
 * ✅ Verwerkt sitemap-upload en parseert URL's
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

    // 📥 Methode 1: Bestand geüpload
    if (!empty($_FILES['octopus_ai_sitemap_file']['tmp_name'])) {
        $file = $_FILES['octopus_ai_sitemap_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $destination = $upload_path . 'sitemap.xml';
            move_uploaded_file($file['tmp_name'], $destination);
            $urls = octopus_ai_parse_sitemap($destination);
        }
    }

    // 🌐 Methode 2: URL ingevoerd
    elseif (!empty($_POST['octopus_ai_sitemap_url'])) {
        $remote_url = esc_url_raw(trim($_POST['octopus_ai_sitemap_url']));
        $xml = wp_remote_get($remote_url);
        if (is_wp_error($xml)) wp_die('Fout bij ophalen van sitemap-URL.');

        $content = wp_remote_retrieve_body($xml);
        if (!$content) wp_die('Lege sitemap ontvangen.');

        $destination = $upload_path . 'sitemap.xml';
        file_put_contents($destination, $content);
        $urls = octopus_ai_parse_sitemap($destination);
    }

    // 🧹 Fallback
    else {
        wp_die('Geen geldige sitemap geüpload of ingevuld.');
    }

    update_option('octopus_ai_sitemap_urls', $urls ?? []);
    wp_redirect(admin_url('admin.php?page=octopus-ai-chatbot&upload=sitemap&found=' . count($urls ?? [])));
    exit;
}

/**
 * ✅ Registreer de XML namespace voor sitemap.org
 */
function octopus_ai_register_sitemap_ns($xml) {
    $xml->registerXPathNamespace('ns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
}

/**
 * ✅ Parse sitemap.xml en haal de URL's op
 */
function octopus_ai_parse_sitemap($path) {
    $sitemap_xml = file_get_contents($path);
    if (!$sitemap_xml) return [];

    // Fallback-oplossing via regex (zonder afhankelijkheid van XML-parsers)
    preg_match_all('/<loc>(.*?)<\/loc>/i', $sitemap_xml, $matches);
    if (empty($matches[1])) return [];

    $urls = array_map('trim', $matches[1]);
    return $urls;
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

    $client = new \WP_Http();
    $count = 0;

    foreach ($urls as $url) {
        if ($count >= 25) break; // max 25 pagina's

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
        if (empty($content)) {
    wp_die('⚠️ Externe sitemap kon niet worden opgehaald. Waarschijnlijk geblokkeerd door je lokale omgeving.');
}
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

