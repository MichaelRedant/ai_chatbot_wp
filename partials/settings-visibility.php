<?php
if (!defined('ABSPATH')) exit;

$mode = get_option('octopus_ai_display_mode', 'all');
$selected_pages = get_option('octopus_ai_selected_pages', []);
$pages = get_pages();
?>

<h2>🌐 Zichtbaarheid</h2>
<table class="form-table">
    <tr>
        <th scope="row"><label for="octopus_ai_display_mode">Chatbot weergave</label></th>
        <td>
            <select name="octopus_ai_display_mode" id="octopus_ai_display_mode">
                <option value="all" <?php selected($mode, 'all'); ?>>Op alle pagina's tonen</option>
                <option value="selected" <?php selected($mode, 'selected'); ?>>Alleen op geselecteerde pagina's tonen</option>
            </select>
            <p class="description">Kies of de chatbot overal zichtbaar is, of enkel op specifieke pagina's.</p>
        </td>
    </tr>
    <tr id="octopus_ai_page_selector_row" style="<?php echo ($mode === 'selected') ? '' : 'display:none'; ?>">
        <th>Selecteer pagina's</th>
        <td>
            <select name="octopus_ai_selected_pages[]" multiple style="width: 400px;">
                <?php foreach ($pages as $page): ?>
                    <option value="<?php echo esc_attr($page->ID); ?>" <?php selected(in_array($page->ID, $selected_pages)); ?>>
                        <?php echo esc_html($page->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <th>Testmodus</th>
        <td>
            <label>
                <input type="checkbox" name="octopus_ai_test_mode" value="1"
                       <?php checked(get_option('octopus_ai_test_mode'), 1); ?>>
                Alleen zichtbaar voor beheerders
            </label>
        </td>
    </tr>
</table>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const selector = document.getElementById('octopus_ai_display_mode');
        const row = document.getElementById('octopus_ai_page_selector_row');

        selector.addEventListener('change', function () {
            row.style.display = this.value === 'selected' ? '' : 'none';
        });
    });
</script>
