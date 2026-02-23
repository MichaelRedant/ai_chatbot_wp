<?php
// Veiligheid
if (!defined('ABSPATH')) exit;

if (!function_exists('octopus_ai_normalize_search_text')) {
    function octopus_ai_normalize_search_text($string)
    {
        $string = (string) $string;
        if ($string === '') {
            return '';
        }

        if (class_exists('Transliterator')) {
            $transliterator = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($transliterator) {
                $string = $transliterator->transliterate($string);
            }
        } else {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $string);
            if ($converted !== false) {
                $string = $converted;
            }
        }

        return strtolower($string);
    }
}

if (!function_exists('octopus_ai_get_chunk_index')) {
    /**
     * Bouwt een compacte zoekindex op met metadata en content-preview.
     *
     * @param string $chunks_dir
     * @return array{signature:string, entries:array<int,array<string,mixed>>}
     */
    function octopus_ai_get_chunk_index($chunks_dir)
    {
        $files = glob(trailingslashit($chunks_dir) . '*.json');
        if (!is_array($files) || empty($files)) {
            return [
                'signature' => 'empty',
                'entries' => [],
            ];
        }

        sort($files, SORT_STRING);

        $signature_parts = [];
        foreach ($files as $file) {
            $signature_parts[] = basename($file) . ':' . (int) @filemtime($file) . ':' . (int) @filesize($file);
        }
        $signature = md5(implode('|', $signature_parts));
        $cache_key = 'octopus_ai_chunk_index_' . md5($chunks_dir . '|' . $signature);

        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['entries'], $cached['signature'])) {
            return $cached;
        }

        $entries = [];
        foreach ($files as $chunk_file) {
            $json_raw = @file_get_contents($chunk_file);
            if ($json_raw === false || $json_raw === '') {
                continue;
            }

            $json = json_decode($json_raw, true);
            if (!is_array($json) || empty($json['content'])) {
                continue;
            }

            $content = trim((string) $json['content']);
            if ($content === '' || strlen($content) < 20) {
                continue;
            }

            $metadata = isset($json['metadata']) && is_array($json['metadata']) ? $json['metadata'] : [];
            $content_preview = $content;
            if (function_exists('mb_substr')) {
                $content_preview = (string) mb_substr($content_preview, 0, 2500);
            } else {
                $content_preview = (string) substr($content_preview, 0, 2500);
            }

            $entries[] = [
                'file' => $chunk_file,
                'modified' => (int) @filemtime($chunk_file),
                'content_preview' => $content_preview,
                'content_preview_norm' => octopus_ai_normalize_search_text($content_preview),
                'section_title_norm' => octopus_ai_normalize_search_text((string) ($metadata['section_title'] ?? '')),
                'page_slug_norm' => octopus_ai_normalize_search_text((string) ($metadata['page_slug'] ?? '')),
                'original_page_norm' => octopus_ai_normalize_search_text((string) ($metadata['original_page'] ?? '')),
                'manual_url_norm' => octopus_ai_normalize_search_text((string) ($metadata['manual_url'] ?? '')),
                'metadata' => [
                    'section_title' => (string) ($metadata['section_title'] ?? ''),
                    'page_slug' => (string) ($metadata['page_slug'] ?? ''),
                    'original_page' => (string) ($metadata['original_page'] ?? ''),
                    'source_url' => (string) ($metadata['source_url'] ?? ''),
                    'manual_url' => (string) ($metadata['manual_url'] ?? ''),
                ],
            ];
        }

        $result = [
            'signature' => $signature,
            'entries' => $entries,
        ];

        // Compacter cachevenster: snel vernieuwen na uploads.
        set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);

        return $result;
    }
}

/**
 * Haalt de meest relevante chunks op obv de gebruikersvraag.
 *
 * @param string $question
 * @param string $topic
 * @return array{context:string, metadata:array{chunks:array<int,array<string,mixed>>, summary:array<string,array<int,string>>}}
 */
function octopus_ai_retrieve_relevant_chunks($question, $topic = '')
{
    $topic_key = is_string($topic) ? strtolower(trim($topic)) : '';
    $upload_dir = wp_upload_dir();
    $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

    if (!file_exists($chunks_dir)) {
        error_log('[Octopus AI] Chunks directory niet gevonden: ' . $chunks_dir);
        return [
            'context' => '',
            'metadata' => [
                'chunks' => [],
                'summary' => [
                    'section_title' => [],
                    'page_slug' => [],
                    'original_page' => [],
                    'source_url' => [],
                    'manual_url' => [],
                ],
            ],
        ];
    }

    $index_data = octopus_ai_get_chunk_index($chunks_dir);
    $index_signature = (string) ($index_data['signature'] ?? 'empty');
    $entries = isset($index_data['entries']) && is_array($index_data['entries']) ? $index_data['entries'] : [];

    if (empty($entries)) {
        return [
            'context' => '',
            'metadata' => [
                'chunks' => [],
                'summary' => [
                    'section_title' => [],
                    'page_slug' => [],
                    'original_page' => [],
                    'source_url' => [],
                    'manual_url' => [],
                ],
            ],
        ];
    }

    $cache_key = 'octopus_ai_chunks_' . md5($question . '||' . $topic_key . '||' . $index_signature);
    $cached = get_transient($cache_key);
    if ($cached && is_array($cached)) {
        return $cached;
    }

    $topic_keywords = [
        'klantenportaal' => ['klantenportaal', 'klant', 'portal', 'login', 'factuur', 'betaling', 'support'],
        'boekhoudprogramma' => ['boekhoud', 'boekhouding', 'boekhoudprogramma', 'btw', 'facturatie', 'journaal', 'balans', 'rapport', 'administratie'],
    ];
    $topic_terms = $topic_key !== '' && isset($topic_keywords[$topic_key]) ? $topic_keywords[$topic_key] : [];

    $normalized_question = octopus_ai_normalize_search_text($question);
    $keywords = preg_split('/\s+/', $normalized_question, -1, PREG_SPLIT_NO_EMPTY);
    $keywords = is_array($keywords) ? $keywords : [];
    $keywords = array_values(array_filter(array_unique($keywords), static function ($kw) {
        return strlen((string) $kw) >= 3;
    }));

    if (empty($keywords) && $normalized_question !== '') {
        $keywords = [$normalized_question];
    }

    $chunks_with_score = [];
    foreach ($entries as $entry) {
        $score = 0;

        $content_norm = (string) ($entry['content_preview_norm'] ?? '');
        $section_norm = (string) ($entry['section_title_norm'] ?? '');
        $slug_norm = (string) ($entry['page_slug_norm'] ?? '');
        $original_norm = (string) ($entry['original_page_norm'] ?? '');
        $manual_norm = (string) ($entry['manual_url_norm'] ?? '');

        foreach ($keywords as $kw) {
            $kw = (string) $kw;
            if ($kw === '') {
                continue;
            }

            if ($content_norm !== '' && strpos($content_norm, $kw) !== false) {
                $score += 1 + min(2, substr_count($content_norm, $kw));
            }
            if ($section_norm !== '' && strpos($section_norm, $kw) !== false) {
                $score += 3;
            }
            if ($slug_norm !== '' && strpos($slug_norm, $kw) !== false) {
                $score += 2;
            }
            if ($original_norm !== '' && strpos($original_norm, $kw) !== false) {
                $score += 1;
            }
            if ($manual_norm !== '' && strpos($manual_norm, $kw) !== false) {
                $score += 2;
            }
        }

        $modified_time = (int) ($entry['modified'] ?? 0);
        if ($modified_time > 0) {
            $days_ago = (time() - $modified_time) / 86400;
            if ($days_ago < 7) {
                $score += 3;
            } elseif ($days_ago < 30) {
                $score += 2;
            } elseif ($days_ago < 90) {
                $score += 1;
            }
        }

        if (!empty($topic_terms)) {
            foreach ($topic_terms as $term) {
                $term = octopus_ai_normalize_search_text($term);
                if ($term === '') {
                    continue;
                }

                if ($content_norm !== '' && strpos($content_norm, $term) !== false) {
                    $score += 2;
                }
                if ($section_norm !== '' && strpos($section_norm, $term) !== false) {
                    $score += 1;
                }
                if ($slug_norm !== '' && strpos($slug_norm, $term) !== false) {
                    $score += 1;
                }
                if ($manual_norm !== '' && strpos($manual_norm, $term) !== false) {
                    $score += 1;
                }
            }
        }

        if ($score > 0) {
            $entry['score'] = $score;
            $chunks_with_score[] = $entry;
        }
    }

    if (empty($chunks_with_score)) {
        error_log('[Octopus AI] Geen relevante chunks gevonden voor vraag: ' . $question);
        return [
            'context' => '',
            'metadata' => [
                'chunks' => [],
                'summary' => [
                    'section_title' => [],
                    'page_slug' => [],
                    'original_page' => [],
                    'source_url' => [],
                    'manual_url' => [],
                ],
            ],
        ];
    }

    usort($chunks_with_score, static function ($a, $b) {
        return (float) ($b['score'] ?? 0) <=> (float) ($a['score'] ?? 0);
    });

    $seen = [
        'section_title' => [],
        'page_slug' => [],
        'original_page' => [],
        'source_url' => [],
        'manual_url' => [],
    ];

    $context = '';
    $max_len = 12000;
    $max_candidates_to_load = 20;
    $top_chunks_metadata = [];

    foreach (array_slice($chunks_with_score, 0, $max_candidates_to_load) as $entry) {
        $chunk_file = (string) ($entry['file'] ?? '');
        if ($chunk_file === '' || !file_exists($chunk_file)) {
            continue;
        }

        $json_raw = @file_get_contents($chunk_file);
        if ($json_raw === false || $json_raw === '') {
            continue;
        }

        $json = json_decode($json_raw, true);
        if (!is_array($json) || empty($json['content'])) {
            continue;
        }

        $content = trim((string) $json['content']);
        if ($content === '' || strlen($content) < 20) {
            continue;
        }

        if (strlen($context . "\n" . $content) > $max_len) {
            if ($context !== '') {
                break;
            }

            if (function_exists('mb_substr')) {
                $content = (string) mb_substr($content, 0, $max_len);
            } else {
                $content = (string) substr($content, 0, $max_len);
            }
        }

        $context .= $content . "\n";

        $metadata = isset($entry['metadata']) && is_array($entry['metadata']) ? $entry['metadata'] : [];
        foreach ($seen as $key => $_) {
            $value = trim((string) ($metadata[$key] ?? ''));
            if ($value !== '' && !in_array($value, $seen[$key], true)) {
                $seen[$key][] = $value;
            }
        }

        if (count($top_chunks_metadata) < 5) {
            $top_chunks_metadata[] = [
                'section_title' => (string) ($metadata['section_title'] ?? ''),
                'page_slug' => (string) ($metadata['page_slug'] ?? ''),
                'original_page' => (string) ($metadata['original_page'] ?? ''),
                'source_url' => (string) ($metadata['source_url'] ?? ''),
                'manual_url' => (string) ($metadata['manual_url'] ?? ''),
                'score' => (float) ($entry['score'] ?? 0),
            ];
        }
    }

    $metadata_summary = array_map(static function ($arr) {
        return array_values(array_filter(array_unique($arr)));
    }, $seen);

    $result = [
        'context' => trim($context),
        'metadata' => [
            'chunks' => $top_chunks_metadata,
            'summary' => $metadata_summary,
        ],
    ];

    set_transient($cache_key, $result, HOUR_IN_SECONDS);

    return $result;
}
