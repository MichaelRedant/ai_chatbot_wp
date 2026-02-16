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

  function parseBotPayload(raw) {
    const fallbackText = String(raw || '').trim();
    const payload = {
      answer: '',
      chatId: 0,
      status: '',
      suggestedTopic: '',
      currentTopic: ''
    };

    if (!fallbackText) return payload;

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
        return payload;
      }
    } catch (error) {
      // keep raw fallback text
    }

    payload.answer = fallbackText;
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

      if (/^[-*]\s+/.test(raw)) {
        flushPara();
        if (!inUl) {
          closeLists();
          out += '<ul>';
          inUl = true;
        }
        out += '<li>' + raw.replace(/^[-*]\s+/, '') + '</li>';
        continue;
      }

      if (/^\d+[\.)]\s+/.test(raw)) {
        flushPara();
        if (!inOl) {
          closeLists();
          out += '<ol>';
          inOl = true;
        }
        out += '<li>' + raw.replace(/^\d+[\.)]\s+/, '') + '</li>';
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

    html = html.replace(/https:\/\/login\.octopus\.be\/manual\/[\w/\-_.?#=&%]+/g, function (url, offset) {
      const before = html.slice(Math.max(0, offset - 15), offset);
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
      this.feedbackEndpoint = this.settings.feedback_url || '/wp-json/octopus-ai/v1/feedback';
      this.sentFeedback = new Set();
      this.messages = [];
      this.isSending = false;
      this.sendCooldown = false;
      this.isEmbedded = this.mode === 'embedded';

      this.topicChoices = [
        {
          key: 'klantenportaal',
          labelNl: 'Klantenportaal',
          labelFr: 'Plateforme Digitale Interactive (PDI)',
          descNl: 'Vragen over facturen, betalingen of support via het klantenportaal.',
          descFr: 'Questions sur les factures, paiements ou support dans la Plateforme Digitale Interactive (PDI).'
        },
        {
          key: 'boekhoudprogramma',
          labelNl: 'Boekhoudprogramma',
          labelFr: 'Logiciel de comptabilite',
          descNl: 'Vragen over boekhouding, btw of rapportages binnen het boekhoudprogramma.',
          descFr: 'Questions sur la comptabilite, TVA ou rapports dans le logiciel.'
        }
      ];
      this.pendingSuggestedTopic = '';
      this.previousTopicBeforeSelection = '';
    }

    init() {
      this.config = this.readConfig();
      this.buildDom();
      this.bindEvents();
      this.restoreSessionState();
      this.updateTopicUi();
      this.updateComposerState();

      if (this.showTopicSelector && !this.selectedTopic) {
        this.topicPanel.classList.add('visible', 'is-required');
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
          headerTextColor: ''
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
        headerTextColor: (this.mount.getAttribute('data-widget-header-text-color') || '').trim()
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
    }

    buildDom() {
      this.buildStorageKeys();

      this.primaryColor = this.config.primaryColor || this.settings.primary_color || '#0f6c95';
      this.headerTextColor = this.config.headerTextColor || this.settings.header_text_color || '#ffffff';
      this.headerTitle = this.config.title || this.settings.brand_name || 'AI Chatbot';
      this.fallbackButtonLabel = this.i18n.fallback_button || 'Bekijk dit in de handleiding';

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
          <button id="chat-close" type="button" aria-label="Sluiten" class="chat-close-button">&times;</button>
        </div>
        <div id="chat-messages" role="log" aria-live="polite" aria-relevant="additions text" aria-atomic="false"></div>
        <button id="chat-reset" type="button" class="chat-reset-button" title="${escapeHtml(this.i18n.reset_title || 'Reset')}">${escapeHtml(this.i18n.reset_button || 'Vernieuw')}</button>
        <div id="chat-input-container">
          <input type="text" id="chat-input" placeholder="${escapeHtml(this.i18n.placeholder || 'Typ je vraag...')}" />
          <button id="chat-send" type="button">${escapeHtml(this.i18n.send || 'Verstuur')}</button>
        </div>
      `;
      this.root.appendChild(this.chatbot);

      this.chatMessages = this.chatbot.querySelector('#chat-messages');
      this.chatInput = this.chatbot.querySelector('#chat-input');
      this.chatSend = this.chatbot.querySelector('#chat-send');
      this.chatClose = this.chatbot.querySelector('#chat-close');
      this.chatReset = this.chatbot.querySelector('#chat-reset');
      this.chatInputContainer = this.chatbot.querySelector('#chat-input-container');
      this.headerInner = this.chatbot.querySelector('.chat-header-inner');
      this.chatMessages.setAttribute('aria-label', this.lang === 'FR' ? 'Historique de conversation' : 'Gespreksgeschiedenis');
      this.chatClose.setAttribute('aria-label', this.lang === 'FR' ? 'Fermer' : 'Sluiten');
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
            ? 'Souhaitez-vous poser une question sur la Plateforme Digitale Interactive (PDI) ou le logiciel comptable ?'
            : 'Heb je een vraag over het klantenportaal of het boekhoudprogramma?'}</p>
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
        }, delay);
      });

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

    restoreSessionState() {
      this.selectedTopic = sessionStorage.getItem(this.topicStorageKey) || '';
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
          if (!text) return;

          this.messages.push({
            sender: sender,
            content: text,
            chatId: chatId
          });
          this.renderMessage(text, sender, { chatId: chatId });
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
    }

    addMessage(content, sender, options) {
      const payload = {
        sender: sender === 'user' ? 'user' : 'bot',
        content: String(content || ''),
        chatId: Number(options && options.chatId ? options.chatId : 0)
      };

      if (!payload.content.trim()) return;
      this.messages.push(payload);
      this.renderMessage(payload.content, payload.sender, { chatId: payload.chatId });
      this.saveMessages();
    }

    serializeHistoryForApi() {
      return this.messages
        .filter((item) => item.sender === 'user' && item.content.trim().length > 0)
        .slice(-12)
        .map((item) => ({
          role: 'user',
          content: item.content.trim()
        }));
    }

    async requestBotPayload(messageText, topicKey) {
      const response = await fetch(this.restEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message: messageText,
          history: this.serializeHistoryForApi(),
          topic: topicKey || this.selectedTopic
        })
      });

      const raw = await response.text();
      return parseBotPayload(raw);
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

      const typing = document.createElement('div');
      typing.classList.add('typing-indicator');
      typing.innerHTML = '<span class="dot"></span><span class="dot"></span><span class="dot"></span>';
      this.chatMessages.appendChild(typing);
      this.scrollMessagesToBottom(true);

      try {
        const payload = await this.requestBotPayload(text, this.selectedTopic);
        const answer = payload.answer || (this.i18n.api_error || 'Er ging iets mis met het ophalen van het antwoord.');

        const suggested = this.topicChoices.find((choice) => choice.key === payload.suggestedTopic) || null;
        const canAutoSwitch = payload.status === 'topic_mismatch' && this.showTopicSelector && !!suggested;

        if (canAutoSwitch) {
          this.setTopic(suggested.key, false);

          const switchNotice = this.lang === 'FR'
            ? 'Je bascule automatiquement vers **' + this.getTopicLabel(suggested) + '** pour repondre correctement.'
            : 'Ik schakel automatisch over naar **' + this.getTopicLabel(suggested) + '** om je vraag correct te beantwoorden.';
          this.addMessage(switchNotice, 'bot', { chatId: 0 });

          const followPayload = await this.requestBotPayload(text, suggested.key);
          const followAnswer = followPayload.answer || answer;

          typing.remove();
          this.addMessage(followAnswer, 'bot', { chatId: followPayload.chatId });
        } else {
          typing.remove();
          this.addMessage(answer, 'bot', { chatId: payload.chatId });

          if (payload.status === 'topic_mismatch' && this.showTopicSelector) {
            this.requestTopicSelection({
              forceChoice: true,
              suggestedTopic: payload.suggestedTopic,
              announce: false
            });
          }
        }
      } catch (error) {
        typing.remove();
        this.addMessage(this.i18n.api_error || 'Er ging iets mis met het ophalen van het antwoord.', 'bot', { chatId: 0 });
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
        const response = await fetch(this.feedbackEndpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            chat_id: chatId,
            feedback: feedback
          })
        });

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
        this.requestTopicSelection({ forceChoice: true, announce: false });
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
