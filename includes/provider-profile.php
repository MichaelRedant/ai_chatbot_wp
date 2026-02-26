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
            'topic_labels' => [
                'klantenportaal' => [
                    'nl' => 'Klantenportaal',
                    'fr' => 'Plateforme Digitale Interactive (PDI)',
                ],
                'boekhoudprogramma' => [
                    'nl' => 'Boekhoudprogramma',
                    'fr' => 'Logiciel de comptabilite',
                ],
            ],
            'topic_descriptions' => [
                'klantenportaal' => [
                    'nl' => 'Vragen over facturen, betalingen of support via het klantenportaal.',
                    'fr' => 'Questions sur les factures, paiements ou support dans la Plateforme Digitale Interactive (PDI).',
                ],
                'boekhoudprogramma' => [
                    'nl' => 'Vragen over boekhouding, btw of rapportages binnen het boekhoudprogramma.',
                    'fr' => 'Questions sur la comptabilite, TVA ou rapports dans le logiciel.',
                ],
            ],
            'intent_terms' => [
                'facturatie' => ['factuur', 'facturatie', 'verkoopfactuur', 'creditnota', 'invoice', 'facture', 'facturation', 'avoir'],
                'aankoop' => ['aankoop', 'aankoopfactuur', 'inkoop', 'leverancier', 'purchase', 'achat', 'fournisseur'],
                'btw' => ['btw', 'tva', 'intervat', 'btw-aangifte', 'declaration tva'],
                'bank' => ['bank', 'uittreksel', 'codaboek', 'reconciliatie', 'banque', 'releve', 'coda', 'rapprochement'],
                'integraties' => ['koppeling', 'integratie', 'api', 'webhook', 'mollie', 'woo', 'shop', 'integration'],
                'gebruikers' => ['gebruiker', 'toegang', 'rechten', 'utilisateur', 'acces', 'droits'],
                'instellingen' => ['instelling', 'configuratie', 'voorkeur', 'parametre', 'configuration', 'reglage'],
                'algemeen' => ['octopus', 'help', 'support', 'manuel', 'handleiding'],
            ],
            'intent_topic_map' => [
                'facturatie' => 'boekhoudprogramma',
                'aankoop' => 'boekhoudprogramma',
                'btw' => 'boekhoudprogramma',
                'bank' => 'boekhoudprogramma',
                'integraties' => 'klantenportaal',
                'gebruikers' => 'klantenportaal',
                'instellingen' => 'boekhoudprogramma',
            ],
            'retrieval' => [
                'topic_terms' => [
                    'klantenportaal' => [
                        'klantenportaal',
                        'portal',
                        'webportal',
                        'manualportal',
                        'manual_portal',
                        'pdi',
                        'client portal',
                        'portail',
                        'portail client',
                        'plateforme',
                        'plateforme digitale interactive',
                        'invoices',
                        'salesinvoices',
                        'purchaseinvoices',
                        'manualportal_',
                    ],
                    'boekhoudprogramma' => [
                        'boekhoud',
                        'boekhouding',
                        'boekhoudprogramma',
                        'accounting',
                        'accountingprogram',
                        'manual_accounting',
                        'tva',
                        'btw',
                        'journaal',
                        'journal',
                        'balans',
                        'ledger',
                    ],
                ],
                'topic_keywords' => [
                    'klantenportaal' => ['klantenportaal', 'klant', 'portal', 'login', 'factuur', 'betaling', 'support'],
                    'boekhoudprogramma' => ['boekhoud', 'boekhouding', 'boekhoudprogramma', 'btw', 'facturatie', 'journaal', 'balans', 'rapport', 'administratie'],
                ],
                'stopwords' => [
                    'vraag', 'vragen', 'reactie', 'gebruiker', 'vervolg', 'vorige', 'bericht', 'antwoord', 'antwoorden',
                    'question', 'questions', 'reponse', 'reponses', 'utilisateur', 'suite', 'precedente', 'message',
                    'follow', 'followup', 'previous',
                ],
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

        if (isset($profile['topic_labels']) && is_array($profile['topic_labels'])) {
            foreach ($profile['topic_labels'] as $topic => $labels) {
                $topic_key = sanitize_key((string) $topic);
                if ($topic_key === '' || !is_array($labels)) {
                    continue;
                }

                $label_nl = sanitize_text_field((string) ($labels['nl'] ?? ($labels['NL'] ?? '')));
                $label_fr = sanitize_text_field((string) ($labels['fr'] ?? ($labels['FR'] ?? '')));

                if ($label_nl !== '') {
                    $normalized['topic_labels'][$topic_key]['nl'] = $label_nl;
                }
                if ($label_fr !== '') {
                    $normalized['topic_labels'][$topic_key]['fr'] = $label_fr;
                }
            }
        }

        if (isset($profile['topic_descriptions']) && is_array($profile['topic_descriptions'])) {
            foreach ($profile['topic_descriptions'] as $topic => $descriptions) {
                $topic_key = sanitize_key((string) $topic);
                if ($topic_key === '' || !is_array($descriptions)) {
                    continue;
                }

                $description_nl = sanitize_text_field((string) ($descriptions['nl'] ?? ($descriptions['NL'] ?? '')));
                $description_fr = sanitize_text_field((string) ($descriptions['fr'] ?? ($descriptions['FR'] ?? '')));

                if ($description_nl !== '') {
                    $normalized['topic_descriptions'][$topic_key]['nl'] = $description_nl;
                }
                if ($description_fr !== '') {
                    $normalized['topic_descriptions'][$topic_key]['fr'] = $description_fr;
                }
            }
        }

        if (isset($profile['intent_terms']) && is_array($profile['intent_terms'])) {
            foreach ($profile['intent_terms'] as $intent => $terms) {
                $intent_key = sanitize_key((string) $intent);
                if ($intent_key === '') {
                    continue;
                }

                $clean_intent_terms = octopus_ai_sanitize_provider_terms($terms);
                if (!empty($clean_intent_terms)) {
                    $normalized['intent_terms'][$intent_key] = $clean_intent_terms;
                }
            }
        }

        $allowed_topics = array_values(array_unique(array_filter(array_merge(
            array_keys(is_array($normalized['topic_terms']) ? $normalized['topic_terms'] : []),
            array_keys(is_array($normalized['topic_labels']) ? $normalized['topic_labels'] : [])
        ))));
        if (empty($allowed_topics)) {
            $allowed_topics = ['klantenportaal', 'boekhoudprogramma'];
        }

        if (isset($profile['intent_topic_map']) && is_array($profile['intent_topic_map'])) {
            foreach ($profile['intent_topic_map'] as $intent => $topic) {
                $intent_key = sanitize_key((string) $intent);
                $topic_key = sanitize_key((string) $topic);
                if ($intent_key === '' || $topic_key === '' || !in_array($topic_key, $allowed_topics, true)) {
                    continue;
                }
                $normalized['intent_topic_map'][$intent_key] = $topic_key;
            }
        }

        $retrieval = isset($profile['retrieval']) && is_array($profile['retrieval']) ? $profile['retrieval'] : [];
        if (isset($retrieval['stopwords'])) {
            $clean_stopwords = octopus_ai_sanitize_provider_terms($retrieval['stopwords']);
            if (!empty($clean_stopwords)) {
                $normalized['retrieval']['stopwords'] = array_values(array_unique(array_merge(
                    is_array($normalized['retrieval']['stopwords']) ? $normalized['retrieval']['stopwords'] : [],
                    $clean_stopwords
                )));
            }
        }

        if (isset($retrieval['topic_terms']) && is_array($retrieval['topic_terms'])) {
            foreach ($retrieval['topic_terms'] as $topic => $terms) {
                $topic_key = sanitize_key((string) $topic);
                if ($topic_key === '') {
                    continue;
                }

                $clean_terms = octopus_ai_sanitize_provider_terms($terms);
                if (!empty($clean_terms)) {
                    $normalized['retrieval']['topic_terms'][$topic_key] = $clean_terms;
                }
            }
        }

        if (isset($retrieval['topic_keywords']) && is_array($retrieval['topic_keywords'])) {
            foreach ($retrieval['topic_keywords'] as $topic => $terms) {
                $topic_key = sanitize_key((string) $topic);
                if ($topic_key === '') {
                    continue;
                }

                $clean_terms = octopus_ai_sanitize_provider_terms($terms);
                if (!empty($clean_terms)) {
                    $normalized['retrieval']['topic_keywords'][$topic_key] = $clean_terms;
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

if (!function_exists('octopus_ai_get_provider_allowed_topics')) {
    function octopus_ai_get_provider_allowed_topics()
    {
        $profile = octopus_ai_get_provider_profile();
        $topic_terms = isset($profile['topic_terms']) && is_array($profile['topic_terms']) ? $profile['topic_terms'] : [];
        $topic_labels = isset($profile['topic_labels']) && is_array($profile['topic_labels']) ? $profile['topic_labels'] : [];
        $topics = array_values(array_unique(array_filter(array_merge(array_keys($topic_terms), array_keys($topic_labels)))));

        if (empty($topics)) {
            $topics = ['klantenportaal', 'boekhoudprogramma'];
        }

        return array_values(array_map('sanitize_key', $topics));
    }
}

if (!function_exists('octopus_ai_get_provider_topic_terms_map')) {
    function octopus_ai_get_provider_topic_terms_map()
    {
        $profile = octopus_ai_get_provider_profile();
        $topic_terms = isset($profile['topic_terms']) && is_array($profile['topic_terms']) ? $profile['topic_terms'] : [];
        $map = [];

        foreach ($topic_terms as $topic_key => $terms) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '' || !is_array($terms)) {
                continue;
            }

            $clean = [];
            foreach ($terms as $term) {
                $term = strtolower(trim(sanitize_text_field((string) $term)));
                if ($term === '' || in_array($term, $clean, true)) {
                    continue;
                }
                $clean[] = $term;
            }

            if (!empty($clean)) {
                $map[$topic_key] = $clean;
            }
        }

        return $map;
    }
}

if (!function_exists('octopus_ai_get_provider_topic_labels_map')) {
    function octopus_ai_get_provider_topic_labels_map()
    {
        $defaults = octopus_ai_get_default_provider_profile();
        $profile = octopus_ai_get_provider_profile();

        $default_labels = isset($defaults['topic_labels']) && is_array($defaults['topic_labels']) ? $defaults['topic_labels'] : [];
        $custom_labels = isset($profile['topic_labels']) && is_array($profile['topic_labels']) ? $profile['topic_labels'] : [];
        $topics = array_values(array_unique(array_filter(array_merge(array_keys($default_labels), array_keys($custom_labels), octopus_ai_get_provider_allowed_topics()))));
        $labels = [];

        foreach ($topics as $topic_key) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '') {
                continue;
            }

            $default_topic_labels = isset($default_labels[$topic_key]) && is_array($default_labels[$topic_key]) ? $default_labels[$topic_key] : [];
            $custom_topic_labels = isset($custom_labels[$topic_key]) && is_array($custom_labels[$topic_key]) ? $custom_labels[$topic_key] : [];

            $label_nl = sanitize_text_field((string) ($custom_topic_labels['nl'] ?? ($custom_topic_labels['NL'] ?? ($default_topic_labels['nl'] ?? ($default_topic_labels['NL'] ?? '')))));
            $label_fr = sanitize_text_field((string) ($custom_topic_labels['fr'] ?? ($custom_topic_labels['FR'] ?? ($default_topic_labels['fr'] ?? ($default_topic_labels['FR'] ?? '')))));

            if ($label_nl === '') {
                $label_nl = ucfirst(str_replace('_', ' ', $topic_key));
            }
            if ($label_fr === '') {
                $label_fr = $label_nl;
            }

            $labels[$topic_key] = [
                'nl' => $label_nl,
                'fr' => $label_fr,
            ];
        }

        return $labels;
    }
}

if (!function_exists('octopus_ai_get_provider_topic_descriptions_map')) {
    function octopus_ai_get_provider_topic_descriptions_map()
    {
        $defaults = octopus_ai_get_default_provider_profile();
        $profile = octopus_ai_get_provider_profile();

        $default_descriptions = isset($defaults['topic_descriptions']) && is_array($defaults['topic_descriptions']) ? $defaults['topic_descriptions'] : [];
        $custom_descriptions = isset($profile['topic_descriptions']) && is_array($profile['topic_descriptions']) ? $profile['topic_descriptions'] : [];
        $topics = array_values(array_unique(array_filter(array_merge(array_keys($default_descriptions), array_keys($custom_descriptions), octopus_ai_get_provider_allowed_topics()))));
        $descriptions = [];

        foreach ($topics as $topic_key) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '') {
                continue;
            }

            $default_topic_descriptions = isset($default_descriptions[$topic_key]) && is_array($default_descriptions[$topic_key]) ? $default_descriptions[$topic_key] : [];
            $custom_topic_descriptions = isset($custom_descriptions[$topic_key]) && is_array($custom_descriptions[$topic_key]) ? $custom_descriptions[$topic_key] : [];

            $desc_nl = sanitize_text_field((string) ($custom_topic_descriptions['nl'] ?? ($custom_topic_descriptions['NL'] ?? ($default_topic_descriptions['nl'] ?? ($default_topic_descriptions['NL'] ?? '')))));
            $desc_fr = sanitize_text_field((string) ($custom_topic_descriptions['fr'] ?? ($custom_topic_descriptions['FR'] ?? ($default_topic_descriptions['fr'] ?? ($default_topic_descriptions['FR'] ?? '')))));

            $descriptions[$topic_key] = [
                'nl' => $desc_nl,
                'fr' => $desc_fr !== '' ? $desc_fr : $desc_nl,
            ];
        }

        return $descriptions;
    }
}

if (!function_exists('octopus_ai_get_provider_topic_label')) {
    function octopus_ai_get_provider_topic_label($topic, $lang = 'NL', $default = '')
    {
        $topic = sanitize_key((string) $topic);
        $lang = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';
        if ($topic === '') {
            return sanitize_text_field((string) $default);
        }

        $labels = octopus_ai_get_provider_topic_labels_map();
        if (isset($labels[$topic]) && is_array($labels[$topic])) {
            $label = $lang === 'FR'
                ? sanitize_text_field((string) ($labels[$topic]['fr'] ?? ''))
                : sanitize_text_field((string) ($labels[$topic]['nl'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return sanitize_text_field((string) ($default !== '' ? $default : ucfirst(str_replace('_', ' ', $topic))));
    }
}

if (!function_exists('octopus_ai_get_provider_topic_description')) {
    function octopus_ai_get_provider_topic_description($topic, $lang = 'NL', $default = '')
    {
        $topic = sanitize_key((string) $topic);
        $lang = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';
        if ($topic === '') {
            return sanitize_text_field((string) $default);
        }

        $descriptions = octopus_ai_get_provider_topic_descriptions_map();
        if (isset($descriptions[$topic]) && is_array($descriptions[$topic])) {
            $description = $lang === 'FR'
                ? sanitize_text_field((string) ($descriptions[$topic]['fr'] ?? ''))
                : sanitize_text_field((string) ($descriptions[$topic]['nl'] ?? ''));
            if ($description !== '') {
                return $description;
            }
        }

        return sanitize_text_field((string) $default);
    }
}

if (!function_exists('octopus_ai_get_provider_intent_terms_map')) {
    function octopus_ai_get_provider_intent_terms_map()
    {
        $defaults = octopus_ai_get_default_provider_profile();
        $profile = octopus_ai_get_provider_profile();

        $default_map = isset($defaults['intent_terms']) && is_array($defaults['intent_terms']) ? $defaults['intent_terms'] : [];
        $custom_map = isset($profile['intent_terms']) && is_array($profile['intent_terms']) ? $profile['intent_terms'] : [];
        $merged = $default_map;

        foreach ($custom_map as $intent_key => $terms) {
            $intent_key = sanitize_key((string) $intent_key);
            if ($intent_key === '' || !is_array($terms)) {
                continue;
            }

            if (!isset($merged[$intent_key]) || !is_array($merged[$intent_key])) {
                $merged[$intent_key] = [];
            }

            foreach ($terms as $term) {
                $term = strtolower(trim(sanitize_text_field((string) $term)));
                if ($term === '' || in_array($term, $merged[$intent_key], true)) {
                    continue;
                }
                $merged[$intent_key][] = $term;
            }
        }

        return $merged;
    }
}

if (!function_exists('octopus_ai_get_provider_intent_topic_map')) {
    function octopus_ai_get_provider_intent_topic_map()
    {
        $defaults = octopus_ai_get_default_provider_profile();
        $profile = octopus_ai_get_provider_profile();
        $allowed_topics = octopus_ai_get_provider_allowed_topics();

        $map = isset($defaults['intent_topic_map']) && is_array($defaults['intent_topic_map'])
            ? $defaults['intent_topic_map']
            : [];
        $custom_map = isset($profile['intent_topic_map']) && is_array($profile['intent_topic_map'])
            ? $profile['intent_topic_map']
            : [];

        foreach ($custom_map as $intent_key => $topic_key) {
            $intent_key = sanitize_key((string) $intent_key);
            $topic_key = sanitize_key((string) $topic_key);
            if ($intent_key === '' || $topic_key === '' || !in_array($topic_key, $allowed_topics, true)) {
                continue;
            }
            $map[$intent_key] = $topic_key;
        }

        return $map;
    }
}

if (!function_exists('octopus_ai_get_provider_retrieval_topic_terms_map')) {
    function octopus_ai_get_provider_retrieval_topic_terms_map()
    {
        $defaults = octopus_ai_get_default_provider_profile();
        $profile = octopus_ai_get_provider_profile();

        $default_map = isset($defaults['retrieval']['topic_terms']) && is_array($defaults['retrieval']['topic_terms'])
            ? $defaults['retrieval']['topic_terms']
            : [];
        $custom_map = isset($profile['retrieval']['topic_terms']) && is_array($profile['retrieval']['topic_terms'])
            ? $profile['retrieval']['topic_terms']
            : [];
        $base_topic_terms = octopus_ai_get_provider_topic_terms_map();
        $topics = array_values(array_unique(array_filter(array_merge(array_keys($default_map), array_keys($custom_map), array_keys($base_topic_terms), octopus_ai_get_provider_allowed_topics()))));
        $map = [];

        foreach ($topics as $topic_key) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '') {
                continue;
            }

            $terms = [];
            $candidates = [];
            if (isset($default_map[$topic_key]) && is_array($default_map[$topic_key])) {
                $candidates = array_merge($candidates, $default_map[$topic_key]);
            }
            if (isset($custom_map[$topic_key]) && is_array($custom_map[$topic_key])) {
                $candidates = array_merge($candidates, $custom_map[$topic_key]);
            }
            if (isset($base_topic_terms[$topic_key]) && is_array($base_topic_terms[$topic_key])) {
                $candidates = array_merge($candidates, $base_topic_terms[$topic_key]);
            }

            foreach ($candidates as $term) {
                $term = strtolower(trim(sanitize_text_field((string) $term)));
                if ($term === '' || in_array($term, $terms, true)) {
                    continue;
                }
                $terms[] = $term;
            }

            if (!empty($terms)) {
                $map[$topic_key] = $terms;
            }
        }

        return $map;
    }
}

if (!function_exists('octopus_ai_get_provider_retrieval_topic_keywords_map')) {
    function octopus_ai_get_provider_retrieval_topic_keywords_map()
    {
        $defaults = octopus_ai_get_default_provider_profile();
        $profile = octopus_ai_get_provider_profile();

        $default_map = isset($defaults['retrieval']['topic_keywords']) && is_array($defaults['retrieval']['topic_keywords'])
            ? $defaults['retrieval']['topic_keywords']
            : [];
        $custom_map = isset($profile['retrieval']['topic_keywords']) && is_array($profile['retrieval']['topic_keywords'])
            ? $profile['retrieval']['topic_keywords']
            : [];
        $base_topic_terms = octopus_ai_get_provider_topic_terms_map();
        $topics = array_values(array_unique(array_filter(array_merge(array_keys($default_map), array_keys($custom_map), array_keys($base_topic_terms), octopus_ai_get_provider_allowed_topics()))));
        $map = [];

        foreach ($topics as $topic_key) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '') {
                continue;
            }

            $terms = [];
            $candidates = [];
            if (isset($default_map[$topic_key]) && is_array($default_map[$topic_key])) {
                $candidates = array_merge($candidates, $default_map[$topic_key]);
            }
            if (isset($custom_map[$topic_key]) && is_array($custom_map[$topic_key])) {
                $candidates = array_merge($candidates, $custom_map[$topic_key]);
            }
            if (empty($candidates) && isset($base_topic_terms[$topic_key]) && is_array($base_topic_terms[$topic_key])) {
                $candidates = $base_topic_terms[$topic_key];
            }

            foreach ($candidates as $term) {
                $term = strtolower(trim(sanitize_text_field((string) $term)));
                if ($term === '' || in_array($term, $terms, true)) {
                    continue;
                }
                $terms[] = $term;
            }

            if (!empty($terms)) {
                $map[$topic_key] = $terms;
            }
        }

        return $map;
    }
}

if (!function_exists('octopus_ai_get_provider_retrieval_stopwords')) {
    function octopus_ai_get_provider_retrieval_stopwords()
    {
        $defaults = octopus_ai_get_default_provider_profile();
        $profile = octopus_ai_get_provider_profile();
        $base_stopwords = isset($defaults['retrieval']['stopwords']) && is_array($defaults['retrieval']['stopwords'])
            ? $defaults['retrieval']['stopwords']
            : [];
        $custom_stopwords = isset($profile['retrieval']['stopwords']) && is_array($profile['retrieval']['stopwords'])
            ? $profile['retrieval']['stopwords']
            : [];
        $merged = [];

        foreach (array_merge($base_stopwords, $custom_stopwords) as $stopword) {
            $stopword = strtolower(trim(sanitize_text_field((string) $stopword)));
            if ($stopword === '' || in_array($stopword, $merged, true)) {
                continue;
            }
            $merged[] = $stopword;
        }

        return $merged;
    }
}

if (!function_exists('octopus_ai_get_provider_topic_choices')) {
    function octopus_ai_get_provider_topic_choices()
    {
        $topics = octopus_ai_get_provider_allowed_topics();
        $choices = [];

        foreach ($topics as $topic_key) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '') {
                continue;
            }

            $label_nl = octopus_ai_get_provider_topic_label($topic_key, 'NL', ucfirst(str_replace('_', ' ', $topic_key)));
            $label_fr = octopus_ai_get_provider_topic_label($topic_key, 'FR', $label_nl);
            $desc_nl = octopus_ai_get_provider_topic_description($topic_key, 'NL', '');
            $desc_fr = octopus_ai_get_provider_topic_description($topic_key, 'FR', $desc_nl);

            $choices[] = [
                'key' => $topic_key,
                'label_nl' => $label_nl,
                'label_fr' => $label_fr,
                'desc_nl' => $desc_nl,
                'desc_fr' => $desc_fr,
            ];
        }

        return $choices;
    }
}
