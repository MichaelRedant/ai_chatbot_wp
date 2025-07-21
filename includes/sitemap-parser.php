<?php
namespace OctopusAI\Includes;

if (!defined('ABSPATH')) exit;

class SitemapParser {

    public function getUrlsFromSitemap() {
        $upload_dir = wp_upload_dir();
        $sitemap_path = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/sitemap.xml';

        if (!file_exists($sitemap_path)) return [];

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($sitemap_path);
        if (!$xml) return [];

        $namespaces = $xml->getDocNamespaces(true);
        if (isset($namespaces[''])) {
            $xml->registerXPathNamespace('ns', $namespaces['']);
            $entries = $xml->xpath('//ns:url/ns:loc');
        } else {
            $entries = $xml->xpath('//url/loc');
        }

        $urls = [];
        foreach ($entries as $loc) {
            $urls[] = (string) $loc;
        }

        return $urls;
    }

    public function fetchAndSaveHtmlFromUrls($limit = 25) {
        $upload_dir = wp_upload_dir();
        $output_dir = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
        if (!file_exists($output_dir)) wp_mkdir_p($output_dir);

        $urls = $this->getUrlsFromSitemap();
        $count = 0;

        foreach ($urls as $url) {
            if ($count >= $limit) break;

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
            file_put_contents($output_dir . 'sitemap_' . $slug . '.txt', $text);
            $count++;
        }

        return $count;
    }
}
