<?php
// Veiligheid
if (!defined('ABSPATH')) exit;

/**
 * Haalt de meest relevante chunks op obv de gebruikersvraag.
 *
 * @param string $question
 * @return string Context string
 */
function octopus_ai_retrieve_relevant_chunks($question) {
    $cache_key = 'octopus_ai_chunks_' . md5($question);
    $cached = get_transient($cache_key);
    if ($cached && is_array($cached)) return $cached;

    $upload_dir = wp_upload_dir();
    $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

    if (!file_exists($chunks_dir)) {
        error_log('[Octopus AI] Chunks directory niet gevonden: ' . $chunks_dir);
        return [
            'context' => '',
            'metadata' => []
        ];
    }

    $normalize = function ($string) {
        if (class_exists('Transliterator')) {
            $transliterator = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            return $transliterator ? $transliterator->transliterate($string) : $string;
        } else {
            return iconv('UTF-8', 'ASCII//TRANSLIT', $string);
        }
    };

    $normalized_question = $normalize(strtolower($question));
    $keywords = preg_split('/\s+/', $normalized_question, -1, PREG_SPLIT_NO_EMPTY);
    $chunks_with_score = [];

    foreach (glob($chunks_dir . '*.txt') as $chunk_file) {
        $raw = file_get_contents($chunk_file);
        $normalized_content = $normalize(strtolower($raw));

        preg_match('/##section_title:(.*?)\n/', $raw, $m1);
        preg_match('/##page_slug:(.*?)\n/', $raw, $m2);
        preg_match('/##original_page:(.*?)\n/', $raw, $m3);
        preg_match('/##source_url:(.*?)\n/', $raw, $m4);

        $section_title = isset($m1[1]) ? trim($normalize(strtolower($m1[1]))) : '';
        $page_slug     = isset($m2[1]) ? trim($normalize(strtolower($m2[1]))) : '';
        $original_page = isset($m3[1]) ? trim($normalize(strtolower($m3[1]))) : '';
        $source_url    = isset($m4[1]) ? trim($m4[1]) : '';

        $score = 0;

// Exacte keywordhits
foreach ($keywords as $kw) {
    if (strlen($kw) < 3) continue;
    if (strpos($normalized_content, $kw) !== false) $score += 1;
    if (strpos($section_title, $kw) !== false)      $score += 2;
    if (strpos($page_slug, $kw) !== false)          $score += 2;
    if (strpos($original_page, $kw) !== false)      $score += 1;
}

// 🔥 Recency boost
$modified_time = filemtime($chunk_file); // UNIX timestamp
$days_ago = (time() - $modified_time) / 86400;

if ($days_ago < 7) {
    $score += 3; // deze week
} elseif ($days_ago < 30) {
    $score += 2; // deze maand
} elseif ($days_ago < 90) {
    $score += 1; // dit kwartaal
}

// Fuzzy match boost: content
similar_text($normalized_content, $normalized_question, $percent1);
if ($percent1 >= 20) $score += 1;
if ($percent1 >= 30) $score += 1;

// Fuzzy match boost: section title
similar_text($section_title, $normalized_question, $percent2);
if ($percent2 >= 20) $score += 1;

// ❌ Sla chunk over als die leeg is of geen nuttige inhoud bevat
$clean = preg_replace('/##.*?:.*?(\\n|$)/', '', $raw);
if (trim($clean) === '' || strlen($clean) < 20) {
    error_log('[Octopus AI] Chunk overgeslagen wegens lege of ongeldige inhoud: ' . basename($chunk_file));
    continue;
}


        if ($score > 0) {
            $chunks_with_score[] = [
                'file' => $chunk_file,
                'content' => $raw,
                'score' => $score,
                'metadata' => [
                    'section_title' => $m1[1] ?? '',
                    'page_slug'     => $m2[1] ?? '',
                    'original_page' => $m3[1] ?? '',
                    'source_url'    => $m4[1] ?? '',
                ]
            ];
        }
    }

    if (empty($chunks_with_score)) {
        error_log('[Octopus AI] Geen relevante chunks gevonden voor vraag: ' . $question);
        return ['context' => '', 'metadata' => []];
    }

    usort($chunks_with_score, fn($a, $b) => $b['score'] <=> $a['score']);

    // ✨ Metadata verzamelen
    $seen = ['section_title' => [], 'page_slug' => [], 'original_page' => [], 'source_url' => []];
    $context = '';
    $max_len = 12000;

    foreach ($chunks_with_score as $chunk) {
        $clean = preg_replace('/##.*?:.*?(\\n|$)/', '', $chunk['content']);
        if (strlen($context . "\n" . $clean) > $max_len) break;
        $context .= $clean . "\n";

        foreach ($seen as $key => $_) {
            $val = trim($chunk['metadata'][$key] ?? '');
            if ($val && !in_array($val, $seen[$key], true)) {
                $seen[$key][] = $val;
            }
        }
    }

    $metadata = array_map(fn($arr) => array_filter(array_unique($arr)), $seen);
    $result = [
        'context'  => trim($context),
        'metadata' => $chunks_with_score[0]['metadata'] ?? [],
        'metas'    => array_column(array_slice($chunks_with_score, 0, 5), 'metadata')
    ];

    // 🧠 Cache opslaan voor 6 uur
    set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);

    return $result;
}



