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

// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ REST API endpoint registreren
add_action('rest_api_init', function () {
    register_rest_route('octopus-ai/v1', '/chatbot', array(
        'methods' => 'POST',
        'callback' => 'octopus_ai_chatbot_callback',
        'permission_callback' => '__return_true'
    ));

    register_rest_route('octopus-ai/v1', '/chatbot-lite', array(
        'methods' => 'POST',
        'callback' => 'octopus_ai_chatbot_lite_callback',
        'permission_callback' => '__return_true'
    ));

    register_rest_route('octopus-ai/v1', '/feedback', array(
        'methods'  => 'POST',
        'callback' => 'octopus_ai_save_feedback',
        'permission_callback' => '__return_true',
    ));
});

if (!function_exists('octopus_ai_chatbot_lite_callback')) {
    /**
     * Lichtgewicht failover-endpoint voor omgevingen waar de hoofdroute HTTP 500 geeft.
     * Geeft altijd een bruikbare fallback met links terug.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    function octopus_ai_chatbot_lite_callback($request)
    {
        try {
            $lang = function_exists('octopus_ai_get_request_language')
                ? octopus_ai_get_request_language()
                : 'NL';
            $message = sanitize_text_field((string) $request->get_param('message'));
            $topic = sanitize_key((string) $request->get_param('topic'));

            $fallback_default = ($lang === 'FR')
                ? "Desole, je ne peux pas t'aider avec ca."
                : get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.');
            $fallback = function_exists('octopus_ai_get_provider_fallback_text')
                ? octopus_ai_get_provider_fallback_text($lang, $fallback_default)
                : $fallback_default;
            $handoff_url = function_exists('octopus_ai_get_handoff_url')
                ? octopus_ai_get_handoff_url($lang)
                : '';

            $include_reference_links = (bool) apply_filters(
                'octopus_ai_chatbot_lite_include_reference_links',
                false,
                $lang,
                $topic,
                $message
            );

            $selected_reference_links = [];
            if ($include_reference_links) {
                $reference_candidates = function_exists('octopus_ai_select_topic_reference_links')
                    ? octopus_ai_select_topic_reference_links($message, $topic, $lang, 3)
                    : [];
                $selected_reference_links = function_exists('octopus_ai_select_top_reference_links')
                    ? octopus_ai_select_top_reference_links($reference_candidates, $lang, 2)
                    : [];
            }

            if (function_exists('octopus_ai_build_no_solution_answer')) {
                $answer = octopus_ai_build_no_solution_answer(
                    $lang,
                    $message,
                    $fallback,
                    [
                        'references' => $selected_reference_links,
                        'handoff_url' => $handoff_url,
                    ]
                );
            } else {
                $answer = $fallback;
            }

            $notice = ($lang === 'FR')
                ? "Mode degrade active: voici les meilleurs liens disponibles."
                : 'Noodmodus actief: hieronder de best beschikbare links.';
            $answer = $notice . "\n\n" . ltrim((string) $answer);
            if (function_exists('octopus_ai_sanitize_answer_output')) {
                $answer = octopus_ai_sanitize_answer_output($answer);
            }

            if (function_exists('delete_transient')) {
                delete_transient('octopus_ai_last_fatal_error');
            }

            return rest_ensure_response([
                'answer' => (string) $answer,
                'chat_id' => 0,
                'status' => 'lite_fallback',
                'confidence' => 0.0,
                'reference_links' => is_array($selected_reference_links) ? $selected_reference_links : [],
                'suggested_topic' => '',
                'current_topic' => $topic,
                'primary_source_url' => (
                    is_array($selected_reference_links) &&
                    isset($selected_reference_links[0]['url']) &&
                    is_string($selected_reference_links[0]['url'])
                ) ? esc_url_raw((string) $selected_reference_links[0]['url']) : '',
            ]);
        } catch (Throwable $exception) {
            error_log('[Octopus AI] Lite callback fout: ' . $exception->getMessage());
            $lang = function_exists('octopus_ai_get_request_language')
                ? octopus_ai_get_request_language()
                : 'NL';
            $answer = ($lang === 'FR')
                ? "Une erreur technique persiste. Consulte la documentation principale."
                : 'Er blijft een technische fout. Bekijk de hoofddocumentatie.';

            return rest_ensure_response([
                'answer' => $answer,
                'chat_id' => 0,
                'status' => 'lite_error',
                'confidence' => 0.0,
                'reference_links' => [],
                'suggested_topic' => '',
                'current_topic' => '',
                'primary_source_url' => function_exists('octopus_ai_get_handoff_url')
                    ? esc_url_raw((string) octopus_ai_get_handoff_url($lang))
                    : '',
            ]);
        }
    }
}

if (!function_exists('octopus_ai_chatbot_safe_mode_enabled')) {
    /**
     * Safe mode houdt de hoofd-chatroute licht en productie-stabiel.
     * Standaard aan om 500/fatals op hosts met strikte runtime-limieten te voorkomen.
     *
     * @param WP_REST_Request|null $request
     * @return bool
     */
    function octopus_ai_chatbot_safe_mode_enabled($request = null)
    {
        $enabled = apply_filters('octopus_ai_chatbot_safe_mode_enabled', true, $request);
        return (bool) $enabled;
    }
}

if (!function_exists('octopus_ai_chatbot_safe_callback')) {
    /**
     * Lightweight maar inhoudelijke chatbot callback voor productie.
     * Vermijdt zware URL-validaties en dure live-crawl-paden in het hoofdendpoint.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    function octopus_ai_chatbot_safe_callback($request)
    {
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(25);
            }
            @ini_set('max_execution_time', '25');

            $lang = function_exists('octopus_ai_get_request_language')
                ? octopus_ai_get_request_language()
                : 'NL';
            $message = sanitize_text_field((string) $request->get_param('message'));
            $history = octopus_ai_sanitize_client_history($request->get_param('history') ?? [], 8);
            $topic = sanitize_key((string) $request->get_param('topic'));

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

            $allowed_topics = function_exists('octopus_ai_get_provider_allowed_topics')
                ? octopus_ai_get_provider_allowed_topics()
                : [];
            if (empty($allowed_topics)) {
                $topic_terms_map = function_exists('octopus_ai_get_topic_terms_map')
                    ? octopus_ai_get_topic_terms_map()
                    : [];
                $allowed_topics = array_values(array_filter(array_map('sanitize_key', array_keys(is_array($topic_terms_map) ? $topic_terms_map : []))));
            }
            if (!in_array($topic, $allowed_topics, true)) {
                $topic = '';
            }

            $selected_topic = $topic;
            $effective_topic = $topic;

            $effective_query_data = function_exists('octopus_ai_get_effective_retrieval_message')
                ? octopus_ai_get_effective_retrieval_message($message, $history, $lang)
                : ['query' => $message, 'used_history' => false, 'previous_user_message' => ''];
            $retrieval_query = trim((string) ($effective_query_data['query'] ?? $message));
            if ($retrieval_query === '') {
                $retrieval_query = $message;
            }
            $reference_query = trim((string) ($effective_query_data['previous_user_message'] ?? ''));
            if ($reference_query === '') {
                $reference_query = $message;
            }

            if (octopus_ai_is_3d_printing_question($message)) {
                $easter_egg_answer = octopus_ai_get_3d_printing_easter_egg_answer($lang);
                $easter_egg_answer = octopus_ai_sanitize_answer_output($easter_egg_answer);

                return rest_ensure_response([
                    'answer' => $easter_egg_answer,
                    'chat_id' => 0,
                    'status' => 'easter_egg_3d',
                    'confidence' => 1.0,
                    'reference_links' => [],
                    'suggested_topic' => '',
                    'current_topic' => $selected_topic,
                    'primary_source_url' => 'https://x3dprints.be',
                ]);
            }

            $retrieval_topic = $effective_topic;
            if ($retrieval_topic === '' && function_exists('octopus_ai_detect_topic_for_retrieval')) {
                $detected_topic = sanitize_key((string) octopus_ai_detect_topic_for_retrieval($retrieval_query));
                if (in_array($detected_topic, $allowed_topics, true)) {
                    $retrieval_topic = $detected_topic;
                }
            }

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

            $topic_mismatch = (!$skip_topic_mismatch) ? octopus_ai_detect_topic_mismatch($message, $selected_topic, $history) : '';
            $topic_mismatch_notice = '';
            if ($topic_mismatch !== '') {
                $current_label = octopus_ai_get_topic_label($selected_topic, $lang);
                $suggested_label = octopus_ai_get_topic_label($topic_mismatch, $lang);
                $topic_mismatch_notice = ($lang === 'FR')
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
                $topic_mismatch_notice = octopus_ai_apply_language_glossary($topic_mismatch_notice, $lang);

                if ($effective_topic === '') {
                    $retrieval_topic = $topic_mismatch;
                }
            }

            if (!octopus_ai_is_in_scope_question($message, $selected_topic, $history)) {
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
                    'confidence' => 1.0,
                    'reference_links' => [],
                    'suggested_topic' => $topic_mismatch,
                    'current_topic' => $selected_topic,
                    'primary_source_url' => '',
                ]);
            }

            $fallback_default = ($lang === 'FR')
                ? "Desole, je ne peux pas t'aider avec ca."
                : get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.');
            $fallback = function_exists('octopus_ai_get_provider_fallback_text')
                ? octopus_ai_get_provider_fallback_text($lang, $fallback_default)
                : $fallback_default;

            $api_key = trim((string) get_option('octopus_ai_api_key'));
            if ($api_key === '') {
                $config_notice = ($lang === 'FR')
                    ? "La configuration du chatbot est incomplete. Merci de verifier la cle API dans les reglages."
                    : 'De chatbotconfiguratie is onvolledig. Controleer de API-key in de instellingen.';
                $missing_key_answer = octopus_ai_build_no_solution_answer(
                    $lang,
                    $reference_query !== '' ? $reference_query : $message,
                    $fallback,
                    [
                        'references' => [],
                        'handoff_url' => function_exists('octopus_ai_get_handoff_url') ? octopus_ai_get_handoff_url($lang) : '',
                    ]
                );
                $missing_key_answer = $config_notice . "\n\n" . ltrim((string) $missing_key_answer);
                $missing_key_answer = octopus_ai_sanitize_answer_output($missing_key_answer);

                return rest_ensure_response([
                    'answer' => $missing_key_answer,
                    'chat_id' => 0,
                    'status' => 'config_error_missing_api_key',
                    'confidence' => 0.0,
                    'reference_links' => [],
                    'suggested_topic' => $topic_mismatch,
                    'current_topic' => $selected_topic,
                    'primary_source_url' => '',
                ]);
            }

            $model = get_option('octopus_ai_model', 'gpt-4.1-mini');
            $context = '';
            $metadata_chunks = [];
            $extract_metadata_chunks = static function ($result) {
                if (!is_array($result)) {
                    return [];
                }
                if (isset($result['metadata']['chunks']) && is_array($result['metadata']['chunks'])) {
                    return $result['metadata']['chunks'];
                }
                if (isset($result['metadatas']) && is_array($result['metadatas'])) {
                    return $result['metadatas'];
                }
                if (isset($result['metas']) && is_array($result['metas'])) {
                    return $result['metas'];
                }
                return [];
            };

            if (function_exists('octopus_ai_retrieve_relevant_chunks')) {
                $candidates = [];
                if ($effective_topic !== '') {
                    $candidates[] = $effective_topic;
                } else {
                    if ($retrieval_topic !== '') {
                        $candidates[] = $retrieval_topic;
                    }
                    $candidates[] = '';
                }
                $candidates = array_values(array_unique(array_map('sanitize_key', $candidates)));

                foreach ($candidates as $candidate_topic) {
                    $result = octopus_ai_retrieve_relevant_chunks($retrieval_query, $candidate_topic);
                    $candidate_context = trim((string) ($result['context'] ?? ''));
                    $candidate_metadata = $extract_metadata_chunks($result);
                    if ($candidate_context === '' && empty($candidate_metadata)) {
                        continue;
                    }
                    $context = $candidate_context;
                    $metadata_chunks = $candidate_metadata;
                    if ($candidate_topic !== '') {
                        $retrieval_topic = $candidate_topic;
                    }
                    break;
                }
            }

            $relevant_found = (trim((string) $context) !== '' && strlen((string) $context) > 10) || !empty($metadata_chunks);
            $topic_for_reference = $effective_topic !== '' ? $effective_topic : $retrieval_topic;
            $selected_reference_links = [];
            $include_reference_links = (bool) apply_filters(
                'octopus_ai_safe_chat_include_reference_links',
                false,
                $lang,
                $topic_for_reference,
                $reference_query
            );
            if ($include_reference_links) {
                $reference_candidates = function_exists('octopus_ai_select_topic_reference_links')
                    ? octopus_ai_select_topic_reference_links($reference_query, $topic_for_reference, $lang, 3)
                    : [];
                $selected_reference_links = function_exists('octopus_ai_select_top_reference_links')
                    ? octopus_ai_select_top_reference_links($reference_candidates, $lang, 2)
                    : [];
            }

            if (!$relevant_found) {
                $fallback_answer = octopus_ai_build_no_solution_answer(
                    $lang,
                    $reference_query,
                    $fallback,
                    [
                        'references' => $selected_reference_links,
                        'handoff_url' => function_exists('octopus_ai_get_handoff_url') ? octopus_ai_get_handoff_url($lang) : '',
                    ]
                );
                if ($topic_mismatch_notice !== '') {
                    $fallback_answer = rtrim((string) $fallback_answer) . "\n\n" . $topic_mismatch_notice;
                }
                $fallback_answer = octopus_ai_sanitize_answer_output($fallback_answer);

                return rest_ensure_response([
                    'answer' => $fallback_answer,
                    'chat_id' => 0,
                    'status' => 'fallback',
                    'confidence' => 0.0,
                    'reference_links' => $selected_reference_links,
                    'suggested_topic' => $topic_mismatch,
                    'current_topic' => $selected_topic,
                    'primary_source_url' => (
                        is_array($selected_reference_links) &&
                        isset($selected_reference_links[0]['url']) &&
                        is_string($selected_reference_links[0]['url'])
                    ) ? esc_url_raw((string) $selected_reference_links[0]['url']) : '',
                ]);
            }

            $default_tone = ($lang === 'FR')
                ? 'Tu aides les utilisateurs d Octopus de maniere claire et concise. Utilise uniquement le contexte fourni.'
                : 'Je helpt gebruikers van Octopus duidelijk en kort. Gebruik enkel de meegegeven context.';
            $tone = trim((string) get_option('octopus_ai_tone', $default_tone));
            if ($tone === '') {
                $tone = $default_tone;
            }

            $system_prompt = $tone;
            if ($topic_for_reference !== '') {
                $system_prompt .= "\n\nActieve flow: " . octopus_ai_get_topic_label($topic_for_reference, $lang) . '.';
            }
            if ($context !== '') {
                $system_prompt .= "\n\nContext:\n" . $context;
            }
            $strict_no_solution = octopus_ai_get_no_solution_message($lang, $fallback);
            $strict_rule = ($lang === 'FR')
                ? "Regle de fiabilite: n'invente rien. Si la solution n'est pas explicitement presente dans le contexte, donne l'etape la plus plausible basee sur le contexte disponible et pose une courte question de clarification. Utilise la reponse stricte suivante uniquement en dernier recours: \"" . $strict_no_solution . "\""
                : "Betrouwbaarheidsregel: verzin niets. Als de oplossing niet expliciet in de context staat, geef de meest waarschijnlijke vervolgstap op basis van de beschikbare context en stel een korte verduidelijkingsvraag. Gebruik volgend strikt antwoord alleen als laatste redmiddel: \"" . $strict_no_solution . "\"";
            $system_prompt .= "\n\n" . $strict_rule;

            if (!empty($effective_query_data['used_history']) && !empty($effective_query_data['previous_user_message'])) {
                $previous_user_message = sanitize_textarea_field((string) $effective_query_data['previous_user_message']);
                $system_prompt .= ($lang === 'FR')
                    ? ("\n\nQuestion precedente: " . $previous_user_message . "\nTraite le nouveau message comme une suite contextuelle.")
                    : ("\n\nVorige vraag: " . $previous_user_message . "\nBehandel het nieuwe bericht als contextueel vervolg.");
            }
            if (!empty($effective_query_data['used_history'])) {
                $system_prompt .= ($lang === 'FR')
                    ? "\n\nRegle de suivi: n'explique pas a nouveau les etapes deja traitees juste avant. Donne uniquement la suite utile, les differences ou les actions suivantes."
                    : "\n\nVervolgregel: herhaal geen stappen die net al uitgelegd zijn. Geef alleen de ontbrekende vervolgstappen, verschillen of volgende acties.";
            }
            $history_has_assistant = false;
            foreach ($history as $history_entry) {
                if (!is_array($history_entry)) {
                    continue;
                }
                $history_role = isset($history_entry['role']) ? strtolower(trim((string) $history_entry['role'])) : '';
                if ($history_role === 'assistant' || $history_role === 'bot') {
                    $history_has_assistant = true;
                    break;
                }
            }
            if ($history_has_assistant) {
                $system_prompt .= ($lang === 'FR')
                    ? "\n\nContexte de conversation: tiens compte des reponses precedentes du chatbot. Si l'utilisateur demande une suite, ne repete pas le bloc deja donne, sauf demande explicite de recapitulatif."
                    : "\n\nGesprekscontext: hou rekening met eerdere chatbotantwoorden. Als de gebruiker om een vervolg vraagt, herhaal het vorige blok niet, tenzij expliciet om een samenvatting gevraagd wordt.";
            }

            $messages = [['role' => 'system', 'content' => $system_prompt]];
            $current_message_present = false;
            foreach ($history as $entry) {
                if (!isset($entry['content'])) {
                    continue;
                }
                $entry_role = isset($entry['role']) ? strtolower(trim((string) $entry['role'])) : 'user';
                if ($entry_role === 'bot') {
                    $entry_role = 'assistant';
                }
                if (!in_array($entry_role, ['user', 'assistant'], true)) {
                    continue;
                }
                $content = sanitize_textarea_field((string) $entry['content']);
                if ($content === '') {
                    continue;
                }
                $messages[] = [
                    'role' => $entry_role,
                    'content' => $content,
                ];
                if ($entry_role === 'user' && $content === $message) {
                    $current_message_present = true;
                }
            }
            if (!$current_message_present) {
                $messages[] = [
                    'role' => 'user',
                    'content' => $message,
                ];
            }

            $openai_attempts = (int) apply_filters('octopus_ai_safe_chat_openai_attempts', 3, $lang, $model);
            $openai_timeout = (int) apply_filters('octopus_ai_safe_chat_openai_timeout', 20, $lang, $model);
            $openai_result = octopus_ai_openai_chat_completion_with_retry(
                $api_key,
                $messages,
                $model,
                $openai_attempts,
                $openai_timeout
            );
            if (is_wp_error($openai_result)) {
                $fallback_model = apply_filters('octopus_ai_safe_chat_fallback_model', 'gpt-4o-mini', $model, $lang);
                $fallback_model = sanitize_text_field((string) $fallback_model);
                if ($fallback_model !== '' && $fallback_model !== $model) {
                    $retry_attempts = max(1, min(3, $openai_attempts));
                    $retry_timeout = max(10, min(30, $openai_timeout));
                    $retry_result = octopus_ai_openai_chat_completion_with_retry(
                        $api_key,
                        $messages,
                        $fallback_model,
                        $retry_attempts,
                        $retry_timeout
                    );
                    if (!is_wp_error($retry_result)) {
                        $openai_result = $retry_result;
                    }
                }
            }
            if (is_wp_error($openai_result)) {
                $service_reason = $openai_result->get_error_message();
                error_log('[Octopus AI] Safe callback OpenAI service fallback actief: ' . $service_reason);

                $service_notice = ($lang === 'FR')
                    ? "Je ne peux temporairement pas generer une reponse detaillee. Voici les liens les plus pertinents."
                    : 'Ik kan tijdelijk geen gedetailleerd antwoord genereren. Hieronder staan de meest relevante links.';
                $service_fallback = octopus_ai_build_no_solution_answer(
                    $lang,
                    $reference_query !== '' ? $reference_query : $message,
                    '',
                    [
                        'references' => $selected_reference_links,
                        'handoff_url' => function_exists('octopus_ai_get_handoff_url') ? octopus_ai_get_handoff_url($lang) : '',
                    ]
                );
                $service_fallback = $service_notice . "\n\n" . ltrim((string) $service_fallback);
                if ($topic_mismatch_notice !== '') {
                    $service_fallback = rtrim((string) $service_fallback) . "\n\n" . $topic_mismatch_notice;
                }
                $service_fallback = octopus_ai_sanitize_answer_output($service_fallback);
                if (!function_exists('octopus_ai_log_interaction')) {
                    require_once plugin_dir_path(__FILE__) . 'logger.php';
                }
                $service_chat_id = 0;
                if (function_exists('octopus_ai_log_interaction')) {
                    $context_length = strlen((string) $context);
                    $error_payload = wp_json_encode([
                        'reason' => 'safe_service_fallback',
                        'service_reason' => (string) $service_reason,
                        'model' => (string) $model,
                    ]);
                    $service_chat_id = (int) octopus_ai_log_interaction(
                        $message,
                        $service_fallback,
                        $context_length,
                        'fail',
                        (string) $error_payload
                    );
                }

                return rest_ensure_response([
                    'answer' => $service_fallback,
                    'chat_id' => $service_chat_id,
                    'status' => 'service_fallback',
                    'confidence' => 0.0,
                    'reference_links' => $selected_reference_links,
                    'suggested_topic' => $topic_mismatch,
                    'current_topic' => $selected_topic,
                    'primary_source_url' => (
                        is_array($selected_reference_links) &&
                        isset($selected_reference_links[0]['url']) &&
                        is_string($selected_reference_links[0]['url'])
                    ) ? esc_url_raw((string) $selected_reference_links[0]['url']) : '',
                ]);
            }

            $body = isset($openai_result['body']) && is_array($openai_result['body']) ? $openai_result['body'] : [];
            $answer = $body['choices'][0]['message']['content'] ?? '';
            if (!$answer) {
                $error_message = $body['error']['message'] ?? 'Ongeldige API-respons.';
                error_log('[Octopus AI] Safe callback lege OpenAI response: ' . $error_message);

                $answer = octopus_ai_build_no_solution_answer(
                    $lang,
                    $reference_query,
                    $fallback,
                    [
                        'references' => $selected_reference_links,
                        'handoff_url' => function_exists('octopus_ai_get_handoff_url') ? octopus_ai_get_handoff_url($lang) : '',
                    ]
                );
            }

            $decoded_json = json_decode('"' . addcslashes((string) $answer, "\\\"\/\n\r\t") . '"');
            if (is_string($decoded_json)) {
                $answer = $decoded_json;
            }

            $answer = preg_replace_callback('/\\\\?u([0-9a-fA-F]{4})/', function ($matches) {
                $hex = $matches[1];
                $bin = pack('H*', $hex);
                return function_exists('octopus_ai_utf16be_to_utf8')
                    ? octopus_ai_utf16be_to_utf8($bin)
                    : '';
            }, (string) $answer);

            $answer = function_exists('octopus_ai_normalize_utf8')
                ? octopus_ai_normalize_utf8($answer)
                : (string) $answer;
            $answer = stripslashes((string) $answer);
            $answer = html_entity_decode((string) $answer, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $answer = wp_specialchars_decode((string) $answer, ENT_QUOTES);
            $answer = octopus_ai_apply_language_glossary((string) $answer, $lang);
            $answer = octopus_ai_sanitize_answer_output((string) $answer);

            if (trim((string) $answer) === '' || trim((string) $answer) === trim((string) $fallback)) {
                $answer = octopus_ai_build_no_solution_answer(
                    $lang,
                    $reference_query,
                    $fallback,
                    [
                        'references' => $selected_reference_links,
                        'handoff_url' => function_exists('octopus_ai_get_handoff_url') ? octopus_ai_get_handoff_url($lang) : '',
                    ]
                );
            }

            $has_manual_link = function_exists('octopus_ai_answer_contains_allowed_manual_link')
                ? octopus_ai_answer_contains_allowed_manual_link($answer, $lang)
                : false;
            if (!$has_manual_link && !empty($selected_reference_links)) {
                $heading = ($lang === 'FR') ? 'Liens utiles' : 'Handige links';
                $answer = rtrim((string) $answer) . "\n\n{$heading}:\n";
                foreach ($selected_reference_links as $ref) {
                    if (!is_array($ref)) {
                        continue;
                    }
                    $ref_title = sanitize_text_field((string) ($ref['title'] ?? ''));
                    $ref_url = esc_url_raw((string) ($ref['url'] ?? ''));
                    if ($ref_url === '') {
                        continue;
                    }
                    if ($ref_title === '') {
                        $ref_title = ($lang === 'FR') ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding';
                    }
                    $answer .= '- [' . $ref_title . '](' . $ref_url . ')' . "\n";
                }
                $answer = rtrim((string) $answer);
            }

            if ($topic_mismatch_notice !== '') {
                $answer = rtrim((string) $answer) . "\n\n" . $topic_mismatch_notice;
            }

            $answer = octopus_ai_sanitize_answer_output((string) $answer);

            if (!function_exists('octopus_ai_log_interaction')) {
                require_once plugin_dir_path(__FILE__) . 'logger.php';
            }

            $is_fallback = stripos((string) $answer, (string) $fallback) !== false || strlen(trim((string) $answer)) < 10;
            $confidence = $is_fallback ? 0.0 : (!empty($metadata_chunks) ? 0.78 : 0.62);
            $confidence_threshold = function_exists('octopus_ai_get_confidence_threshold')
                ? (float) octopus_ai_get_confidence_threshold()
                : 0.55;
            $effective_confidence_threshold = max(0.20, $confidence_threshold - 0.10);
            $confidence_shortfall = (!$is_fallback && $confidence < $effective_confidence_threshold);

            if ($confidence_shortfall) {
                $clarification_note = ($lang === 'FR')
                    ? "Si ce n'est pas exactement ta situation, precise l'ecran ou l'etape bloquante et je te donne la suite."
                    : 'Als dit niet exact jouw situatie is, geef het scherm of de blokkende stap mee en ik geef meteen het vervolg.';
                $answer = rtrim((string) $answer) . "\n\n" . $clarification_note;
                $answer = octopus_ai_sanitize_answer_output((string) $answer);
            }

            $status = $is_fallback ? 'fail' : 'success';
            $chat_id = 0;
            if (function_exists('octopus_ai_log_interaction')) {
                $context_length = strlen((string) $context);
                $chat_id = (int) octopus_ai_log_interaction($message, $answer, $context_length, $status, '');
            }

            if (function_exists('delete_transient')) {
                delete_transient('octopus_ai_last_fatal_error');
            }

            return rest_ensure_response([
                'answer' => (string) $answer,
                'chat_id' => $chat_id,
                'status' => $status,
                'confidence' => round((float) $confidence, 3),
                'reference_links' => is_array($selected_reference_links) ? $selected_reference_links : [],
                'suggested_topic' => $topic_mismatch,
                'current_topic' => $selected_topic,
                'primary_source_url' => (
                    is_array($selected_reference_links) &&
                    isset($selected_reference_links[0]['url']) &&
                    is_string($selected_reference_links[0]['url'])
                ) ? esc_url_raw((string) $selected_reference_links[0]['url']) : '',
            ]);
        } catch (Throwable $exception) {
            error_log('[Octopus AI] Safe callback runtime-fout: ' . $exception->getMessage());

            $runtime_lang = function_exists('octopus_ai_get_request_language')
                ? octopus_ai_get_request_language()
                : 'NL';
            $runtime_message = '';
            if (is_object($request) && method_exists($request, 'get_param')) {
                $runtime_message = sanitize_text_field((string) $request->get_param('message'));
            }

            $runtime_fallback_default = ($runtime_lang === 'FR')
                ? "Desole, je ne peux pas t'aider avec ca."
                : get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.');
            $runtime_fallback = function_exists('octopus_ai_get_provider_fallback_text')
                ? octopus_ai_get_provider_fallback_text($runtime_lang, $runtime_fallback_default)
                : $runtime_fallback_default;
            $runtime_notice = ($runtime_lang === 'FR')
                ? "Une erreur technique s'est produite. Voici les liens utiles disponibles."
                : 'Er is een technische fout opgetreden. Hieronder staan nuttige beschikbare links.';

            if (function_exists('octopus_ai_build_no_solution_answer')) {
                $runtime_answer = octopus_ai_build_no_solution_answer(
                    $runtime_lang,
                    $runtime_message,
                    $runtime_fallback,
                    [
                        'references' => [],
                        'handoff_url' => function_exists('octopus_ai_get_handoff_url') ? octopus_ai_get_handoff_url($runtime_lang) : '',
                    ]
                );
            } else {
                $runtime_answer = $runtime_fallback;
            }

            $runtime_answer = $runtime_notice . "\n\n" . ltrim((string) $runtime_answer);
            if (function_exists('octopus_ai_sanitize_answer_output')) {
                $runtime_answer = octopus_ai_sanitize_answer_output($runtime_answer);
            }

            return rest_ensure_response([
                'answer' => (string) $runtime_answer,
                'chat_id' => 0,
                'status' => 'runtime_fallback',
                'confidence' => 0.0,
                'reference_links' => [],
                'suggested_topic' => '',
                'current_topic' => '',
                'primary_source_url' => '',
            ]);
        }
    }
}

if (!function_exists('octopus_ai_convert_rest_chatbot_errors_to_fallback')) {
    /**
     * Zet WP_Error responses op de chatbot-route om naar een bruikbare fallback payload.
     * Dit voorkomt frontend-hard-fails met HTTP 500 op productie.
     *
     * @param mixed            $response
     * @param array            $handler
     * @param WP_REST_Request  $request
     * @return mixed
     */
    function octopus_ai_convert_rest_chatbot_errors_to_fallback($response, $handler, $request)
    {
        if (!($request instanceof WP_REST_Request)) {
            return $response;
        }

        $route = (string) $request->get_route();
        if ($route !== '/octopus-ai/v1/chatbot' && $route !== '/octopus-ai/v1/chatbot-lite') {
            return $response;
        }

        if (!is_wp_error($response)) {
            return $response;
        }

        $lang = function_exists('octopus_ai_get_request_language')
            ? octopus_ai_get_request_language()
            : 'NL';
        $message = sanitize_text_field((string) $request->get_param('message'));

        $fallback_default = ($lang === 'FR')
            ? "Desole, je ne peux pas t'aider avec ca."
            : get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.');
        $fallback = function_exists('octopus_ai_get_provider_fallback_text')
            ? octopus_ai_get_provider_fallback_text($lang, $fallback_default)
            : $fallback_default;
        $handoff_url = function_exists('octopus_ai_get_handoff_url')
            ? octopus_ai_get_handoff_url($lang)
            : '';

        $raw_reason = sanitize_text_field((string) $response->get_error_message());
        $runtime_notice = ($lang === 'FR')
            ? "Une erreur technique est survenue. Je te partage les liens utiles disponibles."
            : 'Er trad een technische fout op. Ik deel de beschikbare nuttige links.';

        $answer = function_exists('octopus_ai_build_no_solution_answer')
            ? octopus_ai_build_no_solution_answer(
                $lang,
                $message,
                $fallback,
                [
                    'references' => [],
                    'handoff_url' => $handoff_url,
                ]
            )
            : $fallback;
        $answer = $runtime_notice . "\n\n" . ltrim((string) $answer);
        if (function_exists('octopus_ai_sanitize_answer_output')) {
            $answer = octopus_ai_sanitize_answer_output($answer);
        }

        error_log('[Octopus AI] REST WP_Error omgezet naar chatbot fallback: ' . $raw_reason);

        return new WP_REST_Response([
            'answer' => (string) $answer,
            'chat_id' => 0,
            'status' => 'rest_error_fallback',
            'confidence' => 0.0,
            'reference_links' => [],
            'suggested_topic' => '',
            'current_topic' => '',
            'primary_source_url' => '',
            'error_reason' => $raw_reason,
        ], 200);
    }

    add_filter('rest_request_after_callbacks', 'octopus_ai_convert_rest_chatbot_errors_to_fallback', 20, 3);
}


// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Frontend instellingen beschikbaar maken via AJAX
add_action('wp_ajax_octopus_ai_get_settings', 'octopus_ai_get_settings');
add_action('wp_ajax_nopriv_octopus_ai_get_settings', 'octopus_ai_get_settings');

function octopus_ai_is_valid_url($url) {
    $url = esc_url_raw((string) $url);
    if ($url === '') {
        return false;
    }

    static $request_cache = [];
    if (array_key_exists($url, $request_cache)) {
        return (bool) $request_cache[$url];
    }

    if (
        function_exists('octopus_ai_is_chatbot_rest_request') &&
        octopus_ai_is_chatbot_rest_request()
    ) {
        $skip_remote_checks = (bool) apply_filters(
            'octopus_ai_skip_remote_url_validation_in_chat',
            true,
            $url
        );
        if ($skip_remote_checks) {
            $request_cache[$url] = true;
            return true;
        }
    }

    $cache_key = 'octopus_ai_urlcheck_' . md5($url);
    $cached = get_transient($cache_key);
    if (!is_null($cached)) {
        $request_cache[$url] = (bool) $cached;
        return (bool) $cached;
    }

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

    $request_cache[$url] = (bool) $is_valid;
    set_transient($cache_key, (bool) $is_valid, 12 * HOUR_IN_SECONDS);
    return (bool) $is_valid;
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
            ['ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã¢â‚¬Å“', 'ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â'],
            ['ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾', 'ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã¢â‚¬Å“'],
            ['ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â«', 'ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â»'],
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
        $source_map = function_exists('octopus_ai_get_provider_topic_terms_map')
            ? octopus_ai_get_provider_topic_terms_map()
            : [];
        if (!is_array($source_map) || empty($source_map)) {
            $provider_defaults = function_exists('octopus_ai_get_default_provider_profile')
                ? octopus_ai_get_default_provider_profile()
                : [];
            $source_map = isset($provider_defaults['topic_terms']) && is_array($provider_defaults['topic_terms'])
                ? $provider_defaults['topic_terms']
                : [];
        }

        $topic_terms = [];
        foreach ($source_map as $topic_key => $topic_keywords) {
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

        if (function_exists('octopus_ai_get_provider_topic_label')) {
            $provider_label = octopus_ai_get_provider_topic_label($topic, $lang, '');
            if ($provider_label !== '') {
                return $provider_label;
            }
        }

        if ($topic === '') {
            return '';
        }

        return ucfirst(str_replace('_', ' ', $topic));
    }
}

if (!function_exists('octopus_ai_get_manual_search_fallback_url')) {
    function octopus_ai_get_manual_search_fallback_url($lang, $keyword)
    {
        $lang = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';
        $keyword = sanitize_text_field((string) $keyword);
        if ($keyword === '') {
            return '';
        }

        $base_url = function_exists('octopus_ai_get_manual_base_url')
            ? octopus_ai_get_manual_base_url($lang)
            : '';
        if ($base_url === '' && function_exists('octopus_ai_get_provider_manual_base_url')) {
            $base_url = octopus_ai_get_provider_manual_base_url($lang);
        }

        $base_url = esc_url_raw((string) $base_url);
        if ($base_url !== '') {
            return trailingslashit($base_url) . 'hmftsearch.htm?zoom_query=' . rawurlencode($keyword);
        }

        return 'https://example.com/?q=' . rawurlencode($keyword);
    }
}

if (!function_exists('octopus_ai_is_short_follow_up_message')) {
    function octopus_ai_is_short_follow_up_message($message)
    {
        $normalized = octopus_ai_normalize_scope_text($message);
        if ($normalized === '') {
            return false;
        }

        $affirmations = [
            'ja', 'ok', 'oke', 'okee', 'yes', 'oui', "d'accord", 'daccord',
            'graag', 'doe maar', 'ga verder', 'verder', 'klopt', 'correct',
            'neen', 'nee', 'non', 'niet', 'pas aan', 'switch', 'wissel',
        ];

        if (in_array($normalized, $affirmations, true)) {
            return true;
        }

        $length = function_exists('octopus_ai_string_length')
            ? (int) octopus_ai_string_length($normalized)
            : strlen((string) $normalized);

        if ($length > 50) {
            return false;
        }

        if (
            preg_match('/^(en|en dan|en hoe|hoe dan|waar dan|welke dan|wat dan|toon|laat zien|doe verder)\b/u', $normalized) === 1 ||
            preg_match('/^(et|et puis|alors|comment|ou|lequel|laquelle|montre|continue)\b/u', $normalized) === 1
        ) {
            return true;
        }

        return false;
    }
}

if (!function_exists('octopus_ai_get_previous_user_message_for_context')) {
    function octopus_ai_get_previous_user_message_for_context(array $history, $current_message = '')
    {
        if (empty($history)) {
            return '';
        }

        $current_norm = octopus_ai_normalize_scope_text($current_message);
        $skipped_current = false;

        for ($i = count($history) - 1; $i >= 0; $i--) {
            $entry = $history[$i] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $role = sanitize_key((string) ($entry['role'] ?? 'user'));
            if ($role !== 'user') {
                continue;
            }

            $content = trim((string) ($entry['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $content_norm = octopus_ai_normalize_scope_text($content);
            if (!$skipped_current && $current_norm !== '' && $content_norm === $current_norm) {
                $skipped_current = true;
                continue;
            }

            $length = function_exists('octopus_ai_string_length')
                ? (int) octopus_ai_string_length($content_norm)
                : strlen((string) $content_norm);
            if ($length < 6) {
                continue;
            }

            return $content;
        }

        return '';
    }
}

if (!function_exists('octopus_ai_get_effective_retrieval_message')) {
    function octopus_ai_get_effective_retrieval_message($message, array $history = [], $lang = 'NL')
    {
        $message = trim((string) $message);
        if ($message === '') {
            return [
                'query' => '',
                'used_history' => false,
                'previous_user_message' => '',
            ];
        }

        if (!octopus_ai_is_short_follow_up_message($message)) {
            return [
                'query' => $message,
                'used_history' => false,
                'previous_user_message' => '',
            ];
        }

        $previous = octopus_ai_get_previous_user_message_for_context($history, $message);
        if ($previous === '') {
            return [
                'query' => $message,
                'used_history' => false,
                'previous_user_message' => '',
            ];
        }

        $normalized_message = octopus_ai_normalize_scope_text($message);
        $affirmation_only_messages = [
            'ja', 'ok', 'oke', 'okee', 'yes', 'oui', "d'accord", 'daccord',
            'graag', 'doe maar', 'ga verder', 'verder', 'klopt', 'correct',
            'neen', 'nee', 'non', 'niet',
        ];
        $is_affirmation_only = in_array($normalized_message, $affirmation_only_messages, true);

        // Gebruik een compacte retrieval-query zonder meta-zinnen; dat geeft stabielere keyword matching.
        $query = $is_affirmation_only
            ? $previous
            : trim($previous . ' ' . $message);

        return [
            'query' => $query,
            'used_history' => true,
            'previous_user_message' => $previous,
        ];
    }
}

if (!function_exists('octopus_ai_get_topic_match_analysis')) {
    function octopus_ai_get_topic_match_analysis($message)
    {
        $normalized = octopus_ai_normalize_scope_text($message);
        if ($normalized === '') {
            return [
                'scores' => [],
                'best_topic' => '',
                'best_score' => 0.0,
                'second_topic' => '',
                'second_score' => 0.0,
                'is_ambiguous' => false,
            ];
        }

        $topic_terms_map = octopus_ai_get_topic_terms_map();
        if (!is_array($topic_terms_map) || empty($topic_terms_map)) {
            return [
                'scores' => [],
                'best_topic' => '',
                'best_score' => 0.0,
                'second_topic' => '',
                'second_score' => 0.0,
                'is_ambiguous' => false,
            ];
        }

        $scores = [];
        foreach ($topic_terms_map as $topic_key => $terms) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '' || !is_array($terms)) {
                continue;
            }

            $score = 0.0;
            foreach ($terms as $term) {
                $term = octopus_ai_normalize_scope_text($term);
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

                $score += min(4.0, (float) $occurrences);
                if (strlen($term) >= 8) {
                    $score += 0.35;
                }
            }

            $scores[$topic_key] = round($score, 3);
        }

        if (empty($scores)) {
            return [
                'scores' => [],
                'best_topic' => '',
                'best_score' => 0.0,
                'second_topic' => '',
                'second_score' => 0.0,
                'is_ambiguous' => false,
            ];
        }

        arsort($scores);
        $keys = array_keys($scores);
        $best_topic = (string) ($keys[0] ?? '');
        $second_topic = (string) ($keys[1] ?? '');
        $best_score = (float) ($best_topic !== '' ? ($scores[$best_topic] ?? 0.0) : 0.0);
        $second_score = (float) ($second_topic !== '' ? ($scores[$second_topic] ?? 0.0) : 0.0);

        $is_ambiguous = (
            $best_score >= 1.2 &&
            $second_score >= 1.2 &&
            abs($best_score - $second_score) <= 0.85
        );

        return [
            'scores' => $scores,
            'best_topic' => $best_topic,
            'best_score' => $best_score,
            'second_topic' => $second_topic,
            'second_score' => $second_score,
            'is_ambiguous' => $is_ambiguous,
        ];
    }
}

if (!function_exists('octopus_ai_detect_topic_mismatch')) {
    function octopus_ai_detect_topic_mismatch($message, $selected_topic = '', $history = [])
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

        if (octopus_ai_is_short_follow_up_message($normalized) && is_array($history) && !empty($history)) {
            return '';
        }

        $analysis = octopus_ai_get_topic_match_analysis($normalized);
        $scores = isset($analysis['scores']) && is_array($analysis['scores']) ? $analysis['scores'] : [];
        $selected_score = isset($scores[$selected_topic]) ? (float) $scores[$selected_topic] : 0.0;
        $best_topic = sanitize_key((string) ($analysis['best_topic'] ?? ''));
        $best_score = (float) ($analysis['best_score'] ?? 0.0);
        $is_ambiguous = !empty($analysis['is_ambiguous']);

        if ($selected_score >= 1.0 || $is_ambiguous || $best_topic === '' || $best_topic === $selected_topic) {
            return '';
        }

        if ($best_score < 2.8) {
            return '';
        }

        $delta = $best_score - $selected_score;
        if ($delta < 1.8) {
            return '';
        }

        return $best_topic;
    }
}

if (!function_exists('octopus_ai_get_reference_query_terms')) {
    function octopus_ai_get_reference_query_terms($question, $lang = 'NL')
    {
        $normalized = octopus_ai_normalize_scope_text($question);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [];
        }

        $stopwords = [
            'de', 'het', 'een', 'en', 'of', 'van', 'voor', 'met', 'naar', 'op', 'in', 'te', 'om',
            'ik', 'je', 'jij', 'u', 'wij', 'we', 'jullie', 'zij', 'hun', 'mij', 'me',
            'wat', 'welk', 'welke', 'hoe', 'waar', 'waarom', 'wanneer', 'kan', 'mag', 'moet',
            'vraag', 'vragen', 'reactie', 'gebruiker', 'vervolg', 'vorige', 'bericht', 'antwoord', 'antwoorden',
            'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'dans', 'sur', 'pour', 'avec', 'sans',
            'je', 'tu', 'vous', 'nous', 'ils', 'elles', 'qui', 'que', 'quoi', 'comment', 'ou', 'quand',
            'question', 'questions', 'reponse', 'reponses', 'utilisateur', 'suite', 'precedente', 'message',
            'the', 'and', 'for', 'with', 'from', 'this', 'that', 'what', 'how', 'where', 'when',
            'follow', 'followup', 'previous',
        ];

        $terms = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || in_array($part, $stopwords, true)) {
                continue;
            }
            if (strlen($part) < 3) {
                continue;
            }
            if (!in_array($part, $terms, true)) {
                $terms[] = $part;
            }
        }

        $allow_manual_terms = true;
        if (
            function_exists('octopus_ai_is_chatbot_rest_request') &&
            octopus_ai_is_chatbot_rest_request()
        ) {
            $allow_manual_terms = (bool) apply_filters(
                'octopus_ai_reference_query_use_live_manual_terms_in_chat',
                false,
                $question,
                $lang
            );
        }

        if ($allow_manual_terms && function_exists('octopus_ai_extract_manual_query_terms')) {
            $manual_terms = octopus_ai_extract_manual_query_terms($question, $lang, 10);
            if (is_array($manual_terms)) {
                foreach ($manual_terms as $manual_term) {
                    $manual_term = octopus_ai_normalize_scope_text((string) $manual_term);
                    if ($manual_term === '' || strlen($manual_term) < 3 || in_array($manual_term, $stopwords, true)) {
                        continue;
                    }
                    if (!in_array($manual_term, $terms, true)) {
                        $terms[] = $manual_term;
                    }
                }
            }
        }

        if (function_exists('octopus_ai_detect_intent')) {
            $intent = sanitize_key((string) octopus_ai_detect_intent($question));
            if ($intent !== '' && !in_array($intent, $terms, true)) {
                $terms[] = $intent;
            }
        }

        $phrases = [];
        $phrase_source = array_slice($terms, 0, 8);
        $phrase_count = count($phrase_source);
        for ($i = 0; $i < $phrase_count - 1; $i++) {
            $bigram = trim($phrase_source[$i] . ' ' . $phrase_source[$i + 1]);
            if (strlen($bigram) >= 7 && !in_array($bigram, $phrases, true)) {
                $phrases[] = $bigram;
            }

            if ($i < $phrase_count - 2) {
                $trigram = trim($phrase_source[$i] . ' ' . $phrase_source[$i + 1] . ' ' . $phrase_source[$i + 2]);
                if (strlen($trigram) >= 12 && !in_array($trigram, $phrases, true)) {
                    $phrases[] = $trigram;
                }
            }

            if (count($phrases) >= 8) {
                break;
            }
        }

        $terms = array_values(array_unique(array_merge($terms, $phrases)));
        if (empty($terms) && $normalized !== '') {
            $terms[] = $normalized;
        }

        return array_slice($terms, 0, 18);
    }
}

if (!function_exists('octopus_ai_get_reference_intent_topic_hint')) {
    function octopus_ai_get_reference_intent_topic_hint($question)
    {
        if (!function_exists('octopus_ai_detect_intent')) {
            return '';
        }

        $intent = sanitize_key((string) octopus_ai_detect_intent($question));
        if ($intent === '') {
            return '';
        }

        $map = function_exists('octopus_ai_get_provider_intent_topic_map')
            ? octopus_ai_get_provider_intent_topic_map()
            : [];
        if (!is_array($map) || empty($map)) {
            $provider_defaults = function_exists('octopus_ai_get_default_provider_profile')
                ? octopus_ai_get_default_provider_profile()
                : [];
            $map = isset($provider_defaults['intent_topic_map']) && is_array($provider_defaults['intent_topic_map'])
                ? $provider_defaults['intent_topic_map']
                : [];
        }

        $allowed_topics = function_exists('octopus_ai_get_provider_allowed_topics')
            ? octopus_ai_get_provider_allowed_topics()
            : [];
        if (empty($allowed_topics)) {
            $topic_terms_map = function_exists('octopus_ai_get_topic_terms_map')
                ? octopus_ai_get_topic_terms_map()
                : [];
            $allowed_topics = array_values(array_filter(array_map('sanitize_key', array_keys(is_array($topic_terms_map) ? $topic_terms_map : []))));
        }

        $topic = isset($map[$intent]) ? sanitize_key((string) $map[$intent]) : '';
        if (!in_array($topic, $allowed_topics, true)) {
            return '';
        }

        return $topic;
    }
}

if (!function_exists('octopus_ai_get_reference_url_dedupe_key')) {
    function octopus_ai_get_reference_url_dedupe_key($url)
    {
        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return md5($url);
        }

        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        $path = rawurldecode($path);
        $path = preg_replace('#/+#', '/', $path);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        if ($host === '') {
            return strtolower($path);
        }

        return $host . '|' . strtolower($path);
    }
}

if (!function_exists('octopus_ai_score_reference_candidate')) {
    function octopus_ai_score_reference_candidate($question, $topic, $title, $slug, $url, $base_score = 0.0, $lang = 'NL')
    {
        $topic = sanitize_key((string) $topic);
        $intent_topic_hint = octopus_ai_get_reference_intent_topic_hint($question);
        if ($topic === '' && $intent_topic_hint !== '') {
            $topic = $intent_topic_hint;
        }

        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return -100.0;
        }

        $score = (float) $base_score;

        $title_norm = octopus_ai_normalize_scope_text($title);
        $slug_norm = octopus_ai_normalize_scope_text(str_replace(['-', '_', '.', '/'], ' ', (string) $slug));
        $url_norm = octopus_ai_normalize_scope_text($url);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $path_norm = octopus_ai_normalize_scope_text(str_replace(['-', '_', '.', '/'], ' ', $path));

        $query_terms = octopus_ai_get_reference_query_terms($question, $lang);
        foreach ($query_terms as $term) {
            if ($title_norm !== '' && strpos($title_norm, $term) !== false) {
                $score += 6.0;
            }
            if ($slug_norm !== '' && strpos($slug_norm, $term) !== false) {
                $score += 5.0;
            }
            if ($path_norm !== '' && strpos($path_norm, $term) !== false) {
                $score += 4.0;
            }
            if ($url_norm !== '' && strpos($url_norm, $term) !== false) {
                $score += 2.5;
            }
        }

        $topic_hits = 0;
        $other_best_hits = 0;
        if ($topic !== '') {
            $topic_terms_map = octopus_ai_get_topic_terms_map();
            $topic_terms = isset($topic_terms_map[$topic]) && is_array($topic_terms_map[$topic])
                ? $topic_terms_map[$topic]
                : [];

            foreach ($topic_terms as $topic_term) {
                $topic_term = octopus_ai_normalize_scope_text($topic_term);
                if ($topic_term === '' || strlen($topic_term) < 3) {
                    continue;
                }

                $found = false;
                if ($title_norm !== '' && strpos($title_norm, $topic_term) !== false) {
                    $score += 1.6;
                    $found = true;
                }
                if ($slug_norm !== '' && strpos($slug_norm, $topic_term) !== false) {
                    $score += 1.4;
                    $found = true;
                }
                if ($path_norm !== '' && strpos($path_norm, $topic_term) !== false) {
                    $score += 1.1;
                    $found = true;
                }

                if ($found) {
                    $topic_hits++;
                }
            }

            if ($topic_hits > 0) {
                $score += min(6.0, $topic_hits * 0.6);
            } else {
                $score -= 1.0;
            }

            foreach ($topic_terms_map as $topic_key => $topic_terms_other) {
                $topic_key = sanitize_key((string) $topic_key);
                if ($topic_key === '' || $topic_key === $topic || !is_array($topic_terms_other)) {
                    continue;
                }

                $candidate_hits = 0;
                foreach ($topic_terms_other as $topic_term_other) {
                    $topic_term_other = octopus_ai_normalize_scope_text($topic_term_other);
                    if ($topic_term_other === '' || strlen($topic_term_other) < 3) {
                        continue;
                    }

                    if (
                        ($title_norm !== '' && strpos($title_norm, $topic_term_other) !== false) ||
                        ($slug_norm !== '' && strpos($slug_norm, $topic_term_other) !== false) ||
                        ($path_norm !== '' && strpos($path_norm, $topic_term_other) !== false)
                    ) {
                        $candidate_hits++;
                    }
                }

                if ($candidate_hits > $other_best_hits) {
                    $other_best_hits = $candidate_hits;
                }
            }

            if ($other_best_hits > $topic_hits && $other_best_hits >= 2) {
                $score -= min(8.0, ($other_best_hits - $topic_hits) * 1.4);
            }
        }

        $path_lower = strtolower((string) $path);
        $path_trim = trim($path_lower, '/');
        if ($path_trim === '' || preg_match('#^manual(?:/(nl|fr))?$#', $path_trim)) {
            $score -= 6.0;
        }
        if (strpos($url_norm, 'hmftsearch htm') !== false) {
            $score -= 10.0;
        }
        if (preg_match('#/(index|default)\.html?$#i', $path_lower)) {
            $score -= 3.0;
        }
        if (preg_match('#\.html?$#i', $path_lower) && substr_count($path_trim, '/') >= 1) {
            $score += 1.2;
        }

        $path_depth = $path_trim === '' ? 0 : (substr_count($path_trim, '/') + 1);
        if ($path_depth >= 3) {
            $score += 0.8;
        }

        if (function_exists('octopus_ai_score_manual_url_for_question')) {
            $score += 0.45 * (float) octopus_ai_score_manual_url_for_question($question, $url, $lang);
        }

        return (float) $score;
    }
}

if (!function_exists('octopus_ai_build_reference_title')) {
    function octopus_ai_build_reference_title($title, $slug, $url, $lang = 'NL')
    {
        $title = trim(sanitize_text_field((string) $title));
        if ($title !== '') {
            return $title;
        }

        $slug_candidate = trim((string) pathinfo((string) $slug, PATHINFO_FILENAME));
        if ($slug_candidate !== '') {
            $label = ucwords(str_replace(['-', '_'], ' ', $slug_candidate));
            $label = trim(sanitize_text_field($label));
            if ($label !== '') {
                return $label;
            }
        }

        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        $path_candidate = trim((string) pathinfo($path, PATHINFO_FILENAME));
        if ($path_candidate !== '') {
            $label = ucwords(str_replace(['-', '_'], ' ', $path_candidate));
            $label = trim(sanitize_text_field($label));
            if ($label !== '') {
                return $label;
            }
        }

        return strtoupper((string) $lang) === 'FR'
            ? 'Voir dans le manuel'
            : 'Bekijk dit in de handleiding';
    }
}

if (!function_exists('octopus_ai_build_manual_topic_index')) {
    function octopus_ai_build_manual_topic_index($lang = 'NL')
    {
        if (!function_exists('octopus_ai_get_chunk_index')) {
            $context_helper = __DIR__ . '/context-retriever.php';
            if (file_exists($context_helper)) {
                require_once $context_helper;
            }
        }

        if (!function_exists('octopus_ai_get_chunk_index') || !function_exists('octopus_ai_get_manual_url_candidates')) {
            return [];
        }

        $lang = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';
        $upload_dir = wp_upload_dir();
        $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
        if (!is_dir($chunks_dir)) {
            return [];
        }

        $index_data = octopus_ai_get_chunk_index($chunks_dir);
        if (!is_array($index_data)) {
            return [];
        }

        $signature = isset($index_data['signature']) ? (string) $index_data['signature'] : 'empty';
        $cache_key = 'octopus_ai_manual_topics_' . md5($lang . '|' . $signature);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $entries = isset($index_data['entries']) && is_array($index_data['entries'])
            ? $index_data['entries']
            : [];
        if (empty($entries)) {
            set_transient($cache_key, [], 20 * MINUTE_IN_SECONDS);
            return [];
        }

        $topics_by_url = [];
        $topic_terms_map = function_exists('octopus_ai_get_topic_terms_map')
            ? octopus_ai_get_topic_terms_map()
            : [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $meta = isset($entry['metadata']) && is_array($entry['metadata']) ? $entry['metadata'] : [];
            $candidates = octopus_ai_get_manual_url_candidates($meta, $lang);
            if (empty($candidates)) {
                continue;
            }

            $section_title = (string) ($meta['section_title'] ?? '');
            $page_slug = (string) ($meta['page_slug'] ?? '');
            $source_url = (string) ($meta['source_url'] ?? '');
            $manual_url = (string) ($meta['manual_url'] ?? '');
            $content_preview_norm = (string) ($entry['content_preview_norm'] ?? '');

            $blob_parts = [];
            $blob_parts[] = octopus_ai_normalize_scope_text($section_title);
            $blob_parts[] = octopus_ai_normalize_scope_text(str_replace(['-', '_', '/', '.'], ' ', $page_slug));
            $blob_parts[] = octopus_ai_normalize_scope_text(str_replace(['-', '_', '/', '.'], ' ', $source_url));
            $blob_parts[] = octopus_ai_normalize_scope_text(str_replace(['-', '_', '/', '.'], ' ', $manual_url));
            $blob_parts[] = octopus_ai_normalize_scope_text($content_preview_norm);
            $blob = trim(implode(' ', array_filter($blob_parts)));
            $entry_topic_hits = [];

            if (function_exists('octopus_ai_get_chunk_topic_hits') && is_array($topic_terms_map) && !empty($topic_terms_map)) {
                $entry_topic_hits = octopus_ai_get_chunk_topic_hits($entry, $topic_terms_map);
                if (!is_array($entry_topic_hits)) {
                    $entry_topic_hits = [];
                }
            }

            $entry_modified = isset($entry['modified']) ? (int) $entry['modified'] : 0;

            foreach ($candidates as $candidate_url) {
                $candidate_url = esc_url_raw((string) $candidate_url);
                if ($candidate_url === '' || !octopus_ai_is_allowed_manual_url($candidate_url, $lang)) {
                    continue;
                }

                if (!isset($topics_by_url[$candidate_url])) {
                    $topics_by_url[$candidate_url] = [
                        'url' => $candidate_url,
                        'title' => '',
                        'slug' => '',
                        'blob' => '',
                        'hits' => 0,
                        'topic_hits' => [],
                        'latest_modified' => 0,
                    ];
                }

                if ($topics_by_url[$candidate_url]['title'] === '' && trim($section_title) !== '') {
                    $topics_by_url[$candidate_url]['title'] = trim($section_title);
                }
                if ($topics_by_url[$candidate_url]['slug'] === '' && trim($page_slug) !== '') {
                    $topics_by_url[$candidate_url]['slug'] = trim($page_slug);
                }

                if ($blob !== '') {
                    $topics_by_url[$candidate_url]['blob'] .= ' ' . $blob;
                }
                $topics_by_url[$candidate_url]['hits'] = (int) $topics_by_url[$candidate_url]['hits'] + 1;

                if (!empty($entry_topic_hits)) {
                    foreach ($entry_topic_hits as $topic_key => $topic_hit_score) {
                        $topic_key = sanitize_key((string) $topic_key);
                        $topic_hit_score = (int) $topic_hit_score;
                        if ($topic_key === '' || $topic_hit_score <= 0) {
                            continue;
                        }

                        if (!isset($topics_by_url[$candidate_url]['topic_hits'][$topic_key])) {
                            $topics_by_url[$candidate_url]['topic_hits'][$topic_key] = 0;
                        }
                        $topics_by_url[$candidate_url]['topic_hits'][$topic_key] += $topic_hit_score;
                    }
                }

                if ($entry_modified > (int) ($topics_by_url[$candidate_url]['latest_modified'] ?? 0)) {
                    $topics_by_url[$candidate_url]['latest_modified'] = $entry_modified;
                }
            }
        }

        $result = array_values($topics_by_url);
        set_transient($cache_key, $result, 20 * MINUTE_IN_SECONDS);

        return $result;
    }
}

if (!function_exists('octopus_ai_select_topic_reference_links')) {
    function octopus_ai_select_topic_reference_links($question, $topic = '', $lang = 'NL', $limit = 5)
    {
        $limit = max(1, min(10, (int) $limit));
        $topic_index = octopus_ai_build_manual_topic_index($lang);
        if (empty($topic_index)) {
            return [];
        }

        $topic = sanitize_key((string) $topic);
        $detected_topic = function_exists('octopus_ai_detect_topic_for_retrieval')
            ? sanitize_key((string) octopus_ai_detect_topic_for_retrieval($question))
            : '';
        $intent_topic_hint = octopus_ai_get_reference_intent_topic_hint($question);
        $reference_topic = $topic !== '' ? $topic : ($detected_topic !== '' ? $detected_topic : $intent_topic_hint);
        $reference_topic = sanitize_key((string) $reference_topic);

        $query_terms = octopus_ai_get_reference_query_terms($question, $lang);
        $topic_terms_map = octopus_ai_get_topic_terms_map();
        $topic_terms = ($reference_topic !== '' && isset($topic_terms_map[$reference_topic]) && is_array($topic_terms_map[$reference_topic]))
            ? $topic_terms_map[$reference_topic]
            : [];

        $scored = [];
        foreach ($topic_index as $row) {
            if (!is_array($row)) {
                continue;
            }

            $url = esc_url_raw((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $title = (string) ($row['title'] ?? '');
            $slug = (string) ($row['slug'] ?? '');
            $blob = octopus_ai_normalize_scope_text((string) ($row['blob'] ?? ''));
            $hits = max(1, (int) ($row['hits'] ?? 1));
            $row_topic_hits = isset($row['topic_hits']) && is_array($row['topic_hits'])
                ? $row['topic_hits']
                : [];
            $latest_modified = isset($row['latest_modified']) ? (int) $row['latest_modified'] : 0;

            $score = octopus_ai_score_reference_candidate($question, $reference_topic, $title, $slug, $url, 0.0, $lang);
            $score += min(4.0, $hits * 0.15);

            foreach ($query_terms as $term) {
                $term = octopus_ai_normalize_scope_text($term);
                if ($term === '' || $blob === '') {
                    continue;
                }

                $occurrences = substr_count($blob, $term);
                if ($occurrences > 0) {
                    $score += min(8.0, 1.8 + ($occurrences * 1.1));
                }
            }

            if ($reference_topic !== '') {
                $selected_topic_hits = isset($row_topic_hits[$reference_topic]) ? (int) $row_topic_hits[$reference_topic] : 0;
                $other_best_hits = 0;
                foreach ($row_topic_hits as $topic_key => $topic_hit_score) {
                    $topic_key = sanitize_key((string) $topic_key);
                    if ($topic_key === '' || $topic_key === $reference_topic) {
                        continue;
                    }

                    $topic_hit_score = (int) $topic_hit_score;
                    if ($topic_hit_score > $other_best_hits) {
                        $other_best_hits = $topic_hit_score;
                    }
                }

                if ($selected_topic_hits > 0) {
                    $score += min(12.0, 1.4 + ($selected_topic_hits * 0.5));
                } elseif ($other_best_hits >= 2) {
                    $score -= min(8.0, 1.2 + ($other_best_hits * 0.5));
                }
            } elseif ($intent_topic_hint !== '') {
                $intent_hint_hits = isset($row_topic_hits[$intent_topic_hint]) ? (int) $row_topic_hits[$intent_topic_hint] : 0;
                if ($intent_hint_hits > 0) {
                    $score += min(7.0, 0.8 + ($intent_hint_hits * 0.35));
                }
            }

            foreach ($topic_terms as $term) {
                $term = octopus_ai_normalize_scope_text($term);
                if ($term === '' || strlen($term) < 3 || $blob === '') {
                    continue;
                }
                if (strpos($blob, $term) !== false) {
                    $score += 0.9;
                }
            }

            if ($blob === '') {
                $score -= 1.2;
            }

            if (function_exists('octopus_ai_score_manual_url_for_question')) {
                $score += 0.35 * (float) octopus_ai_score_manual_url_for_question($question, $url, $lang);
            }

            if ($latest_modified > 0) {
                $age_days = (time() - $latest_modified) / DAY_IN_SECONDS;
                if ($age_days >= 0) {
                    $score += 2.2 * exp(-$age_days / 60.0);
                    if ($age_days < 30) {
                        $score += 0.6;
                    } elseif ($age_days > 365) {
                        $score -= 0.8;
                    }
                }
            }

            $scored[] = [
                'title' => octopus_ai_build_reference_title($title, $slug, $url, $lang),
                'url' => $url,
                'score' => (float) $score,
            ];
        }

        if (empty($scored)) {
            return [];
        }

        usort($scored, static function ($a, $b) {
            $score_a = isset($a['score']) ? (float) $a['score'] : 0.0;
            $score_b = isset($b['score']) ? (float) $b['score'] : 0.0;
            if ($score_a === $score_b) {
                return 0;
            }
            return ($score_a < $score_b) ? 1 : -1;
        });

        $unique = [];
        $result = [];
        foreach ($scored as $candidate) {
            $url = (string) ($candidate['url'] ?? '');
            $dedupe_key = octopus_ai_get_reference_url_dedupe_key($url);
            if ($url === '' || ($dedupe_key !== '' && isset($unique[$dedupe_key]))) {
                continue;
            }

            $unique[$dedupe_key !== '' ? $dedupe_key : $url] = true;
            $result[] = $candidate;
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }
}

if (!function_exists('octopus_ai_detect_topic_for_retrieval')) {
    function octopus_ai_detect_topic_for_retrieval($message)
    {
        $analysis = octopus_ai_get_topic_match_analysis($message);
        $best_topic = sanitize_key((string) ($analysis['best_topic'] ?? ''));
        $best_score = (float) ($analysis['best_score'] ?? 0.0);
        $second_score = (float) ($analysis['second_score'] ?? 0.0);
        $is_ambiguous = !empty($analysis['is_ambiguous']);

        if ($best_topic === '' || $is_ambiguous) {
            return '';
        }

        if ($best_score < 1.8) {
            return '';
        }

        if (($best_score - $second_score) < 0.9) {
            return '';
        }

        return $best_topic;
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

if (!function_exists('octopus_ai_select_top_reference_links')) {
    function octopus_ai_select_top_reference_links(array $reference_candidates, $lang = 'NL', $limit = 3)
    {
        $limit = max(1, min(10, (int) $limit));
        if (empty($reference_candidates)) {
            return [];
        }

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
        $selected = [];
        foreach ($reference_candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $url = esc_url_raw((string) ($candidate['url'] ?? ''));
            $dedupe_key = octopus_ai_get_reference_url_dedupe_key($url);
            if ($url === '' || ($dedupe_key !== '' && isset($seen_urls[$dedupe_key]))) {
                continue;
            }

            $seen_urls[$dedupe_key !== '' ? $dedupe_key : $url] = true;
            $title = sanitize_text_field((string) ($candidate['title'] ?? ''));
            if ($title === '') {
                $title = strtoupper((string) $lang) === 'FR'
                    ? 'Voir dans le manuel'
                    : 'Bekijk dit in de handleiding';
            }

            $selected[] = [
                'title' => $title,
                'url' => $url,
                'score' => isset($candidate['score']) ? (float) $candidate['score'] : 0.0,
            ];

            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }
}

if (!function_exists('octopus_ai_get_confidence_threshold')) {
    function octopus_ai_get_confidence_threshold()
    {
        $raw = get_option('octopus_ai_confidence_threshold', 55);
        $percent = is_numeric($raw) ? (float) $raw : 55.0;
        $percent = max(0.0, min(100.0, $percent));
        return $percent / 100.0;
    }
}

if (!function_exists('octopus_ai_get_handoff_url')) {
    function octopus_ai_get_handoff_url($lang = 'NL')
    {
        $lang = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';
        $option_key = $lang === 'FR' ? 'octopus_ai_handoff_url_fr' : 'octopus_ai_handoff_url_nl';
        $url = esc_url_raw((string) get_option($option_key, ''));

        if ($url === '') {
            return '';
        }

        if (function_exists('wp_http_validate_url') && !wp_http_validate_url($url)) {
            return '';
        }

        return $url;
    }
}

if (!function_exists('octopus_ai_calculate_answer_confidence')) {
    function octopus_ai_calculate_answer_confidence(array $signals = [])
    {
        $context = (string) ($signals['context'] ?? '');
        $live_context = (string) ($signals['live_context'] ?? '');
        $metadata_chunks = isset($signals['metadata_chunks']) && is_array($signals['metadata_chunks'])
            ? $signals['metadata_chunks']
            : [];
        $reference_count = max(0, (int) ($signals['reference_count'] ?? 0));
        $topic_selected = !empty($signals['topic_selected']);

        $best_signal = max(
            0.0,
            (float) ($signals['best_metadata_score'] ?? 0.0),
            (float) ($signals['best_live_score'] ?? 0.0)
        );

        $total_context_length = strlen(trim($context)) + strlen(trim($live_context));
        $context_norm = min(1.0, $total_context_length / 9000.0);
        $metadata_norm = min(1.0, count($metadata_chunks) / 5.0);
        $reference_norm = min(1.0, $reference_count / 3.0);

        $signal_norm = 0.0;
        if ($best_signal >= 8.0) {
            $signal_norm = 1.0;
        } elseif ($best_signal >= 5.0) {
            $signal_norm = 0.75;
        } elseif ($best_signal >= 2.5) {
            $signal_norm = 0.5;
        } elseif ($best_signal > 0.0) {
            $signal_norm = 0.2;
        }

        $score = 0.05;
        $score += 0.4 * $context_norm;
        $score += 0.3 * $signal_norm;
        $score += 0.15 * $metadata_norm;
        $score += 0.1 * $reference_norm;
        if ($topic_selected) {
            $score += 0.05;
        }

        return max(0.0, min(1.0, $score));
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
    function octopus_ai_build_no_solution_answer($lang, $message, $fallback = '', array $options = [])
    {
        $lang = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';
        $message = (string) $message;
        $answer = octopus_ai_get_no_solution_message($lang, $fallback);
        $reference_candidates = isset($options['references']) && is_array($options['references'])
            ? $options['references']
            : [];
        $reference_links = function_exists('octopus_ai_select_top_reference_links')
            ? octopus_ai_select_top_reference_links($reference_candidates, $lang, 3)
            : [];

        if (!empty($reference_links)) {
            $heading = ($lang === 'FR') ? 'Pages utiles' : 'Relevante pagina\'s';
            $answer .= "\n\n{$heading}:\n";
            foreach ($reference_links as $reference_link) {
                $title = sanitize_text_field((string) ($reference_link['title'] ?? ''));
                $url = esc_url_raw((string) ($reference_link['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                if ($title === '') {
                    $title = ($lang === 'FR') ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding';
                }
                $answer .= '- [' . $title . '](' . $url . ')' . "\n";
            }
            $answer = rtrim($answer);
        }

        if (!function_exists('octopus_ai_extract_keyword')) {
            $keyword_helper = plugin_dir_path(__FILE__) . 'helpers/extract-keyword.php';
            if (file_exists($keyword_helper)) {
                require_once $keyword_helper;
            }
        }

        if (empty($reference_links) && function_exists('octopus_ai_extract_keyword')) {
            $keyword = octopus_ai_extract_keyword($message);
            if ($keyword) {
                $search_url = function_exists('octopus_ai_get_manual_search_url')
                    ? octopus_ai_get_manual_search_url($lang, $keyword)
                    : octopus_ai_get_manual_search_fallback_url($lang, $keyword);
                $link_text = ($lang === 'FR')
                    ? 'Voir aussi dans la documentation'
                    : 'Bekijk mogelijke info in de handleiding';
                $answer .= "\n\n[$link_text]($search_url)";
            }
        }

        $handoff_url = esc_url_raw((string) ($options['handoff_url'] ?? ''));
        if ($handoff_url === '') {
            $handoff_url = function_exists('octopus_ai_get_handoff_url')
                ? octopus_ai_get_handoff_url($lang)
                : '';
        }
        if ($handoff_url !== '') {
            $handoff_label = ($lang === 'FR') ? 'Contacter le support' : 'Contacteer support';
            $answer .= "\n\n[$handoff_label]($handoff_url)";
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

        $provider_defaults = function_exists('octopus_ai_get_default_provider_profile')
            ? octopus_ai_get_default_provider_profile()
            : [];
        $default_brand_terms = isset($provider_defaults['brand_terms']) && is_array($provider_defaults['brand_terms'])
            ? $provider_defaults['brand_terms']
            : [];
        $default_domain_terms = isset($provider_defaults['domain_terms']) && is_array($provider_defaults['domain_terms'])
            ? $provider_defaults['domain_terms']
            : [];
        $default_topic_terms = function_exists('octopus_ai_get_provider_topic_terms_map')
            ? octopus_ai_get_provider_topic_terms_map()
            : (
                isset($provider_defaults['topic_terms']) && is_array($provider_defaults['topic_terms'])
                    ? $provider_defaults['topic_terms']
                    : []
            );
        $default_off_topic_terms = isset($provider_defaults['off_topic_terms']) && is_array($provider_defaults['off_topic_terms'])
            ? $provider_defaults['off_topic_terms']
            : [];

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

        $has_brand_match = $contains_any($normalized, $brand_terms);
        if ($has_brand_match) {
            return true;
        }

        $has_domain_match = $contains_any($normalized, $domain_terms);
        if ($has_domain_match) {
            return true;
        }

        $has_topic_match = ($topic !== '' && isset($topic_terms[$topic]) && $contains_any($normalized, $topic_terms[$topic]));
        if ($has_topic_match) {
            return true;
        }

        $has_history = is_array($history) && !empty($history);
        $normalized_length = function_exists('octopus_ai_string_length')
            ? octopus_ai_string_length($normalized)
            : strlen((string) $normalized);
        $has_followup_question = (bool) preg_match('/\b(hoe|waar|welke|wat|kan|mag|moet|comment|ou|quel|quelle|puis|peux|dois|faut)\b/u', $normalized);
        $has_off_topic_match = $contains_any($normalized, $off_topic_terms);

        // In actieve gesprekken behandelen we korte vervolgvragen soepeler.
        if ($has_off_topic_match) {
            if (!$has_history || ($normalized_length > 140 && !$has_followup_question)) {
                return false;
            }
        }

        if (
            $has_history &&
            (
                $normalized_length <= 120 ||
                $has_followup_question
            )
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

            $raw_role = isset($entry['role']) ? sanitize_text_field((string) $entry['role']) : '';
            $role = strtolower(trim((string) $raw_role));
            if ($role === 'bot') {
                $role = 'assistant';
            }
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $content = isset($entry['content']) ? sanitize_textarea_field((string) $entry['content']) : '';
            $content = trim((string) preg_replace('/\s+/u', ' ', $content));
            if ($content === '') {
                continue;
            }

            if (function_exists('mb_substr')) {
                $content = (string) mb_substr($content, 0, 1500);
            } else {
                $content = (string) substr($content, 0, 1500);
            }

            $sanitized[] = [
                'role' => $role,
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

        // Verwijder eenvoudige dubbele labels/regels die UX vervuilen.
        $answer = preg_replace('/\b(Bekijk dit in de handleiding)(\s+\1)+\b/ui', '$1', (string) $answer);
        $answer = preg_replace('/\b(Voir dans le manuel)(\s+\1)+\b/ui', '$1', (string) $answer);

        $lines = preg_split('/\n/u', (string) $answer);
        if (is_array($lines) && !empty($lines)) {
            $cleaned_lines = [];
            $previous_key = '';
            foreach ($lines as $line) {
                $line = rtrim((string) $line);
                $line_key = strtolower(trim((string) preg_replace('/\s+/u', ' ', $line)));
                if ($line_key !== '' && $line_key === $previous_key) {
                    continue;
                }
                $cleaned_lines[] = $line;
                $previous_key = $line_key;
            }
            $answer = implode("\n", $cleaned_lines);
            $answer = preg_replace("/\n{3,}/", "\n\n", (string) $answer);
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
            $state['open_until'] = time() + 90;
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
    function octopus_ai_openai_chat_completion_with_retry($api_key, array $messages, $model, $max_attempts = 3, $timeout_seconds = 20)
    {
        $max_attempts = max(1, min(3, (int) $max_attempts));
        $timeout_seconds = max(5, min(30, (int) $timeout_seconds));

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
                'timeout' => $timeout_seconds,
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

        // Model-fallback: sommige omgevingen hebben geen toegang tot het gekozen model.
        $fallback_model = apply_filters('octopus_ai_openai_fallback_model', 'gpt-4o-mini', $model, $last_status_code, $error_message);
        $fallback_model = sanitize_text_field((string) $fallback_model);
        $is_model_error = (
            in_array((int) $last_status_code, [400, 404], true) &&
            stripos((string) $error_message, 'model') !== false
        );
        if ($is_model_error && $fallback_model !== '' && $fallback_model !== (string) $model) {
            return octopus_ai_openai_chat_completion_with_retry(
                $api_key,
                $messages,
                $fallback_model,
                1,
                $timeout_seconds
            );
        }

        return new WP_Error(
            'api_error',
            sprintf(__('Fout van OpenAI (HTTP %1$d): %2$s', 'octopus-ai'), $last_status_code, $error_message),
            ['status' => $status_for_client]
        );
    }
}

// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Chatbot callback
function octopus_ai_chatbot_callback($request)
{
    if (
        function_exists('octopus_ai_chatbot_safe_callback') &&
        (
            !function_exists('octopus_ai_chatbot_safe_mode_enabled') ||
            octopus_ai_chatbot_safe_mode_enabled($request)
        )
    ) {
        return octopus_ai_chatbot_safe_callback($request);
    }

    try {
    if (function_exists('set_time_limit')) {
        @set_time_limit(55);
    }
    @ini_set('max_execution_time', '55');

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
    $allowed_topics = function_exists('octopus_ai_get_provider_allowed_topics')
        ? octopus_ai_get_provider_allowed_topics()
        : [];
    if (empty($allowed_topics)) {
        $topic_terms_map = function_exists('octopus_ai_get_topic_terms_map')
            ? octopus_ai_get_topic_terms_map()
            : [];
        $allowed_topics = array_values(array_filter(array_map('sanitize_key', array_keys(is_array($topic_terms_map) ? $topic_terms_map : []))));
    }
    if (!in_array($topic, $allowed_topics, true)) {
        $topic = '';
    }
    $selected_topic = $topic;
    $effective_topic = $topic;
    $lang = octopus_ai_get_request_language();
    $effective_query_data = function_exists('octopus_ai_get_effective_retrieval_message')
        ? octopus_ai_get_effective_retrieval_message($message, $history, $lang)
        : ['query' => $message, 'used_history' => false, 'previous_user_message' => ''];
    $retrieval_query = trim((string) ($effective_query_data['query'] ?? $message));
    if ($retrieval_query === '') {
        $retrieval_query = $message;
    }
    $reference_query = trim((string) ($effective_query_data['previous_user_message'] ?? ''));
    if ($reference_query === '') {
        $reference_query = $message;
    }
    $retrieval_topic = $effective_topic;
    if ($retrieval_topic === '' && function_exists('octopus_ai_detect_topic_for_retrieval')) {
        $detected_topic = sanitize_key((string) octopus_ai_detect_topic_for_retrieval($retrieval_query));
        if (in_array($detected_topic, $allowed_topics, true)) {
            $retrieval_topic = $detected_topic;
        }
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

    $intent = octopus_ai_detect_intent($retrieval_query);
    if ($intent) {
        error_log('[Octopus AI] Gedetecteerde intent: ' . $intent);
    }

    if (octopus_ai_is_3d_printing_question($message)) {
        $easter_egg_answer = octopus_ai_get_3d_printing_easter_egg_answer($lang);
        $easter_egg_answer = octopus_ai_sanitize_answer_output($easter_egg_answer);

        return rest_ensure_response([
            'answer' => $easter_egg_answer,
            'chat_id' => 0,
            'status' => 'easter_egg_3d',
        ]);
    }

    $topic_mismatch = (!$skip_topic_mismatch) ? octopus_ai_detect_topic_mismatch($message, $selected_topic, $history) : '';
    $topic_mismatch_notice = '';
    if ($topic_mismatch !== '') {
        $current_label = octopus_ai_get_topic_label($selected_topic, $lang);
        $suggested_label = octopus_ai_get_topic_label($topic_mismatch, $lang);

        $topic_mismatch_notice = ($lang === 'FR')
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
        $topic_mismatch_notice = octopus_ai_apply_language_glossary($topic_mismatch_notice, $lang);

        // Niet blokkeren: antwoord geven op de vraag, maar voor deze beurt beide flows doorzoeken.
        $effective_topic = '';
        $retrieval_topic = $topic_mismatch;
    }

    if (!octopus_ai_is_in_scope_question($message, $selected_topic, $history)) {
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

    $disable_live_manual_for_chat = (bool) apply_filters(
        'octopus_ai_disable_live_manual_for_chat_requests',
        true,
        $manual_mode,
        $source_strategy,
        $lang
    );
    if ($disable_live_manual_for_chat) {
        $use_live_manual = false;
        $use_local_chunks = true;
        if ($source_strategy === 'live_manual') {
            $source_strategy = 'manual_upload';
        }
    }

    if (
        $use_live_manual &&
        function_exists('octopus_ai_is_chatbot_rest_request') &&
        octopus_ai_is_chatbot_rest_request() &&
        function_exists('octopus_ai_live_manual_enabled_for_chat_requests') &&
        !octopus_ai_live_manual_enabled_for_chat_requests($lang, $retrieval_query)
    ) {
        $use_live_manual = false;
        $use_local_chunks = true;
        if ($source_strategy === 'live_manual') {
            $source_strategy = 'manual_upload';
        }
    }

    if ($use_live_manual) {
        $live_memory_limit = function_exists('octopus_ai_live_manual_get_memory_limit_bytes')
            ? (int) octopus_ai_live_manual_get_memory_limit_bytes()
            : (function_exists('octopus_ai_get_memory_limit_bytes') ? (int) octopus_ai_get_memory_limit_bytes() : 0);
        $live_max_execution = function_exists('octopus_ai_live_manual_get_max_execution_time_seconds')
            ? (int) octopus_ai_live_manual_get_max_execution_time_seconds()
            : (is_numeric(ini_get('max_execution_time')) ? (int) ini_get('max_execution_time') : 0);
        $low_runtime_budget = (
            ($live_max_execution > 0 && $live_max_execution <= 30) ||
            ($live_memory_limit > 0 && $live_memory_limit < (320 * 1024 * 1024))
        );

        $force_local_on_low_budget = (bool) apply_filters(
            'octopus_ai_force_local_chunks_on_low_runtime_budget',
            true,
            $manual_mode,
            $source_strategy,
            $live_max_execution,
            $live_memory_limit
        );

        if ($low_runtime_budget && $force_local_on_low_budget) {
            $use_live_manual = false;
            $use_local_chunks = true;
            if ($source_strategy === 'live_manual') {
                $source_strategy = 'manual_upload';
            }
            error_log(
                sprintf(
                    '[Octopus AI] Live manual tijdelijk uitgeschakeld door runtime-limiet (max_execution_time=%d, memory_limit=%d).',
                    $live_max_execution,
                    $live_memory_limit
                )
            );
        }
    }

    $api_key = trim((string) get_option('octopus_ai_api_key'));
    if ($lang === 'FR') {
    $tone = <<<EOT
ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€¦Ã‚Â½Ãƒâ€šÃ‚Â¯ Objectif
Tu es un chatbot professionnel qui aide les clients ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â  utiliser Octopus de maniÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¨re claire, efficace et conviviale.

Fournis des rÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©ponses directes et utiles sur lÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢utilisation dÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢Octopus

Utilise des paragraphes courts, des listes ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â  puces ou des ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©tapes lorsque cela facilite la comprÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©hension

ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒâ€šÃ‚Â£ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â Ton

Professionnel, chaleureux, adaptÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â© au public belge francophone

Ne mentionne jamais lÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢IA, GPT ou toute technologie similaire

Aucune supposition ou invention : reste factuel et prÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©cis

Ne tÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢appuie que sur les chunks fournis et sur les pages du manuel autorisÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©es.

ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€¦Ã‚Â¡Ãƒâ€šÃ‚Â« Limitations

RÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©pond uniquement si un contexte pertinent est disponible

Ne fournis aucune information sur la lÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©gislation, la comptabilitÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â© ou des logiciels externes

En cas de doute, rÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©ponds simplement : ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â« DÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©solÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©, je ne peux pas tÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢aider avec ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§a. ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â»

ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢Ãƒâ€šÃ‚Â¬ Comportement

Si lÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢utilisateur rÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©pond par ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â« oui ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â», ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â« ok ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â» ou confirme, continue avec les instructions ou dÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©tails utiles, sans te rÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©pÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©ter inutilement

ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾ Si possible

Ajoute la mention : ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â« ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾ Voir dans le manuel ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â» avec un lien valide lorsque cÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢est pertinent

Termine en partageant la liste des trois pages du manuel les plus pertinentes.

Contexte :
EOT;
} else {
    $tone = get_option('octopus_ai_tone') ?: <<<EOT
Je bent een AI-chatbot die klanten professioneel, duidelijk en kort helpt bij het gebruik van deze software.

ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€¦Ã‚Â½Ãƒâ€šÃ‚Â¯ Doel:
- Help gebruikers stap voor stap bij hun vraag over de werking van Octopus
- Geef vlotte, concrete en heldere antwoorden
- Gebruik waar nuttig bullets, stappen of korte paragrafen

ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒâ€šÃ‚Â£ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â Tone of voice:
- Vriendelijk, Vlaams professioneel en to the point
- Geen disclaimers of verwijzingen naar AI, GPT of technologie
- Geen veronderstellingen of verzinsels

ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€¦Ã‚Â¡Ãƒâ€šÃ‚Â« Beperkingen:
- Beantwoord enkel vragen waarvoor relevante context beschikbaar is
- Geef gÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©n antwoord over wetgeving, boekhoudregels, code of externe software
- Bij twijfel: zeg ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã¢â‚¬Å“Sorry, daar kan ik je niet mee helpen.ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â

ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢Ãƒâ€šÃ‚Â¬ Conversatiegedrag:
- Als de gebruiker ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã¢â‚¬Å“jaÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â, ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã¢â‚¬Å“okÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â, ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã¢â‚¬Å“doe maarÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â of iets bevestigend antwoordt, beschouw dit als een vervolg op je vorige uitleg
- Geef dan het logische volgende stapje of verdieping
- Herhaal in dat geval **niet** je vorige antwoord

ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾ Indien beschikbaar:
- Voeg onderaan toe: ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã¢â‚¬Å“ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾ Bekijk dit in de handleidingÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â met een juiste link

Gebruik alleen informatie uit de gedeelde context en de toegestane handleiding-URLÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢s.
Sluit af met een opsomming van de drie meest relevante handleidinglinks.

Context:
EOT;
}


    $fallback_default = ($lang === 'FR')
        ? "DÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©solÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©, je ne peux pas tÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢aider avec ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§a."
        : get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.');
    $fallback = function_exists('octopus_ai_get_provider_fallback_text')
        ? octopus_ai_get_provider_fallback_text($lang, $fallback_default)
        : $fallback_default;

    if ($api_key === '') {
        $config_notice = ($lang === 'FR')
            ? "La configuration du chatbot est incomplete. Merci de verifier la cle API dans les reglages."
            : 'De chatbotconfiguratie is onvolledig. Controleer de API-key in de instellingen.';
        $missing_key_answer = octopus_ai_build_no_solution_answer(
            $lang,
            $reference_query !== '' ? $reference_query : $message,
            $fallback,
            [
                'references' => [],
                'handoff_url' => function_exists('octopus_ai_get_handoff_url') ? octopus_ai_get_handoff_url($lang) : '',
            ]
        );
        $missing_key_answer = $config_notice . "\n\n" . ltrim((string) $missing_key_answer);
        $missing_key_answer = octopus_ai_sanitize_answer_output($missing_key_answer);

        return rest_ensure_response([
            'answer' => $missing_key_answer,
            'chat_id' => 0,
            'status' => 'config_error_missing_api_key',
            'confidence' => 0.0,
            'reference_links' => [],
            'suggested_topic' => $topic_mismatch,
            'current_topic' => $selected_topic,
            'primary_source_url' => '',
        ]);
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
        $extract_metadata_chunks = static function ($result) {
            if (!is_array($result)) {
                return [];
            }
            if (isset($result['metadata']['chunks']) && is_array($result['metadata']['chunks'])) {
                return $result['metadata']['chunks'];
            }
            if (isset($result['metadatas']) && is_array($result['metadatas'])) {
                return $result['metadatas'];
            }
            if (isset($result['metas']) && is_array($result['metas'])) {
                return $result['metas'];
            }
            return [];
        };
        $get_best_metadata_score = static function (array $metadata_chunks) {
            $best_score = 0.0;
            foreach ($metadata_chunks as $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $score = isset($meta['score']) ? (float) $meta['score'] : 0.0;
                if ($score > $best_score) {
                    $best_score = $score;
                }
            }
            return $best_score;
        };

        if ($use_local_chunks && $effective_topic === '') {
            $dual_topics = $allowed_topics;
            if ($retrieval_topic !== '') {
                $dual_topics = array_values(array_unique(array_merge([$retrieval_topic], $dual_topics)));
            }
            $dual_topic_results = [];
            foreach ($dual_topics as $candidate_topic) {
                $candidate_result = octopus_ai_retrieve_relevant_chunks($retrieval_query, $candidate_topic);
                $candidate_context = trim((string) ($candidate_result['context'] ?? ''));
                $candidate_metas = [];
                foreach ($extract_metadata_chunks($candidate_result) as $candidate_meta) {
                    if (!is_array($candidate_meta)) {
                        continue;
                    }
                    $candidate_meta['topic'] = $candidate_topic;
                    $candidate_metas[] = $candidate_meta;
                }

                if ($candidate_context === '' && empty($candidate_metas)) {
                    continue;
                }

                $dual_topic_results[] = [
                    'topic' => $candidate_topic,
                    'context' => $candidate_context,
                    'metadata' => $candidate_metas,
                    'best_score' => $get_best_metadata_score($candidate_metas),
                    'context_length' => strlen($candidate_context),
                ];
            }

            $metadata_merged = [];
            if (!empty($dual_topic_results)) {
                usort($dual_topic_results, static function ($a, $b) {
                    $a_score = isset($a['best_score']) ? (float) $a['best_score'] : 0.0;
                    $b_score = isset($b['best_score']) ? (float) $b['best_score'] : 0.0;
                    if ($a_score === $b_score) {
                        $a_len = isset($a['context_length']) ? (int) $a['context_length'] : 0;
                        $b_len = isset($b['context_length']) ? (int) $b['context_length'] : 0;
                        if ($a_len === $b_len) {
                            return 0;
                        }
                        return ($a_len < $b_len) ? 1 : -1;
                    }
                    return ($a_score < $b_score) ? 1 : -1;
                });

                $primary_result = $dual_topic_results[0];
                $secondary_result = $dual_topic_results[1] ?? null;
                $primary_score = (float) ($primary_result['best_score'] ?? 0.0);
                $secondary_score = is_array($secondary_result) ? (float) ($secondary_result['best_score'] ?? 0.0) : 0.0;
                $score_gap = $primary_score - $secondary_score;

                // Vermijd ruis: combineer enkel beide flows als de signalen echt dicht bij elkaar liggen.
                $should_merge_topics = is_array($secondary_result) && $secondary_score > 0.0 && $score_gap <= 2.2;
                $selected_topic_results = $should_merge_topics
                    ? [$primary_result, $secondary_result]
                    : [$primary_result];

                if ($retrieval_topic === '' || $primary_score > 0.0) {
                    $retrieval_topic = sanitize_key((string) ($primary_result['topic'] ?? ''));
                }

                $include_topic_labels = count($selected_topic_results) > 1;
                $context_parts = [];
                foreach ($selected_topic_results as $topic_result) {
                    if (!is_array($topic_result)) {
                        continue;
                    }

                    $topic_context = trim((string) ($topic_result['context'] ?? ''));
                    $topic_key = sanitize_key((string) ($topic_result['topic'] ?? ''));
                    if ($topic_context !== '') {
                        if ($include_topic_labels && $topic_key !== '') {
                            $context_parts[] = '[' . octopus_ai_get_topic_label($topic_key, $lang) . "]\n" . $topic_context;
                        } else {
                            $context_parts[] = $topic_context;
                        }
                    }

                    $topic_metadata = isset($topic_result['metadata']) && is_array($topic_result['metadata'])
                        ? $topic_result['metadata']
                        : [];
                    foreach ($topic_metadata as $topic_meta_row) {
                        if (is_array($topic_meta_row)) {
                            $metadata_merged[] = $topic_meta_row;
                        }
                    }
                }

                if (!empty($context_parts)) {
                    $context = implode("\n\n", $context_parts);
                    if (function_exists('mb_substr')) {
                        $context = (string) mb_substr($context, 0, 13000);
                    } else {
                        $context = (string) substr($context, 0, 13000);
                    }
                }
            }

            if (empty($context) && empty($metadata_merged)) {
                $fallback_result = octopus_ai_retrieve_relevant_chunks($retrieval_query, '');
                $context = (string) ($fallback_result['context'] ?? '');
                $metadata_merged = $extract_metadata_chunks($fallback_result);
            }

            $metadata_chunks = $metadata_merged;
            $metadata_chunks_for_live = $metadata_merged;

            if (!empty($context) && strlen($context) > 20) {
                $relevantFound = true;
            }
        } elseif ($use_local_chunks) {
            $result = octopus_ai_retrieve_relevant_chunks($retrieval_query, $effective_topic);
            $context = $result['context'] ?? '';
            $metadata_chunks = $extract_metadata_chunks($result);
            $metadata_chunks_for_live = $metadata_chunks;

            if (!empty($context) && strlen($context) > 20) {
                $relevantFound = true;
            }
        } else {
            if ($effective_topic === '') {
                $dual_topics = $allowed_topics;
                if ($retrieval_topic !== '') {
                    $dual_topics = array_values(array_unique(array_merge([$retrieval_topic], $dual_topics)));
                }
                $topic_metadata_rows = [];
                foreach ($dual_topics as $candidate_topic) {
                    $candidate_result = octopus_ai_retrieve_relevant_chunks($retrieval_query, $candidate_topic);
                    $candidate_metas = [];
                    foreach ($extract_metadata_chunks($candidate_result) as $candidate_meta) {
                        if (!is_array($candidate_meta)) {
                            continue;
                        }
                        $candidate_meta['topic'] = $candidate_topic;
                        $candidate_metas[] = $candidate_meta;
                    }
                    if (empty($candidate_metas)) {
                        continue;
                    }
                    $topic_metadata_rows[] = [
                        'topic' => $candidate_topic,
                        'metadata' => $candidate_metas,
                        'best_score' => $get_best_metadata_score($candidate_metas),
                    ];
                }

                $metadata_merged = [];
                if (!empty($topic_metadata_rows)) {
                    usort($topic_metadata_rows, static function ($a, $b) {
                        $a_score = isset($a['best_score']) ? (float) $a['best_score'] : 0.0;
                        $b_score = isset($b['best_score']) ? (float) $b['best_score'] : 0.0;
                        if ($a_score === $b_score) {
                            $a_count = isset($a['metadata']) && is_array($a['metadata']) ? count($a['metadata']) : 0;
                            $b_count = isset($b['metadata']) && is_array($b['metadata']) ? count($b['metadata']) : 0;
                            if ($a_count === $b_count) {
                                return 0;
                            }
                            return ($a_count < $b_count) ? 1 : -1;
                        }
                        return ($a_score < $b_score) ? 1 : -1;
                    });

                    $primary_row = $topic_metadata_rows[0];
                    $secondary_row = $topic_metadata_rows[1] ?? null;
                    $primary_score = (float) ($primary_row['best_score'] ?? 0.0);
                    $secondary_score = is_array($secondary_row) ? (float) ($secondary_row['best_score'] ?? 0.0) : 0.0;
                    $score_gap = $primary_score - $secondary_score;

                    // Voor live-context selectie enkel beide topics als signalen dicht bij elkaar liggen.
                    $selected_rows = [$primary_row];
                    if (is_array($secondary_row) && $secondary_score > 0.0 && $score_gap <= 1.8) {
                        $selected_rows[] = $secondary_row;
                    }

                    if ($retrieval_topic === '' || $primary_score > 0.0) {
                        $retrieval_topic = sanitize_key((string) ($primary_row['topic'] ?? ''));
                    }

                    foreach ($selected_rows as $selected_row) {
                        if (!is_array($selected_row)) {
                            continue;
                        }
                        $selected_metadata = isset($selected_row['metadata']) && is_array($selected_row['metadata'])
                            ? $selected_row['metadata']
                            : [];
                        foreach ($selected_metadata as $selected_meta_item) {
                            if (is_array($selected_meta_item)) {
                                $metadata_merged[] = $selected_meta_item;
                            }
                        }
                    }
                }

                if (empty($metadata_merged)) {
                    $fallback_result = octopus_ai_retrieve_relevant_chunks($retrieval_query, '');
                    $metadata_merged = $extract_metadata_chunks($fallback_result);
                }
                $metadata_chunks_for_live = $metadata_merged;
            } else {
                $result = octopus_ai_retrieve_relevant_chunks($retrieval_query, $effective_topic);
                $metadata_chunks_for_live = $extract_metadata_chunks($result);
            }

            if ($source_strategy !== 'live_manual') {
                $metadata_chunks = $metadata_chunks_for_live;
            }
        }
    }

    if ($use_live_manual) {
        $live_manual = octopus_ai_fetch_live_manual_context($metadata_chunks_for_live, $lang, $retrieval_query);
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

    // ÃƒÆ’Ã‚Â¢Ãƒâ€šÃ‚ÂÃƒâ€¦Ã¢â‚¬â„¢ Als er geen relevante context gevonden werd, geef fallback met zoeklink terug
    if (!$relevantFound) {
        $fallback_topic = $effective_topic !== '' ? $effective_topic : $retrieval_topic;
        $fallback_reference_candidates = function_exists('octopus_ai_select_topic_reference_links')
            ? octopus_ai_select_topic_reference_links($reference_query, $fallback_topic, $lang, 3)
            : [];

        $is_ambiguous_question = false;
        $ambiguous_analysis = function_exists('octopus_ai_get_topic_match_analysis')
            ? octopus_ai_get_topic_match_analysis($reference_query)
            : [];
        if (
            $effective_topic === '' &&
            !octopus_ai_is_short_follow_up_message($message) &&
            is_array($ambiguous_analysis) &&
            !empty($ambiguous_analysis['is_ambiguous'])
        ) {
            $is_ambiguous_question = true;
        }

        if ($is_ambiguous_question) {
            $clarify_label_1 = isset($allowed_topics[0]) ? octopus_ai_get_topic_label($allowed_topics[0], $lang) : '';
            $clarify_label_2 = isset($allowed_topics[1]) ? octopus_ai_get_topic_label($allowed_topics[1], $lang) : '';
            if ($clarify_label_1 !== '' && $clarify_label_2 !== '') {
                $clarify_text = ($lang === 'FR')
                    ? sprintf('Ta question peut concerner %s ou %s. Choisis le flux souhaite pour une reponse exacte, ou precise ton contexte.', $clarify_label_1, $clarify_label_2)
                    : sprintf('Je vraag kan over %s of %s gaan. Kies de gewenste flow voor een exact antwoord, of specificeer je context.', $clarify_label_1, $clarify_label_2);
            } else {
                $clarify_text = ($lang === 'FR')
                    ? "Ta question peut concerner plusieurs flux. Choisis le flux souhaite pour une reponse exacte, ou precise ton contexte."
                    : 'Je vraag kan over meerdere flows gaan. Kies de gewenste flow voor een exact antwoord, of specificeer je context.';
            }
            $clarify_text = octopus_ai_apply_language_glossary($clarify_text, $lang);

            $clarify_candidates = [];
            if (function_exists('octopus_ai_select_topic_reference_links')) {
                foreach (array_slice($allowed_topics, 0, 2) as $candidate_topic) {
                    $candidate_topic = sanitize_key((string) $candidate_topic);
                    if ($candidate_topic === '') {
                        continue;
                    }
                    $clarify_candidates = array_merge(
                        $clarify_candidates,
                        octopus_ai_select_topic_reference_links($reference_query, $candidate_topic, $lang, 2)
                    );
                }
            }
            $clarify_links = function_exists('octopus_ai_select_top_reference_links')
                ? octopus_ai_select_top_reference_links($clarify_candidates, $lang, 3)
                : [];

            if (!empty($clarify_links)) {
                $heading = ($lang === 'FR') ? 'Liens utiles' : 'Handige links';
                $clarify_text .= "\n\n" . $heading . ":\n";
                foreach ($clarify_links as $candidate) {
                    if (!is_array($candidate)) {
                        continue;
                    }
                    $candidate_title = sanitize_text_field((string) ($candidate['title'] ?? ''));
                    $candidate_url = esc_url_raw((string) ($candidate['url'] ?? ''));
                    if ($candidate_url === '') {
                        continue;
                    }
                    if ($candidate_title === '') {
                        $candidate_title = ($lang === 'FR') ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding';
                    }
                    $clarify_text .= '- [' . $candidate_title . '](' . $candidate_url . ')' . "\n";
                }
                $clarify_text = rtrim((string) $clarify_text);
            }

            if ($topic_mismatch_notice !== '') {
                $clarify_text = rtrim((string) $clarify_text) . "\n\n" . $topic_mismatch_notice;
            }

            return rest_ensure_response([
                'answer' => octopus_ai_sanitize_answer_output($clarify_text),
                'chat_id' => 0,
                'status' => 'needs_flow_clarification',
                'confidence' => 0.0,
                'reference_links' => $clarify_links,
                'suggested_topic' => $topic_mismatch,
                'current_topic' => $selected_topic,
                'primary_source_url' => (
                    is_array($clarify_links) &&
                    isset($clarify_links[0]['url']) &&
                    is_string($clarify_links[0]['url'])
                ) ? esc_url_raw((string) $clarify_links[0]['url']) : '',
            ]);
        }

        $fallback_answer = octopus_ai_build_no_solution_answer(
            $lang,
            $reference_query,
            $fallback,
            [
                'references' => $fallback_reference_candidates,
                'handoff_url' => function_exists('octopus_ai_get_handoff_url') ? octopus_ai_get_handoff_url($lang) : '',
            ]
        );
        if ($topic_mismatch_notice !== '') {
            $fallback_answer = rtrim((string) $fallback_answer) . "\n\n" . $topic_mismatch_notice;
        }

        $fallback_selected_links = function_exists('octopus_ai_select_top_reference_links')
            ? octopus_ai_select_top_reference_links($fallback_reference_candidates, $lang, 3)
            : [];
        return rest_ensure_response([
            'answer' => $fallback_answer,
            'chat_id' => 0,
            'status' => 'fallback',
            'confidence' => 0.0,
            'reference_links' => $fallback_selected_links,
            'suggested_topic' => $topic_mismatch,
            'current_topic' => $selected_topic,
            'primary_source_url' => (
                is_array($fallback_selected_links) &&
                isset($fallback_selected_links[0]['url']) &&
                is_string($fallback_selected_links[0]['url'])
            ) ? esc_url_raw((string) $fallback_selected_links[0]['url']) : '',
        ]);
    }


    // ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã‚Â¾ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ Prompt opbouwen
    $system_prompt = $tone;

    $topic_scope_instruction = '';
    if ($effective_topic !== '') {
        $active_topic_label = octopus_ai_get_topic_label($effective_topic, $lang);
        $active_topic_description = '';
        if (function_exists('octopus_ai_get_provider_topic_description')) {
            $active_topic_description = octopus_ai_get_provider_topic_description($effective_topic, $lang, '');
        }

        if ($active_topic_description !== '') {
            $topic_scope_instruction = ($lang === 'FR')
                ? "Flux actif: {$active_topic_label}. Portee du flux: {$active_topic_description}. Reponds prioritairement dans ce cadre. Si la question concerne clairement un autre flux, propose de changer."
                : "Actieve flow: {$active_topic_label}. Scope van deze flow: {$active_topic_description}. Beantwoord prioritair binnen deze scope. Als de vraag duidelijk over een andere flow gaat, stel dan voor om te wisselen.";
        } else {
            $topic_scope_instruction = ($lang === 'FR')
                ? "Flux actif: {$active_topic_label}. Reponds prioritairement dans le cadre de ce flux. Si la question concerne clairement un autre flux, propose de changer."
                : "Actieve flow: {$active_topic_label}. Beantwoord prioritair binnen deze flow. Als de vraag duidelijk over een andere flow gaat, stel dan voor om te wisselen.";
        }
    } else {
        $available_topic_labels = [];
        foreach (array_slice($allowed_topics, 0, 3) as $candidate_topic) {
            $candidate_topic = sanitize_key((string) $candidate_topic);
            if ($candidate_topic === '') {
                continue;
            }
            $available_topic_labels[] = octopus_ai_get_topic_label($candidate_topic, $lang);
        }
        $available_topic_labels = array_values(array_filter(array_unique($available_topic_labels)));
        $available_topic_text = implode(' / ', $available_topic_labels);

        $topic_scope_instruction = ($lang === 'FR')
            ? (
                $available_topic_text !== ''
                    ? "Aucun flux fixe selectionne. Recherche dans les flux suivants: {$available_topic_text}. Donne directement la reponse la plus pertinente. Demande un choix de flux uniquement si plusieurs flux semblent aussi pertinents."
                    : "Aucun flux fixe selectionne. Donne directement la reponse la plus pertinente et demande un choix de flux uniquement si plusieurs flux semblent aussi pertinents."
            )
            : (
                $available_topic_text !== ''
                    ? "Geen vaste flow geselecteerd. Zoek in de volgende flows: {$available_topic_text}. Geef meteen het meest passende inhoudelijke antwoord. Vraag alleen om een flowkeuze als meerdere flows even relevant lijken."
                    : "Geen vaste flow geselecteerd. Geef meteen het meest passende inhoudelijke antwoord en vraag alleen om een flowkeuze als meerdere flows even relevant lijken."
            );
        if ($retrieval_topic !== '') {
            $retrieval_label = octopus_ai_get_topic_label($retrieval_topic, $lang);
            $topic_scope_instruction .= ($lang === 'FR')
                ? " La question semble surtout concerner {$retrieval_label}; privilegie d'abord ce flux, puis ajoute l'autre uniquement si utile."
                : " De vraag lijkt vooral over {$retrieval_label} te gaan; prioritiseer eerst die flow en gebruik de andere alleen indien nodig.";
        }
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
        ? "Regle de fiabilite: n'invente rien. Si la solution n'est pas explicitement presente dans le contexte fourni, donne l'etape la plus plausible basee sur le contexte disponible et pose une courte question de clarification. Utilise la reponse stricte suivante uniquement en dernier recours: \"" . $strict_no_solution . "\""
        : "Betrouwbaarheidsregel: verzin niets. Als de oplossing niet expliciet in de beschikbare context staat, geef de meest waarschijnlijke vervolgstap op basis van de beschikbare context en stel een korte verduidelijkingsvraag. Gebruik volgend strikt antwoord alleen als laatste redmiddel: \"" . $strict_no_solution . "\"";
    $system_prompt .= "\n\n" . $strict_rule;
    $system_prompt .= "\n\nOpmerking:\nAls de gebruiker bevestigt dat hij verder geholpen wil worden (bijv. zegt 'ja'), geef dan een inhoudelijk vervolg op het onderwerp, niet een algemene begroeting of herstart.";
    if (!empty($effective_query_data['used_history']) && !empty($effective_query_data['previous_user_message'])) {
        $previous_user_message = sanitize_textarea_field((string) $effective_query_data['previous_user_message']);
        $system_prompt .= ($lang === 'FR')
            ? ("\n\nInterpretation du message court de l'utilisateur:\n- Question precedente: " . $previous_user_message . "\n- Traite ce nouveau message comme une suite contextuelle de cette question.")
            : ("\n\nInterpretatie van kort gebruikersbericht:\n- Vorige vraag: " . $previous_user_message . "\n- Behandel het nieuwe bericht als contextueel vervolg op die vraag.");
    }

    // ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾ Links toevoegen
    $validLinkFound      = false;
    $primary_doc_url     = '';
    $best_metadata_link  = '';
    $best_metadata_score = -1.0;
    $best_live_link      = '';
    $best_live_score     = -1.0;
    $best_topic_link     = '';
    $best_topic_score    = -1.0;

    $topic_for_reference = $effective_topic !== '' ? $effective_topic : $retrieval_topic;
    $topic_reference_links = function_exists('octopus_ai_select_topic_reference_links')
        ? octopus_ai_select_topic_reference_links($reference_query, $topic_for_reference, $lang, 5)
        : [];

    if (!empty($topic_reference_links)) {
        $system_prompt .= "\n\nGecross-referencede topic-links (handleiding):\n";
        foreach ($topic_reference_links as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidate_url = esc_url_raw((string) ($candidate['url'] ?? ''));
            if ($candidate_url === '') {
                continue;
            }

            $candidate_title = sanitize_text_field((string) ($candidate['title'] ?? ''));
            $candidate_score = isset($candidate['score']) ? (float) $candidate['score'] : 0.0;
            $candidate_label = $candidate_title !== ''
                ? $candidate_title
                : (($lang === 'FR') ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding');

            $system_prompt .= "- [{$candidate_label}]({$candidate_url})\n";

            if ($candidate_score > $best_topic_score) {
                $best_topic_score = $candidate_score;
                $best_topic_link = $candidate_url;
            }

            $reference_candidates[] = [
                'title' => $candidate_label,
                'url' => $candidate_url,
                'score' => $candidate_score + 0.4,
            ];
            $validLinkFound = true;
        }
    }

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
                $candidate_score = function_exists('octopus_ai_score_reference_candidate')
                    ? octopus_ai_score_reference_candidate(
                        $reference_query,
                        $topic_for_reference,
                        $title,
                        $slug,
                        $candidate_url,
                        $current_score,
                        $lang
                    )
                    : $current_score;

                $possible_links[] = [
                    'url'       => $candidate_url,
                    'is_manual' => $is_manual_path,
                    'is_valid'  => true,
                    'score'     => $candidate_score,
                ];
            }

            if (!empty($possible_links)) {
                if ($title !== '') {
                    $system_prompt .= "- *{$title}*\n";
                }
                foreach ($possible_links as $link_info) {
                    $link  = $link_info['url'];
                    $score = isset($link_info['score']) ? (float) $link_info['score'] : 0.0;
                    $label = ($lang === 'FR') ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding';
                    if ($link && !$link_info['is_manual']) {
                        $label = ($lang === 'FR') ? 'Voir la source' : 'Bekijk de bron';
                    }

                    if ($title !== '') {
                        $system_prompt .= "  [{$label}]({$link})\n";
                    } else {
                        $system_prompt .= "- [{$label}]({$link})\n";
                    }

                    if ($link_info['is_valid']) {
                        if (
                            $score > $best_metadata_score ||
                            ($score === $best_metadata_score && $best_metadata_link === '')
                        ) {
                            $best_metadata_score = $score;
                            $best_metadata_link  = $link;
                        }
                        $validLinkFound = true;

                        $reference_candidates[] = [
                            'title' => function_exists('octopus_ai_build_reference_title')
                                ? octopus_ai_build_reference_title($title, $slug, $link, $lang)
                                : ($title !== '' ? $title : (($lang === 'FR') ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding')),
                            'url'   => $link,
                            'score' => $score,
                        ];
                    }
                }
            }
        }
    }

    if (!empty($live_sources)) {
        $system_prompt .= "\n\nLive bronnen:\n";
        foreach ($live_sources as $index => $link) {
            $link = esc_url_raw((string) $link);
            if ($link === '') {
                continue;
            }

            $label = ($lang === 'FR') ? 'Voir dans le manuel' : 'Bekijk dit in de handleiding';
            if (strpos($link, 'octopus.be/manual') === false) {
                $label = ($lang === 'FR') ? 'Voir la source' : 'Bekijk de bron';
            }
            $system_prompt .= "- [{$label}]({$link})\n";

            $base_live_score = max(0.1, $live_best_score - ($index * 0.1));
            $live_link_score = function_exists('octopus_ai_score_reference_candidate')
                ? octopus_ai_score_reference_candidate(
                    $reference_query,
                    $topic_for_reference,
                    '',
                    '',
                    $link,
                    $base_live_score,
                    $lang
                )
                : $base_live_score;

            if ($live_link_score > $best_live_score) {
                $best_live_score = $live_link_score;
                $best_live_link = $link;
            }

            $reference_candidates[] = [
                'title' => ($lang === 'FR') ? 'Page du manuel' : 'Handleidingpagina',
                'url'   => $link,
                'score' => $live_link_score,
            ];
            $validLinkFound = true;
        }
    }

    if (!empty($live_best_source)) {
        $live_best_source = esc_url_raw((string) $live_best_source);
        if ($live_best_source !== '') {
            $candidate_live_best_score = function_exists('octopus_ai_score_reference_candidate')
                ? octopus_ai_score_reference_candidate(
                    $reference_query,
                    $topic_for_reference,
                    '',
                    '',
                    $live_best_source,
                    max($live_best_score, 0.1),
                    $lang
                )
                : max($live_best_score, 0.1);

            if ($candidate_live_best_score > $best_live_score) {
                $best_live_score = $candidate_live_best_score;
                $best_live_link = $live_best_source;
            }
        }
    }

    $preferred_doc_url = '';
    $preferred_doc_score = -1000.0;
    $preferred_candidates = [
        [
            'url' => $best_topic_link,
            'score' => $best_topic_score,
        ],
        [
            'url' => $best_live_link,
            'score' => $best_live_score,
        ],
        [
            'url' => $best_metadata_link,
            'score' => $best_metadata_score,
        ],
    ];

    if (!empty($live_best_source)) {
        $preferred_candidates[] = [
            'url' => esc_url_raw((string) $live_best_source),
            'score' => max(0.1, $best_live_score),
        ];
    }

    foreach ($preferred_candidates as $candidate) {
        $candidate_url = esc_url_raw((string) ($candidate['url'] ?? ''));
        $candidate_score = isset($candidate['score']) ? (float) $candidate['score'] : -1000.0;
        if ($candidate_url === '') {
            continue;
        }

        if ($candidate_score > $preferred_doc_score) {
            $preferred_doc_score = $candidate_score;
            $preferred_doc_url = $candidate_url;
        }
    }

    if ($preferred_doc_url !== '') {
        $primary_doc_url = $preferred_doc_url;
        $validLinkFound  = true;
    }

    if ($primary_doc_url === '' && !empty($live_sources)) {
        $primary_doc_url = esc_url_raw((string) $live_sources[0]);
        if ($primary_doc_url !== '') {
            $validLinkFound = true;
        }
    }

    if ($primary_doc_url !== '') {
        $reference_candidates[] = [
            'title' => ($lang === 'FR') ? 'Page du manuel' : 'Handleidingpagina',
            'url'   => $primary_doc_url,
            'score' => max($best_topic_score, $best_metadata_score, $best_live_score, 0.1),
        ];
    }

    // ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã‚Â¾ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ Opbouw history
    $selected_reference_links = function_exists('octopus_ai_select_top_reference_links')
        ? octopus_ai_select_top_reference_links($reference_candidates, $lang, 3)
        : [];
    $confidence_score = function_exists('octopus_ai_calculate_answer_confidence')
        ? octopus_ai_calculate_answer_confidence([
            'context' => $context,
            'live_context' => $live_context,
            'metadata_chunks' => $metadata_chunks,
            'best_metadata_score' => $best_metadata_score,
            'best_live_score' => $live_best_score,
            'reference_count' => count($selected_reference_links),
            'topic_selected' => ($effective_topic !== '' || $retrieval_topic !== ''),
        ])
        : 1.0;
    $confidence_threshold = function_exists('octopus_ai_get_confidence_threshold')
        ? (float) octopus_ai_get_confidence_threshold()
        : 0.55;

    if ($confidence_score < $confidence_threshold) {
        $low_confidence_answer = octopus_ai_build_no_solution_answer(
            $lang,
            $reference_query,
            $fallback,
            [
                'references' => $selected_reference_links,
                'handoff_url' => function_exists('octopus_ai_get_handoff_url') ? octopus_ai_get_handoff_url($lang) : '',
            ]
        );
        if ($topic_mismatch_notice !== '') {
            $low_confidence_answer = rtrim((string) $low_confidence_answer) . "\n\n" . $topic_mismatch_notice;
        }

        if (!function_exists('octopus_ai_log_interaction')) {
            require_once plugin_dir_path(__FILE__) . 'logger.php';
        }

        $chat_id = 0;
        if (function_exists('octopus_ai_log_interaction')) {
            $context_length = strlen($context) + strlen($live_context);
            $error_message = wp_json_encode([
                'reason' => 'low_confidence_fallback',
                'confidence_score' => round($confidence_score, 4),
                'confidence_threshold' => round($confidence_threshold, 4),
            ]);
            $chat_id = (int) octopus_ai_log_interaction($message, $low_confidence_answer, $context_length, 'fail', (string) $error_message);
        }

        return rest_ensure_response([
            'answer' => $low_confidence_answer,
            'chat_id' => $chat_id,
            'status' => 'low_confidence_fallback',
            'confidence' => round($confidence_score, 3),
            'reference_links' => $selected_reference_links,
            'suggested_topic' => $topic_mismatch,
            'current_topic' => $selected_topic,
            'primary_source_url' => (
                is_array($selected_reference_links) &&
                isset($selected_reference_links[0]['url']) &&
                is_string($selected_reference_links[0]['url'])
            ) ? esc_url_raw((string) $selected_reference_links[0]['url']) : '',
        ]);
    }

    $build_service_fallback_response = static function ($reason = '') use (
        $lang,
        $message,
        $reference_query,
        $selected_reference_links,
        $topic_mismatch_notice,
        $topic_mismatch,
        $selected_topic,
        $context,
        $live_context,
        $confidence_score
    ) {
        $service_notice = ($lang === 'FR')
            ? "Je ne peux temporairement pas generer une reponse detaillee. Voici les liens les plus pertinents."
            : 'Ik kan tijdelijk geen gedetailleerd antwoord genereren. Hieronder staan de meest relevante links.';

        $fallback_answer = octopus_ai_build_no_solution_answer(
            $lang,
            $reference_query !== '' ? $reference_query : $message,
            '',
            [
                'references' => $selected_reference_links,
                'handoff_url' => function_exists('octopus_ai_get_handoff_url') ? octopus_ai_get_handoff_url($lang) : '',
            ]
        );

        if ($fallback_answer !== '') {
            $fallback_answer = $service_notice . "\n\n" . ltrim((string) $fallback_answer);
        } else {
            $fallback_answer = $service_notice;
        }

        if ($topic_mismatch_notice !== '') {
            $fallback_answer = rtrim((string) $fallback_answer) . "\n\n" . $topic_mismatch_notice;
        }

        $fallback_answer = octopus_ai_sanitize_answer_output($fallback_answer);

        if (!function_exists('octopus_ai_log_interaction')) {
            require_once plugin_dir_path(__FILE__) . 'logger.php';
        }

        $chat_id = 0;
        if (function_exists('octopus_ai_log_interaction')) {
            $context_length = strlen((string) $context) + strlen((string) $live_context);
            $error_message = wp_json_encode([
                'reason' => 'openai_service_fallback',
                'detail' => sanitize_text_field((string) $reason),
            ]);
            $chat_id = (int) octopus_ai_log_interaction($message, $fallback_answer, $context_length, 'fail', (string) $error_message);
        }

        return rest_ensure_response([
            'answer' => $fallback_answer,
            'chat_id' => $chat_id,
            'status' => 'service_fallback',
            'confidence' => round((float) $confidence_score, 3),
            'reference_links' => is_array($selected_reference_links) ? $selected_reference_links : [],
            'suggested_topic' => $topic_mismatch,
            'current_topic' => $selected_topic,
            'primary_source_url' => (
                is_array($selected_reference_links) &&
                isset($selected_reference_links[0]['url']) &&
                is_string($selected_reference_links[0]['url'])
            ) ? esc_url_raw((string) $selected_reference_links[0]['url']) : '',
        ]);
    };

    $messages = [['role' => 'system', 'content' => $system_prompt]];
    $current_message_present = false;
    foreach ($history as $entry) {
        if (isset($entry['content'])) {
            $entry_role = isset($entry['role']) ? strtolower(trim((string) $entry['role'])) : 'user';
            if ($entry_role === 'bot') {
                $entry_role = 'assistant';
            }
            if (!in_array($entry_role, ['user', 'assistant'], true)) {
                continue;
            }

            $content = sanitize_textarea_field((string) $entry['content']);
            if ($content === '') {
                continue;
            }

            $messages[] = [
                'role'    => $entry_role,
                'content' => $content,
            ];

            if ($entry_role === 'user' && $content === $message) {
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

    // ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ API request met retry/backoff + circuit breaker
    $openai_result = octopus_ai_openai_chat_completion_with_retry($api_key, $messages, $model, 3);
    if (is_wp_error($openai_result)) {
        $service_reason = $openai_result->get_error_message();
        error_log('[Octopus AI] OpenAI call mislukt, service fallback actief: ' . $service_reason);
        return $build_service_fallback_response($service_reason);
    }

    $body_json = (string) ($openai_result['body_json'] ?? '');
    $body = isset($openai_result['body']) && is_array($openai_result['body']) ? $openai_result['body'] : [];
    // ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€šÃ‚Â§Ãƒâ€šÃ‚Â  AI-antwoord verwerken
    $answer = $body['choices'][0]['message']['content'] ?? '';

    if (!$answer) {
        $error_message = $body['error']['message'] ?? 'Ongeldige API-respons.';
        error_log('[Octopus AI] OpenAI gaf lege/ongeldige response, service fallback actief: ' . $error_message);
        return $build_service_fallback_response((string) $error_message);
    }

    // ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Unicode-decodering via JSON (zoals \u00e9 ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©)
    $decoded_json = json_decode('"' . addcslashes($answer, "\\\"\/\n\r\t") . '"');
    if (is_string($decoded_json)) {
        $answer = $decoded_json;
    }

    // ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Unicode-decoding voor uXXXX of \uXXXX (fallback)
    $answer = preg_replace_callback('/\\\\?u([0-9a-fA-F]{4})/', function ($matches) {
        $hex = $matches[1];
        $bin = pack('H*', $hex);
        return function_exists('octopus_ai_utf16be_to_utf8')
            ? octopus_ai_utf16be_to_utf8($bin)
            : '';
    }, $answer);

// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ EÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©n keer UTF-8 normaliseren
$answer = function_exists('octopus_ai_normalize_utf8')
    ? octopus_ai_normalize_utf8($answer)
    : (string) $answer;

// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Dubbele slashes en quotes strippen
$answer = stripslashes($answer);

// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Decodeer HTML entities (zoals &eacute; ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©)
$answer = html_entity_decode($answer, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Decodeer wp-specialchars (zoals &#039; ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ ')
$answer = wp_specialchars_decode($answer, ENT_QUOTES);


// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Emoji verwijderen (blacklist)
$emoji_blacklist = [
    'ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒâ€¦Ã‚Â½','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€šÃ‚Â§Ãƒâ€šÃ‚Â¾','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€¦Ã¢â‚¬â„¢','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€šÃ‚Â§Ãƒâ€šÃ‚Â ','ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã‚Â¡ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€¦Ã‚Â¡ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢Ãƒâ€šÃ‚Â¬','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€¦Ã‚Â½Ãƒâ€šÃ‚Â¯','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒâ€šÃ‚Â£ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒâ€šÃ‚Â½','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒâ€šÃ‚Â¼','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€¦Ã‚Â ',
    'ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€šÃ‚Â§Ãƒâ€šÃ‚Âª','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢Ãƒâ€šÃ‚Â¡','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â','ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦','ÃƒÆ’Ã‚Â¢Ãƒâ€šÃ‚ÂÃƒâ€¦Ã¢â‚¬â„¢','ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã‚Â¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¹ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€šÃ‚Â§Ãƒâ€šÃ‚Â¨','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€šÃ‚Â¦','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€šÃ‚Â¬','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€šÃ‚Â¥','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€šÃ‚Â¤','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€šÃ‚Â',
    'ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€¹Ã¢â‚¬Â ','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÂ¢Ã¢â€šÂ¬Ã‚Â°','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹','ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â°ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢Ãƒâ€šÃ‚Â»','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€šÃ‚Â±','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“Ãƒâ€šÃ‚Â¥ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€¦Ã‚Â½','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“Ãƒâ€¦Ã‚Â ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â€šÂ¬Ã…â€œ',
    'ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂºÃƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€šÃ‚ÂªÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€šÃ‚Â§Ãƒâ€šÃ‚Â¹','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸Ãƒâ€šÃ‚ÂªÃƒâ€šÃ‚Âª','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒÂ¢Ã¢â€šÂ¬Ã‹Å“ÃƒÆ’Ã‚Â¯Ãƒâ€šÃ‚Â¸Ãƒâ€šÃ‚Â','ÃƒÆ’Ã‚Â¢Ãƒâ€šÃ‚ÂÃƒâ€šÃ‚Â³','ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬â„¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚Âº','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒâ€šÃ‚Â§','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‹Å“Ãƒâ€¦Ã‚Â½','ÃƒÆ’Ã‚Â°Ãƒâ€¦Ã‚Â¸ÃƒÂ¢Ã¢â€šÂ¬Ã‹Å“Ãƒâ€šÃ‚Â'
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

// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Dode links naar de handleiding weghalen (optioneel: kan zwaar zijn als er veel zijn)
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
    $answer = octopus_ai_build_no_solution_answer($lang, $reference_query, $fallback);
}

$has_manual_link = octopus_ai_answer_contains_allowed_manual_link($answer, $lang);

// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Fallback-zoeklink als geen geldige link gevonden is
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
    $keyword = octopus_ai_extract_keyword($reference_query);
    if ($keyword) {
        $zoeklink = function_exists('octopus_ai_get_manual_search_url')
            ? octopus_ai_get_manual_search_url($lang, $keyword)
            : octopus_ai_get_manual_search_fallback_url($lang, $keyword);

        if (empty(trim($answer))) {
            $answer = octopus_ai_get_no_solution_message($lang, $fallback);
        }

        $label = ($lang === 'FR') ? 'Voir aussi dans la documentation' : 'Bekijk mogelijke info in de handleiding';

        // ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â°ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¸ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â§ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¹ Verwijder eventuele losse fallback-tekst zonder link om dubbels te vermijden
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

// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Voeg lijst met top 3 referentielinks toe
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

if ($topic_mismatch_notice !== '') {
    $answer = rtrim((string) $answer) . "\n\n" . $topic_mismatch_notice;
}

$answer = octopus_ai_sanitize_answer_output($answer);

if (!function_exists('octopus_ai_log_interaction')) {
    require_once plugin_dir_path(__FILE__) . 'logger.php';

}

// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Bepaal status
$is_fallback = stripos($answer, $fallback) !== false || strlen(trim($answer)) < 10;

$status = $is_fallback ? 'fail' : 'success';

// ÃƒÆ’Ã‚Â¢Ãƒâ€¦Ã¢â‚¬Å“ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Logging uitvoeren
$chat_id = 0;
if (function_exists('octopus_ai_log_interaction')) {
    $context_length = strlen($context) + strlen($live_context);
    $error_message  = $status === 'fail' ? json_encode($body) : '';
    $chat_id = (int) octopus_ai_log_interaction($message, $answer, $context_length, $status, $error_message);
}

if (function_exists('delete_transient')) {
    delete_transient('octopus_ai_last_fatal_error');
}

return rest_ensure_response([
    'answer' => $answer,
    'chat_id' => $chat_id,
    'status' => $status,
    'confidence' => round((float) $confidence_score, 3),
    'reference_links' => is_array($selected_reference_links) ? $selected_reference_links : [],
    'suggested_topic' => $topic_mismatch,
    'current_topic' => $selected_topic,
    'primary_source_url' => $primary_doc_url !== ''
        ? esc_url_raw((string) $primary_doc_url)
        : (
            is_array($selected_reference_links) &&
            isset($selected_reference_links[0]['url']) &&
            is_string($selected_reference_links[0]['url'])
                ? esc_url_raw((string) $selected_reference_links[0]['url'])
                : ''
        ),
]);

    } catch (Throwable $exception) {
        error_log('[Octopus AI] Onverwachte chatbot runtime-fout: ' . $exception->getMessage());

        $runtime_lang = function_exists('octopus_ai_get_request_language')
            ? octopus_ai_get_request_language()
            : 'NL';
        $runtime_message = '';
        if (is_object($request) && method_exists($request, 'get_param')) {
            $runtime_message = sanitize_text_field((string) $request->get_param('message'));
        }

        $runtime_fallback_default = ($runtime_lang === 'FR')
            ? "Desole, je ne peux pas t'aider avec ca."
            : get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.');
        $runtime_fallback = function_exists('octopus_ai_get_provider_fallback_text')
            ? octopus_ai_get_provider_fallback_text($runtime_lang, $runtime_fallback_default)
            : $runtime_fallback_default;
        $runtime_notice = ($runtime_lang === 'FR')
            ? "Une erreur technique s'est produite. Voici les liens utiles disponibles."
            : 'Er is een technische fout opgetreden. Hieronder staan nuttige beschikbare links.';

        if (function_exists('octopus_ai_build_no_solution_answer')) {
            $runtime_answer = octopus_ai_build_no_solution_answer(
                $runtime_lang,
                $runtime_message,
                $runtime_fallback,
                [
                    'references' => [],
                    'handoff_url' => function_exists('octopus_ai_get_handoff_url') ? octopus_ai_get_handoff_url($runtime_lang) : '',
                ]
            );
        } else {
            $runtime_answer = $runtime_fallback;
        }

        $runtime_answer = $runtime_notice . "\n\n" . ltrim((string) $runtime_answer);
        if (function_exists('octopus_ai_sanitize_answer_output')) {
            $runtime_answer = octopus_ai_sanitize_answer_output($runtime_answer);
        }

        return rest_ensure_response([
            'answer' => (string) $runtime_answer,
            'chat_id' => 0,
            'status' => 'runtime_fallback',
            'confidence' => 0.0,
            'reference_links' => [],
            'suggested_topic' => '',
            'current_topic' => '',
            'primary_source_url' => '',
        ]);
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

