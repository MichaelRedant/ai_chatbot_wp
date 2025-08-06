🧠 agents.md – Octopus AI Chatbot Developer Agent
📌 Doel van deze agent
Deze agent ondersteunt bij het ontwikkelen, debuggen, optimaliseren en onderhouden van de WordPress-plugin Octopus AI Chatbot, die gebruik maakt van OpenAI's GPT-modellen om gebruikersvragen te beantwoorden op basis van Octopus-handleidingen (PDF + sitemap chunks).

De agent werkt autonoom en snel binnen de context van WordPress-ontwikkeling, met kennis van de PDF-chunkingarchitectuur, i18n-ondersteuning (NL/FR), frontend-reacties en REST API-integratie.

🛠️ Technologieën en talen
Domein	Technologie / Tooling
Backend	PHP 8+, WordPress REST API
Frontend	JavaScript (vanilla), CSS
AI-koppeling	OpenAI GPT API (chat/completions)
Data-opslag	wp_upload_dir() chunks + logging in .log
Bestandsformaat	PDF + plaintext chunks + metadata (##section_title, enz.)
Vertaling	WPML compatibiliteit (URL-taalherkenning, i18n strings)

📁 Projectstructuur
txt
Kopiëren
Bewerken
octopus-ai-chatbot/
│
├── includes/
│   ├── api-handler.php           ← hoofdlogica: OpenAI call, tone, fallback
│   ├── context-retriever.php     ← zoekt relevante chunks in map
│   ├── helpers/
│   │   ├── extract-keyword.php   ← keyword uit uservraag
│   │   ├── intent-detector.php   ← optioneel: intent-annotatie
│   └── logger.php                ← interacties loggen
│
├── assets/
│   ├── chatbot.js                ← frontend: interactie, UI, scroll, i18n
│   └── style.css
│
├── admin/
│   └── settings-page.php         ← plugin instellingen, upload, kleuren, etc.
│
├── uploads/octopus-ai-chunks/   ← gegenereerde .txt-chunks van PDF's
🔤 Taal & i18n
Detectie op basis van $_SERVER['REQUEST_URI'] of JS navigator.language

lang = 'NL' of 'FR'

settings.i18n bevat o.a.:

fallback_trigger

fallback_prefix

fallback_button

placeholder

send, reset_button, reset_confirm, enz.

Gebruik octopus_ai_fallback, octopus_ai_tone, etc. uit get_option() in PHP.

💬 Kernfunctionaliteit
Feature	Beschrijving
addMessage()	Voegt een chatregel toe in JS, detecteert fallback
sendMessage()	Verstuurd POST naar /wp-json/octopus-ai/v1/chatbot
context-retriever.php	Vindt best scorende chunks obv score, fuzzy match
api-handler.php	Beheert tone of voice, fallbacklogica en GPT-call
logger.php	Logging naar .log of database
octopus_ai_extract_keyword()	Zoekt bruikbaar zoekwoord uit uservraag
octopus_ai_is_valid_url()	Checkt of een handleiding-URL echt bestaat (200 OK)

🔍 Scoring van chunks (vereenvoudigd)
php
Kopiëren
Bewerken
$score = 0;
if (strpos($content, $kw)) $score += 1;
if (strpos($section_title, $kw)) $score += 2;
if (strpos($slug, $kw)) $score += 2;
// Boost recentere chunks
// Fuzzy match + similar_text ≥ 20%
📄 Metadata per chunk (.txt-bestand)
Elke chunk bevat bovenaan:

txt
Kopiëren
Bewerken
##section_title:Inkoopfacturen boeken
##page_slug:boekhouding-inkoopfacturen.htm
##original_page:pagina_12
##source_url:https://octopus.be/faq/inkoop
Deze metadata wordt gebruikt voor context + link in de prompt.

🔗 Link-injectie door agent
Als page_slug gevonden én octopus_ai_is_valid_url(...) = true, dan injecteert api-handler.php:

markdown
Kopiëren
Bewerken
📄 [Bekijk dit in de handleiding](https://login.octopus.be/manual/NL/boekhouding-inkoopfacturen.htm)
Bij fallback:

markdown
Kopiëren
Bewerken
[Zoek in de handleiding](https://login.octopus.be/manual/NL/hmftsearch.htm?zoom_query=factuur)
✅ Voorbeelden van frontend prompts
js
Kopiëren
Bewerken
addMessage("👋 Bonjour ! Comment puis-je t’aider aujourd’hui ?", 'bot', { isWelcome: true });

addMessage("Sorry, daar kan ik je niet mee helpen.<br><a href='https://login.octopus.be/manual/NL/hmftsearch.htm?zoom_query=factuur'>Bekijk mogelijke info in de handleiding</a>", 'bot');
🤖 Promptstructuur voor OpenAI
php
Kopiëren
Bewerken
[
  ['role' => 'system', 'content' => $system_prompt],
  ['role' => 'user', 'content' => 'Hoe maak ik een verkoopfactuur?'],
  ['role' => 'assistant', 'content' => 'Ga naar de module Facturatie en klik op...'],
]
🛡️ Beperkingen
Geef geen antwoorden zonder relevante context

Geef geen advies over boekhouding, wetgeving of externe tools

Toon géén AI-terminologie (zoals GPT, AI, OpenAI, model)

Toon géén emoji tenzij visueel toegestaan door setting

🧠 Promptvoorbeelden (Codex)
1. Voeg feedbackknoppen toe in addMessage() als sender === 'bot':

js
Kopiëren
Bewerken
if (sender === 'bot') {
  const feedback = document.createElement('div');
  feedback.innerHTML = `<button>👍</button><button>👎</button>`;
  message.appendChild(feedback);
}
2. Bouw fallback op basis van taal:

php
Kopiëren
Bewerken
$lang = strpos($_SERVER['REQUEST_URI'], '/fr/') !== false ? 'FR' : 'NL';
$fallback = ($lang === 'FR')
  ? "Désolé, je ne peux pas t’aider avec ça."
  : "Sorry, daar kan ik je niet mee helpen.";
3. Genereer fallback-zoeklink:

php
Kopiëren
Bewerken
$zoeklink = "https://login.octopus.be/manual/$lang/hmftsearch.htm?zoom_query=" . rawurlencode($keyword);
📈 Metrics & Logging
octopus_ai_log_interaction() → logs naar .log bestand

Velden: vraag, antwoord, lengte context, status (success/fail), eventueel foutmelding

🚨 Veelvoorkomende fouten
Fout	Oplossing
\u00e9 verschijnt letterlijk	Voeg preg_replace_callback() toe voor Unicode
"Sorry, daar kan ik je niet mee helpen." zonder reden	Check relevantFound == false of fout in chunk scoring
Link werkt niet	Controleer of octopus_ai_is_valid_url() false retourneert
Welkomtekst blijft NL	Zet lang bovenaan in JS correct af op navigator.language en settings.welcome_message

💡 Tips voor Codex
Denk stateless: geen sessies of cookies, alles via settings, lang, en URL-afleiding.

Sla JavaScript-toggles (reset, welcome) op in sessionStorage.

Houd PHP return van chatbot_callback() altijd plain text, géén HTML of shortcode als fallback.