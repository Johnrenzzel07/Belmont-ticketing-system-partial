<?php
/**
 * Floating AI Assistant widget ("Belmont Assist")
 * Included by footer.php for all logged-in users.
 * Talks to api/ai.php?action=assistant_chat (Groq LLM + KB + user's tickets).
 */
?>
<style>
/* ===== Floating AI Assistant ===== */
#aiFab {
    position: fixed;
    bottom: 26px;
    right: 26px;
    width: 58px;
    height: 58px;
    border-radius: 50%;
    border: none;
    background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
    color: #fff;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 8px 24px rgba(37, 99, 235, .35);
    z-index: 1080;
    transition: transform .2s ease, box-shadow .2s ease;
}
#aiFab:hover { transform: scale(1.08); box-shadow: 0 12px 32px rgba(124, 58, 237, .45); }
#aiFab::after {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    border: 2px solid rgba(124, 58, 237, .45);
    animation: aiFabPulse 2.4s ease-out infinite;
    pointer-events: none;
}
@keyframes aiFabPulse {
    0%   { transform: scale(.9); opacity: .8; }
    70%  { transform: scale(1.25); opacity: 0; }
    100% { transform: scale(1.25); opacity: 0; }
}
#aiFab .bi-x-lg { display: none; }
#aiFab.open .bi-stars { display: none; }
#aiFab.open .bi-x-lg  { display: inline; }
#aiFab.open::after    { animation: none; opacity: 0; }

#aiChatPanel {
    position: fixed;
    bottom: 96px;
    right: 26px;
    width: 372px;
    max-width: calc(100vw - 32px);
    height: 520px;
    max-height: calc(100vh - 130px);
    background: var(--card-bg, #fff);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 18px;
    box-shadow: 0 24px 64px rgba(15, 23, 42, .25);
    z-index: 1079;
    display: none;
    flex-direction: column;
    overflow: hidden;
}
#aiChatPanel.open { display: flex; animation: aiPanelIn .22s ease; }
@keyframes aiPanelIn {
    from { opacity: 0; transform: translateY(14px) scale(.97); }
    to   { opacity: 1; transform: none; }
}

.ai-chat-header {
    display: flex;
    align-items: center;
    gap: .65rem;
    padding: .85rem 1rem;
    background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
    color: #fff;
}
.ai-chat-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, .2);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.ai-chat-title { font-weight: 700; font-size: .9rem; line-height: 1.1; }
.ai-chat-sub   { font-size: .68rem; opacity: .85; display: flex; align-items: center; gap: .3rem; }
.ai-online-dot { width: 7px; height: 7px; border-radius: 50%; background: #4ade80; display: inline-block; }
.ai-chat-clear {
    margin-left: auto;
    background: rgba(255,255,255,.15);
    border: none; color: #fff;
    border-radius: 8px;
    font-size: .68rem;
    padding: .25rem .55rem;
    cursor: pointer;
}
.ai-chat-clear:hover { background: rgba(255,255,255,.28); }

.ai-chat-body {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: .6rem;
    background: var(--body-bg, #f8fafc);
}
.ai-msg {
    max-width: 86%;
    padding: .6rem .8rem;
    border-radius: 14px;
    font-size: .82rem;
    line-height: 1.45;
    white-space: pre-wrap;
    word-wrap: break-word;
}
.ai-msg.user {
    align-self: flex-end;
    background: linear-gradient(135deg, #2563eb, #4f46e5);
    color: #fff;
    border-bottom-right-radius: 4px;
}
.ai-msg.bot {
    align-self: flex-start;
    background: var(--card-bg, #fff);
    border: 1px solid var(--border, #e5e7eb);
    color: var(--text-primary, #111827);
    border-bottom-left-radius: 4px;
}
.ai-msg .ai-sources {
    margin-top: .5rem;
    padding-top: .45rem;
    border-top: 1px dashed var(--border, #e5e7eb);
    font-size: .7rem;
}
.ai-msg .ai-sources a {
    display: inline-block;
    margin: .15rem .25rem 0 0;
    padding: .15rem .5rem;
    border-radius: 20px;
    background: rgba(37, 99, 235, .08);
    color: #2563eb;
    text-decoration: none;
}
.ai-msg .ai-sources a:hover { background: rgba(37, 99, 235, .16); }

/* Suggested ticket draft card */
.ai-ticket-card {
    margin-top: .6rem;
    border: 1px solid rgba(37, 99, 235, .25);
    border-radius: 12px;
    overflow: hidden;
    background: rgba(37, 99, 235, .04);
}
.ai-ticket-head {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .45rem .65rem;
    background: rgba(37, 99, 235, .09);
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #2563eb;
}
.ai-ticket-body { padding: .55rem .65rem; }
.ai-ticket-subject { font-weight: 700; font-size: .8rem; margin-bottom: .35rem; }
.ai-ticket-tags { display: flex; flex-wrap: wrap; gap: .3rem; margin-bottom: .45rem; }
.ai-ticket-tag {
    font-size: .65rem;
    font-weight: 600;
    padding: .12rem .5rem;
    border-radius: 20px;
    background: #eef2ff;
    color: #4338ca;
    white-space: nowrap;
}
.ai-ticket-tag.prio-critical { background: #fee2e2; color: #b91c1c; }
.ai-ticket-tag.prio-high     { background: #ffedd5; color: #c2410c; }
.ai-ticket-tag.prio-medium   { background: #fef9c3; color: #a16207; }
.ai-ticket-tag.prio-low      { background: #dcfce7; color: #15803d; }
.ai-ticket-desc {
    font-size: .74rem;
    line-height: 1.55;
    color: var(--text-primary, #334155);
    max-height: 150px;
    overflow-y: auto;
    margin-bottom: .55rem;
    white-space: pre-wrap;
    text-align: justify;
    padding-right: .25rem;
}
.ai-ticket-use {
    width: 100%;
    border: none;
    border-radius: 8px;
    background: linear-gradient(135deg, #2563eb, #4f46e5);
    color: #fff;
    font-size: .76rem;
    font-weight: 600;
    padding: .45rem;
    cursor: pointer;
    transition: opacity .15s;
}
.ai-ticket-use:hover { opacity: .9; }

/* Suggested thread reply draft */
.ai-reply-card {
    margin-top: .6rem;
    border: 1px solid rgba(16, 185, 129, .3);
    border-radius: 12px;
    overflow: hidden;
    background: rgba(16, 185, 129, .04);
}
.ai-reply-head {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .45rem .65rem;
    background: rgba(16, 185, 129, .1);
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #059669;
}
.ai-reply-body { padding: .55rem .65rem; }
.ai-reply-text {
    font-size: .73rem;
    line-height: 1.55;
    color: var(--text-primary, #334155);
    max-height: 200px;
    overflow-y: auto;
    white-space: pre-wrap;
    margin-bottom: .5rem;
    padding: .5rem .55rem;
    background: var(--card-bg, #fff);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px;
}
.ai-reply-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
.ai-reply-copy, .ai-reply-paste {
    flex: 1;
    min-width: 120px;
    border: none;
    border-radius: 8px;
    font-size: .74rem;
    font-weight: 600;
    padding: .45rem .5rem;
    cursor: pointer;
}
.ai-reply-copy {
    background: #ecfdf5;
    color: #059669;
    border: 1px solid rgba(16, 185, 129, .35);
}
.ai-reply-paste {
    background: linear-gradient(135deg, #059669, #10b981);
    color: #fff;
}
.ai-reply-copy:hover { background: #d1fae5; }
.ai-reply-paste:hover { opacity: .92; }

.ai-typing { display: flex; gap: 4px; padding: .7rem .9rem; align-self: flex-start; }
.ai-typing span {
    width: 7px; height: 7px; border-radius: 50%;
    background: #94a3b8;
    animation: aiBounce 1.2s infinite;
}
.ai-typing span:nth-child(2) { animation-delay: .15s; }
.ai-typing span:nth-child(3) { animation-delay: .3s; }
@keyframes aiBounce {
    0%, 60%, 100% { transform: translateY(0); opacity: .5; }
    30%           { transform: translateY(-5px); opacity: 1; }
}

.ai-chips { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .25rem; }
.ai-chip {
    border: 1px solid var(--border, #d8dee9);
    background: var(--card-bg, #fff);
    color: var(--text-primary, #334155);
    border-radius: 20px;
    font-size: .72rem;
    padding: .3rem .7rem;
    cursor: pointer;
    transition: all .15s;
}
.ai-chip:hover { border-color: #2563eb; color: #2563eb; background: rgba(37,99,235,.05); }

.ai-chat-footer {
    display: flex;
    gap: .5rem;
    padding: .7rem .8rem;
    border-top: 1px solid var(--border, #e5e7eb);
    background: var(--card-bg, #fff);
}
#aiChatInput {
    flex: 1;
    border: 1px solid var(--border, #d8dee9);
    border-radius: 22px;
    padding: .5rem .9rem;
    font-size: .82rem;
    outline: none;
    background: var(--body-bg, #f8fafc);
    color: var(--text-primary, #111827);
}
#aiChatInput:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
#aiChatSend {
    width: 38px; height: 38px;
    border-radius: 50%;
    border: none;
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: transform .15s;
}
#aiChatSend:hover:not(:disabled) { transform: scale(1.08); }
#aiChatSend:disabled { opacity: .5; cursor: not-allowed; }

@media (max-width: 576px) {
    #aiChatPanel { right: 16px; bottom: 88px; height: 70vh; }
    #aiFab { right: 16px; bottom: 18px; }
}
</style>

<!-- Floating button -->
<button id="aiFab" type="button" title="Ask Belmont Assist" aria-label="Open AI assistant">
    <i class="bi bi-stars"></i>
    <i class="bi bi-x-lg"></i>
</button>

<!-- Chat panel -->
<div id="aiChatPanel" role="dialog" aria-label="AI assistant chat">
    <div class="ai-chat-header">
        <div class="ai-chat-avatar"><i class="bi bi-robot"></i></div>
        <div>
            <div class="ai-chat-title">Belmont Assist</div>
            <div class="ai-chat-sub"><span class="ai-online-dot"></span> AI helpdesk assistant</div>
        </div>
        <button class="ai-chat-clear" id="aiChatClear" type="button" title="Start a new conversation">
            <i class="bi bi-arrow-counterclockwise"></i> New chat
        </button>
    </div>
    <div class="ai-chat-body" id="aiChatBody"></div>
    <div class="ai-chat-footer">
        <input type="text" id="aiChatInput" placeholder="Ask anything about the helpdesk..."
               maxlength="1000" autocomplete="off">
        <button id="aiChatSend" type="button" aria-label="Send">
            <i class="bi bi-send-fill" style="font-size:.85rem"></i>
        </button>
    </div>
</div>

<script>
(function () {
    const fab    = document.getElementById('aiFab');
    const panel  = document.getElementById('aiChatPanel');
    const body   = document.getElementById('aiChatBody');
    const input  = document.getElementById('aiChatInput');
    const sendBtn = document.getElementById('aiChatSend');
    const clearBtn = document.getElementById('aiChatClear');

    const STORAGE_KEY = 'belmontAiChat';
    let history = [];
    let busy = false;

    try { history = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '[]'); } catch (e) { history = []; }

    const GREETING = 'Hi! I\'m Belmont Assist — your helpdesk guide. I can help you create tickets, '
        + 'check ticket status, reply to staff in a conversation thread, or answer questions from the Knowledge Base. '
        + 'What do you need?';

    const CHIPS = [
        'How do I create a ticket?',
        'How do I reply to staff on my ticket?',
        'What is the status of my tickets?',
        'How do SLA priorities work?'
    ];

    /** Ticket id when user is on views/tickets/view.php (for thread-aware reply help) */
    function currentTicketId() {
        if (typeof window.AI_TICKET_ID !== 'undefined' && window.AI_TICKET_ID) {
            return String(window.AI_TICKET_ID);
        }
        const m = window.location.pathname.match(/\/views\/tickets\/view\.php$/i);
        if (!m) return '';
        return new URLSearchParams(window.location.search).get('id') || '';
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function scrollDown() { body.scrollTop = body.scrollHeight; }

    function addMsg(role, text, sources, ticket, replyDraft) {
        const div = document.createElement('div');
        div.className = 'ai-msg ' + (role === 'user' ? 'user' : 'bot');
        let html = esc(text);
        if (sources && sources.length) {
            html += '<div class="ai-sources">Sources: ';
            sources.forEach(s => {
                html += `<a href="${APP_URL}/views/kb/article.php?slug=${encodeURIComponent(s.slug)}" target="_blank">
                            <i class="bi bi-book"></i> ${esc(s.title)}</a>`;
            });
            html += '</div>';
        }
        if (replyDraft) {
            html += `<div class="ai-reply-card">
                <div class="ai-reply-head"><i class="bi bi-chat-left-text"></i> Suggested Reply</div>
                <div class="ai-reply-body">
                    <div class="ai-reply-text">${esc(replyDraft)}</div>
                    <div class="ai-reply-actions">
                        <button type="button" class="ai-reply-copy"><i class="bi bi-clipboard me-1"></i>Copy reply</button>
                        <button type="button" class="ai-reply-paste"><i class="bi bi-reply me-1"></i>Paste into reply</button>
                    </div>
                </div>
            </div>`;
        }
        if (ticket && ticket.subject) {
            const tags = [];
            if (ticket.department_name) tags.push(`<span class="ai-ticket-tag"><i class="bi bi-building"></i> ${esc(ticket.department_name)}</span>`);
            if (ticket.category_name)   tags.push(`<span class="ai-ticket-tag"><i class="bi bi-tag"></i> ${esc(ticket.category_name)}</span>`);
            if (ticket.priority)        tags.push(`<span class="ai-ticket-tag prio-${esc(ticket.priority)}"><i class="bi bi-flag"></i> ${esc(ticket.priority)}</span>`);
            if (ticket.due_date)        tags.push(`<span class="ai-ticket-tag"><i class="bi bi-calendar-event"></i> Due ${esc(ticket.due_date)}</span>`);
            html += `<div class="ai-ticket-card">
                <div class="ai-ticket-head"><i class="bi bi-ticket-detailed"></i> Suggested Ticket Draft</div>
                <div class="ai-ticket-body">
                    <div class="ai-ticket-subject">${esc(ticket.subject)}</div>
                    <div class="ai-ticket-tags">${tags.join('')}</div>
                    <div class="ai-ticket-desc">${esc(ticket.description || '')}</div>
                    <button type="button" class="ai-ticket-use">
                        <i class="bi bi-pencil-square me-1"></i>Use this draft — open ticket form
                    </button>
                </div>
            </div>`;
        }
        div.innerHTML = html;
        if (replyDraft) {
            const copyBtn = div.querySelector('.ai-reply-copy');
            const pasteBtn = div.querySelector('.ai-reply-paste');
            if (copyBtn) copyBtn.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                navigator.clipboard.writeText(replyDraft).then(() => {
                    copyBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied!';
                    setTimeout(() => { copyBtn.innerHTML = '<i class="bi bi-clipboard me-1"></i>Copy reply'; }, 2000);
                }).catch(() => {});
            });
            if (pasteBtn) pasteBtn.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                const msgField = document.getElementById('replyMessage');
                if (msgField) {
                    msgField.value = replyDraft;
                    msgField.focus();
                    msgField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    pasteBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Pasted!';
                    setTimeout(() => { pasteBtn.innerHTML = '<i class="bi bi-reply me-1"></i>Paste into reply'; }, 2000);
                } else {
                    navigator.clipboard.writeText(replyDraft).then(() => {
                        pasteBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied — open ticket to paste';
                        setTimeout(() => { pasteBtn.innerHTML = '<i class="bi bi-reply me-1"></i>Paste into reply'; }, 2500);
                    }).catch(() => {});
                }
            });
        }
        if (ticket && ticket.subject) {
            const useBtn = div.querySelector('.ai-ticket-use');
            if (useBtn) useBtn.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                try { sessionStorage.setItem('aiTicketDraft', JSON.stringify(ticket)); } catch (err) {}
                window.location.href = APP_URL + '/views/tickets/create.php';
            });
        }
        body.appendChild(div);
        scrollDown();
    }

    function addChips() {
        const wrap = document.createElement('div');
        wrap.className = 'ai-chips';
        CHIPS.forEach(c => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'ai-chip';
            b.textContent = c;
            b.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                input.value = c;
                send();
            });
            wrap.appendChild(b);
        });
        body.appendChild(wrap);
        scrollDown();
    }

    function showTyping() {
        const t = document.createElement('div');
        t.className = 'ai-typing';
        t.id = 'aiTyping';
        t.innerHTML = '<span></span><span></span><span></span>';
        body.appendChild(t);
        scrollDown();
    }
    function hideTyping() {
        const t = document.getElementById('aiTyping');
        if (t) t.remove();
    }

    function renderHistory() {
        body.innerHTML = '';
        addMsg('bot', GREETING);
        if (history.length === 0) {
            addChips();
        } else {
            history.forEach(m => addMsg(m.role, m.content, m.sources, m.ticket, m.reply_draft));
        }
    }

    function saveHistory() {
        // keep the last 20 turns so sessionStorage stays small
        try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history.slice(-20))); } catch (e) {}
    }

    function send() {
        const msg = input.value.trim();
        if (!msg || busy) return;
        busy = true;
        sendBtn.disabled = true;
        input.value = '';

        // remove suggestion chips once the conversation starts
        const chips = body.querySelector('.ai-chips');
        if (chips) chips.remove();

        addMsg('user', msg);
        // Save the question right away so the conversation survives
        // page navigation or a slow/failed AI response
        history.push({role: 'user', content: msg});
        saveHistory();
        showTyping();

        const fd = new FormData();
        fd.append('action', 'assistant_chat');
        // history already contains the question we just pushed — send the turns before it
        fd.append('history', JSON.stringify(history.slice(0, -1).map(m => ({role: m.role, content: m.content}))));
        fd.append('message', msg);
        fd.append('_csrf', CSRF_TOKEN);
        const tid = currentTicketId();
        if (tid) fd.append('ticket_id', tid);

        fetch(APP_URL + '/api/ai.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(r => {
                hideTyping();
                let reply;
                if (r.success) {
                    reply = {
                        role: 'assistant',
                        content: r.answer || 'Sorry, I could not generate an answer.',
                        sources: r.sources || [],
                        ticket: r.ticket || null,
                        reply_draft: r.reply_draft || null
                    };
                } else {
                    reply = {role: 'assistant', content: r.message || 'Something went wrong. Please try again.'};
                }
                addMsg('bot', reply.content, reply.sources, reply.ticket, reply.reply_draft);
                history.push(reply);
                saveHistory();
            })
            .catch(() => {
                hideTyping();
                const reply = {role: 'assistant', content: 'I could not reach the server. Please try again.'};
                addMsg('bot', reply.content);
                history.push(reply);
                saveHistory();
            })
            .finally(() => {
                busy = false;
                sendBtn.disabled = false;
                input.focus();
            });
    }

    fab.addEventListener('click', () => {
        const open = panel.classList.toggle('open');
        fab.classList.toggle('open', open);
        if (open) {
            renderHistory();
            setTimeout(() => input.focus(), 150);
        }
    });

    clearBtn.addEventListener('click', () => {
        history = [];
        saveHistory();
        renderHistory();
        input.focus();
    });

    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && panel.classList.contains('open')) {
            panel.classList.remove('open');
            fab.classList.remove('open');
        }
    });
})();
</script>
