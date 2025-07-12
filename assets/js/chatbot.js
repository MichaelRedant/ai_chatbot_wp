document.addEventListener('DOMContentLoaded', function () {

    // ✅ Dynamische instellingen ophalen
    fetch(octopus_ai_chatbot_vars.ajaxurl + '?action=octopus_ai_get_settings')
        .then(response => response.json())
        .then(settings => {
            document.documentElement.style.setProperty('--primary-color', settings.primary_color);
            document.querySelector('#chat-header').innerText = settings.brand_name;
        })
        .catch(error => console.error('Instellingen laden mislukt', error));

    // ✅ Toggle button genereren
    const toggleButton = document.createElement('div');
    toggleButton.id = 'octopus-chat-toggle';
    toggleButton.innerHTML = `<img src="${octopus_ai_chatbot_vars.logo_url}" alt="Chatbot">`;
    document.body.appendChild(toggleButton);

    // ✅ Chatbot venster genereren
    const chatbot = document.createElement('div');
    chatbot.id = 'octopus-chatbot';
    chatbot.innerHTML = `
        <div id="chat-header">
            ${octopus_ai_chatbot_vars.brand_name || 'AI Chatbot'}
            <button id="chat-close" aria-label="Sluiten" style="cursor:pointer;font-size:18px;background:none;border:none;color:white;margin-left:auto;">&times;</button>
        </div>
        <div id="chat-messages"></div>
        <div id="chat-input-container">
            <input type="text" id="chat-input" placeholder="Typ je vraag..." />
            <button id="chat-send">Verstuur</button>
        </div>
    `;
    const poweredBy = document.createElement('div');
poweredBy.id = 'octopus-chat-powered-by';
poweredBy.innerHTML = `<small>Powered by <a href="https://www.xinudesign.be" target="_blank" style="color: #999; text-decoration: none;">Xinudesign</a></small>`;
chatbot.appendChild(poweredBy);

    document.body.appendChild(chatbot);

    const chatMessages = document.getElementById('chat-messages');
    const chatInput = document.getElementById('chat-input');
    const chatSend = document.getElementById('chat-send');
    const chatClose = document.getElementById('chat-close');

    // ✅ Messages behouden tijdens sessie
    if (sessionStorage.getItem('octopus_chat_history')) {
        chatMessages.innerHTML = sessionStorage.getItem('octopus_chat_history');
    }

    // ✅ Openen via toggle
    toggleButton.addEventListener('click', () => {
        chatbot.classList.add('fade-in');
        chatbot.classList.remove('fade-out');
        chatbot.style.display = 'flex';
        toggleButton.style.display = 'none';

        if (octopus_ai_chatbot_vars.welcome_message && !sessionStorage.getItem('octopus_chat_welcomed')) {
            setTimeout(() => {
                addMessage(octopus_ai_chatbot_vars.welcome_message, 'bot');
                saveChatHistory();
                sessionStorage.setItem('octopus_chat_welcomed', 'true');
            }, 300);
        }
    });

    // ✅ Sluiten via X
    chatClose.addEventListener('click', closeChatbot);

    // ✅ Klik buiten de chatbot sluit deze (optioneel)
    document.addEventListener('click', function (e) {
        if (chatbot.style.display === 'flex' && !chatbot.contains(e.target) && !toggleButton.contains(e.target)) {
            closeChatbot();
        }
    });

    // ✅ Functie om chatbot te sluiten
    function closeChatbot() {
        chatbot.classList.remove('fade-in');
        chatbot.classList.add('fade-out');
        setTimeout(() => {
            chatbot.style.display = 'none';
            toggleButton.style.display = 'flex';
        }, 300);
    }

    // ✅ Verzenden van berichten
    chatSend.addEventListener('click', sendMessage);
    chatInput.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            sendMessage();
        }
    });

    function addMessage(content, sender = 'user') {
        const message = document.createElement('div');
        message.classList.add(sender === 'user' ? 'user-message' : 'bot-message');
        message.textContent = content;
        chatMessages.appendChild(message);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        saveChatHistory();
    }

    function saveChatHistory() {
        sessionStorage.setItem('octopus_chat_history', chatMessages.innerHTML);
    }

    async function sendMessage() {
        const message = chatInput.value.trim();
        if (!message) return;
        addMessage(message, 'user');
        chatInput.value = '';

        const typing = document.createElement('div');
        typing.classList.add('typing-indicator');
        typing.textContent = 'De chatbot is aan het typen...';
        chatMessages.appendChild(typing);
        chatMessages.scrollTop = chatMessages.scrollHeight;

        try {
            const response = await fetch('/wp-json/octopus-ai/v1/chatbot', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: message })
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
