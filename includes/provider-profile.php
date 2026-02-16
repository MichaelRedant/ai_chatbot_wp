<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('octopus_ai_get_default_provider_profile')) {
    function octopus_ai_get_default_provider_profile()
    {
        return [
            'provider_key' => 'octopus',
            'brand_name' => 'Octopus',
            'brand_terms' => [
                'octopus',
                'octopus dms',
                'octopusdms',
                'login.octopus.be',
                'academy.octopus.be',
            ],
            'domain_terms' => [
                'klantenportaal',
                'plateforme digitale interactive',
                'pdi',
                'portail client',
                'boekhoud',
                'compta',
                'comptabilite',
                'factuur',
                'facture',
                'facturation',
                'creditnota',
                'avoir',
                'offerte',
                'devis',
                'betaling',
                'paiement',
                'bank',
                'coda',
                'peppol',
                'btw',
                'tva',
                'intervat',
                'journaal',
                'journal',
                'module',
                'instelling',
                'configuration',
                'configuratie',
                'inloggen',
                'login',
                'gebruiker',
                'utilisateur',
                'leverancier',
                'fournisseur',
                'uittreksel',
                'rapport',
                'bijlage',
                'upload',
                'dossier',
                'document',
                'handleiding',
                'manual',
                'support',
            ],
            'off_topic_terms' => [
                'weer',
                'meteo',
                'weather',
                'temperatuur',
                'voetbal',
                'football',
                'basket',
                'tennis',
                'bitcoin',
                'crypto',
                'aandelen',
                'bourse',
                'recept',
                'recette',
                'koken',
                'restaurant',
                'film',
                'serie',
                'muziek',
                'music',
                'song',
                'grap',
                'joke',
                'politiek',
                'verkiezing',
                'election',
                'vakantie',
                'vacances',
                'voyage',
                'travel',
                'horoscoop',
                'astrologie',
                'python',
                'javascript',
                'css',
                'html',
                'linux',
                'windows',
                'android',
                'iphone',
                'game',
                'gaming',
            ],
            'topic_terms' => [
                'klantenportaal' => ['klantenportaal', 'platform', 'plateforme', 'plateforme digitale interactive', 'pdi', 'portal', 'portail', 'klant', 'client', 'factuur', 'facture', 'betaling', 'paiement', 'upload'],
                'boekhoudprogramma' => ['boekhoud', 'compta', 'comptabilite', 'btw', 'tva', 'journaal', 'journal', 'balans', 'rapport'],
            ],
            'manual' => [
                'base_url_nl' => 'https://login.octopus.be/manual/NL/',
                'base_url_fr' => 'https://login.octopus.be/manual/FR/',
            ],
            'i18n' => [
                'out_of_scope_nl' => 'Sorry, ik beantwoord enkel vragen die over Octopus gaan.',
                'out_of_scope_fr' => 'Desole, je reponds uniquement aux questions liees a Octopus.',
                'fallback_nl' => 'Sorry, daar kan ik je niet mee helpen.',
                'fallback_fr' => 'Desole, je ne peux pas t aider avec ca.',
            ],
        ];
    }
}

if (!function_exists('octopus_ai_sanitize_provider_terms')) {
    function octopus_ai_sanitize_provider_terms($terms)
    {
        if (is_string($terms)) {
            $terms = preg_split('/[\r\n,]+/', $terms);
        }

        if (!is_array($terms)) {
            return [];
        }

        $clean = [];
        foreach ($terms as $term) {
            $term = sanitize_text_field((string) $term);
            $term = strtolower(trim($term));
            if ($term === '' || in_array($term, $clean, true)) {
                continue;
            }
            $clean[] = $term;
        }

        return $clean;
    }
}

if (!function_exists('octopus_ai_sanitize_provider_profile')) {
    function octopus_ai_sanitize_provider_profile($profile)
    {
        if (is_string($profile)) {
            $decoded = json_decode(wp_unslash($profile), true);
            $profile = is_array($decoded) ? $decoded : [];
        }

        $defaults = octopus_ai_get_default_provider_profile();
        if (!is_array($profile)) {
            return $defaults;
        }

        $normalized = $defaults;
        $normalized['provider_key'] = sanitize_key((string) ($profile['provider_key'] ?? $defaults['provider_key']));
        $normalized['brand_name'] = sanitize_text_field((string) ($profile['brand_name'] ?? $defaults['brand_name']));

        $brand_terms = octopus_ai_sanitize_provider_terms($profile['brand_terms'] ?? []);
        $domain_terms = octopus_ai_sanitize_provider_terms($profile['domain_terms'] ?? []);
        $off_topic_terms = octopus_ai_sanitize_provider_terms($profile['off_topic_terms'] ?? []);

        if (!empty($brand_terms)) {
            $normalized['brand_terms'] = $brand_terms;
        }
        if (!empty($domain_terms)) {
            $normalized['domain_terms'] = $domain_terms;
        }
        if (!empty($off_topic_terms)) {
            $normalized['off_topic_terms'] = $off_topic_terms;
        }

        if (isset($profile['topic_terms']) && is_array($profile['topic_terms'])) {
            foreach ($profile['topic_terms'] as $topic => $terms) {
                $topic_key = sanitize_key((string) $topic);
                if ($topic_key === '') {
                    continue;
                }

                $clean_topic_terms = octopus_ai_sanitize_provider_terms($terms);
                if (!empty($clean_topic_terms)) {
                    $normalized['topic_terms'][$topic_key] = $clean_topic_terms;
                }
            }
        }

        $manual = isset($profile['manual']) && is_array($profile['manual']) ? $profile['manual'] : [];
        $base_nl = esc_url_raw((string) ($manual['base_url_nl'] ?? $defaults['manual']['base_url_nl']));
        $base_fr = esc_url_raw((string) ($manual['base_url_fr'] ?? $defaults['manual']['base_url_fr']));

        if (function_exists('wp_http_validate_url')) {
            if ($base_nl !== '' && !wp_http_validate_url($base_nl)) {
                $base_nl = $defaults['manual']['base_url_nl'];
            }
            if ($base_fr !== '' && !wp_http_validate_url($base_fr)) {
                $base_fr = $defaults['manual']['base_url_fr'];
            }
        }

        $normalized['manual']['base_url_nl'] = $base_nl !== '' ? $base_nl : $defaults['manual']['base_url_nl'];
        $normalized['manual']['base_url_fr'] = $base_fr !== '' ? $base_fr : $defaults['manual']['base_url_fr'];

        $i18n = isset($profile['i18n']) && is_array($profile['i18n']) ? $profile['i18n'] : [];
        foreach (['out_of_scope_nl', 'out_of_scope_fr', 'fallback_nl', 'fallback_fr'] as $key) {
            if (isset($i18n[$key])) {
                $value = sanitize_text_field((string) $i18n[$key]);
                if ($value !== '') {
                    $normalized['i18n'][$key] = $value;
                }
            }
        }

        return $normalized;
    }
}

if (!function_exists('octopus_ai_get_provider_profile')) {
    function octopus_ai_get_provider_profile()
    {
        $defaults = octopus_ai_get_default_provider_profile();
        $stored = get_option('octopus_ai_provider_profile', []);
        $sanitized = octopus_ai_sanitize_provider_profile($stored);

        return wp_parse_args($sanitized, $defaults);
    }
}

if (!function_exists('octopus_ai_get_provider_manual_base_url')) {
    function octopus_ai_get_provider_manual_base_url($lang)
    {
        $profile = octopus_ai_get_provider_profile();
        $lang_key = strtoupper((string) $lang) === 'FR' ? 'fr' : 'nl';
        $manual = isset($profile['manual']) && is_array($profile['manual']) ? $profile['manual'] : [];
        $value = $lang_key === 'fr'
            ? (string) ($manual['base_url_fr'] ?? '')
            : (string) ($manual['base_url_nl'] ?? '');

        return trim($value);
    }
}

if (!function_exists('octopus_ai_get_provider_out_of_scope_text')) {
    function octopus_ai_get_provider_out_of_scope_text($lang, $default = '')
    {
        $profile = octopus_ai_get_provider_profile();
        $i18n = isset($profile['i18n']) && is_array($profile['i18n']) ? $profile['i18n'] : [];
        $lang_key = strtoupper((string) $lang) === 'FR' ? 'fr' : 'nl';
        $key = $lang_key === 'fr' ? 'out_of_scope_fr' : 'out_of_scope_nl';
        $value = sanitize_text_field((string) ($i18n[$key] ?? ''));

        return $value !== '' ? $value : sanitize_text_field((string) $default);
    }
}

if (!function_exists('octopus_ai_get_provider_fallback_text')) {
    function octopus_ai_get_provider_fallback_text($lang, $default = '')
    {
        $profile = octopus_ai_get_provider_profile();
        $i18n = isset($profile['i18n']) && is_array($profile['i18n']) ? $profile['i18n'] : [];
        $lang_key = strtoupper((string) $lang) === 'FR' ? 'fr' : 'nl';
        $key = $lang_key === 'fr' ? 'fallback_fr' : 'fallback_nl';
        $value = sanitize_text_field((string) ($i18n[$key] ?? ''));

        return $value !== '' ? $value : sanitize_text_field((string) $default);
    }
}
