<?php
namespace OctopusAI\Includes;

require_once __DIR__ . '/../vendor/autoload.php';

use Smalot\PdfParser\Parser;

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
     * @param string $fileUrl  Publieke URL naar het PDF-bestand (optioneel).
     * @return array
     */
    public function chunkPdfWithMetadata(string $filePath, string $sourceId, string $fileUrl = ''): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);
        $pages = $pdf->getPages();
        $chunks = [];

        $sourceTitle = basename($filePath, '.pdf');

        foreach ($pages as $i => $page) {
            $text = trim($page->getText());
            if (empty($text)) continue;

            $pageNumber = $i + 1;
            $pageSlug = $this->generatePageSlug($sourceTitle, $pageNumber);
            $sectionTitle = $this->detectSectionTitle($text);
            $pageUrl = '';
            if ($fileUrl !== '') {
                $pageUrl = rtrim($fileUrl, '/') . '#page=' . $pageNumber;
            }

            $splitChunks = $this->splitTextIntoChunks($text, $this->chunkSize);
            foreach ($splitChunks as $chunkText) {
                $chunks[] = [
                    'content'  => $chunkText,
                    'metadata' => [
                        'page_slug'     => $pageSlug,
                        'source_title'  => $sourceTitle,
                        'original_page' => $pageNumber,
                        'section_title' => $sectionTitle,
                        'source_url' => $pageUrl,
                    ],
                ];
            }
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
}
