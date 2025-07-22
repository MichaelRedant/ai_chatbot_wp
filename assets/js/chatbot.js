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
        <button id="chat-reset" class="chat-reset-button" title="Reset gesprek">🔄 Vernieuw</button>

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

        if (settings.welcome_message && !sessionStorage.getItem('octopus_chat_welcomed')) {
            setTimeout(() => {
                addMessage(settings.welcome_message, 'bot', { isWelcome: true });
                saveChatHistory();
                sessionStorage.setItem('octopus_chat_welcomed', 'true');
            }, 300);
        }
    });

    // ✅ Sluiten
    chatClose.addEventListener('click', closeChatbot);
    const chatReset = document.getElementById('chat-reset');
chatReset.addEventListener('click', () => {
    if (confirm('Weet je zeker dat je het gesprek wilt vernieuwen?')) {
        sessionStorage.removeItem('octopus_chat_history');
        sessionStorage.removeItem('octopus_chat_welcomed');
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
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')  // **tekst** → <strong>
    .replace(/\*([^*]+)\*/g, '<em>$1</em>')            // *tekst* → <em>
    .replace(/\[([^\]]+)]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');


    message.innerHTML = html;

    if (sender === 'bot' && !options.isWelcome) {
        const feedback = document.createElement('div');
        feedback.className = 'feedback-buttons';
        feedback.innerHTML = `
            <button class="thumb-up" title="Nuttig">👍</button>
            <button class="thumb-down" title="Niet nuttig">👎</button>
        `;
        message.appendChild(feedback);
    }

    // Fallback-link
    if (sender === 'bot' && content.toLowerCase().includes('daar kan ik je niet mee helpen')) {
        const lastUserMessages = Array.from(document.querySelectorAll('.user-message'));
        const lastQuestion = lastUserMessages.length > 0 ? lastUserMessages.at(-1).innerText : '';
        const keyword = extractKeyword(lastQuestion);

        const fallbackLink = document.createElement('div');
        fallbackLink.className = 'fallback-link';
        fallbackLink.innerHTML = `
            <div style="margin-top:6px; font-size:12px; color:#666;">
                ℹ️ Misschien vind je het antwoord wel in onze handleiding:<br>
                <a href="https://login.octopus.be/manual/NL/hmftsearch.htm?zoom_query=${encodeURIComponent(keyword)}" target="_blank" style="color: var(--primary-color); text-decoration: underline;">
                    Bekijk dit in de handleiding
                </a>
            </div>
        `;
        message.appendChild(fallbackLink);
    }

    chatMessages.appendChild(message);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}


    // ✅ Historiek opslaan
    function saveChatHistory() {
        sessionStorage.setItem('octopus_chat_history', chatMessages.innerHTML);
    }
    

    // ✅ Versturen
    async function sendMessage() {
    const message = chatInput.value.trim();
    if (!message) return;
    addMessage(message, 'user');
    chatInput.value = '';

    const typing = document.createElement('div');
    typing.classList.add('typing-indicator');
    typing.innerHTML = '<span class="dot"></span><span class="dot"></span><span class="dot"></span>';
    chatMessages.appendChild(typing);
    chatMessages.scrollTop = chatMessages.scrollHeight;

    // 🔁 Haal alle eerdere user + bot berichten op
const fullHistory = Array.from(chatMessages.querySelectorAll('.user-message, .bot-message'))
    .map(el => ({
        role: el.classList.contains('user-message') ? 'user' : 'assistant',
        content: el.innerText.trim()
    }))
    .filter(m => m.content.length > 0);

// ❗ Beperk tot laatste 12 entries (6 uitwisselingen)
const limitedHistory = fullHistory.slice(-12);

// Voeg huidige vraag toe
limitedHistory.push({ role: 'user', content: message });


    try {
        const response = await fetch('/wp-json/octopus-ai/v1/chatbot', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ message, history: limitedHistory })
});


        const data = await response.text();
        typing.remove();

        // Detecteer fallback
        const isFallback = data.trim().startsWith('Sorry, daar kan ik je niet mee helpen.');

        // Check of er al een geldige handleidinglink in het antwoord zit
        const bevatSlugLink = data.includes('login.octopus.be/manual/NL/') && (data.includes('page_slug') || data.includes('.html'));

        if (isFallback && !bevatSlugLink) {
            const zoekterm = extractKeyword(message);
            const fallbackLink = `https://login.octopus.be/manual/NL/hmftsearch.htm?zoom_query=${encodeURIComponent(zoekterm)}`;
            const enhanced = `${data}<br><a href="${fallbackLink}" target="_blank" rel="noopener noreferrer">Bekijk dit in de handleiding</a>`;
            addMessage(enhanced, 'bot');
        } else {
            addMessage(data, 'bot');
            saveChatHistory(); 
        }

    } catch (error) {
        typing.remove();
        addMessage('Er ging iets mis. Probeer later opnieuw.', 'bot');
    }
}

function extractKeyword(question) {
    const blacklist = ['hoe', 'kan', 'ik', 'de', 'het', 'een', 'wat', 'waar', 'wanneer', 'is', 'zijn', 'mijn', 'je', 'jouw', 'op', 'te'];
    const words = question.toLowerCase().match(/\w+/g) || [];
    const keywords = words.filter(word => word.length > 3 && !blacklist.includes(word));
    return keywords[0] || 'octopus';
}



});
