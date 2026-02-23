<?php
namespace OctopusAI\Includes;

if (!defined('ABSPATH')) exit;

class SitemapParser {

    /**
     * ✅ Haal alle unieke URL's op uit ALLE geüploade XML-bestanden
     */
    public function getUrlsFromSitemap($limitPerFile = 50) {
        if (!function_exists('simplexml_load_file')) {
            error_log('[Octopus AI] SimpleXML extensie ontbreekt: sitemap-URLs kunnen niet ingelezen worden.');
            return [];
        }

        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
        $sitemap_files = glob($base_dir . '*.xml');

        $urls = [];

        foreach ($sitemap_files as $path) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($path);
            if (!$xml) continue;

            $namespaces = $xml->getDocNamespaces(true);
            if (isset($namespaces[''])) {
                $xml->registerXPathNamespace('ns', $namespaces['']);
                $entries = $xml->xpath('//ns:url/ns:loc');
            } else {
                $entries = $xml->xpath('//url/loc');
            }

            $count = 0;
            foreach ($entries as $loc) {
                if ($count >= $limitPerFile) break;
                $urls[] = (string) $loc;
                $count++;
            }
        }

        return array_unique($urls);
    }

    /**
     * Maak een stabiele hash op basis van de volledige URL.
     */
    private function buildChunkHashFromUrl($url) {
        $normalized = trim((string) $url);
        $normalized = preg_replace('/#.*$/', '', $normalized);
        $normalized = strtolower((string) $normalized);

        return substr(md5($normalized), 0, 12);
    }

    /**
     * Bouw bestandsnaam op met slug + hash om botsingen te vermijden.
     */
    private function buildChunkFilenameFromUrl($url, $fallbackIndex) {
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $basename = pathinfo((string) basename($path), PATHINFO_FILENAME);
        $slug = sanitize_title($basename);
        if ($slug === '') {
            $slug = 'pagina-' . (int) $fallbackIndex;
        }

        $hash = $this->buildChunkHashFromUrl($url);

        return 'sitemap_' . $slug . '_' . $hash . '.json';
    }

    /**
     * ✅ Haal content op van URL's en bewaar als chunks in .txt-bestanden
     *
     * @param int   $limit Max aantal te verwerken URL's (0 = onbeperkt)
     * @param array|null $urls Optionele lijst van URL's om te verwerken
     */
    public function fetchAndSaveHtmlFromUrls($limit = 0, $urls = null) {
        if (!class_exists('\DOMDocument') || !class_exists('\DOMXPath')) {
            error_log('[Octopus AI] DOM extensie ontbreekt: HTML parsing overgeslagen.');
            return 0;
        }

        $upload_dir = wp_upload_dir();
        $output_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
        if (!file_exists($output_dir)) wp_mkdir_p($output_dir);


        if ($urls === null) {
            $urls = $this->getUrlsFromSitemap();
        }

        $count = 0;

        foreach ($urls as $url) {
            if ($limit > 0 && $count >= $limit) break; // enkel als limiet > 0

            list($html, $final_url) = $this->retrieveHtmlWithLangFallback($url);

            if (!$html) continue;

            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            libxml_clear_errors();

            // verwijder overbodige tags
            foreach (['script', 'style', 'noscript'] as $tag) {
                foreach ($dom->getElementsByTagName($tag) as $node) {
                    $node->parentNode->removeChild($node);
                }
            }

            $xpath = new \DOMXPath($dom);
            $mainNode = $xpath->query('//main')->item(0) ?? $xpath->query('//body')->item(0);
            if (!$mainNode) continue;

            $titleNode = $xpath->query('//main//h1 | //body//h1 | //title')->item(0);
            $section_title = $titleNode ? trim($titleNode->textContent) : '';


            $text = trim(preg_replace('/\s+/', ' ', $mainNode->textContent));

            if (
                stripos($section_title, '404') !== false ||
                stripos($section_title, 'Not Found') !== false ||
                stripos($text, 'Not Found') !== false ||
                stripos($text, 'requested URL was not found') !== false
            ) {
                continue;
            }


            if (strlen($text) < 50) continue;

            $url_path = trim((string) parse_url($final_url, PHP_URL_PATH), '/');
            $page_slug = $url_path;
            $chunk_hash = $this->buildChunkHashFromUrl($final_url);
            $chunk_filename = $this->buildChunkFilenameFromUrl($final_url, $count);
            $data = [
                'content'  => $text,
                'metadata' => [
                    'section_title' => $section_title,
                    'page_slug'     => $page_slug,
                    'original_page' => '',
                    'source_url'    => $final_url,
                    'manual_url'    => $final_url,
                ],
            ];

            foreach (glob($output_dir . 'sitemap_*_' . $chunk_hash . '.json') as $existing_file) {
                if (file_exists($existing_file)) {
                    unlink($existing_file);
                }
            }

            file_put_contents(
                $output_dir . $chunk_filename,
                wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
            $count++;
        }


        return $count;
    }

    /**
     * 🔄 Probeer URL op te halen met NL/FR fallback indien nodig
     */
    private function retrieveHtmlWithLangFallback($url) {
        $candidates = [$url];

        if (strpos($url, '/manual/') !== false && !preg_match('/\/manual\/(NL|FR)\//', $url)) {
            $candidates[] = preg_replace('/\/manual\//', '/manual/NL/', $url, 1);
            $candidates[] = preg_replace('/\/manual\//', '/manual/FR/', $url, 1);
        }

        foreach ($candidates as $candidate) {
            $response = wp_remote_get($candidate);
            if (is_wp_error($response)) continue;
            if (wp_remote_retrieve_response_code($response) !== 200) continue;

            $html = wp_remote_retrieve_body($response);
            if (!$html) continue;
            if (stripos($html, 'Not Found') !== false || stripos($html, 'requested URL was not found') !== false) {
                continue;
            }

            return [$html, $candidate];
        }

        return [null, null];
    }

    /**
     * 🧹 Verwijder chunk-bestanden op basis van een lijst URL's
     */
    public function deleteChunksForUrls(array $urls) {
        $upload_dir = wp_upload_dir();
        $chunk_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

        foreach ($urls as $index => $url) {
            $hash = $this->buildChunkHashFromUrl($url);
            foreach (glob($chunk_dir . 'sitemap_*_' . $hash . '.json') as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }

            // Backward compatibility: verwijder oude naamconventie zonder hash.
            $legacy_slug = sanitize_title((string) basename((string) parse_url((string) $url, PHP_URL_PATH))) ?: 'pagina-' . $index;
            $legacy_file = $chunk_dir . 'sitemap_' . $legacy_slug . '.json';
            if (file_exists($legacy_file)) {
                unlink($legacy_file);
            }
        }
    }
}
