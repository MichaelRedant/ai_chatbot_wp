<?php
if (!defined('ABSPATH')) exit;

function octopus_ai_log_interaction($vraag, $antwoord, $context_lengte, $status, $foutmelding = '') {
    global $wpdb;

    $table = $wpdb->prefix . 'octopus_ai_logs';

    $wpdb->insert($table, [
        'vraag'          => sanitize_text_field($vraag),
        'antwoord'       => wp_strip_all_tags($antwoord),
        'context_lengte' => intval($context_lengte),
        'status'         => sanitize_text_field($status),
        'foutmelding'    => sanitize_text_field($foutmelding),
        'datum'          => current_time('mysql'),
    ]);
}
