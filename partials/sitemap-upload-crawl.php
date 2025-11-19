<?php
if (!defined('ABSPATH')) exit;

$upload_dir    = wp_upload_dir();
$upload_path   = trailingslashit($upload_dir['basedir']) . 'octopus-chatbot/';
$upload_url    = trailingslashit($upload_dir['baseurl']) . 'octopus-chatbot/';
$chunk_dir     = trailingslashit($upload_dir['basedir']) . 'octopus-ai-chunks/';
$chunk_url     = trailingslashit($upload_dir['baseurl']) . 'octopus-ai-chunks/';
$sitemap_path  = $upload_path . 'sitemap.xml';
$sitemap_urls  = get_option('octopus_ai_sitemap_urls', []);
?>

<h2 id="sitemap-zone">🗺️ Sitemapbeheer</h2>

<!-- 1️⃣ UPLOAD / EXTERNE URL -->
<h3>📥 Upload of koppel een sitemap</h3>

<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
    <?php wp_nonce_field('octopus_ai_upload_sitemap', 'octopus_ai_sitemap_nonce'); ?>
    <input type="hidden" name="action" value="octopus_ai_upload_sitemap">
    <input type="file" name="octopus_ai_sitemap_file" accept=".xml">
    <?php submit_button('Upload sitemap.xml'); ?>
</form>

<p><strong>...of geef een externe sitemap URL op:</strong></p>
<form method="post">
    <input type="url" name="sitemap_url" value="<?php echo esc_attr(get_option('octopus_ai_sitemap_url', '')); ?>" style="width:400px;" placeholder="https://example.com/sitemap.xml" />
    <?php submit_button('💾 Sitemap opslaan'); ?>
</form>

<?php
// ✅ Externe URL opslaan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sitemap_url'])) {
    update_option('octopus_ai_sitemap_url', esc_url_raw($_POST['sitemap_url']));
    echo '<div class="notice notice-success is-dismissible"><p>Sitemap-URL opgeslagen.</p></div>';
}
?>

<?php if (file_exists($sitemap_path)): ?>
    <p><strong>📄 Huidige sitemap:</strong> <a href="<?php echo esc_url($upload_url . 'sitemap.xml'); ?>" target="_blank">Bekijk sitemap.xml</a></p>
<?php endif; ?>

<!-- 2️⃣ ACTIES: TOON / CRAWL -->
<h3>⚙️ Sitemap verwerken</h3>

<p>
    <a href="<?php echo esc_url(add_query_arg('sitemap_debug', '1')); ?>" class="button">🔍 Toon sitemap-URL’s</a>
    <a href="<?php echo esc_url(add_query_arg('crawl', 'now')); ?>" class="button button-primary">🌐 Crawlen & opslaan</a>
</p>

<?php
require_once plugin_dir_path(dirname(__FILE__, 2)) . 'includes/sitemap-parser.php';
$parser = new \OctopusAI\Includes\SitemapParser();

if (isset($_GET['sitemap_debug'])) {
    $urls = $parser->getUrlsFromSitemap();
    echo '<p><strong>' . count($urls) . ' URL(s)</strong> gevonden in de sitemap.</p>';
    echo '<ul>';
    foreach (array_slice($urls, 0, 10) as $url) {
        echo '<li><a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a></li>';
    }
    echo '</ul>';
}

if (isset($_GET['crawl']) && $_GET['crawl'] === 'now') {
    $count = $parser->fetchAndSaveHtmlFromUrls(25);
    echo "<div class='updated'><p><strong>$count pagina's</strong> gecrawld en opgeslagen in chunks-folder.</p></div>";
}
?>

<!-- 3️⃣ GEVONDEN URL’S -->
<h3>🌐 Gevonden URL's</h3>

<?php
if (isset($_GET['found'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . intval($_GET['found']) . ' URL(s) gevonden in sitemap.</p></div>';
}
if (isset($_GET['cleared'])) {
    echo '<div class="notice notice-success is-dismissible"><p>Sitemap-lijst gewist.</p></div>';
}
?>

<?php if ($sitemap_urls): ?>
    <ul style="max-height:200px;overflow:auto;border:1px solid #ccc;padding:10px;background:#fff;">
        <?php foreach ($sitemap_urls as $url): ?>
            <li><a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html($url); ?></a></li>
        <?php endforeach; ?>
    </ul>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top:10px;">
        <?php wp_nonce_field('octopus_ai_clear_sitemap_urls'); ?>
        <input type="hidden" name="action" value="octopus_ai_clear_sitemap_urls">
        <?php submit_button('🗑️ Verwijder URL-lijst', 'delete', '', false); ?>
    </form>
<?php else: ?>
    <p><em>Er zijn nog geen sitemap-URL’s beschikbaar.</em></p>
<?php endif; ?>

<!-- 4️⃣ CHUNKS BEHEREN -->
<h3>📦 Sitemap Chunks beheren</h3>

<?php
if (isset($_GET['chunks_deleted'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . intval($_GET['chunks_deleted']) . ' chunk(s) verwijderd.</p></div>';
}
if (isset($_GET['chunks_cleared'])) {
    echo '<div class="notice notice-success is-dismissible"><p>Alle sitemap chunks verwijderd.</p></div>';
}

if (file_exists($chunk_dir)) {
    $chunk_files = glob($chunk_dir . 'sitemap_*.txt');
    if ($chunk_files): ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('octopus_ai_delete_chunks'); ?>
            <input type="hidden" name="action" value="octopus_ai_delete_chunks">
            <ul style="max-height:250px;overflow:auto;border:1px solid #ccc;padding:10px;background:#fff;">
                <?php foreach ($chunk_files as $file):
                    $filename = basename($file); ?>
                    <li>
                        <label>
                            <input type="checkbox" name="chunk_files[]" value="<?php echo esc_attr($filename); ?>">
                            <a href="<?php echo esc_url($chunk_url . $filename); ?>" target="_blank"><?php echo esc_html($filename); ?></a>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p style="margin-top:10px;">
                <input type="submit" class="button button-secondary" value="🗑️ Geselecteerde chunks verwijderen" onclick="return confirm('Weet je zeker dat je deze bestanden wilt verwijderen?');">
            </p>
        </form>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top:10px;">
            <?php wp_nonce_field('octopus_ai_clear_all_chunks'); ?>
            <input type="hidden" name="action" value="octopus_ai_clear_all_chunks">
            <?php submit_button('🧨 Verwijder ALLE sitemap chunks', 'delete', '', false); ?>
        </form>
    <?php else: ?>
        <p><em>Er zijn momenteel geen sitemap chunks opgeslagen.</em></p>
    <?php endif;
} else {
    echo '<p><em>De chunks-folder bestaat nog niet.</em></p>';
}
?>
