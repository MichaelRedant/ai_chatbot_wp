Must-have (stabiel + schaalbaar)

Security hardening: geen do_shortcode op model-output, sanitize markdown/html strikt.
Abuse control: server-side rate limiting + request quotas per IP/site.
Retrieval herwerken naar index/embeddings (geen full file scan per request).
Feedback en logging normaliseren naar 1 datastore (DB), inclusief referentie naar chat-id.
Prompt-integriteit: client history beperken (liefst enkel user turns server-side valideren).
Chunk naming uniek maken op full URL hash.
Config consistent maken (welcome message, menu slugs, source modes).
Good-to-have

Async ingestion (PDF/sitemap) via background jobs i.p.v. sync admin requests.
OpenAI error handling uitbreiden met retries/backoff/circuit breaker.
DB indexen op datum, status, feedback voor logschaalbaarheid.
Multi-widget support (nu blokkeert globale init op pagina’s met meerdere widgets: chatbot.js (line 2), chatbot.js (line 74)).
E2E + regressietests met echte accountancy use-cases.
Quality of Life

Adminpagina opsplitsen (nu erg monolithisch) en inline CSS/JS uit de PHP halen.
Bron-health dashboard (dekking, stale chunks, fallback ratio per topic).
Config export/import (JSON) voor snelle rollout naar andere klanten.
Duidelijke “provider profile” laag voor hergebruik per bedrijf.
Voor herbruikbaarheid (accountancy-specifiek maar portable)

Maak een provider config (JSON/option) voor merk, domeintermen, handleiding-URL’s, fallbacks.
Verplaats hardcoded Octopus-kennis naar configuratie: api-handler.php (line 177), api-handler.php (line 185), live-manual.php (line 68).
Houd core generiek (retrieval, logging, UI), en domain-specifieke regels in adapters/profielen.