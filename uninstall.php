<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$cleanup_file = __DIR__ . '/includes/cleanup.php';
if (file_exists($cleanup_file)) {
    require_once $cleanup_file;
}

if (function_exists('octopus_ai_cleanup_plugin_data')) {
    octopus_ai_cleanup_plugin_data(array(
        'drop_log_table' => true,
        'delete_upload_dirs' => true,
        'network_wide' => is_multisite(),
    ));
}
