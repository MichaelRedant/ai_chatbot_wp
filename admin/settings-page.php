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

add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'toplevel_page_octopus-ai-chatbot') {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('octopus-ai-admin-color-picker', plugin_dir_url(__FILE__) . '../assets/js/admin-color-picker.js', array('wp-color-picker'), false, true);
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
 * Verwerk PDF upload via admin_post
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

    $uploaded_file = $_FILES['octopus_ai_pdf_upload'];
    if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
        wp_redirect(add_query_arg('upload', 'error', admin_url('admin.php?page=octopus-ai-chatbot')));
        exit;
    }

    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    if (!file_exists($upload_path)) {
        wp_mkdir_p($upload_path);
    }

    $filename = sanitize_file_name($uploaded_file['name']);
    $file_path = $upload_path . $filename;

    if (move_uploaded_file($uploaded_file['tmp_name'], $file_path)) {
        require_once plugin_dir_path(__FILE__) . '../includes/pdf-parser.php';
require_once plugin_dir_path(__FILE__) . '../includes/pdf-chunker.php';
$parsed_text = octopus_parse_pdf_to_text($file_path);

// Chunk en sla op
$chunks_created = octopus_chunk_pdf_text($parsed_text);

file_put_contents($upload_path . 'handleiding.txt', $parsed_text);

echo '<div style="padding:10px;background:#d4edda;color:#155724;border-left:4px solid #28a745;margin-top:15px;">
' . $chunks_created . ' chunks succesvol aangemaakt voor context search.
</div>';

        wp_redirect(add_query_arg('upload', 'success', admin_url('admin.php?page=octopus-ai-chatbot')));
        exit;
    } else {
        wp_redirect(add_query_arg('upload', 'error', admin_url('admin.php?page=octopus-ai-chatbot')));
        exit;
    }
}

/**
 * Verwerk verwijderen van bestanden
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
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    $filename = basename($_GET['file']);
    $file_path = $upload_path . $filename;

    if (file_exists($file_path)) {
        unlink($file_path);
        wp_redirect(add_query_arg('delete', 'success', admin_url('admin.php?page=octopus-ai-chatbot')));
        exit;
    } else {
        wp_redirect(add_query_arg('delete', 'error', admin_url('admin.php?page=octopus-ai-chatbot')));
        exit;
    }
}

/**
 * Instellingenpagina HTML
 */
function octopus_ai_settings_page() {
    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    $upload_url = trailingslashit($upload_dir['baseurl']) . 'octopus-chatbot/';
    ?>
    <div class="wrap">
        <h1>Octopus AI Chatbot Instellingen</h1>

        <?php if (isset($_GET['upload'])): ?>
            <?php if ($_GET['upload'] === 'success'): ?>
                <div style="padding:10px;background:#d4edda;color:#155724;border-left:4px solid #28a745;margin-bottom:15px;">
                    PDF succesvol geüpload en verwerkt.
                </div>
            <?php elseif ($_GET['upload'] === 'error'): ?>
                <div style="padding:10px;background:#f8d7da;color:#721c24;border-left:4px solid #dc3545;margin-bottom:15px;">
                    Er ging iets mis bij het uploaden van de PDF.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($_GET['delete'])): ?>
            <?php if ($_GET['delete'] === 'success'): ?>
                <div style="padding:10px;background:#d4edda;color:#155724;border-left:4px solid #28a745;margin-bottom:15px;">
                    Bestand succesvol verwijderd.
                </div>
            <?php elseif ($_GET['delete'] === 'error'): ?>
                <div style="padding:10px;background:#f8d7da;color:#721c24;border-left:4px solid #dc3545;margin-bottom:15px;">
                    Er ging iets mis bij het verwijderen van het bestand.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('octopus_ai_settings_group'); ?>
            <?php do_settings_sections('octopus_ai_settings_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">OpenAI API Key</th>
                    <td>
                        <input type="text" name="octopus_ai_api_key" value="<?php echo esc_attr(get_option('octopus_ai_api_key')); ?>" style="width: 400px;" />
                        <p class="description">Voer hier je OpenAI API key in.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Tone of Voice</th>
                    <td>
                        <textarea name="octopus_ai_tone" rows="4" style="width: 400px;"><?php echo esc_textarea(get_option('octopus_ai_tone', 'Je bent de Octopus Chatbot. Beantwoord vriendelijk en kort vragen over Octopus.')); ?></textarea>
                        <p class="description">Bepaal de toon waarop de chatbot antwoordt.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Fallback tekst</th>
                    <td>
                        <input type="text" name="octopus_ai_fallback" value="<?php echo esc_attr(get_option('octopus_ai_fallback', 'Sorry, daar kan ik je niet mee helpen.')); ?>" style="width: 400px;" />
                        <p class="description">Antwoord wanneer de chatbot geen passend antwoord vindt.</p>
                    </td>
                </tr>
                <tr valign="top">
    <th scope="row">Primaire kleur</th>
    <td>
        <input type="text" name="octopus_ai_primary_color" value="<?php echo esc_attr(get_option('octopus_ai_primary_color', '#0f6c95')); ?>" class="wp-color-picker-field" data-default-color="#0f6c95" style="width: 100px;" />
        <p class="description">Kies de primaire kleur voor de chatbot (bijvoorbeeld: #0f6c95).</p>
    </td>
</tr>
<tr valign="top">
    <th scope="row">Verwelkomingstekst</th>
    <td>
        <textarea name="octopus_ai_welcome_message" rows="2" style="width: 400px;"><?php echo esc_textarea(get_option('octopus_ai_welcome_message', '👋 Hallo! Hoe kan ik je vandaag helpen?')); ?></textarea>
        <p class="description">Tekst die de chatbot automatisch toont wanneer de gebruiker het venster opent.</p>
    </td>
</tr>


<tr valign="top">
    <th scope="row">Merknaam</th>
    <td>
        <input type="text" name="octopus_ai_brand_name" value="<?php echo esc_attr(get_option('octopus_ai_brand_name', 'AI Chatbot')); ?>" style="width: 400px;" />
        <p class="description">Naam die in de chatbot header wordt getoond.</p>
    </td>
</tr>
<tr valign="top">
    <th scope="row">Chatbot Logo</th>
    <td>
        <?php $logo_url = get_option('octopus_ai_logo_url', 'https://www.octopus.be/wp-content/uploads/2025/04/web-app-manifest-512x512-1.webp'); ?>
        <img id="octopus-ai-logo-preview" src="<?php echo esc_url($logo_url); ?>" alt="Logo preview" style="max-width: 100px; display: block; margin-bottom: 10px;">
        <input type="url" id="octopus-ai-logo-url" name="octopus_ai_logo_url" value="<?php echo esc_attr($logo_url); ?>" style="width: 400px;" readonly />
        <br>
        <button type="button" class="button" id="octopus-ai-upload-logo-button">Upload / Selecteer logo</button>
        <p class="description">Selecteer of upload een logo dat op de toggle knop van de chatbot verschijnt.</p>
    </td>
</tr>

<tr valign="top">
    <th scope="row">Chatbot weergave</th>
    <td>
        <select name="octopus_ai_display_mode" id="octopus_ai_display_mode">
            <?php $mode = get_option('octopus_ai_display_mode', 'all'); ?>
            <option value="all" <?php selected($mode, 'all'); ?>>Op alle pagina's tonen</option>
            <option value="selected" <?php selected($mode, 'selected'); ?>>Alleen op geselecteerde pagina's tonen</option>
        </select>
        <p class="description">Bepaal waar de chatbot zichtbaar is.</p>
    </td>
</tr>

<tr valign="top" id="octopus_ai_page_selector_row" style="<?php echo ($mode === 'selected') ? '' : 'display:none;'; ?>">
    <th scope="row">Selecteer pagina's</th>
    <td>
        <?php
        $selected_pages = get_option('octopus_ai_selected_pages', array());
        $pages = get_pages();
        ?>
        <select name="octopus_ai_selected_pages[]" multiple="multiple" style="width: 400px;">
            <?php foreach ($pages as $page): ?>
                <option value="<?php echo esc_attr($page->ID); ?>" <?php selected(in_array($page->ID, $selected_pages)); ?>>
                    <?php echo esc_html($page->post_title); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">Kies op welke pagina's de chatbot zichtbaar moet zijn.</p>
    </td>
</tr>


            </table>
            <?php submit_button('Instellingen opslaan'); ?>
        </form>

        <hr style="margin: 40px 0;">

        <h2>Upload Handleiding PDF</h2>
        <p>Upload een PDF-handleiding die de chatbot als kennisbron gebruikt.</p>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('octopus_ai_upload_pdf', 'octopus_ai_pdf_nonce'); ?>
            <input type="hidden" name="action" value="octopus_ai_pdf_upload">
            <input type="file" name="octopus_ai_pdf_upload" accept="application/pdf" required style="margin-bottom:10px;"><br>
            <?php submit_button('Upload PDF'); ?>
        </form>

        <hr style="margin: 40px 0;">

        <h2>Geüploade Bestanden</h2>
        <?php
        if (file_exists($upload_path)) {
            $files = glob($upload_path . '*');
            if ($files) {
                echo '<ul>';
                foreach ($files as $file) {
                    $filename = basename($file);
                    $delete_url = wp_nonce_url(
                        admin_url('admin-post.php?action=octopus_ai_delete_file&file=' . urlencode($filename)),
                        'octopus_ai_delete_file'
                    );
                    echo '<li>';
                    echo '<a href="' . esc_url($upload_url . $filename) . '" target="_blank">' . esc_html($filename) . '</a> ';
                    echo '<a href="' . esc_url($delete_url) . '" style="color:red;margin-left:10px;" onclick="return confirm(\'Weet je zeker dat je dit bestand wilt verwijderen?\');">Verwijderen</a>';
                    echo '</li>';
                }
                echo '</ul>';
            } else {
                echo '<p>Er zijn nog geen bestanden geüpload.</p>';
            }
        } else {
            echo '<p>Er zijn nog geen bestanden geüpload.</p>';
        }
        ?>
        <hr style="margin: 40px 0;">

<hr style="margin: 40px 0;">

<div style="background: #ffffff; border: 1px solid #ddd; padding: 20px; border-radius: 8px; max-width: 600px; margin: 0 auto; text-align: center;">
    

    <h2 style="margin: 0; color: #0f6c95;">Ontwikkeld door <strong>Michaël Redant</strong></h2>

    <p style="font-size: 14px; line-height: 1.6; max-width: 500px; margin: 10px auto;">
        Deze plugin werd ontwikkeld en wordt onderhouden door <strong>Xinudesign</strong>, jouw partner in AI-oplossingen en WordPress maatwerk. Beheer je AI efficiënter met <a href="https://www.xinudesign.be/vault/" target="_blank">Persona Vault</a>: een slimme tool om persona's voor jouw AI centraal te bewaren en hergebruiken.
    </p>

    <div style="margin-top: 15px;">
        <a href="https://xinudesign.be" target="_blank" style="background: #0f6c95; color: #fff; padding: 8px 14px; text-decoration: none; border-radius: 4px; margin: 5px; display: inline-block;">🌐 Bezoek Xinudesign</a>
        <a href="https://www.xinudesign.be/vault/" target="_blank" style="background: #0f6c95; color: #fff; padding: 8px 14px; text-decoration: none; border-radius: 4px; margin: 5px; display: inline-block;">🧩 Persona Vault</a>
        <a href="https://x3dprints.be" target="_blank" style="background: #333; color: #fff; padding: 8px 14px; text-decoration: none; border-radius: 4px; margin: 5px; display: inline-block;">🖨️ X3DPrints</a>
    </div>

    <p style="font-size: 13px; color: #555; margin-top: 15px;">
        📧 Contact: <a href="mailto:michael@xinudesign.be">michael@xinudesign.be</a>
    </p>
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
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'toplevel_page_octopus-ai-chatbot') {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }
});
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'toplevel_page_octopus-ai-chatbot') {
        wp_enqueue_media();
        wp_enqueue_script('octopus-ai-admin-media', plugin_dir_url(__FILE__) . '../assets/js/admin-media-uploader.js', array('jquery'), '1.0', true);
    }
});

?>
