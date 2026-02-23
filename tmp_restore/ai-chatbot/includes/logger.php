<?php
if (!defined('ABSPATH')) exit;

function octopus_ai_log_interaction($vraag, $antwoord, $context_lengte, $status, $foutmelding = '', $feedback = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'octopus_ai_logs';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'onbekend';

    $inserted = $wpdb->insert($table, [
        'vraag'          => sanitize_text_field((string) $vraag),
        'antwoord'       => sanitize_textarea_field((string) $antwoord),
        'context_lengte' => intval($context_lengte),
        'status'         => sanitize_text_field((string) $status),
        'foutmelding'    => sanitize_textarea_field((string) $foutmelding),
        'feedback'       => $feedback !== null ? sanitize_text_field((string) $feedback) : null,
        'datum'          => current_time('mysql'),
        'ip_address'     => sanitize_text_field((string) $ip_address),
    ]);

    if ($inserted === false) {
        return 0;
    }

    return (int) $wpdb->insert_id;
}

