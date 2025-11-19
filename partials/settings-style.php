<?php
if (!defined('ABSPATH')) exit;

$logo = get_option('octopus_ai_logo_url');
?>

<h2>🎨 Uiterlijk</h2>
<table class="form-table">
    <tr>
        <th scope="row"><label for="octopus_ai_primary_color">Primaire kleur</label></th>
        <td>
            <input type="text" name="octopus_ai_primary_color" class="wp-color-picker-field"
                   data-default-color="#0f6c95"
                   value="<?php echo esc_attr(get_option('octopus_ai_primary_color', '#0f6c95')); ?>" />
            <p class="description">Bepaal de hoofdkleur van je chatbot.</p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="octopus-ai-logo-url">Logo</label></th>
        <td>
            <?php if ($logo): ?>
                <img src="<?php echo esc_url($logo); ?>" id="octopus-ai-logo-preview"
                     style="max-width:100px;display:block;margin-bottom:10px;">
            <?php endif; ?>
            <input type="url" name="octopus_ai_logo_url" id="octopus-ai-logo-url"
                   value="<?php echo esc_attr($logo); ?>" style="width:400px;" readonly>
            <button type="button" class="button" id="octopus-ai-upload-logo-button">Upload / Selecteer logo</button>
            <p class="description">Optioneel: logo dat bovenaan de chatbot verschijnt.</p>
        </td>
    </tr>
</table>
