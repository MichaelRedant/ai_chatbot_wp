document.addEventListener('DOMContentLoaded', function () {
    // ✅ Toggle knop
    const toggleButton = document.createElement('div');
    toggleButton.id = 'octopus-chat-toggle';
    toggleButton.innerHTML = `<img src="${octopus_ai_chatbot_vars.logo_url}" alt="Chatbot">`;
    document.body.appendChild(toggleButton);

    // ✅ Chatvenster
    const chatbot = document.createElement('div');
    chatbot.id = 'octopus-chatbot';
    document.body.appendChild(chatbot);

    const settings = octopus_ai_chatbot_vars;
const fallbackTrigger = settings.i18n.fallback_trigger || "Sorry, daar kan ik je niet mee helpen.";

    let lang = octopus_ai_chatbot_vars.lang;
if (!lang || lang === '') {
    const browserLang = navigator.language || navigator.userLanguage || 'nl';
    lang = browserLang.toLowerCase().startsWith('fr') ? 'FR' : 'NL';
}


    let sendCooldown = false;

    // ✅ CSS-variabelen instellen
    document.documentElement.style.setProperty('--primary-color', settings.primary_color);
    document.documentElement.style.setProperty('--header-text-color', settings.header_text_color || '#ffffff');

    // ✅ HTML injecteren
    chatbot.innerHTML = `
        <div id="chat-header" style="background-color:${settings.primary_color};">
            <div class="chat-header-inner">
                <img src="${settings.logo_url}" alt="Logo" class="chat-logo" style="height: 24px; max-width: 28px; margin-right: 10px;">
                <span class="chat-header-title">${settings.brand_name || 'AI Chatbot'}</span>
            </div>
            <button id="chat-close" aria-label="Sluiten" class="chat-close-button">&times;</button>
        </div>
        <div id="chat-messages"></div>
        <button id="chat-reset" class="chat-reset-button" title="${settings.i18n.reset_title || 'Reset'}">🔄 ${settings.i18n.reset_button || 'Vernieuw'}</button>


        <div id="chat-input-container">
            <input type="text" id="chat-input" placeholder="Typ je vraag..." />
            <button id="chat-send">Verstuur</button>
        </div>
    `;

    // ✅ DOM-elementen ophalen
    const headerTitle  = chatbot.querySelector('.chat-header-title');
    const closeButton  = chatbot.querySelector('.chat-close-button');
    const headerBar    = chatbot.querySelector('#chat-header');
    const chatMessages = document.getElementById('chat-messages');
    const chatInput    = document.getElementById('chat-input');
    const chatSend     = document.getElementById('chat-send');
    const chatClose    = document.getElementById('chat-close');

    // ✅ Styling forceren
    if (headerTitle)  headerTitle.style.setProperty('color', settings.header_text_color || '#ffffff', 'important');
    if (closeButton)  closeButton.style.setProperty('color', settings.header_text_color || '#ffffff', 'important');
    if (headerBar)    headerBar.style.setProperty('color', settings.header_text_color || '#ffffff', 'important');

    // ✅ Historiek herstellen
    if (sessionStorage.getItem('octopus_chat_history')) {
        chatMessages.innerHTML = sessionStorage.getItem('octopus_chat_history');
    }

    // ✅ Openen
   toggleButton.addEventListener('click', () => {
    chatbot.classList.remove('fade-out');
    chatbot.classList.add('fade-in');
    chatbot.style.display = 'flex';
    toggleButton.style.display = 'none';

    const sessionKey = `octopus_chat_welcomed_${lang}`;
    if (!sessionStorage.getItem(sessionKey)) {
        let welcome = settings.welcome_message;
        if (!welcome || welcome.trim() === '') {
            welcome = (lang === 'FR')
                ? "👋 Bonjour ! Comment puis-je t’aider aujourd’hui ?"
                : "👋 Hallo! Hoe kan ik je vandaag helpen?";
        }

        setTimeout(() => {
            addMessage(welcome, 'bot', { isWelcome: true });
            saveChatHistory();
            sessionStorage.setItem(sessionKey, 'true');
        }, 300);
    }
});

    // ✅ Sluiten
    chatClose.addEventListener('click', closeChatbot);
    const chatReset = document.getElementById('chat-reset');
    chatReset.innerHTML = `🔄 ${settings.i18n.reset_button || 'Vernieuw'}`;
    chatInput.placeholder = settings.i18n.placeholder;
chatSend.textContent  = settings.i18n.send;
chatReset.title       = settings.i18n.reset_title;


chatReset.addEventListener('click', () => {
    if (confirm(settings.i18n.reset_confirm)) {
        sessionStorage.removeItem('octopus_chat_history');
        sessionStorage.removeItem(sessionKey);
        chatMessages.innerHTML = '';
        if (settings.welcome_message) {
            addMessage(settings.welcome_message, 'bot', { isWelcome: true });
            saveChatHistory();
            sessionStorage.setItem('octopus_chat_welcomed', 'true');
        }
    }
});

    document.addEventListener('click', function (e) {
        if (chatbot.style.display === 'flex' && !chatbot.contains(e.target) && !toggleButton.contains(e.target)) {
            closeChatbot();
        }
    });

    function closeChatbot() {
        chatbot.classList.remove('fade-in');
        chatbot.classList.add('fade-out');
        setTimeout(() => {
            chatbot.style.display = 'none';
            toggleButton.style.display = 'flex';
        }, 300);
    }

    // ✅ Input events
    chatSend.addEventListener('click', sendMessage);
    chatInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // ✅ Bericht tonen
   function addMessage(content, sender = 'user', options = {}) {
    const message = document.createElement('div');
    message.classList.add(sender === 'user' ? 'user-message' : 'bot-message');

    const html = content
    .replace(/\\n/g, '<br>')
    .replace(/\\(.)/g, '$1')
    .replace(/(?<!\*)\*\*(.+?)\*\*(?!\*)/g, '<strong>$1</strong>')
.replace(/(?<!\*)\*(?!\*)([^*]+)(?<!\*)\*(?!\*)/g, '<em>$1</em>')
    .replace(/\[([^\]]+)]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');



    message.innerHTML = html;

    if (sender === 'bot' && !options.isWelcome) {
    const feedback = document.createElement('div');
    feedback.className = 'feedback-buttons';
    feedback.innerHTML = `
    <button class="thumb-up" title="${settings.i18n.feedback_up}">👍</button>
    <button class="thumb-down" title="${settings.i18n.feedback_down}">👎</button>
`;

    message.appendChild(feedback);

    // ✅ Eventlisteners
    feedback.querySelector('.thumb-up').addEventListener('click', () => sendFeedback('up', message.innerText));
    feedback.querySelector('.thumb-down').addEventListener('click', () => sendFeedback('down', message.innerText));
}



    // Fallback-link
    if (sender === 'bot' && fallbackTrigger && content.trim().toLowerCase().startsWith(fallbackTrigger.toLowerCase())) {
        const lastUserMessages = Array.from(document.querySelectorAll('.user-message'));
        const lastQuestion = lastUserMessages.length > 0 ? lastUserMessages.at(-1).innerText : '';
        const keyword = extractKeyword(lastQuestion);

        const fallbackLink = document.createElement('div');
        fallbackLink.className = 'fallback-link';
        fallbackLink.innerHTML = `
<div style="margin-top:6px; font-size:12px; color:#666;">
    ${settings.i18n.fallback_prefix}<br>
    <a href="https://login.octopus.be/manual/${lang}/hmftsearch.htm?zoom_query=${encodeURIComponent(keyword)}" target="_blank" style="color: var(--primary-color); text-decoration: underline;">
        ${settings.i18n.fallback_button}
    </a>
</div>
`;
        message.appendChild(fallbackLink);
    }

    chatMessages.appendChild(message);
chatMessages.scrollTo({
    top: chatMessages.scrollHeight,
    behavior: 'smooth'
});
}


    // ✅ Historiek opslaan
    function saveChatHistory() {
        sessionStorage.setItem('octopus_chat_history', chatMessages.innerHTML);
    }
    

    // ✅ Versturen
   async function sendMessage() {
    const message = chatInput.value.trim();
    if (!message || sendCooldown) return;

    sendCooldown = true;
    setTimeout(() => sendCooldown = false, 1500);

    addMessage(message, 'user');
    chatInput.value = '';

    const typing = document.createElement('div');
    typing.classList.add('typing-indicator');
    typing.innerHTML = '<span class="dot"></span><span class="dot"></span><span class="dot"></span>';
    chatMessages.appendChild(typing);
    chatMessages.scrollTo({ top: chatMessages.scrollHeight, behavior: 'smooth' });

    const fullHistory = Array.from(chatMessages.querySelectorAll('.user-message, .bot-message'))
        .map(el => ({
            role: el.classList.contains('user-message') ? 'user' : 'assistant',
            content: el.innerText.trim()
        }))
        .filter(m => m.content.length > 0)
        .slice(-12); // Laatste 6 uitwisselingen

    try {
        const response = await fetch('/wp-json/octopus-ai/v1/chatbot', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message, history: fullHistory })
        });

        const data = await response.text();
        typing.remove();

        const bevatLink = data.includes(`https://login.octopus.be/manual/${lang}/`);


const isFallback = data.trim().toLowerCase().startsWith(fallbackTrigger.toLowerCase());

        if (isFallback && !bevatLink) {
            const zoekterm = extractKeyword(message);
            const fallbackLink = `https://login.octopus.be/manual/${lang}/hmftsearch.htm?zoom_query=${encodeURIComponent(zoekterm)}`;

            addMessage(`${data}<br><a href="${fallbackLink}" target="_blank" rel="noopener noreferrer">${settings.i18n.fallback_button}</a>`, 'bot');

        } else {
            addMessage(data, 'bot');
        }

        saveChatHistory();

    } catch (error) {
        typing.remove();
        console.error('API-fout:', error);
        addMessage(settings.i18n.api_error, 'bot');

    }


}

function extractKeyword(question) {
    const blacklist = ['hoe', 'kan', 'ik', 'de', 'het', 'een', 'wat', 'waar', 'wanneer', 'is', 'zijn', 'mijn', 'je', 'jouw', 'op', 'te'];
    const words = question.toLowerCase().match(/\w+/g) || [];
    const keywords = words.filter(word => word.length > 3 && !blacklist.includes(word));
    return keywords[0] || 'octopus';
}

function sendFeedback(type, answer) {
    fetch('/wp-json/octopus-ai/v1/feedback', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ feedback: type, answer })
    }).then(() => {
        alert(type === 'up' ? settings.i18n.feedback_up : settings.i18n.feedback_down);


    });
}

// ⏳ Automatisch afsluiten na inactiviteit
let inactivityTimer;

function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(() => {
        if (chatbot.style.display === 'flex') {
            closeChatbot();
        }
    }, 5 * 60 * 1000); // 5 minuten
}

// Reset timer bij interactie
['click', 'keydown', 'mousemove'].forEach(event => {
    chatbot.addEventListener(event, resetInactivityTimer);
});
resetInactivityTimer();

});
