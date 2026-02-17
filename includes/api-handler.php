<?php

// Veiligheid
if (!defined('ABSPATH')) exit;

if (!function_exists('octopus_ai_detect_intent')) {
    require_once __DIR__ . '/helpers/intent-detector.php';
}

if (!function_exists('octopus_ai_get_manual_mode') || !function_exists('octopus_ai_fetch_live_manual_context')) {
    $live_manual_helper = __DIR__ . '/helpers/live-manual.php';
    if (file_exists($live_manual_helper)) {
        require_once $live_manual_helper;
    }
}

if (!function_exists('octopus_ai_apply_language_glossary')) {
    $fr_glossary_helper = __DIR__ . '/helpers/fr-glossary.php';
    if (file_exists($fr_glossary_helper)) {
        require_once $fr_glossary_helper;
    }
}

if (!function_exists('octopus_ai_apply_language_glossary')) {
    function octopus_ai_apply_language_glossary($text, $lang = 'NL')
    {
        return (string) $text;
    }
}

if (!function_exists('octopus_ai_get_manual_mode')) {
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

        if (!in_array($mode, $allowed, true)) {
            error_log('[Octopus AI] Onbekende manual-modus, val terug op "hybrid".');
            return 'hybrid';
        }

        return $mode;
    }
}

if (!function_exists('octopus_ai_fetch_live_manual_context')) {
    function octopus_ai_fetch_live_manual_context($metadata_chunks, $lang, $question = '')
    {
        error_log('[Octopus AI] Live manual helper ontbreekt, live-context wordt overgeslagen.');

        return [
            'text'    => '',
            'sources' => [],
            'best_source' => '',
            'best_score'  => 0.0,
            'snippets'    => [],
            'errors'  => [],
        ];
    }
}

if (file_exists(__DIR__ . '/helpers/live-manual.php')) {
    require_once __DIR__ . '/helpers/live-manual.php';
}

// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ REST API endpoint registreren
add_action('rest_api_init', function () {
    register_rest_route('octopus-ai/v1', '/chatbot', array(
        'methods' => 'POST',
        'callback' => 'octopus_ai_chatbot_callback',
        'permission_callback' => '__return_true'
    ));

    register_rest_route('octopus-ai/v1', '/feedback', array(
        'methods'  => 'POST',
        'callback' => 'octopus_ai_save_feedback',
        'permission_callback' => '__return_true',
    ));
});


// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Frontend instellingen beschikbaar maken via AJAX
add_action('wp_ajax_octopus_ai_get_settings', 'octopus_ai_get_settings');
add_action('wp_ajax_nopriv_octopus_ai_get_settings', 'octopus_ai_get_settings');

function octopus_ai_is_valid_url($url) {
    $cache_key = 'octopus_ai_urlcheck_' . md5($url);
    $cached = get_transient($cache_key);
    if (!is_null($cached)) return $cached;

    $response = wp_remote_head($url, [
        'timeout' => 5,
        'redirection' => 3,
    ]);
    $is_valid = false;

    if (!is_wp_error($response)) {
        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status === 405 || $status === 0) {
            $response = wp_remote_get($url, [
                'timeout' => 5,
                'redirection' => 3,
            ]);
            if (!is_wp_error($response)) {
                $status = (int) wp_remote_retrieve_response_code($response);
            } else {
                $status = 0;
            }
        }

        // Beschouw 200-399 of 403 als geldig (sommige handleidinglinks vereisen login)
        $is_valid = ($status >= 200 && $status < 400) || $status === 403;
    }

    set_transient($cache_key, $is_valid, 12 * HOUR_IN_SECONDS);
    return $is_valid;
}


if (!function_exists('octopus_ai_trim_surrounding_quotes')) {
    function octopus_ai_trim_surrounding_quotes($text)
    {
        $trimmed = trim((string) $text);
        if ($trimmed === '') {
            return $trimmed;
        }

        $pairs = [
            ['"', '"'],
            ['ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œ', 'ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â'],
            ['ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾', 'ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œ'],
            ['Ãƒâ€šÃ‚Â«', 'Ãƒâ€šÃ‚Â»'],
        ];

        foreach ($pairs as $pair) {
            [$open, $close] = $pair;
            $open_length  = function_exists('octopus_ai_string_length')
                ? octopus_ai_string_length($open)
                : strlen((string) $open);
            $close_length = function_exists('octopus_ai_string_length')
                ? octopus_ai_string_length($close)
                : strlen((string) $close);
            if (
                (function_exists('octopus_ai_string_substr') ? octopus_ai_string_substr($trimmed, 0, $open_length) : substr((string) $trimmed, 0, $open_length)) === $open &&
                (function_exists('octopus_ai_string_substr') ? octopus_ai_string_substr($trimmed, -$close_length) : substr((string) $trimmed, -$close_length)) === $close &&
                (function_exists('octopus_ai_string_length') ? octopus_ai_string_length($trimmed) : strlen((string) $trimmed)) >= ($open_length + $close_length)
            ) {
                $trimmed_length = function_exists('octopus_ai_string_length')
                    ? octopus_ai_string_length($trimmed)
                    : strlen((string) $trimmed);
                $inner = function_exists('octopus_ai_string_substr')
                    ? octopus_ai_string_substr($trimmed, $open_length, $trimmed_length - $open_length - $close_length)
                    : substr((string) $trimmed, $open_length, $trimmed_length - $open_length - $close_length);
                return trim($inner);
            }
        }

        return $trimmed;
    }
}

if (!function_exists('octopus_ai_string_length')) {
    function octopus_ai_string_length($value)
    {
        $value = (string) $value;
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value);
        }

        return strlen($value);
    }
}

if (!function_exists('octopus_ai_string_substr')) {
    function octopus_ai_string_substr($value, $start, $length = null)
    {
        $value = (string) $value;
        $start = (int) $start;
        if ($length === null) {
            if (function_exists('mb_substr')) {
                return (string) mb_substr($value, $start);
            }
            return (string) substr($value, $start);
        }

        $length = (int) $length;
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, $start, $length);
        }

        return (string) substr($value, $start, $length);
    }
}

if (!function_exists('octopus_ai_utf16be_to_utf8')) {
    function octopus_ai_utf16be_to_utf8($binary)
    {
        $binary = (string) $binary;
        if ($binary === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-16BE', 'UTF-8//IGNORE', $binary);
            if ($converted !== false) {
                return (string) $converted;
            }
        }

        return '';
    }
}

if (!function_exists('octopus_ai_normalize_utf8')) {
    function octopus_ai_normalize_utf8($text)
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($converted !== false) {
                return (string) $converted;
            }
        }

        return $text;
    }
}

if (!function_exists('octopus_ai_normalize_scope_text')) {
    function octopus_ai_normalize_scope_text($value)
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return '';
        }

        if (function_exists('remove_accents')) {
            $value = strtolower(remove_accents($value));
        }

        $value = preg_replace('/\s+/u', ' ', (string) $value);
        return trim((string) $value);
    }
}

if (!function_exists('octopus_ai_get_topic_terms_map')) {
    function octopus_ai_get_topic_terms_map()
    {
        $topic_terms = [
            'klantenportaal' => ['klantenportaal', 'platform', 'plateforme', 'plateforme digitale interactive', 'pdi', 'portal', 'portail', 'klant', 'client', 'factuur', 'facture', 'betaling', 'paiement', 'upload'],
            'boekhoudprogramma' => ['boekhoud', 'boekhouding', 'boekhoudprogramma', 'compta', 'comptabilite', 'btw', 'tva', 'journaal', 'journal', 'balans', 'rapport'],
        ];

        $provider_profile = function_exists('octopus_ai_get_provider_profile')
            ? octopus_ai_get_provider_profile()
            : [];

        if (!isset($provider_profile['topic_terms']) || !is_array($provider_profile['topic_terms'])) {
            return $topic_terms;
        }

        foreach ($provider_profile['topic_terms'] as $topic_key => $topic_keywords) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '' || !is_array($topic_keywords)) {
                continue;
            }

            $clean_terms = [];
            foreach ($topic_keywords as $topic_keyword) {
                $normalized_keyword = octopus_ai_normalize_scope_text($topic_keyword);
                if ($normalized_keyword === '' || in_array($normalized_keyword, $clean_terms, true)) {
                    continue;
                }
                $clean_terms[] = $normalized_keyword;
            }

            if (!empty($clean_terms)) {
                $topic_terms[$topic_key] = $clean_terms;
            }
        }

        return $topic_terms;
    }
}

if (!function_exists('octopus_ai_get_topic_label')) {
    function octopus_ai_get_topic_label($topic, $lang = 'NL')
    {
        $topic = sanitize_key((string) $topic);
        $lang = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';

        $labels = [
            'klantenportaal' => [
                'NL' => 'Klantenportaal',
                'FR' => 'Plateforme Digitale Interactive (PDI)',
            ],
            'boekhoudprogramma' => [
                'NL' => 'Boekhoudprogramma',
                'FR' => 'Logiciel de comptabilite',
            ],
        ];

        if (!isset($labels[$topic])) {
            return $topic;
        }

        return $labels[$topic][$lang] ?? $labels[$topic]['NL'];
    }
}

if (!function_exists('octopus_ai_detect_topic_mismatch')) {
    function octopus_ai_detect_topic_mismatch($message, $selected_topic = '')
    {
        $selected_topic = sanitize_key((string) $selected_topic);
        if ($selected_topic === '') {
            return '';
        }

        $topic_terms_map = octopus_ai_get_topic_terms_map();
        if (!isset($topic_terms_map[$selected_topic])) {
            return '';
        }

        $normalized = octopus_ai_normalize_scope_text($message);
        if ($normalized === '') {
            return '';
        }

        $score_for_topic = static function ($haystack, array $terms) {
            $score = 0;
            foreach ($terms as $term) {
                $term = octopus_ai_normalize_scope_text($term);
                if ($term === '') {
                    continue;
                }

                if (strpos($haystack, $term) !== false) {
                    $score++;
                }
            }
            return $score;
        };

        $selected_score = $score_for_topic($normalized, (array) $topic_terms_map[$selected_topic]);
        if ($selected_score > 0) {
            return '';
        }

        $best_other_topic = '';
        $best_other_score = 0;
        foreach ($topic_terms_map as $topic_key => $terms) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '' || $topic_key === $selected_topic) {
                continue;
            }

            $score = $score_for_topic($normalized, (array) $terms);
            if ($score > $best_other_score) {
                $best_other_score = $score;
                $best_other_topic = $topic_key;
            }
        }

        if ($best_other_score <= 0) {
            return '';
        }

        return $best_other_topic;
    }
}

if (!function_exists('octopus_ai_is_3d_printing_question')) {
    function octopus_ai_is_3d_printing_question($message)
    {
        $normalized = octopus_ai_normalize_scope_text($message);
        if ($normalized === '') {
            return false;
        }

        $has_term = static function ($haystack, $term) {
            $term = trim((string) $term);
            if ($term === '') {
                return false;
            }

            $pattern = '/\b' . preg_quote($term, '/') . '\b/u';
            return preg_match($pattern, $haystack) === 1;
        };

        $high_confidence_patterns = [
            '/\b3d[\s-]*print(?:en|er|ing)?\b/u',
            '/\b3d[\s-]*printer\b/u',
            '/\bimpression\s*3d\b/u',
            '/\bimprimante\s*3d\b/u',
            '/\b(?:filament|resin|resine|hars|stl|gcode|g-code|nozzle|slicer)\b/u',
        ];

        foreach ($high_confidence_patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        $short_material_terms = ['pla', 'abs', 'petg', 'fdm', 'sla'];
        $context_terms = ['3d', 'print', 'printer', 'impression', 'imprimante', 'filament', 'resin', 'resine', 'hars', 'stl', 'gcode', 'g-code', 'nozzle', 'slicer'];

        $has_short_material = false;
        foreach ($short_material_terms as $term) {
            if ($has_term($normalized, $term)) {
                $has_short_material = true;
                break;
            }
        }

        if (!$has_short_material) {
            return false;
        }

        foreach ($context_terms as $context_term) {
            if ($has_term($normalized, $context_term)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('octopus_ai_get_3d_printing_easter_egg_answer')) {
    function octopus_ai_get_3d_printing_easter_egg_answer($lang = 'NL')
    {
        $lang = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';

        if ($lang === 'FR') {
            return "Pour tout ce qui concerne l'impression 3D, nous recommandons **X3DPrints.be**.\n\nConsultez: [X3DPrints.be](https://x3dprints.be)";
        }

        return "Voor alles rond 3D-printen raden we **X3DPrints.be** aan.\n\nBekijk: [X3DPrints.be](https://x3dprints.be)";
    }
}

if (!function_exists('octopus_ai_get_no_solution_message')) {
    function octopus_ai_get_no_solution_message($lang = 'NL', $fallback = '')
    {
        $lang = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';
        $fallback = sanitize_text_field((string) $fallback);

        $base = ($lang === 'FR')
            ? 'Je ne trouve pas de solution fiable pour cette question dans la documentation disponible. Pour eviter des erreurs, je prefere ne pas supposer.'
            : 'Ik vind geen betrouwbare oplossing voor deze vraag in de beschikbare documentatie. Om fouten te vermijden ga ik hier niet op gokken.';

        if ($fallback !== '' && stripos($base, $fallback) === false) {
            $base .= "\n\n" . $fallback;
        }

        return $base;
    }
}

if (!function_exists('octopus_ai_build_no_solution_answer')) {
    function octopus_ai_build_no_solution_answer($lang, $message, $fallback = '')
    {
        $lang = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';
        $message = (string) $message;
        $answer = octopus_ai_get_no_solution_message($lang, $fallback);

        if (!function_exists('octopus_ai_extract_keyword')) {
            $keyword_helper = plugin_dir_path(__FILE__) . 'helpers/extract-keyword.php';
            if (file_exists($keyword_helper)) {
                require_once $keyword_helper;
            }
        }

        if (function_exists('octopus_ai_extract_keyword')) {
            $keyword = octopus_ai_extract_keyword($message);
            if ($keyword) {
                $search_url = function_exists('octopus_ai_get_manual_search_url')
                    ? octopus_ai_get_manual_search_url($lang, $keyword)
                    : ("https://login.octopus.be/manual/{$lang}/hmftsearch.htm?zoom_query=" . rawurlencode($keyword));
                $link_text = ($lang === 'FR')
                    ? 'Voir aussi dans la documentation'
                    : 'Bekijk mogelijke info in de handleiding';
                $answer .= "\n\n[$link_text]($search_url)";
            }
        }

        $answer = octopus_ai_apply_language_glossary($answer, $lang);
        return octopus_ai_sanitize_answer_output($answer);
    }
}

if (!function_exists('octopus_ai_is_in_scope_question')) {
    function octopus_ai_is_in_scope_question($message, $topic = '', $history = [])
    {
        $normalized = strtolower(trim((string) $message));
        if ($normalized === '') {
            return false;
        }

        if (function_exists('remove_accents')) {
            $normalized = strtolower(remove_accents($normalized));
        }

        $normalized = preg_replace('/\s+/u', ' ', (string) $normalized);

        $affirmations = [
            'ja',
            'ok',
            'oke',
            'okee',
            'yes',
            'oui',
            "d'accord",
            'daccord',
            'merci',
            'bedankt',
            'thanks',
            'doe maar',
            'ga verder',
            'verder',
            'graag',
        ];
        if (in_array($normalized, $affirmations, true)) {
            return true;
        }

        $contains_any = static function ($haystack, array $terms) {
            foreach ($terms as $term) {
                if ($term !== '' && strpos($haystack, $term) !== false) {
                    return true;
                }
            }
            return false;
        };

        $default_brand_terms = [
            'octopus',
            'octopus dms',
            'octopusdms',
            'login.octopus.be',
            'academy.octopus.be',
        ];

        $default_domain_terms = [
            'klantenportaal',
            'plateforme digitale interactive',
            'pdi',
            'portail client',
            'boekhoud',
            'compta',
            'comptabilite',
            'factuur',
            'facture',
            'facturation',
            'creditnota',
            'avoir',
            'offerte',
            'devis',
            'betaling',
            'paiement',
            'bank',
            'coda',
            'peppol',
            'btw',
            'tva',
            'intervat',
            'journaal',
            'journal',
            'module',
            'instelling',
            'configuration',
            'configuratie',
            'inloggen',
            'login',
            'gebruiker',
            'utilisateur',
            'leverancier',
            'fournisseur',
            'uittreksel',
            'rapport',
            'bijlage',
            'upload',
            'dossier',
            'document',
            'handleiding',
            'manual',
            'support',
        ];

        $default_topic_terms = [
            'klantenportaal' => ['klantenportaal', 'platform', 'plateforme', 'plateforme digitale interactive', 'pdi', 'portal', 'portail', 'klant', 'client', 'factuur', 'facture', 'betaling', 'paiement', 'upload'],
            'boekhoudprogramma' => ['boekhoud', 'compta', 'comptabilite', 'btw', 'tva', 'journaal', 'journal', 'balans', 'rapport'],
        ];

        $default_off_topic_terms = [
            'weer',
            'meteo',
            'weather',
            'temperatuur',
            'voetbal',
            'football',
            'basket',
            'tennis',
            'bitcoin',
            'crypto',
            'aandelen',
            'bourse',
            'recept',
            'recette',
            'koken',
            'restaurant',
            'film',
            'serie',
            'muziek',
            'music',
            'song',
            'grap',
            'joke',
            'politiek',
            'verkiezing',
            'election',
            'vakantie',
            'vacances',
            'voyage',
            'travel',
            'horoscoop',
            'astrologie',
            'python',
            'javascript',
            'css',
            'html',
            'linux',
            'windows',
            'android',
            'iphone',
            'game',
            'gaming',
        ];

        $provider_profile = function_exists('octopus_ai_get_provider_profile')
            ? octopus_ai_get_provider_profile()
            : [];

        $brand_terms = isset($provider_profile['brand_terms']) && is_array($provider_profile['brand_terms']) && !empty($provider_profile['brand_terms'])
            ? array_values($provider_profile['brand_terms'])
            : $default_brand_terms;
        $domain_terms = isset($provider_profile['domain_terms']) && is_array($provider_profile['domain_terms']) && !empty($provider_profile['domain_terms'])
            ? array_values($provider_profile['domain_terms'])
            : $default_domain_terms;
        $off_topic_terms = isset($provider_profile['off_topic_terms']) && is_array($provider_profile['off_topic_terms']) && !empty($provider_profile['off_topic_terms'])
            ? array_values($provider_profile['off_topic_terms'])
            : $default_off_topic_terms;

        $topic_terms = $default_topic_terms;
        if (isset($provider_profile['topic_terms']) && is_array($provider_profile['topic_terms'])) {
            foreach ($provider_profile['topic_terms'] as $topic_key => $topic_keywords) {
                $topic_key = sanitize_key((string) $topic_key);
                if ($topic_key === '' || !is_array($topic_keywords)) {
                    continue;
                }

                $clean_terms = [];
                foreach ($topic_keywords as $topic_keyword) {
                    $topic_keyword = strtolower(trim(sanitize_text_field((string) $topic_keyword)));
                    if ($topic_keyword === '' || in_array($topic_keyword, $clean_terms, true)) {
                        continue;
                    }
                    $clean_terms[] = $topic_keyword;
                }

                if (!empty($clean_terms)) {
                    $topic_terms[$topic_key] = $clean_terms;
                }
            }
        }

        if ($contains_any($normalized, $brand_terms)) {
            return true;
        }

        if ($contains_any($normalized, $domain_terms)) {
            return true;
        }

        if ($topic !== '' && isset($topic_terms[$topic]) && $contains_any($normalized, $topic_terms[$topic])) {
            return true;
        }

        if ($contains_any($normalized, $off_topic_terms)) {
            return false;
        }

        $has_history = is_array($history) && !empty($history);
        $normalized_length = function_exists('octopus_ai_string_length')
            ? octopus_ai_string_length($normalized)
            : strlen((string) $normalized);
        if (
            $has_history &&
            $normalized_length <= 80 &&
            preg_match('/\b(hoe|waar|welke|wat|kan|mag|moet|comment|ou|quel|quelle|puis|peux|dois|faut)\b/u', $normalized)
        ) {
            return true;
        }

        return false;
    }
}

if (!function_exists('octopus_ai_answer_contains_allowed_manual_link')) {
    function octopus_ai_answer_contains_allowed_manual_link($answer, $lang)
    {
        $answer = (string) $answer;
        if ($answer === '') {
            return false;
        }

        if (!preg_match_all('/https?:\/\/[^\s)\]]+/i', $answer, $matches) || empty($matches[0])) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            $url = esc_url_raw((string) $candidate);
            if ($url !== '' && octopus_ai_is_allowed_manual_url($url, $lang)) {
                return true;
            }
        }

        return false;
    }
}


function octopus_ai_get_settings()
{
    $lang = octopus_ai_get_request_language();
    $welcome_nl = (string) get_option('octopus_ai_welcome_message_nl', '');
    $welcome_fr = (string) get_option('octopus_ai_welcome_message_fr', '');
    $welcome_legacy = (string) get_option('octopus_ai_welcome_message', '');
    $welcome = $lang === 'FR'
        ? ($welcome_fr !== '' ? $welcome_fr : "Bonjour ! Comment puis-je t'aider aujourd'hui ?")
        : ($welcome_nl !== '' ? $welcome_nl : ($welcome_legacy !== '' ? $welcome_legacy : 'Hallo! Hoe kan ik je vandaag helpen?'));

    wp_send_json_success(array(
        'primary_color'     => get_option('octopus_ai_primary_color', '#0f6c95'),
        'brand_name'        => get_option('octopus_ai_brand_name', 'AI Chatbot'),
        'logo_url'          => get_option('octopus_ai_logo_url', ''),
        'welcome_message'   => $welcome,
        'feedback_url'      => esc_url_raw(rest_url('octopus-ai/v1/feedback')),
    ));
}

if (!function_exists('octopus_ai_get_request_language')) {
    function octopus_ai_get_request_language()
    {
        $request_uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $lang_header = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));

        if (preg_match('#/fr(/|$)#', $request_uri)) {
            return 'FR';
        }

        if (strpos($lang_header, 'fr') === 0) {
            return 'FR';
        }

        return 'NL';
    }
}

if (!function_exists('octopus_ai_get_client_ip')) {
    function octopus_ai_get_client_ip()
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if (strpos($candidate, ',') !== false) {
                $parts = explode(',', $candidate);
                $candidate = trim((string) ($parts[0] ?? ''));
            }

            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }
}

if (!function_exists('octopus_ai_check_rate_limit')) {
    function octopus_ai_check_rate_limit($bucket, $limit, $window_seconds)
    {
        $bucket = sanitize_key((string) $bucket);
        $limit = max(1, (int) $limit);
        $window_seconds = max(10, (int) $window_seconds);

        $ip = octopus_ai_get_client_ip();
        $site = function_exists('home_url') ? home_url('/') : 'local';
        $cache_key = 'octopus_ai_rl_' . md5($site . '|' . $bucket . '|' . $ip);
        $now = time();

        $state = get_transient($cache_key);
        if (!is_array($state) || !isset($state['count'], $state['reset_at'])) {
            $state = [
                'count' => 0,
                'reset_at' => $now + $window_seconds,
            ];
        }

        if ((int) $state['reset_at'] <= $now) {
            $state = [
                'count' => 0,
                'reset_at' => $now + $window_seconds,
            ];
        }

        if ((int) $state['count'] >= $limit) {
            $retry_after = max(1, (int) $state['reset_at'] - $now);

            return new WP_Error(
                'octopus_ai_rate_limited',
                __('Te veel aanvragen. Probeer het zo meteen opnieuw.', 'octopus-ai'),
                [
                    'status' => 429,
                    'retry_after' => $retry_after,
                ]
            );
        }

        $state['count'] = (int) $state['count'] + 1;
        set_transient($cache_key, $state, $window_seconds);

        return true;
    }
}

if (!function_exists('octopus_ai_sanitize_client_history')) {
    function octopus_ai_sanitize_client_history($history, $max_messages = 10)
    {
        if (!is_array($history)) {
            return [];
        }

        $max_messages = max(1, (int) $max_messages);
        $sanitized = [];

        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $role = isset($entry['role']) ? sanitize_text_field((string) $entry['role']) : '';
            if ($role !== 'user') {
                continue;
            }

            $content = isset($entry['content']) ? sanitize_textarea_field((string) $entry['content']) : '';
            $content = trim((string) preg_replace('/\s+/u', ' ', $content));
            if ($content === '') {
                continue;
            }

            $sanitized[] = [
                'role' => 'user',
                'content' => $content,
            ];
        }

        if (count($sanitized) > $max_messages) {
            $sanitized = array_slice($sanitized, -$max_messages);
        }

        return $sanitized;
    }
}

if (!function_exists('octopus_ai_sanitize_answer_output')) {
    function octopus_ai_sanitize_answer_output($answer)
    {
        $answer = (string) $answer;
        $answer = preg_replace("/\r\n?/", "\n", $answer);
        $answer = wp_strip_all_tags($answer, false);
        $answer = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $answer);
        $answer = preg_replace("/\n{3,}/", "\n\n", $answer);
        $answer = trim($answer);

        if (function_exists('mb_substr')) {
            $answer = mb_substr($answer, 0, 8000);
        } else {
            $answer = substr($answer, 0, 8000);
        }

        return trim($answer);
    }
}

if (!function_exists('octopus_ai_get_openai_circuit_key')) {
    function octopus_ai_get_openai_circuit_key()
    {
        $site = function_exists('home_url') ? home_url('/') : 'local';
        return 'octopus_ai_openai_circuit_' . md5($site);
    }
}

if (!function_exists('octopus_ai_get_openai_circuit_state')) {
    function octopus_ai_get_openai_circuit_state()
    {
        $state = get_transient(octopus_ai_get_openai_circuit_key());
        if (!is_array($state)) {
            return [
                'failures' => 0,
                'open_until' => 0,
            ];
        }

        return [
            'failures' => isset($state['failures']) ? max(0, (int) $state['failures']) : 0,
            'open_until' => isset($state['open_until']) ? max(0, (int) $state['open_until']) : 0,
        ];
    }
}

if (!function_exists('octopus_ai_set_openai_circuit_state')) {
    function octopus_ai_set_openai_circuit_state($state)
    {
        $normalized = [
            'failures' => isset($state['failures']) ? max(0, (int) $state['failures']) : 0,
            'open_until' => isset($state['open_until']) ? max(0, (int) $state['open_until']) : 0,
        ];

        set_transient(octopus_ai_get_openai_circuit_key(), $normalized, 30 * MINUTE_IN_SECONDS);
    }
}

if (!function_exists('octopus_ai_mark_openai_failure')) {
    function octopus_ai_mark_openai_failure()
    {
        $state = octopus_ai_get_openai_circuit_state();
        $state['failures'] = (int) $state['failures'] + 1;

        // Open het circuit tijdelijk na opeenvolgende fouten.
        if ((int) $state['failures'] >= 5) {
            $state['open_until'] = time() + (5 * MINUTE_IN_SECONDS);
        }

        octopus_ai_set_openai_circuit_state($state);
    }
}

if (!function_exists('octopus_ai_reset_openai_circuit')) {
    function octopus_ai_reset_openai_circuit()
    {
        octopus_ai_set_openai_circuit_state([
            'failures' => 0,
            'open_until' => 0,
        ]);
    }
}

if (!function_exists('octopus_ai_is_retryable_openai_status')) {
    function octopus_ai_is_retryable_openai_status($status_code)
    {
        $status_code = (int) $status_code;
        return in_array($status_code, [408, 409, 425, 429, 500, 502, 503, 504], true);
    }
}

if (!function_exists('octopus_ai_openai_chat_completion_with_retry')) {
    function octopus_ai_openai_chat_completion_with_retry($api_key, array $messages, $model, $max_attempts = 3)
    {
        $circuit = octopus_ai_get_openai_circuit_state();
        if ((int) $circuit['open_until'] > time()) {
            $remaining = max(1, (int) $circuit['open_until'] - time());
            return new WP_Error(
                'octopus_ai_circuit_open',
                sprintf(__('De AI-service is tijdelijk onbeschikbaar. Probeer opnieuw binnen %d seconden.', 'octopus-ai'), $remaining),
                ['status' => 503]
            );
        }

        $attempt = 0;
        $last_wp_error = null;
        $last_status_code = 0;
        $last_body = '';
        $last_retryable_failure = false;

        while ($attempt < $max_attempts) {
            $attempt++;

            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body' => wp_json_encode([
                    'model' => $model,
                    'messages' => $messages,
                ]),
                'timeout' => 20,
            ]);

            if (is_wp_error($response)) {
                $last_wp_error = $response;
                $last_retryable_failure = true;
            } else {
                $last_status_code = (int) wp_remote_retrieve_response_code($response);
                $last_body = (string) wp_remote_retrieve_body($response);

                if ($last_status_code >= 200 && $last_status_code < 300) {
                    octopus_ai_reset_openai_circuit();
                    return [
                        'status_code' => $last_status_code,
                        'body_json' => $last_body,
                        'body' => json_decode($last_body, true),
                    ];
                }

                $last_retryable_failure = octopus_ai_is_retryable_openai_status($last_status_code);
            }

            $should_retry = is_wp_error($response) || octopus_ai_is_retryable_openai_status($last_status_code);
            if (!$should_retry || $attempt >= $max_attempts) {
                break;
            }

            try {
                $jitter = random_int(0, 150);
            } catch (Exception $e) {
                $jitter = wp_rand(0, 150);
            }

            $sleep_ms = (int) min(1800, 250 * (2 ** ($attempt - 1)) + (int) $jitter);
            usleep($sleep_ms * 1000);
        }

        if ($last_retryable_failure) {
            octopus_ai_mark_openai_failure();
        } else {
            octopus_ai_reset_openai_circuit();
        }

        if ($last_wp_error instanceof WP_Error) {
            return new WP_Error(
                'api_error',
                __('Technische fout bij het ophalen van het antwoord.', 'octopus-ai'),
                ['status' => 502]
            );
        }

        $decoded = json_decode($last_body, true);
        $error_message = is_array($decoded) && isset($decoded['error']['message'])
            ? sanitize_text_field((string) $decoded['error']['message'])
            : __('Onbekende fout van de AI-service.', 'octopus-ai');
        $status_for_client = ($last_status_code >= 400 && $last_status_code < 600) ? $last_status_code : 502;

        return new WP_Error(
            'api_error',
            sprintf(__('Fout van OpenAI (HTTP %1$d): %2$s', 'octopus-ai'), $last_status_code, $error_message),
            ['status' => $status_for_client]
        );
    }
}

// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Chatbot callback
function octopus_ai_chatbot_callback($request)
{
    try {
    $message = sanitize_text_field((string) $request->get_param('message'));
    $history = octopus_ai_sanitize_client_history($request->get_param('history') ?? [], 10);
    $topic = sanitize_key((string) $request->get_param('topic'));
    $skip_topic_mismatch_raw = $request->get_param('skip_topic_mismatch');
    $skip_topic_mismatch = false;
    if (is_bool($skip_topic_mismatch_raw)) {
        $skip_topic_mismatch = $skip_topic_mismatch_raw;
    } else {
        $skip_topic_mismatch = in_array(
            strtolower(trim((string) $skip_topic_mismatch_raw)),
            array('1', 'true', 'yes', 'on'),
            true
        );
    }
    $allowed_topics = ['klantenportaal', 'boekhoudprogramma'];
    if (!in_array($topic, $allowed_topics, true)) {
        $topic = '';
    }

    if ($message === '') {
        return new WP_Error('octopus_ai_empty_message', __('Leeg bericht ontvangen.', 'octopus-ai'), ['status' => 400]);
    }

    $message_length = function_exists('octopus_ai_string_length')
        ? octopus_ai_string_length($message)
        : strlen((string) $message);
    if ($message_length > 1500) {
        return new WP_Error('octopus_ai_message_too_long', __('Bericht is te lang. Hou het onder 1500 tekens.', 'octopus-ai'), ['status' => 400]);
    }

    $rate_limit = octopus_ai_check_rate_limit('chatbot', 20, 5 * MINUTE_IN_SECONDS);
    if (is_wp_error($rate_limit)) {
        return $rate_limit;
    }

    $intent = octopus_ai_detect_intent($message);
    if ($intent) {
        error_log('[Octopus AI] Gedetecteerde intent: ' . $intent);
    }

   // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Detecteer taal op basis van URL of browserinstellingen
    $lang = octopus_ai_get_request_language();

    if (octopus_ai_is_3d_printing_question($message)) {
        $easter_egg_answer = octopus_ai_get_3d_printing_easter_egg_answer($lang);
        $easter_egg_answer = octopus_ai_sanitize_answer_output($easter_egg_answer);

        return rest_ensure_response([
            'answer' => $easter_egg_answer,
            'chat_id' => 0,
            'status' => 'easter_egg_3d',
        ]);
    }

    $topic_mismatch = (!$skip_topic_mismatch) ? octopus_ai_detect_topic_mismatch($message, $topic) : '';
    if ($topic_mismatch !== '') {
        $current_label = octopus_ai_get_topic_label($topic, $lang);
        $suggested_label = octopus_ai_get_topic_label($topic_mismatch, $lang);

        $mismatch_answer = ($lang === 'FR')
            ? sprintf(
                "Tu es actuellement dans le flux %s, mais ta question semble concerner %s. Souhaites-tu basculer vers ce flux ?",
                $current_label,
                $suggested_label
            )
            : sprintf(
                "Je zit momenteel in de flow %s, maar je vraag lijkt over %s te gaan. Wil je overschakelen naar die flow?",
                $current_label,
                $suggested_label
            );
        $mismatch_answer = octopus_ai_apply_language_glossary($mismatch_answer, $lang);

        return rest_ensure_response([
            'answer' => octopus_ai_sanitize_answer_output($mismatch_answer),
            'chat_id' => 0,
            'status' => 'topic_mismatch',
            'suggested_topic' => $topic_mismatch,
            'current_topic' => $topic,
        ]);
    }

    if (!octopus_ai_is_in_scope_question($message, $topic, $history)) {
        $out_of_scope_default = ($lang === 'FR')
            ? 'Desole, je reponds uniquement aux questions liees a Octopus.'
            : 'Sorry, ik beantwoord enkel vragen die over Octopus gaan.';
        $out_of_scope_answer = function_exists('octopus_ai_get_provider_out_of_scope_text')
            ? octopus_ai_get_provider_out_of_scope_text($lang, $out_of_scope_default)
            : $out_of_scope_default;
        $out_of_scope_answer = octopus_ai_apply_language_glossary($out_of_scope_answer, $lang);
        $out_of_scope_answer = octopus_ai_sanitize_answer_output($out_of_scope_answer);

        return rest_ensure_response([
            'answer' => $out_of_scope_answer,
            'chat_id' => 0,
            'status' => 'out_of_scope',
        ]);
    }

    $manual_mode = octopus_ai_get_manual_mode();
    $source_strategy = get_option('octopus_ai_source_strategy', '');
    if ($source_strategy === '') {
        $source_strategy = ($manual_mode === 'live') ? 'live_manual' : 'manual_upload';
    }
    $use_live_manual = in_array($manual_mode, ['live', 'hybrid'], true);
    $use_local_chunks = $manual_mode !== 'live';

    $api_key = trim((string) get_option('octopus_ai_api_key'));
    if ($lang === 'FR') {
    $tone = <<<EOT
ÃƒÂ°Ã…Â¸Ã…Â½Ã‚Â¯ Objectif
Tu es un chatbot professionnel qui aide les clients ÃƒÆ’Ã‚Â  utiliser Octopus de maniÃƒÆ’Ã‚Â¨re claire, efficace et conviviale.

Fournis des rÃƒÆ’Ã‚Â©ponses directes et utiles sur lÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢utilisation dÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢Octopus

Utilise des paragraphes courts, des listes ÃƒÆ’Ã‚Â  puces ou des ÃƒÆ’Ã‚Â©tapes lorsque cela facilite la comprÃƒÆ’Ã‚Â©hension

ÃƒÂ°Ã…Â¸Ã¢â‚¬â€Ã‚Â£ÃƒÂ¯Ã‚Â¸Ã‚Â Ton

Professionnel, chaleureux, adaptÃƒÆ’Ã‚Â© au public belge francophone

Ne mentionne jamais lÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢IA, GPT ou toute technologie similaire

Aucune supposition ou invention : reste factuel et prÃƒÆ’Ã‚Â©cis

Ne tÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢appuie que sur les chunks fournis et sur les pages du manuel autorisÃƒÆ’Ã‚Â©es.

ÃƒÂ°Ã…Â¸Ã…Â¡Ã‚Â« Limitations

RÃƒÆ’Ã‚Â©pond uniquement si un contexte pertinent est disponible

Ne fournis aucune information sur la lÃƒÆ’Ã‚Â©gislation, la comptabilitÃƒÆ’Ã‚Â© ou des logiciels externes

En cas de doute, rÃƒÆ’Ã‚Â©ponds simplement : Ãƒâ€šÃ‚Â« DÃƒÆ’Ã‚Â©solÃƒÆ’Ã‚Â©, je ne peux pas tÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢aider avec ÃƒÆ’Ã‚Â§a. Ãƒâ€šÃ‚Â»

ÃƒÂ°Ã…Â¸Ã¢â‚¬â„¢Ã‚Â¬ Comportement

Si lÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢utilisateur rÃƒÆ’Ã‚Â©pond par Ãƒâ€šÃ‚Â« oui Ãƒâ€šÃ‚Â», Ãƒâ€šÃ‚Â« ok Ãƒâ€šÃ‚Â» ou confirme, continue avec les instructions ou dÃƒÆ’Ã‚Â©tails utiles, sans te rÃƒÆ’Ã‚Â©pÃƒÆ’Ã‚Â©ter inutilement

ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Å¾ Si possible

Ajoute la mention : Ãƒâ€šÃ‚Â« ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Å¾ Voir dans le manuel Ãƒâ€šÃ‚Â» avec un lien valide lorsque cÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢est pertinent

Termine en partageant la liste des trois pages du manuel les plus pertinentes.

Contexte :
EOT;
} else {
    $tone = get_option('octopus_ai_tone') ?: <<<EOT
Je bent een AI-chatbot die klanten professioneel, duidelijk en kort helpt bij het gebruik van deze software.

ÃƒÂ°Ã…Â¸Ã…Â½Ã‚Â¯ Doel:
- Help gebruikers stap voor stap bij hun vraag over de werking van Octopus
- Geef vlotte, concrete en heldere antwoorden
- Gebruik waar nuttig bullets, stappen of korte paragrafen

ÃƒÂ°Ã…Â¸Ã¢â‚¬â€Ã‚Â£ÃƒÂ¯Ã‚Â¸Ã‚Â Tone of voice:
- Vriendelijk, Vlaams professioneel en to the point
- Geen disclaimers of verwijzingen naar AI, GPT of technologie
- Geen veronderstellingen of verzinsels

ÃƒÂ°Ã…Â¸Ã…Â¡Ã‚Â« Beperkingen:
- Beantwoord enkel vragen waarvoor relevante context beschikbaar is
- Geef gÃƒÆ’Ã‚Â©ÃƒÆ’Ã‚Â©n antwoord over wetgeving, boekhoudregels, code of externe software
- Bij twijfel: zeg ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œSorry, daar kan ik je niet mee helpen.ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â

ÃƒÂ°Ã…Â¸Ã¢â‚¬â„¢Ã‚Â¬ Conversatiegedrag:
- Als de gebruiker ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œjaÃƒÂ¢Ã¢â€šÂ¬Ã‚Â, ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œokÃƒÂ¢Ã¢â€šÂ¬Ã‚Â, ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œdoe maarÃƒÂ¢Ã¢â€šÂ¬Ã‚Â of iets bevestigend antwoordt, beschouw dit als een vervolg op je vorige uitleg
- Geef dan het logische volgende stapje of verdieping
- Herhaal in dat geval **niet** je vorige antwoord

ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Å¾ Indien beschikbaar:
- Voeg onderaan toe: ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Å¾ Bekijk dit in de handleidingÃƒÂ¢Ã¢â€šÂ¬Ã‚Â met een juiste link

Gebruik alleen informatie uit de gedeelde context en de toegestane handleiding-URLÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢s.
Sluit af met een opsomming van de drie meest relevante handleidinglinks.

Context:
EOT;
}


    $fallback_default = ($lang === 'FR')
        ? "DÃƒÆ’Ã‚Â©solÃƒÆ’Ã‚Â©, je ne peux pas tÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢aider avec ÃƒÆ’Ã‚Â§a."
        : get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.');
    $fallback = function_exists('octopus_ai_get_provider_fallback_text')
        ? octopus_ai_get_provider_fallback_text($lang, $fallback_default)
        : $fallback_default;

    if ($api_key === '') {
        return new WP_Error(
            'octopus_ai_missing_api_key',
            __('Octopus AI API key ontbreekt. Voeg een geldige key toe op de instellingenpagina.', 'octopus-ai')
        );
    }

    $model = get_option('octopus_ai_model', 'gpt-4.1-mini');
    $context = '';
    $metadata_chunks = [];
    $metadata_chunks_for_live = [];
    $relevantFound = false;
    $live_context = '';
    $live_sources = [];
    $live_best_source = '';
    $live_best_score  = 0.0;
    $reference_candidates = [];

    if (function_exists('octopus_ai_retrieve_relevant_chunks')) {
        if ($use_local_chunks) {
            $result = octopus_ai_retrieve_relevant_chunks($message, $topic);
            $context = $result['context'] ?? '';

            if (isset($result['metadata']['chunks']) && is_array($result['metadata']['chunks'])) {
                $metadata_chunks = $result['metadata']['chunks'];
            } elseif (isset($result['metadatas']) && is_array($result['metadatas'])) {
                $metadata_chunks = $result['metadatas'];
            } elseif (isset($result['metas']) && is_array($result['metas'])) {
                $metadata_chunks = $result['metas'];
            }

            $metadata_chunks_for_live = $metadata_chunks;

            if (!empty($context) && strlen($context) > 20) {
                $relevantFound = true;
            }
        } else {
            $result = octopus_ai_retrieve_relevant_chunks($message, $topic);
            if (isset($result['metadata']['chunks']) && is_array($result['metadata']['chunks'])) {
                $metadata_chunks_for_live = $result['metadata']['chunks'];
            } elseif (isset($result['metadatas']) && is_array($result['metadatas'])) {
                $metadata_chunks_for_live = $result['metadatas'];
            } elseif (isset($result['metas']) && is_array($result['metas'])) {
                $metadata_chunks_for_live = $result['metas'];
            }

            if ($source_strategy !== 'live_manual') {
                $metadata_chunks = $metadata_chunks_for_live;
            }
        }
    }

    if ($use_live_manual) {
        $live_manual = octopus_ai_fetch_live_manual_context($metadata_chunks_for_live, $lang, $message);
        if (is_array($live_manual)) {
            $live_context = isset($live_manual['text']) ? trim((string) $live_manual['text']) : '';
            $live_sources = isset($live_manual['sources']) && is_array($live_manual['sources'])
                ? array_values(array_filter($live_manual['sources']))
                : [];
            $live_sources = array_values(array_filter(
                $live_sources,
                static function ($url) use ($lang) {
                    return octopus_ai_is_allowed_manual_url($url, $lang);
                }
            ));
            $live_best_source = isset($live_manual['best_source'])
                ? esc_url_raw((string) $live_manual['best_source'])
                : '';
            $live_best_score = isset($live_manual['best_score'])
                ? (float) $live_manual['best_score']
                : 0.0;
            if (!empty($live_best_source)) {
                $live_sources = array_values(array_unique(array_merge([$live_best_source], $live_sources)));
            }

            if ($live_context !== '') {
                $relevantFound = true;
            }

            if (!empty($live_manual['errors']) && is_array($live_manual['errors'])) {
                foreach ($live_manual['errors'] as $error_item) {
                    $error_url    = $error_item['url'] ?? '';
                    $error_status = $error_item['status'] ?? '';
                    $error_text   = $error_item['error'] ?? '';

                    if ($error_url === '') {
                        continue;
                    }

                    if ((int) $error_status === 403) {
                        error_log(sprintf('[Octopus AI] Handleiding vereist login (403) voor %s', $error_url));
                    } elseif ($error_status !== 200 && $error_status !== '') {
                        error_log(sprintf('[Octopus AI] Handleiding niet opgehaald (%s) voor %s: %s', $error_status, $error_url, $error_text));
                    } elseif ($error_status === 0 && $error_text !== '') {
                        error_log(sprintf('[Octopus AI] Handleiding niet opgehaald voor %s: %s', $error_url, $error_text));
                    }
                }
            }
        }
    }

    // ÃƒÂ¢Ã‚ÂÃ…â€™ Als er geen relevante context gevonden werd, geef fallback met zoeklink terug
    if (!$relevantFound) {
        return rest_ensure_response([
            'answer' => octopus_ai_build_no_solution_answer($lang, $message, $fallback),
            'chat_id' => 0,
            'status' => 'fallback',
        ]);
    }


    // ÃƒÂ¢Ã…Â¾Ã¢â‚¬Â¢ Prompt opbouwen
    $system_prompt = $tone;

    $topic_scope_instruction = '';
    if ($topic === 'klantenportaal') {
        $topic_scope_instruction = ($lang === 'FR')
            ? "Flux actif: Plateforme Digitale Interactive (PDI). Reponds uniquement dans le cadre de la Plateforme Digitale Interactive (factures, paiements, support client). Si la question concerne le logiciel comptable, demande a l'utilisateur de changer de flux."
            : "Actieve flow: Klantenportaal. Beantwoord enkel binnen de context van het klantenportaal (facturen, betalingen, support in het portaal). Als de vraag over het boekhoudprogramma gaat, vraag de gebruiker om van flow te wisselen.";
    } elseif ($topic === 'boekhoudprogramma') {
        $topic_scope_instruction = ($lang === 'FR')
            ? "Flux actif: Logiciel de comptabilite. Reponds uniquement dans le cadre du logiciel de comptabilite (compta, TVA, journaux, rapports). Si la question concerne la Plateforme Digitale Interactive (PDI), demande a l'utilisateur de changer de flux."
            : "Actieve flow: Boekhoudprogramma. Beantwoord enkel binnen de context van het boekhoudprogramma (boekhouding, btw, dagboeken, rapporten). Als de vraag over het klantenportaal gaat, vraag de gebruiker om van flow te wisselen.";
    }

    if ($topic_scope_instruction !== '') {
        $system_prompt .= "\n\nFlow-instructie:\n" . $topic_scope_instruction;
    }

    if ($context !== '') {
        $system_prompt .= "\n\nContext (chunks):\n" . $context;
    }

    if ($live_context !== '') {
        $system_prompt .= "\n\nLive handleiding (laatste versie):\n" . $live_context;
    }

    if ($context === '' && $live_context === '') {
        $system_prompt .= "\n\nContext:\n";
    }
    $strict_no_solution = octopus_ai_get_no_solution_message($lang, $fallback);
    $strict_rule = ($lang === 'FR')
        ? "Regle anti-hallucination: n'invente rien. Si la solution n'est pas explicitement presente dans le contexte fourni, reponds exactement: \"" . $strict_no_solution . "\""
        : "Strikte anti-hallucinatie regel: verzin niets. Als de oplossing niet expliciet in de beschikbare context staat, antwoord exact: \"" . $strict_no_solution . "\"";
    $system_prompt .= "\n\n" . $strict_rule;
    $system_prompt .= "\n\nOpmerking:\nAls de gebruiker bevestigt dat hij verder geholpen wil worden (bijv. zegt 'ja'), geef dan een inhoudelijk vervolg op het onderwerp, niet een algemene begroeting of herstart.";

    // ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Å¾ Links toevoegen
    $validLinkFound      = false;
    $primary_doc_url     = '';
    $best_metadata_link  = '';
    $best_metadata_score = -1.0;
    if (!empty($metadata_chunks)) {
        $system_prompt .= "\n\nDeze informatie komt uit de volgende onderdelen:\n";
        foreach ($metadata_chunks as $meta) {
            $title        = sanitize_text_field($meta['section_title'] ?? '');
            $slug         = sanitize_text_field($meta['page_slug'] ?? '');
            $current_score = isset($meta['score']) ? (float) $meta['score'] : 0.0;

            $manual_candidates = function_exists('octopus_ai_get_manual_url_candidates')
                ? octopus_ai_get_manual_url_candidates((array) $meta, $lang)
                : [];

            $possible_links = [];

            foreach ($manual_candidates as $candidate_url) {
                $candidate_url = esc_url_raw((string) $candidate_url);
                if ($candidate_url === '') {
                    continue;
                }

                $from_live = in_array($candidate_url, $live_sources, true);
                $is_valid  = $from_live || octopus_ai_is_valid_url($candidate_url);
                if (!$is_valid) {
                    continue;
                }

                $path = (string) wp_parse_url($candidate_url, PHP_URL_PATH);
                $is_manual_path = stripos($path, '/manual/') !== false;

                $possible_links[] = [
                    'url'       => $candidate_url,
                    'is_manual' => $is_manual_path,
                    'is_valid'  => true,
                ];
            }

            if ($title && !empty($possible_links)) {
                $system_prompt .= "- *{$title}*\n";
                foreach ($possible_links as $link_info) {
                    $link  = $link_info['url'];
                    $label = ($lang === 'FR') ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding';
                    if ($link && !$link_info['is_manual']) {
                        $label = ($lang === 'FR') ? 'Voir la source' : 'Bekijk de bron';
                    }

                    $system_prompt .= "  [{$label}]({$link})\n";

                    if ($link_info['is_valid']) {
                        if (
                            $current_score > $best_metadata_score ||
                            ($current_score === $best_metadata_score && $best_metadata_link === '')
                        ) {
                            $best_metadata_score = $current_score;
                            $best_metadata_link  = $link;
                        }
                        $validLinkFound = true;

                        $reference_title = $title;
                        if ($reference_title === '' && $slug !== '') {
                            $reference_title = ucwords(str_replace(['-', '_'], ' ', pathinfo($slug, PATHINFO_FILENAME)));
                        }

                        $reference_candidates[] = [
                            'title' => $reference_title,
                            'url'   => $link,
                            'score' => $current_score,
                        ];
                    }
                }
            }
        }
    }

    if (!empty($live_sources)) {
        $system_prompt .= "\n\nLive bronnen:\n";
        foreach ($live_sources as $index => $link) {
            $label = ($lang === 'FR') ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding';
            if ($link && strpos($link, 'octopus.be/manual') === false) {
                $label = ($lang === 'FR') ? 'Voir la source' : 'Bekijk de bron';
            }
            $system_prompt .= "- [{$label}]({$link})\n";

            $reference_candidates[] = [
                'title' => ($lang === 'FR') ? 'Page du manuel' : 'Handleidingpagina',
                'url'   => $link,
                'score' => max(0.1, $live_best_score - ($index * 0.1)),
            ];
        }
    }

    $preferred_doc_url = $best_metadata_link;
    if (!empty($live_best_source)) {
        $preferred_doc_url = (
            $preferred_doc_url === '' ||
            $live_best_score > $best_metadata_score + 0.01
        ) ? $live_best_source : $preferred_doc_url;
    }

    if ($preferred_doc_url !== '') {
        $primary_doc_url = $preferred_doc_url;
        $validLinkFound  = true;
    }

    if ($primary_doc_url === '' && !empty($live_sources)) {
        $primary_doc_url = $live_sources[0];
        if ($primary_doc_url !== '') {
            $validLinkFound = true;
        }
    }

    if ($primary_doc_url !== '') {
        $reference_candidates[] = [
            'title' => ($lang === 'FR') ? 'Page du manuel' : 'Handleidingpagina',
            'url'   => $primary_doc_url,
            'score' => max($best_metadata_score, $live_best_score, 0.1),
        ];
    }

    // ÃƒÂ¢Ã…Â¾Ã¢â‚¬Â¢ Opbouw history
    $messages = [['role' => 'system', 'content' => $system_prompt]];
    $current_message_present = false;
    foreach ($history as $entry) {
        if (isset($entry['content'])) {
            $content = sanitize_textarea_field((string) $entry['content']);
            if ($content === '') {
                continue;
            }

            $messages[] = [
                'role'    => 'user',
                'content' => $content,
            ];

            if ($content === $message) {
                $current_message_present = true;
            }
        }
    }

    if (!$current_message_present) {
        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];
    }

    // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ API request met retry/backoff + circuit breaker
    $openai_result = octopus_ai_openai_chat_completion_with_retry($api_key, $messages, $model, 3);
    if (is_wp_error($openai_result)) {
        return $openai_result;
    }

    $body_json = (string) ($openai_result['body_json'] ?? '');
    $body = isset($openai_result['body']) && is_array($openai_result['body']) ? $openai_result['body'] : [];
    // ÃƒÂ°Ã…Â¸Ã‚Â§Ã‚Â  AI-antwoord verwerken
    $answer = $body['choices'][0]['message']['content'] ?? '';

    if (!$answer) {
        $error_message = $body['error']['message'] ?? 'Ongeldige API-respons.';
        return new WP_Error('api_error', 'Fout van OpenAI: ' . $error_message);
    }

    // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Unicode-decodering via JSON (zoals \u00e9 ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ ÃƒÆ’Ã‚Â©)
    $decoded_json = json_decode('"' . addcslashes($answer, "\\\"\/\n\r\t") . '"');
    if (is_string($decoded_json)) {
        $answer = $decoded_json;
    }

    // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Unicode-decoding voor uXXXX of \uXXXX (fallback)
    $answer = preg_replace_callback('/\\\\?u([0-9a-fA-F]{4})/', function ($matches) {
        $hex = $matches[1];
        $bin = pack('H*', $hex);
        return function_exists('octopus_ai_utf16be_to_utf8')
            ? octopus_ai_utf16be_to_utf8($bin)
            : '';
    }, $answer);

// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ EÃƒÆ’Ã‚Â©n keer UTF-8 normaliseren
$answer = function_exists('octopus_ai_normalize_utf8')
    ? octopus_ai_normalize_utf8($answer)
    : (string) $answer;

// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Dubbele slashes en quotes strippen
$answer = stripslashes($answer);

// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Decodeer HTML entities (zoals &eacute; ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ ÃƒÆ’Ã‚Â©)
$answer = html_entity_decode($answer, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Decodeer wp-specialchars (zoals &#039; ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ ')
$answer = wp_specialchars_decode($answer, ENT_QUOTES);


// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Emoji verwijderen (blacklist)
$emoji_blacklist = [
    'ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Å¾','ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ…Â½','ÃƒÂ°Ã…Â¸Ã‚Â§Ã‚Â¾','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã…â€™','ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ‚Â','ÃƒÂ°Ã…Â¸Ã¢â‚¬â€Ã¢â‚¬Å¡ÃƒÂ¯Ã‚Â¸Ã‚Â','ÃƒÂ°Ã…Â¸Ã‚Â§Ã‚Â ','ÃƒÂ¢Ã…Â¡Ã¢â€žÂ¢ÃƒÂ¯Ã‚Â¸Ã‚Â','ÃƒÂ°Ã…Â¸Ã…Â¡Ã¢â€šÂ¬','ÃƒÂ°Ã…Â¸Ã¢â‚¬â„¢Ã‚Â¬','ÃƒÂ°Ã…Â¸Ã…Â½Ã‚Â¯','ÃƒÂ°Ã…Â¸Ã¢â‚¬â€Ã‚Â£ÃƒÂ¯Ã‚Â¸Ã‚Â','ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ‚Â½','ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ‚Â¼','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã…Â ',
    'ÃƒÂ°Ã…Â¸Ã‚Â§Ã‚Âª','ÃƒÂ°Ã…Â¸Ã¢â‚¬â„¢Ã‚Â¡','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â','ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ¢â‚¬â€','ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦','ÃƒÂ¢Ã‚ÂÃ…â€™','ÃƒÂ¢Ã…Â¡Ã‚Â ÃƒÂ¯Ã‚Â¸Ã‚Â','ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¹ÃƒÂ¯Ã‚Â¸Ã‚Â','ÃƒÂ°Ã…Â¸Ã‚Â§Ã‚Â¨','ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ‚Â','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â¦','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â¬','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â¥','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â¤','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â',
    'ÃƒÂ°Ã…Â¸Ã¢â‚¬â€Ã†â€™ÃƒÂ¯Ã‚Â¸Ã‚Â','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‹â€ ','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Â°','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Â¦','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Â ','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Â¹','ÃƒÂ¢Ã…â€œÃ¢â‚¬Â°ÃƒÂ¯Ã‚Â¸Ã‚Â','ÃƒÂ°Ã…Â¸Ã¢â‚¬â„¢Ã‚Â»','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â±','ÃƒÂ°Ã…Â¸Ã¢â‚¬â€œÃ‚Â¥ÃƒÂ¯Ã‚Â¸Ã‚Â','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã…Â½','ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â','ÃƒÂ°Ã…Â¸Ã¢â‚¬â€œÃ…Â ÃƒÂ¯Ã‚Â¸Ã‚Â','ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ¢â‚¬â„¢','ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ¢â‚¬Å“',
    'ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂºÃ‚Â ÃƒÂ¯Ã‚Â¸Ã‚Â','ÃƒÂ°Ã…Â¸Ã‚ÂªÃ¢â‚¬Å¾','ÃƒÂ°Ã…Â¸Ã‚Â§Ã‚Â¹','ÃƒÂ°Ã…Â¸Ã‚ÂªÃ‚Âª','ÃƒÂ°Ã…Â¸Ã¢â‚¬â€Ã¢â‚¬ËœÃƒÂ¯Ã‚Â¸Ã‚Â','ÃƒÂ¢Ã‚ÂÃ‚Â³','ÃƒÂ¢Ã…â€™Ã¢â‚¬Âº','ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ‚Â§','ÃƒÂ°Ã…Â¸Ã¢â‚¬ËœÃ…Â½','ÃƒÂ°Ã…Â¸Ã¢â‚¬ËœÃ‚Â'
];
$answer = str_replace($emoji_blacklist, '', $answer);

    // Links sanitiseren: enkel toegestane handleiding-URL's doorlaten
    $answer = preg_replace_callback(
        '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
        function ($m) use ($lang) {
            $text = sanitize_text_field($m[1]);
            $url  = esc_url_raw($m[2]);
            if (!octopus_ai_is_allowed_manual_url($url, $lang) || !octopus_ai_is_valid_url($url)) {
                return $text; // verwijder ongeldige link, behoud tekst
            }
            return '[' . $text . '](' . $url . ')';
        },
        $answer ?? ''
    );

    // Kale URLs die niet toegelaten zijn verwijderen; toegelaten behouden
    $answer = preg_replace_callback(
        '/\bhttps?:\/\/[^\s)]+/i',
        function ($m) use ($lang) {
            $url = esc_url_raw($m[0]);
            if (!octopus_ai_is_allowed_manual_url($url, $lang) || !octopus_ai_is_valid_url($url)) {
                return '';
            }
            return $url;
        },
        $answer ?? ''
    );

// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Dode links naar de handleiding weghalen (optioneel: kan zwaar zijn als er veel zijn)
$answer = preg_replace_callback(
    '/\((https?:\/\/[^\s)]+)\)/',
    function ($matches) use ($lang) {
        $url = esc_url_raw((string) ($matches[1] ?? ''));
        if ($url === '') {
            return '';
        }

        if (!octopus_ai_is_allowed_manual_url($url, $lang) || !octopus_ai_is_valid_url($url)) {
            return '';
        }

        return '(' . $url . ')';
    },
    $answer
);
$answer = wp_specialchars_decode($answer, ENT_QUOTES);
$answer = html_entity_decode($answer, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$answer = octopus_ai_trim_surrounding_quotes($answer);
$answer = octopus_ai_apply_language_glossary($answer, $lang);
$answer = octopus_ai_sanitize_answer_output($answer);

if (trim($answer) === '' || trim($answer) === trim($fallback)) {
    $answer = octopus_ai_build_no_solution_answer($lang, $message, $fallback);
}

$has_manual_link = octopus_ai_answer_contains_allowed_manual_link($answer, $lang);

// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Fallback-zoeklink als geen geldige link gevonden is
if (
    !$validLinkFound &&
    (
        trim($answer) === trim($fallback) ||
        !$has_manual_link
    )
) {
    if (!function_exists('octopus_ai_extract_keyword')) {
        require_once plugin_dir_path(__FILE__) . 'helpers/extract-keyword.php';
    }
    $keyword = octopus_ai_extract_keyword($message);
    if ($keyword) {
        $zoeklink = function_exists('octopus_ai_get_manual_search_url')
            ? octopus_ai_get_manual_search_url($lang, $keyword)
            : ("https://login.octopus.be/manual/{$lang}/hmftsearch.htm?zoom_query=" . rawurlencode($keyword));

        if (empty(trim($answer))) {
            $answer = octopus_ai_get_no_solution_message($lang, $fallback);
        }

        $label = ($lang === 'FR') ? 'Voir aussi dans la documentation' : 'Bekijk mogelijke info in de handleiding';

        // ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€šÃ‚Â§Ãƒâ€šÃ‚Â¹ Verwijder eventuele losse fallback-tekst zonder link om dubbels te vermijden
        $answer_lines = preg_split("/\r?\n/", $answer);
        if ($answer_lines !== false) {
            $answer_lines = array_filter(
                $answer_lines,
                static function ($line) use ($label) {
                    $trimmed = trim($line, " \t\"'");
                    return $trimmed !== $label;
                }
            );
            $answer = trim(implode("\n", $answer_lines));
        }

        // Zorg dat er maximaal twee opeenvolgende nieuwe regels overblijven
        $answer = preg_replace("/\n{3,}/", "\n\n", $answer ?? '');

        $answer .= "\n\n[$label]($zoeklink)";
    }
}

// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Voeg lijst met top 3 referentielinks toe
if (!empty($reference_candidates)) {
    usort(
        $reference_candidates,
        static function ($a, $b) {
            $scoreA = isset($a['score']) ? (float) $a['score'] : 0.0;
            $scoreB = isset($b['score']) ? (float) $b['score'] : 0.0;
            if ($scoreA === $scoreB) {
                return 0;
            }
            return ($scoreA < $scoreB) ? 1 : -1;
        }
    );

    $seen_urls = [];
    $selected  = [];
    foreach ($reference_candidates as $candidate) {
        $url = isset($candidate['url']) ? trim((string) $candidate['url']) : '';
        if ($url === '' || isset($seen_urls[$url])) {
            continue;
        }

        $seen_urls[$url] = true;
        $selected[] = [
            'title' => isset($candidate['title']) ? trim((string) $candidate['title']) : '',
            'url'   => $url,
        ];

        if (count($selected) >= 3) {
            break;
        }
    }

    if (!empty($selected)) {
        $heading = ($lang === 'FR') ? 'Liens utiles' : 'Handige links';
        $answer  = rtrim($answer) . "\n\n{$heading}:\n";
        foreach ($selected as $ref) {
            $title = $ref['title'] !== '' ? $ref['title'] : (($lang === 'FR') ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding');
            $safe_title = sanitize_text_field($title);
            if ($safe_title === '') {
                $safe_title = ($lang === 'FR') ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding';
            }
            $answer .= sprintf('- [%s](%s)\n', $safe_title, esc_url($ref['url']));
        }
        $answer = rtrim($answer);
    }
}

$answer = octopus_ai_sanitize_answer_output($answer);

if (!function_exists('octopus_ai_log_interaction')) {
    require_once plugin_dir_path(__FILE__) . 'logger.php';

}

// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Bepaal status
$is_fallback = stripos($answer, $fallback) !== false || strlen(trim($answer)) < 10;

$status = $is_fallback ? 'fail' : 'success';

// ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Logging uitvoeren
$chat_id = 0;
if (function_exists('octopus_ai_log_interaction')) {
    $context_length = strlen($context) + strlen($live_context);
    $error_message  = $status === 'fail' ? json_encode($body) : '';
    $chat_id = (int) octopus_ai_log_interaction($message, $answer, $context_length, $status, $error_message);
}

return rest_ensure_response([
    'answer' => $answer,
    'chat_id' => $chat_id,
    'status' => $status,
]);

    } catch (Throwable $exception) {
        error_log('[Octopus AI] Onverwachte chatbot runtime-fout: ' . $exception->getMessage());
        return new WP_Error(
            'octopus_ai_runtime_error',
            __('Technische fout bij het verwerken van het chatbotverzoek.', 'octopus-ai'),
            ['status' => 500]
        );
    }
}

function octopus_ai_save_feedback($request) {
    try {
    $rate_limit = octopus_ai_check_rate_limit('feedback', 60, 5 * MINUTE_IN_SECONDS);
    if (is_wp_error($rate_limit)) {
        return $rate_limit;
    }

    $feedback = sanitize_text_field((string) $request->get_param('feedback'));
    $chat_id  = absint($request->get_param('chat_id'));

    if (!in_array($feedback, ['up', 'down'])) {
        return new WP_Error('invalid_feedback', 'Ongeldige feedbackwaarde.', ['status' => 400]);
    }

    if ($chat_id <= 0) {
        return new WP_Error('invalid_chat_id', 'Ongeldig chat-id.', ['status' => 400]);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'octopus_ai_logs';

    $updated = $wpdb->update(
        $table,
        ['feedback' => $feedback],
        ['id' => $chat_id],
        ['%s'],
        ['%d']
    );

    if ($updated === false) {
        return new WP_Error('feedback_db_error', 'Feedback kon niet opgeslagen worden.', ['status' => 500]);
    }

    if ($updated === 0) {
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id = %d", $chat_id));
        if ($exists === 0) {
            return new WP_Error('feedback_not_found', 'Chatlog niet gevonden.', ['status' => 404]);
        }
    }

    return rest_ensure_response([
        'status' => 'ok',
        'chat_id' => $chat_id,
        'feedback' => $feedback,
    ]);
    } catch (Throwable $exception) {
        error_log('[Octopus AI] Onverwachte feedback runtime-fout: ' . $exception->getMessage());
        return new WP_Error(
            'octopus_ai_feedback_runtime_error',
            __('Technische fout bij het opslaan van feedback.', 'octopus-ai'),
            ['status' => 500]
        );
    }
}
