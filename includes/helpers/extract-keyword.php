<?php
if (!defined('ABSPATH')) exit;

/**
 * Haalt het belangrijkste keyword uit de vraag voor fallback-zoeklink.
 */
function octopus_ai_extract_keyword($message) {
    $message = strtolower(strip_tags($message));
    $message = preg_replace('/[^\p{L}\p{N}\s]/u', '', $message); // verwijder leestekens
    $woorden = preg_split('/\s+/', $message, -1, PREG_SPLIT_NO_EMPTY);

    $stopwoorden = ['hoe', 'kan', 'ik', 'de', 'het', 'een', 'en', 'of', 'je', 'mijn', 'is', 'te', 'in', 'met', 'op', 'aan', 'voor', 'wat', 'waar', 'welke', 'zijn'];
    $relevante = array_diff($woorden, $stopwoorden);

    usort($relevante, fn($a, $b) => strlen($b) <=> strlen($a));
    return $relevante[0] ?? null;
}
