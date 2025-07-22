<?php

// Veiligheid
if (!defined('ABSPATH')) exit;

if (!function_exists('octopus_ai_detect_intent')) {
    require_once __DIR__ . '/helpers/intent-detector.php';

}


// ✅ REST API endpoint registreren
add_action('rest_api_init', function () {
    register_rest_route('octopus-ai/v1', '/chatbot', array(
        'methods' => 'POST',
        'callback' => 'octopus_ai_chatbot_callback',
        'permission_callback' => '__return_true'
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
    $is_valid = $headers && strpos($headers[0], '200') !== false;

    set_transient($cache_key, $is_valid, 12 * HOUR_IN_SECONDS);
    return $is_valid;
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

    // ➕ Intentherkenning
    $intent = octopus_ai_detect_intent($message);
    if ($intent) {
        error_log('[Octopus AI] Gedetecteerde intent: ' . $intent);
    }

    // 🔐 Instellingen ophalen
    $api_key = get_option('octopus_ai_api_key');
    $tone = get_option('octopus_ai_tone');
if (!$tone) {
    $tone = <<<EOT
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

Context:
EOT;

}

    $fallback = get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.');
    $model   = get_option('octopus_ai_model', 'gpt-4.1-mini');

    // 📥 Context + metadata ophalen
    $context = '';
    $metadata = [];

    if (function_exists('octopus_ai_retrieve_relevant_chunks')) {
        $result = octopus_ai_retrieve_relevant_chunks($message);

        if (!empty($result['context'])) {
            $context = $result['context'];
            $metadata = $result['metadata']; // voor backward compatibility
            $metas    = $result['metas'] ?? [];
        }
    }

    // 🧠 System prompt opbouwen
$system_prompt = $tone . "\n\nContext:\n" . $context;

// ➕ Conversatielogica instructie toevoegen
$system_prompt .= "\n\nOpmerking:\nAls de gebruiker bevestigt dat hij verder geholpen wil worden (bijv. zegt 'ja'), geef dan een inhoudelijk vervolg op het onderwerp, niet een algemene begroeting of herstart.";


// 📄 Extra info op basis van meerdere relevante chunks
if (!empty($metas)) {
    $system_prompt .= "\n\n📄 Deze informatie komt uit de volgende onderdelen:\n";

    foreach ($metas as $meta) {
        $title = sanitize_text_field($meta['section_title'] ?? '');
        $slug  = sanitize_text_field($meta['page_slug'] ?? '');
        $url   = esc_url_raw($meta['source_url'] ?? '');

        if ($title && $slug) {
            $doc_url = "https://login.octopus.be/manual/NL/{$slug}";
            if (octopus_ai_is_valid_url($doc_url)) {
                $system_prompt .= "- *{$title}*\n  [Bekijk dit in de handleiding]({$doc_url})\n";
            } else {
                error_log("[Octopus AI] ❌ Handleiding-link bestaat niet: {$doc_url}");
                $system_prompt .= "- *{$title}*\n  _(Er bestaat momenteel geen rechtstreekse handleidingspagina voor dit onderdeel)_\n";
            }
        } elseif ($title && $url) {
            $system_prompt .= "- *{$title}*\n  [Bekijk dit op de website]({$url})\n";
        }
    }
}

$messages = [
    ['role' => 'system', 'content' => $system_prompt]
];

foreach ($history as $entry) {
    if (isset($entry['role'], $entry['content']) && in_array($entry['role'], ['user', 'assistant'])) {
        $messages[] = [
            'role'    => sanitize_text_field($entry['role']),
            'content' => sanitize_textarea_field($entry['content']),
        ];
    }
}

    // ✅ API request voorbereiden
    $data = array(
        'model' => $model,
        'messages' => $messages
    );

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body'    => wp_json_encode($data),
        'timeout' => 30,
    ));

    if (is_wp_error($response)) {
        error_log('[Octopus AI] WP Error: ' . $response->get_error_message());
        return new WP_Error('api_error', 'Technische fout bij het ophalen van het antwoord.');
    }

    $body_json = wp_remote_retrieve_body($response);
    $body = json_decode($body_json, true);

    if (!isset($body['choices'][0]['message']['content'])) {
        $error_message = $body['error']['message'] ?? 'Ongeldige API-respons.';
        error_log('[Octopus AI] OpenAI fout: ' . $error_message);
        error_log('[Octopus AI] Body: ' . $body_json);
        return new WP_Error('api_error', 'Fout van OpenAI: ' . $error_message);
    }

    $answer = $body['choices'][0]['message']['content'];
    $status = 'success';

    // 🧾 Logging
    if (function_exists('octopus_ai_log_interaction')) {
        $context_length = strlen($context);
        $error_message  = $status === 'fail' ? json_encode($body) : '';
        octopus_ai_log_interaction($message, $answer, $context_length, $status, $error_message);
    }

    if (!empty($answer)) {
    $answer = mb_convert_encoding($answer, 'UTF-8', 'UTF-8');

    // Fallbackcontrole: komt exact overeen?
    if (trim($answer) === trim($fallback)) {
        // Zoekterm extraheren
        if (!function_exists('octopus_ai_extract_keyword')) {
            require_once plugin_dir_path(__FILE__) . 'helpers/extract-keyword.php';
        }
        $keyword = octopus_ai_extract_keyword($message);

        if ($keyword) {
            $zoeklink = 'https://login.octopus.be/manual/NL/hmftsearch.htm?zoom_query=' . rawurlencode($keyword);
            $answer .= "\n\n🔎 [Bekijk mogelijke info in de handleiding]($zoeklink)";
        }
    }

    return do_shortcode(wp_specialchars_decode(stripslashes($answer), ENT_QUOTES));

}

return $fallback;
}
