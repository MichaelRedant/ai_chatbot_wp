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
<?php
// Statistieken ophalen
$total_questions = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
$error_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'fail'");
$success_count = $total_questions - $error_count;
$avg_context = $wpdb->get_var("SELECT ROUND(AVG(context_length), 1) FROM {$table_name}");

// Topvragen ophalen
$top_questions = $wpdb->get_results("
    SELECT question, COUNT(*) as count 
    FROM {$table_name} 
    GROUP BY question 
    ORDER BY count DESC 
    LIMIT 5
");

// Bereken max count voor grafische schaal
$max_count = 0;
foreach ($top_questions as $q) {
    if ($q->count > $max_count) $max_count = $q->count;
}
?>

<style>
.stat-bar {
    height: 12px;
    background-color: #4caf50;
    border-radius: 4px;
}
.stat-bar.error { background-color: #e53935; }
.stat-bar-wrapper {
    width: 100%;
    background: #e0e0e0;
    border-radius: 4px;
    margin: 4px 0;
    height: 12px;
}
</style>

<div style="margin-top: 20px; background: #f8f9fa; border: 1px solid #ccd0d4; padding: 20px; border-radius: 8px;">
    <h2 style="margin-top: 0;">📊 Chatbot Statistieken</h2>

    <table class="widefat fixed striped" style="margin-bottom: 20px;">
        <thead>
            <tr>
                <th>Metric</th>
                <th>Waarde</th>
                <th>Visualisatie</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>✅ Succesvolle antwoorden</td>
                <td><?php echo $success_count; ?> / <?php echo $total_questions; ?></td>
                <td>
                    <div class="stat-bar-wrapper">
                        <div class="stat-bar" style="width: <?php echo ($total_questions ? ($success_count / $total_questions * 100) : 0); ?>%;"></div>
                    </div>
                </td>
            </tr>
            <tr>
                <td>❌ Foutmeldingen</td>
                <td><?php echo $error_count; ?> / <?php echo $total_questions; ?></td>
                <td>
                    <div class="stat-bar-wrapper">
                        <div class="stat-bar error" style="width: <?php echo ($total_questions ? ($error_count / $total_questions * 100) : 0); ?>%;"></div>
                    </div>
                </td>
            </tr>
            <tr>
                <td>📐 Gemiddelde contextlengte</td>
                <td><?php echo $avg_context ? $avg_context . ' tekens' : 'nvt'; ?></td>
                <td>
                    <div class="stat-bar-wrapper">
                        <div class="stat-bar" style="width: <?php echo min(($avg_context / 2000) * 100, 100); ?>%;"></div>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>

    <h3>💬 Meest gestelde vragen</h3>
    <table class="widefat fixed">
        <thead>
            <tr><th>Vraag</th><th>Frequentie</th><th></th></tr>
        </thead>
        <tbody>
            <?php if ($top_questions): ?>
                <?php foreach ($top_questions as $q): ?>
                    <tr>
                        <td><?php echo esc_html(wp_trim_words($q->question, 12, '...')); ?></td>
                        <td><?php echo $q->count; ?></td>
                        <td>
                            <div class="stat-bar-wrapper">
                                <div class="stat-bar" style="width: <?php echo ($max_count ? ($q->count / $max_count * 100) : 0); ?>%;"></div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3">Nog geen herhaalde vragen gevonden.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

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
