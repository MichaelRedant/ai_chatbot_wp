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

        chatMessages.appendChild(message);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        saveChatHistory();
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

        try {
            const response = await fetch('/wp-json/octopus-ai/v1/chatbot', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message })
            });
            const data = await response.text();
            typing.remove();
            addMessage(data, 'bot');
        } catch (error) {
            typing.remove();
            addMessage('Er ging iets mis. Probeer later opnieuw.', 'bot');
        }
    }
});
