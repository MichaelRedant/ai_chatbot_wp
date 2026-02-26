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

if (!function_exists('octopus_ai_get_retrieval_stopwords')) {
    function octopus_ai_get_retrieval_stopwords()
    {
        $base_stopwords = [
            'de', 'het', 'een', 'en', 'van', 'voor', 'met', 'als', 'bij', 'op', 'in', 'naar', 'door', 'aan', 'of',
            'te', 'tot', 'dat', 'die', 'dit', 'dan', 'der', 'ook', 'nog', 'maar', 'niet', 'wel', 'kan', 'kunnen',
            'vraag', 'vragen', 'reactie', 'gebruiker', 'vervolg', 'vorige', 'bericht', 'antwoorden', 'antwoord',
            'le', 'la', 'les', 'des', 'pour', 'avec', 'dans', 'par', 'une', 'est', 'sur', 'aux', 'qui', 'que',
            'question', 'questions', 'reponse', 'reponses', 'utilisateur', 'suite', 'precedente', 'message',
            'the', 'and', 'for', 'with', 'from', 'this', 'that', 'your', 'you', 'are', 'was', 'were', 'have', 'has',
            'follow', 'followup', 'previous',
        ];

        $provider_stopwords = function_exists('octopus_ai_get_provider_retrieval_stopwords')
            ? octopus_ai_get_provider_retrieval_stopwords()
            : [];

        $merged = [];
        foreach (array_merge($base_stopwords, is_array($provider_stopwords) ? $provider_stopwords : []) as $term) {
            $term = strtolower(trim((string) $term));
            if ($term === '' || in_array($term, $merged, true)) {
                continue;
            }
            $merged[] = $term;
        }

        return $merged;
    }
}

if (!function_exists('octopus_ai_extract_retrieval_keywords')) {
    function octopus_ai_extract_retrieval_keywords($question)
    {
        $normalized_question = octopus_ai_normalize_search_text((string) $question);
        if ($normalized_question === '') {
            return [];
        }

        $stopwords = octopus_ai_get_retrieval_stopwords();
        $raw_tokens = preg_split('/[^a-z0-9\-_]+/i', $normalized_question, -1, PREG_SPLIT_NO_EMPTY);
        $raw_tokens = is_array($raw_tokens) ? $raw_tokens : [];

        $tokens = [];
        foreach ($raw_tokens as $token) {
            $token = trim((string) $token, "-_ \t\n\r\0\x0B");
            if ($token === '' || in_array($token, $stopwords, true)) {
                continue;
            }
            if (strlen($token) < 3 || strlen($token) > 40) {
                continue;
            }
            if (ctype_digit($token)) {
                continue;
            }
            if (!in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        // Voeg korte 2-woord zinnen toe voor meer precisie.
        $phrases = [];
        $token_count = count($tokens);
        for ($i = 0; $i < $token_count - 1; $i++) {
            $phrase = $tokens[$i] . ' ' . $tokens[$i + 1];
            if (strlen($phrase) >= 7 && !in_array($phrase, $phrases, true)) {
                $phrases[] = $phrase;
            }
            if (count($phrases) >= 6) {
                break;
            }
        }

        $keywords = array_values(array_unique(array_merge($tokens, $phrases)));
        if (empty($keywords)) {
            $keywords[] = $normalized_question;
        }

        return $keywords;
    }
}

if (!function_exists('octopus_ai_build_content_fingerprint')) {
    function octopus_ai_build_content_fingerprint($text)
    {
        $normalized = octopus_ai_normalize_search_text((string) $text);
        $normalized = trim((string) preg_replace('/\s+/u', ' ', (string) $normalized));
        if ($normalized === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            $normalized = (string) mb_substr($normalized, 0, 700);
        } else {
            $normalized = (string) substr($normalized, 0, 700);
        }

        return md5($normalized);
    }
}

if (!function_exists('octopus_ai_get_retriever_topic_terms_map')) {
    function octopus_ai_get_retriever_topic_terms_map()
    {
        $fallback_default_map = [];
        if (function_exists('octopus_ai_get_default_provider_profile')) {
            $provider_defaults = octopus_ai_get_default_provider_profile();
            if (
                isset($provider_defaults['retrieval']['topic_terms']) &&
                is_array($provider_defaults['retrieval']['topic_terms']) &&
                !empty($provider_defaults['retrieval']['topic_terms'])
            ) {
                $fallback_default_map = $provider_defaults['retrieval']['topic_terms'];
            } elseif (isset($provider_defaults['topic_terms']) && is_array($provider_defaults['topic_terms'])) {
                $fallback_default_map = $provider_defaults['topic_terms'];
            }
        }

        $default_map = function_exists('octopus_ai_get_provider_retrieval_topic_terms_map')
            ? octopus_ai_get_provider_retrieval_topic_terms_map()
            : $fallback_default_map;
        if (!is_array($default_map) || empty($default_map)) {
            $default_map = $fallback_default_map;
        }

        $source_map = function_exists('octopus_ai_get_topic_terms_map')
            ? octopus_ai_get_topic_terms_map()
            : $default_map;

        if (!is_array($source_map) || empty($source_map)) {
            $source_map = $default_map;
        }

        $map = [];
        foreach ($source_map as $topic_key => $terms) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '' || !is_array($terms)) {
                continue;
            }

            $clean_terms = [];
            foreach ($terms as $term) {
                $normalized = octopus_ai_normalize_search_text((string) $term);
                if ($normalized === '' || in_array($normalized, $clean_terms, true)) {
                    continue;
                }
                $clean_terms[] = $normalized;
            }

            if (isset($default_map[$topic_key]) && is_array($default_map[$topic_key])) {
                foreach ($default_map[$topic_key] as $default_term) {
                    $normalized_default = octopus_ai_normalize_search_text((string) $default_term);
                    if ($normalized_default !== '' && !in_array($normalized_default, $clean_terms, true)) {
                        $clean_terms[] = $normalized_default;
                    }
                }
            }

            if (!empty($clean_terms)) {
                $map[$topic_key] = $clean_terms;
            }
        }

        foreach ($default_map as $topic_key => $terms) {
            if (isset($map[$topic_key])) {
                continue;
            }

            $clean_terms = [];
            foreach ($terms as $term) {
                $normalized = octopus_ai_normalize_search_text((string) $term);
                if ($normalized === '' || in_array($normalized, $clean_terms, true)) {
                    continue;
                }
                $clean_terms[] = $normalized;
            }

            if (!empty($clean_terms)) {
                $map[$topic_key] = $clean_terms;
            }
        }

        return $map;
    }
}

if (!function_exists('octopus_ai_get_chunk_topic_hits')) {
    function octopus_ai_get_chunk_topic_hits(array $entry, array $topic_terms_map)
    {
        $blob_parts = [];
        $blob_parts[] = (string) ($entry['section_title_norm'] ?? '');
        $blob_parts[] = (string) ($entry['page_slug_norm'] ?? '');
        $blob_parts[] = (string) ($entry['original_page_norm'] ?? '');
        $blob_parts[] = (string) ($entry['manual_url_norm'] ?? '');
        $blob_parts[] = (string) ($entry['source_title_norm'] ?? '');
        $blob_parts[] = (string) ($entry['index_terms_norm'] ?? '');

        // Gebruik extra inhoud om topicverschil te zien, maar cap om CPU te beschermen.
        $content_norm = (string) ($entry['content_preview_norm'] ?? '');
        if ($content_norm !== '') {
            if (function_exists('mb_substr')) {
                $blob_parts[] = (string) mb_substr($content_norm, 0, 1800);
            } else {
                $blob_parts[] = (string) substr($content_norm, 0, 1800);
            }
        }

        $blob = trim(implode(' ', array_filter($blob_parts)));
        if ($blob === '') {
            return [];
        }

        $hits = [];
        foreach ($topic_terms_map as $topic_key => $terms) {
            if (!is_array($terms) || empty($terms)) {
                continue;
            }

            $score = 0;
            foreach ($terms as $term) {
                $term = octopus_ai_normalize_search_text((string) $term);
                if ($term === '' || strlen($term) < 3) {
                    continue;
                }

                $occurrences = substr_count($blob, $term);
                if ($occurrences <= 0) {
                    continue;
                }

                $score += min(4, $occurrences);
            }

            $hits[$topic_key] = $score;
        }

        return $hits;
    }
}

if (!function_exists('octopus_ai_get_retriever_intent_terms_map')) {
    function octopus_ai_get_retriever_intent_terms_map()
    {
        $fallback_default_map = [];
        if (function_exists('octopus_ai_get_default_provider_profile')) {
            $provider_defaults = octopus_ai_get_default_provider_profile();
            $fallback_default_map = isset($provider_defaults['intent_terms']) && is_array($provider_defaults['intent_terms'])
                ? $provider_defaults['intent_terms']
                : [];
        }
        $map = function_exists('octopus_ai_get_provider_intent_terms_map')
            ? octopus_ai_get_provider_intent_terms_map()
            : $fallback_default_map;
        if (!is_array($map) || empty($map)) {
            $map = $fallback_default_map;
        }

        foreach ($map as $intent_key => $terms) {
            if (!is_array($terms)) {
                $map[$intent_key] = [];
                continue;
            }

            $normalized_terms = [];
            foreach ($terms as $term) {
                $term = octopus_ai_normalize_search_text((string) $term);
                if ($term === '' || strlen($term) < 3 || in_array($term, $normalized_terms, true)) {
                    continue;
                }
                $normalized_terms[] = $term;
            }
            $map[$intent_key] = $normalized_terms;
        }

        return $map;
    }
}

if (!function_exists('octopus_ai_get_retriever_intent_topic_map')) {
    function octopus_ai_get_retriever_intent_topic_map()
    {
        $fallback_default_map = [];
        if (function_exists('octopus_ai_get_default_provider_profile')) {
            $provider_defaults = octopus_ai_get_default_provider_profile();
            $fallback_default_map = isset($provider_defaults['intent_topic_map']) && is_array($provider_defaults['intent_topic_map'])
                ? $provider_defaults['intent_topic_map']
                : [];
        }
        $allowed_topics = function_exists('octopus_ai_get_provider_allowed_topics')
            ? octopus_ai_get_provider_allowed_topics()
            : [];
        if (empty($allowed_topics)) {
            $topic_terms_map = octopus_ai_get_retriever_topic_terms_map();
            $allowed_topics = array_values(array_filter(array_map('sanitize_key', array_keys(is_array($topic_terms_map) ? $topic_terms_map : []))));
        }
        $map = function_exists('octopus_ai_get_provider_intent_topic_map')
            ? octopus_ai_get_provider_intent_topic_map()
            : $fallback_default_map;
        if (!is_array($map) || empty($map)) {
            $map = $fallback_default_map;
        }

        $normalized_map = [];
        foreach ($map as $intent_key => $topic_key) {
            $intent_key = sanitize_key((string) $intent_key);
            $topic_key = sanitize_key((string) $topic_key);
            if ($intent_key === '' || $topic_key === '' || !in_array($topic_key, $allowed_topics, true)) {
                continue;
            }
            $normalized_map[$intent_key] = $topic_key;
        }

        return $normalized_map;
    }
}

if (!function_exists('octopus_ai_detect_retrieval_intent')) {
    function octopus_ai_detect_retrieval_intent($question, array $intent_terms_map)
    {
        $normalized = octopus_ai_normalize_search_text((string) $question);
        if ($normalized === '') {
            return [
                'intent' => '',
                'score' => 0.0,
                'matched_terms' => [],
            ];
        }

        $seed_intent = '';
        if (function_exists('octopus_ai_detect_intent')) {
            $seed_intent = sanitize_key((string) octopus_ai_detect_intent($question));
        }

        $best_intent = '';
        $best_score = 0.0;
        $second_score = 0.0;
        $best_terms = [];

        foreach ($intent_terms_map as $intent_key => $terms) {
            $intent_key = sanitize_key((string) $intent_key);
            if ($intent_key === '' || !is_array($terms) || empty($terms)) {
                continue;
            }

            $score = 0.0;
            $matched_terms = [];
            foreach ($terms as $term) {
                $term = octopus_ai_normalize_search_text((string) $term);
                if ($term === '' || strlen($term) < 3) {
                    continue;
                }

                if (strpos($normalized, $term) === false) {
                    continue;
                }

                $occurrences = substr_count($normalized, $term);
                if ($occurrences <= 0) {
                    continue;
                }

                $score += min(3.5, (float) $occurrences);
                if (strlen($term) >= 8) {
                    $score += 0.8;
                }

                if (!in_array($term, $matched_terms, true)) {
                    $matched_terms[] = $term;
                }
            }

            if ($seed_intent !== '' && $seed_intent === $intent_key) {
                $score += 1.25;
            }

            if ($score > $best_score) {
                $second_score = $best_score;
                $best_score = $score;
                $best_intent = $intent_key;
                $best_terms = $matched_terms;
            } elseif ($score > $second_score) {
                $second_score = $score;
            }
        }

        if ($best_intent === '' || $best_score < 1.2 || $best_score <= ($second_score + 0.2)) {
            return [
                'intent' => '',
                'score' => 0.0,
                'matched_terms' => [],
            ];
        }

        return [
            'intent' => $best_intent,
            'score' => round($best_score, 2),
            'matched_terms' => array_slice($best_terms, 0, 6),
        ];
    }
}

if (!function_exists('octopus_ai_get_chunk_intent_score')) {
    function octopus_ai_get_chunk_intent_score(array $entry, $intent, array $intent_terms_map)
    {
        $intent = sanitize_key((string) $intent);
        if ($intent === '' || !isset($intent_terms_map[$intent]) || !is_array($intent_terms_map[$intent])) {
            return 0.0;
        }

        $terms = $intent_terms_map[$intent];
        if (empty($terms)) {
            return 0.0;
        }

        $content_norm = (string) ($entry['content_preview_norm'] ?? '');
        $section_norm = (string) ($entry['section_title_norm'] ?? '');
        $slug_norm = (string) ($entry['page_slug_norm'] ?? '');
        $original_norm = (string) ($entry['original_page_norm'] ?? '');
        $manual_norm = (string) ($entry['manual_url_norm'] ?? '');
        $source_title_norm = (string) ($entry['source_title_norm'] ?? '');
        $index_terms_norm = (string) ($entry['index_terms_norm'] ?? '');

        $score = 0.0;
        foreach ($terms as $term) {
            $term = octopus_ai_normalize_search_text((string) $term);
            if ($term === '' || strlen($term) < 3) {
                continue;
            }

            if ($content_norm !== '') {
                $content_hits = substr_count($content_norm, $term);
                if ($content_hits > 0) {
                    $score += min(4.0, 1.2 + (float) $content_hits);
                    if (strlen($term) >= 8) {
                        $score += 0.4;
                    }
                }
            }
            if ($section_norm !== '' && strpos($section_norm, $term) !== false) {
                $score += 1.8;
            }
            if ($source_title_norm !== '' && strpos($source_title_norm, $term) !== false) {
                $score += 1.8;
            }
            if ($index_terms_norm !== '' && strpos($index_terms_norm, $term) !== false) {
                $score += 2.1;
            }
            if ($slug_norm !== '' && strpos($slug_norm, $term) !== false) {
                $score += 1.0;
            }
            if ($original_norm !== '' && strpos($original_norm, $term) !== false) {
                $score += 0.8;
            }
            if ($manual_norm !== '' && strpos($manual_norm, $term) !== false) {
                $score += 1.0;
            }
        }

        return round(min(22.0, $score), 3);
    }
}

if (!function_exists('octopus_ai_get_chunk_recency_score')) {
    function octopus_ai_get_chunk_recency_score($modified_time, $source_type_norm = '', $has_intent_signal = false)
    {
        $modified_time = (int) $modified_time;
        if ($modified_time <= 0) {
            return 0.0;
        }

        $age_seconds = max(0, time() - $modified_time);
        $age_days = $age_seconds / DAY_IN_SECONDS;
        $source_type_norm = octopus_ai_normalize_search_text((string) $source_type_norm);

        // Continue decay: recente docs krijgen meer gewicht, oudere docs minder.
        $score = 4.0 * exp(-$age_days / 45.0);

        if ($age_days <= 7) {
            $score += 1.2;
        } elseif ($age_days <= 30) {
            $score += 0.6;
        }

        if ($has_intent_signal && $age_days <= 30) {
            $score += 0.35;
        }

        if (
            $source_type_norm !== '' &&
            $age_days <= 21 &&
            (
                strpos($source_type_norm, 'sitemap') !== false ||
                strpos($source_type_norm, 'manual') !== false ||
                strpos($source_type_norm, 'live') !== false
            )
        ) {
            $score += 0.5;
        }

        if ($age_days > 365) {
            $score -= 0.8;
        }
        if ($age_days > 730) {
            $score -= 0.8;
        }

        return max(-2.0, min(6.5, round($score, 3)));
    }
}

if (!function_exists('octopus_ai_get_active_source_strategy')) {
    function octopus_ai_get_active_source_strategy()
    {
        $strategy = strtolower(trim((string) get_option('octopus_ai_source_strategy', 'manual_upload')));
        $allowed  = ['manual_upload', 'sitemap_online', 'live_manual'];

        if (!in_array($strategy, $allowed, true)) {
            return 'manual_upload';
        }

        return $strategy;
    }
}

if (!function_exists('octopus_ai_is_chunk_file_allowed_for_strategy')) {
    function octopus_ai_is_chunk_file_allowed_for_strategy($chunk_file, $source_strategy = '')
    {
        $source_strategy = $source_strategy !== ''
            ? strtolower(trim((string) $source_strategy))
            : octopus_ai_get_active_source_strategy();

        $basename = strtolower((string) basename((string) $chunk_file));
        if ($basename === '') {
            return false;
        }

        // In sitemap_online modus mag alleen context uit sitemap-chunks komen.
        if ($source_strategy === 'sitemap_online') {
            return strpos($basename, 'sitemap_') === 0;
        }

        // live_manual gebruikt geen lokale chunks.
        if ($source_strategy === 'live_manual') {
            return false;
        }

        return true;
    }
}

if (!function_exists('octopus_ai_get_chunk_index')) {
    /**
     * Bouwt een compacte zoekindex op met metadata en content-preview.
     *
     * @param string $chunks_dir
     * @return array{signature:string, entries:array<int,array<string,mixed>>}
     */
    function octopus_ai_get_chunk_index($chunks_dir, $source_strategy = '')
    {
        $files = glob(trailingslashit($chunks_dir) . '*.json');
        if (!is_array($files) || empty($files)) {
            return [
                'signature' => 'empty',
                'entries' => [],
            ];
        }

        $source_strategy = $source_strategy !== ''
            ? strtolower(trim((string) $source_strategy))
            : octopus_ai_get_active_source_strategy();

        $files = array_values(array_filter($files, static function ($file) use ($source_strategy) {
            return octopus_ai_is_chunk_file_allowed_for_strategy($file, $source_strategy);
        }));

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
        $cache_key = 'octopus_ai_chunk_index_' . md5($chunks_dir . '|' . $source_strategy . '|' . $signature);

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

            $index_terms = isset($metadata['index_terms']) && is_array($metadata['index_terms'])
                ? array_values(array_filter(array_map('strval', $metadata['index_terms'])))
                : [];
            $index_terms = array_slice($index_terms, 0, 30);
            $index_terms_blob = implode(' ', $index_terms);

            $entries[] = [
                'file' => $chunk_file,
                'modified' => (int) @filemtime($chunk_file),
                'content_preview' => $content_preview,
                'content_preview_norm' => octopus_ai_normalize_search_text($content_preview),
                'section_title_norm' => octopus_ai_normalize_search_text((string) ($metadata['section_title'] ?? '')),
                'page_slug_norm' => octopus_ai_normalize_search_text((string) ($metadata['page_slug'] ?? '')),
                'original_page_norm' => octopus_ai_normalize_search_text((string) ($metadata['original_page'] ?? '')),
                'manual_url_norm' => octopus_ai_normalize_search_text((string) ($metadata['manual_url'] ?? '')),
                'source_title_norm' => octopus_ai_normalize_search_text((string) ($metadata['source_title'] ?? '')),
                'source_type_norm' => octopus_ai_normalize_search_text((string) ($metadata['source_type'] ?? '')),
                'index_terms_norm' => octopus_ai_normalize_search_text($index_terms_blob),
                'metadata' => [
                    'source_title' => (string) ($metadata['source_title'] ?? ''),
                    'section_title' => (string) ($metadata['section_title'] ?? ''),
                    'page_slug' => (string) ($metadata['page_slug'] ?? ''),
                    'original_page' => (string) ($metadata['original_page'] ?? ''),
                    'source_url' => (string) ($metadata['source_url'] ?? ''),
                    'manual_url' => (string) ($metadata['manual_url'] ?? ''),
                    'source_type' => (string) ($metadata['source_type'] ?? ''),
                    'chunk_index' => (int) ($metadata['chunk_index'] ?? 0),
                    'total_chunks' => (int) ($metadata['total_chunks'] ?? 0),
                    'index_terms' => $index_terms,
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
    $source_strategy = function_exists('octopus_ai_get_active_source_strategy')
        ? octopus_ai_get_active_source_strategy()
        : 'manual_upload';
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

    $index_data = octopus_ai_get_chunk_index($chunks_dir, $source_strategy);
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

    $cache_key = 'octopus_ai_chunks_' . md5('v4||' . $question . '||' . $topic_key . '||' . $source_strategy . '||' . $index_signature);
    $cached = get_transient($cache_key);
    if ($cached && is_array($cached)) {
        return $cached;
    }

    $topic_keywords = function_exists('octopus_ai_get_provider_retrieval_topic_keywords_map')
        ? octopus_ai_get_provider_retrieval_topic_keywords_map()
        : [];
    if (!is_array($topic_keywords) || empty($topic_keywords)) {
        $provider_defaults = function_exists('octopus_ai_get_default_provider_profile')
            ? octopus_ai_get_default_provider_profile()
            : [];
        $topic_keywords = isset($provider_defaults['retrieval']['topic_keywords']) && is_array($provider_defaults['retrieval']['topic_keywords'])
            ? $provider_defaults['retrieval']['topic_keywords']
            : [];
    }
    if (!is_array($topic_keywords) || empty($topic_keywords)) {
        $topic_keywords = octopus_ai_get_retriever_topic_terms_map();
    }
    $topic_terms = $topic_key !== '' && isset($topic_keywords[$topic_key]) ? $topic_keywords[$topic_key] : [];
    $topic_terms_map = octopus_ai_get_retriever_topic_terms_map();

    $keywords = octopus_ai_extract_retrieval_keywords($question);
    $intent_terms_map = octopus_ai_get_retriever_intent_terms_map();
    $intent_signal = octopus_ai_detect_retrieval_intent($question, $intent_terms_map);
    $detected_intent = sanitize_key((string) ($intent_signal['intent'] ?? ''));
    $intent_confidence = (float) ($intent_signal['score'] ?? 0.0);
    $intent_topic_map = octopus_ai_get_retriever_intent_topic_map();
    $intent_topic_hint = (
        $detected_intent !== '' &&
        isset($intent_topic_map[$detected_intent])
    ) ? sanitize_key((string) $intent_topic_map[$detected_intent]) : '';
    $has_intent_signal = $detected_intent !== '' && $intent_confidence > 0.0;

    $chunks_with_score = [];
    foreach ($entries as $entry) {
        $score = 0;
        $intent_bonus = 0.0;
        $recency_bonus = 0.0;

        $content_norm = (string) ($entry['content_preview_norm'] ?? '');
        $section_norm = (string) ($entry['section_title_norm'] ?? '');
        $slug_norm = (string) ($entry['page_slug_norm'] ?? '');
        $original_norm = (string) ($entry['original_page_norm'] ?? '');
        $manual_norm = (string) ($entry['manual_url_norm'] ?? '');
        $source_title_norm = (string) ($entry['source_title_norm'] ?? '');
        $source_type_norm = (string) ($entry['source_type_norm'] ?? '');
        $index_terms_norm = (string) ($entry['index_terms_norm'] ?? '');

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
            if ($source_title_norm !== '' && strpos($source_title_norm, $kw) !== false) {
                $score += 3;
            }
            if ($index_terms_norm !== '' && strpos($index_terms_norm, $kw) !== false) {
                $score += 4;
            }
            if ($source_type_norm !== '' && strpos($source_type_norm, $kw) !== false) {
                $score += 1;
            }

            // Langere termen krijgen extra gewicht bij exacte match.
            if (strlen($kw) >= 8 && $content_norm !== '' && strpos($content_norm, $kw) !== false) {
                $score += 2;
            }
        }

        $entry_topic_hits = octopus_ai_get_chunk_topic_hits($entry, $topic_terms_map);
        $entry['topic_hits'] = $entry_topic_hits;

        if ($topic_key !== '' && isset($topic_terms_map[$topic_key])) {
            $selected_hits = isset($entry_topic_hits[$topic_key]) ? (int) $entry_topic_hits[$topic_key] : 0;
            $other_best_hits = 0;

            foreach ($entry_topic_hits as $candidate_topic => $candidate_hits) {
                if ($candidate_topic === $topic_key) {
                    continue;
                }
                $candidate_hits = (int) $candidate_hits;
                if ($candidate_hits > $other_best_hits) {
                    $other_best_hits = $candidate_hits;
                }
            }

            // Harde filter: sterk andere flow zonder signaal voor actieve flow overslaan.
            if ($selected_hits <= 0 && $other_best_hits >= 2) {
                continue;
            }

            if ($selected_hits > 0) {
                $score += min(14.0, $selected_hits * 2.5);
            }

            if ($other_best_hits > $selected_hits && $other_best_hits >= 2) {
                $score -= min(10.0, $other_best_hits * 2.0);
            }
        }

        if ($has_intent_signal) {
            $intent_bonus = octopus_ai_get_chunk_intent_score($entry, $detected_intent, $intent_terms_map);
            if ($intent_bonus > 0.0) {
                $score += $intent_bonus;
            }

            if ($intent_topic_hint !== '' && isset($topic_terms_map[$intent_topic_hint])) {
                $intent_hint_hits = isset($entry_topic_hits[$intent_topic_hint]) ? (int) $entry_topic_hits[$intent_topic_hint] : 0;

                if ($topic_key === '') {
                    if ($intent_hint_hits > 0) {
                        $score += min(8.0, $intent_hint_hits * 1.8);
                    } elseif ($intent_bonus <= 0.0 && $intent_confidence >= 2.8) {
                        $score -= 0.7;
                    }
                } else {
                    if ($intent_topic_hint === $topic_key && $intent_hint_hits > 0) {
                        $score += min(5.0, $intent_hint_hits * 1.25);
                    } elseif ($intent_topic_hint !== $topic_key && $intent_bonus > 0.0) {
                        $score -= min(4.0, $intent_bonus * 0.28);
                    }
                }
            }
        }

        $modified_time = (int) ($entry['modified'] ?? 0);
        $recency_bonus = octopus_ai_get_chunk_recency_score($modified_time, $source_type_norm, $has_intent_signal);
        $score += $recency_bonus;

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
                if ($source_title_norm !== '' && strpos($source_title_norm, $term) !== false) {
                    $score += 2;
                }
                if ($index_terms_norm !== '' && strpos($index_terms_norm, $term) !== false) {
                    $score += 2;
                }
            }
        }

        if ($score > 0) {
            $entry['score'] = round((float) $score, 3);
            $entry['intent'] = $detected_intent;
            $entry['intent_bonus'] = round((float) $intent_bonus, 3);
            $entry['recency_bonus'] = round((float) $recency_bonus, 3);
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

    // Als een topic actief is, geef voorkeur aan chunks die dat topic expliciet ondersteunen.
    if ($topic_key !== '') {
        $preferred_chunks = [];
        foreach ($chunks_with_score as $entry) {
            $topic_hits = isset($entry['topic_hits']) && is_array($entry['topic_hits']) ? $entry['topic_hits'] : [];
            $selected_hits = isset($topic_hits[$topic_key]) ? (int) $topic_hits[$topic_key] : 0;
            $other_best_hits = 0;
            foreach ($topic_hits as $candidate_topic => $candidate_hits) {
                if ($candidate_topic === $topic_key) {
                    continue;
                }
                $candidate_hits = (int) $candidate_hits;
                if ($candidate_hits > $other_best_hits) {
                    $other_best_hits = $candidate_hits;
                }
            }

            if ($selected_hits > 0 || $selected_hits >= $other_best_hits) {
                $preferred_chunks[] = $entry;
            }
        }

        if (!empty($preferred_chunks)) {
            $chunks_with_score = $preferred_chunks;
        }
    }

    $seen = [
        'section_title' => [],
        'page_slug' => [],
        'original_page' => [],
        'source_url' => [],
        'manual_url' => [],
    ];

    $context = '';
    $max_len = ($topic_key !== '') ? 16000 : 12000;
    $max_candidates_to_load = ($topic_key !== '') ? 45 : 25;
    $per_chunk_max_len = 2200;
    $top_chunks_metadata = [];
    $source_usage = [];
    $page_usage = [];
    $content_fingerprints = [];
    $max_chunks_per_source = ($topic_key !== '') ? 5 : 4;
    $max_chunks_per_page = 3;
    $candidate_entries = array_slice($chunks_with_score, 0, $max_candidates_to_load);
    $unique_source_keys = [];
    foreach ($candidate_entries as $candidate_entry) {
        $candidate_meta = isset($candidate_entry['metadata']) && is_array($candidate_entry['metadata']) ? $candidate_entry['metadata'] : [];
        $candidate_source_key = octopus_ai_normalize_search_text((string) (
            ($candidate_meta['manual_url'] ?? '')
            ?: ($candidate_meta['source_url'] ?? '')
            ?: ($candidate_meta['source_title'] ?? '')
        ));
        if ($candidate_source_key !== '') {
            $unique_source_keys[$candidate_source_key] = true;
        }
    }

    $unique_source_count = count($unique_source_keys);
    if ($unique_source_count <= 1) {
        $max_chunks_per_source = ($topic_key !== '') ? 10 : 8;
    } elseif ($unique_source_count === 2) {
        $max_chunks_per_source = ($topic_key !== '') ? 7 : 6;
    }

    foreach ($candidate_entries as $entry) {
        $entry_metadata = isset($entry['metadata']) && is_array($entry['metadata']) ? $entry['metadata'] : [];
        $source_key_raw = (string) (
            ($entry_metadata['manual_url'] ?? '')
            ?: ($entry_metadata['source_url'] ?? '')
            ?: ($entry_metadata['source_title'] ?? '')
        );
        $source_key = octopus_ai_normalize_search_text($source_key_raw);
        if ($source_key !== '' && isset($source_usage[$source_key]) && $source_usage[$source_key] >= $max_chunks_per_source) {
            continue;
        }

        $page_key_raw = (string) (
            ($entry_metadata['page_slug'] ?? '')
            ?: ($entry_metadata['section_title'] ?? '')
        );
        $page_key = octopus_ai_normalize_search_text($page_key_raw);
        if ($page_key !== '' && isset($page_usage[$page_key]) && $page_usage[$page_key] >= $max_chunks_per_page) {
            continue;
        }

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

        if (function_exists('mb_substr')) {
            $content = (string) mb_substr($content, 0, $per_chunk_max_len);
        } else {
            $content = (string) substr($content, 0, $per_chunk_max_len);
        }

        $content_fingerprint = octopus_ai_build_content_fingerprint($content);
        if ($content_fingerprint !== '' && isset($content_fingerprints[$content_fingerprint])) {
            continue;
        }

        $context_block = $content;
        $section_title = trim((string) ($entry_metadata['section_title'] ?? ''));
        $has_page_context = $page_key !== '' && !empty($page_usage[$page_key]);
        if ($section_title !== '' && !$has_page_context) {
            $context_block = $section_title . "\n" . $content;
        }

        if (strlen($context . "\n" . $context_block) > $max_len) {
            if ($context !== '') {
                break;
            }

            if (function_exists('mb_substr')) {
                $context_block = (string) mb_substr($context_block, 0, $max_len);
            } else {
                $context_block = (string) substr($context_block, 0, $max_len);
            }
        }

        $context .= $context_block . "\n";

        if ($source_key !== '') {
            $source_usage[$source_key] = isset($source_usage[$source_key]) ? ((int) $source_usage[$source_key] + 1) : 1;
        }
        if ($page_key !== '') {
            $page_usage[$page_key] = isset($page_usage[$page_key]) ? ((int) $page_usage[$page_key] + 1) : 1;
        }
        if ($content_fingerprint !== '') {
            $content_fingerprints[$content_fingerprint] = true;
        }

        $metadata = $entry_metadata;
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
                'intent' => (string) ($entry['intent'] ?? ''),
                'intent_bonus' => (float) ($entry['intent_bonus'] ?? 0.0),
                'recency_bonus' => (float) ($entry['recency_bonus'] ?? 0.0),
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
