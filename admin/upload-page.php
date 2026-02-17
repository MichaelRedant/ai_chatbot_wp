<?php
// Veiligheid
if (!defined('ABSPATH')) exit;

if (!function_exists('octopus_ai_parse_csv_to_qa_text')) {
    function octopus_ai_parse_csv_to_qa_text($raw_text)
    {
        $raw_text = (string) $raw_text;
        if ($raw_text === '') {
            return '';
        }

        $rows = preg_split('/\r\n|\r|\n/', $raw_text);
        $rows = is_array($rows) ? $rows : [];
        $rows = array_values(array_filter(array_map('trim', $rows), static function ($line) {
            return $line !== '';
        }));

        if (empty($rows)) {
            return '';
        }

        $delimiter = ',';
        $first_row = $rows[0];
        $delimiter_scores = array(
            ';' => substr_count($first_row, ';'),
            ',' => substr_count($first_row, ','),
            "\t" => substr_count($first_row, "\t"),
        );
        arsort($delimiter_scores);
        $best_delimiter = array_key_first($delimiter_scores);
        if (is_string($best_delimiter) && $best_delimiter !== '') {
            $delimiter = $best_delimiter;
        }

        $header = str_getcsv($rows[0], $delimiter);
        $header_norm = array_map(static function ($col) {
            return strtolower(trim((string) $col));
        }, is_array($header) ? $header : []);

        $question_headers = array('vraag', 'question', 'question_fr', 'question_nl');
        $answer_headers = array('antwoord', 'answer', 'reponse', 'answer_fr', 'answer_nl');

        $question_idx = -1;
        $answer_idx = -1;
        foreach ($header_norm as $idx => $column) {
            if ($question_idx < 0 && in_array($column, $question_headers, true)) {
                $question_idx = (int) $idx;
            }
            if ($answer_idx < 0 && in_array($column, $answer_headers, true)) {
                $answer_idx = (int) $idx;
            }
        }

        $start_index = 0;
        if ($question_idx >= 0 || $answer_idx >= 0) {
            $start_index = 1;
            if ($question_idx < 0) {
                $question_idx = 0;
            }
            if ($answer_idx < 0) {
                $answer_idx = 1;
            }
        } else {
            $question_idx = 0;
            $answer_idx = 1;
        }

        $blocks = array();
        for ($i = $start_index; $i < count($rows); $i++) {
            $row = str_getcsv($rows[$i], $delimiter);
            if (!is_array($row) || empty($row)) {
                continue;
            }

            $question = trim((string) ($row[$question_idx] ?? ''));
            $answer = trim((string) ($row[$answer_idx] ?? ''));

            if ($question === '' && $answer === '') {
                continue;
            }
            if ($question === '') {
                $question = 'Vraag niet gespecificeerd';
            }

            $block = "Vraag: " . $question;
            if ($answer !== '') {
                $block .= "\nAntwoord: " . $answer;
            }
            $blocks[] = $block;
        }

        return implode("\n\n", $blocks);
    }
}

if (!function_exists('octopus_ai_extract_text_from_uploaded_file')) {
    function octopus_ai_extract_text_from_uploaded_file($file_path, $original_name)
    {
        $file_path = (string) $file_path;
        $original_name = (string) $original_name;
        $extension = strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = strtolower((string) pathinfo($file_path, PATHINFO_EXTENSION));
        }

        $allowed_extensions = array('pdf', 'txt', 'md', 'markdown', 'csv');
        if (!in_array($extension, $allowed_extensions, true)) {
            return new WP_Error(
                'octopus_ai_unsupported_extension',
                'Niet ondersteund bestandstype. Gebruik PDF, TXT, MD of CSV.'
            );
        }

        if ($extension === 'pdf') {
            if (!function_exists('octopus_ai_extract_pdf_text_safely')) {
                $pdf_helper = __DIR__ . '/../includes/pdf-parser.php';
                if (file_exists($pdf_helper)) {
                    require_once $pdf_helper;
                }
            }

            if (function_exists('octopus_ai_extract_pdf_text_safely')) {
                $result = octopus_ai_extract_pdf_text_safely($file_path, true);
                if (is_wp_error($result)) {
                    return $result;
                }
                return trim((string) $result);
            }

            return new WP_Error(
                'octopus_ai_pdf_helper_missing',
                'PDF helper niet beschikbaar. Controleer pluginbestanden en probeer opnieuw.'
            );
        }

        $raw_text = @file_get_contents($file_path);
        if ($raw_text === false) {
            return new WP_Error('octopus_ai_text_read_failed', 'Kon het tekstbestand niet lezen.');
        }

        $text = (string) $raw_text;
        if ($text !== '' && function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $encoding = mb_detect_encoding($text, array('UTF-8', 'Windows-1252', 'ISO-8859-1'), true);
            if (is_string($encoding) && strtoupper($encoding) !== 'UTF-8') {
                $converted = @mb_convert_encoding($text, 'UTF-8', $encoding);
                if ($converted !== false) {
                    $text = $converted;
                }
            }
        }

        $text = preg_replace("/^\xEF\xBB\xBF/", '', $text);
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);

        if ($extension === 'csv') {
            $text = octopus_ai_parse_csv_to_qa_text($text);
        }

        return trim((string) $text);
    }
}

add_action('admin_menu', function() {
    add_submenu_page(
        'octopus-ai-chatbot',
        'Kennisbeheer',
        'Kennisbeheer',
        'manage_options',
        'octopus_ai_pdf_beheer',
        'octopus_ai_pdf_upload_page'
    );
});

function octopus_ai_pdf_upload_page() {
    if (isset($_POST['octopus_pdf_upload_nonce']) && wp_verify_nonce($_POST['octopus_pdf_upload_nonce'], 'octopus_pdf_upload')) {
        if (!empty($_FILES['octopus_pdf_file']['name'])) {
            $uploaded = wp_handle_upload($_FILES['octopus_pdf_file'], array(
                'test_form' => false,
                'test_type' => false,
            ));

            if (!isset($uploaded['error'])) {
                $file_path = $uploaded['file'];
                $original_name = (string) ($_FILES['octopus_pdf_file']['name'] ?? basename($file_path));
                $text = octopus_ai_extract_text_from_uploaded_file($file_path, $original_name);
                if (is_wp_error($text)) {
                    if (file_exists($file_path)) {
                        @unlink($file_path);
                    }
                    echo '<div class="error"><p>' . esc_html($text->get_error_message()) . '</p></div>';
                    return;
                }

                if (strlen(trim((string) $text)) < 20) {
                    if (file_exists($file_path)) {
                        @unlink($file_path);
                    }
                    echo '<div class="error"><p>Te weinig tekst gevonden om bruikbare chunks op te bouwen.</p></div>';
                    return;
                }

                // Chunking
                $chunks = str_split($text, 1000);
                $upload_dir = wp_upload_dir();
                $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
                if (!file_exists($chunks_dir)) {
                    wp_mkdir_p($chunks_dir);
                }

                $slug = sanitize_title((string) pathinfo($original_name, PATHINFO_FILENAME));
                if ($slug === '') {
                    $slug = 'kennisbron-' . time();
                }

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
                            'source_type' => strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION)),
                        ],
                    ];
                    file_put_contents($chunk_file, wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                }

                echo '<div class="updated"><p>Upload en verwerking gelukt. Chunks opgeslagen in: ' . esc_html($chunks_dir) . '</p></div>';
            } else {
                echo '<div class="error"><p>Upload fout: ' . esc_html($uploaded['error']) . '</p></div>';
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>Kennisbeheer</h1>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('octopus_pdf_upload', 'octopus_pdf_upload_nonce'); ?>
            <p><label for="octopus_pdf_file">Upload een kennisbestand (PDF, TXT, MD of CSV met vraag/antwoord):</label></p>
            <input type="file" id="octopus_pdf_file" name="octopus_pdf_file" accept=".pdf,.txt,.md,.markdown,.csv,text/plain,text/csv,application/pdf" required>
            <p><input type="submit" class="button button-primary" value="Upload en Verwerk Kennisbron"></p>
        </form>
    </div>
    <?php
}
