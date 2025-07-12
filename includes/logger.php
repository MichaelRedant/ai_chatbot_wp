<?php
if (!defined('ABSPATH')) exit;

function octopus_ai_log_interaction($question, $answer, $context_length, $status, $error_message = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'octopus_ai_logs';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $wpdb->insert($table_name, [
        'question' => $question,
        'answer' => $answer,
        'context_length' => intval($context_length),
        'status' => $status,
        'error_message' => $error_message,
        'ip_address' => $ip_address
    ]);
}
