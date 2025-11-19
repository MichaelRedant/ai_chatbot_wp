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

    return $lang_key === 'fr'
        ? 'https://login.octopus.be/manual/FR/'
        : 'https://login.octopus.be/manual/NL/';
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
    $base_url = octopus_ai_get_manual_base_url($lang);

    foreach ($metadata_chunks as $meta) {
        if (!is_array($meta)) {
            continue;
        }

        $source_url = isset($meta['source_url']) ? trim((string) $meta['source_url']) : '';
        $page_slug  = isset($meta['page_slug']) ? trim((string) $meta['page_slug']) : '';

        $candidates = [];
        if ($source_url !== '') {
            $candidates[] = $source_url;
        }

        if ($page_slug !== '') {
            $page_slug = ltrim($page_slug, '/');
            $candidates[] = trailingslashit($base_url) . $page_slug;
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            if (!octopus_ai_is_allowed_manual_url($candidate, $lang)) {
                continue;
            }

            if (function_exists('wp_http_validate_url')) {
                if (!wp_http_validate_url($candidate)) {
                    continue;
                }
            }

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

    $start_time = microtime(true);
    $response = wp_remote_get($url, [
        'timeout'     => 8,
        'redirection' => 3,
        'headers'     => [
            'User-Agent' => 'OctopusAIChatbot/1.0 (+https://octopus.be)',
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
function octopus_ai_fetch_live_manual_context(array $metadata_chunks, $lang, $question = '')
{
    $urls = octopus_ai_build_manual_urls($metadata_chunks, $lang);
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
