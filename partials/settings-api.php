<?php
if (!defined('ABSPATH')) exit;

$api_key = get_option('octopus_ai_api_key');
$masked_key = $api_key ? substr($api_key, 0, 5) . str_repeat('*', strlen($api_key) - 10) . substr($api_key, -5) : '';
?>

<h2>🔐 API-instellingen</h2>
<table class="form-table">
    <tr>
        <th scope="row"><label for="octopus_ai_api_key">API Key</label></th>
        <td>
            <input type="text" name="octopus_ai_api_key" id="octopus_ai_api_key"
                   value="<?php echo esc_attr($masked_key); ?>" style="width: 400px;"
                   placeholder="Voer je OpenAI API key in" />
            <p class="description">De key wordt gemaskeerd weergegeven. Vul opnieuw in om te wijzigen.</p>
        </td>
    </tr>
</table>
