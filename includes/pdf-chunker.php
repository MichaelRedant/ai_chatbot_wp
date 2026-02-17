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

    public function __construct($chunkSize = 800, $overlap = 100)
    {
        $this->chunkSize = $chunkSize;
        $this->overlap = $overlap;
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
        $sourceTitle = basename($filePath, '.pdf');
        if (!file_exists($filePath)) {
            error_log('[Octopus AI] PDF bestand niet gevonden: ' . $filePath);
            return [];
        }

        $pages = [];
        $can_use_smalot = class_exists(Parser::class);
        if ($can_use_smalot && function_exists('octopus_ai_validate_pdf_for_smalot')) {
            $guard = \octopus_ai_validate_pdf_for_smalot($filePath);
            if (\is_wp_error($guard)) {
                $can_use_smalot = false;
                error_log('[Octopus AI] PDF parser guard: ' . $guard->get_error_message());
            }
        }

        if ($can_use_smalot) {
            try {
                $parser = new Parser();
                $pdf = $parser->parseFile($filePath);
                $pages = $pdf->getPages();
            } catch (\Throwable $e) {
                error_log('[Octopus AI] PDF parsing mislukt: ' . $e->getMessage());
                $pages = [];
            }
        }

        $chunks = [];

        if (!empty($pages)) {
            foreach ($pages as $i => $page) {
                $text = trim($page->getText());
                if (empty($text)) {
                    continue;
                }

                $pageNumber = $i + 1;
                $pageSlug = $this->generatePageSlug($sourceTitle, $pageNumber);
                $sectionTitle = $this->detectSectionTitle($text);

                $splitChunks = $this->splitTextIntoChunks($text, $this->chunkSize);
                foreach ($splitChunks as $chunkText) {
                    $chunks[] = [
                        'content'  => $chunkText,
                        'metadata' => [
                            'page_slug'     => $pageSlug,
                            'source_title'  => $sourceTitle,
                            'original_page' => $pageNumber,
                            'section_title' => $sectionTitle,
                            'source_url'    => $fileUrl . '#page=' . $pageNumber,
                            'manual_url'    => '',
                        ],
                    ];
                }
            }

            return $chunks;
        }

        // Fallback zonder Smalot parser (bijv. te weinig memory): lees volledige tekst en chunk generiek.
        if (!function_exists('octopus_ai_extract_pdf_text_safely')) {
            error_log('[Octopus AI] PDF helper ontbreekt, fallback chunking kan niet uitgevoerd worden.');
            return [];
        }

        $fallback_text = \octopus_ai_extract_pdf_text_safely($filePath, false);
        if (\is_wp_error($fallback_text)) {
            error_log('[Octopus AI] PDF fallback extractie mislukt: ' . $fallback_text->get_error_message());
            return [];
        }

        $fallback_text = trim((string) $fallback_text);
        if ($fallback_text === '') {
            return [];
        }

        return $this->buildChunksFromPlainText($fallback_text, $sourceTitle, $fileUrl);
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
        $lines = explode("\n", $text);
        $firstLine = trim($lines[0]);

        if (strlen($firstLine) > 10 && strlen($firstLine) < 120) {
            if (preg_match('/^[A-Z][a-z]/', $firstLine)) {
                return $firstLine;
            }
        }

        return null;
    }

    /**
     * Split text into manageable chunks.
     */
    private function splitTextIntoChunks(string $text, int $maxTokens = 750): array
    {
        $sentences = preg_split('/(?<=[.?!])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        $chunk = '';

        foreach ($sentences as $sentence) {
            if ((strlen($chunk . ' ' . $sentence) / 4) < $maxTokens) {
                $chunk .= ' ' . $sentence;
            } else {
                $chunks[] = trim($chunk);
                $chunk = $sentence;
            }
        }

        if (!empty($chunk)) {
            $chunks[] = trim($chunk);
        }

        return $chunks;
    }

    /**
     * Fallback: chunk volledige tekst zonder pagina-objecten.
     */
    private function buildChunksFromPlainText(string $text, string $sourceTitle, string $fileUrl): array
    {
        $chunks = [];
        $splitChunks = $this->splitTextIntoChunks($text, $this->chunkSize);
        $chunkNumber = 0;

        foreach ($splitChunks as $chunkText) {
            $chunkText = trim((string) $chunkText);
            if ($chunkText === '') {
                continue;
            }

            $chunkNumber++;
            $pageSlug = $this->generatePageSlug($sourceTitle, $chunkNumber);
            $sectionTitle = $this->detectSectionTitle($chunkText);

            $chunks[] = [
                'content' => $chunkText,
                'metadata' => [
                    'page_slug' => $pageSlug,
                    'source_title' => $sourceTitle,
                    'original_page' => $chunkNumber,
                    'section_title' => $sectionTitle,
                    'source_url' => $fileUrl,
                    'manual_url' => '',
                ],
            ];
        }

        return $chunks;
    }
}
}
