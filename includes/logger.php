<?php
if (!defined('ABSPATH')) exit;

function octopus_ai_log_interaction($vraag, $antwoord, $context_lengte, $status, $foutmelding = '', $feedback = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'octopus_ai_logs';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'onbekend';

    $wpdb->insert($table, [
        'vraag'          => $vraag,
        'antwoord'       => $antwoord,
        'context_lengte' => intval($context_lengte),
        'status'         => $status,
        'foutmelding'    => $foutmelding,
        'feedback'       => $feedback,
        'datum'          => current_time('mysql'),
        'ip_address'     => $ip_address
    ]);
}

