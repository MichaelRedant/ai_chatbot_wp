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

if (!function_exists('octopus_ai_decode_pdf_literal_string')) {
    function octopus_ai_decode_pdf_literal_string($token)
    {
        $token = (string) $token;
        if ($token === '') {
            return '';
        }

        $first = substr($token, 0, 1);
        $last = substr($token, -1);
        if ($first === '(' && $last === ')' && strlen($token) >= 2) {
            $token = substr($token, 1, -1);
        }

        // Octale escapes (\053)
        $token = preg_replace_callback('/\\\\([0-7]{1,3})/', static function ($match) {
            $value = octdec((string) $match[1]);
            return chr(max(0, min(255, $value)));
        }, $token);

        $token = strtr($token, array(
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\b' => "\x08",
            '\\f' => "\x0c",
            '\\(' => '(',
            '\\)' => ')',
            '\\\\' => '\\',
        ));

        return (string) $token;
    }
}

if (!function_exists('octopus_ai_decode_pdf_hex_string')) {
    function octopus_ai_decode_pdf_hex_string($token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return '';
        }

        if (substr($token, 0, 1) === '<' && substr($token, -1) === '>') {
            $token = substr($token, 1, -1);
        }

        $token = preg_replace('/\s+/', '', (string) $token);
        if ($token === '' || preg_match('/^[0-9A-Fa-f]+$/', $token) !== 1) {
            return '';
        }

        if ((strlen($token) % 2) === 1) {
            $token .= '0';
        }

        $decoded = @hex2bin($token);
        if (!is_string($decoded)) {
            return '';
        }

        return $decoded;
    }
}

if (!function_exists('octopus_ai_filter_pdf_text_fragment')) {
    function octopus_ai_filter_pdf_text_fragment($fragment)
    {
        $fragment = (string) $fragment;
        if ($fragment === '') {
            return '';
        }

        $fragment = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/u', ' ', $fragment);
        $fragment = preg_replace('/\s+/u', ' ', (string) $fragment);
        $fragment = trim((string) $fragment);
        if ($fragment === '') {
            return '';
        }

        if (!preg_match('/\p{L}/u', $fragment)) {
            return '';
        }

        $len = function_exists('mb_strlen') ? (int) mb_strlen($fragment) : strlen($fragment);
        if ($len < 2 || $len > 800) {
            return '';
        }

        return $fragment;
    }
}

if (!function_exists('octopus_ai_extract_text_from_pdf_stream_content')) {
    function octopus_ai_extract_text_from_pdf_stream_content($content)
    {
        $content = (string) $content;
        if ($content === '') {
            return array();
        }

        $fragments = array();
        $blocks = array();
        if (preg_match_all('/BT(.*?)ET/s', $content, $matches) && !empty($matches[1])) {
            $blocks = $matches[1];
        } else {
            $blocks = array($content);
        }

        foreach ($blocks as $block) {
            $block = (string) $block;
            if ($block === '') {
                continue;
            }

            if (preg_match_all('/\((?:\\\\.|[^\\\\\)])*\)\s*(?:Tj|TJ|\'|")/s', $block, $literal_matches)) {
                foreach ((array) $literal_matches[0] as $match) {
                    if (preg_match('/\((?:\\\\.|[^\\\\\)])*\)/s', (string) $match, $string_token)) {
                        $decoded = octopus_ai_decode_pdf_literal_string((string) $string_token[0]);
                        $filtered = octopus_ai_filter_pdf_text_fragment($decoded);
                        if ($filtered !== '') {
                            $fragments[] = $filtered;
                        }
                    }
                }
            }

            if (preg_match_all('/<([0-9A-Fa-f\s]{4,})>\s*(?:Tj|TJ|\'|")/s', $block, $hex_matches)) {
                foreach ((array) $hex_matches[1] as $hex_payload) {
                    $decoded = octopus_ai_decode_pdf_hex_string('<' . (string) $hex_payload . '>');
                    $filtered = octopus_ai_filter_pdf_text_fragment($decoded);
                    if ($filtered !== '') {
                        $fragments[] = $filtered;
                    }
                }
            }
        }

        return $fragments;
    }
}

if (!function_exists('octopus_ai_try_inflate_pdf_stream')) {
    function octopus_ai_try_inflate_pdf_stream($raw_stream)
    {
        $raw_stream = (string) $raw_stream;
        if ($raw_stream === '') {
            return '';
        }

        $candidates = array($raw_stream, ltrim($raw_stream, "\r\n"));
        foreach ($candidates as $candidate) {
            $decoded = @gzuncompress($candidate);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }

            $decoded = @gzinflate($candidate);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }

            if (strlen($candidate) > 2) {
                $decoded = @gzinflate(substr($candidate, 2));
                if (is_string($decoded) && $decoded !== '') {
                    return $decoded;
                }
            }
        }

        return '';
    }
}

if (!function_exists('octopus_ai_extract_pdf_text_from_raw_streams')) {
    function octopus_ai_extract_pdf_text_from_raw_streams($file_path)
    {
        $file_path = (string) $file_path;
        if ($file_path === '' || !file_exists($file_path)) {
            return '';
        }

        $mb = 1024 * 1024;
        $memory_limit = function_exists('octopus_ai_get_memory_limit_bytes')
            ? (int) octopus_ai_get_memory_limit_bytes()
            : 0;
        $default_max = 30 * $mb;
        if ($memory_limit > 0) {
            $default_max = max(8 * $mb, min(60 * $mb, (int) floor($memory_limit / 2)));
        }
        $max_read_bytes = (int) apply_filters('octopus_ai_raw_pdf_extractor_max_bytes', $default_max, $memory_limit);
        $max_read_bytes = max(4 * $mb, $max_read_bytes);

        $file_size = (int) @filesize($file_path);
        if ($file_size <= 0 || $file_size > $max_read_bytes) {
            return '';
        }

        $raw_pdf = @file_get_contents($file_path);
        if (!is_string($raw_pdf) || $raw_pdf === '') {
            return '';
        }

        $fragments = array();
        if (preg_match_all('/stream[\r\n]+(.*?)endstream/s', $raw_pdf, $stream_matches) && !empty($stream_matches[1])) {
            foreach ((array) $stream_matches[1] as $stream_content) {
                $stream_content = (string) $stream_content;
                if ($stream_content === '') {
                    continue;
                }

                $decoded_variants = array($stream_content);
                $inflated = octopus_ai_try_inflate_pdf_stream($stream_content);
                if ($inflated !== '') {
                    $decoded_variants[] = $inflated;
                }

                foreach ($decoded_variants as $variant) {
                    $variant_fragments = octopus_ai_extract_text_from_pdf_stream_content($variant);
                    if (!empty($variant_fragments)) {
                        $fragments = array_merge($fragments, $variant_fragments);
                    }
                }
            }
        }

        // Fallback op hele file als stream-detectie niets opleverde.
        if (empty($fragments)) {
            if (preg_match_all('/\((?:\\\\.|[^\\\\\)]){2,}\)/s', $raw_pdf, $raw_tokens)) {
                foreach ((array) $raw_tokens[0] as $token) {
                    $decoded = octopus_ai_decode_pdf_literal_string((string) $token);
                    $filtered = octopus_ai_filter_pdf_text_fragment($decoded);
                    if ($filtered !== '') {
                        $fragments[] = $filtered;
                    }
                }
            }
        }

        if (empty($fragments)) {
            return '';
        }

        $unique = array();
        $text_parts = array();
        foreach ($fragments as $fragment) {
            $key = strtolower((string) $fragment);
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = true;
            $text_parts[] = $fragment;
            if (count($text_parts) >= 6000) {
                break;
            }
        }

        $text = trim(implode("\n", $text_parts));
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\n{3,}/', "\n\n", (string) $text);
        return trim((string) $text);
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

        $raw_stream_text = octopus_ai_extract_pdf_text_from_raw_streams($file_path);
        if ($raw_stream_text !== '') {
            return $raw_stream_text;
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
