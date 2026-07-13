'use strict';

var tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
var INIT_DATA = tg ? tg.initData : '';

var state = {
    view: 'list',
    tab: 'new',
    ticketId: null,
    myId: null,
    ticketStatus: null,
    ticketAssigned: false,
    lastMessageId: 0,
    pollTimer: null,
};
var pendingFiles = [];

// ---------------- helpers ----------------

function bytesToLabel(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' МБ';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' КБ';
    return bytes + ' Б';
}

function fmtDateTime(mysqlDt) {
    var d = new Date(mysqlDt.replace(' ', 'T'));
    return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function toast(text) {
    var el = document.getElementById('toast');
    el.textContent = text;
    el.classList.add('show');
    setTimeout(function () { el.classList.remove('show'); }, 2600);
}

function apiFetch(url, opts) {
    opts = opts || {};
    opts.headers = opts.headers || {};
    opts.headers['X-Init-Data'] = INIT_DATA;
    return fetch(url, opts).then(function (r) { return r.json(); });
}

function openLightbox(src) {
    var lb = document.getElementById('lightbox');
    document.getElementById('lightbox-img').src = src;
    lb.classList.add('show');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('show');
}

// ---------------- view switching ----------------

function setTitle(text) { document.getElementById('topbar-title').textContent = text; }

function showView(name) {
    state.view = name;
    document.getElementById('view-list').style.display = name === 'list' ? 'flex' : 'none';
    document.getElementById('view-ticket').style.display = name === 'ticket' ? 'flex' : 'none';
    document.getElementById('view-report').style.display = name === 'report' ? 'block' : 'none';
    document.getElementById('tabbar').style.display = name === 'list' ? 'flex' : 'none';
    document.getElementById('back-btn').style.display = name === 'list' ? 'none' : 'inline-block';
    if (state.pollTimer && name !== 'ticket') {
        clearInterval(state.pollTimer);
        state.pollTimer = null;
    }
}

var TAB_TITLES = { new: 'Новые заявки', my: 'Мои заявки', closed: 'Закрытые заявки' };

function switchTab(tab) {
    state.tab = tab;
    document.querySelectorAll('.tab').forEach(function (el) {
        el.classList.toggle('active', el.getAttribute('data-tab') === tab);
    });
    setTitle(TAB_TITLES[tab]);
    loadList(tab);
}

// ---------------- list ----------------

function assigneeBadge(t) {
    if (!t.assignee_name) return '';
    var cls = t.assignee_color === 'purple' ? 'badge-purple' : (t.assignee_color === 'blue' ? 'badge-blue' : 'badge-neutral');
    return '<span class="badge ' + cls + '">' + escapeHtml(t.assignee_name) + '</span>';
}

function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}

function loadList(tab) {
    var listEl = document.getElementById('ticket-list');
    var emptyEl = document.getElementById('list-empty');
    var loaderEl = document.getElementById('list-loader');
    listEl.innerHTML = '';
    emptyEl.style.display = 'none';
    loaderEl.style.display = 'block';

    apiFetch('api/tickets.php?tab=' + encodeURIComponent(tab)).then(function (data) {
        loaderEl.style.display = 'none';
        if (!data.ok) { toast(data.error || 'Ошибка загрузки'); return; }
        if (!data.tickets.length) {
            document.getElementById('list-empty-text').textContent = tab === 'closed' ? 'Закрытых заявок пока нет' : (tab === 'my' ? 'У вас нет закреплённых заявок' : 'Новых заявок нет');
            emptyEl.style.display = 'block';
            return;
        }
        data.tickets.forEach(function (t) {
            var card = document.createElement('div');
            card.className = 'ticket-card';
            card.innerHTML =
                '<div class="ticket-card-top">' +
                  '<span class="ticket-card-id">№' + t.id + '</span>' +
                  (t.status === 'closed' ? '<span class="badge badge-closed">✔ Закрыта</span>' : '<span class="badge badge-open">● Открыта</span>') +
                '</div>' +
                '<div class="ticket-card-subject">' + escapeHtml(t.subject) + '</div>' +
                '<div class="ticket-card-meta"><span>' + escapeHtml(t.author_name) + ' · ' + fmtDateTime(t.updated_at) + '</span>' + assigneeBadge(t) + '</div>';
            card.addEventListener('click', function () { openTicket(t.id); });
            listEl.appendChild(card);
        });
    }).catch(function () { loaderEl.style.display = 'none'; toast('Ошибка сети'); });
}

// ---------------- ticket detail ----------------

function buildMessageRow(m) {
    var out = m.sender_id === state.myId;
    var row = document.createElement('div');
    row.className = 'msg-row ' + (out ? 'out' : 'in');
    row.setAttribute('data-id', m.id);

    var author = document.createElement('div');
    author.className = 'msg-author';
    author.textContent = m.sender_name + (m.sender_role === 'it' ? ' · IT-отдел' : '');
    row.appendChild(author);

    if (m.body) {
        var bubble = document.createElement('div');
        bubble.className = 'msg-bubble';
        bubble.textContent = m.body;
        row.appendChild(bubble);
    }

    if (m.attachments && m.attachments.length) {
        var wrap = document.createElement('div');
        wrap.className = 'msg-attachments';
        m.attachments.forEach(function (a) {
            if (a.is_image) {
                var div = document.createElement('div');
                div.className = 'att-image';
                var img = document.createElement('img');
                img.src = a.url;
                img.loading = 'lazy';
                img.addEventListener('click', function () { openLightbox(a.url); });
                div.appendChild(img);
                wrap.appendChild(div);
            } else {
                var link = document.createElement('a');
                link.className = 'att-file';
                link.href = a.url;
                link.target = '_blank';
                link.textContent = '📎 ' + a.name + ' (' + bytesToLabel(a.size) + ')';
                wrap.appendChild(link);
            }
        });
        row.appendChild(wrap);
    }

    var time = document.createElement('div');
    time.className = 'msg-time';
    time.textContent = fmtDateTime(m.created_at);
    row.appendChild(time);

    return row;
}

function renderChatActions(ticket) {
    var wrap = document.getElementById('chat-actions');
    wrap.innerHTML = '';
    if (ticket.status === 'open') {
        if (!ticket.assignee_name) {
            var claimBtn = document.createElement('button');
            claimBtn.className = 'btn btn-outline';
            claimBtn.textContent = '🖐 Забрать себе';
            claimBtn.addEventListener('click', function () { claimTicket(ticket.id); });
            wrap.appendChild(claimBtn);
        }
        var closeBtn = document.createElement('button');
        closeBtn.className = 'btn btn-success';
        closeBtn.textContent = '🔒 Закрыть заявку';
        closeBtn.addEventListener('click', function () { openCloseSheet(ticket.id); });
        wrap.appendChild(closeBtn);
    } else {
        var reopenBtn = document.createElement('button');
        reopenBtn.className = 'btn btn-outline';
        reopenBtn.textContent = '↺ Открыть заявку';
        reopenBtn.addEventListener('click', function () { reopenTicket(ticket.id); });
        wrap.appendChild(reopenBtn);
    }
}

function openTicket(id) {
    state.ticketId = id;
    state.lastMessageId = 0;
    showView('ticket');
    setTitle('Заявка №' + id);
    document.getElementById('chat-messages').innerHTML = '';
    document.getElementById('ticket-info-bar').textContent = 'Загрузка…';

    apiFetch('api/ticket.php?id=' + id).then(function (data) {
        if (!data.ok) { toast(data.error || 'Ошибка'); showView('list'); return; }
        state.myId = data.my_id;
        state.ticketStatus = data.ticket.status;
        state.ticketAssigned = !!data.ticket.assignee_name;

        var info = document.getElementById('ticket-info-bar');
        info.innerHTML = 'Автор: <b>' + escapeHtml(data.ticket.author_name) + '</b>' +
            (data.ticket.assignee_name ? ' · Отвечает: <b>' + escapeHtml(data.ticket.assignee_name) + '</b>' : ' · Не закреплена');

        var messagesEl = document.getElementById('chat-messages');
        data.messages.forEach(function (m) {
            messagesEl.appendChild(buildMessageRow(m));
            state.lastMessageId = m.id;
        });
        messagesEl.scrollTop = messagesEl.scrollHeight;

        renderChatActions(data.ticket);
        document.getElementById('chat-composer').style.display = data.ticket.status === 'open' ? 'block' : 'none';

        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = setInterval(pollTicket, 4000);
    }).catch(function () { toast('Ошибка сети'); });
}

function pollTicket() {
    if (state.view !== 'ticket' || !state.ticketId) return;
    apiFetch('api/ticket.php?id=' + state.ticketId + '&after_id=' + state.lastMessageId).then(function (data) {
        if (!data.ok) return;
        var messagesEl = document.getElementById('chat-messages');
        var shouldScroll = messagesEl.scrollTop + messagesEl.clientHeight >= messagesEl.scrollHeight - 60;
        var hadNew = false;
        data.messages.forEach(function (m) {
            if (m.id <= state.lastMessageId) return;
            messagesEl.appendChild(buildMessageRow(m));
            state.lastMessageId = m.id;
            hadNew = true;
        });
        if (hadNew && shouldScroll) messagesEl.scrollTop = messagesEl.scrollHeight;
        if (data.ticket.status !== state.ticketStatus || !!data.ticket.assignee_name !== state.ticketAssigned) {
            state.ticketStatus = data.ticket.status;
            state.ticketAssigned = !!data.ticket.assignee_name;
            renderChatActions(data.ticket);
            document.getElementById('chat-composer').style.display = data.ticket.status === 'open' ? 'block' : 'none';
            var info = document.getElementById('ticket-info-bar');
            info.innerHTML = 'Автор: <b>' + escapeHtml(data.ticket.author_name) + '</b>' +
                (data.ticket.assignee_name ? ' · Отвечает: <b>' + escapeHtml(data.ticket.assignee_name) + '</b>' : ' · Не закреплена');
        }
    }).catch(function () {});
}

function claimTicket(id) {
    apiFetch('api/claim.php', { method: 'POST', body: buildForm({ ticket_id: id }) }).then(function (data) {
        if (data.ok) { toast('Заявка закреплена за вами'); openTicket(id); }
        else toast(data.error || 'Не удалось закрепить заявку');
    });
}

function reopenTicket(id) {
    if (!confirm('Открыть заявку повторно?')) return;
    apiFetch('api/reopen.php', { method: 'POST', body: buildForm({ ticket_id: id }) }).then(function (data) {
        if (data.ok) { toast('Заявка открыта'); openTicket(id); }
        else toast(data.error || 'Не удалось открыть заявку');
    });
}

function buildForm(obj) {
    var fd = new FormData();
    Object.keys(obj).forEach(function (k) { fd.append(k, obj[k]); });
    return fd;
}

// ---------------- close sheet ----------------

var closeHours = 1;
var closeTicketId = null;

function openCloseSheet(id) {
    closeTicketId = id;
    closeHours = 1;
    document.getElementById('weekend-duty-check').checked = false;
    document.getElementById('hours-stepper').style.display = 'none';
    document.getElementById('hours-value').textContent = closeHours;
    document.getElementById('close-sheet').classList.add('show');
}
function closeCloseSheet() {
    document.getElementById('close-sheet').classList.remove('show');
}

// ---------------- report ----------------

function loadReport() {
    showView('report');
    setTitle('Отчёт по дежурствам');
    var wrap = document.getElementById('report-wrap');
    wrap.innerHTML = 'Загрузка…';
    apiFetch('api/report.php').then(function (data) {
        if (!data.ok) { wrap.innerHTML = ''; toast(data.error || 'Ошибка'); return; }
        if (!data.weeks.length) {
            wrap.innerHTML = '<div class="empty-state"><div class="icon">📊</div>Данных о дежурствах пока нет</div>';
            return;
        }
        wrap.innerHTML = '';
        data.weeks.forEach(function (w) {
            var card = document.createElement('div');
            card.className = 'report-card';
            var rowsHtml = w.entries.map(function (en) {
                var dotColor = en.color === 'purple' ? '#8b5cf6' : (en.color === 'blue' ? '#38bdf8' : '#94a3b8');
                return '<div class="report-row"><span><span class="dot" style="background:' + dotColor + '"></span>' + escapeHtml(en.name) + '</span><b>' + en.hours + ' ч</b></div>';
            }).join('');
            card.innerHTML = '<div class="report-week">Неделя ' + w.label + ' · Итого: ' + w.total + ' ч</div>' + rowsHtml;
            wrap.appendChild(card);
        });
    }).catch(function () { wrap.innerHTML = ''; toast('Ошибка сети'); });
}

// ---------------- composer (attach + send) ----------------

function renderPreview() {
    var previewEl = document.getElementById('preview');
    previewEl.innerHTML = '';
    pendingFiles.forEach(function (file, idx) {
        var chip = document.createElement('div');
        chip.className = 'preview-chip';
        var label = document.createElement('span');
        label.textContent = (file.type.indexOf('image/') === 0 ? '🖼 ' : '📎 ') + file.name;
        var remove = document.createElement('span');
        remove.className = 'remove';
        remove.textContent = '×';
        remove.addEventListener('click', function () { pendingFiles.splice(idx, 1); renderPreview(); });
        chip.appendChild(label);
        chip.appendChild(remove);
        previewEl.appendChild(chip);
    });
}

function sendMessage() {
    var bodyEl = document.getElementById('body');
    var body = bodyEl.value.trim();
    if (!body && pendingFiles.length === 0) return;
    var sendBtn = document.getElementById('send-btn');
    sendBtn.disabled = true;

    var fd = new FormData();
    fd.append('ticket_id', state.ticketId);
    fd.append('body', body);
    pendingFiles.forEach(function (f) { fd.append('files[]', f); });

    apiFetch('api/send_message.php', { method: 'POST', body: fd }).then(function (data) {
        sendBtn.disabled = false;
        if (data.ok) {
            bodyEl.value = '';
            pendingFiles = [];
            renderPreview();
            var messagesEl = document.getElementById('chat-messages');
            messagesEl.appendChild(buildMessageRow(data.message));
            messagesEl.scrollTop = messagesEl.scrollHeight;
            state.lastMessageId = data.message.id;
            if (data.attachment_errors && data.attachment_errors.length) {
                toast('Не все файлы удалось прикрепить: ' + data.attachment_errors.join('; '));
            }
            if (!state.ticketAssigned) {
                state.ticketAssigned = true;
                openTicket(state.ticketId);
            }
        } else {
            toast(data.error || 'Не удалось отправить сообщение');
        }
    }).catch(function () { sendBtn.disabled = false; toast('Ошибка сети'); });
}

// ---------------- boot ----------------

function boot() {
    if (tg) {
        tg.ready();
        tg.expand();
        if (tg.setHeaderColor) { try { tg.setHeaderColor('#10142a'); } catch (e) {} }
    }

    if (!INIT_DATA) {
        document.getElementById('auth-block').style.display = 'flex';
    }

    document.querySelectorAll('.tab').forEach(function (el) {
        el.addEventListener('click', function () { switchTab(el.getAttribute('data-tab')); });
    });

    document.getElementById('back-btn').addEventListener('click', function () {
        showView('list');
        setTitle(TAB_TITLES[state.tab]);
    });

    document.getElementById('menu-btn').addEventListener('click', function () {
        document.getElementById('menu-sheet').classList.add('show');
    });
    document.getElementById('menu-backdrop').addEventListener('click', function () {
        document.getElementById('menu-sheet').classList.remove('show');
    });
    document.querySelectorAll('#menu-sheet .sheet-item').forEach(function (el) {
        el.addEventListener('click', function () {
            document.getElementById('menu-sheet').classList.remove('show');
            if (el.getAttribute('data-action') === 'report') loadReport();
        });
    });

    document.getElementById('close-backdrop').addEventListener('click', closeCloseSheet);
    document.getElementById('weekend-duty-check').addEventListener('change', function () {
        document.getElementById('hours-stepper').style.display = this.checked ? 'flex' : 'none';
    });
    document.getElementById('hours-plus').addEventListener('click', function () {
        closeHours = Math.min(24, closeHours + 1);
        document.getElementById('hours-value').textContent = closeHours;
    });
    document.getElementById('hours-minus').addEventListener('click', function () {
        closeHours = Math.max(1, closeHours - 1);
        document.getElementById('hours-value').textContent = closeHours;
    });
    document.getElementById('confirm-close-btn').addEventListener('click', function () {
        var weekendDuty = document.getElementById('weekend-duty-check').checked;
        var fd = new FormData();
        fd.append('ticket_id', closeTicketId);
        fd.append('weekend_duty', weekendDuty ? '1' : '0');
        fd.append('hours', closeHours);
        apiFetch('api/close.php', { method: 'POST', body: fd }).then(function (data) {
            closeCloseSheet();
            if (data.ok) { toast('Заявка закрыта'); openTicket(closeTicketId); }
            else toast(data.error || 'Не удалось закрыть заявку');
        });
    });

    var attachBtn = document.getElementById('attach-btn');
    var fileInput = document.getElementById('file-input');
    attachBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
        for (var i = 0; i < fileInput.files.length; i++) {
            if (pendingFiles.length >= 10) break;
            pendingFiles.push(fileInput.files[i]);
        }
        fileInput.value = '';
        renderPreview();
    });

    document.getElementById('send-btn').addEventListener('click', sendMessage);
    document.getElementById('body').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });

    switchTab('new');

    var params = new URLSearchParams(window.location.search);
    var ticketParam = params.get('ticket');
    if (ticketParam) {
        openTicket(parseInt(ticketParam, 10));
    }
}

document.addEventListener('DOMContentLoaded', boot);
