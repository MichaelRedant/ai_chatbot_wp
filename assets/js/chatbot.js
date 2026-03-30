document.addEventListener('DOMContentLoaded', function () {
  const settings = window.octopus_ai_chatbot_vars || {};
  const i18n = settings.i18n || {};
  const lang = settings.lang || ((navigator.language || '').toLowerCase().startsWith('fr') ? 'FR' : 'NL');
  const renderMode = settings.render_mode || 'floating';
  const prefersReducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  function parseIntInRange(value, min, max, fallback) {
    const parsed = parseInt(value, 10);
    if (Number.isNaN(parsed)) return fallback;
    return Math.min(max, Math.max(min, parsed));
  }

  function toBool(value, fallback) {
    if (value === undefined || value === null || value === '') return fallback;
    return value === '1' || value === 'true' || value === 'yes' || value === true;
  }

  function sanitizeFontFamily(value) {
    const candidate = String(value || '').trim();
    if (!candidate) return '';
    return candidate.replace(/[^a-zA-Z0-9,\s"'_-]/g, '').slice(0, 120);
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (character) {
      switch (character) {
        case '&': return '&amp;';
        case '<': return '&lt;';
        case '>': return '&gt;';
        case '"': return '&quot;';
        case '\'': return '&#39;';
        default: return character;
      }
    });
  }

  function sanitizeUrl(url) {
    const candidate = String(url || '').trim();
    if (!candidate) return '';

    try {
      const parsed = new URL(candidate, window.location.origin);
      if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
        return '';
      }
      return parsed.toString();
    } catch (error) {
      return '';
    }
  }

  function isCriticalHtmlPayload(raw) {
    const text = String(raw || '').trim();
    if (!text) return false;

    const lower = text.toLowerCase();
    const markers = [
      'er heeft zich een kritieke fout voorgedaan op deze website',
      'there has been a critical error on this website',
      'faq-troubleshooting',
      'wordpress.org/documentation/article/faq-troubleshooting',
      'wp-die-message'
    ];

    if (markers.some((marker) => lower.indexOf(marker) !== -1)) {
      return true;
    }

    const hasHtml = /<\s*(html|body|p|a|div|h1|h2)\b/i.test(text);
    return hasHtml && lower.indexOf('wordpress') !== -1 && lower.indexOf('critical error') !== -1;
  }

  function stripHtmlTags(value) {
    return String(value || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function parseApiErrorMessage(raw) {
    const text = String(raw || '').trim();
    if (!text || isCriticalHtmlPayload(text)) return '';

    try {
      const parsed = JSON.parse(text);
      const candidates = [
        parsed && parsed.message,
        parsed && parsed.error && parsed.error.message,
        parsed && parsed.data && parsed.data.message,
        parsed && parsed.data && parsed.data.error
      ];

      for (let i = 0; i < candidates.length; i++) {
        const candidate = candidates[i];
        if (typeof candidate !== 'string') continue;
        const clean = stripHtmlTags(candidate);
        if (!clean) continue;
        if (clean.length > 260) return clean.slice(0, 257) + '...';
        return clean;
      }
    } catch (error) {
      // ignore parse failures
    }

    return '';
  }

  function getApiErrorText(error, fallbackMessage) {
    const fallback = String(fallbackMessage || '').trim() || 'Er ging iets mis met het ophalen van het antwoord.';
    if (error && error.name === 'AbortError') {
      return fallback;
    }
    if (error && typeof error.userMessage === 'string' && error.userMessage.trim()) {
      return error.userMessage.trim();
    }
    if (error && typeof error.message === 'string') {
      const message = error.message.trim();
      if (message && message !== 'runtime_html_error' && !/^http_\d+$/i.test(message)) {
        return message;
      }
    }
    return fallback;
  }

  async function fetchWithTimeout(url, options, timeoutMs) {
    const timeout = parseIntInRange(timeoutMs, 3000, 90000, 20000);
    if (typeof AbortController === 'undefined') {
      return fetch(url, options || {});
    }

    const controller = new AbortController();
    const requestOptions = Object.assign({}, options || {}, { signal: controller.signal });
    const timer = setTimeout(() => controller.abort(), timeout);

    try {
      return await fetch(url, requestOptions);
    } finally {
      clearTimeout(timer);
    }
  }

  function decodeUnicode(value) {
    return String(value || '')
      .replace(/\\\\\//g, '/')
      .replace(/\\\//g, '/')
      .replace(/\\u([0-9a-fA-F]{4})/g, function (_, hex) {
        return String.fromCharCode(parseInt(hex, 16));
      });
  }

  function stripWrappingQuotes(value) {
    const trimmed = String(value || '').trim();
    if ((trimmed.startsWith('"') && trimmed.endsWith('"')) || (trimmed.startsWith("'") && trimmed.endsWith("'"))) {
      return trimmed.slice(1, -1);
    }
    return trimmed;
  }

  function normalizeBreaks(text) {
    let output = String(text || '');
    output = output
      .replace(/\\r\\n/g, '\n')
      .replace(/\\n/g, '\n')
      .replace(/\\r/g, '\n')
      .replace(/\\\\\//g, '/')
      .replace(/\\\//g, '/')
      .replace(/\r\n/g, '\n');

    output = output.replace(/(^|[^a-zA-Z])nn(?![a-zA-Z])/g, '$1\n\n');
    output = output.replace(/([\.:;!?])n(\s|$)/g, '$1\n');
    output = output.replace(/([\s\.:;!?])n\s*-\s/g, '$1\n- ');
    output = output.replace(/\sn\s/g, '\n');
    output = output.replace(/\s([-*])\s+/g, '\n$1 ');
    output = output.replace(/\s(\d+[\.)])\s+/g, '\n$1 ');

    if (output.indexOf('\n') === -1 && output.length > 220) {
      output = output.replace(/([.!?])\s+(?=[A-Z])/g, '$1\n');
    }

    output = output.replace(/\n{3,}/g, '\n\n');
    return output;
  }

  function normalizeOrderedListNumbering(text) {
    const lines = String(text || '').split('\n');
    let orderedIndex = 0;
    let inOrderedBlock = false;

    for (let i = 0; i < lines.length; i++) {
      const sourceLine = String(lines[i] || '');
      const trimmed = sourceLine.trim();

      if (trimmed === '') {
        continue;
      }

      const orderedMatch = trimmed.match(/^(\d+)\s*(?:\\?[.)])\s+(.+)$/);
      if (orderedMatch) {
        orderedIndex = inOrderedBlock ? orderedIndex + 1 : 1;
        inOrderedBlock = true;

        const leadingWhitespaceMatch = sourceLine.match(/^\s*/);
        const leadingWhitespace = leadingWhitespaceMatch ? leadingWhitespaceMatch[0] : '';
        lines[i] = leadingWhitespace + orderedIndex + '. ' + orderedMatch[2];
        continue;
      }

      inOrderedBlock = false;
      orderedIndex = 0;
    }

    return lines.join('\n');
  }

  function parseBotPayload(raw) {
    const fallbackText = String(raw || '').trim();
    const payload = {
      answer: '',
      chatId: 0,
      status: '',
      suggestedTopic: '',
      currentTopic: '',
      primarySourceUrl: ''
    };

    if (!fallbackText) return payload;
    if (isCriticalHtmlPayload(fallbackText)) return payload;

    try {
      const parsed = JSON.parse(fallbackText);
      if (typeof parsed === 'string') {
        payload.answer = parsed;
        return payload;
      }

      if (parsed && typeof parsed === 'object') {
        const root = (parsed.data && typeof parsed.data === 'object') ? parsed.data : parsed;
        if (typeof root.answer === 'string') payload.answer = root.answer;
        else if (typeof root.reply === 'string') payload.answer = root.reply;
        else if (typeof root.message === 'string') payload.answer = root.message;
        else if (typeof parsed.message === 'string') payload.answer = parsed.message;

        const candidateChatId = root.chat_id ?? parsed.chat_id ?? 0;
        payload.chatId = Number.isFinite(Number(candidateChatId)) ? Number(candidateChatId) : 0;
        payload.status = typeof (root.status ?? parsed.status) === 'string' ? String(root.status ?? parsed.status) : '';
        payload.suggestedTopic = typeof (root.suggested_topic ?? parsed.suggested_topic) === 'string'
          ? String(root.suggested_topic ?? parsed.suggested_topic)
          : '';
        payload.currentTopic = typeof (root.current_topic ?? parsed.current_topic) === 'string'
          ? String(root.current_topic ?? parsed.current_topic)
          : '';
        payload.primarySourceUrl = typeof (root.primary_source_url ?? parsed.primary_source_url) === 'string'
          ? String(root.primary_source_url ?? parsed.primary_source_url).trim()
          : '';
        return payload;
      }
    } catch (error) {
      // keep raw fallback text
    }

    payload.answer = isCriticalHtmlPayload(fallbackText) ? '' : fallbackText;
    return payload;
  }

  function enhanceStructure(htmlIn) {
    const parts = String(htmlIn || '').split(/<br\s*\/?>/i);
    let out = '';
    let inUl = false;
    let inOl = false;
    let para = [];

    const flushPara = function () {
      if (para.length) {
        out += '<p>' + para.join(' ') + '</p>';
        para = [];
      }
    };

    const closeLists = function () {
      if (inUl) {
        out += '</ul>';
        inUl = false;
      }
      if (inOl) {
        out += '</ol>';
        inOl = false;
      }
    };

    for (let i = 0; i < parts.length; i++) {
      const raw = (parts[i] || '').trim();
      if (raw === '') {
        flushPara();
        continue;
      }

      const markdownHeading = raw.match(/^#{1,4}\s+(.+)$/);
      if (markdownHeading) {
        flushPara();
        closeLists();
        out += '<h4 class="octopus-msg-heading">' + String(markdownHeading[1] || '').trim() + '</h4>';
        continue;
      }

      if (/^[^<]{3,60}:\s*$/.test(raw)) {
        flushPara();
        closeLists();
        out += '<h5 class="octopus-msg-subheading">' + raw.replace(/:\s*$/, ':') + '</h5>';
        continue;
      }

      const unorderedMatch = raw.match(/^(?:<(?:strong|b|em)>\s*)?[-*•]\s*(?:<\/(?:strong|b|em)>\s*)?(.+)$/i);
      if (unorderedMatch) {
        flushPara();
        if (!inUl) {
          closeLists();
          out += '<ul>';
          inUl = true;
        }
        out += '<li>' + String(unorderedMatch[1] || '').trim() + '</li>';
        continue;
      }

      const orderedMatch = raw.match(/^(?:<(?:strong|b|em)>\s*)?\d+\s*(?:\\?\.|\\?\))\s*(?:<\/(?:strong|b|em)>\s*)?(.+)$/i);
      if (orderedMatch) {
        flushPara();
        if (!inOl) {
          closeLists();
          out += '<ol>';
          inOl = true;
        }
        out += '<li>' + String(orderedMatch[1] || '').trim() + '</li>';
        continue;
      }

      closeLists();
      para.push(raw);
    }

    flushPara();
    closeLists();
    return out || htmlIn;
  }

  function formatMessageToHtml(content, fallbackButtonLabel) {
    let text = stripWrappingQuotes(decodeUnicode(content));
    text = normalizeBreaks(text);
    text = normalizeOrderedListNumbering(text);

    let html = escapeHtml(text);
    html = html
      .replace(/\r?\n/g, '<br>')
      .replace(/\\r\\n|\\n|\\r/g, '<br>')
      .replace(/\\([\\`*_{}\[\]()#+\-.!>])/g, '$1')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(?!\*)([^*]+)\*(?!\*)/g, '<em>$1</em>')
      .replace(/\[([^\]]+)]\((https?:\/\/[^\s)]+)\)/g, function (_, label, url) {
        const safeUrl = sanitizeUrl(url);
        if (!safeUrl) return label;
        const safeHref = safeUrl.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
        return '<a href="' + safeHref + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
      });

    html = html.replace(/https?:\/\/[^\s<>"')]+/g, function (url, offset) {
      const before = html.slice(Math.max(0, offset - 30), offset).toLowerCase();
      if (before.indexOf('href=') !== -1) return url;
      const safeUrl = sanitizeUrl(url);
      if (!safeUrl) return '';
      const safeHref = safeUrl.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
      return '<a href="' + safeHref + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(fallbackButtonLabel) + '</a>';
    });

    return enhanceStructure(html);
  }

  class OctopusChatbotInstance {
    constructor(options) {
      this.mode = options.mode;
      this.mount = options.mount || null;
      this.instanceIndex = options.instanceIndex || 0;
      this.settings = options.settings;
      this.i18n = options.i18n;
      this.lang = options.lang;
      this.prefersReducedMotion = options.prefersReducedMotion;

      this.restEndpoint = this.settings.rest_url || '/wp-json/octopus-ai/v1/chatbot';
      this.restEndpointLite = this.settings.rest_url_lite || '/wp-json/octopus-ai/v1/chatbot-lite';
      this.feedbackEndpoint = this.settings.feedback_url || '/wp-json/octopus-ai/v1/feedback';
      this.requestTimeoutMs = parseIntInRange(this.settings.request_timeout_ms, 3000, 90000, 20000);
      this.feedbackTimeoutMs = parseIntInRange(this.settings.feedback_timeout_ms, 3000, 30000, 12000);
      this.sentFeedback = new Set();
      this.messages = [];
      this.isSending = false;
      this.sendCooldown = false;
      this.isEmbedded = this.mode === 'embedded';
      this.isExpanded = false;

      this.topicChoices = this.buildTopicChoices(this.settings.topic_choices, this.settings.topic_terms);
      this.topicTermsMap = this.buildTopicTermsMap(this.settings.topic_terms);
      this.pendingSuggestedTopic = '';
      this.previousTopicBeforeSelection = '';
      this.pendingTopicDecision = null;
      this.dismissedSuggestedTopics = new Set();
    }

    init() {
      this.config = this.readConfig();
      this.buildDom();
      this.bindEvents();
      this.restoreSessionState();
      this.updateTopicUi();
      this.updateComposerState();

      if (this.showTopicSelector && !this.selectedTopic) {
        this.topicPanel.classList.add('visible');
      }

      if (this.isEmbedded) {
        this.chatbot.style.display = 'flex';
        this.chatbot.classList.add('is-open');
        this.chatClose.style.display = 'none';
        if (!this.config.showResetButton) {
          this.chatReset.style.display = 'none';
        }
        this.showWelcomeOnce(0);
        setTimeout(() => {
          if (!this.chatInput.disabled) this.chatInput.focus();
        }, 120);
      } else {
        this.chatbot.style.display = 'none';
      }
    }

    readConfig() {
      if (!this.isEmbedded || !this.mount) {
        return {
          title: '',
          welcomeMessage: '',
          height: 560,
          radius: 16,
          showTopicSelector: true,
          showResetButton: true,
          primaryColor: '',
          headerTextColor: '',
          fontFamily: '',
          headerFontSize: 16,
          headerFontWeight: '600',
          bodyFontSize: 14,
          messageRadius: 12,
          userMessageBg: '',
          userMessageText: '',
          botMessageBg: '',
          botMessageText: '',
          inputBgColor: '',
          inputTextColor: '',
          inputBorderColor: '',
          buttonBgColor: '',
          buttonTextColor: '',
          buttonRadius: 20
        };
      }

      return {
        title: (this.mount.getAttribute('data-widget-title') || '').trim(),
        welcomeMessage: (this.mount.getAttribute('data-widget-welcome') || '').trim(),
        height: parseIntInRange(this.mount.getAttribute('data-widget-height'), 360, 900, 560),
        radius: parseIntInRange(this.mount.getAttribute('data-widget-radius'), 8, 28, 16),
        showTopicSelector: toBool(this.mount.getAttribute('data-show-topic-selector'), true),
        showResetButton: toBool(this.mount.getAttribute('data-show-reset-button'), true),
        primaryColor: (this.mount.getAttribute('data-widget-primary-color') || '').trim(),
        headerTextColor: (this.mount.getAttribute('data-widget-header-text-color') || '').trim(),
        fontFamily: sanitizeFontFamily(this.mount.getAttribute('data-widget-font-family') || ''),
        headerFontSize: parseIntInRange(this.mount.getAttribute('data-widget-header-font-size'), 12, 24, 16),
        headerFontWeight: (this.mount.getAttribute('data-widget-header-font-weight') || '600').trim(),
        bodyFontSize: parseIntInRange(this.mount.getAttribute('data-widget-body-font-size'), 12, 18, 14),
        messageRadius: parseIntInRange(this.mount.getAttribute('data-widget-message-radius'), 6, 24, 12),
        userMessageBg: (this.mount.getAttribute('data-widget-user-message-bg') || '').trim(),
        userMessageText: (this.mount.getAttribute('data-widget-user-message-text') || '').trim(),
        botMessageBg: (this.mount.getAttribute('data-widget-bot-message-bg') || '').trim(),
        botMessageText: (this.mount.getAttribute('data-widget-bot-message-text') || '').trim(),
        inputBgColor: (this.mount.getAttribute('data-widget-input-bg-color') || '').trim(),
        inputTextColor: (this.mount.getAttribute('data-widget-input-text-color') || '').trim(),
        inputBorderColor: (this.mount.getAttribute('data-widget-input-border-color') || '').trim(),
        buttonBgColor: (this.mount.getAttribute('data-widget-button-bg-color') || '').trim(),
        buttonTextColor: (this.mount.getAttribute('data-widget-button-text-color') || '').trim(),
        buttonRadius: parseIntInRange(this.mount.getAttribute('data-widget-button-radius'), 8, 26, 20)
      };
    }

    buildStorageKeys() {
      const base = this.isEmbedded
        ? 'embedded_' + this.lang + '_' + window.location.pathname + '_' + this.instanceIndex
        : 'floating_' + this.lang;

      this.historyStorageKey = 'octopus_chat_history_' + base;
      this.topicStorageKey = 'octopus_chat_topic_' + base;
      this.welcomeSessionKey = 'octopus_chat_welcome_' + base;
      this.feedbackStorageKey = 'octopus_chat_feedback_' + base;
      this.expandedStorageKey = 'octopus_chat_expanded_' + base;
    }

    buildDom() {
      this.buildStorageKeys();

      this.primaryColor = this.config.primaryColor || this.settings.primary_color || '#0f6c95';
      this.headerTextColor = this.config.headerTextColor || this.settings.header_text_color || '#ffffff';
      this.headerTitle = this.config.title || this.settings.brand_name || 'AI Chatbot';
      this.fallbackButtonLabel = this.i18n.fallback_button || 'Bekijk dit in de handleiding';
      this.aiDisclaimerText = (this.i18n.ai_disclaimer || (
        this.lang === 'FR'
          ? "Remarque: ce chatbot utilise l'IA. Les reponses sont generees automatiquement, a titre informatif, et peuvent etre inexactes ou incompletes. Ceci ne constitue pas un avis juridique, fiscal ou comptable. Verifie toujours dans la documentation officielle."
          : 'Let op: deze chatbot gebruikt AI. Antwoorden worden automatisch gegenereerd, zijn enkel informatief en kunnen onjuist of onvolledig zijn. Dit is geen juridisch, fiscaal of boekhoudkundig advies. Verifieer altijd in de officiele handleiding.'
      )).trim();
      this.fontFamily = sanitizeFontFamily(this.config.fontFamily || '');
      this.headerFontSize = parseIntInRange(this.config.headerFontSize, 12, 24, 16);
      this.headerFontWeight = ['400', '500', '600', '700', '800'].includes(String(this.config.headerFontWeight))
        ? String(this.config.headerFontWeight)
        : '600';
      this.bodyFontSize = parseIntInRange(this.config.bodyFontSize, 12, 18, 14);
      this.messageRadius = parseIntInRange(this.config.messageRadius, 6, 24, 12);
      this.userMessageBg = this.config.userMessageBg || '';
      this.userMessageText = this.config.userMessageText || '';
      this.botMessageBg = this.config.botMessageBg || '';
      this.botMessageText = this.config.botMessageText || '';
      this.inputBgColor = this.config.inputBgColor || '';
      this.inputTextColor = this.config.inputTextColor || '';
      this.inputBorderColor = this.config.inputBorderColor || '';
      this.buttonBgColor = this.config.buttonBgColor || this.primaryColor;
      this.buttonTextColor = this.config.buttonTextColor || '';
      this.buttonRadius = parseIntInRange(this.config.buttonRadius, 8, 26, 20);

      if (this.isEmbedded) {
        this.root = document.createElement('div');
        this.root.className = 'octopus-chatbot-embed-root';
        this.mount.appendChild(this.root);
      } else {
        this.root = document.createElement('div');
        this.root.className = 'octopus-chatbot-floating-root';
        document.body.appendChild(this.root);
      }

      this.root.style.setProperty('--primary-color', this.primaryColor);
      this.root.style.setProperty('--header-text-color', this.headerTextColor);
      this.root.style.setProperty('--octopus-header-font-size', this.headerFontSize + 'px');
      this.root.style.setProperty('--octopus-header-font-weight', this.headerFontWeight);
      this.root.style.setProperty('--octopus-body-font-size', this.bodyFontSize + 'px');
      this.root.style.setProperty('--octopus-message-radius', this.messageRadius + 'px');
      this.root.style.setProperty('--octopus-button-radius', this.buttonRadius + 'px');
      this.root.style.setProperty('--octopus-button-bg', this.buttonBgColor);
      if (this.fontFamily) this.root.style.setProperty('--octopus-chat-font-family', this.fontFamily);
      if (this.userMessageBg) this.root.style.setProperty('--octopus-user-message-bg', this.userMessageBg);
      if (this.userMessageText) this.root.style.setProperty('--octopus-user-message-text', this.userMessageText);
      if (this.botMessageBg) this.root.style.setProperty('--octopus-bot-message-bg', this.botMessageBg);
      if (this.botMessageText) this.root.style.setProperty('--octopus-bot-message-text', this.botMessageText);
      if (this.inputBgColor) this.root.style.setProperty('--octopus-input-bg', this.inputBgColor);
      if (this.inputTextColor) this.root.style.setProperty('--octopus-input-text', this.inputTextColor);
      if (this.inputBorderColor) this.root.style.setProperty('--octopus-input-border', this.inputBorderColor);
      if (this.buttonTextColor) this.root.style.setProperty('--octopus-button-text', this.buttonTextColor);

      if (this.isEmbedded) {
        this.root.style.setProperty('--octopus-widget-height', this.config.height + 'px');
        this.root.style.setProperty('--octopus-widget-radius', this.config.radius + 'px');
      }

      if (!this.isEmbedded) {
        this.toggleButton = document.createElement('div');
        this.toggleButton.id = 'octopus-chat-toggle';
        this.toggleButton.innerHTML = '<img src="' + (this.settings.logo_url || '') + '" alt="Chatbot">';
        this.root.appendChild(this.toggleButton);
      }

      this.chatbot = document.createElement('div');
      this.chatbot.id = 'octopus-chatbot';
      this.chatbot.innerHTML = `
        <div id="chat-header" style="background-color:${this.primaryColor};">
          <div class="chat-header-inner">
            <div class="chat-logo-glass"><img src="${this.settings.logo_url || ''}" alt="Logo" class="chat-logo"></div>
            <span class="chat-header-title">${escapeHtml(this.headerTitle)}</span>
          </div>
          <div class="chat-header-actions">
            <button id="chat-reset" type="button" aria-label="${escapeHtml(this.i18n.reset_title || 'Reset')}" title="${escapeHtml(this.i18n.reset_title || 'Reset')}" class="chat-header-action chat-reset-button">
              <span class="chat-reset-icon" aria-hidden="true">&#x21bb;</span>
            </button>
            <button id="chat-expand" type="button" aria-label="Vergroot chatvenster" class="chat-header-action chat-expand-button" aria-pressed="false">
              <span class="chat-expand-icon" aria-hidden="true">⤢</span>
            </button>
            <button id="chat-close" type="button" aria-label="Sluiten" class="chat-header-action chat-close-button">&times;</button>
          </div>
        </div>
        <div id="chat-messages" role="log" aria-live="polite" aria-relevant="additions text" aria-atomic="false"></div>
        <div id="chat-input-container">
          <input type="text" id="chat-input" placeholder="${escapeHtml(this.i18n.placeholder || 'Typ je vraag...')}" />
          <button id="chat-send" type="button">${escapeHtml(this.i18n.send || 'Verstuur')}</button>
        </div>
        <div id="chat-ai-disclaimer">${escapeHtml(this.aiDisclaimerText)}</div>
      `;
      this.root.appendChild(this.chatbot);

      this.chatMessages = this.chatbot.querySelector('#chat-messages');
      this.chatInput = this.chatbot.querySelector('#chat-input');
      this.chatSend = this.chatbot.querySelector('#chat-send');
      this.chatClose = this.chatbot.querySelector('#chat-close');
      this.chatExpand = this.chatbot.querySelector('#chat-expand');
      this.chatExpandIcon = this.chatbot.querySelector('.chat-expand-icon');
      this.chatReset = this.chatbot.querySelector('#chat-reset');
      this.chatInputContainer = this.chatbot.querySelector('#chat-input-container');
      this.headerInner = this.chatbot.querySelector('.chat-header-inner');
      this.chatMessages.setAttribute('aria-label', this.lang === 'FR' ? 'Historique de conversation' : 'Gespreksgeschiedenis');
      this.chatClose.setAttribute('aria-label', this.lang === 'FR' ? 'Fermer' : 'Sluiten');
      if (this.chatExpand) {
        this.chatExpand.setAttribute('aria-label', this.lang === 'FR' ? 'Agrandir le chat' : 'Chat vergroten');
        this.chatExpand.setAttribute('title', this.lang === 'FR' ? 'Agrandir' : 'Vergroot');
      }
      this.chatInput.setAttribute('aria-label', this.i18n.placeholder || (this.lang === 'FR' ? 'Tapez votre question' : 'Typ je vraag'));
      this.chatSend.setAttribute('aria-label', this.i18n.send || (this.lang === 'FR' ? 'Envoyer' : 'Verstuur'));
      this.chatReset.setAttribute('aria-label', this.i18n.reset_title || (this.lang === 'FR' ? 'Reinitialiser la conversation' : 'Gesprek resetten'));

      this.topicBadge = document.createElement('button');
      this.topicBadge.id = 'chat-topic-badge';
      this.topicBadge.type = 'button';
      this.topicBadge.className = 'chat-topic-badge';
      this.topicBadge.setAttribute('aria-label', this.lang === 'FR' ? 'Choisir ou changer le flux' : 'Kies of wijzig de flow');
      this.headerInner.appendChild(this.topicBadge);

      this.topicPanel = document.createElement('section');
      this.topicPanel.id = 'chat-topic-panel';
      this.topicPanel.setAttribute('aria-label', this.lang === 'FR' ? 'Selection du flux' : 'Flow selectie');
      this.topicPanel.innerHTML = `
        <div class="topic-panel-inner">
          <p class="topic-intro">${this.lang === 'FR'
            ? 'Choisis le flux qui correspond le mieux a ta question.'
            : 'Kies de flow die het best bij je vraag past.'}</p>
          <div class="topic-grid">
            ${this.topicChoices.map((choice) => `
              <button type="button" class="topic-option" data-topic="${choice.key}">
                <span class="topic-option-title">${escapeHtml(this.getTopicLabel(choice))}</span>
                <span class="topic-option-description">${escapeHtml(this.getTopicDescription(choice))}</span>
              </button>
            `).join('')}
          </div>
        </div>`;
      this.chatMessages.prepend(this.topicPanel);

      this.topicStatus = document.createElement('div');
      this.topicStatus.id = 'chat-topic-status';
      this.topicStatus.innerHTML = `
        <span class="topic-status-label"></span>
        <button type="button" class="topic-change-button">${this.lang === 'FR' ? 'Rechoisir' : 'Kies opnieuw'}</button>
      `;
      this.chatbot.insertBefore(this.topicStatus, this.chatInputContainer);

      this.topicQuickPick = document.createElement('div');
      this.topicQuickPick.id = 'chat-topic-quickpick';
      this.topicQuickPick.innerHTML = `
        <span class="topic-quickpick-label">${this.lang === 'FR' ? 'Choisis un flux pour de meilleures reponses:' : 'Kies een flow voor betere antwoorden:'}</span>
        <div class="topic-quickpick-actions">
          ${this.topicChoices.map((choice) => (
            `<button type="button" class="topic-quickpick-btn" data-topic="${choice.key}">${escapeHtml(this.getTopicLabel(choice))}</button>`
          )).join('')}
        </div>
      `;
      this.chatbot.insertBefore(this.topicQuickPick, this.chatInputContainer);

      this.topicStatusLabel = this.topicStatus.querySelector('.topic-status-label');
      this.topicChangeButton = this.topicStatus.querySelector('.topic-change-button');
      this.topicOptions = this.topicPanel.querySelectorAll('.topic-option');
      this.topicQuickButtons = this.topicQuickPick.querySelectorAll('.topic-quickpick-btn');
      this.topicChangeButton.setAttribute('aria-label', this.lang === 'FR' ? 'Rechoisir le flux' : 'Flow opnieuw kiezen');
      this.showTopicSelector = !this.isEmbedded || this.config.showTopicSelector;
      this.selectedTopic = '';
    }

    bindEvents() {
      if (!this.isEmbedded && this.toggleButton) {
        this.toggleButton.addEventListener('click', () => {
          this.chatbot.style.display = 'flex';
          this.toggleButton.style.display = 'none';
          requestAnimationFrame(() => this.chatbot.classList.add('is-open'));
          this.syncExpandedViewportState();
          this.showWelcomeOnce(200);
          setTimeout(() => {
            if (!this.chatInput.disabled) this.chatInput.focus();
          }, 180);
        });
      }

      this.chatClose.addEventListener('click', () => {
        this.chatbot.classList.remove('is-open');
        const delay = this.prefersReducedMotion ? 0 : 180;
        setTimeout(() => {
          this.chatbot.style.display = 'none';
          if (this.toggleButton) this.toggleButton.style.display = 'flex';
          this.syncExpandedViewportState();
        }, delay);
      });

      if (this.chatExpand) {
        this.chatExpand.addEventListener('click', () => this.toggleExpanded());
      }

      this.topicBadge.addEventListener('click', () => {
        if (!this.showTopicSelector) return;
        if (!this.selectedTopic) {
          this.topicPanel.classList.add('visible');
          return;
        }
        this.topicPanel.classList.toggle('visible');
      });

      this.topicOptions.forEach((button) => {
        button.addEventListener('click', () => this.setTopic(button.dataset.topic || '', true));
      });
      this.topicQuickButtons.forEach((button) => {
        button.addEventListener('click', () => this.setTopic(button.dataset.topic || '', true));
      });

      this.topicChangeButton.addEventListener('click', (event) => {
        if (!this.showTopicSelector) return;
        event.preventDefault();
        this.requestTopicSelection({ forceChoice: true, announce: true });
      });

      this.chatSend.addEventListener('click', () => this.sendMessage());
      this.chatInput.addEventListener('input', () => this.updateComposerState());
      this.chatInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
          event.preventDefault();
          this.sendMessage();
        }
      });

      this.chatReset.addEventListener('click', () => this.resetConversation());

      this.chatMessages.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        const button = target.closest('.octopus-feedback-btn');
        if (!button) return;

        const container = button.closest('.octopus-feedback');
        if (!container) return;

        const chatId = Number(container.getAttribute('data-chat-id') || 0);
        const feedback = button.getAttribute('data-feedback') || '';
        this.submitFeedback(chatId, feedback, container);
      });
    }

    getExpandLabel(expanded) {
      if (expanded) {
        return this.lang === 'FR' ? 'Reduire le chat' : 'Chat verkleinen';
      }
      return this.lang === 'FR' ? 'Agrandir le chat' : 'Chat vergroten';
    }

    syncExpandedViewportState() {
      const isVisible = this.isEmbedded || this.chatbot.style.display !== 'none';
      document.body.classList.toggle('octopus-chat-expanded', this.isExpanded && isVisible);
    }

    setExpandedState(expanded, options) {
      const config = options && typeof options === 'object' ? options : {};
      const persist = config.persist !== false;
      const keepFocus = config.focus !== false;

      this.isExpanded = !!expanded;
      this.chatbot.classList.toggle('is-expanded', this.isExpanded);
      this.root.classList.toggle('is-expanded', this.isExpanded);

      if (this.chatExpand) {
        const label = this.getExpandLabel(this.isExpanded);
        this.chatExpand.setAttribute('aria-pressed', this.isExpanded ? 'true' : 'false');
        this.chatExpand.setAttribute('aria-label', label);
        this.chatExpand.setAttribute('title', label);
      }

      if (this.chatExpandIcon) {
        this.chatExpandIcon.textContent = this.isExpanded ? '⤡' : '⤢';
      }

      if (persist) {
        sessionStorage.setItem(this.expandedStorageKey, this.isExpanded ? '1' : '0');
      }

      this.syncExpandedViewportState();

      if (keepFocus && this.chatInput && !this.chatInput.disabled && (this.isEmbedded || this.chatbot.style.display !== 'none')) {
        setTimeout(() => this.chatInput.focus(), 40);
      }
    }

    toggleExpanded() {
      this.setExpandedState(!this.isExpanded);
    }

    restoreSessionState() {
      this.selectedTopic = sessionStorage.getItem(this.topicStorageKey) || '';
      this.isExpanded = sessionStorage.getItem(this.expandedStorageKey) === '1';
      this.setExpandedState(this.isExpanded, { persist: false, focus: false });
      this.restoreFeedbackSet();
      this.restoreMessages();
    }

    restoreFeedbackSet() {
      const raw = sessionStorage.getItem(this.feedbackStorageKey);
      if (!raw) return;

      try {
        const list = JSON.parse(raw);
        if (Array.isArray(list)) {
          list.forEach((item) => {
            const id = Number(item);
            if (Number.isFinite(id) && id > 0) this.sentFeedback.add(id);
          });
        }
      } catch (error) {
        sessionStorage.removeItem(this.feedbackStorageKey);
      }
    }

    saveFeedbackSet() {
      const list = Array.from(this.sentFeedback);
      sessionStorage.setItem(this.feedbackStorageKey, JSON.stringify(list));
    }

    restoreMessages() {
      const raw = sessionStorage.getItem(this.historyStorageKey);
      if (!raw) return;

      try {
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
          sessionStorage.removeItem(this.historyStorageKey);
          return;
        }

        parsed.forEach((entry) => {
          if (!entry || typeof entry !== 'object') return;
          const sender = entry.sender === 'user' ? 'user' : 'bot';
          const text = typeof entry.content === 'string' ? entry.content : '';
          const chatId = Number(entry.chatId || 0);
          const primarySourceUrl = typeof entry.primarySourceUrl === 'string' ? entry.primarySourceUrl.trim() : '';
          if (!text) return;

          this.messages.push({
            sender: sender,
            content: text,
            chatId: chatId,
            primarySourceUrl: primarySourceUrl
          });
          this.renderMessage(text, sender, { chatId: chatId, primarySourceUrl: primarySourceUrl });
        });
      } catch (error) {
        sessionStorage.removeItem(this.historyStorageKey);
      }
    }

    saveMessages() {
      const payload = this.messages.slice(-40);
      sessionStorage.setItem(this.historyStorageKey, JSON.stringify(payload));
    }

    getTopicLabel(choice) {
      return this.lang === 'FR' ? choice.labelFr : choice.labelNl;
    }

    getTopicDescription(choice) {
      return this.lang === 'FR' ? choice.descFr : choice.descNl;
    }

    buildTopicChoices(rawChoices, rawTerms) {
      const parsed = [];
      if (Array.isArray(rawChoices) && rawChoices.length) {
        rawChoices.forEach((choice) => {
          if (!choice || typeof choice !== 'object') return;

          const key = String(choice.key || '')
            .toLowerCase()
            .replace(/[^a-z0-9_-]/g, '')
            .trim();
          if (!key) return;
          if (parsed.some((item) => item.key === key)) return;

          const labelNl = String(choice.label_nl || choice.labelNl || key).trim();
          const labelFr = String(choice.label_fr || choice.labelFr || labelNl).trim();
          const descNl = String(choice.desc_nl || choice.descNl || '').trim();
          const descFr = String(choice.desc_fr || choice.descFr || descNl).trim();

          parsed.push({
            key: key,
            labelNl: labelNl || key,
            labelFr: labelFr || (labelNl || key),
            descNl: descNl,
            descFr: descFr
          });
        });
      }

      if (parsed.length) {
        return parsed;
      }

      const fallbackFromTerms = [];
      if (rawTerms && typeof rawTerms === 'object') {
        Object.keys(rawTerms).forEach((rawKey) => {
          const key = String(rawKey || '')
            .toLowerCase()
            .replace(/[^a-z0-9_-]/g, '')
            .trim();
          if (!key || fallbackFromTerms.some((item) => item.key === key)) return;

          const label = key.replace(/[_-]+/g, ' ').trim();
          const title = label ? (label.charAt(0).toUpperCase() + label.slice(1)) : key;
          fallbackFromTerms.push({
            key: key,
            labelNl: title,
            labelFr: title,
            descNl: '',
            descFr: ''
          });
        });
      }

      if (fallbackFromTerms.length) {
        return fallbackFromTerms;
      }

      return [
        {
          key: 'flow',
          labelNl: 'Flow',
          labelFr: 'Flux',
          descNl: '',
          descFr: ''
        }
      ];
    }

    normalizeTopicText(value) {
      return String(value || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9\s-]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    }

    buildTopicTermsMap(rawMap) {
      const source = rawMap && typeof rawMap === 'object' ? rawMap : {};
      const map = {};

      this.topicChoices.forEach((choice) => {
        const key = choice.key;
        const candidateTerms = Array.isArray(source[key]) && source[key].length
          ? source[key]
          : [choice.labelNl, choice.labelFr, key];
        const cleanTerms = [];
        candidateTerms.forEach((term) => {
          const normalized = this.normalizeTopicText(term);
          if (!normalized || cleanTerms.includes(normalized)) return;
          cleanTerms.push(normalized);
        });
        if (cleanTerms.length) {
          map[key] = cleanTerms;
        }
      });

      return map;
    }

    detectTopicFromText(messageText) {
      const normalized = this.normalizeTopicText(messageText);
      if (!normalized) return '';

      const scores = [];
      this.topicChoices.forEach((choice) => {
        const terms = Array.isArray(this.topicTermsMap[choice.key]) ? this.topicTermsMap[choice.key] : [];
        let score = 0;
        terms.forEach((term) => {
          if (term && normalized.indexOf(term) !== -1) {
            score += 1;
          }
        });
        scores.push({ key: choice.key, score: score });
      });

      scores.sort((a, b) => b.score - a.score);
      const best = scores[0] || { key: '', score: 0 };
      const second = scores[1] || { key: '', score: 0 };
      if (!best.key || best.score <= 0) return '';
      if (second.score > 0 && best.score <= second.score) return '';
      return best.key;
    }

    ensureTopicForSilentMode() {
      if (this.showTopicSelector) return;
      const fallback = this.topicChoices[0];
      if (!fallback) return;
      if (!this.selectedTopic || !this.topicChoices.find((choice) => choice.key === this.selectedTopic)) {
        this.selectedTopic = fallback.key;
        sessionStorage.setItem(this.topicStorageKey, this.selectedTopic);
      }
    }

    requestTopicSelection(options) {
      if (!this.showTopicSelector) return;

      const config = options && typeof options === 'object' ? options : {};
      const forceChoice = !!config.forceChoice;
      const announce = !!config.announce;
      const suggestedTopic = typeof config.suggestedTopic === 'string' ? config.suggestedTopic : '';

      if (forceChoice && this.selectedTopic) {
        this.previousTopicBeforeSelection = this.selectedTopic;
      } else if (forceChoice) {
        this.previousTopicBeforeSelection = '';
      }

      if (forceChoice) {
        this.selectedTopic = '';
        sessionStorage.removeItem(this.topicStorageKey);
      }

      this.pendingSuggestedTopic = this.topicChoices.find((choice) => choice.key === suggestedTopic)
        ? suggestedTopic
        : '';

      this.updateTopicUi();
      this.topicPanel.classList.add('visible');
      this.topicPanel.classList.toggle('is-required', forceChoice);
      this.chatInput.blur();

      if (announce) {
        const prompt = this.lang === 'FR'
          ? 'Choisis a nouveau le flux correct: **Plateforme Digitale Interactive (PDI)** ou **Logiciel de comptabilite**.'
          : 'Kies opnieuw de juiste flow: **Klantenportaal** of **Boekhoudprogramma**.';
        this.addMessage(prompt, 'bot', { chatId: 0 });
      }
    }

    setTopic(key, announce) {
      if (!this.topicChoices.find((choice) => choice.key === key)) return;

      const previous = this.selectedTopic || this.previousTopicBeforeSelection || '';
      this.selectedTopic = key;
      this.dismissedSuggestedTopics.clear();
      this.pendingSuggestedTopic = '';
      this.previousTopicBeforeSelection = '';
      sessionStorage.setItem(this.topicStorageKey, key);
      this.updateTopicUi();
      this.topicPanel.classList.remove('visible', 'is-required');

      if (announce) {
        const meta = this.topicChoices.find((choice) => choice.key === key);
        const label = meta ? this.getTopicLabel(meta) : key;
        let message = '';
        if (this.lang === 'FR') {
          if (previous && previous !== key) {
            message = 'Je bascule vers **' + label + '**.';
          } else if (previous === key) {
            message = 'Parfait, je reste sur **' + label + '**.';
          } else {
            message = 'Merci ! Je vais chercher pour **' + label + '**.';
          }
        } else if (previous && previous !== key) {
          message = 'Ik schakel over naar **' + label + '**.';
        } else if (previous === key) {
          message = 'Prima, ik blijf in **' + label + '**.';
        } else {
          message = 'Top! Ik zoek vanaf nu over **' + label + '**.';
        }
        this.addMessage(message, 'bot', { chatId: 0 });
      }

      setTimeout(() => {
        if (!this.chatInput.disabled) this.chatInput.focus();
      }, 80);
    }

    clearPendingTopicDecision(markClosed) {
      if (!this.pendingTopicDecision) return;

      const decision = this.pendingTopicDecision;
      this.pendingTopicDecision = null;

      if (!decision.actions || !(decision.actions instanceof Element)) return;

      const buttons = decision.actions.querySelectorAll('button');
      buttons.forEach((button) => {
        button.disabled = true;
        button.classList.add('is-disabled');
      });

      if (markClosed) {
        const closedText = this.lang === 'FR'
          ? 'Decision ignoree. Pose ta question suivante.'
          : 'Keuze vervallen. Stel gerust je volgende vraag.';
        decision.actions.innerHTML = '<span class="topic-switch-note">' + escapeHtml(closedText) + '</span>';
      }
    }

    createTypingIndicator() {
      const typing = document.createElement('div');
      typing.classList.add('typing-indicator');
      typing.innerHTML = '<span class="dot"></span><span class="dot"></span><span class="dot"></span>';
      this.chatMessages.appendChild(typing);
      this.scrollMessagesToBottom(true);
      return typing;
    }

    showTopicMismatchDecision(messageText, payload, suggested) {
      if (!this.showTopicSelector || !suggested) {
        this.addMessage(
          payload.answer || (this.i18n.api_error || 'Er ging iets mis met het ophalen van het antwoord.'),
          'bot',
          {
            chatId: payload.chatId,
            primarySourceUrl: payload.primarySourceUrl
          }
        );
        return;
      }

      this.clearPendingTopicDecision(true);

      const currentMeta = this.topicChoices.find((choice) => choice.key === this.selectedTopic) || null;
      const currentLabel = currentMeta ? this.getTopicLabel(currentMeta) : (this.lang === 'FR' ? 'flux actuel' : 'huidige flow');
      const suggestedLabel = this.getTopicLabel(suggested);
      const prompt = this.lang === 'FR'
        ? 'Ta question semble plutot concerner **' + suggestedLabel + '**. Tu es actuellement dans **' + currentLabel + '**. Veux-tu basculer ?'
        : 'Je vraag lijkt eerder over **' + suggestedLabel + '** te gaan. Je zit momenteel in **' + currentLabel + '**. Wil je overschakelen?';
      const switchLabel = this.i18n.switch_yes || (this.lang === 'FR' ? 'Oui, basculer' : 'Ja, overschakelen');
      const keepLabel = this.i18n.switch_no || (this.lang === 'FR' ? 'Non, rester ici' : 'Nee, hier blijven');

      const promptNode = this.addMessage(prompt, 'bot', { chatId: 0 });
      if (!(promptNode instanceof Element)) {
        return;
      }

      const actions = document.createElement('div');
      actions.className = 'topic-switch-actions';
      actions.innerHTML = ''
        + '<button type="button" class="topic-switch-btn is-primary" data-action="switch">' + escapeHtml(switchLabel) + '</button>'
        + '<button type="button" class="topic-switch-btn is-secondary" data-action="stay">' + escapeHtml(keepLabel) + '</button>';
      promptNode.appendChild(actions);
      this.scrollMessagesToBottom(true);

      const decision = { actions: actions };
      this.pendingTopicDecision = decision;

      const onChoice = async (mode) => {
        if (this.pendingTopicDecision !== decision) return;
        this.pendingTopicDecision = null;

        const buttons = actions.querySelectorAll('button');
        buttons.forEach((button) => {
          button.disabled = true;
          button.classList.add('is-disabled');
        });

        this.setSendingState(true);
        const typing = this.createTypingIndicator();

        try {
          let followPayload = null;
          if (mode === 'switch') {
            this.setTopic(suggested.key, true);
            followPayload = await this.requestBotPayload(messageText, suggested.key);
          } else {
            const keepNotice = this.i18n.switch_stay_notice || (
              this.lang === 'FR'
                ? "D'accord, je reste dans le flux actuel."
                : 'Prima, ik blijf in je huidige flow.'
            );
            this.addMessage(keepNotice, 'bot', { chatId: 0 });
            followPayload = await this.requestBotPayload(
              messageText,
              this.selectedTopic,
              { skipTopicMismatch: true }
            );
          }

          const followAnswer = (followPayload && followPayload.answer) || payload.answer || (this.i18n.api_error || 'Er ging iets mis met het ophalen van het antwoord.');
          this.addMessage(followAnswer, 'bot', {
            chatId: followPayload ? followPayload.chatId : 0,
            primarySourceUrl: followPayload ? followPayload.primarySourceUrl : payload.primarySourceUrl
          });
          actions.innerHTML = '<span class="topic-switch-note">' + escapeHtml(this.lang === 'FR' ? 'Choix applique.' : 'Keuze toegepast.') + '</span>';
        } catch (error) {
          this.addMessage(getApiErrorText(error, this.i18n.api_error || 'Er ging iets mis met het ophalen van het antwoord.'), 'bot', { chatId: 0 });
          actions.innerHTML = '<span class="topic-switch-note">' + escapeHtml(this.lang === 'FR' ? 'Proposition non traitee.' : 'Keuze kon niet verwerkt worden.') + '</span>';
        } finally {
          typing.remove();
          this.setSendingState(false);
          this.updateComposerState();
        }
      };

      actions.querySelector('[data-action="switch"]').addEventListener('click', () => onChoice('switch'));
      actions.querySelector('[data-action="stay"]').addEventListener('click', () => onChoice('stay'));
    }

    showTopicSuggestionDecision(context) {
      const config = context && typeof context === 'object' ? context : {};
      const suggested = config.suggested && typeof config.suggested === 'object' ? config.suggested : null;
      const messageText = String(config.messageText || '').trim();
      const fallbackAnswer = String(config.fallbackAnswer || '').trim();

      if (!this.showTopicSelector || !suggested || this.selectedTopic) return;
      if (!messageText) return;
      if (this.dismissedSuggestedTopics.has(suggested.key)) return;

      this.clearPendingTopicDecision(true);

      const suggestedLabel = this.getTopicLabel(suggested);
      const prompt = this.lang === 'FR'
        ? 'Ta question semble concerner **' + suggestedLabel + '**. Veux-tu definir ce flux pour les prochaines reponses ?'
        : 'Je vraag lijkt over **' + suggestedLabel + '** te gaan. Wil je deze flow instellen voor volgende antwoorden?';
      const yesLabel = this.lang === 'FR' ? 'Oui, definir' : 'Ja, instellen';
      const noLabel = this.lang === 'FR' ? 'Non, pas maintenant' : 'Nee, nu niet';

      const promptNode = this.addMessage(prompt, 'bot', { chatId: 0 });
      if (!(promptNode instanceof Element)) {
        return;
      }

      const actions = document.createElement('div');
      actions.className = 'topic-switch-actions';
      actions.innerHTML = ''
        + '<button type="button" class="topic-switch-btn is-primary" data-action="set">' + escapeHtml(yesLabel) + '</button>'
        + '<button type="button" class="topic-switch-btn is-secondary" data-action="skip">' + escapeHtml(noLabel) + '</button>';
      promptNode.appendChild(actions);
      this.scrollMessagesToBottom(true);

      const decision = { actions: actions };
      this.pendingTopicDecision = decision;

      const finalize = (note) => {
        if (this.pendingTopicDecision === decision) {
          this.pendingTopicDecision = null;
        }
        actions.innerHTML = '<span class="topic-switch-note">' + escapeHtml(note) + '</span>';
      };

      const onChoice = async (mode) => {
        if (this.pendingTopicDecision !== decision) return;

        const buttons = actions.querySelectorAll('button');
        buttons.forEach((button) => {
          button.disabled = true;
          button.classList.add('is-disabled');
        });

        const isSet = mode === 'set';
        if (isSet) {
          this.dismissedSuggestedTopics.delete(suggested.key);
          this.setTopic(suggested.key, true);
        } else {
          this.dismissedSuggestedTopics.add(suggested.key);
          finalize(this.lang === 'FR' ? "D'accord, je continue sans flux fixe." : 'Prima, ik ga verder zonder vaste flow.');
          this.updateComposerState();
          return;
        }

        this.setSendingState(true);
        const typing = this.createTypingIndicator();

        try {
          const followPayload = await this.requestBotPayload(
            messageText,
            suggested.key,
            { skipTopicMismatch: true }
          );
          const followAnswer = String((followPayload && followPayload.answer) || '').trim();
          const shouldAppendFollowAnswer = followAnswer !== '' && followAnswer !== fallbackAnswer;

          if (shouldAppendFollowAnswer) {
            this.addMessage(followAnswer, 'bot', {
              chatId: followPayload ? followPayload.chatId : 0,
              primarySourceUrl: followPayload ? followPayload.primarySourceUrl : ''
            });
          }

          finalize(this.lang === 'FR' ? 'Flux defini.' : 'Flow ingesteld.');
        } catch (error) {
          this.addMessage(getApiErrorText(error, this.i18n.api_error || 'Er ging iets mis met het ophalen van het antwoord.'), 'bot', { chatId: 0 });
          finalize(this.lang === 'FR' ? 'Proposition non traitee.' : 'Keuze kon niet verwerkt worden.');
        } finally {
          typing.remove();
          this.setSendingState(false);
          this.updateComposerState();
        }
      };

      actions.querySelector('[data-action="set"]').addEventListener('click', () => onChoice('set'));
      actions.querySelector('[data-action="skip"]').addEventListener('click', () => onChoice('skip'));
    }

    isNearMessagesBottom(thresholdPx) {
      const threshold = Number.isFinite(Number(thresholdPx)) ? Number(thresholdPx) : 90;
      const remaining = this.chatMessages.scrollHeight - this.chatMessages.scrollTop - this.chatMessages.clientHeight;
      return remaining <= threshold;
    }

    scrollMessagesToBottom(force) {
      if (!force && !this.isNearMessagesBottom(90)) return;
      this.chatMessages.scrollTo({ top: this.chatMessages.scrollHeight, behavior: 'smooth' });
    }

    updateTopicUi() {
      this.ensureTopicForSilentMode();
      const meta = this.topicChoices.find((choice) => choice.key === this.selectedTopic) || null;

      if (!this.showTopicSelector) {
        this.topicBadge.style.display = 'none';
        this.topicPanel.style.display = 'none';
        this.topicQuickPick.classList.add('is-hidden');
        this.topicStatus.classList.add('is-hidden');
        this.chatInput.disabled = false;
        this.chatInput.placeholder = this.i18n.placeholder || 'Typ je vraag...';
        this.updateComposerState();
        return;
      }

      this.topicBadge.style.display = '';
      this.topicPanel.style.display = '';
      this.topicBadge.textContent = meta ? this.getTopicLabel(meta) : (this.lang === 'FR' ? 'Choisir un sujet' : 'Kies een onderwerp');

      if (meta) {
        this.topicStatus.classList.remove('is-hidden');
        this.topicStatusLabel.textContent = this.lang === 'FR'
          ? 'Vous consultez les reponses pour ' + this.getTopicLabel(meta) + '.'
          : 'Ik zoek antwoorden over ' + this.getTopicLabel(meta) + '.';
        this.topicQuickPick.classList.add('is-hidden');
      } else {
        this.topicStatus.classList.add('is-hidden');
        this.topicQuickPick.classList.remove('is-hidden');
      }

      const ready = !!meta;
      this.chatInput.disabled = false;
      this.chatInput.placeholder = ready
        ? (this.i18n.placeholder || 'Typ je vraag...')
        : (this.lang === 'FR' ? 'Tape ta question (ou choisis un flux ci-dessous)...' : 'Typ je vraag (of kies hieronder een flow)...');

      this.topicOptions.forEach((button) => {
        const topicKey = button.dataset.topic || '';
        button.setAttribute('aria-pressed', topicKey === this.selectedTopic ? 'true' : 'false');
        button.classList.toggle('is-selected', topicKey === this.selectedTopic);
        button.classList.toggle(
          'is-suggested',
          !meta && this.pendingSuggestedTopic !== '' && topicKey === this.pendingSuggestedTopic
        );
      });
      this.topicQuickButtons.forEach((button) => {
        const topicKey = button.dataset.topic || '';
        button.setAttribute('aria-pressed', topicKey === this.selectedTopic ? 'true' : 'false');
      });

      this.updateComposerState();
    }

    updateComposerState() {
      const hasText = this.chatInput.value.trim().length > 0;
      const canSend = !this.isSending && !this.chatInput.disabled && hasText;

      this.chatSend.disabled = !canSend;
      this.chatSend.classList.toggle('is-disabled', !canSend);
    }

    setSendingState(sending) {
      this.isSending = sending;
      this.chatSend.classList.toggle('is-loading', sending);
      this.chatInput.classList.toggle('is-busy', sending);

      if (sending) {
        this.chatSend.disabled = true;
        this.chatInput.disabled = true;
      } else {
        this.updateTopicUi();
        this.updateComposerState();
      }
    }

    animateMessageEntry(element) {
      if (this.prefersReducedMotion) return;

      element.classList.add('chat-message-enter');
      requestAnimationFrame(function () {
        element.classList.add('chat-message-enter-active');
      });
      setTimeout(function () {
        element.classList.remove('chat-message-enter', 'chat-message-enter-active');
      }, 320);
    }

    renderMessage(content, sender, options) {
      const shouldAutoScroll = sender === 'user' || this.isNearMessagesBottom(96);
      const message = document.createElement('div');
      message.classList.add(sender === 'user' ? 'user-message' : 'bot-message');
      message.innerHTML = formatMessageToHtml(content, this.fallbackButtonLabel);

      if (sender === 'bot') {
        const primarySourceUrl = typeof (options && options.primarySourceUrl) === 'string'
          ? String(options.primarySourceUrl).trim()
          : '';
        if (primarySourceUrl) {
          let safeSourceUrl = '';
          try {
            const parsedUrl = new URL(primarySourceUrl, window.location.origin);
            if (parsedUrl.protocol === 'http:' || parsedUrl.protocol === 'https:') {
              safeSourceUrl = parsedUrl.toString();
            }
          } catch (error) {
            safeSourceUrl = '';
          }

          if (safeSourceUrl) {
            const sourceWrap = document.createElement('div');
            sourceWrap.className = 'octopus-primary-source';

            const sourceLabel = document.createElement('span');
            sourceLabel.className = 'octopus-primary-source-label';
            sourceLabel.textContent = this.lang === 'FR' ? 'Source principale:' : 'Primaire bron:';
            sourceWrap.appendChild(sourceLabel);

            const sourceLink = document.createElement('a');
            sourceLink.href = safeSourceUrl;
            sourceLink.target = '_blank';
            sourceLink.rel = 'noopener noreferrer';
            sourceLink.textContent = this.lang === 'FR' ? 'Voir la page' : 'Bekijk pagina';
            sourceWrap.appendChild(sourceLink);

            message.appendChild(sourceWrap);
          }
        }

        const chatId = Number(options && options.chatId ? options.chatId : 0);
        if (chatId > 0 && !this.sentFeedback.has(chatId)) {
          message.insertAdjacentHTML('beforeend', `
            <div class="octopus-feedback" data-chat-id="${chatId}">
              <button type="button" class="octopus-feedback-btn" data-feedback="up" aria-label="Positive feedback">&#128077;</button>
              <button type="button" class="octopus-feedback-btn" data-feedback="down" aria-label="Negative feedback">&#128078;</button>
            </div>
          `);
        }
      }

      this.chatMessages.appendChild(message);
      this.animateMessageEntry(message);
      if (shouldAutoScroll) {
        this.scrollMessagesToBottom(true);
      }
      return message;
    }

    addMessage(content, sender, options) {
      const payload = {
        sender: sender === 'user' ? 'user' : 'bot',
        content: String(content || ''),
        chatId: Number(options && options.chatId ? options.chatId : 0),
        primarySourceUrl: typeof (options && options.primarySourceUrl) === 'string'
          ? String(options.primarySourceUrl).trim()
          : ''
      };

      if (!payload.content.trim()) return;
      this.messages.push(payload);
      const node = this.renderMessage(payload.content, payload.sender, {
        chatId: payload.chatId,
        primarySourceUrl: payload.primarySourceUrl
      });
      this.saveMessages();
      return node;
    }

    serializeHistoryForApi() {
      return this.messages
        .filter((item) => (
          (item.sender === 'user' || item.sender === 'bot') &&
          item.content.trim().length > 0
        ))
        .slice(-14)
        .map((item) => ({
          role: item.sender === 'bot' ? 'assistant' : 'user',
          content: item.content.trim().slice(0, 1500)
        }));
    }

    async requestLiteFallbackPayload(messageText, topicKey, skipTopicMismatch, fallbackErrorText) {
      const liteEndpoint = String(this.restEndpointLite || '').trim();
      const mainEndpoint = String(this.restEndpoint || '').trim();
      if (!liteEndpoint || liteEndpoint === mainEndpoint) {
        return null;
      }

      try {
        const liteResponse = await fetchWithTimeout(liteEndpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            message: messageText,
            history: this.serializeHistoryForApi(),
            topic: topicKey || this.selectedTopic,
            skip_topic_mismatch: !!skipTopicMismatch
          })
        }, this.requestTimeoutMs);

        const liteRaw = await liteResponse.text();
        if (!liteResponse.ok || isCriticalHtmlPayload(liteRaw)) {
          return null;
        }

        const payload = parseBotPayload(liteRaw);
        const answer = String(payload.answer || '').trim();
        if (!answer) {
          return null;
        }
        return payload;
      } catch (error) {
        return null;
      }
    }

    async requestBotPayload(messageText, topicKey, options) {
      const config = options && typeof options === 'object' ? options : {};
      const skipTopicMismatch = !!config.skipTopicMismatch;
      const fallbackErrorText = this.i18n.api_error || 'Er ging iets mis met het ophalen van het antwoord.';

      let response = null;
      let raw = '';
      try {
        response = await fetchWithTimeout(this.restEndpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            message: messageText,
            history: this.serializeHistoryForApi(),
            topic: topicKey || this.selectedTopic,
            skip_topic_mismatch: skipTopicMismatch
          })
        }, this.requestTimeoutMs);
        raw = await response.text();
      } catch (error) {
        const litePayload = await this.requestLiteFallbackPayload(messageText, topicKey, skipTopicMismatch, fallbackErrorText);
        if (litePayload) {
          return litePayload;
        }
        return {
          answer: getApiErrorText(error, fallbackErrorText),
          chatId: 0,
          status: 'transport_error',
          suggestedTopic: '',
          currentTopic: this.selectedTopic || '',
          primarySourceUrl: ''
        };
      }

      if (isCriticalHtmlPayload(raw)) {
        const litePayload = await this.requestLiteFallbackPayload(messageText, topicKey, skipTopicMismatch, fallbackErrorText);
        if (litePayload) {
          return litePayload;
        }
        return {
          answer: fallbackErrorText,
          chatId: 0,
          status: 'runtime_html_error',
          suggestedTopic: '',
          currentTopic: this.selectedTopic || '',
          primarySourceUrl: ''
        };
      }

      if (!response.ok) {
        const litePayload = await this.requestLiteFallbackPayload(messageText, topicKey, skipTopicMismatch, fallbackErrorText);
        if (litePayload) {
          return litePayload;
        }

        const serverMessage = parseApiErrorMessage(raw);
        const statusCode = Number(response.status || 0);
        const userMessage = serverMessage || (fallbackErrorText + (statusCode > 0 ? (' (HTTP ' + String(statusCode) + ')') : ''));
        return {
          answer: userMessage,
          chatId: 0,
          status: 'http_error',
          suggestedTopic: '',
          currentTopic: this.selectedTopic || '',
          primarySourceUrl: ''
        };
      }

      const payload = parseBotPayload(raw);
      if (String(payload.answer || '').trim() === '') {
        const litePayload = await this.requestLiteFallbackPayload(messageText, topicKey, skipTopicMismatch, fallbackErrorText);
        if (litePayload) {
          return litePayload;
        }
      }

      return payload;
    }

    showWelcomeOnce(delayMs) {
      if (sessionStorage.getItem(this.welcomeSessionKey)) {
        return;
      }

      const welcome = this.config.welcomeMessage || this.settings.welcome_message || (
        this.lang === 'FR' ? 'Bonjour ! Comment puis-je t aider ?' : 'Hallo! Hoe kan ik je vandaag helpen?'
      );

      setTimeout(() => {
        this.addMessage(welcome, 'bot', { chatId: 0 });
        sessionStorage.setItem(this.welcomeSessionKey, '1');
      }, delayMs);
    }

    async sendMessage() {
      this.ensureTopicForSilentMode();
      this.clearPendingTopicDecision(true);

      if (this.showTopicSelector && !this.selectedTopic) {
        this.topicPanel.classList.add('visible');
      }

      const text = this.chatInput.value.trim();
      if (!text || this.sendCooldown || this.isSending) return;

      this.sendCooldown = true;
      setTimeout(() => {
        this.sendCooldown = false;
      }, 1200);

      this.addMessage(text, 'user', { chatId: 0 });
      this.chatInput.value = '';
      this.setSendingState(true);
      this.updateComposerState();

      const typing = this.createTypingIndicator();

      try {
        const payload = await this.requestBotPayload(text, this.selectedTopic);
        const rawAnswer = String(payload.answer || '').trim();
        const answer = (!rawAnswer || isCriticalHtmlPayload(rawAnswer))
          ? (this.i18n.api_error || 'Er ging iets mis met het ophalen van het antwoord.')
          : rawAnswer;

        const suggested = this.topicChoices.find((choice) => choice.key === payload.suggestedTopic) || null;
        const shouldConfirmSwitch = payload.status === 'topic_mismatch' && this.showTopicSelector && !!suggested;

        typing.remove();
        if (shouldConfirmSwitch) {
          this.showTopicMismatchDecision(text, payload, suggested);
          return;
        }

        this.addMessage(answer, 'bot', {
          chatId: payload.chatId,
          primarySourceUrl: payload.primarySourceUrl
        });

        if (payload.status === 'topic_mismatch' && this.showTopicSelector) {
          this.requestTopicSelection({
            forceChoice: false,
            suggestedTopic: payload.suggestedTopic,
            announce: false
          });
        } else if (this.showTopicSelector && !this.selectedTopic) {
          const inferredTopicKey = this.detectTopicFromText(text);
          const inferredTopic = this.topicChoices.find((choice) => choice.key === inferredTopicKey) || null;
          if (inferredTopic) {
            this.showTopicSuggestionDecision({
              suggested: inferredTopic,
              messageText: text,
              fallbackAnswer: answer
            });
          }
        }
      } catch (error) {
        typing.remove();
        this.addMessage(getApiErrorText(error, this.i18n.api_error || 'Er ging iets mis met het ophalen van het antwoord.'), 'bot', { chatId: 0 });
      } finally {
        this.setSendingState(false);
        this.updateComposerState();
      }
    }

    async submitFeedback(chatId, feedback, container) {
      if (!this.feedbackEndpoint) return;
      if (!Number.isFinite(chatId) || chatId <= 0) return;
      if (this.sentFeedback.has(chatId)) return;
      if (!['up', 'down'].includes(feedback)) return;

      const buttons = container.querySelectorAll('.octopus-feedback-btn');
      buttons.forEach((button) => {
        button.disabled = true;
        button.classList.add('is-disabled');
      });

      try {
        const response = await fetchWithTimeout(this.feedbackEndpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            chat_id: chatId,
            feedback: feedback
          })
        }, this.feedbackTimeoutMs);

        if (!response.ok) {
          throw new Error('feedback_failed');
        }

        this.sentFeedback.add(chatId);
        this.saveFeedbackSet();

        const thanks = feedback === 'up'
          ? (this.i18n.feedback_up || 'Bedankt voor je positieve feedback!')
          : (this.i18n.feedback_down || 'Bedankt voor je feedback.');

        container.classList.add('is-locked');
        container.innerHTML = '<span class="octopus-feedback-note">' + escapeHtml(thanks) + '</span>';
      } catch (error) {
        buttons.forEach((button) => {
          button.disabled = false;
          button.classList.remove('is-disabled');
        });
      }
    }

    resetConversation() {
      const confirmation = this.i18n.reset_confirm || 'Weet je zeker dat je het gesprek wilt vernieuwen?';
      if (!window.confirm(confirmation)) {
        return;
      }

      this.messages = [];
      this.sentFeedback.clear();
      this.dismissedSuggestedTopics.clear();
      this.clearPendingTopicDecision(false);
      sessionStorage.removeItem(this.historyStorageKey);
      sessionStorage.removeItem(this.topicStorageKey);
      sessionStorage.removeItem(this.welcomeSessionKey);
      sessionStorage.removeItem(this.feedbackStorageKey);

      this.chatMessages.querySelectorAll('.user-message, .bot-message, .typing-indicator').forEach((node) => node.remove());
      this.selectedTopic = '';
      this.pendingSuggestedTopic = '';
      this.previousTopicBeforeSelection = '';
      this.topicPanel.classList.remove('is-required');
      this.updateTopicUi();

      if (this.showTopicSelector) {
        this.requestTopicSelection({ forceChoice: false, announce: false });
      }

      if (this.isEmbedded) {
        this.showWelcomeOnce(0);
      }

      this.updateComposerState();
    }
  }

  if (renderMode === 'elementor_widget') {
    const mounts = Array.from(document.querySelectorAll('[data-octopus-chatbot-widget]'));
    if (!mounts.length) return;

    mounts.forEach((mount, index) => {
      if (mount.getAttribute('data-octopus-chatbot-initialized') === '1') return;
      mount.setAttribute('data-octopus-chatbot-initialized', '1');

      const instance = new OctopusChatbotInstance({
        mode: 'embedded',
        mount: mount,
        instanceIndex: index,
        settings: settings,
        i18n: i18n,
        lang: lang,
        prefersReducedMotion: prefersReducedMotion
      });
      instance.init();
    });

    return;
  }

  if (window.__octopusAiFloatingBooted) {
    return;
  }
  window.__octopusAiFloatingBooted = true;

  const floatingInstance = new OctopusChatbotInstance({
    mode: 'floating',
    mount: null,
    instanceIndex: 0,
    settings: settings,
    i18n: i18n,
    lang: lang,
    prefersReducedMotion: prefersReducedMotion
  });
  floatingInstance.init();
});
