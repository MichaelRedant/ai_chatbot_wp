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

    $intent = octopus_ai_detect_intent($message);
    if ($intent) {
        error_log('[Octopus AI] Gedetecteerde intent: ' . $intent);
    }

    $api_key = get_option('octopus_ai_api_key');
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

Context:
EOT;

    $fallback = get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.');
    $model = get_option('octopus_ai_model', 'gpt-4.1-mini');
    $context = '';
$metas = [];
$relevantFound = false;

if (function_exists('octopus_ai_retrieve_relevant_chunks')) {
    $result = octopus_ai_retrieve_relevant_chunks($message);
    $context = $result['context'] ?? '';
    $metas   = $result['metas'] ?? [];

    if (!empty($context) && strlen($context) > 50) {
        $relevantFound = true;
    }
}

// ❌ Als er geen relevante context gevonden werd, geef direct fallback met zoeklink terug
if (!$relevantFound) {
    if (!function_exists('octopus_ai_extract_keyword')) {
        require_once plugin_dir_path(__FILE__) . 'helpers/extract-keyword.php';
    }

    $keyword = octopus_ai_extract_keyword($message);
    $zoeklink = 'https://login.octopus.be/manual/NL/hmftsearch.htm?zoom_query=' . rawurlencode($keyword);

    $fallback_text = $fallback . "\n\n[Bekijk mogelijke info in de handleiding]($zoeklink)";
    return do_shortcode($fallback_text);
}

    // ➕ Prompt opbouwen
    $system_prompt = $tone . "\n\nContext:\n" . $context;
    $system_prompt .= "\n\nOpmerking:\nAls de gebruiker bevestigt dat hij verder geholpen wil worden (bijv. zegt 'ja'), geef dan een inhoudelijk vervolg op het onderwerp, niet een algemene begroeting of herstart.";

    // 📄 Links toevoegen
    $validLinkFound = false;
    if (!empty($metas)) {
        $system_prompt .= "\n\nDeze informatie komt uit de volgende onderdelen:\n";
        foreach ($metas as $meta) {
            $title = sanitize_text_field($meta['section_title'] ?? '');
            $slug  = sanitize_text_field($meta['page_slug'] ?? '');
            $url   = esc_url_raw($meta['source_url'] ?? '');

            if ($title && $slug) {
                $doc_url = "https://login.octopus.be/manual/NL/{$slug}";
                if (octopus_ai_is_valid_url($doc_url)) {
                    $system_prompt .= "- *{$title}*\n  [Bekijk dit in de handleiding]({$doc_url})\n";
                    $validLinkFound = true;
                }
            } elseif ($title && $url) {
                $system_prompt .= "- *{$title}*\n  [Bekijk dit op de website]({$url})\n";
                $validLinkFound = true;
            }
        }
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

// ✅ Decode: \uXXXX → écht Unicode-teken (zoals “ ” é …)
$answer = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($m) {
    return mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UCS-2BE');
}, $answer);

// ✅ Algemene correcties en veiligheid
$answer = mb_convert_encoding($answer, 'UTF-8', 'UTF-8');
$answer = wp_specialchars_decode(stripslashes($answer), ENT_QUOTES);

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
        $zoeklink = 'https://login.octopus.be/manual/NL/hmftsearch.htm?zoom_query=' . rawurlencode($keyword);
        $answer .= "\n\n[Bekijk mogelijke info in de handleiding]($zoeklink)";
    }
}

if (!function_exists('octopus_ai_log_interaction')) {
    require_once plugin_dir_path(__FILE__) . 'log-helper.php';
}

// ✅ Logging uitvoeren
if (function_exists('octopus_ai_log_interaction')) {
    $context_length = strlen($context);
    octopus_ai_log_interaction($message, $answer, $context_length, 'success');
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
