<?php
if (!defined('ABSPATH')) exit;
?>

<h2>🗣️ Taal & Weergave</h2>
<table class="form-table">
    <tr>
        <th scope="row"><label for="octopus_ai_tone">Tone of Voice</label></th>
        <td>
            <textarea name="octopus_ai_tone" id="octopus_ai_tone" rows="3" style="width: 400px;"><?php echo esc_textarea(get_option('octopus_ai_tone')); ?></textarea>
            <p class="description">Bijv. formeel, vriendelijk, creatief, zakelijk...</p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="octopus_ai_fallback">Fallback tekst</label></th>
        <td>
            <input type="text" name="octopus_ai_fallback" id="octopus_ai_fallback" value="<?php echo esc_attr(get_option('octopus_ai_fallback')); ?>" style="width: 400px;" />
            <p class="description">Tekst die getoond wordt als het AI-model geen antwoord kan genereren.</p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="octopus_ai_welcome_message">Verwelkomingstekst</label></th>
        <td>
            <textarea name="octopus_ai_welcome_message" id="octopus_ai_welcome_message" rows="2" style="width: 400px;"><?php echo esc_textarea(get_option('octopus_ai_welcome_message')); ?></textarea>
            <p class="description">Bijv. “Hallo! Waarmee kan ik je helpen?”</p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="octopus_ai_brand_name">Merknaam</label></th>
        <td>
            <input type="text" name="octopus_ai_brand_name" id="octopus_ai_brand_name" value="<?php echo esc_attr(get_option('octopus_ai_brand_name')); ?>" style="width: 400px;" />
            <p class="description">De merknaam die getoond wordt in het chatbotvenster.</p>
        </td>
    </tr>
</table>
