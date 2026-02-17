<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('octopus_ai_recursive_delete_path')) {
    function octopus_ai_recursive_delete_path($path)
    {
        $path = (string) $path;
        if ($path === '') {
            return false;
        }

        if (!file_exists($path)) {
            return true;
        }

        if (is_file($path) || is_link($path)) {
            return @unlink($path);
        }

        if (!is_dir($path)) {
            return false;
        }

        $entries = @scandir($path);
        if (!is_array($entries)) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $entry;
            octopus_ai_recursive_delete_path($child);
        }

        return @rmdir($path);
    }
}

if (!function_exists('octopus_ai_clear_background_jobs')) {
    function octopus_ai_clear_background_jobs()
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('octopus_ai_process_pdf_queue');
            wp_clear_scheduled_hook('octopus_ai_process_sitemap_queue');
        }

        if (function_exists('delete_transient')) {
            delete_transient('octopus_ai_pdf_queue_lock');
            delete_transient('octopus_ai_sitemap_queue_lock');
        }
    }
}

if (!function_exists('octopus_ai_cleanup_current_site_data')) {
    function octopus_ai_cleanup_current_site_data($drop_log_table = true, $delete_upload_dirs = true)
    {
        global $wpdb;

        $result = array(
            'options_deleted' => 0,
            'log_table_dropped' => false,
            'upload_dirs_deleted' => 0,
            'upload_dirs_failed' => array(),
        );

        if (isset($wpdb) && isset($wpdb->options)) {
            $option_patterns = array(
                'octopus_ai_%',
                '_transient_octopus_ai_%',
                '_transient_timeout_octopus_ai_%',
            );

            foreach ($option_patterns as $pattern) {
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                        $pattern
                    )
                );

                if (is_int($deleted) && $deleted > 0) {
                    $result['options_deleted'] += (int) $deleted;
                }
            }
        }

        if ($drop_log_table && isset($wpdb) && isset($wpdb->prefix)) {
            $table_name = $wpdb->prefix . 'octopus_ai_logs';
            $dropped = $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
            $result['log_table_dropped'] = $dropped !== false;
        }

        if ($delete_upload_dirs && function_exists('wp_upload_dir')) {
            $upload_dir = wp_upload_dir();
            $base_dir = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';

            if ($base_dir !== '' && is_dir($base_dir)) {
                $target_dirs = array(
                    trailingslashit($base_dir) . 'octopus-ai-chunks',
                    trailingslashit($base_dir) . 'octopus-chatbot',
                );

                foreach ($target_dirs as $target_dir) {
                    if (!file_exists($target_dir)) {
                        continue;
                    }

                    $deleted = octopus_ai_recursive_delete_path($target_dir);
                    if ($deleted) {
                        $result['upload_dirs_deleted']++;
                    } else {
                        $result['upload_dirs_failed'][] = $target_dir;
                    }
                }
            }
        }

        return $result;
    }
}

if (!function_exists('octopus_ai_cleanup_plugin_data')) {
    function octopus_ai_cleanup_plugin_data($args = array())
    {
        $defaults = array(
            'drop_log_table' => true,
            'delete_upload_dirs' => true,
            'network_wide' => false,
        );
        $args = function_exists('wp_parse_args') ? wp_parse_args($args, $defaults) : array_merge($defaults, (array) $args);

        $drop_log_table = (bool) $args['drop_log_table'];
        $delete_upload_dirs = (bool) $args['delete_upload_dirs'];
        $network_wide = (bool) $args['network_wide'];

        $summary = array(
            'sites_cleaned' => 0,
            'options_deleted' => 0,
            'log_tables_dropped' => 0,
            'upload_dirs_deleted' => 0,
            'upload_dirs_failed' => array(),
        );

        $cleanup_site = static function () use (&$summary, $drop_log_table, $delete_upload_dirs) {
            octopus_ai_clear_background_jobs();
            $site_result = octopus_ai_cleanup_current_site_data($drop_log_table, $delete_upload_dirs);
            $summary['sites_cleaned']++;
            $summary['options_deleted'] += (int) ($site_result['options_deleted'] ?? 0);
            if (!empty($site_result['log_table_dropped'])) {
                $summary['log_tables_dropped']++;
            }
            $summary['upload_dirs_deleted'] += (int) ($site_result['upload_dirs_deleted'] ?? 0);
            if (!empty($site_result['upload_dirs_failed']) && is_array($site_result['upload_dirs_failed'])) {
                $summary['upload_dirs_failed'] = array_merge($summary['upload_dirs_failed'], $site_result['upload_dirs_failed']);
            }
        };

        if ($network_wide && is_multisite() && function_exists('get_sites') && function_exists('switch_to_blog') && function_exists('restore_current_blog')) {
            $site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
            if (is_array($site_ids) && !empty($site_ids)) {
                foreach ($site_ids as $site_id) {
                    switch_to_blog((int) $site_id);
                    $cleanup_site();
                    restore_current_blog();
                }
            } else {
                $cleanup_site();
            }
        } else {
            $cleanup_site();
        }

        $summary['upload_dirs_failed'] = array_values(array_unique(array_map('strval', $summary['upload_dirs_failed'])));
        return $summary;
    }
}
