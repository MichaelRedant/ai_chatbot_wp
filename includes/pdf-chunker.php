<?php
// Veiligheid
if (!defined('ABSPATH')) exit;

/**
 * PDF tekst chunken en opslaan
 */
function octopus_chunk_pdf_text($text, $chunk_size = 2000) {
    $upload_dir = wp_upload_dir();
    $chunks_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

    if (!file_exists($chunks_dir)) {
        wp_mkdir_p($chunks_dir);
    }

    // Oude chunks verwijderen
    $old_chunks = glob($chunks_dir . '*.txt');
    foreach ($old_chunks as $old_chunk) {
        unlink($old_chunk);
    }

    // Splits de tekst in stukken
    $chunks = str_split($text, $chunk_size);
    foreach ($chunks as $index => $chunk) {
        $chunk_file = $chunks_dir . 'chunk_' . ($index + 1) . '.txt';
        file_put_contents($chunk_file, $chunk);
    }

    return count($chunks);
}
?>
