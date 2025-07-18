<?php
// Veiligheid
if (!defined('ABSPATH')) exit;

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

    // Instellingen ophalen
    $api_key = get_option('octopus_ai_api_key');
    $tone    = get_option('octopus_ai_tone', 'Je bent de Octopus Chatbot. Beantwoord vriendelijk en kort vragen over Octopus.');
    $fallback = get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.');
    $model   = get_option('octopus_ai_model', 'gpt-4.1-mini');

    // Handleiding als context
    $upload_dir       = wp_upload_dir();
    $handleiding_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/handleiding.txt';
    $context = '';

    if (file_exists($handleiding_path)) {
        $context = file_get_contents($handleiding_path);
        $context = substr($context, 0, 5000); // fallback indien chunks falen
    } else {
        $context = 'Er is nog geen handleiding geüpload. Upload een handleiding in de plugin-instellingen.';
    }

    // ✅ Slimmere contextselectie via chunks
    if (function_exists('octopus_ai_retrieve_relevant_chunks')) {
        $smart_context = octopus_ai_retrieve_relevant_chunks($message);
        if (!empty($smart_context)) {
            $context = $smart_context;
        }
    }

    // ✅ API request voorbereiden
    $data = array(
        'model' => $model,
        'messages' => array(
            array(
                'role'    => 'system',
                'content' => $tone . "\n\nContext:\n" . $context
            ),
            array(
                'role'    => 'user',
                'content' => $message
            )
        )
    );

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body'    => wp_json_encode($data),
        'timeout' => 30,
    ));

    // ❌ Technische fout (bijv. internet, timeout)
    if (is_wp_error($response)) {
        error_log('[Octopus AI] WP Error: ' . $response->get_error_message());
        return new WP_Error('api_error', 'Technische fout bij het ophalen van het antwoord.');
    }

    // ✅ JSON body ophalen
    $body_json = wp_remote_retrieve_body($response);
    $body = json_decode($body_json, true);

    // ❌ Fout van OpenAI (bijv. rate limit, key ongeldig)
    if (!isset($body['choices'][0]['message']['content'])) {
        $error_message = isset($body['error']['message']) ? $body['error']['message'] : 'Ongeldige API-respons.';
        error_log('[Octopus AI] OpenAI fout: ' . $error_message);
        error_log('[Octopus AI] Body: ' . $body_json);
        return new WP_Error('api_error', 'Fout van OpenAI: ' . $error_message);
    }

    // ✅ Antwoord verwerken
    $answer = $body['choices'][0]['message']['content'];
    $status = 'success';

    // ✅ Logging
    if (function_exists('octopus_ai_log_interaction')) {
        $context_length = strlen($context);
        $error_message  = $status === 'fail' ? json_encode($body) : '';
        octopus_ai_log_interaction($message, $answer, $context_length, $status, $error_message);
    }

    // ✅ Markdown met links correct weergeven
    if (!empty($answer)) {
        $answer = stripslashes($answer);
        return wp_kses_post($answer);
    }

    return $fallback;
}
