<?php
// Veiligheid
if (!defined('ABSPATH')) exit;

/**
 * Voeg menu toe aan WordPress dashboard
 */
add_action('admin_menu', 'octopus_ai_add_admin_menu');
function octopus_ai_add_admin_menu() {
    add_menu_page(
        'Octopus AI Chatbot',
        'Octopus AI Chatbot',
        'manage_options',
        'octopus-ai-chatbot',
        'octopus_ai_settings_page',
        'dashicons-format-chat',
        26
    );
}

/**
 * Enqueue scripts en media uploader
 */
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'toplevel_page_octopus-ai-chatbot') {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();
        wp_enqueue_script('octopus-ai-admin-color-picker', plugin_dir_url(__FILE__) . '../assets/js/admin-color-picker.js', array('wp-color-picker'), false, true);
        wp_enqueue_script('octopus-ai-admin-media', plugin_dir_url(__FILE__) . '../assets/js/admin-media-uploader.js', array('jquery'), '1.0', true);
    }
});

/**
 * Registreer instellingen
 */
add_action('admin_init', 'octopus_ai_register_settings');
function octopus_ai_register_settings() {
    register_setting('octopus_ai_settings_group', 'octopus_ai_api_key', 'sanitize_text_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_tone', 'sanitize_textarea_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_fallback', 'sanitize_text_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_primary_color', 'sanitize_hex_color');
    register_setting('octopus_ai_settings_group', 'octopus_ai_brand_name', 'sanitize_text_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_logo_url', 'esc_url_raw');
    register_setting('octopus_ai_settings_group', 'octopus_ai_welcome_message', 'sanitize_textarea_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_display_mode', 'sanitize_text_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_selected_pages', function($value){
        return array_map('intval', (array) $value);
    });
}

/**
 * Verwerk PDF upload
 */
add_action('admin_post_octopus_ai_pdf_upload', 'octopus_ai_handle_pdf_upload');
function octopus_ai_handle_pdf_upload() {
    if (
        !current_user_can('manage_options') ||
        !isset($_FILES['octopus_ai_pdf_upload']) ||
        !isset($_POST['octopus_ai_pdf_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_pdf_nonce'], 'octopus_ai_upload_pdf')
    ) {
        wp_die('Beveiligingsfout bij upload.');
    }

    require_once plugin_dir_path(__FILE__) . '../includes/pdf-parser.php';
    require_once plugin_dir_path(__FILE__) . '../includes/pdf-chunker.php';

    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    if (!file_exists($upload_path)) wp_mkdir_p($upload_path);

    $files = $_FILES['octopus_ai_pdf_upload'];

    foreach ($files['name'] as $index => $name) {
        if ($files['error'][$index] === UPLOAD_ERR_OK) {
            $filename = sanitize_file_name($name);
            $filepath = $upload_path . $filename;

            if (move_uploaded_file($files['tmp_name'][$index], $filepath)) {
                $parsed_text = octopus_parse_pdf_to_text($filepath);
                octopus_chunk_pdf_text($parsed_text);
                file_put_contents($upload_path . 'handleiding.txt', $parsed_text);
            }
        }
    }

    wp_redirect(add_query_arg('upload', 'success', admin_url('admin.php?page=octopus-ai-chatbot')));
    exit;
}

/**
 * Verwijderen bestanden
 */
add_action('admin_post_octopus_ai_delete_file', 'octopus_ai_handle_delete_file');
function octopus_ai_handle_delete_file() {
    if (
        !current_user_can('manage_options') ||
        !isset($_GET['file']) ||
        !isset($_GET['_wpnonce']) ||
        !wp_verify_nonce($_GET['_wpnonce'], 'octopus_ai_delete_file')
    ) {
        wp_die('Beveiligingsfout bij verwijderen.');
    }

    $upload_dir = wp_upload_dir();
    $file_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/' . basename($_GET['file']);

    if (file_exists($file_path)) {
        unlink($file_path);
        wp_redirect(add_query_arg('delete', 'success', admin_url('admin.php?page=octopus-ai-chatbot')));
    } else {
        wp_redirect(add_query_arg('delete', 'error', admin_url('admin.php?page=octopus-ai-chatbot')));
    }
    exit;
}

add_action('admin_post_octopus_ai_bulk_delete', 'octopus_ai_handle_bulk_delete');
function octopus_ai_handle_bulk_delete() {
    if (
        !current_user_can('manage_options') ||
        !isset($_POST['octopus_ai_bulk_delete_nonce']) ||
        !wp_verify_nonce($_POST['octopus_ai_bulk_delete_nonce'], 'octopus_ai_bulk_delete')
    ) {
        wp_die('Beveiligingsfout bij bulk verwijderen.');
    }

    $files_to_delete = isset($_POST['octopus_ai_files']) ? (array) $_POST['octopus_ai_files'] : array();
    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';

    $deleted_count = 0;
    foreach ($files_to_delete as $filename) {
        $safe_name = basename($filename);
        $file_path = $upload_path . $safe_name;
        if (file_exists($file_path)) {
            unlink($file_path);
            $deleted_count++;
        }
    }

    wp_redirect(add_query_arg('bulk_delete', $deleted_count, admin_url('admin.php?page=octopus-ai-chatbot')));
    exit;
}


/**
 * Instellingenpagina HTML
 */
function octopus_ai_settings_page() {
    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    $upload_url = trailingslashit($upload_dir['baseurl']) . 'octopus-chatbot/';
    $mode = get_option('octopus_ai_display_mode', 'all');
    $selected_pages = get_option('octopus_ai_selected_pages', array());
    $pages = get_pages();
?>
<div class="wrap">
    <h1>Octopus AI Chatbot Instellingen</h1>

    <?php if (isset($_GET['upload']) && $_GET['upload'] === 'success'): ?>
        <div class="notice notice-success is-dismissible"><p>PDF's succesvol geüpload en verwerkt.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['delete']) && $_GET['delete'] === 'success'): ?>
        <div class="notice notice-success is-dismissible"><p>Bestand succesvol verwijderd.</p></div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields('octopus_ai_settings_group'); ?>
        <table class="form-table">
            <tr><th>OpenAI API Key</th><td><input type="text" name="octopus_ai_api_key" value="<?php echo esc_attr(get_option('octopus_ai_api_key')); ?>" style="width: 400px;" /></td></tr>
            <tr><th>Tone of Voice</th><td><textarea name="octopus_ai_tone" rows="3" style="width: 400px;"><?php echo esc_textarea(get_option('octopus_ai_tone')); ?></textarea></td></tr>
            <tr><th>Fallback tekst</th><td><input type="text" name="octopus_ai_fallback" value="<?php echo esc_attr(get_option('octopus_ai_fallback')); ?>" style="width: 400px;" /></td></tr>
            <tr><th>Primaire kleur</th><td><input type="text" name="octopus_ai_primary_color" class="wp-color-picker-field" data-default-color="#0f6c95" value="<?php echo esc_attr(get_option('octopus_ai_primary_color', '#0f6c95')); ?>" /></td></tr>
            <tr><th>Verwelkomingstekst</th><td><textarea name="octopus_ai_welcome_message" rows="2" style="width: 400px;"><?php echo esc_textarea(get_option('octopus_ai_welcome_message')); ?></textarea></td></tr>
            <tr><th>Merknaam</th><td><input type="text" name="octopus_ai_brand_name" value="<?php echo esc_attr(get_option('octopus_ai_brand_name')); ?>" style="width: 400px;" /></td></tr>
            <tr><th>Logo-URL</th><td>
                <?php $logo = get_option('octopus_ai_logo_url'); ?>
                <img src="<?php echo esc_url($logo); ?>" id="octopus-ai-logo-preview" style="max-width:100px;display:block;margin-bottom:10px;">
                <input type="url" name="octopus_ai_logo_url" id="octopus-ai-logo-url" value="<?php echo esc_attr($logo); ?>" style="width:400px;" readonly>
                <button type="button" class="button" id="octopus-ai-upload-logo-button">Upload / Selecteer logo</button>
            </td></tr>
            <tr><th>Chatbot weergave</th><td>
                <select name="octopus_ai_display_mode" id="octopus_ai_display_mode">
                    <option value="all" <?php selected($mode, 'all'); ?>>Op alle pagina's tonen</option>
                    <option value="selected" <?php selected($mode, 'selected'); ?>>Alleen op geselecteerde pagina's tonen</option>
                </select>
            </td></tr>
            <tr id="octopus_ai_page_selector_row" style="<?php echo ($mode === 'selected') ? '' : 'display:none'; ?>">
                <th>Selecteer pagina's</th>
                <td>
                    <select name="octopus_ai_selected_pages[]" multiple style="width: 400px;">
                        <?php foreach ($pages as $page): ?>
                            <option value="<?php echo esc_attr($page->ID); ?>" <?php selected(in_array($page->ID, $selected_pages)); ?>>
                                <?php echo esc_html($page->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
        <?php submit_button('Instellingen opslaan'); ?>
    </form>

    <hr>

    <h2>Upload PDF-handleiding(en)</h2>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('octopus_ai_upload_pdf', 'octopus_ai_pdf_nonce'); ?>
        <input type="hidden" name="action" value="octopus_ai_pdf_upload">
        <input type="file" name="octopus_ai_pdf_upload[]" accept="application/pdf" multiple required>
        <?php submit_button('Upload PDF'); ?>
    </form>

    <h2>Geüploade Bestanden</h2>

<?php if (isset($_GET['bulk_delete'])): ?>
    <div style="padding:10px;background:#d4edda;color:#155724;border-left:4px solid #28a745;margin-bottom:15px;">
        <?php echo intval($_GET['bulk_delete']); ?> bestand(en) succesvol verwijderd.
    </div>
<?php endif; ?>

<?php
if (file_exists($upload_path)) {
    $files = glob($upload_path . '*');
    if ($files) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('octopus_ai_bulk_delete', 'octopus_ai_bulk_delete_nonce');
        echo '<input type="hidden" name="action" value="octopus_ai_bulk_delete">';
        echo '<ul>';
        foreach ($files as $file) {
            $filename = basename($file);
            $delete_url = wp_nonce_url(
                admin_url('admin-post.php?action=octopus_ai_delete_file&file=' . urlencode($filename)),
                'octopus_ai_delete_file'
            );
            echo '<li>';
            echo '<label><input type="checkbox" name="octopus_ai_files[]" value="' . esc_attr($filename) . '"> ';
            echo '<a href="' . esc_url($upload_url . $filename) . '" target="_blank">' . esc_html($filename) . '</a></label> ';
            echo '<a href="' . esc_url($delete_url) . '" style="color:red;margin-left:10px;" onclick="return confirm(\'Weet je zeker dat je dit bestand wilt verwijderen?\');">Verwijderen</a>';
            echo '</li>';
        }
        echo '</ul>';
        echo '<p><input type="submit" class="button button-secondary" value="Geselecteerde bestanden verwijderen" onclick="return confirm(\'Weet je zeker dat je deze bestanden wilt verwijderen?\');"></p>';
        echo '</form>';
    } else {
        echo '<p>Er zijn nog geen bestanden geüpload.</p>';
    }
} else {
    echo '<p>Er zijn nog geen bestanden geüpload.</p>';
}
?>
    </ul>

    <hr>

    <div style="background:#fff;border:1px solid #ddd;padding:20px;border-radius:8px;max-width:600px;margin:0 auto;text-align:center;">
        <h2 style="margin:0;color:#0f6c95;">Ontwikkeld door <strong>Michaël Redant</strong></h2>
        <p style="font-size:14px;line-height:1.6;">Deze plugin werd ontwikkeld door <strong>Xinudesign</strong>, jouw partner in AI-oplossingen en WordPress maatwerk.
        Beheer persona’s slim via <a href="https://www.xinudesign.be/vault/" target="_blank">Persona Vault</a>.</p>
        <div style="margin-top:15px;">
            <a href="https://xinudesign.be" class="button button-primary" target="_blank">🌐 Xinudesign</a>
            <a href="https://xinudesign.be/vault/" class="button" target="_blank">🧩 Persona Vault</a>
            <a href="https://x3dprints.be" class="button" target="_blank">🖨️ X3DPrints</a>
        </div>
        <p style="font-size:13px;color:#555;margin-top:15px;">📧 Contact: <a href="mailto:michael@xinudesign.be">michael@xinudesign.be</a></p>
        <p style="font-size:11px;color:#999;">Powered by Xinudesign</p>
    </div>

    <script>
        document.getElementById('octopus_ai_display_mode').addEventListener('change', function() {
            const row = document.getElementById('octopus_ai_page_selector_row');
            row.style.display = this.value === 'selected' ? '' : 'none';
        });
    </script>
</div>
<?php
}
?>
