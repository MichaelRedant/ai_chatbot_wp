=== AI Chatbot ===
Contributors: michaelredant
Tags: ai, chatbot, support, openai
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
AI Chatbot plugin voor WordPress met:
- OpenAI chat integratie
- document retrieval via PDF en sitemap chunks
- NL/FR ondersteuning
- logging en feedback in WordPress database
- floating widget en Elementor widget modus

== Credits ==
Ontwikkeld en onderhouden door Michaël Redant.

== Installation ==
1. Upload de plugin naar `wp-content/plugins/ai-chatbot`.
2. Activeer de plugin in WordPress.
3. Open `Octopus AI Chatbot` in het admin menu.
4. Vul je API-key in en configureer de databron.

== Changelog ==
= 0.8 =
* Security hardening op output en history-validatie.
* Rate limiting op chatbot en feedback endpoints.
* Feedback opslag gekoppeld aan chat logs via `chat_id`.
* Snellere retrieval met gecachte chunk-index.
* Sitemap chunknamen uniek gemaakt via URL-hash.
* Configuratie-consistentie voor welkomstteksten NL/FR.
