<?php
namespace OctopusAI\Includes;

if (!defined('ABSPATH')) exit;

$autoload_path = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
}

use Smalot\PdfParser\Parser;

if (!class_exists('OctopusAI\Includes\Chunker')) {

class Chunker
{
    private $chunkSize;
    private $overlap;
    private $lastError;

    public function __construct($chunkSize = 800, $overlap = 100)
    {
        $this->chunkSize = $chunkSize;
        $this->overlap = $overlap;
        $this->lastError = '';
    }

    /**
     * Laatste fouttekst van de meest recente chunkpoging.
     */
    public function getLastError(): string
    {
        return (string) $this->lastError;
    }

    private function setLastError(string $message): void
    {
        $this->lastError = trim($message);
    }

    /**
     * Chunk een PDF-bestand met metadata.
     *
     * @param string $filePath Volledig pad naar het PDF-bestand.
     * @param string $sourceId Slug of bestandsnaam zonder extensie.
     * @param string $fileUrl Publieke URL naar het PDF-bestand.
     * @return array
     */
    public function chunkPdfWithMetadata(string $filePath, string $sourceId, string $fileUrl): array
    {
        $this->setLastError('');
        $sourceTitle = (string) pathinfo($filePath, PATHINFO_FILENAME);
        if ($sourceTitle === '') {
            $sourceTitle = basename((string) $filePath);
        }
        if (!file_exists($filePath)) {
            $message = 'PDF bestand niet gevonden: ' . $filePath;
            $this->setLastError($message);
            error_log('[Octopus AI] ' . $message);
            return [];
        }

        $pages = [];
        $can_use_smalot = class_exists(Parser::class);
        $smalot_guard_failed = false;
        if ($can_use_smalot && function_exists('octopus_ai_validate_pdf_for_smalot')) {
            $guard = \octopus_ai_validate_pdf_for_smalot($filePath);
            if (\is_wp_error($guard)) {
                $can_use_smalot = false;
                $smalot_guard_failed = true;
                $message = 'PDF parser guard: ' . $guard->get_error_message();
                $this->setLastError($message);
                error_log('[Octopus AI] ' . $message);
            }
        }

        if ($can_use_smalot) {
            try {
                $parser = new Parser();
                $pdf = $parser->parseFile($filePath);
                $pages = $pdf->getPages();
            } catch (\Throwable $e) {
                $message = 'PDF parsing mislukt: ' . $e->getMessage();
                $this->setLastError($message);
                error_log('[Octopus AI] ' . $message);
                $pages = [];
            }
        }

        $chunks = [];
        $chunkSequence = 0;

        if (!empty($pages)) {
            foreach ($pages as $i => $page) {
                $rawPageText = trim((string) $page->getText());
                $text = $this->normalizeChunkText($rawPageText);
                if (empty($text)) {
                    continue;
                }

                $pageNumber = $i + 1;
                $pageSlug = $this->generatePageSlug($sourceTitle, $pageNumber);
                $sectionTitle = $this->detectSectionTitle($rawPageText);

                $source_url = $fileUrl !== '' ? ($fileUrl . '#page=' . $pageNumber) : '';
                $page_chunks = $this->chunkTextWithMetadata(
                    $text,
                    $sourceTitle,
                    $source_url,
                    [
                        'page_slug'     => $pageSlug,
                        'original_page' => (string) $pageNumber,
                        'section_title' => (string) $sectionTitle,
                        'manual_url'    => '',
                        'source_type'   => 'pdf',
                        'source_id'     => (string) $sourceId,
                    ]
                );

                foreach ($page_chunks as $page_chunk) {
                    if (!isset($page_chunk['metadata']) || !is_array($page_chunk['metadata'])) {
                        $page_chunk['metadata'] = [];
                    }

                    $chunkSequence++;
                    $page_chunk['metadata']['chunk_index'] = $chunkSequence;
                    $chunks[] = $page_chunk;
                }
            }

            if (!empty($chunks)) {
                return $chunks;
            }

            $this->setLastError('Geen leesbare tekst per pagina gevonden; probeer documentniveau-extractie.');
        }

        // Fallback: lees volledige tekst en chunk generiek.
        if (!function_exists('octopus_ai_extract_pdf_text_safely')) {
            $message = 'PDF helper ontbreekt, fallback chunking kan niet uitgevoerd worden.';
            $this->setLastError($message);
            error_log('[Octopus AI] ' . $message);
            return [];
        }

        // Als Smalot guard faalde, forceer pdftotext-only fallback om memory issues te vermijden.
        $allow_smalot_fallback = !$smalot_guard_failed;
        $fallback_text = \octopus_ai_extract_pdf_text_safely($filePath, $allow_smalot_fallback);
        if (\is_wp_error($fallback_text)) {
            $message = 'PDF fallback extractie mislukt: ' . $fallback_text->get_error_message();
            $this->setLastError($message);
            error_log('[Octopus AI] ' . $message);
            return [];
        }

        $fallback_text = trim((string) $fallback_text);
        if ($fallback_text === '') {
            $this->setLastError('PDF bevat geen leesbare tekst.');
            return [];
        }

        return $this->buildChunksFromPlainText($fallback_text, $sourceTitle, $fileUrl);
    }

    /**
     * Herbruikbare tekstchunking voor PDF/TXT/MD/CSV/sitemap.
     */
    public function chunkTextWithMetadata(string $text, string $sourceTitle, string $sourceUrl = '', array $baseMetadata = []): array
    {
        $this->setLastError('');
        $sourceTitle = trim((string) $sourceTitle);
        if ($sourceTitle === '') {
            $sourceTitle = 'kennisbron';
        }

        $normalizedText = $this->normalizeChunkText($text);
        if ($normalizedText === '') {
            $this->setLastError('Geen tekst gevonden om te chunken.');
            return [];
        }

        $splitChunks = $this->splitTextIntoChunks($normalizedText, $this->chunkSize);
        if (empty($splitChunks)) {
            $this->setLastError('Geen bruikbare chunks gevonden.');
            return [];
        }

        $sourceType = isset($baseMetadata['source_type']) ? sanitize_key((string) $baseMetadata['source_type']) : 'text';
        if ($sourceType === '') {
            $sourceType = 'text';
        }

        $manualUrl = isset($baseMetadata['manual_url']) ? esc_url_raw((string) $baseMetadata['manual_url']) : '';
        $resolvedSourceUrl = $sourceUrl !== ''
            ? esc_url_raw((string) $sourceUrl)
            : esc_url_raw((string) ($baseMetadata['source_url'] ?? ''));

        $chunks = [];
        foreach ($splitChunks as $index => $chunkText) {
            $chunkText = $this->normalizeChunkText((string) $chunkText);
            if ($chunkText === '') {
                continue;
            }

            $chunkNumber = $index + 1;
            $sectionTitle = trim((string) ($baseMetadata['section_title'] ?? ''));
            if ($sectionTitle === '') {
                $sectionTitle = (string) ($this->detectSectionTitle($chunkText) ?? '');
            }

            $pageSlug = trim((string) ($baseMetadata['page_slug'] ?? ''));
            if ($pageSlug === '') {
                $pageSlug = $this->generatePageSlug($sourceTitle, $chunkNumber);
            }

            $originalPage = trim((string) ($baseMetadata['original_page'] ?? ''));
            if ($originalPage === '') {
                $originalPage = (string) $chunkNumber;
            }

            $indexTerms = [];
            if (!empty($baseMetadata['index_terms']) && is_array($baseMetadata['index_terms'])) {
                $indexTerms = $this->mergeUniqueTerms($indexTerms, $baseMetadata['index_terms']);
            }
            $indexTerms = $this->mergeUniqueTerms($indexTerms, $this->buildTermIndex($sourceTitle, 6));
            $indexTerms = $this->mergeUniqueTerms($indexTerms, $this->buildTermIndex($sectionTitle, 6));
            $indexTerms = $this->mergeUniqueTerms($indexTerms, $this->buildTermIndex($chunkText, 14), 18);

            $metadata = $baseMetadata;
            $metadata['source_title'] = $sourceTitle;
            $metadata['page_slug'] = $pageSlug;
            $metadata['original_page'] = $originalPage;
            $metadata['section_title'] = $sectionTitle;
            $metadata['source_url'] = $resolvedSourceUrl;
            $metadata['manual_url'] = $manualUrl;
            $metadata['source_type'] = $sourceType;
            $metadata['chunk_index'] = $chunkNumber;
            $metadata['index_terms'] = $indexTerms;

            $chunks[] = [
                'content' => $chunkText,
                'metadata' => $metadata,
            ];
        }

        if (empty($chunks)) {
            $this->setLastError('Alle chunks waren leeg na normalisatie.');
        }

        return $chunks;
    }

    /**
     * Genereer slug voor handleiding-link.
     */
    private function generatePageSlug(string $title, int $pageNumber): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
        $slug = trim($slug, '-');
        return $slug . '-p' . $pageNumber;
    }

    /**
     * Detecteer sectietitel bovenaan pagina.
     */
    private function detectSectionTitle(string $text): ?string
    {
        $normalized = str_replace(array("\r\n", "\r"), "\n", (string) $text);
        $lines = preg_split('/\n+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($lines)) {
            return null;
        }

        foreach ($lines as $line) {
            $candidate = trim((string) preg_replace('/\s+/u', ' ', (string) $line));
            if ($candidate === '') {
                continue;
            }

            $length = function_exists('mb_strlen') ? (int) mb_strlen($candidate) : strlen($candidate);
            if ($length < 5 || $length > 140) {
                continue;
            }

            if (preg_match('/^(pagina|page)\s+\d+/iu', $candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Split text into manageable chunks.
     */
    private function splitTextIntoChunks(string $text, int $maxTokens = 750): array
    {
        $text = $this->normalizeChunkText($text);
        if ($text === '') {
            return [];
        }

        $maxChars = max(900, (int) floor($maxTokens * 4));
        $overlapChars = max(80, min((int) floor($maxChars * 0.35), (int) ($this->overlap * 4)));

        $blocks = $this->splitTextIntoSemanticBlocks($text);
        if (!is_array($blocks) || empty($blocks)) {
            $blocks = [$text];
        }

        $segments = [];
        foreach ($blocks as $block) {
            $block = $this->normalizeChunkText((string) $block);
            if ($block === '') {
                continue;
            }

            $blockLength = function_exists('mb_strlen') ? (int) mb_strlen($block) : strlen($block);
            if ($blockLength > (int) floor($maxChars * 1.35)) {
                $segments = array_merge($segments, $this->splitLongTextBySentence($block, $maxChars));
            } else {
                $segments[] = $block;
            }
        }

        if (empty($segments)) {
            return [];
        }

        $chunks = [];
        $current = '';

        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '') {
                continue;
            }

            $candidate = $current === '' ? $segment : ($current . "\n\n" . $segment);
            $candidateLength = function_exists('mb_strlen') ? (int) mb_strlen($candidate) : strlen($candidate);

            if ($candidateLength <= $maxChars) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $chunks[] = trim($current);
            }

            $overlapText = $this->extractTailForOverlap($current, $overlapChars);
            $current = trim($overlapText . "\n" . $segment);

            $currentLength = function_exists('mb_strlen') ? (int) mb_strlen($current) : strlen($current);
            if ($currentLength > (int) floor($maxChars * 1.2)) {
                $pieces = $this->splitLongTextBySentence($current, $maxChars);
                if (!empty($pieces)) {
                    $current = array_pop($pieces);
                    $chunks = array_merge($chunks, $pieces);
                }
            }
        }

        if ($current !== '') {
            $chunks[] = trim($current);
        }

        $unique = [];
        $result = [];
        foreach ($chunks as $chunk) {
            $chunk = $this->normalizeChunkText($chunk);
            if ($chunk === '') {
                continue;
            }

            $chunkLength = function_exists('mb_strlen') ? (int) mb_strlen($chunk) : strlen($chunk);
            if ($chunkLength < 40) {
                continue;
            }

            $fingerprint = md5(strtolower((string) preg_replace('/\s+/u', ' ', $chunk)));
            if (isset($unique[$fingerprint])) {
                continue;
            }

            $unique[$fingerprint] = true;
            $result[] = $chunk;
        }

        if (!empty($result)) {
            return $result;
        }

        // Laatste vangnet: als er nog voldoende tekst is, bewaar minstens 1 chunk.
        $fallback = $this->normalizeChunkText($text);
        $fallbackLength = function_exists('mb_strlen') ? (int) mb_strlen($fallback) : strlen($fallback);
        if ($fallbackLength >= 20) {
            if ($fallbackLength > $maxChars) {
                if (function_exists('mb_substr')) {
                    $fallback = (string) mb_substr($fallback, 0, $maxChars);
                } else {
                    $fallback = (string) substr($fallback, 0, $maxChars);
                }
            }
            return [$fallback];
        }

        return [];
    }

    /**
     * Fallback: chunk volledige tekst zonder pagina-objecten.
     */
    private function buildChunksFromPlainText(string $text, string $sourceTitle, string $fileUrl): array
    {
        return $this->chunkTextWithMetadata(
            $text,
            $sourceTitle,
            $fileUrl,
            [
                'manual_url' => '',
                'source_type' => 'pdf',
            ]
        );
    }

    /**
     * Normaliseer tekst zodat chunking stabieler wordt.
     */
    private function normalizeChunkText(string $text): string
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = preg_replace('/\t+/u', ' ', $text);
        $text = preg_replace('/[ ]{2,}/u', ' ', (string) $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", (string) $text);
        $text = trim((string) $text);

        return $text;
    }

    /**
     * Splits tekst in semantische blokken op basis van headings en paragrafen.
     */
    private function splitTextIntoSemanticBlocks(string $text): array
    {
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
        $lines = preg_split('/\n/', $text);
        if (!is_array($lines) || empty($lines)) {
            return [];
        }

        $blocks = [];
        $buffer = [];

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', (string) $line));
            if ($line === '') {
                if (!empty($buffer)) {
                    $blocks[] = implode("\n", $buffer);
                    $buffer = [];
                }
                continue;
            }

            $isHeading = $this->isLikelyHeadingLine($line);
            if ($isHeading && !empty($buffer)) {
                $blocks[] = implode("\n", $buffer);
                $buffer = [];
            }

            $buffer[] = $line;

            if (!$isHeading && !$this->isLikelyBulletLine($line) && count($buffer) >= 10) {
                $blocks[] = implode("\n", $buffer);
                $buffer = [];
            }
        }

        if (!empty($buffer)) {
            $blocks[] = implode("\n", $buffer);
        }

        return array_values(array_filter(array_map(function ($block) {
            return $this->normalizeChunkText((string) $block);
        }, $blocks), static function ($block) {
            return $block !== '';
        }));
    }

    /**
     * Eenvoudige heading-detectie om secties te respecteren tijdens chunking.
     */
    private function isLikelyHeadingLine(string $line): bool
    {
        $line = trim((string) $line);
        if ($line === '') {
            return false;
        }

        $length = function_exists('mb_strlen') ? (int) mb_strlen($line) : strlen($line);
        if ($length < 4 || $length > 130) {
            return false;
        }

        if (preg_match('/^(pagina|page)\s+\d+/iu', $line)) {
            return false;
        }

        if (preg_match('/^[A-Z0-9][A-Z0-9\-\s\/\(\)]{4,}$/u', $line)) {
            return true;
        }

        if (preg_match('/^\d+(\.\d+)*[\)\.]?\s+[^\.!?]+$/u', $line)) {
            return true;
        }

        if (preg_match('/^[^\.\!\?]{4,120}\:\s*$/u', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Detecteer list-items zodat ze niet losgekoppeld worden van hun context.
     */
    private function isLikelyBulletLine(string $line): bool
    {
        return preg_match('/^(\-|\*|\x{2022}|\d+[\.\)])\s+/u', trim((string) $line)) === 1;
    }

    /**
     * Bouw compacte indextermen op voor retrieval zonder inhoud te vervuilen.
     */
    private function buildTermIndex(string $text, int $maxTerms = 12): array
    {
        $text = $this->normalizeChunkText($text);
        if ($text === '') {
            return [];
        }

        $lower = function_exists('mb_strtolower')
            ? (string) mb_strtolower($text, 'UTF-8')
            : (string) strtolower($text);

        $clean = preg_replace('/[^\p{L}\p{N}\-\s]+/u', ' ', $lower);
        $tokens = preg_split('/\s+/u', (string) $clean, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || empty($tokens)) {
            return [];
        }

        $stopwords = [
            'de', 'het', 'een', 'en', 'van', 'voor', 'met', 'als', 'bij', 'op', 'in', 'naar', 'door', 'aan', 'of',
            'le', 'la', 'les', 'des', 'pour', 'avec', 'dans', 'par', 'une', 'est', 'sur', 'aux', 'qui', 'que',
            'the', 'and', 'for', 'with', 'this', 'that', 'from', 'your', 'you', 'are', 'was', 'were', 'have', 'has'
        ];

        $scores = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token, "-_ \t\n\r\0\x0B");
            if ($token === '' || in_array($token, $stopwords, true)) {
                continue;
            }

            $len = function_exists('mb_strlen') ? (int) mb_strlen($token) : strlen($token);
            if ($len < 3 || $len > 36 || ctype_digit($token)) {
                continue;
            }

            $score = 1 + (int) floor(min(2, max(0, $len - 6) / 6));
            $scores[$token] = isset($scores[$token]) ? $scores[$token] + $score : $score;
        }

        if (empty($scores)) {
            return [];
        }

        arsort($scores, SORT_NUMERIC);
        $terms = array_keys($scores);

        return array_slice($terms, 0, max(1, $maxTerms));
    }

    /**
     * Voeg termen samen met dedupe en een maximum.
     */
    private function mergeUniqueTerms(array $existingTerms, array $newTerms, int $maxTerms = 16): array
    {
        $map = [];
        foreach ($existingTerms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            $key = function_exists('mb_strtolower')
                ? (string) mb_strtolower($term, 'UTF-8')
                : (string) strtolower($term);
            $map[$key] = $term;
        }

        foreach ($newTerms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            $key = function_exists('mb_strtolower')
                ? (string) mb_strtolower($term, 'UTF-8')
                : (string) strtolower($term);
            if (!isset($map[$key])) {
                $map[$key] = $term;
            }
            if (count($map) >= $maxTerms) {
                break;
            }
        }

        return array_slice(array_values($map), 0, $maxTerms);
    }

    /**
     * Splits zeer lange tekst op zinsgrenzen, met woord-fallback.
     */
    private function splitLongTextBySentence(string $text, int $maxChars): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [];
        }

        $sentences = preg_split('/(?<=[\.\!\?\:;])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($sentences) || empty($sentences)) {
            $sentences = [$text];
        }

        $pieces = [];
        $buffer = '';

        foreach ($sentences as $sentence) {
            $sentence = trim((string) $sentence);
            if ($sentence === '') {
                continue;
            }

            $candidate = $buffer === '' ? $sentence : ($buffer . ' ' . $sentence);
            $candidateLength = function_exists('mb_strlen') ? (int) mb_strlen($candidate) : strlen($candidate);

            if ($candidateLength <= $maxChars) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $pieces[] = trim($buffer);
                $buffer = '';
            }

            $sentenceLength = function_exists('mb_strlen') ? (int) mb_strlen($sentence) : strlen($sentence);
            if ($sentenceLength <= $maxChars) {
                $buffer = $sentence;
                continue;
            }

            $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($words) || empty($words)) {
                $pieces[] = $sentence;
                continue;
            }

            $wordBuffer = '';
            foreach ($words as $word) {
                $word = trim((string) $word);
                if ($word === '') {
                    continue;
                }

                $wordCandidate = $wordBuffer === '' ? $word : ($wordBuffer . ' ' . $word);
                $wordCandidateLength = function_exists('mb_strlen') ? (int) mb_strlen($wordCandidate) : strlen($wordCandidate);
                if ($wordCandidateLength <= $maxChars) {
                    $wordBuffer = $wordCandidate;
                    continue;
                }

                if ($wordBuffer !== '') {
                    $pieces[] = trim($wordBuffer);
                }
                $wordBuffer = $word;
            }

            if ($wordBuffer !== '') {
                $buffer = trim($wordBuffer);
            }
        }

        if ($buffer !== '') {
            $pieces[] = trim($buffer);
        }

        return array_values(array_filter($pieces, static function ($item) {
            return trim((string) $item) !== '';
        }));
    }

    /**
     * Neem het staartstuk van vorige chunk mee als overlap.
     */
    private function extractTailForOverlap(string $text, int $overlapChars): string
    {
        $text = trim((string) $text);
        if ($text === '' || $overlapChars <= 0) {
            return '';
        }

        $length = function_exists('mb_strlen') ? (int) mb_strlen($text) : strlen($text);
        if ($length <= $overlapChars) {
            return $text;
        }

        $tail = function_exists('mb_substr')
            ? (string) mb_substr($text, $length - $overlapChars)
            : (string) substr($text, -$overlapChars);

        $tail = trim((string) $tail);
        if ($tail === '') {
            return '';
        }

        $firstSpacePos = strpos($tail, ' ');
        if ($firstSpacePos !== false && $firstSpacePos < (int) floor(strlen($tail) * 0.5)) {
            $tail = substr($tail, $firstSpacePos + 1);
        }

        return trim((string) $tail);
    }
}
}
