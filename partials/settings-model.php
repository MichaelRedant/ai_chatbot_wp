<?php
if (!defined('ABSPATH')) exit;

$selected_model = get_option('octopus_ai_model', 'gpt-4.1-mini');
?>

<h2>🧠 AI Model</h2>
<table class="form-table">
    <tr>
        <th scope="row"><label for="octopus_ai_model">Modelkeuze</label></th>
        <td>
            <select name="octopus_ai_model" id="octopus_ai_model" style="width: 400px;">
                <?php
                $models = [
                    'gpt-4.1-mini'   => 'GPT-4.1 Mini ⚖️ (aanbevolen)',
                    'gpt-4.1-nano'   => 'GPT-4.1 Nano 🚀 (supersnel)',
                    'gpt-4.1'        => 'GPT-4.1 🧠 (maximale accuraatheid)',
                    'o4-mini'        => 'OpenAI o4-mini 🔬 (voor redenering)',
                    'gpt-3.5-turbo'  => 'GPT-3.5 Turbo 💬 (budgetoptie)'
                ];
                foreach ($models as $value => $label) {
                    echo '<option value="' . esc_attr($value) . '" ' . selected($selected_model, $value, false) . '>' . esc_html($label) . '</option>';
                }
                ?>
            </select>

            <p style="margin-top:10px;">
                <a href="#" onclick="toggleModelInfo(); return false;">📊 Bekijk vergelijking van modellen en prijzen</a>
            </p>

            <div id="model-info-table" style="display:none; margin-top:10px; border:1px solid #ddd; padding:10px; border-radius:6px; background:#f9f9f9;">
                <table class="widefat striped">
                    <thead>
                        <tr><th>Model</th><th>Snelheid ⚡</th><th>Intelligentie 🧠</th><th>Prijs / 1K tokens</th><th>Aanbevolen voor</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>GPT-4.1 Mini</td><td>⚡⚡⚡</td><td>🧠🧠🧠</td><td>$0.40 / $1.60</td><td>⚖️ Balans snelheid/kwaliteit</td></tr>
                        <tr><td>GPT-4.1 Nano</td><td>⚡⚡⚡⚡</td><td>🧠🧠</td><td>$0.10 / $0.40</td><td>🚀 Snelle basistaken</td></tr>
                        <tr><td>GPT-4.1</td><td>⚡</td><td>🧠🧠🧠🧠</td><td>$2.00 / $8.00</td><td>💡 Complexe vragen</td></tr>
                        <tr><td>OpenAI o4-mini</td><td>⚡⚡</td><td>🧠🧠🧠🧠</td><td>$1.10 / $4.40</td><td>🔬 Redenering & logica</td></tr>
                        <tr><td>GPT-3.5 Turbo</td><td>⚡⚡⚡</td><td>🧠</td><td>± $0.50 / $1.50</td><td>💬 Budgetoptie</td></tr>
                    </tbody>
                </table>
            </div>

            <script>
                function toggleModelInfo() {
                    const el = document.getElementById("model-info-table");
                    el.style.display = el.style.display === "none" ? "block" : "none";
                }
            </script>
        </td>
    </tr>
</table>
