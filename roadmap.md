# AI Chatbot Roadmap (zonder nieuwe database)

Dit plan houdt expliciet rekening met de beperking: **geen nieuwe database/tabellen**.

## Doelen

1. Correctere antwoorden met minder hallucinaties.
2. Betere brontransparantie per antwoord.
3. Betere escalatie als de chatbot geen betrouwbare oplossing heeft.
4. Herbruikbaarheid voor andere accountancy-software via configuratie.

## KPI's

1. **Fallback ratio** daalt of blijft stabiel, maar met hogere juistheid.
2. **Verkeerde-link ratio** daalt.
3. **Flow-mismatch antwoorden** dalen.
4. **Gemiddelde responstijd** blijft aanvaardbaar.

## Fase 0 - Basis (reeds aanwezig)

1. Dual-flow gedrag zonder harde lock (geen flow = beide flows).
2. Linksanitizing + anti-hallucinatie instructies.
3. Top links in antwoord en provider-profiel voor hergebruik.

## Fase 1 - Betrouwbaarheid first (nu implementeren)

1. **Confidence gating**:
   - Bereken confidence op basis van contextkwaliteit, bronnen en retrieval-signalen.
   - Onder drempel: geen gokantwoord, maar gecontroleerde fallback.
2. **Low-confidence fallback met bronnen**:
   - Toon betrouwbare handleidinglinks.
   - Toon optionele support/handoff-link (NL/FR) indien ingesteld.
3. **Instellingen in admin**:
   - Confidence-drempel (%).
   - Handoff/support URL NL + FR.

## Implementatiestatus (huidige code)

1. **Geimplementeerd**
   - Confidence gating met instelbare drempel.
   - Low-confidence fallback met referentielinks + optionele handoff URL per taal.
   - Dual-flow retrieval zonder lock wanneer geen flow is gekozen.
   - Inferred topic-prioriteit bij geen flow, zonder de andere flow uit te sluiten.
   - Cross-reference scoring van handleidingtopics voor betere linkkeuze.
   - Ingebouwde regressietest-suite in admin (NL/FR, beide flows en geen flow) met PASS/FAIL-overzicht.
   - Operationeel quality-dashboard op basis van bestaande logs (14d KPI's, trend, fail-redenen, topic-risico en alerts).
   - Retrieval scoring verfijnd met intent-signalen + topic-hints + continue recency decay voor betere ranking.
   - Cross-reference prioriteit verbeterd met topic-hit aggregatie, intent-flow hints, recency en URL-deduplicatie.
   - Ambigue vragen verduidelijken enkel wanneer nodig; bij duidelijke flow-mismatch wordt niet geblokkeerd maar wel een flow-switch suggestie getoond.
   - Betere interpretatie van korte vervolgberichten (zoals "ja", "ok", "en dan") via context uit de vorige gebruikersvraag.
   - Kwaliteitsherstel: dual-flow retrieval gebruikt nu score-gestuurde topicselectie (minder context-ruis, accuratere flow-antwoorden).
   - UI toont nu expliciet de primaire bronlink per botantwoord (indien beschikbaar).
2. **Openstaand**
   - Geen must-have items meer in Fase 1.

## Fase 2 - Relevantie en routing

1. [x] Retrieval scoring verder verfijnen (intent + topic + recency).
2. [x] Cross-reference prioriteit op handleidingtopics verbeteren.
3. [x] Ambigue vragen: enkel verduidelijking vragen indien echt nodig.

## Fase 3 - UX en operations

1. [x] Betere zichtbaarheid van primaire bron in UI.
2. Evaluatieset met regressievragen (NL/FR + beide flows).
3. Compact dashboard voor quality-signalen (fallback, stale chunks, coverage).

## Fase 4 - Herbruikbare productisatie

1. [x] Provider-profielen uitbreiden als standaard adapterlaag.
2. [x] Config export/import als deployment-flow behouden.
3. [x] Domeinspecifieke termen volledig uit core naar profiel.

## Fase 5 - Productie quality gates

1. [x] Quality gate op export: blokkeer config-export bij kritieke kwaliteitsfouten.
2. [x] Automatische regressiescore koppelen aan quality gate (minimum PASS%).
3. [ ] Release checklist + semver changelog automatiseren.

## Werkafspraken

1. Geen nieuwe DB/tabellen.
2. Alleen WordPress options + bestaande logtabel gebruiken.
3. Elke fase eindigt met testscenario's en rollback-veilige wijzigingen.

