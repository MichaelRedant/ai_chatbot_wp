document.addEventListener('DOMContentLoaded', function () {
  const settings = window.octopus_ai_chatbot_vars || {};
  const i18n = settings.i18n || {};
  const lang = settings.lang || ((navigator.language || '').toLowerCase().startsWith('fr') ? 'FR' : 'NL');

  // Toggle button
  const toggleButton = document.createElement('div');
  toggleButton.id = 'octopus-chat-toggle';
  toggleButton.innerHTML = `<img src="${settings.logo_url || ''}" alt="Chatbot">`;
  document.body.appendChild(toggleButton);

  // Chat container
  const chatbot = document.createElement('div');
  chatbot.id = 'octopus-chatbot';
  document.body.appendChild(chatbot);

  // Header + layout
  const primary = settings.primary_color || '#0f6c95';
  chatbot.innerHTML = `
    <div id="chat-header" style="background-color:${primary};">
      <div class="chat-header-inner">
        <div class="chat-logo-glass"><img src="${settings.logo_url || ''}" alt="Logo" class="chat-logo"></div>
        <span class="chat-header-title">${settings.brand_name || 'AI Chatbot'}</span>
      </div>
      <button id="chat-close" aria-label="Sluiten" class="chat-close-button">&times;</button>
    </div>
    <div id="chat-messages"></div>
    <button id="chat-reset" class="chat-reset-button" title="${i18n.reset_title || 'Reset'}">${i18n.reset_button || 'Vernieuw'}</button>
    <div id="chat-input-container">
      <input type="text" id="chat-input" placeholder="${i18n.placeholder || 'Typ je vraag...'}" />
      <button id="chat-send">${i18n.send || 'Verstuur'}</button>
    </div>
  `;

  // Elements
  const chatMessages = document.getElementById('chat-messages');
  const chatInput = document.getElementById('chat-input');
  const chatSend = document.getElementById('chat-send');
  const chatClose = document.getElementById('chat-close');
  const chatReset = document.getElementById('chat-reset');
  const chatInputContainer = document.getElementById('chat-input-container');

  // Topic state and UI
  const topicChoices = [
    { key: 'klantenportaal', labelNl: 'Klantenportaal', labelFr: 'Portail client',
      descNl: 'Vragen over facturen, betalingen of support via het klantenportaal.',
      descFr: 'Questions sur les factures, paiements ou support dans le portail client.' },
    { key: 'boekhoudprogramma', labelNl: 'Boekhoudprogramma', labelFr: 'Logiciel de comptabilite',
      descNl: 'Vragen over boekhouding, btw of rapportages binnen het boekhoudprogramma.',
      descFr: 'Questions sur la comptabilite, la TVA ou les rapports dans le logiciel.' }
  ];
  const topicStorageKey = `octopus_chat_topic_${lang}`;
  const welcomeSessionKey = `octopus_chat_welcomed_${lang}`;
  let selectedTopic = sessionStorage.getItem(topicStorageKey) || '';

  const getLabel = (c) => (lang === 'FR' ? c.labelFr : c.labelNl);
  const getDesc = (c) => (lang === 'FR' ? c.descFr : c.descNl);

  const topicPanel = document.createElement('section');
  topicPanel.id = 'chat-topic-panel';
  topicPanel.innerHTML = `
    <div class="topic-panel-inner">
      <p class="topic-intro">${lang === 'FR' ? 'Souhaitez-vous poser une question sur le portail client ou le logiciel comptable ?' : 'Heb je een vraag over het klantenportaal of het boekhoudprogramma?'}</p>
      <div class="topic-grid">
        ${topicChoices.map(c => (
          `<button type="button" class="topic-option" data-topic="${c.key}">
            <span class="topic-option-title">${getLabel(c)}</span>
            <span class="topic-option-description">${getDesc(c)}</span>
          </button>`
        )).join('')}
      </div>
    </div>`;
  chatMessages.prepend(topicPanel);

  const topicStatus = document.createElement('div');
  topicStatus.id = 'chat-topic-status';
  topicStatus.innerHTML = `
    <span class="topic-status-label"></span>
    <button type="button" class="topic-change-button">${lang === 'FR' ? 'Changer' : 'Wijzig'}</button>`;
  chatbot.insertBefore(topicStatus, chatInputContainer);
  const topicStatusLabel = topicStatus.querySelector('.topic-status-label');
  const topicChangeButton = topicStatus.querySelector('.topic-change-button');
  const topicOptions = topicPanel.querySelectorAll('.topic-option');

  // Header badge
  const headerInner = chatbot.querySelector('.chat-header-inner');
  const topicBadge = document.createElement('button');
  topicBadge.id = 'chat-topic-badge';
  topicBadge.type = 'button';
  topicBadge.className = 'chat-topic-badge';
  topicBadge.addEventListener('click', () => topicPanel.classList.toggle('visible'));
  headerInner.appendChild(topicBadge);

  function updateTopicUI() {
    const meta = topicChoices.find(c => c.key === selectedTopic) || null;
    const badgeText = meta ? getLabel(meta) : (lang === 'FR' ? 'Choisir un sujet' : 'Kies een onderwerp');
    topicBadge.textContent = badgeText;
    if (meta) {
      topicStatus.classList.remove('is-hidden');
      topicStatusLabel.textContent = (lang === 'FR') ? `Vous consultez les reponses pour ${getLabel(meta)}.` : `Ik zoek antwoorden over ${getLabel(meta)}.`;
    } else {
      topicStatus.classList.add('is-hidden');
    }
    const ready = !!meta;
    chatInput.disabled = !ready;
    chatSend.disabled = !ready;
    chatInput.placeholder = ready ? (i18n.placeholder || 'Typ je vraag...') : (lang === 'FR' ? "Choisis d'abord un sujet..." : 'Kies eerst een onderwerp...');
    topicOptions.forEach(btn => btn.classList.toggle('is-selected', btn.dataset.topic === selectedTopic));
  }

  function setTopic(key, announce = true) {
    if (!topicChoices.find(c => c.key === key)) return;
    const prev = selectedTopic;
    selectedTopic = key;
    sessionStorage.setItem(topicStorageKey, key);
    updateTopicUI();
    topicPanel.classList.remove('visible');
    if (announce) {
      const label = getLabel(topicChoices.find(c => c.key === key));
      const msg = (lang === 'FR')
        ? (prev ? `Je bascule vers **${label}**.` : `Merci ! Je vais chercher pour **${label}**.`)
        : (prev ? `Ik schakel over naar **${label}**.` : `Top! Ik zoek vanaf nu over **${label}**.`);
      addMessage(msg, 'bot');
      saveChatHistory();
    }
  }

  topicOptions.forEach(btn => btn.addEventListener('click', () => setTopic(btn.dataset.topic)));
  topicChangeButton.addEventListener('click', (e) => {
    e.preventDefault();
    const keys = topicChoices.map(c => c.key);
    if (keys.length === 2 && selectedTopic && keys.includes(selectedTopic)) {
      const nextKey = keys.find(k => k !== selectedTopic) || keys[0];
      setTopic(nextKey);
    } else {
      topicPanel.classList.add('visible');
    }
  });

  function decodeUnicode(str) {
    return String(str || '')
      .replace(/\\u([0-9a-fA-F]{4})/g, function(_, hex){ return String.fromCharCode(parseInt(hex, 16)); });
  }
  function stripWrappingQuotes(str) {
    const t = String(str || '').trim();
    if ((t.startsWith('"') && t.endsWith('"')) || (t.startsWith("'") && t.endsWith("'"))) return t.slice(1, -1);
    return t;
  }

  // Normaliseer foutief achtergebleven 'n' markers naar echte newlines
  function normalizeBreaks(text) {
    let s = String(text || '');
    // 'nn' als paragraafscheiding wanneer niet in woorden
    s = s.replace(/(^|[^a-zA-Z])nn(?![a-zA-Z])/g, '$1\n\n');
    // ':n', '.n', ';n' enz. naar newline
    s = s.replace(/([\.:;])n(\s|$)/g, '$1\n');
    // ' n - ' of ' n- ' wordt bullet op nieuwe lijn
    s = s.replace(/([\s\.:;])n\s*-\s/g, '$1\n- ');
    // losse ' n ' als newline (fallback)
    s = s.replace(/\sn\s/g, '\n');
    return s;
  }

  function addMessage(content, sender) {
    const message = document.createElement('div');
    message.classList.add(sender === 'user' ? 'user-message' : 'bot-message');
    let text = stripWrappingQuotes(decodeUnicode(content));
    text = normalizeBreaks(text);
    let html = text;
    html = html
      .replace(/\r?\n/g, '<br>')
      .replace(/\\\n/g, '<br>')
      .replace(/\\(.)/g, '$1')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(?!\*)([^*]+)\*(?!\*)/g, '<em>$1</em>')
      .replace(/\[([^\]]+)]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

    // Autolink allowed manual domain (avoid double-link by checking preceding text contains href=)
    html = html.replace(/https:\/\/login\.octopus\.be\/manual\/[\w\/\-_.?#=&%]+/g, function(url, offset){
      const before = html.slice(Math.max(0, offset - 15), offset);
      if (before.indexOf('href=') !== -1) return url;
      const label = i18n.fallback_button || 'Bekijk dit in de handleiding';
      return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
    });

    // Structuur: maak paragrafen en lijsten van regels
    function enhanceStructure(htmlIn) {
      const parts = String(htmlIn || '').split(/<br\s*\/?\>/i);
      let out = '';
      let inUl = false, inOl = false;
      let para = [];
      const flushPara = () => {
        if (para.length) { out += '<p>' + para.join(' ') + '</p>'; para = []; }
      };
      const closeLists = () => {
        if (inUl) { out += '</ul>'; inUl = false; }
        if (inOl) { out += '</ol>'; inOl = false; }
      };
      for (let i = 0; i < parts.length; i++) {
        const raw = (parts[i] || '').trim();
        if (raw === '') { flushPara(); continue; }
        if (/^[-•*]\s+/.test(raw)) {
          flushPara();
          if (!inUl) { closeLists(); out += '<ul>'; inUl = true; }
          out += '<li>' + raw.replace(/^[-•*]\s+/, '') + '</li>';
          continue;
        }
        if (/^\d+[\.)]\s+/.test(raw)) {
          flushPara();
          if (!inOl) { closeLists(); out += '<ol>'; inOl = true; }
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

    html = enhanceStructure(html);

    message.innerHTML = html;
    chatMessages.appendChild(message);
    chatMessages.scrollTo({ top: chatMessages.scrollHeight, behavior: 'smooth' });
  }

  function saveChatHistory() {
    const only = Array.from(chatMessages.querySelectorAll('.user-message, .bot-message')).map(el => el.outerHTML).join('');
    sessionStorage.setItem('octopus_chat_history', only);
  }

  // Restore messages
  const stored = sessionStorage.getItem('octopus_chat_history');
  if (stored) chatMessages.insertAdjacentHTML('beforeend', stored);
  updateTopicUI();
  if (!selectedTopic) topicPanel.classList.add('visible');

  // Open/close/reset
  toggleButton.addEventListener('click', function(){
    chatbot.style.display = 'flex';
    toggleButton.style.display = 'none';
    if (!sessionStorage.getItem(welcomeSessionKey)) {
      const welcome = settings.welcome_message || (lang === 'FR' ? 'Bonjour ! Comment puis-je taider ?' : 'Hallo! Hoe kan ik je vandaag helpen?');
      setTimeout(function(){ addMessage(welcome, 'bot'); saveChatHistory(); sessionStorage.setItem(welcomeSessionKey, 'true'); }, 200);
    }
  });
  chatClose.addEventListener('click', function(){ chatbot.style.display = 'none'; toggleButton.style.display = 'flex'; });
  chatReset.addEventListener('click', function(){
    if (!confirm(i18n.reset_confirm || 'Weet je zeker dat je het gesprek wilt vernieuwen?')) return;
    sessionStorage.removeItem('octopus_chat_history');
    sessionStorage.removeItem(topicStorageKey);
    sessionStorage.removeItem(welcomeSessionKey);
    chatMessages.innerHTML = '';
    selectedTopic = '';
    updateTopicUI();
    topicPanel.classList.add('visible');
  });

  // Send
  let sendCooldown = false;
  async function sendMessage(){
    if (!selectedTopic) { topicPanel.classList.add('visible'); chatInput.blur(); return; }
    const text = chatInput.value.trim(); if (!text || sendCooldown) return;
    sendCooldown = true; setTimeout(function(){ sendCooldown = false; }, 1200);
    addMessage(text, 'user'); chatInput.value = '';

    const history = Array.from(chatMessages.querySelectorAll('.user-message, .bot-message')).map(function(el){
      return { role: el.classList.contains('user-message') ? 'user' : 'assistant', content: el.innerText.trim() };
    }).filter(function(m){ return m.content.length > 0; }).slice(-12);

    const typing = document.createElement('div'); typing.classList.add('typing-indicator'); typing.innerHTML = '<span class="dot"></span><span class="dot"></span><span class="dot"></span>'; chatMessages.appendChild(typing);
    chatMessages.scrollTo({ top: chatMessages.scrollHeight, behavior: 'smooth' });
    try {
      const resp = await fetch('/wp-json/octopus-ai/v1/chatbot', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: text, history: history, topic: selectedTopic }) });
      const data = await resp.text(); typing.remove(); addMessage(data, 'bot'); saveChatHistory();
    } catch(e) { typing.remove(); addMessage(i18n.api_error || 'Er ging iets mis met het ophalen van het antwoord.', 'bot'); }
  }
  chatSend.addEventListener('click', sendMessage);
  chatInput.addEventListener('keydown', function(e){ if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } });
});
