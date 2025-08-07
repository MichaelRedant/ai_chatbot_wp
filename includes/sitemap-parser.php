<?php
namespace OctopusAI\Includes;

if (!defined('ABSPATH')) exit;

class SitemapParser {

    /**
     * ✅ Haal alle unieke URL's op uit ALLE geüploade XML-bestanden
     */
    public function getUrlsFromSitemap($limitPerFile = 50) {
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
     * ✅ Haal content op van URL's en bewaar als chunks in .txt-bestanden
     *
     * @param int   $limit Max aantal te verwerken URL's (0 = onbeperkt)
     * @param array|null $urls Optionele lijst van URL's om te verwerken
     */
    public function fetchAndSaveHtmlFromUrls($limit = 0, $urls = null) {
        $upload_dir = wp_upload_dir();
        $output_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
        if (!file_exists($output_dir)) wp_mkdir_p($output_dir);

        if ($urls === null) {
            $urls = $this->getUrlsFromSitemap();
        }

        $count = 0;

        foreach ($urls as $url) {
            if ($limit > 0 && $count >= $limit) break; // enkel als limiet > 0

            $response = wp_remote_get($url);
            if (is_wp_error($response)) continue;

            $html = wp_remote_retrieve_body($response);
            if (!$html) continue;

            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);
            $mainNode = $xpath->query('//main')->item(0) ?? $xpath->query('//body')->item(0);
            if (!$mainNode) continue;

            $text = trim($mainNode->textContent);
            if (strlen($text) < 50) continue;

            $slug = sanitize_title(basename(parse_url($url, PHP_URL_PATH))) ?: 'pagina-' . $count;
            $data = [
                'content'  => $text,
                'metadata' => [
                    'section_title' => '',
                    'page_slug'     => $slug,
                    'original_page' => '',
                    'source_url'    => $url,
                ],
            ];
            file_put_contents(
                $output_dir . 'sitemap_' . $slug . '.json',
                wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
            $count++;
        }

        return $count;
    }

    /**
     * 🧹 Verwijder chunk-bestanden op basis van een lijst URL's
     */
    public function deleteChunksForUrls(array $urls) {
        $upload_dir = wp_upload_dir();
        $chunk_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';

        foreach ($urls as $index => $url) {
            $slug = sanitize_title(basename(parse_url($url, PHP_URL_PATH))) ?: 'pagina-' . $index;
            $file = $chunk_dir . 'sitemap_' . $slug . '.json';
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
