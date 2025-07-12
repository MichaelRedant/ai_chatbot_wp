<?php
// Veiligheid
if (!defined('ABSPATH')) exit;

/**
 * Parse PDF naar platte tekst
 * @param string $file_path
 * @return string
 */
function octopus_parse_pdf_to_text($file_path) {
    // Composer autoload
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    } else {
        return "PDF extractie niet mogelijk: Autoloader ontbreekt in vendor map.";
    }

    // Probeer eerst via pdftotext shell command
    if (function_exists('shell_exec')) {
        $output = @shell_exec("pdftotext " . escapeshellarg($file_path) . " -");
        if (!empty($output)) {
            return $output;
        }
    }

    // Fallback naar Smalot PDF Parser
    if (class_exists('\Smalot\PdfParser\Parser')) {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file_path);
            $text = $pdf->getText();
            return $text ?: 'PDF bevat geen tekst die gelezen kon worden.';
        } catch (Exception $e) {
            return 'Er trad een fout op bij het lezen van de PDF: ' . $e->getMessage();
        }
    } else {
        return "Smalot PDF Parser niet gevonden ondanks autoload poging.";
    }

    // Indien niets werkt
    return "PDF extractie is niet beschikbaar op deze server. Zorg dat pdftotext geïnstalleerd is of Smalot PDF Parser correct is geconfigureerd.";
}
