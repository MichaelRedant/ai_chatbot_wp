<?php
if (!defined('ABSPATH')) exit;

function octopus_ai_logs_page_callback() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'octopus_ai_logs';

    // ✅ Verwijder alle logs indien gevraagd
    if (isset($_POST['octopus_ai_delete_logs']) && check_admin_referer('octopus_ai_delete_logs_nonce')) {
        $wpdb->query("TRUNCATE TABLE {$table_name}");
        echo '<div class="updated"><p>Alle logs zijn verwijderd.</p></div>';
    }

    // ✅ Filters ophalen
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $date_from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
    $date_to = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : '';

    $where = [];
    $params = [];

    if ($search) {
        $where[] = "(vraag LIKE %s OR antwoord LIKE %s)";
        $params[] = '%' . $wpdb->esc_like($search) . '%';
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }
    if ($status_filter && in_array($status_filter, ['success', 'fail'])) {
        $where[] = "status = %s";
        $params[] = $status_filter;
    }
    if ($date_from && $date_to) {
        $where[] = "datum BETWEEN %s AND %s";
        $params[] = $date_from . ' 00:00:00';
        $params[] = $date_to . ' 23:59:59';
    }

    $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // ✅ Logs ophalen (paginatie)
    $per_page = 20;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;

    $query = "SELECT * FROM {$table_name} {$where_clause} ORDER BY datum DESC LIMIT %d OFFSET %d";
$params[] = $per_page;
$params[] = $offset;
$logs = $wpdb->get_results($wpdb->prepare($query, ...$params));

    $count_params = $params;
array_pop($count_params); 
array_pop($count_params);
$total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} {$where_clause}", ...$count_params));

    $total_pages = ceil($total_items / $per_page);

    // ✅ Statistieken ophalen
    $total_questions = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    $error_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'fail'");
    $success_count = $total_questions - $error_count;
    $avg_context = $wpdb->get_var("SELECT ROUND(AVG(context_lengte), 1) FROM {$table_name}");
    $feedback_up = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE feedback = 'up'");
    $feedback_down = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE feedback = 'down'");

    $top_ips = $wpdb->get_results("SELECT ip_address, COUNT(*) as count FROM {$table_name} GROUP BY ip_address ORDER BY count DESC LIMIT 5");
    $top_questions = $wpdb->get_results("SELECT vraag, COUNT(*) as count FROM {$table_name} GROUP BY vraag ORDER BY count DESC LIMIT 5");

    ?>
    <div class="wrap">
        <h1>📊 Octopus AI Logging Dashboard</h1>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo admin_url('admin.php?page=octopus-ai-chatbot'); ?>" class="nav-tab">Instellingen</a>
            <a href="<?php echo admin_url('admin.php?page=octopus-ai-chatbot-logs'); ?>" class="nav-tab nav-tab-active">Logging</a>
        </h2>

        <form method="get" style="margin-bottom: 15px;">
            <input type="hidden" name="page" value="octopus-ai-chatbot-logs" />
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Zoek in vragen of antwoorden..." style="width: 240px;" />
            <select name="status">
                <option value="">Alle statussen</option>
                <option value="success" <?php selected($status_filter, 'success'); ?>>✅ Success</option>
                <option value="fail" <?php selected($status_filter, 'fail'); ?>>❌ Fail</option>
            </select>
            <input type="date" name="from" value="<?php echo esc_attr($date_from); ?>" />
            <input type="date" name="to" value="<?php echo esc_attr($date_to); ?>" />
            <?php submit_button('Filteren', '', '', false); ?>
            <?php if ($search || $status_filter || $date_from || $date_to): ?>
                <a href="<?php echo admin_url('admin.php?page=octopus-ai-chatbot-logs'); ?>" class="button">Reset</a>
            <?php endif; ?>
        </form>

        <form method="post" onsubmit="return confirm('Weet je zeker dat je alle logs wilt verwijderen?');" style="margin-bottom: 20px;">
            <?php wp_nonce_field('octopus_ai_delete_logs_nonce'); ?>
            <input type="hidden" name="octopus_ai_delete_logs" value="1">
            <?php submit_button('🗑️ Verwijder alle logs', 'delete'); ?>
        </form>

        <table class="widefat striped" style="margin-bottom: 20px;">
            <thead><tr><th>📈 Statistiek</th><th>Waarde</th></tr></thead>
            <tbody>
                <tr><td>✅ Succesvolle antwoorden</td><td><?php echo $success_count; ?> / <?php echo $total_questions; ?></td></tr>
                <tr><td>❌ Fallbacks of fouten</td><td><?php echo $error_count; ?> / <?php echo $total_questions; ?></td></tr>
                <tr><td>📐 Gemiddelde contextlengte</td><td><?php echo $avg_context ?: 'nvt'; ?> tekens</td></tr>
                <tr><td>👍 Positieve feedback</td><td><?php echo $feedback_up; ?></td></tr>
                <tr><td>👎 Negatieve feedback</td><td><?php echo $feedback_down; ?></td></tr>
            </tbody>
        </table>

        <h3>💬 Meest gestelde vragen</h3>
        <table class="widefat fixed">
            <thead><tr><th>Vraag</th><th>Frequentie</th></tr></thead>
            <tbody>
                <?php foreach ($top_questions as $q): ?>
                    <tr><td><?php echo esc_html(wp_trim_words($q->vraag, 12, '...')); ?></td><td><?php echo $q->count; ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3>🌍 Meest actieve IP-adressen</h3>
        <table class="widefat fixed" style="margin-bottom: 30px;">
            <thead><tr><th>IP-adres</th><th>Aantal vragen</th></tr></thead>
            <tbody>
                <?php foreach ($top_ips as $ip): ?>
                    <tr><td><?php echo esc_html($ip->ip_address); ?></td><td><?php echo $ip->count; ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>📋 Gedetailleerde logs</h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>📅 Datum</th>
                    <th>❓ Vraag</th>
                    <th>💬 Antwoord</th>
                    <th>📏 Context</th>
                    <th>🚦 Status</th>
                    <th>📍 IP</th>
                    <th>📝 Feedback</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->datum); ?></td>
                            <td><?php echo esc_html(wp_trim_words($log->vraag, 10)); ?></td>
                            <td><?php echo esc_html(wp_trim_words($log->antwoord, 15)); ?></td>
                            <td><?php echo intval($log->context_lengte); ?> tekens</td>
                            <td><?php echo $log->status === 'success' ? '✅' : '❌'; ?></td>
                            <td><?php echo esc_html($log->ip_address); ?></td>
                            <td><?php echo $log->feedback === 'up' ? '👍' : ($log->feedback === 'down' ? '👎' : ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7">Geen logs gevonden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $total_pages,
                'current' => $paged,
                'add_args' => array_filter([
                    's' => $search,
                    'status' => $status_filter,
                    'from' => $date_from,
                    'to' => $date_to
                ])
            ));
            echo '</div></div>';
        }
        ?>
    </div>
    <?php
}
