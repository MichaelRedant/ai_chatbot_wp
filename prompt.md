🧠 prompts.md – Octopus AI Chatbot Promptbibliotheek
Deze file bevat richtlijnen en concrete voorbeelden om correcte prompts te genereren voor de Octopus AI Chatbot via de OpenAI API (chat/completions endpoint).

📌 Doel van prompts
Prompts moeten:

De juiste tone of voice gebruiken op basis van lang (NL/FR)

Contextuele chunks injecteren (handleidingen)

Reageren met duidelijke, korte antwoorden

Geen onwaarheden, AI-verwijzingen of juridische antwoorden geven

Valide links inbouwen indien beschikbaar

🌍 Taalherkenning en tone switching
php
Kopiëren
Bewerken
$lang = (strpos($_SERVER['REQUEST_URI'], '/fr/') !== false) ? 'FR' : 'NL';

$tone = ($lang === 'FR') ? <<<EOT
🎯 Objectif
Tu es un chatbot professionnel qui aide les clients à utiliser Octopus de manière claire, efficace et conviviale.
...
Contexte :
EOT
: <<<EOT
Je bent een AI-chatbot die klanten professioneel, duidelijk en kort helpt bij het gebruik van deze software.
...
Context:
EOT;
Gebruik dit tone altijd als eerste system prompt.

📄 Structuur van prompts
json
Kopiëren
Bewerken
[
  {
    "role": "system",
    "content": "🎯 Objectif ... (tone of voice met context)"
  },
  {
    "role": "user",
    "content": "Hoe boek ik een verkoopfactuur?"
  },
  {
    "role": "assistant",
    "content": "Open het menu Facturatie..."
  },
  ...
]
Belangrijk: voeg alleen relevante history toe (laatste 6–12 berichten).

🔗 Injectie van chunk-links (indien page_slug beschikbaar)
markdown
Kopiëren
Bewerken
- *Facturen aanmaken*  
  📄 [Bekijk dit in de handleiding](https://login.octopus.be/manual/NL/facturen-aanmaken.htm)
Of in FR:

markdown
Kopiëren
Bewerken
- *Création de factures*  
  📄 [Voir dans le manuel](https://login.octopus.be/manual/FR/factures-creer.htm)
Gebruik dit enkel wanneer octopus_ai_is_valid_url() true geeft.

❌ Fallback prompt (zonder context)
Indien geen relevante chunks gevonden zijn:

markdown
Kopiëren
Bewerken
Sorry, daar kan ik je niet mee helpen.

[Bekijk mogelijke info in de handleiding](https://login.octopus.be/manual/NL/hmftsearch.htm?zoom_query=factuur)
In het Frans:

markdown
Kopiëren
Bewerken
Désolé, je ne peux pas t’aider avec ça.

[Voir aussi dans la documentation](https://login.octopus.be/manual/FR/hmftsearch.htm?zoom_query=facture)
✅ Promptgenerator voor Codex
php
Kopiëren
Bewerken
function build_prompt($tone, $context, $history) {
    $messages = [['role' => 'system', 'content' => $tone . "\n\nContext:\n" . $context]];
    foreach ($history as $entry) {
        $messages[] = [
            'role' => sanitize_text_field($entry['role']),
            'content' => sanitize_textarea_field($entry['content']),
        ];
    }
    return $messages;
}
🎯 Prompt best practices
Doel	Richtlijn
Tone switching	Gebruik lang = FR of NL, en geef correcte tone vooraf
Linkinjectie	Voeg alleen toe als URL geldig is
Geen output?	Controleer of prompt te lang is, of context leeg
Fallback activeren	Wanneer geen chunks of lege response
Unicode fixen	Decode \uXXXX via mb_convert_encoding(pack(...))
Session chaining	Geef maximaal 6–12 history-berichten door
No-go onderwerpen	Wetgeving, externe tools, AI-discussie, boekhoudregels

💬 Prompt templates
1. Prompt mét context (NL):

plaintext
Kopiëren
Bewerken
🎯 Doel:
Help gebruikers vlot en duidelijk met hun vraag over Octopus.

🗣️ Tone:
Vlaams, professioneel, geen AI- of techverwijzingen.

Context:
Open het facturatiescherm via het linkermenu. Klik op ‘Nieuw document’...

Vraag:
Hoe maak ik een factuur op?

Antwoord:
Ga naar het menu Facturatie. Kies voor ‘Nieuwe verkoopfactuur’. Vul klantgegevens in en selecteer producten...
2. Prompt zonder context (fallback – FR):

plaintext
Kopiëren
Bewerken
Désolé, je ne peux pas t’aider avec ça.

[Voir aussi dans la documentation](https://login.octopus.be/manual/FR/hmftsearch.htm?zoom_query=mandat)
📦 Exporteerbare JSON voor Codex
json
Kopiëren
Bewerken
{
  "lang": "NL",
  "model": "gpt-4.1-mini",
  "messages": [
    {
      "role": "system",
      "content": "Je bent een AI-chatbot ... Context: Ga naar de module Boekhouding..."
    },
    {
      "role": "user",
      "content": "Hoe boek ik een aankoopfactuur?"
    }
  ]
}
🔧 Aanbevolen instellingen OpenAI-call
php
Kopiëren
Bewerken
[
  'model'    => 'gpt-4.1-mini',
  'messages' => $messages,
  'temperature' => 0.3,
  'max_tokens' => 500,
  'top_p' => 1,
  'frequency_penalty' => 0,
  'presence_penalty' => 0
]