<?php
// Veiligheid
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convert shorthand memory values (e.g. 256M) to bytes.
 *
 * @param string|int $value
 * @return int
 */
if (!function_exists('octopus_ai_hr_to_bytes')) {
    function octopus_ai_hr_to_bytes($value)
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0;
        }

        if ($raw === '-1') {
            return -1;
        }

        $unit = strtolower(substr($raw, -1));
        $bytes = (float) $raw;
        switch ($unit) {
            case 'g':
                $bytes *= 1024;
                // no break
            case 'm':
                $bytes *= 1024;
                // no break
            case 'k':
                $bytes *= 1024;
                break;
        }

        return (int) max(0, $bytes);
    }
}

if (!function_exists('octopus_ai_get_memory_limit_bytes')) {
    function octopus_ai_get_memory_limit_bytes()
    {
        return octopus_ai_hr_to_bytes(ini_get('memory_limit'));
    }
}

if (!function_exists('octopus_ai_get_safe_pdf_max_bytes')) {
    function octopus_ai_get_safe_pdf_max_bytes()
    {
        $mb = 1024 * 1024;
        $memory_limit = octopus_ai_get_memory_limit_bytes();

        if ($memory_limit === -1) {
            // Onbeperkt: hou alsnog een pragmatische limiet aan.
            return 35 * $mb;
        }

        if ($memory_limit <= 0) {
            return 8 * $mb;
        }

        $usage = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $reserve = 80 * $mb;
        $headroom = max(0, $memory_limit - $usage - $reserve);
        $dynamic = (int) floor($headroom / 4); // parser kan sterk expanden

        $tier_cap = 3 * $mb;
        if ($memory_limit >= 512 * $mb) {
            $tier_cap = 18 * $mb;
        } elseif ($memory_limit >= 384 * $mb) {
            $tier_cap = 12 * $mb;
        } elseif ($memory_limit >= 256 * $mb) {
            $tier_cap = 4 * $mb;
        }

        $max_bytes = min($tier_cap, max(2 * $mb, $dynamic));
        $max_bytes = (int) max(2 * $mb, $max_bytes);

        if (function_exists('apply_filters')) {
            $max_bytes = (int) apply_filters('octopus_ai_safe_pdf_max_bytes', $max_bytes, $memory_limit, $usage);
        }

        return (int) max(1 * $mb, $max_bytes);
    }
}

if (!function_exists('octopus_ai_validate_pdf_for_smalot')) {
    function octopus_ai_validate_pdf_for_smalot($file_path)
    {
        $file_path = (string) $file_path;
        if ($file_path === '' || !file_exists($file_path)) {
            return new WP_Error('octopus_ai_pdf_missing', 'PDF-bestand niet gevonden.');
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $file_size = (int) @filesize($file_path);
        if ($file_size <= 0) {
            return new WP_Error('octopus_ai_pdf_empty', 'PDF-bestand is leeg of onleesbaar.');
        }

        $max_bytes = octopus_ai_get_safe_pdf_max_bytes();
        if ($file_size > $max_bytes) {
            $file_human = function_exists('size_format') ? size_format($file_size, 2) : ($file_size . ' bytes');
            $max_human = function_exists('size_format') ? size_format($max_bytes, 2) : ($max_bytes . ' bytes');
            return new WP_Error(
                'octopus_ai_pdf_too_large',
                sprintf(
                    'PDF is te groot voor veilige verwerking op deze server (%1$s > %2$s). Verhoog memory_limit of gebruik een kleiner bestand.',
                    $file_human,
                    $max_human
                )
            );
        }

        return true;
    }
}

if (!function_exists('octopus_ai_extract_pdf_text_with_pdftotext')) {
    function octopus_ai_extract_pdf_text_with_pdftotext($file_path)
    {
        if (!function_exists('shell_exec')) {
            return '';
        }

        $file_path = (string) $file_path;
        if ($file_path === '' || !file_exists($file_path)) {
            return '';
        }

        $command = 'pdftotext -enc UTF-8 ' . escapeshellarg($file_path) . ' -';
        $output = @shell_exec($command);
        if (!is_string($output) || trim($output) === '') {
            return '';
        }

        return trim($output);
    }
}

if (!function_exists('octopus_ai_extract_pdf_text_safely')) {
    /**
     * Extract text from PDF with guarded parser usage.
     *
     * @param string $file_path
     * @param bool   $allow_smalot
     * @return string|WP_Error
     */
    function octopus_ai_extract_pdf_text_safely($file_path, $allow_smalot = true)
    {
        $file_path = (string) $file_path;
        if ($file_path === '' || !file_exists($file_path)) {
            return new WP_Error('octopus_ai_pdf_missing', 'PDF-bestand niet gevonden.');
        }

        $shell_text = octopus_ai_extract_pdf_text_with_pdftotext($file_path);
        if ($shell_text !== '') {
            return $shell_text;
        }

        if (!$allow_smalot) {
            return new WP_Error(
                'octopus_ai_pdf_no_parser',
                'Geen veilige PDF parser beschikbaar op deze server (pdftotext ontbreekt en Smalot is uitgeschakeld).'
            );
        }

        $guard = octopus_ai_validate_pdf_for_smalot($file_path);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $autoload_path = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoload_path)) {
            require_once $autoload_path;
        }

        if (!class_exists('\Smalot\PdfParser\Parser')) {
            return new WP_Error(
                'octopus_ai_pdf_parser_missing',
                'PDF parser ontbreekt op de server (vendor/autoload.php of Smalot parser niet beschikbaar).'
            );
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file_path);
            $text = trim((string) $pdf->getText());
            if ($text === '') {
                return new WP_Error('octopus_ai_pdf_no_text', 'PDF bevat geen tekst die gelezen kon worden.');
            }
            return $text;
        } catch (\Throwable $e) {
            return new WP_Error(
                'octopus_ai_pdf_parse_failed',
                'Fout tijdens PDF parsing: ' . $e->getMessage()
            );
        }
    }
}

/**
 * Backward compatible wrapper.
 *
 * @param string $file_path
 * @return string
 */
if (!function_exists('octopus_parse_pdf_to_text')) {
    function octopus_parse_pdf_to_text($file_path)
    {
        $result = octopus_ai_extract_pdf_text_safely($file_path, true);
        if (is_wp_error($result)) {
            return (string) $result->get_error_message();
        }

        return (string) $result;
    }
}
