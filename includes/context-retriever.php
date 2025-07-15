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
    $upload_dir = wp_upload_dir();
    $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

    if (!file_exists($chunks_dir)) {
        error_log('[Octopus AI] Chunks directory niet gevonden: ' . $chunks_dir);
        return '';
    }

    // Helper functie accenten verwijderen
    $normalize = function($string) {
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
        $content_raw = file_get_contents($chunk_file);
        $content_lower = strtolower($content_raw);
        $content_normalized = $normalize($content_lower);

        $score = 0;
        foreach ($keywords as $keyword) {
            if (strlen($keyword) < 4) continue;
            if (strpos($content_normalized, $keyword) !== false) {
                $score++;
            }
        }

        if ($score > 0) {
            $chunks_with_score[] = [
                'file' => $chunk_file,
                'content' => $content_raw,
                'score' => $score
            ];
        }
    }

    if (empty($chunks_with_score)) {
        error_log('[Octopus AI] Geen relevante chunks gevonden voor vraag: ' . $question);
        return '';
    }

    // Sorteer op score (hoogste eerst)
    usort($chunks_with_score, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    // Voeg chunks toe tot limiet
    $context = '';
    $max_context_length = 12000; // karakters
    foreach ($chunks_with_score as $chunk) {
        if (strlen($context . "\n" . $chunk['content']) > $max_context_length) {
            break;
        }
       $clean_content = preg_replace('/##.*?:.*?(\\n|$)/', '', $chunk['content']);
$context .= $clean_content . "\n";
    }

    // Logging voor debug en analyse
    error_log('[Octopus AI] Context opgebouwd met ' . strlen($context) . ' karakters voor vraag: ' . $question);

    return $context;
}
