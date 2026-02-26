<?php
// Veiligheid
if (!defined('ABSPATH')) exit;

/**
 * Detecteert de intent op basis van keywordgroepen.
 *
 * @param string $question
 * @return string|null intent slug uit provider-profiel of null bij onduidelijk
 */
function octopus_ai_detect_intent($question) {
    $question = trim((string) $question);
    if ($question === '') {
        return null;
    }

    $normalize = static function ($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (function_exists('octopus_ai_normalize_search_text')) {
            return octopus_ai_normalize_search_text($value);
        }

        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }
        return strtolower($value);
    };

    $normalized_question = $normalize($question);
    if ($normalized_question === '') {
        return null;
    }

    $default_intents = [];
    if (function_exists('octopus_ai_get_default_provider_profile')) {
        $provider_defaults = octopus_ai_get_default_provider_profile();
        $default_intents = isset($provider_defaults['intent_terms']) && is_array($provider_defaults['intent_terms'])
            ? $provider_defaults['intent_terms']
            : [];
    }
    $intents = function_exists('octopus_ai_get_provider_intent_terms_map')
        ? octopus_ai_get_provider_intent_terms_map()
        : $default_intents;
    if (!is_array($intents) || empty($intents)) {
        $intents = $default_intents;
    }

    $normalized_intents = [];
    foreach ($intents as $intent_key => $keywords) {
        $intent_key = sanitize_key((string) $intent_key);
        if ($intent_key === '' || !is_array($keywords)) {
            continue;
        }

        $clean_keywords = [];
        foreach ($keywords as $keyword) {
            $keyword = $normalize($keyword);
            if ($keyword === '' || strlen($keyword) < 3 || in_array($keyword, $clean_keywords, true)) {
                continue;
            }
            $clean_keywords[] = $keyword;
        }

        if (!empty($clean_keywords)) {
            $normalized_intents[$intent_key] = $clean_keywords;
        }
    }
    if (empty($normalized_intents)) {
        return null;
    }

    $best_intent = '';
    $best_score = 0.0;
    $second_score = 0.0;

    foreach ($normalized_intents as $intent => $keywords) {
        $score = 0.0;
        foreach ($keywords as $kw) {
            if (strpos($normalized_question, $kw) === false) {
                continue;
            }

            $hits = substr_count($normalized_question, $kw);
            if ($hits > 0) {
                $score += min(3.5, (float) $hits);
                if (strlen($kw) >= 8) {
                    $score += 0.45;
                }
            }
        }

        if ($score > $best_score) {
            $second_score = $best_score;
            $best_score = $score;
            $best_intent = $intent;
        } elseif ($score > $second_score) {
            $second_score = $score;
        }
    }

    if ($best_intent === '' || $best_score < 1.0 || $best_score <= ($second_score + 0.15)) {
        return null;
    }

    return $best_intent;
}
