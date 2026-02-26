<?php
if (!defined('ABSPATH')) exit;

/**
 * Haal de ingestelde modus voor documentatie op.
 *
 * @return string
 */
function octopus_ai_get_manual_mode()
{
    $strategy = get_option('octopus_ai_source_strategy', '');
    if ($strategy === 'live_manual') {
        return 'live';
    }

    if (in_array($strategy, ['manual_upload', 'sitemap_online'], true)) {
        return 'local';
    }

    $mode = get_option('octopus_ai_manual_mode', 'hybrid');
    $allowed = ['local', 'hybrid', 'live'];

    return in_array($mode, $allowed, true) ? $mode : 'hybrid';
}

/**
 * Normaliseert een basis-URL zodat hij altijd met een slash eindigt.
 *
 * @param string $url
 * @return string
 */
function octopus_ai_normalize_manual_base($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    $url = rtrim($url, " \\t\n\r\0\x0B");

    if (!in_array(substr($url, -1), ['/', '?'], true)) {
        $url .= '/';
    }

    return $url;
}

/**
 * Bepaal de basis-URL voor de handleiding per taal.
 *
 * @param string $lang
 * @return string
 */
function octopus_ai_get_manual_base_url($lang)
{
    $lang_key = strtoupper($lang) === 'FR' ? 'fr' : 'nl';
    $option_name = $lang_key === 'fr' ? 'octopus_ai_manual_base_url_fr' : 'octopus_ai_manual_base_url_nl';
    $custom_base = trim((string) get_option($option_name, ''));

    if ($custom_base !== '') {
        $normalized = octopus_ai_normalize_manual_base($custom_base);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    if (function_exists('octopus_ai_get_provider_manual_base_url')) {
        $provider_base = octopus_ai_get_provider_manual_base_url($lang_key === 'fr' ? 'FR' : 'NL');
        $provider_base = octopus_ai_normalize_manual_base($provider_base);
        if ($provider_base !== '') {
            return $provider_base;
        }
    }

    $provider_defaults = function_exists('octopus_ai_get_default_provider_profile')
        ? octopus_ai_get_default_provider_profile()
        : [];
    $default_manual = isset($provider_defaults['manual']) && is_array($provider_defaults['manual'])
        ? $provider_defaults['manual']
        : [];
    $fallback_base = $lang_key === 'fr'
        ? (string) ($default_manual['base_url_fr'] ?? '')
        : (string) ($default_manual['base_url_nl'] ?? '');
    $fallback_base = octopus_ai_normalize_manual_base($fallback_base);
    if ($fallback_base !== '') {
        return $fallback_base;
    }

    return $lang_key === 'fr'
        ? 'https://example.com/manual/fr/'
        : 'https://example.com/manual/nl/';
}

/**
 * Geeft het toegestane domein voor handleiding-links per taal terug.
 *
 * @param string $lang
 * @return string
 */
function octopus_ai_get_allowed_manual_host($lang)
{
    $base_url = octopus_ai_get_manual_base_url($lang);
    $parsed   = wp_parse_url($base_url);
    $host     = is_array($parsed) && !empty($parsed['host']) ? strtolower($parsed['host']) : '';

    return $host;
}

/**
 * Controleert of een URL binnen het toegestane handleiding-domein valt.
 *
 * @param string $url
 * @param string $lang
 * @return bool
 */
function octopus_ai_is_allowed_manual_url($url, $lang)
{
    $allowed_host = octopus_ai_get_allowed_manual_host($lang);
    if ($allowed_host === '') {
        return false;
    }

    $parsed = wp_parse_url((string) $url);
    $host   = is_array($parsed) && !empty($parsed['host']) ? strtolower($parsed['host']) : '';

    return $host !== '' && $host === $allowed_host;
}

/**
 * Ophalen van handmatig ingestelde prioritaire URL's.
 *
 * @param string $lang
 * @return array<int, string>
 */
function octopus_ai_get_manual_priority_urls($lang)
{
    $lang_key = strtoupper($lang) === 'FR' ? 'fr' : 'nl';
    $option_name = $lang_key === 'fr' ? 'octopus_ai_manual_priority_urls_fr' : 'octopus_ai_manual_priority_urls_nl';
    $raw = (string) get_option($option_name, '');

    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[\r\n,]+/', $raw);
    $urls = [];

    if (is_array($parts)) {
        foreach ($parts as $part) {
            $candidate = trim((string) $part);
            if ($candidate === '') {
                continue;
            }

            $candidate = esc_url_raw($candidate);
            if ($candidate === '') {
                continue;
            }

            if (function_exists('wp_http_validate_url') && !wp_http_validate_url($candidate)) {
                continue;
            }

            if (!octopus_ai_is_allowed_manual_url($candidate, $lang_key)) {
                continue;
            }

            if (!in_array($candidate, $urls, true)) {
                $urls[] = $candidate;
            }
        }
    }

    return $urls;
}

/**
 * Bouw een zoek-URL naar de handleiding op basis van de ingestelde basis-URL.
 *
 * @param string $lang
 * @param string $keyword
 * @return string
 */
function octopus_ai_get_manual_search_url($lang, $keyword = '')
{
    $base = trailingslashit(octopus_ai_get_manual_base_url($lang));
    $url  = $base . 'hmftsearch.htm';

    $keyword = trim((string) $keyword);
    if ($keyword !== '') {
        $url .= '?zoom_query=' . rawurlencode($keyword);
    }

    return $url;
}

/**
 * Zet een page_slug om naar een waarschijnlijke handleiding-URL.
 *
 * @param string $slug
 * @param string $lang
 * @return string
 */
function octopus_ai_build_manual_url_from_slug($slug, $lang)
{
    $slug = trim((string) $slug);
    if ($slug === '') {
        return '';
    }

    $absolute = esc_url_raw($slug);
    if ($absolute !== '' && preg_match('#^https?://#i', $absolute)) {
        return octopus_ai_is_allowed_manual_url($absolute, $lang) ? $absolute : '';
    }

    $slug = html_entity_decode($slug, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $slug = ltrim($slug, '/');
    $slug = preg_replace('#^manual/(nl|fr)/#i', '', $slug);

    $slug_without_query = preg_replace('/[?#].*$/', '', $slug);
    if (!is_string($slug_without_query) || $slug_without_query === '') {
        return '';
    }

    $filename = pathinfo($slug_without_query, PATHINFO_FILENAME);
    if (is_string($filename) && preg_match('/-p\d+$/i', $filename)) {
        return '';
    }

    if (!preg_match('/\.html?$/i', $slug_without_query)) {
        return '';
    }

    $candidate = esc_url_raw(trailingslashit(octopus_ai_get_manual_base_url($lang)) . $slug);
    if ($candidate === '' || !octopus_ai_is_allowed_manual_url($candidate, $lang)) {
        return '';
    }

    return $candidate;
}

/**
 * Geeft geordende URL-kandidaten terug voor een metadata-record.
 * Prioriteit: manual_url > source_url > slug-fallback.
 *
 * @param array  $meta
 * @param string $lang
 * @return array<int, string>
 */
function octopus_ai_get_manual_url_candidates(array $meta, $lang)
{
    $urls = [];

    $manual_url = isset($meta['manual_url']) ? esc_url_raw((string) $meta['manual_url']) : '';
    $source_url = isset($meta['source_url']) ? esc_url_raw((string) $meta['source_url']) : '';
    $page_slug  = isset($meta['page_slug']) ? (string) $meta['page_slug'] : '';

    $append = static function ($url) use (&$urls, $lang) {
        $url = trim((string) $url);
        if ($url === '' || !octopus_ai_is_allowed_manual_url($url, $lang)) {
            return;
        }

        if (!in_array($url, $urls, true)) {
            $urls[] = $url;
        }
    };

    $append($manual_url);
    $append($source_url);

    $slug_url = octopus_ai_build_manual_url_from_slug($page_slug, $lang);
    $append($slug_url);

    return $urls;
}

/**
 * Bouwt mogelijke handleiding-URL's op basis van chunk-metadata.
 *
 * @param array  $metadata_chunks
 * @param string $lang
 * @return array
 */
function octopus_ai_build_manual_urls(array $metadata_chunks, $lang)
{
    $urls = octopus_ai_get_manual_priority_urls($lang);
    $lang = strtoupper($lang) === 'FR' ? 'FR' : 'NL';

    foreach ($metadata_chunks as $meta) {
        if (!is_array($meta)) {
            continue;
        }

        $candidates = octopus_ai_get_manual_url_candidates($meta, $lang);
        foreach ($candidates as $candidate) {
            if (!in_array($candidate, $urls, true)) {
                $urls[] = $candidate;
            }
        }

        if (count($urls) >= 5) {
            break;
        }
    }

    return $urls;
}

if (!function_exists('octopus_ai_parse_remote_sitemap_urls')) {
    /**
     * Parseert een remote sitemap (urlset of sitemapindex) naar URL's.
     *
     * @param string $sitemap_url
     * @param string $lang
     * @param array  $visited
     * @param int    $depth
     * @return array<int,string>
     */
    function octopus_ai_parse_remote_sitemap_urls($sitemap_url, $lang, array &$visited = [], $depth = 0)
    {
        $sitemap_url = esc_url_raw((string) $sitemap_url);
        if ($sitemap_url === '' || isset($visited[$sitemap_url]) || $depth > 3) {
            return [];
        }

        $visited[$sitemap_url] = true;
        $download = octopus_ai_download_manual_page($sitemap_url);
        if (!is_array($download) || (int) ($download['status'] ?? 0) !== 200) {
            return [];
        }

        $xml_raw = (string) ($download['body'] ?? '');
        if ($xml_raw === '' || !function_exists('simplexml_load_string')) {
            return [];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_raw);
        if (!$xml) {
            return [];
        }

        $namespaces = $xml->getDocNamespaces(true);
        $root_name = strtolower((string) $xml->getName());
        $urls = [];

        if ($root_name === 'urlset') {
            if (isset($namespaces[''])) {
                $xml->registerXPathNamespace('ns', $namespaces['']);
                $entries = $xml->xpath('//ns:url/ns:loc');
            } else {
                $entries = $xml->xpath('//url/loc');
            }

            if (is_array($entries)) {
                foreach ($entries as $loc) {
                    $url = esc_url_raw((string) $loc);
                    if ($url !== '' && octopus_ai_is_allowed_manual_url($url, $lang)) {
                        $urls[] = $url;
                    }
                }
            }
        } elseif ($root_name === 'sitemapindex') {
            if (isset($namespaces[''])) {
                $xml->registerXPathNamespace('ns', $namespaces['']);
                $entries = $xml->xpath('//ns:sitemap/ns:loc');
            } else {
                $entries = $xml->xpath('//sitemap/loc');
            }

            if (is_array($entries)) {
                foreach ($entries as $loc) {
                    $child = esc_url_raw((string) $loc);
                    if ($child === '') {
                        continue;
                    }
                    $urls = array_merge($urls, octopus_ai_parse_remote_sitemap_urls($child, $lang, $visited, $depth + 1));
                }
            }
        }

        return array_values(array_unique($urls));
    }
}

if (!function_exists('octopus_ai_get_manual_sitemap_urls')) {
    /**
     * Haalt handleiding-URL's rechtstreeks uit de live sitemap.
     *
     * @param string $lang
     * @return array<int,string>
     */
    function octopus_ai_get_manual_sitemap_urls($lang)
    {
        $lang_key = strtoupper((string) $lang) === 'FR' ? 'fr' : 'nl';
        $cache_key = 'octopus_ai_manual_sitemap_urls_v2_' . $lang_key;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $base = trailingslashit(octopus_ai_get_manual_base_url($lang));
        $sitemap_url = esc_url_raw($base . 'sitemap.xml');
        if ($sitemap_url === '') {
            return [];
        }

        $visited = [];
        $urls = octopus_ai_parse_remote_sitemap_urls($sitemap_url, $lang, $visited, 0);
        $urls = array_values(array_filter(array_unique(array_map('esc_url_raw', $urls))));

        set_transient($cache_key, $urls, 6 * HOUR_IN_SECONDS);
        return $urls;
    }
}

if (!function_exists('octopus_ai_get_manual_base_path_prefix')) {
    /**
     * Geeft het padprefix van de handleiding-base terug (bijv. /manual/nl/).
     *
     * @param string $lang
     * @return string
     */
    function octopus_ai_get_manual_base_path_prefix($lang)
    {
        $base = octopus_ai_get_manual_base_url($lang);
        $path = (string) wp_parse_url($base, PHP_URL_PATH);
        $path = '/' . ltrim((string) $path, '/');
        $path = preg_replace('#/+#', '/', $path);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        return trailingslashit($path);
    }
}

if (!function_exists('octopus_ai_get_manual_base_path_prefixes')) {
    /**
     * Mogelijke padprefixen voor handleiding-URL's.
     * Houdt rekening met bases zoals /manual/NL/ terwijl de sitemap vaak /manual/* bevat.
     *
     * @param string $lang
     * @return array<int,string>
     */
    function octopus_ai_get_manual_base_path_prefixes($lang)
    {
        $prefixes = [];
        $base_prefix = octopus_ai_get_manual_base_path_prefix($lang);
        if ($base_prefix !== '') {
            $prefixes[] = $base_prefix;
        }

        if (preg_match('#^(.*/)(nl|fr)/$#i', $base_prefix, $matches) && !empty($matches[1])) {
            $prefixes[] = trailingslashit((string) $matches[1]);
        }

        $manual_root = '/manual/';
        if (!in_array($manual_root, $prefixes, true)) {
            $prefixes[] = $manual_root;
        }

        $prefixes = array_values(array_unique(array_filter($prefixes, static function ($prefix) {
            return is_string($prefix) && $prefix !== '';
        })));

        usort(
            $prefixes,
            static function ($a, $b) {
                return strlen((string) $b) <=> strlen((string) $a);
            }
        );

        return $prefixes;
    }
}

if (!function_exists('octopus_ai_normalize_manual_candidate_url')) {
    /**
     * Normaliseert en valideert een kandidaat-URL binnen dezelfde manual-base.
     *
     * @param string $url
     * @param string $lang
     * @param bool   $allow_directory
     * @return string
     */
    function octopus_ai_normalize_manual_candidate_url($url, $lang, $allow_directory = true)
    {
        $url = esc_url_raw((string) $url);
        if ($url === '' || !octopus_ai_is_allowed_manual_url($url, $lang)) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = !empty($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        // Sommige sitemaps geven /manual/<topic>.htm terug i.p.v. /manual/<LANG>/<topic>.htm.
        // Herschrijf dat pad naar de actieve taalmap om 404's te vermijden.
        $base_prefix = octopus_ai_get_manual_base_path_prefix($lang);
        $lang_segment = '';
        if (preg_match('#^/manual/([^/]+)/$#i', $base_prefix, $base_match) && !empty($base_match[1])) {
            $lang_segment = (string) $base_match[1];
        }

        if ($lang_segment !== '' && preg_match('#^/manual/(?![a-z]{2}/)(.+\.html?)$#i', $path, $manual_match) && !empty($manual_match[1])) {
            $rewritten_path = '/manual/' . $lang_segment . '/' . ltrim((string) $manual_match[1], '/');
            $rewritten_path = preg_replace('#/+#', '/', $rewritten_path);
            if (is_string($rewritten_path) && $rewritten_path !== '') {
                $path = $rewritten_path;
            }
        }

        $allowed_prefixes = octopus_ai_get_manual_base_path_prefixes($lang);
        $path_matches_prefix = false;
        foreach ($allowed_prefixes as $prefix) {
            $prefix = (string) $prefix;
            if ($prefix === '/' || strpos(strtolower($path), strtolower($prefix)) === 0) {
                $path_matches_prefix = true;
                break;
            }
        }

        if (!$path_matches_prefix) {
            return '';
        }

        $lower_path = strtolower($path);
        if (strpos($lower_path, '/hmftsearch') !== false || strpos($lower_path, '/search') !== false) {
            return '';
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (!$allow_directory && ($extension === '' || substr($path, -1) === '/')) {
            return '';
        }

        $blocked_extensions = ['css', 'js', 'json', 'xml', 'txt', 'pdf', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'zip', 'rar'];
        if ($extension !== '' && in_array($extension, $blocked_extensions, true)) {
            return '';
        }

        if ($extension !== '' && !in_array($extension, ['htm', 'html'], true)) {
            return '';
        }

        return esc_url_raw($scheme . '://' . $host . $port . $path);
    }
}

if (!function_exists('octopus_ai_resolve_manual_relative_url')) {
    /**
     * Resolveert een relatieve href naar absolute URL.
     *
     * @param string $base_url
     * @param string $href
     * @return string
     */
    function octopus_ai_resolve_manual_relative_url($base_url, $href)
    {
        $base_url = esc_url_raw((string) $base_url);
        $href = trim((string) $href);

        if ($base_url === '' || $href === '' || $href[0] === '#') {
            return '';
        }

        $href_lower = strtolower($href);
        if (strpos($href_lower, 'javascript:') === 0 || strpos($href_lower, 'mailto:') === 0 || strpos($href_lower, 'tel:') === 0) {
            return '';
        }

        if (preg_match('#^https?://#i', $href)) {
            return esc_url_raw($href);
        }

        $base_parts = wp_parse_url($base_url);
        if (!is_array($base_parts) || empty($base_parts['host'])) {
            return '';
        }

        $scheme = !empty($base_parts['scheme']) ? (string) $base_parts['scheme'] : 'https';
        $host = (string) $base_parts['host'];
        $port = isset($base_parts['port']) ? ':' . (int) $base_parts['port'] : '';

        if (strpos($href, '//') === 0) {
            return esc_url_raw($scheme . ':' . $href);
        }

        if ($href[0] === '/') {
            return esc_url_raw($scheme . '://' . $host . $port . $href);
        }

        $base_path = (string) ($base_parts['path'] ?? '/');
        $base_dir = preg_replace('#/[^/]*$#', '/', $base_path);
        if (!is_string($base_dir) || $base_dir === '') {
            $base_dir = '/';
        }

        $combined = $base_dir . $href;
        $combined = preg_replace('#/+#', '/', $combined);
        if (!is_string($combined) || $combined === '') {
            $combined = '/';
        }

        $segments = explode('/', $combined);
        $normalized_segments = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($normalized_segments);
                continue;
            }
            $normalized_segments[] = $segment;
        }

        $normalized_path = '/' . implode('/', $normalized_segments);
        return esc_url_raw($scheme . '://' . $host . $port . $normalized_path);
    }
}

if (!function_exists('octopus_ai_extract_manual_links_from_html')) {
    /**
     * Extraheert interne handleidinglinks uit HTML.
     *
     * @param string $html
     * @param string $page_url
     * @param string $lang
     * @return array<int,string>
     */
    function octopus_ai_extract_manual_links_from_html($html, $page_url, $lang)
    {
        $html = (string) $html;
        if ($html === '') {
            return [];
        }

        $raw_hrefs = [];

        if (class_exists('DOMDocument')) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            if (@$dom->loadHTML($html)) {
                $anchors = $dom->getElementsByTagName('a');
                foreach ($anchors as $anchor) {
                    if ($anchor instanceof DOMElement && $anchor->hasAttribute('href')) {
                        $raw_hrefs[] = (string) $anchor->getAttribute('href');
                    }
                }

                // Neem ook dropdown/JS-attributen mee (option value, data-href, data-url, onclick-nav).
                $all_nodes = $dom->getElementsByTagName('*');
                foreach ($all_nodes as $node) {
                    if (!$node instanceof DOMElement) {
                        continue;
                    }

                    if (strtolower($node->tagName) === 'option') {
                        foreach (['value', 'data-href', 'data-url', 'data-link'] as $attr_name) {
                            if ($node->hasAttribute($attr_name)) {
                                $raw_hrefs[] = (string) $node->getAttribute($attr_name);
                            }
                        }
                    }

                    foreach (['data-href', 'data-url', 'data-link'] as $attr_name) {
                        if ($node->hasAttribute($attr_name)) {
                            $raw_hrefs[] = (string) $node->getAttribute($attr_name);
                        }
                    }

                    if ($node->hasAttribute('onclick')) {
                        $onclick = (string) $node->getAttribute('onclick');
                        if (preg_match_all('/[\'"]([^\'"]+\.html?(?:[?#][^\'"]*)?)[\'"]/i', $onclick, $onclick_matches) && !empty($onclick_matches[1])) {
                            $raw_hrefs = array_merge($raw_hrefs, $onclick_matches[1]);
                        }
                    }
                }
            }
            libxml_clear_errors();
        } else {
            if (preg_match_all('/href\s*=\s*"([^"]+)"/i', $html, $matches_double) && !empty($matches_double[1])) {
                $raw_hrefs = array_merge($raw_hrefs, $matches_double[1]);
            }
            if (preg_match_all("/href\s*=\s*'([^']+)'/i", $html, $matches_single) && !empty($matches_single[1])) {
                $raw_hrefs = array_merge($raw_hrefs, $matches_single[1]);
            }

            if (preg_match_all('/(?:data-href|data-url|data-link)\s*=\s*"([^"]+)"/i', $html, $data_matches_double) && !empty($data_matches_double[1])) {
                $raw_hrefs = array_merge($raw_hrefs, $data_matches_double[1]);
            }
            if (preg_match_all("/(?:data-href|data-url|data-link)\s*=\s*'([^']+)'/i", $html, $data_matches_single) && !empty($data_matches_single[1])) {
                $raw_hrefs = array_merge($raw_hrefs, $data_matches_single[1]);
            }

            if (preg_match_all('/<option[^>]+value\s*=\s*"([^"]+)"/i', $html, $option_matches_double) && !empty($option_matches_double[1])) {
                $raw_hrefs = array_merge($raw_hrefs, $option_matches_double[1]);
            }
            if (preg_match_all("/<option[^>]+value\s*=\s*'([^']+)'/i", $html, $option_matches_single) && !empty($option_matches_single[1])) {
                $raw_hrefs = array_merge($raw_hrefs, $option_matches_single[1]);
            }

            if (preg_match_all('/onclick\s*=\s*"[^"]*([a-z0-9_\/\.\-]+\.html?(?:[?#][^"]*)?)[^"]*"/i', $html, $onclick_matches_double) && !empty($onclick_matches_double[1])) {
                $raw_hrefs = array_merge($raw_hrefs, $onclick_matches_double[1]);
            }
            if (preg_match_all("/onclick\s*=\s*'[^']*([a-z0-9_\\/\\.\\-]+\\.html?(?:[?#][^']*)?)[^']*'/i", $html, $onclick_matches_single) && !empty($onclick_matches_single[1])) {
                $raw_hrefs = array_merge($raw_hrefs, $onclick_matches_single[1]);
            }
        }

        if (empty($raw_hrefs)) {
            return [];
        }

        $links = [];
        foreach ($raw_hrefs as $href) {
            $resolved = octopus_ai_resolve_manual_relative_url($page_url, (string) $href);
            if ($resolved === '') {
                continue;
            }

            $normalized = octopus_ai_normalize_manual_candidate_url($resolved, $lang, true);
            if ($normalized === '' || in_array($normalized, $links, true)) {
                continue;
            }

            $links[] = $normalized;
        }

        return $links;
    }
}

if (!function_exists('octopus_ai_extract_manual_menu_candidates')) {
    /**
     * Haalt relevante links uit navigatie/dropdown-structuren op en geeft die terug met score.
     *
     * @param string $html
     * @param string $page_url
     * @param string $lang
     * @param string $question
     * @param int    $limit
     * @return array<int,array{url:string,score:float}>
     */
    function octopus_ai_extract_manual_menu_candidates($html, $page_url, $lang, $question, $limit = 40)
    {
        $html = (string) $html;
        $question = (string) $question;
        $limit = max(1, (int) $limit);

        if ($html === '' || trim($question) === '') {
            return [];
        }

        $terms = octopus_ai_extract_manual_query_terms($question, $lang, 12);
        if (empty($terms)) {
            return [];
        }

        $candidates = [];

        $register_candidate = static function ($href, $label, $description = '') use (&$candidates, $page_url, $lang, $terms, $question) {
            $href = trim((string) $href);
            if ($href === '') {
                return;
            }

            $resolved = octopus_ai_resolve_manual_relative_url($page_url, $href);
            if ($resolved === '' && preg_match('#^https?://#i', $href)) {
                $resolved = esc_url_raw($href);
            }

            $resolved = octopus_ai_normalize_manual_candidate_url($resolved, $lang, false);
            if ($resolved === '') {
                return;
            }

            $label = trim((string) $label);
            $description = trim((string) $description);

            $composite_text = trim($label . ' ' . $description);
            $normalized_text = function_exists('octopus_ai_normalize_text_for_compare')
                ? octopus_ai_normalize_text_for_compare($composite_text)
                : strtolower($composite_text);
            $normalized_url = function_exists('octopus_ai_normalize_text_for_compare')
                ? octopus_ai_normalize_text_for_compare(str_replace(['-', '_', '/', '.'], ' ', $resolved))
                : strtolower($resolved);

            $score = 0.0;
            foreach ($terms as $term) {
                $term = trim((string) $term);
                if ($term === '') {
                    continue;
                }

                if ($normalized_text !== '') {
                    $text_hits = substr_count($normalized_text, $term);
                    if ($text_hits > 0) {
                        $score += (float) ($text_hits * 6.0);
                    }
                }

                if ($normalized_url !== '') {
                    $url_hits = substr_count($normalized_url, $term);
                    if ($url_hits > 0) {
                        $score += (float) ($url_hits * 2.5);
                    }
                }
            }

            if ($composite_text !== '' && function_exists('octopus_ai_score_manual_snippet')) {
                $score += (float) (0.22 * octopus_ai_score_manual_snippet($question, $composite_text, $resolved));
            }

            if ($score <= 0.0) {
                return;
            }

            if (!isset($candidates[$resolved]) || $score > (float) $candidates[$resolved]['score']) {
                $candidates[$resolved] = [
                    'url' => $resolved,
                    'score' => $score,
                ];
            }
        };

        if (class_exists('DOMDocument')) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();

            if (@$dom->loadHTML($html)) {
                $anchors = $dom->getElementsByTagName('a');
                foreach ($anchors as $anchor) {
                    if (!$anchor instanceof DOMElement || !$anchor->hasAttribute('href')) {
                        continue;
                    }

                    $label = trim((string) $anchor->textContent);
                    $description = '';

                    $ancestor = $anchor->parentNode;
                    $max_walk = 4;
                    while ($max_walk > 0 && $ancestor instanceof DOMElement) {
                        if ($ancestor->hasAttribute('data-desc')) {
                            $description = (string) $ancestor->getAttribute('data-desc');
                            break;
                        }
                        $ancestor = $ancestor->parentNode;
                        $max_walk--;
                    }

                    $register_candidate($anchor->getAttribute('href'), $label, $description);
                }

                $options = $dom->getElementsByTagName('option');
                foreach ($options as $option) {
                    if (!$option instanceof DOMElement) {
                        continue;
                    }

                    $href = '';
                    foreach (['value', 'data-href', 'data-url', 'data-link'] as $attr_name) {
                        if ($option->hasAttribute($attr_name)) {
                            $href = (string) $option->getAttribute($attr_name);
                            if ($href !== '') {
                                break;
                            }
                        }
                    }

                    if ($href === '') {
                        continue;
                    }

                    $register_candidate($href, (string) $option->textContent, '');
                }

                $nodes = $dom->getElementsByTagName('*');
                foreach ($nodes as $node) {
                    if (!$node instanceof DOMElement) {
                        continue;
                    }

                    foreach (['data-href', 'data-url', 'data-link'] as $attr_name) {
                        if ($node->hasAttribute($attr_name)) {
                            $register_candidate(
                                (string) $node->getAttribute($attr_name),
                                (string) $node->textContent,
                                (string) $node->getAttribute('data-desc')
                            );
                        }
                    }
                }
            }
            libxml_clear_errors();
        } else {
            if (preg_match_all('/<a\b[^>]*href\s*=\s*"([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $anchor_double, PREG_SET_ORDER)) {
                foreach ($anchor_double as $match) {
                    $label = trim(wp_strip_all_tags((string) ($match[2] ?? ''), true));
                    $register_candidate((string) ($match[1] ?? ''), $label, '');
                }
            }

            if (preg_match_all("/<a\\b[^>]*href\\s*=\\s*'([^']+)'[^>]*>(.*?)<\\/a>/is", $html, $anchor_single, PREG_SET_ORDER)) {
                foreach ($anchor_single as $match) {
                    $label = trim(wp_strip_all_tags((string) ($match[2] ?? ''), true));
                    $register_candidate((string) ($match[1] ?? ''), $label, '');
                }
            }

            if (preg_match_all('/<option[^>]+value\s*=\s*"([^"]+)"[^>]*>(.*?)<\/option>/is', $html, $option_double, PREG_SET_ORDER)) {
                foreach ($option_double as $match) {
                    $label = trim(wp_strip_all_tags((string) ($match[2] ?? ''), true));
                    $register_candidate((string) ($match[1] ?? ''), $label, '');
                }
            }

            if (preg_match_all("/<option[^>]+value\\s*=\\s*'([^']+)'[^>]*>(.*?)<\\/option>/is", $html, $option_single, PREG_SET_ORDER)) {
                foreach ($option_single as $match) {
                    $label = trim(wp_strip_all_tags((string) ($match[2] ?? ''), true));
                    $register_candidate((string) ($match[1] ?? ''), $label, '');
                }
            }

            if (preg_match_all('/(?:data-href|data-url|data-link)\s*=\s*"([^"]+)"/i', $html, $data_double) && !empty($data_double[1])) {
                foreach ($data_double[1] as $href) {
                    $register_candidate((string) $href, '', '');
                }
            }

            if (preg_match_all("/(?:data-href|data-url|data-link)\\s*=\\s*'([^']+)'/i", $html, $data_single) && !empty($data_single[1])) {
                foreach ($data_single[1] as $href) {
                    $register_candidate((string) $href, '', '');
                }
            }
        }

        if (empty($candidates)) {
            return [];
        }

        $rows = array_values($candidates);
        usort(
            $rows,
            static function ($a, $b) {
                $score_a = isset($a['score']) ? (float) $a['score'] : 0.0;
                $score_b = isset($b['score']) ? (float) $b['score'] : 0.0;
                if ($score_a === $score_b) {
                    return 0;
                }
                return ($score_a < $score_b) ? 1 : -1;
            }
        );

        return array_slice($rows, 0, $limit);
    }
}

if (!function_exists('octopus_ai_crawl_manual_domain_urls')) {
    /**
     * Crawlt onderliggende links op het manual-domein met veilige limieten.
     *
     * @param string $lang
     * @param int    $max_urls
     * @param int    $max_depth
     * @return array<int,string>
     */
    function octopus_ai_crawl_manual_domain_urls($lang, $max_urls = 220, $max_depth = 2)
    {
        $max_urls = max(25, (int) $max_urls);
        $max_depth = max(0, min(4, (int) $max_depth));
        $time_budget = (float) apply_filters('octopus_ai_live_manual_domain_crawl_time_budget', 12.0, $lang);

        $base_url = trailingslashit(octopus_ai_get_manual_base_url($lang));
        $seed_candidates = [
            $base_url,
            $base_url . 'index.html',
            $base_url . 'hmcontent.htm',
            $base_url . 'hmkwindex.htm',
        ];

        $seed_urls = [];
        foreach ($seed_candidates as $seed_candidate) {
            $normalized_seed = octopus_ai_normalize_manual_candidate_url($seed_candidate, $lang, true);
            if ($normalized_seed !== '' && !in_array($normalized_seed, $seed_urls, true)) {
                $seed_urls[] = $normalized_seed;
            }
        }

        if (empty($seed_urls)) {
            return [];
        }

        $queue = [];
        $queued = [];
        foreach ($seed_urls as $seed_url) {
            $queue[] = [
                'url' => $seed_url,
                'depth' => 0,
            ];
            $queued[$seed_url] = true;
        }

        $visited = [];
        $found = [];
        $max_fetches = max($max_urls, 80);
        $started_at = microtime(true);

        while (!empty($queue) && count($visited) < $max_fetches && count($found) < $max_urls) {
            if ($time_budget > 0 && (microtime(true) - $started_at) >= $time_budget) {
                break;
            }

            $current = array_shift($queue);
            if (!is_array($current)) {
                continue;
            }

            $url = esc_url_raw((string) ($current['url'] ?? ''));
            $depth = (int) ($current['depth'] ?? 0);
            if ($url === '' || isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $download = octopus_ai_download_manual_page($url);
            if (!is_array($download) || (int) ($download['status'] ?? 0) !== 200) {
                continue;
            }

            $indexable = octopus_ai_normalize_manual_candidate_url($url, $lang, false);
            if ($indexable !== '' && !in_array($indexable, $found, true)) {
                $found[] = $indexable;
            }

            if ($depth >= $max_depth) {
                continue;
            }

            $links = octopus_ai_extract_manual_links_from_html((string) ($download['body'] ?? ''), $url, $lang);
            foreach ($links as $link) {
                if ($link === '' || isset($visited[$link]) || isset($queued[$link])) {
                    continue;
                }

                $queued[$link] = true;
                $queue[] = [
                    'url' => $link,
                    'depth' => $depth + 1,
                ];
            }
        }

        return array_values(array_unique($found));
    }
}

if (!function_exists('octopus_ai_get_manual_domain_urls')) {
    /**
     * Geeft alle gekende manual-URL's terug (sitemap + interne crawl).
     *
     * @param string $lang
     * @return array<int,string>
     */
    function octopus_ai_get_manual_domain_urls($lang)
    {
        $lang_key = strtoupper((string) $lang) === 'FR' ? 'fr' : 'nl';
        $base = trailingslashit(octopus_ai_get_manual_base_url($lang));
        $base_hash = substr(md5($base), 0, 12);
        $cache_key = 'octopus_ai_manual_domain_urls_v3_' . $lang_key . '_' . $base_hash;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $sitemap_urls = octopus_ai_get_manual_sitemap_urls($lang);

        $crawl_enabled = apply_filters('octopus_ai_live_manual_domain_crawl_enabled', true, $lang);
        $crawl_urls = [];
        if ($crawl_enabled) {
            $crawl_max_urls = (int) apply_filters('octopus_ai_live_manual_domain_crawl_max_urls', 220, $lang);
            $crawl_max_depth = (int) apply_filters('octopus_ai_live_manual_domain_crawl_max_depth', 2, $lang);
            $crawl_urls = octopus_ai_crawl_manual_domain_urls($lang, $crawl_max_urls, $crawl_max_depth);
        }

        $combined = array_values(array_filter(array_unique(array_merge($sitemap_urls, $crawl_urls))));
        set_transient($cache_key, $combined, 6 * HOUR_IN_SECONDS);
        return $combined;
    }
}

if (!function_exists('octopus_ai_extract_manual_query_terms')) {
    /**
     * Extraheert relevante zoektermen uit de vraag.
     *
     * @param string $question
     * @param string $lang
     * @param int    $limit
     * @return array<int,string>
     */
    function octopus_ai_extract_manual_query_terms($question, $lang, $limit = 8)
    {
        $limit = max(1, (int) $limit);
        $normalized = function_exists('octopus_ai_normalize_text_for_compare')
            ? octopus_ai_normalize_text_for_compare((string) $question)
            : strtolower(trim((string) $question));

        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [];
        }

        $lang_key = strtoupper((string) $lang) === 'FR' ? 'fr' : 'nl';
        $stopwords_nl = ['een', 'het', 'de', 'en', 'of', 'ik', 'je', 'jij', 'u', 'wij', 'met', 'voor', 'over', 'van', 'in', 'op', 'te', 'hoe', 'wat', 'waar', 'kan', 'kunnen', 'wil', 'wilt', 'als', 'dan', 'dit', 'dat', 'bij', 'naar'];
        $stopwords_fr = ['le', 'la', 'les', 'de', 'du', 'des', 'et', 'ou', 'je', 'tu', 'vous', 'nous', 'avec', 'pour', 'sur', 'dans', 'est', 'sont', 'comment', 'quoi', 'ou', 'peux', 'peut', 'veux', 'si', 'ce', 'cet', 'cette'];
        $stopwords = $lang_key === 'fr' ? $stopwords_fr : $stopwords_nl;

        $provider_stopwords = function_exists('octopus_ai_get_provider_retrieval_stopwords')
            ? octopus_ai_get_provider_retrieval_stopwords()
            : [];
        $provider_profile = function_exists('octopus_ai_get_provider_profile')
            ? octopus_ai_get_provider_profile()
            : [];
        $provider_brand_terms = isset($provider_profile['brand_terms']) && is_array($provider_profile['brand_terms'])
            ? $provider_profile['brand_terms']
            : [];
        $provider_stopwords = array_merge(
            is_array($provider_stopwords) ? $provider_stopwords : [],
            is_array($provider_brand_terms) ? $provider_brand_terms : []
        );
        foreach ($provider_stopwords as $provider_stopword) {
            $provider_stopword = function_exists('octopus_ai_normalize_text_for_compare')
                ? octopus_ai_normalize_text_for_compare((string) $provider_stopword)
                : strtolower(trim((string) $provider_stopword));
            if ($provider_stopword === '' || in_array($provider_stopword, $stopwords, true)) {
                continue;
            }
            $stopwords[] = $provider_stopword;
        }

        $terms = [];
        foreach ($parts as $part) {
            $term = trim((string) $part);
            if ($term === '' || strlen($term) < 3 || is_numeric($term) || in_array($term, $stopwords, true)) {
                continue;
            }

            if (!in_array($term, $terms, true)) {
                $terms[] = $term;
            }

            if (count($terms) >= $limit) {
                break;
            }
        }

        // Breid zoektermen uit via provider-topic termen zodat core geen domeinspecifieke woorden hoeft te hardcoden.
        $topic_term_buckets = [];
        $append_topic_bucket = static function ($topic_key, $terms_to_add) use (&$topic_term_buckets) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '' || !is_array($terms_to_add)) {
                return;
            }

            if (!isset($topic_term_buckets[$topic_key]) || !is_array($topic_term_buckets[$topic_key])) {
                $topic_term_buckets[$topic_key] = [];
            }

            foreach ($terms_to_add as $bucket_term) {
                $bucket_term = function_exists('octopus_ai_normalize_text_for_compare')
                    ? octopus_ai_normalize_text_for_compare((string) $bucket_term)
                    : strtolower(trim((string) $bucket_term));
                if ($bucket_term === '' || strlen($bucket_term) < 3 || in_array($bucket_term, $topic_term_buckets[$topic_key], true)) {
                    continue;
                }
                $topic_term_buckets[$topic_key][] = $bucket_term;
            }
        };

        $provider_topic_terms = function_exists('octopus_ai_get_provider_topic_terms_map')
            ? octopus_ai_get_provider_topic_terms_map()
            : [];
        if (is_array($provider_topic_terms)) {
            foreach ($provider_topic_terms as $provider_topic_key => $provider_terms) {
                $append_topic_bucket($provider_topic_key, $provider_terms);
            }
        }

        $provider_retrieval_topic_terms = function_exists('octopus_ai_get_provider_retrieval_topic_terms_map')
            ? octopus_ai_get_provider_retrieval_topic_terms_map()
            : [];
        if (is_array($provider_retrieval_topic_terms)) {
            foreach ($provider_retrieval_topic_terms as $provider_topic_key => $provider_terms) {
                $append_topic_bucket($provider_topic_key, $provider_terms);
            }
        }

        if (function_exists('octopus_ai_get_provider_topic_labels_map')) {
            $provider_labels = octopus_ai_get_provider_topic_labels_map();
            if (is_array($provider_labels)) {
                foreach ($provider_labels as $provider_topic_key => $label_pair) {
                    if (!is_array($label_pair)) {
                        continue;
                    }
                    $append_topic_bucket($provider_topic_key, [
                        $provider_topic_key,
                        $label_pair['nl'] ?? '',
                        $label_pair['fr'] ?? '',
                    ]);
                }
            }
        }

        if (empty($topic_term_buckets) && function_exists('octopus_ai_get_default_provider_profile')) {
            $provider_defaults = octopus_ai_get_default_provider_profile();
            $default_topic_terms = isset($provider_defaults['topic_terms']) && is_array($provider_defaults['topic_terms'])
                ? $provider_defaults['topic_terms']
                : [];
            foreach ($default_topic_terms as $provider_topic_key => $provider_terms) {
                $append_topic_bucket($provider_topic_key, $provider_terms);
            }
        }

        $expanded = $terms;
        $max_expanded = max($limit, $limit * 2);
        $max_expanded = max(10, min(40, $max_expanded));
        foreach ($terms as $term) {
            foreach ($topic_term_buckets as $bucket_terms) {
                if (!is_array($bucket_terms) || empty($bucket_terms) || !in_array($term, $bucket_terms, true)) {
                    continue;
                }

                foreach ($bucket_terms as $bucket_term) {
                    if ($bucket_term === '' || in_array($bucket_term, $expanded, true)) {
                        continue;
                    }
                    $expanded[] = $bucket_term;
                    if (count($expanded) >= $max_expanded) {
                        break 3;
                    }
                }
            }
        }

        if (count($expanded) > $max_expanded) {
            $expanded = array_slice($expanded, 0, $max_expanded);
        }

        return array_values(array_unique($expanded));
    }
}

if (!function_exists('octopus_ai_score_manual_url_for_question')) {
    /**
     * Eenvoudige URL-score op basis van trefwoorden uit de vraag.
     *
     * @param string $question
     * @param string $url
     * @param string $lang
     * @return float
     */
    function octopus_ai_score_manual_url_for_question($question, $url, $lang)
    {
        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return 0.0;
        }

        $terms = octopus_ai_extract_manual_query_terms($question, $lang, 10);
        if (empty($terms)) {
            return 0.0;
        }

        $url_norm = function_exists('octopus_ai_normalize_text_for_compare')
            ? octopus_ai_normalize_text_for_compare(str_replace(['-', '_', '/', '.'], ' ', $url))
            : strtolower((string) $url);

        $score = 0.0;
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            $hits = substr_count($url_norm, $term);
            if ($hits > 0) {
                $score += (3.0 * $hits);
            }
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if (preg_match('/(hmsearch|search|index)/i', $path)) {
            $score -= 2.0;
        }

        if (preg_match('/\.html?$/i', $path)) {
            $score += 0.5;
        }

        return $score;
    }
}

if (!function_exists('octopus_ai_discover_live_manual_urls')) {
    /**
     * Ontdekt relevante handleidingpagina's op basis van live sitemap en vraag.
     *
     * @param string $question
     * @param string $lang
     * @param int    $limit
     * @return array<int,string>
     */
    function octopus_ai_discover_live_manual_urls($question, $lang, $limit = 5)
    {
        $limit = max(1, (int) $limit);
        $lang_key = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';
        $normalized_question = function_exists('octopus_ai_normalize_text_for_compare')
            ? octopus_ai_normalize_text_for_compare((string) $question)
            : strtolower(trim((string) $question));
        $base_hash = substr(md5((string) octopus_ai_get_manual_base_url($lang)), 0, 12);
        $cache_key = 'octopus_ai_live_discover_v3_' . strtolower($lang_key) . '_' . $base_hash . '_' . md5($normalized_question . '|' . $limit);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $urls = octopus_ai_get_manual_domain_urls($lang);
        if (empty($urls)) {
            return [];
        }

        $url_scored = [];
        foreach ($urls as $url) {
            $url = esc_url_raw((string) $url);
            if ($url === '' || !octopus_ai_is_allowed_manual_url($url, $lang)) {
                continue;
            }

            $url_score = octopus_ai_score_manual_url_for_question($question, $url, $lang);
            $url_scored[] = [
                'url' => $url,
                'score' => (float) $url_score,
            ];
        }

        usort(
            $url_scored,
            static function ($a, $b) {
                $score_a = isset($a['score']) ? (float) $a['score'] : 0.0;
                $score_b = isset($b['score']) ? (float) $b['score'] : 0.0;
                if ($score_a === $score_b) {
                    return 0;
                }
                return ($score_a < $score_b) ? 1 : -1;
            }
        );

        $prefetch_count = (int) apply_filters('octopus_ai_live_manual_discovery_prefetch_count', max(40, $limit * 12), $lang, $question);
        $prefetch_count = max($limit, min(120, $prefetch_count));
        $prefetch_rows = array_slice($url_scored, 0, $prefetch_count);

        $top_url_score = isset($url_scored[0]['score']) ? (float) $url_scored[0]['score'] : 0.0;
        if ($top_url_score <= 0.0 && count($url_scored) > $prefetch_count) {
            // Als URL-signalen niet helpen, spreid de selectie over de volledige URL-set.
            $prefetch_rows = [];
            $total_urls = count($url_scored);
            $stride = max(1, (int) floor($total_urls / $prefetch_count));

            for ($i = 0; $i < $total_urls && count($prefetch_rows) < $prefetch_count; $i += $stride) {
                $prefetch_rows[] = $url_scored[$i];
            }

            for ($i = 0; $i < $total_urls && count($prefetch_rows) < $prefetch_count; $i++) {
                if (!in_array($url_scored[$i], $prefetch_rows, true)) {
                    $prefetch_rows[] = $url_scored[$i];
                }
            }
        }

        $terms = octopus_ai_extract_manual_query_terms($question, $lang, 8);
        $content_scored = [];
        $menu_scored = [];
        $started_at = microtime(true);
        $time_budget = (float) apply_filters('octopus_ai_live_manual_discovery_time_budget', 10.0, $lang, $question);

        foreach ($prefetch_rows as $row) {
            if ($time_budget > 0 && (microtime(true) - $started_at) >= $time_budget) {
                break;
            }

            $url = esc_url_raw((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $url_score = isset($row['score']) ? (float) $row['score'] : 0.0;
            $download = octopus_ai_download_manual_page($url);
            if (!is_array($download) || (int) ($download['status'] ?? 0) !== 200) {
                continue;
            }

            $snippet = octopus_ai_normalize_manual_text((string) ($download['body'] ?? ''));
            if ($snippet === '') {
                continue;
            }

            $content_score = function_exists('octopus_ai_score_manual_snippet')
                ? (float) octopus_ai_score_manual_snippet($question, $snippet, $url)
                : 0.0;

            $combined_score = ($content_score * 1.35) + $url_score;

            if (!empty($terms) && function_exists('octopus_ai_normalize_text_for_compare')) {
                $snippet_norm = octopus_ai_normalize_text_for_compare($snippet);
                $url_norm = octopus_ai_normalize_text_for_compare(str_replace(['-', '_', '/', '.'], ' ', $url));
                $matched_terms = 0;
                foreach ($terms as $term) {
                    $term = trim((string) $term);
                    if ($term === '') {
                        continue;
                    }
                    if (strpos($snippet_norm, $term) !== false || strpos($url_norm, $term) !== false) {
                        $matched_terms++;
                    }
                }

                if ($matched_terms > 0) {
                    $combined_score += (float) ($matched_terms * 4.0);
                }

                if ($matched_terms >= 2) {
                    $combined_score += 5.0;
                }
            }

            $content_scored[] = [
                'url' => $url,
                'score' => $combined_score,
            ];

            $menu_candidates = octopus_ai_extract_manual_menu_candidates((string) ($download['body'] ?? ''), $url, $lang, $question, 20);
            if (!empty($menu_candidates)) {
                foreach ($menu_candidates as $menu_candidate) {
                    $menu_url = esc_url_raw((string) ($menu_candidate['url'] ?? ''));
                    $menu_score = isset($menu_candidate['score']) ? (float) $menu_candidate['score'] : 0.0;
                    if ($menu_url === '' || $menu_score <= 0.0) {
                        continue;
                    }

                    // Boost links die uit dropdown/TOC-navigatie komen, zodat submenu-topics sneller gekozen worden.
                    $menu_scored[] = [
                        'url' => $menu_url,
                        'score' => (float) (($combined_score * 0.35) + $menu_score + 3.0),
                    ];
                }
            }
        }

        // Fallback op URL-ranking als content-ranking niets oplevert (bv. tijdelijke fetchfouten).
        $ranking_base = !empty($content_scored) ? $content_scored : $url_scored;
        $ranking_rows = array_merge($ranking_base, $menu_scored);
        if (empty($ranking_rows)) {
            $ranking_rows = $url_scored;
        }

        $ranking_map = [];
        foreach ($ranking_rows as $row) {
            $candidate_url = esc_url_raw((string) ($row['url'] ?? ''));
            $candidate_score = isset($row['score']) ? (float) $row['score'] : 0.0;
            if ($candidate_url === '' || !octopus_ai_is_allowed_manual_url($candidate_url, $lang)) {
                continue;
            }

            if (!isset($ranking_map[$candidate_url]) || $candidate_score > (float) $ranking_map[$candidate_url]['score']) {
                $ranking_map[$candidate_url] = [
                    'url' => $candidate_url,
                    'score' => $candidate_score,
                ];
            }
        }

        $ranking = array_values($ranking_map);

        usort(
            $ranking,
            static function ($a, $b) {
                $score_a = isset($a['score']) ? (float) $a['score'] : 0.0;
                $score_b = isset($b['score']) ? (float) $b['score'] : 0.0;
                if ($score_a === $score_b) {
                    return 0;
                }
                return ($score_a < $score_b) ? 1 : -1;
            }
        );

        $selected = [];
        foreach ($ranking as $row) {
            $url = esc_url_raw((string) ($row['url'] ?? ''));
            if ($url === '' || in_array($url, $selected, true)) {
                continue;
            }

            $selected[] = $url;
            if (count($selected) >= $limit) {
                break;
            }
        }

        set_transient($cache_key, $selected, 30 * MINUTE_IN_SECONDS);
        return $selected;
    }
}

/**
 * Downloadt de HTML van een handleidingpagina met caching.
 *
 * @param string $url
 * @return array{status:int, body:string, error:string, duration:float}
 */
function octopus_ai_download_manual_page($url)
{
    $cache_key = 'octopus_ai_manual_' . md5($url);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $user_agent_url = function_exists('home_url') ? home_url('/') : 'https://localhost/';
    $user_agent_url = trim((string) $user_agent_url);
    if ($user_agent_url === '') {
        $user_agent_url = 'https://localhost/';
    }

    $start_time = microtime(true);
    $response = wp_remote_get($url, [
        'timeout'     => 8,
        'redirection' => 3,
        'headers'     => [
            'User-Agent' => 'OctopusAIChatbot/1.0 (+' . $user_agent_url . ')',
        ],
    ]);
    $duration = microtime(true) - $start_time;

    if ($duration > 3) {
        error_log(sprintf('[Octopus AI] Trage handleiding-fetch (%.2fs) voor %s', $duration, $url));
    }

    if (is_wp_error($response)) {
        $result = [
            'status'   => 0,
            'body'     => '',
            'error'    => $response->get_error_message(),
            'duration' => $duration,
        ];
        set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
        return $result;
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);
    $error  = '';

    if ($status !== 200) {
        $error = wp_remote_retrieve_response_message($response);
    }

    $result = [
        'status'   => $status,
        'body'     => ($status === 200 && is_string($body)) ? $body : '',
        'error'    => $error,
        'duration' => $duration,
    ];

    $ttl = ($status === 200) ? HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
    set_transient($cache_key, $result, $ttl);

    return $result;
}

/**
 * Zet HTML om naar genormaliseerde tekst die geschikt is voor de prompt.
 *
 * @param string $html
 * @return string
 */
function octopus_ai_normalize_manual_text($html)
{
    $text = wp_strip_all_tags($html, true);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    $text = preg_replace('/\s{2,}/', ' ', $text);
    $text = trim($text);

    // Beperk tot 2000 karakters om promptinflatie te voorkomen.
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, 2000);
    } else {
        $text = substr($text, 0, 2000);
    }

    return trim($text);
}

if (!function_exists('octopus_ai_normalize_text_for_compare')) {
    function octopus_ai_normalize_text_for_compare($text)
    {
        $text = strtolower(trim((string) $text));

        if ($text === '') {
            return '';
        }

        if (class_exists('Transliterator')) {
            $transliterator = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($transliterator) {
                $text = $transliterator->transliterate($text);
            }
        } else {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }

        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }
}

if (!function_exists('octopus_ai_score_manual_snippet')) {
    function octopus_ai_score_manual_snippet($question, $snippet, $url = '')
    {
        $normalized_question = octopus_ai_normalize_text_for_compare($question);
        $normalized_snippet  = octopus_ai_normalize_text_for_compare($snippet);

        if ($normalized_question === '' || $normalized_snippet === '') {
            return 0.0;
        }

        $keywords = preg_split('/\s+/', $normalized_question, -1, PREG_SPLIT_NO_EMPTY);
        $keywords = array_filter(
            array_unique($keywords),
            static function ($keyword) {
                return strlen($keyword) >= 3;
            }
        );

        $score = 0.0;
        $normalized_url = $url !== '' ? octopus_ai_normalize_text_for_compare($url) : '';

        foreach ($keywords as $keyword) {
            $occurrences = substr_count($normalized_snippet, $keyword);
            if ($occurrences > 0) {
                $score += 6 * $occurrences;
            }

            if ($normalized_url !== '' && strpos($normalized_url, $keyword) !== false) {
                $score += 2.0;
            }
        }

        similar_text($normalized_snippet, $normalized_question, $percentage_match);
        $score += (float) $percentage_match;

        if ($snippet !== '' && substr_count($snippet, '.') >= 1) {
            $score += 1.0;
        }

        return $score;
    }
}

/**
 * Combineert live handleidingstekst voor gebruik als extra context.
 *
 * @param array  $metadata_chunks
 * @param string $lang
 * @param string $question
 * @return array{
 *     text:string,
 *     sources:array<int,string>,
 *     errors:array<int,array{url:string,status:int,error:string}>,
 *     best_source:string,
 *     best_score:float,
 *     snippets:array<int,string>
 * }
 */
function octopus_ai_fetch_live_manual_context(array $metadata_chunks, $lang, $question = '', array $options = [])
{
    $options = wp_parse_args($options, [
        'strict_live' => false,
        'max_sources' => 5,
    ]);

    $strict_live = !empty($options['strict_live']);
    $max_sources = max(1, (int) ($options['max_sources'] ?? 5));

    $urls = $strict_live
        ? octopus_ai_get_manual_priority_urls($lang)
        : octopus_ai_build_manual_urls($metadata_chunks, $lang);

    $discovered_urls = octopus_ai_discover_live_manual_urls($question, $lang, $max_sources);
    if (!empty($discovered_urls)) {
        foreach ($discovered_urls as $candidate_url) {
            if (!in_array($candidate_url, $urls, true)) {
                $urls[] = $candidate_url;
            }
            if (count($urls) >= $max_sources) {
                break;
            }
        }
    }

    if (!empty($urls) && count($urls) > $max_sources) {
        $urls = array_slice($urls, 0, $max_sources);
    }

    if (empty($urls)) {
        return [
            'text'    => '',
            'sources' => [],
            'best_source' => '',
            'best_score'  => 0.0,
            'snippets' => [],
            'errors'  => [],
        ];
    }

    $scored_snippets = [];
    $errors          = [];
    $order           = 0;

    foreach ($urls as $url) {
        $result = octopus_ai_download_manual_page($url);

        if ($result['status'] === 200 && $result['body'] !== '') {
            $snippet = octopus_ai_normalize_manual_text($result['body']);
            if ($snippet !== '') {
                $score = $question !== ''
                    ? octopus_ai_score_manual_snippet($question, $snippet, $url)
                    : 0.0;

                $scored_snippets[] = [
                    'url'     => $url,
                    'text'    => sprintf("Bron: %s\n%s", $url, $snippet),
                    'snippet' => $snippet,
                    'score'   => $score,
                    'order'   => $order,
                ];
            }
        } elseif ($result['status'] === 403) {
            $errors[] = [
                'url'    => $url,
                'status' => $result['status'],
                'error'  => 'Toegang vereist',
            ];
        } else {
            $errors[] = [
                'url'    => $url,
                'status' => $result['status'],
                'error'  => $result['error'],
            ];
        }
        $order++;
    }

    if (empty($scored_snippets)) {
        return [
            'text'    => '',
            'sources' => [],
            'best_source' => '',
            'best_score'  => 0.0,
            'snippets'    => [],
            'errors'  => $errors,
        ];
    }

    usort(
        $scored_snippets,
        static function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['order'] <=> $b['order'];
            }

            return ($b['score'] <=> $a['score']);
        }
    );

    $best = $scored_snippets[0];

    $filtered_sources = array_values(array_filter(
        array_map(
            static function ($item) {
                return $item['url'];
            },
            $scored_snippets
        ),
        static function ($url) use ($lang) {
            return octopus_ai_is_allowed_manual_url($url, $lang);
        }
    ));

    return [
        'text'    => $best['text'],
        'sources' => $filtered_sources,
        'best_source' => $best['url'],
        'best_score'  => (float) $best['score'],
        'snippets'    => array_map(
            static function ($item) {
                return $item['text'];
            },
            $scored_snippets
        ),
        'errors'  => $errors,
    ];
}
