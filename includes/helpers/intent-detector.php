<?php
// Veiligheid
if (!defined('ABSPATH')) exit;

/**
 * Detecteert de intent op basis van keywordgroepen.
 *
 * @param string $question
 * @return string|null intent slug zoals 'facturatie', 'btw', 'integraties', ...
 */
function octopus_ai_detect_intent($question) {
    $question = strtolower($question);

    $intents = [
        'facturatie' => ['factuur', 'verkoopfactuur', 'creditnota', 'klant'],
        'aankoop'    => ['aankoop', 'leverancier', 'inkoop', 'inboeken'],
        'btw'        => ['btw', 'aangifte', 'intervat'],
        'bank'       => ['bank', 'uittreksel', 'codaboek', 'reconciliatie'],
        'integraties'=> ['mollie', 'woo', 'webwinkel', 'api', 'koppeling', 'shop'],
        'gebruikers' => ['gebruiker', 'rechten', 'toegang'],
        'instellingen' => ['instelling', 'configuratie', 'aanpassen'],
        'algemeen'   => ['octopus', 'help', 'vraag', 'ondersteuning']
    ];

    foreach ($intents as $intent => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($question, $kw) !== false) {
                return $intent;
            }
        }
    }

    return null;
}
