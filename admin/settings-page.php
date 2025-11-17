<?php
// Veiligheid
if (!defined('ABSPATH')) exit;


use OctopusAI\Includes\Chunker;
use OctopusAI\Includes\SitemapParser;

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
    register_setting('octopus_ai_settings_group', 'octopus_ai_source_strategy', 'octopus_ai_sanitize_source_strategy');
    register_setting('octopus_ai_settings_group', 'octopus_ai_manual_mode', 'octopus_ai_sanitize_manual_mode');
    register_setting('octopus_ai_settings_group', 'octopus_ai_manual_base_url_nl', 'octopus_ai_sanitize_manual_url');
    register_setting('octopus_ai_settings_group', 'octopus_ai_manual_base_url_fr', 'octopus_ai_sanitize_manual_url');
    register_setting('octopus_ai_settings_group', 'octopus_ai_manual_priority_urls_nl', 'octopus_ai_sanitize_manual_url_list');
    register_setting('octopus_ai_settings_group', 'octopus_ai_manual_priority_urls_fr', 'octopus_ai_sanitize_manual_url_list');
}

function octopus_ai_sanitize_source_strategy($value) {
    $allowed = ['manual_upload', 'sitemap_online', 'live_manual'];
    $value = is_string($value) ? strtolower($value) : '';

    if (!in_array($value, $allowed, true)) {
        return 'manual_upload';
    }

    return $value;
}

function octopus_ai_sanitize_manual_mode($value) {
    $allowed = ['local', 'hybrid', 'live'];
    $value = is_string($value) ? strtolower($value) : '';
    if (!in_array($value, $allowed, true)) {
        return 'hybrid';
    }
    return $value;
}

function octopus_ai_sanitize_manual_url($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $sanitized = esc_url_raw($value);
    if ($sanitized === '') {
        return '';
    }

    if (function_exists('wp_http_validate_url') && !wp_http_validate_url($sanitized)) {
        return '';
    }

    return $sanitized;
}

function octopus_ai_sanitize_manual_url_list($value) {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }

    $parts = preg_split('/[\r\n,]+/', $value);
    $valid = [];

    if (is_array($parts)) {
        foreach ($parts as $part) {
            $sanitized = octopus_ai_sanitize_manual_url($part);
            if ($sanitized !== '' && !in_array($sanitized, $valid, true)) {
                $valid[] = $sanitized;
            }
        }
    }

    return implode("\n", $valid);
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
                $file_url = trailingslashit($upload_dir['baseurl']) . 'octopus-chatbot/' . $filename;
                $chunks = $chunker->chunkPdfWithMetadata($filepath, $slug, $file_url);

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
                            'source_url'    => $meta['source_url'] ?? '',
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
    $source_strategy = get_option('octopus_ai_source_strategy', 'manual_upload');
    $manual_mode = get_option('octopus_ai_manual_mode', 'hybrid');
    $manual_base_nl = get_option('octopus_ai_manual_base_url_nl', '');
    $manual_base_fr = get_option('octopus_ai_manual_base_url_fr', '');
    $manual_priority_nl = get_option('octopus_ai_manual_priority_urls_nl', '');
    $manual_priority_fr = get_option('octopus_ai_manual_priority_urls_fr', '');
    $saved_sitemap_url = get_option('octopus_ai_sitemap_url', '');

    if (!class_exists(SitemapParser::class)) {
        require_once plugin_dir_path(__FILE__) . '../includes/sitemap-parser.php';
    }

    $parser = new SitemapParser();
    $sitemap_path = $upload_path . 'sitemap.xml';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sitemap_url'])) {
        update_option('octopus_ai_sitemap_url', esc_url_raw($_POST['sitemap_url']));
        echo '<div class="notice notice-success is-dismissible"><p>Sitemap-URL opgeslagen.</p></div>';
    }
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

.source-mode-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.source-mode-card {
    border: 1px solid #d8dee4;
    border-radius: 10px;
    padding: 15px;
    background: #fff;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.source-mode-card.is-active {
    border-color: var(--primary-color, #0f6c95);
    box-shadow: 0 4px 14px rgba(15, 108, 149, 0.15);
}

.source-mode-card input[type="radio"] {
    margin-right: 6px;
}

.source-mode-card small {
    display: inline-block;
    background: #eef6fb;
    color: #0f6c95;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
}

.source-mode-details {
    margin-top: 30px;
}

.source-mode-panel {
    display: none;
    margin-bottom: 30px;
}

.source-mode-panel.is-active {
    display: block;
}

.source-mode-panel h3 {
    margin-top: 0;
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

        <form id="octopus-ai-settings-form" method="post" action="options.php">
            <?php settings_fields('octopus_ai_settings_group'); ?>
            <input type="hidden" name="octopus_ai_manual_mode" id="octopus_ai_manual_mode_hidden" value="<?php echo esc_attr($manual_mode); ?>">

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
                                'gpt-4o-mini'            => [
                                    'name'        => 'GPT-4o Mini',
                                    'description' => 'Betaalbaar, snel en perfect voor dagelijkse supportvragen.',
                                    'pricing'     => '$0.15 / $0.60',
                                ],
                                'gpt-4o-2024-11-20'      => [
                                    'name'        => 'GPT-4o (2024-11-20)',
                                    'description' => 'Geoptimaliseerde release voor betrouwbaarheid op langere gesprekken.',
                                    'pricing'     => '$0.22 / $0.88',
                                ],
                                'gpt-4.1'                => [
                                    'name'        => 'GPT-4.1',
                                    'description' => 'Maximale nauwkeurigheid voor complexe procesvragen.',
                                    'pricing'     => '$2.00 / $8.00',
                                ],
                                'gpt-3.5-turbo'          => [
                                    'name'        => 'GPT-3.5 Turbo',
                                    'description' => 'Budgetvriendelijk voor eenvoudige Q&A en standaardflows.',
                                    'pricing'     => '$0.50 / $1.50',
                                ],
                                'gpt-4o'                 => [
                                    'name'        => 'GPT-4o',
                                    'description' => 'Snel en accuraat voor intensief dagelijks gebruik.',
                                    'pricing'     => '$0.50 / $1.50',
                                ],
                                'gpt-4.1-nano-2025-04-14' => [
                                    'name'        => 'GPT-4.1 Nano (2025-04-14)',
                                    'description' => 'Supersnelle nano-variant voor korte hints en checks.',
                                    'pricing'     => '$0.08 / $0.32',
                                ],
                                'gpt-4.1-mini'           => [
                                    'name'        => 'GPT-4.1 Mini',
                                    'description' => 'Allround balans tussen kwaliteit en prijs, aanbevolen.',
                                    'pricing'     => '$0.40 / $1.60',
                                ],
                                'gpt-5-nano'             => [
                                    'name'        => 'GPT-5 Nano',
                                    'description' => 'Nieuwste nano-upgrade met betere contextbehoud.',
                                    'pricing'     => '$0.12 / $0.48',
                                ],
                                'gpt-5'                  => [
                                    'name'        => 'GPT-5',
                                    'description' => 'Premiummodel voor diepgaande dossieranalyses.',
                                    'pricing'     => '$2.50 / $10.00',
                                ],
                                'gpt-5.1-2025-11-13'     => [
                                    'name'        => 'GPT-5.1 (2025-11-13)',
                                    'description' => 'Langetermijnrelease met focus op stabiliteit en audit trails.',
                                    'pricing'     => '$3.00 / $12.00',
                                ],
                                'gpt-5-codex'            => [
                                    'name'        => 'GPT-5 Codex',
                                    'description' => 'Codex-variant met uitstekende stappenplannen en scripts.',
                                    'pricing'     => '$1.20 / $4.80',
                                ],
                                'gpt-4'                  => [
                                    'name'        => 'GPT-4',
                                    'description' => 'Bewezen klasieker voor nauwkeurige antwoorden.',
                                    'pricing'     => '$1.50 / $6.00',
                                ],
                                'gpt-5.1-codex'          => [
                                    'name'        => 'GPT-5.1 Codex',
                                    'description' => 'Meest recente Codex-versie met verbeterde taakautomatisering.',
                                    'pricing'     => '$1.60 / $6.40',
                                ],
                                'gpt-5.1'                => [
                                    'name'        => 'GPT-5.1',
                                    'description' => 'Topmodel voor kritieke klantcases en escalaties.',
                                    'pricing'     => '$2.80 / $11.20',
                                ],
                            ];

                            foreach ($models as $value => $info) {
                                $option_label = sprintf(
                                    '%s – %s (± %s per 1K tokens)',
                                    $info['name'],
                                    $info['description'],
                                    $info['pricing']
                                );

                                echo '<option value="' . esc_attr($value) . '" ' . selected($selected_model, $value, false) . '>' . esc_html($option_label) . '</option>';
                            }
                            ?>
                        </select>

                        <p style="margin-top:10px;">
                            <a href="#" onclick="toggleModelInfo(); return false;">📊 Bekijk vergelijking van modellen en prijzen</a>
                        </p>

                        <div id="model-info-table" style="display:none; margin-top:10px; border:1px solid #ddd; padding:10px; border-radius:6px; background:#f9f9f9;">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th>Model</th>
                                        <th>Korte uitleg</th>
                                        <th>Prijs (prompt/completion per 1K tokens)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($models as $info) : ?>
                                        <tr>
                                            <td><?php echo esc_html($info['name']); ?></td>
                                            <td><?php echo esc_html($info['description']); ?></td>
                                            <td><?php echo esc_html($info['pricing']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
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

            <h2>📚 Bronmateriaal</h2>
            <p class="section-description">Kies één bron. De chatbot zal uitsluitend deze methode gebruiken om context en links te tonen.</p>
            <?php
            $source_cards = [
                'manual_upload' => [
                    'title'       => 'Manueel uploaden',
                    'description' => 'Upload PDF- of sitemapbestanden en bouw zelf chunks op.',
                    'badge'       => 'Offline',
                    'cta'         => 'Gebruik uitsluitend geüploade bestanden.',
                ],
                'sitemap_online' => [
                    'title'       => 'Online sitemap gebruiken',
                    'description' => 'Zoek of bewaar een sitemap-URL en laat Octopus de inhoud crawlen.',
                    'badge'       => 'Automatisch',
                    'cta'         => 'Gebruik uitsluitend de gevonden sitemap.',
                ],
                'live_manual' => [
                    'title'       => 'Live handleiding',
                    'description' => 'Haalt realtime informatie uit de handleiding en toont exacte links.',
                    'badge'       => 'Realtime',
                    'cta'         => 'Gebruik enkel live opgehaalde pagina’s.',
                ],
            ];
            ?>
            <div class="source-mode-grid">
                <?php foreach ($source_cards as $value => $card): ?>
                    <div class="source-mode-card <?php echo $source_strategy === $value ? 'is-active' : ''; ?>" data-target="<?php echo esc_attr($value); ?>">
                        <div>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
                                <input type="radio" name="octopus_ai_source_strategy" value="<?php echo esc_attr($value); ?>" <?php checked($source_strategy, $value); ?>>
                                <?php echo esc_html($card['title']); ?>
                            </label>
                            <small><?php echo esc_html($card['badge']); ?></small>
                        </div>
                        <p><?php echo esc_html($card['description']); ?></p>
                        <p class="description" style="margin:0;color:#444;"><?php echo esc_html($card['cta']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2 style="margin-top:35px;">📄 Handleiding & voorkeuren</h2>
            <table class="form-table">
                <tr>
                    <th>Basis URL NL</th>
                    <td>
                        <input type="url" name="octopus_ai_manual_base_url_nl" value="<?php echo esc_attr($manual_base_nl); ?>" placeholder="https://login.octopus.be/manual/NL/" />
                        <p class="description">Optioneel: wijzig de basis van de Nederlandstalige handleiding indien je een andere omgeving gebruikt. Laat leeg voor de standaard Octopus URL.</p>
                    </td>
                </tr>
                <tr>
                    <th>Basis URL FR</th>
                    <td>
                        <input type="url" name="octopus_ai_manual_base_url_fr" value="<?php echo esc_attr($manual_base_fr); ?>" placeholder="https://login.octopus.be/manual/FR/" />
                        <p class="description">Optioneel: wijzig de basis van de Franstalige handleiding indien nodig.</p>
                    </td>
                </tr>
                <tr>
                    <th>Voorkeurspagina's NL</th>
                    <td>
                        <textarea name="octopus_ai_manual_priority_urls_nl" rows="3" placeholder="https://login.octopus.be/manual/NL/voorbeeld.htm&#10;https://login.octopus.be/manual/NL/andere-pagina.htm"><?php echo esc_textarea($manual_priority_nl); ?></textarea>
                        <p class="description">Geef één of meerdere URL's op (één per lijn) die eerst live opgehaald mogen worden wanneer de chatbot de handleiding raadpleegt.</p>
                    </td>
                </tr>
                <tr>
                    <th>Voorkeurspagina's FR</th>
                    <td>
                        <textarea name="octopus_ai_manual_priority_urls_fr" rows="3" placeholder="https://login.octopus.be/manual/FR/exemple.htm"><?php echo esc_textarea($manual_priority_fr); ?></textarea>
                        <p class="description">Worden gebruikt voor Franstalige sessies. Laat leeg om enkel de metadata van chunks te volgen.</p>
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

        <?php
        $sitemap_dir = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
        $sitemap_url_base = trailingslashit($upload_dir['baseurl']) . 'octopus-chatbot/';
        $sitemaps = glob($sitemap_dir . '*.xml');
        $chunk_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
        $chunk_url = trailingslashit($upload_dir['baseurl']) . 'octopus-ai-chunks/';
        $shared_active = in_array($source_strategy, ['manual_upload', 'sitemap_online'], true);
        ?>

        <div class="source-mode-details">
            <div class="source-mode-panel <?php echo $source_strategy === 'manual_upload' ? 'is-active' : ''; ?>" data-mode="manual_upload">
                <div class="upload-box">
                    <h3>📄 PDF-handleidingen uploaden</h3>
                    <p class="section-description">Upload één of meerdere PDF-bestanden. Elke upload wordt automatisch gechunkt en vormt de enige bron voor antwoorden.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('octopus_ai_upload_pdf', 'octopus_ai_pdf_nonce'); ?>
                        <input type="hidden" name="action" value="octopus_ai_pdf_upload">
                        <input type="file" name="octopus_ai_pdf_upload[]" accept="application/pdf" multiple required>
                        <?php submit_button('Upload PDF'); ?>
                    </form>
                </div>

                <div class="upload-box">
                    <h3>🗺️ Sitemap handmatig uploaden</h3>
                    <p class="section-description">Gebruik dit als je een sitemap.xml lokaal wilt beheren in plaats van online op te halen.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('octopus_ai_upload_sitemap', 'octopus_ai_sitemap_nonce'); ?>
                        <input type="hidden" name="action" value="octopus_ai_upload_sitemap">
                        <input type="file" name="octopus_ai_sitemap_file[]" accept=".xml" multiple required>
                        <?php submit_button('Upload sitemap.xml', 'secondary'); ?>
                    </form>
                </div>
            </div>

            <div class="source-mode-panel <?php echo $source_strategy === 'sitemap_online' ? 'is-active' : ''; ?>" data-mode="sitemap_online">
                <div class="upload-box">
                    <h3>🔗 Sitemap via URL</h3>
                    <p class="section-description">Bewaar een sitemap-URL die automatisch gecrawld en gechunkt wordt.</p>
                    <form method="post">
                        <input type="url" name="sitemap_url" value="<?php echo esc_attr($saved_sitemap_url); ?>" style="width:400px;" placeholder="https://example.com/sitemap.xml" required />
                        <?php submit_button('💾 Sitemap opslaan'); ?>
                    </form>
                </div>

                <div class="upload-box">
                    <h3>🔎 Zoek sitemap automatisch</h3>
                    <p class="section-description">Geef een domein op. De crawler zoekt naar bekende sitemap-bestandsnamen en verwerkt ze meteen.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('octopus_ai_auto_sitemap', 'octopus_ai_auto_sitemap_nonce'); ?>
                        <input type="hidden" name="action" value="octopus_ai_auto_fetch_sitemap">
                        <input type="url" name="octopus_ai_site_url" style="width:400px;" placeholder="https://example.com" required />
                        <?php submit_button('🔎 Zoek sitemap automatisch', 'secondary'); ?>
                    </form>
                    <?php if (file_exists($sitemap_path)) : ?>
                        <p style="margin-top:10px;"><strong>📄 Lokaal opgeslagen sitemap:</strong> <a href="<?php echo esc_url($upload_url . 'sitemap.xml'); ?>" target="_blank">Bekijk sitemap.xml</a></p>
                    <?php endif; ?>
                </div>

                <div class="upload-box">
                    <h3>🧪 Sitemap-voorbeeld</h3>
                    <?php if (isset($_GET['sitemap_debug'])) : ?>
                        <?php $urls = $parser->getUrlsFromSitemap(); ?>
                        <p><strong><?php echo intval(count($urls)); ?> URL(s)</strong> gevonden in de sitemap.</p>
                        <ul>
                            <?php foreach (array_slice($urls, 0, 10) as $url_item) : ?>
                                <li><a href="<?php echo esc_url($url_item); ?>" target="_blank"><?php echo esc_html($url_item); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p class="description">Toon een voorbeeld van de eerste gevonden URL's om te controleren of de juiste sitemap wordt gelezen.</p>
                    <?php endif; ?>
                    <p><a href="<?php echo esc_url(add_query_arg('sitemap_debug', '1')); ?>" class="button">🔍 Toon sitemap-URL’s</a></p>
                </div>
            </div>

            <div class="source-mode-panel <?php echo $source_strategy === 'live_manual' ? 'is-active' : ''; ?>" data-mode="live_manual">
                <div class="upload-box">
                    <h3>⚡ Live handleiding modus</h3>
                    <p>De chatbot haalt voor elke vraag rechtstreeks tekst op uit de Octopus-handleiding. Het antwoord bevat altijd een exacte link naar de gebruikte pagina.</p>
                    <ul style="list-style:disc;padding-left:20px;">
                        <li>Controleer dat de basis-URL's hierboven juist ingesteld zijn.</li>
                        <li>Gebruik de velden voor voorkeurspagina's om belangrijke topics te boosten.</li>
                        <li>Omdat enkel live data gebruikt wordt, hoef je geen PDF's of sitemaps te beheren.</li>
                    </ul>
                </div>
            </div>

            <div class="source-mode-panel <?php echo $shared_active ? 'is-active' : ''; ?>" data-mode-group="manual_upload,sitemap_online">
                <h3>🗂️ Geüploade bestanden</h3>
                <?php if (isset($_GET['bulk_delete'])) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['bulk_delete']); ?> bestand(en) succesvol verwijderd.</p></div>
                <?php endif; ?>
                <?php if (file_exists($upload_path)) : ?>
                    <?php $files = glob($upload_path . '*'); ?>
                    <?php if ($files) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('octopus_ai_bulk_delete', 'octopus_ai_bulk_delete_nonce'); ?>
                            <input type="hidden" name="action" value="octopus_ai_bulk_delete">
                            <ul>
                                <?php foreach ($files as $file) :
                                    $filename = basename($file);
                                    $delete_url = wp_nonce_url(admin_url('admin-post.php?action=octopus_ai_delete_file&file=' . urlencode($filename)), 'octopus_ai_delete_file'); ?>
                                    <li>
                                        <label>
                                            <input type="checkbox" name="octopus_ai_files[]" value="<?php echo esc_attr($filename); ?>">
                                            <a href="<?php echo esc_url($upload_url . $filename); ?>" target="_blank"><?php echo esc_html($filename); ?></a>
                                        </label>
                                        <a href="<?php echo esc_url($delete_url); ?>" style="color:red;margin-left:10px;" onclick="return confirm('Weet je zeker dat je dit bestand wilt verwijderen?');">Verwijderen</a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p><input type="submit" class="button button-secondary" value="Geselecteerde bestanden verwijderen" onclick="return confirm('Weet je zeker dat je deze bestanden wilt verwijderen?');"></p>
                        </form>
                    <?php else : ?>
                        <p>Er zijn nog geen bestanden geüpload.</p>
                    <?php endif; ?>
                <?php else : ?>
                    <p>Er zijn nog geen bestanden geüpload.</p>
                <?php endif; ?>
            </div>

            <div class="source-mode-panel <?php echo $shared_active ? 'is-active' : ''; ?>" data-mode-group="manual_upload,sitemap_online">
                <h3>🧾 Geüploade sitemap-bestanden</h3>
                <?php if (isset($_GET['sitemaps_deleted'])) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['sitemaps_deleted']); ?> sitemap-bestand(en) verwijderd.</p></div>
                <?php endif; ?>
                <?php if ($sitemaps) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('octopus_ai_delete_sitemaps'); ?>
                        <input type="hidden" name="action" value="octopus_ai_delete_sitemaps">
                        <ul style="max-height:250px;overflow:auto;border:1px solid #ccc;padding:10px;background:#fff;">
                            <?php foreach ($sitemaps as $file) :
                                $filename = basename($file); ?>
                                <li>
                                    <label>
                                        <input type="checkbox" name="sitemap_files[]" value="<?php echo esc_attr($filename); ?>">
                                        <a href="<?php echo esc_url($sitemap_url_base . $filename); ?>" target="_blank"><?php echo esc_html($filename); ?></a>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p style="margin-top:10px;">
                            <input type="submit" class="button button-secondary" value="🗑️ Verwijder geselecteerde sitemaps" onclick="return confirm('Weet je zeker dat je deze sitemap-bestanden wilt verwijderen?');">
                        </p>
                    </form>
                <?php else : ?>
                    <p><em>Er zijn momenteel geen sitemap-bestanden geüpload.</em></p>
                <?php endif; ?>
            </div>

            <div class="source-mode-panel <?php echo $shared_active ? 'is-active' : ''; ?>" data-mode-group="manual_upload,sitemap_online">
                <h3>🧹 Sitemap chunks beheren</h3>
                <?php if (isset($_GET['chunks_deleted'])) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['chunks_deleted']); ?> chunk(s) verwijderd.</p></div>
                <?php endif; ?>
                <?php if (isset($_GET['chunks_cleared'])) : ?>
                    <div class="notice notice-success is-dismissible"><p>Alle sitemap chunks verwijderd.</p></div>
                <?php endif; ?>
                <?php if (file_exists($chunk_dir)) : ?>
                    <?php $chunk_files = glob($chunk_dir . 'sitemap_*.json'); ?>
                    <?php if ($chunk_files) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('octopus_ai_delete_chunks'); ?>
                            <input type="hidden" name="action" value="octopus_ai_delete_chunks">
                            <ul style="max-height:250px;overflow:auto;border:1px solid #ccc;padding:10px;background:#fff;">
                                <?php foreach ($chunk_files as $file) :
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
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                            <?php wp_nonce_field('octopus_ai_clear_all_chunks'); ?>
                            <input type="hidden" name="action" value="octopus_ai_clear_all_chunks">
                            <?php submit_button('🧨 Verwijder ALLE sitemap chunks', 'delete', '', false); ?>
                        </form>
                    <?php else : ?>
                        <p><em>Er zijn momenteel geen sitemap chunks opgeslagen.</em></p>
                    <?php endif; ?>
                <?php else : ?>
                    <p><em>De chunks-folder bestaat nog niet.</em></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
    document.addEventListener('DOMContentLoaded', function () {
        const displayMode = document.getElementById('octopus_ai_display_mode');
        const pageRow = document.getElementById('octopus_ai_page_selector_row');
        if (displayMode && pageRow) {
            displayMode.addEventListener('change', function () {
                pageRow.style.display = this.value === 'selected' ? '' : 'none';
            });
        }

        const strategyInputs = document.querySelectorAll('input[name="octopus_ai_source_strategy"]');
        const cards = document.querySelectorAll('.source-mode-card');
        const panels = document.querySelectorAll('.source-mode-panel[data-mode]');
        const groupedPanels = document.querySelectorAll('.source-mode-panel[data-mode-group]');
        const manualModeField = document.getElementById('octopus_ai_manual_mode_hidden');

        function activateStrategy(value) {
            cards.forEach(card => {
                if (card.dataset.target) {
                    card.classList.toggle('is-active', card.dataset.target === value);
                }
            });
            panels.forEach(panel => {
                panel.classList.toggle('is-active', panel.dataset.mode === value);
            });
            groupedPanels.forEach(panel => {
                const list = panel.dataset.modeGroup ? panel.dataset.modeGroup.split(',') : [];
                panel.classList.toggle('is-active', list.includes(value));
            });
            if (manualModeField) {
                manualModeField.value = value === 'live_manual' ? 'live' : 'local';
            }
        }

        strategyInputs.forEach(input => {
            input.addEventListener('change', () => activateStrategy(input.value));
        });

        const initial = document.querySelector('input[name="octopus_ai_source_strategy"]:checked');
        if (initial) {
            activateStrategy(initial.value);
        }
    });
    </script>
<?php }
