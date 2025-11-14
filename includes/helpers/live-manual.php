<?php
if (!defined('ABSPATH')) exit;

/**
 * Bouwt mogelijke handleiding-URL's op basis van chunk-metadata.
 *
 * @param array  $metadata_chunks
 * @param string $lang
 * @return array
 */
function octopus_ai_build_manual_urls(array $metadata_chunks, $lang)
{
    $urls = [];
    $lang = strtoupper($lang) === 'FR' ? 'FR' : 'NL';

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
            $candidates[] = sprintf('https://login.octopus.be/manual/%s/%s', $lang, $page_slug);
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
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

        if (count($urls) >= 3) {
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

/**
 * Combineert live handleidingstekst voor gebruik als extra context.
 *
 * @param array  $metadata_chunks
 * @param string $lang
 * @return array{text:string, sources:array<int,string>, errors:array<int,array{url:string,status:int,error:string}>}
 */
function octopus_ai_fetch_live_manual_context(array $metadata_chunks, $lang)
{
    $urls = octopus_ai_build_manual_urls($metadata_chunks, $lang);
    if (empty($urls)) {
        return [
            'text'    => '',
            'sources' => [],
            'errors'  => [],
        ];
    }

    $snippets = [];
    $errors   = [];

    foreach ($urls as $url) {
        $result = octopus_ai_download_manual_page($url);

        if ($result['status'] === 200 && $result['body'] !== '') {
            $snippet = octopus_ai_normalize_manual_text($result['body']);
            if ($snippet !== '') {
                $snippets[] = sprintf("Bron: %s\n%s", $url, $snippet);
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

        if (count($snippets) >= 2) {
            break;
        }
    }

    return [
        'text'    => implode("\n\n", $snippets),
        'sources' => array_map(
            static function ($snippet) {
                $parts = explode("\n", $snippet, 2);
                return isset($parts[0]) ? trim(str_replace('Bron: ', '', $parts[0])) : '';
            },
            $snippets
        ),
        'errors'  => $errors,
    ];
}
