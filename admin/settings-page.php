<?php
// Veiligheid
if (!defined('ABSPATH')) exit;


use OctopusAI\Includes\Chunker;

// --- ADMIN MENU ---
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

// --- ADMIN SCRIPTS ---
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'toplevel_page_octopus-ai-chatbot') {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();
        wp_enqueue_script('octopus-ai-admin-color-picker', plugin_dir_url(__FILE__) . '../assets/js/admin-color-picker.js', array('wp-color-picker'), false, true);
        wp_enqueue_script('octopus-ai-admin-media', plugin_dir_url(__FILE__) . '../assets/js/admin-media-uploader.js', array('jquery'), '1.0', true);
    }
});

// --- INSTELLINGEN ---
add_action('admin_init', 'octopus_ai_register_settings');
function octopus_ai_register_settings() {
    register_setting('octopus_ai_settings_group', 'octopus_ai_model', 'sanitize_text_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_api_key', function($value) {
    $existing = get_option('octopus_ai_api_key');
    // Als het veld is gemaskeerd, wijzig dan niet
    if (strpos($value, '*****') !== false || empty(trim($value))) {
        return $existing;
    }
    return sanitize_text_field($value);
});
    register_setting('octopus_ai_settings_group', 'octopus_ai_tone', 'sanitize_textarea_field');
    register_setting('octopus_ai_settings_group', 'octopus_ai_header_text_color', 'sanitize_hex_color');
    register_setting('octopus_ai_settings_group', 'octopus_ai_test_mode', 'intval');
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

// --- PDF UPLOAD + CHUNKING ---
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
    $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
    if (!file_exists($upload_path)) wp_mkdir_p($upload_path);
    if (!file_exists($chunks_dir)) wp_mkdir_p($chunks_dir);

    $files = $_FILES['octopus_ai_pdf_upload'];

    foreach ($files['name'] as $index => $name) {
        if ($files['error'][$index] === UPLOAD_ERR_OK) {
            $filename = sanitize_file_name($name);
            $filepath = $upload_path . $filename;

            if (move_uploaded_file($files['tmp_name'][$index], $filepath)) {
                $slug = basename($filename, '.pdf');
                $chunker = new Chunker();
                $chunks = $chunker->chunkPdfWithMetadata($filepath, $slug);

                // verwijder bestaande chunks voor dit PDF-bestand

                foreach (glob($chunks_dir . $slug . '_chunk_*.json') as $old) {

                    unlink($old);
                }

                foreach ($chunks as $i => $chunk) {
                    $meta = $chunk['metadata'];

                    $file = $chunks_dir . $slug . '_chunk_' . ($i + 1) . '.json';
                    $data = [
                        'content'  => $chunk['content'],
                        'metadata' => [
                            'source_title' => $meta['source_title'] ?? '',
                            'page_slug'     => $meta['page_slug'] ?? '',
                            'original_page' => $meta['original_page'] ?? '',
                            'section_title' => $meta['section_title'] ?? '',
                        ],
                    ];
                    file_put_contents($file, wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                }
            }
        }
    }

    wp_redirect(add_query_arg('upload', 'success', admin_url('admin.php?page=octopus-ai-chatbot')));
    exit;
}

// --- BESTAND VERWIJDEREN ---
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
    $safe_file = basename($_GET['file']);
    $file_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/' . $safe_file;
    $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

    if (file_exists($file_path)) {
        $ext = pathinfo($safe_file, PATHINFO_EXTENSION);
        if ($ext === 'pdf') {
            $slug = basename($safe_file, '.pdf');

            foreach (glob($chunks_dir . $slug . '_chunk_*.json') as $chunk) {

                unlink($chunk);
            }
        } elseif ($ext === 'xml') {
            $urls = octopus_ai_parse_sitemap($file_path);
            if ($urls) {
                $parser = new \OctopusAI\Includes\SitemapParser();
                $parser->deleteChunksForUrls($urls);
            }
        }

        unlink($file_path);
        wp_redirect(add_query_arg('delete', 'success', admin_url('admin.php?page=octopus-ai-chatbot')));
    } else {
        wp_redirect(add_query_arg('delete', 'error', admin_url('admin.php?page=octopus-ai-chatbot')));
    }
    exit;
}

// --- BULK DELETE ---
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
    $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

    $deleted_count = 0;
    foreach ($files_to_delete as $filename) {
        $safe_name = basename($filename);
        $file_path = $upload_path . $safe_name;
        if (file_exists($file_path)) {
            $ext = pathinfo($safe_name, PATHINFO_EXTENSION);
            if ($ext === 'pdf') {
                $slug = basename($safe_name, '.pdf');

                foreach (glob($chunks_dir . $slug . '_chunk_*.json') as $chunk) {

                    unlink($chunk);
                }
            } elseif ($ext === 'xml') {
                $urls = octopus_ai_parse_sitemap($file_path);
                if ($urls) {
                    $parser = new \OctopusAI\Includes\SitemapParser();
                    $parser->deleteChunksForUrls($urls);
                }
            }

            unlink($file_path);
            $deleted_count++;
        }
    }

    wp_redirect(add_query_arg('bulk_delete', $deleted_count, admin_url('admin.php?page=octopus-ai-chatbot')));
    exit;
}


// --- ADMIN PAGE HTML ---
function octopus_ai_settings_page() {
    $upload_dir = wp_upload_dir();
    $upload_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
    $upload_url = trailingslashit($upload_dir['baseurl']) . 'octopus-chatbot/';
    $mode = get_option('octopus_ai_display_mode', 'all');
    $selected_pages = get_option('octopus_ai_selected_pages', array());
    $pages = get_pages();
    $api_key = get_option('octopus_ai_api_key');
    $masked_key = $api_key ? substr($api_key, 0, 5) . str_repeat('*', strlen($api_key) - 10) . substr($api_key, -5) : '';
    $selected_model = get_option('octopus_ai_model', 'gpt-4.1-mini');
    ?>
    <style>
    .octopus-settings .form-table th {
        width: 200px;
        font-weight: 600;
        color: #222;
    }

    .octopus-settings .form-table td {
        padding-bottom: 10px;
    }

    .octopus-settings h2 {
        border-left: 4px solid var(--primary-color, #0f6c95);
        padding-left: 10px;
        margin-top: 40px;
        font-size: 20px;
        color: #0f6c95;
    }

    .collapsible-heading {
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f3f4f6;
    padding: 10px;
    border-left: 4px solid var(--primary-color, #0f6c95);
    font-size: 16px;
    margin-top: 40px;
    border-radius: 4px;
}

.collapsible-heading .toggle-arrow {
    font-size: 14px;
    margin-left: 10px;
    transition: transform 0.3s ease;
}

.collapsible-heading.collapsed .toggle-arrow {
    transform: rotate(-90deg);
}

.collapsible-content {
    display: block;
    margin-top: 10px;
    transition: all 0.3s ease;
}
.collapsible-content.hidden {
    display: none;
}

    .octopus-settings input[type="text"],
    .octopus-settings input[type="url"],
    .octopus-settings textarea,
    .octopus-settings select {
        width: 100%;
        max-width: 500px;
        padding: 6px 10px;
        border-radius: 4px;
        border: 1px solid #ccc;
    }

    .octopus-settings .button-primary {
        background-color: #0f6c95;
        border-color: #0f6c95;
        box-shadow: none;
    }

    .octopus-settings ul {
        list-style: none;
        padding-left: 0;
    }

    .octopus-settings ul li {
        margin-bottom: 6px;
    }

    .octopus-settings .notice {
        margin-top: 20px;
    }

    .octopus-settings hr {
        margin-top: 40px;
        margin-bottom: 40px;
        border-color: #ddd;
    }

    .octopus-settings .widefat th,
    .octopus-settings .widefat td {
        font-size: 13px;
    }

    .octopus-settings .section-description {
        font-style: italic;
        color: #666;
        margin-top: -8px;
        margin-bottom: 15px;
    }

    .octopus-settings .upload-box {
    background: #fefefe;
    border: 1px solid #ddd;
    border-left: 5px solid var(--primary-color, #0f6c95);
    padding: 20px;
    margin-top: 20px;
    border-radius: 8px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

.octopus-settings .upload-box h3 {
    margin-top: 0;
    margin-bottom: 10px;
    font-size: 17px;
    color: #0f6c95;
    display: flex;
    align-items: center;
    gap: 8px;
}

.octopus-settings .upload-box input[type="file"],
.octopus-settings .upload-box input[type="url"] {
    margin-top: 5px;
    margin-bottom: 10px;
}

</style>

    <div class="wrap octopus-settings">
        <h1>AI Chatbot Instellingen</h1>

        <?php if (isset($_GET['upload']) && $_GET['upload'] === 'success'): ?>
            <div class="notice notice-success is-dismissible"><p>PDF's succesvol geüpload en verwerkt.</p></div>
        <?php endif; ?>

        <?php if (isset($_GET['upload']) && $_GET['upload'] === 'sitemap' && isset($_GET['found']) && isset($_GET['pages'])): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['found']); ?> URL(s) gevonden en <?php echo intval($_GET['pages']); ?> pagina's gecrawld.</p></div>

        <?php endif; ?>
        <?php if (isset($_GET['delete']) && $_GET['delete'] === 'success'): ?>
            <div class="notice notice-success is-dismissible"><p>Bestand succesvol verwijderd.</p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('octopus_ai_settings_group'); ?>

            <h2>🔐 API-instellingen</h2>
            <table class="form-table">
                <tr><th>API Key</th>
                    <td>
                        <input type="text" name="octopus_ai_api_key" value="<?php echo esc_attr($masked_key); ?>" style="width: 400px;" placeholder="Voer je OpenAI API key in" />
                        <p class="description">De key wordt gemaskeerd weergegeven. Vul opnieuw in om te wijzigen.</p>
                    </td>
                </tr>
            </table>

            <h2>🧠 AI Model</h2>
            <table class="form-table">
                <tr>
                    <th>Modelkeuze</th>
                    <td>
                        <select name="octopus_ai_model" style="width: 400px;">
                            <?php
                            $models = [
                                'gpt-4.1-mini'   => 'GPT-4.1 Mini ⚖️ (aanbevolen)',
                                'gpt-4o'         => 'GPT-4o 🧠⚡ (snel en krachtig)',
                                'gpt-4.1-nano'   => 'GPT-4.1 Nano 🚀 (supersnel)',
                                'gpt-4.1'        => 'GPT-4.1 🧠 (maximale accuraatheid)',
                                'o4-mini'        => 'OpenAI o4-mini 🔬 (voor redenering)',
                                'gpt-3.5-turbo'  => 'GPT-3.5 Turbo 💬 (budgetoptie)'
                            ];
                            foreach ($models as $value => $label) {
                                echo '<option value="' . esc_attr($value) . '" ' . selected($selected_model, $value, false) . '>' . esc_html($label) . '</option>';
                            }
                            ?>
                        </select>

                        <p style="margin-top:10px;">
                            <a href="#" onclick="toggleModelInfo(); return false;">📊 Bekijk vergelijking van modellen en prijzen</a>
                        </p>

                        <div id="model-info-table" style="display:none; margin-top:10px; border:1px solid #ddd; padding:10px; border-radius:6px; background:#f9f9f9;">
                            <table class="widefat striped">
                                <thead>
                                    <tr><th>Model</th><th>Snelheid ⚡</th><th>Intelligentie 🧠</th><th>Prijs / 1K tokens</th><th>Aanbevolen voor</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>GPT-4.1 Mini</td><td>⚡⚡⚡</td><td>🧠🧠🧠</td><td>$0.40 / $1.60</td><td>⚖️ Balans snelheid/kwaliteit</td></tr>
                                    <tr>
    <td>GPT-4o</td>
    <td>⚡⚡⚡⚡</td>
    <td>🧠🧠🧠🧠</td>
    <td>$0.50 / $1.50</td>
    <td>🧠⚡ Nieuw, snel & accuraat</td>
</tr>
                                    <tr><td>GPT-4.1 Nano</td><td>⚡⚡⚡⚡</td><td>🧠🧠</td><td>$0.10 / $0.40</td><td>🚀 Snelle basistaken</td></tr>
                                    <tr><td>GPT-4.1</td><td>⚡</td><td>🧠🧠🧠🧠</td><td>$2.00 / $8.00</td><td>💡 Complexe vragen</td></tr>
                                    <tr><td>OpenAI o4-mini</td><td>⚡⚡</td><td>🧠🧠🧠🧠</td><td>$1.10 / $4.40</td><td>🔬 Redenering & logica</td></tr>
                                    <tr><td>GPT-3.5 Turbo</td><td>⚡⚡⚡</td><td>🧠</td><td>± $0.50 / $1.50</td><td>💬 Budgetoptie</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <script>
                            function toggleModelInfo() {
                                const el = document.getElementById("model-info-table");
                                el.style.display = el.style.display === "none" ? "block" : "none";
                            }
                        </script>
                    </td>
                </tr>
            </table>

            <h2>🗣️ Taal & Weergave</h2>
            <table class="form-table">
                <tr><th>Tone of Voice</th><td><textarea name="octopus_ai_tone" rows="3" style="width: 400px;"><?php echo esc_textarea(get_option('octopus_ai_tone')); ?></textarea></td></tr>
                <tr><th>Fallback tekst</th><td><input type="text" name="octopus_ai_fallback" value="<?php echo esc_attr(get_option('octopus_ai_fallback')); ?>" style="width: 400px;" /></td></tr>
                <tr><th>Verwelkomingstekst</th><td><textarea name="octopus_ai_welcome_message" rows="2" style="width: 400px;"><?php echo esc_textarea(get_option('octopus_ai_welcome_message')); ?></textarea></td></tr>
                <tr><th>Merknaam</th><td><input type="text" name="octopus_ai_brand_name" value="<?php echo esc_attr(get_option('octopus_ai_brand_name')); ?>" style="width: 400px;" /></td></tr>
            </table>

            <h2>🎨 Uiterlijk</h2>
            <table class="form-table">
                <tr><th>Primaire kleur</th><td><input type="text" name="octopus_ai_primary_color" class="wp-color-picker-field" data-default-color="#0f6c95" value="<?php echo esc_attr(get_option('octopus_ai_primary_color', '#0f6c95')); ?>" /></td></tr>
                <tr>
    <th>Header tekstkleur</th>
    <td><input type="text" name="octopus_ai_header_text_color" class="wp-color-picker-field" data-default-color="#ffffff" value="<?php echo esc_attr(get_option('octopus_ai_header_text_color', '#ffffff')); ?>" /></td>
</tr>

                <tr><th>Logo-URL</th>
                    <td>
                        <?php $logo = get_option('octopus_ai_logo_url'); ?>
                        <img src="<?php echo esc_url($logo); ?>" id="octopus-ai-logo-preview" style="max-width:100px;display:block;margin-bottom:10px;">
                        <input type="url" name="octopus_ai_logo_url" id="octopus-ai-logo-url" value="<?php echo esc_attr($logo); ?>" style="width:400px;" readonly>
                        <button type="button" class="button" id="octopus-ai-upload-logo-button">Upload / Selecteer logo</button>
                    </td>
                </tr>
            </table>

            <h2>
    🌐 Zichtbaarheid
</h2>
<div>
            <table class="form-table">
                <tr><th>Chatbot weergave</th>
                    <td>
                        <select name="octopus_ai_display_mode" id="octopus_ai_display_mode">
                            <option value="all" <?php selected($mode, 'all'); ?>>Op alle pagina's tonen</option>
                            <option value="selected" <?php selected($mode, 'selected'); ?>>Alleen op geselecteerde pagina's tonen</option>
                        </select>
                    </td>
                </tr>
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
                <tr>
    <th>Testmodus</th>
    <td>
        <label>
            <input type="checkbox" name="octopus_ai_test_mode" value="1" <?php checked(get_option('octopus_ai_test_mode'), 1); ?>>
            Alleen zichtbaar voor beheerders
        </label>
    </td>
</tr>

            </table>

            <?php submit_button('Instellingen opslaan'); ?>
        </form>
    </div>
        <hr>

        <h2 class="collapsible-heading" onclick="toggleSection(this)">
    🗂️ Geüploade Bestanden <span class="toggle-arrow">▼</span>
</h2>
        <div class="collapsible-content hidden">

        <div class="upload-box">
    <h3>📄 PDF-handleidingen uploaden</h3>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('octopus_ai_upload_pdf', 'octopus_ai_pdf_nonce'); ?>
        <input type="hidden" name="action" value="octopus_ai_pdf_upload">
        <input type="file" name="octopus_ai_pdf_upload[]" accept="application/pdf" multiple required>
        <?php submit_button('Upload PDF'); ?>
    </form>
</div>


        <?php
        if (isset($_GET['bulk_delete'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . intval($_GET['bulk_delete']) . ' bestand(en) succesvol verwijderd.</p></div>';
        }
        if (file_exists($upload_path)) {
            $files = glob($upload_path . '*');
            if ($files) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('octopus_ai_bulk_delete', 'octopus_ai_bulk_delete_nonce');
                echo '<input type="hidden" name="action" value="octopus_ai_bulk_delete">';
                echo '<ul>';
                foreach ($files as $file) {
                    $filename = basename($file);
                    $delete_url = wp_nonce_url(admin_url('admin-post.php?action=octopus_ai_delete_file&file=' . urlencode($filename)), 'octopus_ai_delete_file');
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

        <hr>

       <div class="upload-box">
    <h3>🗺️ Sitemap uploaden of via URL</h3>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data" style="margin-bottom: 15px;">
        <?php wp_nonce_field('octopus_ai_upload_sitemap', 'octopus_ai_sitemap_nonce'); ?>
        <input type="hidden" name="action" value="octopus_ai_upload_sitemap">
       <input type="file" name="octopus_ai_sitemap_file[]" accept=".xml" multiple required>
        <?php submit_button('Upload sitemap.xml', 'secondary'); ?>
    </form>

    <form method="post">
        <input type="url" name="sitemap_url" value="<?php echo esc_attr(get_option('octopus_ai_sitemap_url', '')); ?>" style="width:400px;" placeholder="https://example.com/sitemap.xml" />
        <?php submit_button('💾 Sitemap opslaan'); ?>
    </form>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top:15px;">
        <?php wp_nonce_field('octopus_ai_auto_sitemap', 'octopus_ai_auto_sitemap_nonce'); ?>
        <input type="hidden" name="action" value="octopus_ai_auto_fetch_sitemap">
        <input type="url" name="octopus_ai_site_url" style="width:400px;" placeholder="https://example.com" required />
        <?php submit_button('🔎 Zoek sitemap automatisch', 'secondary'); ?>
    </form>
</div>


<?php
// ✅ Save externe URL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sitemap_url'])) {
    update_option('octopus_ai_sitemap_url', esc_url_raw($_POST['sitemap_url']));
    echo '<div class="notice notice-success is-dismissible"><p>Sitemap-URL opgeslagen.</p></div>';
}

$sitemap_path = $upload_path . 'sitemap.xml';
if (file_exists($sitemap_path)) {
    echo '<p><strong>📄 Huidige sitemap:</strong> <a href="' . esc_url($upload_url . 'sitemap.xml') . '" target="_blank">Bekijk sitemap.xml</a></p>';
}

require_once plugin_dir_path(__FILE__) . '../includes/sitemap-parser.php';
$parser = new \OctopusAI\Includes\SitemapParser();

if (isset($_GET['sitemap_debug'])) {
    $urls = $parser->getUrlsFromSitemap();
    echo '<p><strong>' . count($urls) . ' URL(s)</strong> gevonden in de sitemap.</p>';
    echo '<ul>';
    foreach (array_slice($urls, 0, 10) as $url) {
        echo '<li><a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a></li>';
    }
    echo '</ul>';
}

echo '<p><a href="' . esc_url(add_query_arg('sitemap_debug', '1')) . '" class="button">🔍 Toon sitemap-URL’s</a></p>';
?>

<hr>

<h2>🧾 Geüploade sitemap-bestanden</h2>

<?php
$upload_dir = wp_upload_dir();
$sitemap_dir = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
$sitemap_url = trailingslashit($upload_dir['baseurl']) . 'octopus-chatbot/';

$sitemaps = glob($sitemap_dir . '*.xml');

if (isset($_GET['sitemaps_deleted'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . intval($_GET['sitemaps_deleted']) . ' sitemap-bestand(en) verwijderd.</p></div>';
}

if ($sitemaps):
?>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <?php wp_nonce_field('octopus_ai_delete_sitemaps'); ?>
        <input type="hidden" name="action" value="octopus_ai_delete_sitemaps">
        <ul style="max-height:250px;overflow:auto;border:1px solid #ccc;padding:10px;background:#fff;">
            <?php foreach ($sitemaps as $file): 
                $filename = basename($file); ?>
                <li>
                    <label>
                        <input type="checkbox" name="sitemap_files[]" value="<?php echo esc_attr($filename); ?>">
                        <a href="<?php echo esc_url($sitemap_url . $filename); ?>" target="_blank"><?php echo esc_html($filename); ?></a>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>
        <p style="margin-top:10px;">
            <input type="submit" class="button button-secondary" value="🗑️ Verwijder geselecteerde sitemaps" onclick="return confirm('Weet je zeker dat je deze sitemap-bestanden wilt verwijderen?');">
        </p>
    </form>
<?php else: ?>
    <p><em>Er zijn momenteel geen sitemap-bestanden geüpload.</em></p>
<?php endif; ?>


<h2>🧹 Sitemap chunks beheren</h2>

<?php
$chunk_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
$chunk_url = trailingslashit($upload_dir['baseurl']) . 'octopus-ai-chunks/';

if (isset($_GET['chunks_deleted'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . intval($_GET['chunks_deleted']) . ' chunk(s) verwijderd.</p></div>';
}
if (isset($_GET['chunks_cleared'])) {
    echo '<div class="notice notice-success is-dismissible"><p>Alle sitemap chunks verwijderd.</p></div>';
}

if (file_exists($chunk_dir)) {
    $chunk_files = glob($chunk_dir . 'sitemap_*.json');
    if ($chunk_files): ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('octopus_ai_delete_chunks'); ?>
            <input type="hidden" name="action" value="octopus_ai_delete_chunks">
            <ul style="max-height:250px;overflow:auto;border:1px solid #ccc;padding:10px;background:#fff;">
                <?php foreach ($chunk_files as $file): 
                    $filename = basename($file); ?>
                    <li>
                        <label>
                            <input type="checkbox" name="chunk_files[]" value="<?php echo esc_attr($filename); ?>">
                            <a href="<?php echo esc_url($chunk_url . $filename); ?>" target="_blank"><?php echo esc_html($filename); ?></a>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p style="margin-top:10px;">
                <input type="submit" class="button button-secondary" value="🗑️ Geselecteerde chunks verwijderen" onclick="return confirm('Weet je zeker dat je deze bestanden wilt verwijderen?');">
            </p>
        </form>

        <!-- Alles verwijderen -->
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top:10px;">
            <?php wp_nonce_field('octopus_ai_clear_all_chunks'); ?>
            <input type="hidden" name="action" value="octopus_ai_clear_all_chunks">
            <?php submit_button('🧨 Verwijder ALLE sitemap chunks', 'delete', '', false); ?>
        </form>

    <?php else: ?>
        <p><em>Er zijn momenteel geen sitemap chunks opgeslagen.</em></p>
    <?php endif;
} else {
    echo '<p><em>De chunks-folder bestaat nog niet.</em></p>';
}
?>

        </div>


<hr>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const url = new URL(window.location);
    const paramsToRemove = ['upload', 'delete', 'bulk_delete', 'chunks_deleted', 'chunks_cleared', 'sitemap_debug', 'pages'];

    let shouldUpdate = false;
    for (const param of paramsToRemove) {
        if (url.searchParams.has(param)) {
            url.searchParams.delete(param);
            shouldUpdate = true;
        }
    }

    if (shouldUpdate) {
        window.history.replaceState({}, document.title, url.pathname + url.search);
    }
});
</script>
<script>
function toggleSection(header) {
    const content = header.nextElementSibling;
    content.classList.toggle('hidden');
    header.classList.toggle('collapsed');
}
</script>


    </div>

    <script>
        document.getElementById('octopus_ai_display_mode').addEventListener('change', function() {
            const row = document.getElementById('octopus_ai_page_selector_row');
            row.style.display = this.value === 'selected' ? '' : 'none';
        });
    </script>
<?php if (isset($_GET['upload']) || isset($_GET['sitemap_debug'])): ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const el = document.getElementById('sitemap-zone');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
</script>
<?php endif; ?>
<?php }



