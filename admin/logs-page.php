<?php
// Veiligheid
if (!defined('ABSPATH')) exit;

// Callback functie voor de logs pagina
function octopus_ai_logs_page_callback() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'octopus_ai_logs';

    // Verwijder alle logs indien gevraagd
    if (isset($_POST['octopus_ai_delete_logs']) && check_admin_referer('octopus_ai_delete_logs_nonce')) {
        $wpdb->query("TRUNCATE TABLE {$table_name}");
        echo '<div class="updated"><p>Alle logs zijn verwijderd.</p></div>';
    }

    // Zoekterm verwerken
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

    // Paginatie
    $per_page = 20;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;

    // Query bouwen
    $query = "SELECT * FROM {$table_name}";
    if ($search) {
        $query .= $wpdb->prepare(" WHERE question LIKE %s OR answer LIKE %s", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
    }
    $query .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $prepared_query = $wpdb->prepare($query, $per_page, $offset);
    $logs = $wpdb->get_results($prepared_query);

    // Totaal aantal voor paginatie
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}" . ($search ? $wpdb->prepare(" WHERE question LIKE %s OR answer LIKE %s", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%') : ""));
    $total_pages = ceil($total_items / $per_page);

    ?>
    <div class="wrap">
        <h1>Octopus AI Chatbot Logs</h1>

        <form method="get">
            <input type="hidden" name="page" value="octopus-ai-chatbot-logs" />
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Zoeken in vragen/antwoorden..." style="width: 300px;">
            <?php submit_button('Zoeken', '', '', false); ?>
            <?php if ($search): ?>
                <a href="<?php echo admin_url('admin.php?page=octopus-ai-chatbot-logs'); ?>" class="button">Reset</a>
            <?php endif; ?>
        </form>

        <form method="post" onsubmit="return confirm('Weet je zeker dat je alle logs wilt verwijderen?');" style="margin-top: 15px;">
            <?php wp_nonce_field('octopus_ai_delete_logs_nonce'); ?>
            <input type="hidden" name="octopus_ai_delete_logs" value="1">
            <?php submit_button('Verwijder alle logs', 'delete'); ?>
        </form>

        <table class="widefat fixed striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th width="15%">Datum</th>
                    <th width="25%">Vraag</th>
                    <th width="40%">Antwoord</th>
                    <th width="10%">Context</th>
                    <th width="10%">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->created_at); ?></td>
                            <td><?php echo esc_html(wp_trim_words($log->question, 10, '...')); ?></td>
                            <td><?php echo esc_html(wp_trim_words($log->answer, 15, '...')); ?></td>
                            <td><?php echo esc_html($log->context_length); ?> tekens</td>
                            <td><?php echo esc_html($log->status); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5">Nog geen logs gevonden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        // Paginatie links
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $total_pages,
                'current' => $paged,
                'add_args' => array('s' => $search)
            ));
            echo '</div></div>';
        }
        ?>
    </div>
    <?php
}
?>
