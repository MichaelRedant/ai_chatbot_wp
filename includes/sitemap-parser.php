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
    private function buildChunkFilenameFromUrl($url, $fallbackIndex, $chunkIndex = 1, $totalChunks = 1) {
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $basename = pathinfo((string) basename($path), PATHINFO_FILENAME);
        $slug = sanitize_title($basename);
        if ($slug === '') {
            $slug = 'pagina-' . (int) $fallbackIndex;
        }

        $hash = $this->buildChunkHashFromUrl($url);

        if ((int) $totalChunks > 1) {
            return 'sitemap_' . $slug . '_chunk_' . (int) $chunkIndex . '_' . $hash . '.json';
        }

        return 'sitemap_' . $slug . '_' . $hash . '.json';
    }

    /**
     * Haal een gedeelde chunker op voor consistente chunk-kwaliteit.
     */
    private function getChunkerInstance() {
        if (!class_exists(Chunker::class)) {
            $chunker_file = __DIR__ . '/pdf-chunker.php';
            if (file_exists($chunker_file)) {
                require_once $chunker_file;
            }
        }

        if (!class_exists(Chunker::class)) {
            return null;
        }

        return new Chunker(700, 120);
    }

    /**
     * Normaliseer leesbare inhoud voor chunking.
     */
    private function normalizeMainTextLine($value) {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = wp_strip_all_tags($value, true);
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) > 420) {
                $value = (string) mb_substr($value, 0, 420);
            }
        } elseif (strlen($value) > 420) {
            $value = (string) substr($value, 0, 420);
        }

        return $value;
    }

    /**
     * Extraheer tekst met structuur i.p.v. een grote platte blob.
     */
    private function extractMainTextForChunking(\DOMXPath $xpath, \DOMNode $mainNode) {
        $parts = [];
        $nodes = $xpath->query('.//h1|.//h2|.//h3|.//h4|.//p|.//li|.//dt|.//dd|.//td|.//th', $mainNode);

        if ($nodes instanceof \DOMNodeList && $nodes->length > 0) {
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMNode) {
                    continue;
                }

                $line = $this->normalizeMainTextLine($node->textContent);
                if ($line === '') {
                    continue;
                }

                $name = strtolower((string) $node->nodeName);
                if (in_array($name, ['h1', 'h2', 'h3', 'h4'], true)) {
                    $parts[] = $line . ':';
                    continue;
                }

                if ($name === 'li') {
                    $parts[] = '- ' . $line;
                    continue;
                }

                $parts[] = $line;
            }
        }

        if (empty($parts)) {
            $fallback = $this->normalizeMainTextLine($mainNode->textContent);
            return $fallback !== '' ? $fallback : '';
        }

        $text = implode("\n", $parts);
        $text = preg_replace('/\n{3,}/', "\n\n", (string) $text);

        return trim((string) $text);
    }

    /**
     * Normaliseer een gevonden term zodat die bruikbaar is voor zoekindexering.
     */
    private function normalizeSearchTerm($value) {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = wp_strip_all_tags($value, true);
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strlen')) {
            if (mb_strlen($value) < 3) {
                return '';
            }
            if (mb_strlen($value) > 140) {
                $value = (string) mb_substr($value, 0, 140);
            }
        } else {
            if (strlen($value) < 3) {
                return '';
            }
            if (strlen($value) > 140) {
                $value = (string) substr($value, 0, 140);
            }
        }

        return trim($value);
    }

    /**
     * Voeg een zoekterm toe met deduplicatie.
     */
    private function addSearchTerm(array &$terms_map, $value) {
        $normalized = $this->normalizeSearchTerm($value);
        if ($normalized === '') {
            return;
        }

        $key = function_exists('mb_strtolower')
            ? (string) mb_strtolower($normalized, 'UTF-8')
            : (string) strtolower($normalized);

        if (!isset($terms_map[$key])) {
            $terms_map[$key] = $normalized;
        }
    }

    /**
     * Resolveer relatieve links tegen de huidige pagina-URL.
     */
    private function resolveRelativeUrl($base_url, $href) {
        $base_url = esc_url_raw((string) $base_url);
        $href = trim((string) $href);

        if ($base_url === '' || $href === '' || $href[0] === '#') {
            return '';
        }

        $href_lower = strtolower($href);
        if (strpos($href_lower, 'javascript:') === 0 || strpos($href_lower, 'mailto:') === 0 || strpos($href_lower, 'tel:') === 0) {
            return '';
        }

        if (preg_match('#^https?://#i', $href)) {
            return esc_url_raw($href);
        }

        $base_parts = wp_parse_url($base_url);
        if (!is_array($base_parts) || empty($base_parts['host'])) {
            return '';
        }

        $scheme = !empty($base_parts['scheme']) ? (string) $base_parts['scheme'] : 'https';
        $host = (string) $base_parts['host'];
        $port = isset($base_parts['port']) ? ':' . (int) $base_parts['port'] : '';

        if (strpos($href, '//') === 0) {
            return esc_url_raw($scheme . ':' . $href);
        }

        if ($href[0] === '/') {
            return esc_url_raw($scheme . '://' . $host . $port . $href);
        }

        $base_path = (string) ($base_parts['path'] ?? '/');
        $base_dir = preg_replace('#/[^/]*$#', '/', $base_path);
        if (!is_string($base_dir) || $base_dir === '') {
            $base_dir = '/';
        }

        $combined = $base_dir . $href;
        $combined = preg_replace('#/+#', '/', $combined);
        if (!is_string($combined) || $combined === '') {
            $combined = '/';
        }

        $segments = explode('/', $combined);
        $normalized_segments = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($normalized_segments);
                continue;
            }
            $normalized_segments[] = $segment;
        }

        return esc_url_raw($scheme . '://' . $host . $port . '/' . implode('/', $normalized_segments));
    }

    /**
     * Extra indextermen uit navigatie en dropdowns op de pagina.
     */
    private function extractNavigationSearchTerms(\DOMXPath $xpath, $page_url) {
        $terms_map = [];

        $anchors = $xpath->query('//a[@href]');
        if ($anchors instanceof \DOMNodeList) {
            foreach ($anchors as $anchor) {
                if (!$anchor instanceof \DOMElement) {
                    continue;
                }

                $this->addSearchTerm($terms_map, $anchor->textContent);
                if ($anchor->hasAttribute('title')) {
                    $this->addSearchTerm($terms_map, $anchor->getAttribute('title'));
                }

                $ancestor = $anchor->parentNode;
                $max_walk = 3;
                while ($ancestor instanceof \DOMElement && $max_walk > 0) {
                    if ($ancestor->hasAttribute('data-desc')) {
                        $this->addSearchTerm($terms_map, $ancestor->getAttribute('data-desc'));
                        break;
                    }
                    $ancestor = $ancestor->parentNode;
                    $max_walk--;
                }

                $resolved = $this->resolveRelativeUrl($page_url, $anchor->getAttribute('href'));
                if ($resolved !== '') {
                    $path = (string) wp_parse_url($resolved, PHP_URL_PATH);
                    $filename = (string) pathinfo($path, PATHINFO_FILENAME);
                    if ($filename !== '') {
                        $this->addSearchTerm($terms_map, str_replace(['-', '_', '.'], ' ', $filename));
                    }
                }
            }
        }

        $options = $xpath->query('//option');
        if ($options instanceof \DOMNodeList) {
            foreach ($options as $option) {
                if (!$option instanceof \DOMElement) {
                    continue;
                }

                $this->addSearchTerm($terms_map, $option->textContent);

                $target = '';
                foreach (['value', 'data-href', 'data-url', 'data-link'] as $attr_name) {
                    if ($option->hasAttribute($attr_name)) {
                        $target = (string) $option->getAttribute($attr_name);
                        if ($target !== '') {
                            break;
                        }
                    }
                }

                $resolved = $this->resolveRelativeUrl($page_url, $target);
                if ($resolved !== '') {
                    $path = (string) wp_parse_url($resolved, PHP_URL_PATH);
                    $filename = (string) pathinfo($path, PATHINFO_FILENAME);
                    if ($filename !== '') {
                        $this->addSearchTerm($terms_map, str_replace(['-', '_', '.'], ' ', $filename));
                    }
                }
            }
        }

        $desc_nodes = $xpath->query('//*[@data-desc]');
        if ($desc_nodes instanceof \DOMNodeList) {
            foreach ($desc_nodes as $node) {
                if ($node instanceof \DOMElement && $node->hasAttribute('data-desc')) {
                    $this->addSearchTerm($terms_map, $node->getAttribute('data-desc'));
                }
            }
        }

        return array_values($terms_map);
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
        $chunker = $this->getChunkerInstance();
        $json_flags = function_exists('octopus_ai_get_chunk_json_encode_flags')
            ? octopus_ai_get_chunk_json_encode_flags()
            : JSON_UNESCAPED_UNICODE;

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


            $base_text = $this->extractMainTextForChunking($xpath, $mainNode);
            $navigation_terms = $this->extractNavigationSearchTerms($xpath, (string) $final_url);
            $text = trim((string) $base_text);

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
            $source_title = $section_title !== '' ? $section_title : (basename($url_path) ?: 'Sitemap pagina');

            $chunk_payloads = [];
            if ($chunker instanceof Chunker) {
                $chunk_payloads = $chunker->chunkTextWithMetadata(
                    $text,
                    $source_title,
                    (string) $final_url,
                    [
                        'section_title' => (string) $section_title,
                        'page_slug'     => (string) $page_slug,
                        'original_page' => '',
                        'source_url'    => (string) $final_url,
                        'manual_url'    => (string) $final_url,
                        'source_type'   => 'sitemap',
                        'index_terms'   => $navigation_terms,
                    ]
                );
            }

            if (!is_array($chunk_payloads) || empty($chunk_payloads)) {
                $chunk_payloads = [[
                    'content' => $text,
                    'metadata' => [
                        'source_title' => $source_title,
                        'section_title' => $section_title,
                        'page_slug' => $page_slug,
                        'original_page' => '',
                        'source_url' => $final_url,
                        'manual_url' => $final_url,
                        'source_type' => 'sitemap',
                        'chunk_index' => 1,
                        'index_terms' => $navigation_terms,
                    ],
                ]];
            }

            foreach (glob($output_dir . 'sitemap_*_' . $chunk_hash . '*.json') as $existing_file) {
                if (file_exists($existing_file)) {
                    unlink($existing_file);
                }
            }

            $written_for_url = 0;
            $total_chunks = count($chunk_payloads);
            foreach ($chunk_payloads as $index => $payload) {
                $chunk_content = isset($payload['content']) ? trim((string) $payload['content']) : '';
                if ($chunk_content === '') {
                    continue;
                }

                $meta = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
                $chunk_index = $index + 1;
                $chunk_filename = $this->buildChunkFilenameFromUrl($final_url, $count + 1, $chunk_index, $total_chunks);
                $data = [
                    'content'  => $chunk_content,
                    'metadata' => [
                        'source_title' => (string) ($meta['source_title'] ?? $source_title),
                        'section_title' => (string) ($meta['section_title'] ?? $section_title),
                        'page_slug'     => (string) ($meta['page_slug'] ?? $page_slug),
                        'original_page' => (string) ($meta['original_page'] ?? ''),
                        'source_url'    => esc_url_raw((string) ($meta['source_url'] ?? $final_url)),
                        'manual_url'    => esc_url_raw((string) ($meta['manual_url'] ?? $final_url)),
                        'source_type'   => (string) ($meta['source_type'] ?? 'sitemap'),
                        'chunk_index'   => (int) ($meta['chunk_index'] ?? $chunk_index),
                        'total_chunks'  => $total_chunks,
                        'index_terms'   => isset($meta['index_terms']) && is_array($meta['index_terms'])
                            ? array_values(array_filter(array_map('strval', $meta['index_terms'])))
                            : [],
                    ],
                ];

                file_put_contents(
                    $output_dir . $chunk_filename,
                    wp_json_encode($data, $json_flags)
                );
                $written_for_url++;
            }

            if ($written_for_url > 0) {
                $count++;
            }
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
            foreach (glob($chunk_dir . 'sitemap_*_' . $hash . '*.json') as $file) {
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
