<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('octopus_ai_get_fr_glossary_map')) {
    /**
     * Terminologievoorkeuren voor FR-antwoorden.
     * Gebaseerd op: Woordenlijst Octopus (NL/FR).
     *
     * @return array<string,string>
     */
    function octopus_ai_get_fr_glossary_map()
    {
        $map = [
            'klantenportaal' => 'Plateforme Digitale Interactive (PDI)',
            'portail client' => 'Plateforme Digitale Interactive (PDI)',
            'plateforme digitale interactive, pdi' => 'Plateforme Digitale Interactive (PDI)',
            'boekhoudprogramma' => 'logiciel comptable',

            'back-upaanvraag' => 'demande de backup',
            'back-upbestand' => 'backup / copie de securite',
            'sauvegarde' => 'backup',
            'multifactorauthenticatie' => 'authentification multifactorielle',
            'authentification multifacteur' => 'authentification multifactorielle',

            'creditnota' => 'note de credit',
            'e-mailadres' => 'adresse e-mail',
            'mailbox' => 'adresse e-mail',

            'archiefdossier' => "dossier d'archive",
            'gearchiveerd dossier' => 'dossier archive',
            "fichier d'archive" => "dossier d'archive",
            'archieflicentie' => "Licence d'archivage",

            'btw-aangifte' => 'declaration de TVA',
            'btw brief' => "lettre d'accompagnement (de la declaration de TVA)",
            'btw-brief' => "lettre d'accompagnement (de la declaration de TVA)",
            'btw' => 'TVA',
            'htva' => 'HTVA',
            'btw aftrekbaar' => 'TVA deductible',

            'facturatietool' => 'module de facturation',
            'facturatiemodule' => 'module de facturation',
            'bankmodule' => 'module bancaire',
            'facturatie' => 'facturation',
            'dagboeken' => 'journaux',
            'dagboek' => 'journal',
            'divers dagboek' => 'journal des Operations Diverses (journal d OD)',
            'journaalpost' => 'encodage',
            'boeking' => 'encodage',
            'grootboek' => 'grand livre',
            'balans' => 'balance',
            'verlies- en winst rekening' => 'compte de resultats (CDR)',
            'verlies- en winstrekening' => 'compte de resultats (CDR)',

            'marge-regeling' => 'vente a la marge',
            'intracommunautaire levering' => 'livraison intracommunautaire',
            'rekeninguittreksel' => 'extrait de compte',
            'openstaande posten' => 'postes ouverts',
            'periodieke afsluiting' => 'cloture des comptes',
            'jaarafsluiting' => 'cloture annuelle (fin d exercice)',
            'verkoopfactuur' => 'facture de vente',
            'aankoopfactuur' => "facture d'achat",
            'e-facturatie' => 'e-facturation',
            'e-factuur' => 'facture electronique structuree',
            'factuurvermelding' => 'mention sur la facture',
            'bijlage' => 'annexe',
            'ubl-bestand' => 'fichier UBL',
            'peppol-id' => 'identifiant Peppol',
            'klantreferentie' => 'reference client',
            'referentieveld' => 'champ de reference',
            'opmerkingsveld' => 'champ de commentaire',

            'btw-code' => 'code TVA',
            'btw-regeling' => 'regime TVA',
            'btw-tarief' => 'taux de TVA',
            'btw-listing' => 'listing TVA',
            'btw-carrousel' => 'carrousel TVA',
            'verleggingsregeling' => 'autoliquidation',
            'medecontractant' => 'cocontractant',
            'intrastat-aangifte' => 'declaration Intrastat',
            'coda-bestand' => 'fichier CODA',
            'bankkoppeling' => 'liaison bancaire',
            'automatische verwerking' => 'traitement automatique',
            'ocr-herkenning' => 'reconnaissance OCR',
            'gebruikersrechten' => "droits d'utilisateurs",
            'instellingen' => 'parametres',
            'importeren' => 'importer',
            'exporteren' => 'exporter',
            'digitale goedkeuring' => 'approbation numerique',
            'workflow' => 'flux de travail',
            'overzichtsscherm' => 'tableau de bord',
            'activiteitenlogboek' => "registre d'activites",

            'melding' => 'notification',
            'notificatie' => 'notification',
            'synchroniseren' => 'synchroniser',
            'uploaden' => 'telecharger vers',
            'downloaden' => 'telecharger de',
            'gebruiker' => 'utilisateur',
            'beheerder' => 'administrateur',
            'dossierbeheerder' => 'gestionnaire de dossier',
            'subgebruiker' => 'acces pour un collaborateur supplementaire',
            'medewerker' => 'collaborateur',
            'samenwerking starten' => 'demarrer une collaboration',
            'toegangsverzoek' => "demande d'acces",
        ];

        $map = apply_filters('octopus_ai_fr_glossary_map', $map);
        return is_array($map) ? $map : [];
    }
}

if (!function_exists('octopus_ai_apply_fr_glossary')) {
    /**
     * Past de FR-woordenlijst toe op tekst, zonder URLs te muteren.
     *
     * @param string $text
     * @return string
     */
    function octopus_ai_apply_fr_glossary($text)
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        $protected_urls = [];
        $text = preg_replace_callback(
            '/https?:\/\/[^\s)\]]+/i',
            static function ($matches) use (&$protected_urls) {
                $token = '__OCTOPUS_URL_' . count($protected_urls) . '__';
                $protected_urls[$token] = (string) ($matches[0] ?? '');
                return $token;
            },
            $text
        );

        $map = octopus_ai_get_fr_glossary_map();
        if (empty($map)) {
            foreach ($protected_urls as $token => $url) {
                $text = str_replace($token, $url, $text);
            }
            return $text;
        }

        // Langste termen eerst, om overlap (bv. btw vs btw-aangifte) te vermijden.
        uksort(
            $map,
            static function ($a, $b) {
                $len_a = function_exists('mb_strlen') ? mb_strlen((string) $a) : strlen((string) $a);
                $len_b = function_exists('mb_strlen') ? mb_strlen((string) $b) : strlen((string) $b);
                return $len_b <=> $len_a;
            }
        );

        foreach ($map as $source => $replacement) {
            $source = trim((string) $source);
            $replacement = trim((string) $replacement);
            if ($source === '' || $replacement === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($source, '/') . '(?![\p{L}\p{N}])/iu';
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        foreach ($protected_urls as $token => $url) {
            $text = str_replace($token, $url, $text);
        }

        return $text;
    }
}

if (!function_exists('octopus_ai_apply_language_glossary')) {
    /**
     * Toepassen van taalgebonden terminologie.
     *
     * @param string $text
     * @param string $lang
     * @return string
     */
    function octopus_ai_apply_language_glossary($text, $lang = 'NL')
    {
        $lang = strtoupper((string) $lang) === 'FR' ? 'FR' : 'NL';
        if ($lang !== 'FR') {
            return (string) $text;
        }

        return octopus_ai_apply_fr_glossary((string) $text);
    }
}
