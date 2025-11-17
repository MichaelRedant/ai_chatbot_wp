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

if (!function_exists('octopus_ai_get_manual_mode')) {
    function octopus_ai_get_manual_mode()
    {
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

require_once __DIR__ . '/helpers/live-manual.php';

// ✅ REST API endpoint registreren
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


// ✅ Frontend instellingen beschikbaar maken via AJAX
add_action('wp_ajax_octopus_ai_get_settings', 'octopus_ai_get_settings');
add_action('wp_ajax_nopriv_octopus_ai_get_settings', 'octopus_ai_get_settings');

function octopus_ai_is_valid_url($url) {
    $cache_key = 'octopus_ai_urlcheck_' . md5($url);
    $cached = get_transient($cache_key);
    if (!is_null($cached)) return $cached;

    $headers = @get_headers($url);
    $is_valid = false;

    if ($headers && isset($headers[0])) {
        if (preg_match('/^HTTP\/[^\s]+\s+(\d+)/', $headers[0], $matches)) {
            $status = (int) $matches[1];
            // Beschouw 200-399 of 403 als geldig (sommige handleidinglinks vereisen login)
            $is_valid = ($status >= 200 && $status < 400) || $status === 403;
        }
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
            ['“', '”'],
            ['„', '“'],
            ['«', '»'],
        ];

        foreach ($pairs as $pair) {
            [$open, $close] = $pair;
            $open_length  = mb_strlen($open);
            $close_length = mb_strlen($close);
            if (
                mb_substr($trimmed, 0, $open_length) === $open &&
                mb_substr($trimmed, -$close_length) === $close &&
                mb_strlen($trimmed) >= ($open_length + $close_length)
            ) {
                $inner = mb_substr($trimmed, $open_length, mb_strlen($trimmed) - $open_length - $close_length);
                return trim($inner);
            }
        }

        return $trimmed;
    }
}


function octopus_ai_get_settings()
{
    wp_send_json_success(array(
        'primary_color'     => get_option('octopus_ai_primary_color', '#0f6c95'),
        'brand_name'        => get_option('octopus_ai_brand_name', 'AI Chatbot'),
        'logo_url'          => get_option('octopus_ai_logo_url', ''),
        'welcome_message'   => get_option('octopus_ai_welcome_message', '👋 Hallo! Hoe kan ik je vandaag helpen?')
    ));
}

// ✅ Chatbot callback
function octopus_ai_chatbot_callback($request)
{
    $message = sanitize_text_field($request->get_param('message'));
    $history = $request->get_param('history') ?? [];

    $intent = octopus_ai_detect_intent($message);
    if ($intent) {
        error_log('[Octopus AI] Gedetecteerde intent: ' . $intent);
    }

   // ✅ Detecteer taal op basis van URL of browserinstellingen
    $lang         = 'NL';
    $request_uri  = $_SERVER['REQUEST_URI'] ?? '';
    $lang_header  = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');

    if (preg_match('#/fr(/|$)#', $request_uri)) {
        $lang = 'FR';
    } elseif (strpos($lang_header, 'fr') === 0) {
        $lang = 'FR';
    }

    $manual_mode = octopus_ai_get_manual_mode();
    $use_live_manual = in_array($manual_mode, ['live', 'hybrid'], true);
    $use_local_chunks = $manual_mode !== 'live';

    $api_key = trim((string) get_option('octopus_ai_api_key'));
    if ($lang === 'FR') {
    $tone = <<<EOT
🎯 Objectif
Tu es un chatbot professionnel qui aide les clients à utiliser Octopus de manière claire, efficace et conviviale.

Fournis des réponses directes et utiles sur l’utilisation d’Octopus

Utilise des paragraphes courts, des listes à puces ou des étapes lorsque cela facilite la compréhension

🗣️ Ton

Professionnel, chaleureux, adapté au public belge francophone

Ne mentionne jamais l’IA, GPT ou toute technologie similaire

Aucune supposition ou invention : reste factuel et précis

Ne t’appuie que sur les chunks fournis et sur les pages du manuel autorisées.

🚫 Limitations

Répond uniquement si un contexte pertinent est disponible

Ne fournis aucune information sur la législation, la comptabilité ou des logiciels externes

En cas de doute, réponds simplement : « Désolé, je ne peux pas t’aider avec ça. »

💬 Comportement

Si l’utilisateur répond par « oui », « ok » ou confirme, continue avec les instructions ou détails utiles, sans te répéter inutilement

📄 Si possible

Ajoute la mention : « 📄 Voir dans le manuel » avec un lien valide lorsque c’est pertinent

Termine en partageant la liste des trois pages du manuel les plus pertinentes.

Contexte :
EOT;
} else {
    $tone = get_option('octopus_ai_tone') ?: <<<EOT
Je bent een AI-chatbot die klanten professioneel, duidelijk en kort helpt bij het gebruik van deze software.

🎯 Doel:
- Help gebruikers stap voor stap bij hun vraag over de werking van Octopus
- Geef vlotte, concrete en heldere antwoorden
- Gebruik waar nuttig bullets, stappen of korte paragrafen

🗣️ Tone of voice:
- Vriendelijk, Vlaams professioneel en to the point
- Geen disclaimers of verwijzingen naar AI, GPT of technologie
- Geen veronderstellingen of verzinsels

🚫 Beperkingen:
- Beantwoord enkel vragen waarvoor relevante context beschikbaar is
- Geef géén antwoord over wetgeving, boekhoudregels, code of externe software
- Bij twijfel: zeg “Sorry, daar kan ik je niet mee helpen.”

💬 Conversatiegedrag:
- Als de gebruiker “ja”, “ok”, “doe maar” of iets bevestigend antwoordt, beschouw dit als een vervolg op je vorige uitleg
- Geef dan het logische volgende stapje of verdieping
- Herhaal in dat geval **niet** je vorige antwoord

📄 Indien beschikbaar:
- Voeg onderaan toe: “📄 Bekijk dit in de handleiding” met een juiste link

Gebruik alleen informatie uit de gedeelde context en de toegestane handleiding-URL’s.
Sluit af met een opsomming van de drie meest relevante handleidinglinks.

Context:
EOT;
}


    $fallback = ($lang === 'FR')
    ? "Désolé, je ne peux pas t’aider avec ça."
    : get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.');

    if ($api_key === '') {
        return new WP_Error(
            'octopus_ai_missing_api_key',
            __('Octopus AI API key ontbreekt. Voeg een geldige key toe op de instellingenpagina.', 'octopus-ai')
        );
    }

    $model = get_option('octopus_ai_model', 'gpt-4.1-mini');
    $context = '';
    $metadata_chunks = [];
    $relevantFound = false;
    $live_context = '';
    $live_sources = [];
    $live_best_source = '';
    $live_best_score  = 0.0;
    $reference_candidates = [];

    if ($use_local_chunks && function_exists('octopus_ai_retrieve_relevant_chunks')) {
        $result = octopus_ai_retrieve_relevant_chunks($message);
        $context = $result['context'] ?? '';

        if (isset($result['metadata']['chunks']) && is_array($result['metadata']['chunks'])) {
            $metadata_chunks = $result['metadata']['chunks'];
        } elseif (isset($result['metadatas']) && is_array($result['metadatas'])) {
            $metadata_chunks = $result['metadatas'];
        } elseif (isset($result['metas']) && is_array($result['metas'])) {
            $metadata_chunks = $result['metas'];
        }

        if (!empty($context) && strlen($context) > 20) {
            $relevantFound = true;
        }
    } elseif (function_exists('octopus_ai_retrieve_relevant_chunks')) {
        // We gebruiken de metadata nog steeds om live bronnen te bepalen in live-modus.
        $result = octopus_ai_retrieve_relevant_chunks($message);
        if (isset($result['metadata']['chunks']) && is_array($result['metadata']['chunks'])) {
            $metadata_chunks = $result['metadata']['chunks'];
        } elseif (isset($result['metadatas']) && is_array($result['metadatas'])) {
            $metadata_chunks = $result['metadatas'];
        } elseif (isset($result['metas']) && is_array($result['metas'])) {
            $metadata_chunks = $result['metas'];
        }
    }

    if ($use_live_manual) {
        $live_manual = octopus_ai_fetch_live_manual_context($metadata_chunks, $lang, $message);
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

    // ❌ Als er geen relevante context gevonden werd, geef fallback met zoeklink terug
    if (!$relevantFound) {
        if (!function_exists('octopus_ai_extract_keyword')) {
            require_once plugin_dir_path(__FILE__) . 'helpers/extract-keyword.php';
        }

        $keyword = octopus_ai_extract_keyword($message);
        if ($keyword) {
            $zoeklink = "https://login.octopus.be/manual/{$lang}/hmftsearch.htm?zoom_query=" . rawurlencode($keyword);

            $link_text = ($lang === 'FR')
                ? 'Voir aussi dans la documentation'
                : 'Bekijk mogelijke info in de handleiding';

            $fallback_text = $fallback . "\n\n[$link_text]($zoeklink)";
            return $fallback_text; // ← GEEN do_shortcode()
        }
        return $fallback;
    }


    // ➕ Prompt opbouwen
    $system_prompt = $tone;

    if ($context !== '') {
        $system_prompt .= "\n\nContext (chunks):\n" . $context;
    }

    if ($live_context !== '') {
        $system_prompt .= "\n\nLive handleiding (laatste versie):\n" . $live_context;
    }

    if ($context === '' && $live_context === '') {
        $system_prompt .= "\n\nContext:\n";
    }
    $system_prompt .= "\n\nOpmerking:\nAls de gebruiker bevestigt dat hij verder geholpen wil worden (bijv. zegt 'ja'), geef dan een inhoudelijk vervolg op het onderwerp, niet een algemene begroeting of herstart.";

    // 📄 Links toevoegen
    $validLinkFound      = false;
    $primary_doc_url     = '';
    $best_metadata_link  = '';
    $best_metadata_score = -1.0;
    if (!empty($metadata_chunks)) {
        $system_prompt .= "\n\nDeze informatie komt uit de volgende onderdelen:\n";
        foreach ($metadata_chunks as $meta) {
            $title        = sanitize_text_field($meta['section_title'] ?? '');
            $slug         = sanitize_text_field($meta['page_slug'] ?? '');
            $source_url   = isset($meta['source_url']) ? esc_url_raw($meta['source_url']) : '';
            $current_score = isset($meta['score']) ? (float) $meta['score'] : 0.0;

            $possible_links = [];
            if ($slug) {
                $doc_url      = "https://login.octopus.be/manual/{$lang}/{$slug}";
                $doc_is_valid = octopus_ai_is_allowed_manual_url($doc_url, $lang)
                    ? octopus_ai_is_valid_url($doc_url)
                    : false;
                if ($doc_is_valid) {
                    $possible_links[] = [
                        'url'       => $doc_url,
                        'is_manual' => true,
                        'is_valid'  => true,
                    ];
                }
            }

            if ($source_url && octopus_ai_is_allowed_manual_url($source_url, $lang)) {
                $existing_urls = array_map(
                    static function ($link_info) {
                        return $link_info['url'];
                    },
                    $possible_links
                );

                if (!in_array($source_url, $existing_urls, true)) {
                    $source_is_valid = octopus_ai_is_valid_url($source_url);
                    $from_live       = in_array($source_url, $live_sources, true);
                    if ($source_is_valid || $from_live) {
                        $possible_links[] = [
                            'url'       => $source_url,
                            'is_manual' => strpos($source_url, 'octopus.be/manual') !== false,
                            'is_valid'  => $source_is_valid || $from_live,
                        ];
                    }
                }
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

    // ➕ Opbouw history
    $messages = [['role' => 'system', 'content' => $system_prompt]];
    foreach ($history as $entry) {
        if (isset($entry['role'], $entry['content']) && in_array($entry['role'], ['user', 'assistant'])) {
            $messages[] = [
                'role'    => sanitize_text_field($entry['role']),
                'content' => sanitize_textarea_field($entry['content']),
            ];
        }
    }

    // ✅ API request
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => wp_json_encode([
            'model'    => $model,
            'messages' => $messages
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('api_error', 'Technische fout bij het ophalen van het antwoord.');
    }

    $body_json = wp_remote_retrieve_body($response);
    $body = json_decode($body_json, true);
    // 🧠 AI-antwoord verwerken
    $answer = $body['choices'][0]['message']['content'] ?? '';

    if (!$answer) {
        $error_message = $body['error']['message'] ?? 'Ongeldige API-respons.';
        return new WP_Error('api_error', 'Fout van OpenAI: ' . $error_message);
    }

    // ✅ Unicode-decodering via JSON (zoals \u00e9 → é)
    $decoded_json = json_decode('"' . addcslashes($answer, "\\\"\/\n\r\t") . '"');
    if (is_string($decoded_json)) {
        $answer = $decoded_json;
    }

    // ✅ Unicode-decoding voor uXXXX of \uXXXX (fallback)
    $answer = preg_replace_callback('/\\\\?u([0-9a-fA-F]{4})/', function ($matches) {
        $hex = $matches[1];
        $bin = pack('H*', $hex);
        return mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
    }, $answer);

// ✅ Eén keer UTF-8 normaliseren
$answer = mb_convert_encoding($answer, 'UTF-8', 'UTF-8');

// ✅ Dubbele slashes en quotes strippen
$answer = stripslashes($answer);

// ✅ Decodeer HTML entities (zoals &eacute; → é)
$answer = html_entity_decode($answer, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// ✅ Decodeer wp-specialchars (zoals &#039; → ')
$answer = wp_specialchars_decode($answer, ENT_QUOTES);


// ✅ Emoji verwijderen (blacklist)
$emoji_blacklist = [
    '📄','🔎','🧾','📌','🔐','🗂️','🧠','⚙️','🚀','💬','🎯','🗣️','🔽','🔼','📊',
    '🧪','💡','📁','🔗','✅','❌','⚠️','ℹ️','🧨','🔍','📦','📬','📥','📤','📍',
    '🗃️','📈','📉','📅','📆','📋','✉️','💻','📱','🖥️','📎','📝','🖊️','🔒','🔓',
    '🛠️','🪄','🧹','🪪','🗑️','⏳','⌛','🔧','👎','👍'
];
$answer = str_replace($emoji_blacklist, '', $answer);

// ✅ Dode links naar de handleiding weghalen (optioneel: kan zwaar zijn als er veel zijn)
$answer = preg_replace_callback(
    '/\(https:\/\/login\.octopus\.be\/manual\/(NL|FR)\/([^)]+)\)/',
    function ($matches) {
        $lang = $matches[1];
        $slug = $matches[2];
        $url  = "https://login.octopus.be/manual/{$lang}/{$slug}";
        return octopus_ai_is_valid_url($url) ? "($url)" : ''; // enkel tonen indien valide
    },
    $answer
);
$answer = wp_specialchars_decode($answer, ENT_QUOTES);
$answer = html_entity_decode($answer, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$answer = octopus_ai_trim_surrounding_quotes($answer);

// ✅ Fallback-zoeklink als geen geldige link gevonden is
if (
    !$validLinkFound &&
    (
        trim($answer) === trim($fallback) ||
        !preg_match('/https:\/\/login\.octopus\.be\/manual\/(NL|FR)\//', $answer)
    )
) {
    if (!function_exists('octopus_ai_extract_keyword')) {
        require_once plugin_dir_path(__FILE__) . 'helpers/extract-keyword.php';
    }
    $keyword = octopus_ai_extract_keyword($message);
    if ($keyword) {
        $zoeklink = "https://login.octopus.be/manual/{$lang}/hmftsearch.htm?zoom_query=" . rawurlencode($keyword);

        if (empty(trim($answer))) {
            $answer = $fallback;
        }

        $label = ($lang === 'FR') ? 'Voir aussi dans la documentation' : 'Bekijk mogelijke info in de handleiding';

        // 🧹 Verwijder eventuele losse fallback-tekst zonder link om dubbels te vermijden
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

// ✅ Voeg lijst met top 3 referentielinks toe
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

if (!function_exists('octopus_ai_log_interaction')) {
    require_once plugin_dir_path(__FILE__) . 'logger.php';

}

// ✅ Bepaal status
$is_fallback = stripos($answer, $fallback) !== false || strlen(trim($answer)) < 10;

$status = $is_fallback ? 'fail' : 'success';

$error_message  = $status === 'fail' ? json_encode($body) : '';



// ✅ Logging uitvoeren
if (function_exists('octopus_ai_log_interaction')) {
    $context_length = strlen($context);
    $error_message  = $status === 'fail' ? json_encode($body) : '';
    octopus_ai_log_interaction($message, $answer, $context_length, $status, $error_message);

}


return do_shortcode($answer);

}

function octopus_ai_save_feedback($request) {
    $feedback = sanitize_text_field($request->get_param('feedback'));
    $answer   = sanitize_textarea_field($request->get_param('answer'));

    if (!in_array($feedback, ['up', 'down'])) {
        return new WP_Error('invalid_feedback', 'Ongeldige feedbackwaarde.');
    }

    $log = sprintf("[%s] Feedback: %s\nAntwoord: %s\n---\n", date('Y-m-d H:i:s'), strtoupper($feedback), $answer);
    $upload_dir = wp_upload_dir();
    $logfile = trailingslashit($upload_dir['basedir']) . 'octopus-ai-feedback.log';
    file_put_contents($logfile, $log, FILE_APPEND);

    return rest_ensure_response(['status' => 'ok']);
}
