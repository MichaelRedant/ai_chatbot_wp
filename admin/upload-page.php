<?php
// Veiligheid
if (!defined('ABSPATH')) exit;

use Smalot\PdfParser\Parser;

add_action('admin_menu', function() {
    add_submenu_page(
        'octopus_ai_chatbot',
        'PDF Beheer',
        'PDF Beheer',
        'manage_options',
        'octopus_ai_pdf_beheer',
        'octopus_ai_pdf_upload_page'
    );
});

function octopus_ai_pdf_upload_page() {
    if (isset($_POST['octopus_pdf_upload_nonce']) && wp_verify_nonce($_POST['octopus_pdf_upload_nonce'], 'octopus_pdf_upload')) {
        if (!empty($_FILES['octopus_pdf_file']['name'])) {
            require_once __DIR__ . '/../vendor/autoload.php';

            $uploaded = wp_handle_upload($_FILES['octopus_pdf_file'], array('test_form' => false));

            if (!isset($uploaded['error'])) {
                $file_path = $uploaded['file'];
                $parser = new Parser();
                $pdf = $parser->parseFile($file_path);
                $text = $pdf->getText();

                // Chunking
                $chunks = str_split($text, 1000);
                $upload_dir = wp_upload_dir();
                $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
                if (!file_exists($chunks_dir)) {
                    wp_mkdir_p($chunks_dir);
                }

                $slug = basename($file_path, '.pdf');
                foreach (glob($chunks_dir . $slug . '_chunk_*.json') as $old) {
                    unlink($old);
                }

                foreach ($chunks as $index => $chunk) {
                    $chunk_file = $chunks_dir . $slug . '_chunk_' . $index . '.json';
                    $data = [
                        'content'  => $chunk,
                        'metadata' => [
                            'source_title' => $slug,
                            'page_slug'     => $slug . '-p' . ($index + 1),
                            'original_page' => $index + 1,
                            'section_title' => '',
                        ],
                    ];
                    file_put_contents($chunk_file, wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }

                echo '<div class="updated"><p>Upload en parsing gelukt. Chunks opgeslagen in: ' . esc_html($chunks_dir) . '</p></div>';
            } else {
                echo '<div class="error"><p>Upload fout: ' . esc_html($uploaded['error']) . '</p></div>';
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>PDF Beheer</h1>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('octopus_pdf_upload', 'octopus_pdf_upload_nonce'); ?>
            <p><label for="octopus_pdf_file">Selecteer een PDF om te uploaden en te verwerken:</label></p>
            <input type="file" id="octopus_pdf_file" name="octopus_pdf_file" accept="application/pdf" required>
            <p><input type="submit" class="button button-primary" value="Upload en Verwerk PDF"></p>
        </form>
    </div>
    <?php
}
