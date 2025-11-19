<?php
if (!defined('ABSPATH')) exit;

$upload_dir = wp_upload_dir();
$upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
$upload_url  = trailingslashit($upload_dir['baseurl'])  . 'octopus-chatbot/';

?>

<h2>📄 Upload PDF-handleiding(en)</h2>
<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
    <?php wp_nonce_field('octopus_ai_upload_pdf', 'octopus_ai_pdf_nonce'); ?>
    <input type="hidden" name="action" value="octopus_ai_pdf_upload">
    <input type="file" name="octopus_ai_pdf_upload[]" accept="application/pdf" multiple required>
    <?php submit_button('Upload PDF'); ?>
</form>

<hr>

<h2>🗂️ Geüploade Bestanden</h2>
<?php if (isset($_GET['bulk_delete'])): ?>
    <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['bulk_delete']); ?> bestand(en) succesvol verwijderd.</p></div>
<?php endif; ?>

<?php
if (file_exists($upload_path)) {
    $files = glob($upload_path . '*');
    if ($files): ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('octopus_ai_bulk_delete', 'octopus_ai_bulk_delete_nonce'); ?>
            <input type="hidden" name="action" value="octopus_ai_bulk_delete">
            <ul>
                <?php foreach ($files as $file): 
                    $filename = basename($file);
                    $delete_url = wp_nonce_url(
                        admin_url('admin-post.php?action=octopus_ai_delete_file&file=' . urlencode($filename)),
                        'octopus_ai_delete_file'
                    );
                ?>
                    <li>
                        <label>
                            <input type="checkbox" name="octopus_ai_files[]" value="<?php echo esc_attr($filename); ?>">
                            <a href="<?php echo esc_url($upload_url . $filename); ?>" target="_blank">
                                <?php echo esc_html($filename); ?>
                            </a>
                        </label>
                        <a href="<?php echo esc_url($delete_url); ?>" style="color:red;margin-left:10px;"
                           onclick="return confirm('Weet je zeker dat je dit bestand wilt verwijderen?');">
                            Verwijderen
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p>
                <input type="submit" class="button button-secondary"
                       value="Geselecteerde bestanden verwijderen"
                       onclick="return confirm('Weet je zeker dat je deze bestanden wilt verwijderen?');">
            </p>
        </form>
    <?php else: ?>
        <p><em>Er zijn nog geen bestanden geüpload.</em></p>
    <?php endif;
} else {
    echo '<p><em>Uploadmap bestaat niet.</em></p>';
}
?>
