'use strict';

var API_BASE = 'api/';

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
    return fetch(API_BASE + url, opts).then(function (r) { return r.json(); });
}

// Пережимает фото перед отправкой (фото с телефона часто весят по 5-10 МБ,
// а для заявки достаточно 1600px по длинной стороне) — заметно ускоряет
// загрузку, особенно в мобильной сети. GIF не трогаем (потеряется анимация),
// уже небольшие файлы и не-изображения возвращаем как есть.
function compressImageFile(file) {
    var MAX_DIM = 1600;
    var QUALITY = 0.82;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 300 * 1024) {
        return Promise.resolve(file);
    }
    return new Promise(function (resolve) {
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
            URL.revokeObjectURL(url);
            var scale = Math.min(1, MAX_DIM / Math.max(img.naturalWidth, img.naturalHeight));
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(img.naturalWidth * scale);
            canvas.height = Math.round(img.naturalHeight * scale);
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(function (blob) {
                if (!blob || blob.size >= file.size) {
                    resolve(file);
                    return;
                }
                var newName = file.name.replace(/\.\w+$/, '') + '.jpg';
                var compressed;
                try {
                    compressed = new File([blob], newName, { type: 'image/jpeg' });
                } catch (e) {
                    blob.name = newName;
                    compressed = blob;
                }
                resolve(compressed);
            }, 'image/jpeg', QUALITY);
        };
        img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
    });
}

// Отправка через XMLHttpRequest вместо fetch — только у него есть событие
// прогресса ЗАГРУЗКИ (upload.progress), нужное для индикатора при отправке
// фото/документов.
function submitFormWithProgress(url, formData, onProgress) {
    return new Promise(function (resolve, reject) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', API_BASE + url);
        xhr.upload.addEventListener('progress', function (e) {
            if (onProgress && e.lengthComputable) {
                onProgress(e.loaded / e.total);
            }
        });
        xhr.onload = function () {
            if (onProgress) onProgress(1);
            try {
                resolve(JSON.parse(xhr.responseText));
            } catch (e) {
                reject(e);
            }
        };
        xhr.onerror = function () { reject(new Error('network')); };
        xhr.send(formData);
    });
}

function uploadProgressEls(prefix) {
    return { box: document.getElementById(prefix + '-progress'), bar: document.getElementById(prefix + '-progress-bar') };
}
function showUploadProgress(prefix) {
    var els = uploadProgressEls(prefix);
    if (!els.box) return;
    els.bar.style.width = '0%';
    els.box.style.display = 'block';
}
function setUploadProgress(prefix, fraction) {
    var els = uploadProgressEls(prefix);
    if (!els.bar) return;
    els.bar.style.width = Math.round(fraction * 100) + '%';
}
function hideUploadProgress(prefix) {
    var els = uploadProgressEls(prefix);
    if (!els.box) return;
    els.box.style.display = 'none';
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
    document.getElementById('view-users').style.display = name === 'users' ? 'flex' : 'none';
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

function telHref(phone) {
    return 'tel:' + phone.replace(/[^\d+]/g, '');
}

function callButtonHtml(phone) {
    if (!phone) return '';
    return '<a class="call-btn" href="' + telHref(phone) + '" title="Позвонить">📞</a>';
}

function loadList(tab) {
    var listEl = document.getElementById('ticket-list');
    var emptyEl = document.getElementById('list-empty');
    var loaderEl = document.getElementById('list-loader');
    listEl.innerHTML = '';
    emptyEl.style.display = 'none';
    loaderEl.style.display = 'block';

    apiFetch('tickets.php?tab=' + encodeURIComponent(tab)).then(function (data) {
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

function renderTicketInfoBar(ticket) {
    var info = document.getElementById('ticket-info-bar');
    var jobTitle = ticket.author_job_title ? ' <span class="muted">(' + escapeHtml(ticket.author_job_title) + ')</span>' : '';
    info.innerHTML = 'Автор: <b>' + escapeHtml(ticket.author_name) + '</b>' + jobTitle + callButtonHtml(ticket.author_phone) +
        (ticket.assignee_name ? ' · Отвечает: <b>' + escapeHtml(ticket.assignee_name) + '</b>' : ' · Не закреплена');
}

function openTicket(id) {
    state.ticketId = id;
    state.lastMessageId = 0;
    showView('ticket');
    setTitle('Заявка №' + id);
    document.getElementById('chat-messages').innerHTML = '';
    document.getElementById('ticket-info-bar').textContent = 'Загрузка…';

    apiFetch('ticket.php?id=' + id).then(function (data) {
        if (!data.ok) { toast(data.error || 'Ошибка'); showView('list'); return; }
        state.myId = data.my_id;
        state.ticketStatus = data.ticket.status;
        state.ticketAssigned = !!data.ticket.assignee_name;
        renderTicketInfoBar(data.ticket);

        var messagesEl = document.getElementById('chat-messages');
        data.messages.forEach(function (m) {
            messagesEl.appendChild(buildMessageRow(m));
            state.lastMessageId = m.id;
        });
        messagesEl.scrollTop = messagesEl.scrollHeight;

        renderChatActions(data.ticket);
        document.getElementById('chat-composer').style.display = data.ticket.status === 'open' ? 'block' : 'none';

        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = document.hidden ? null : setInterval(pollTicket, 2500);
    }).catch(function () { toast('Ошибка сети'); });
}

function pollTicket() {
    if (state.view !== 'ticket' || !state.ticketId) return;
    apiFetch('ticket.php?id=' + state.ticketId + '&after_id=' + state.lastMessageId).then(function (data) {
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
            renderTicketInfoBar(data.ticket);
        }
    }).catch(function () {});
}

function claimTicket(id) {
    apiFetch('claim.php', { method: 'POST', body: buildForm({ ticket_id: id }) }).then(function (data) {
        if (data.ok) { toast('Заявка закреплена за вами'); openTicket(id); }
        else toast(data.error || 'Не удалось закрепить заявку');
    });
}

function reopenTicket(id) {
    if (!confirm('Открыть заявку повторно?')) return;
    apiFetch('reopen.php', { method: 'POST', body: buildForm({ ticket_id: id }) }).then(function (data) {
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
    apiFetch('report.php').then(function (data) {
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

// ---------------- сотрудники (администрирование) ----------------

var usersState = { list: [], selected: {}, editingId: null };

function loadUsers() {
    showView('users');
    setTitle('Сотрудники');
    document.getElementById('users-search').value = '';
    usersState.selected = {};
    updateUsersBulkBar();
    var listEl = document.getElementById('users-list');
    var emptyEl = document.getElementById('users-empty');
    var loaderEl = document.getElementById('users-loader');
    listEl.innerHTML = '';
    emptyEl.style.display = 'none';
    loaderEl.style.display = 'block';

    apiFetch('users_list.php').then(function (data) {
        loaderEl.style.display = 'none';
        if (!data.ok) { toast(data.error || 'Ошибка загрузки'); return; }
        usersState.list = data.users;
        renderUsers('');
    }).catch(function () { loaderEl.style.display = 'none'; toast('Ошибка сети'); });
}

function updateUsersBulkBar() {
    var ids = Object.keys(usersState.selected).filter(function (k) { return usersState.selected[k]; });
    var bar = document.getElementById('users-bulk-bar');
    if (ids.length) {
        bar.style.display = 'flex';
        document.getElementById('users-selected-count').textContent = ids.length + ' выбрано';
    } else {
        bar.style.display = 'none';
    }
}

function renderUsers(query) {
    var listEl = document.getElementById('users-list');
    var emptyEl = document.getElementById('users-empty');
    var q = query.trim().toLowerCase();
    var items = !q ? usersState.list : usersState.list.filter(function (u) {
        return (u.full_name && u.full_name.toLowerCase().indexOf(q) !== -1) ||
               (u.phone && u.phone.toLowerCase().indexOf(q) !== -1) ||
               (u.job_title && u.job_title.toLowerCase().indexOf(q) !== -1);
    });

    listEl.innerHTML = '';
    emptyEl.style.display = items.length ? 'none' : 'block';

    items.forEach(function (u) {
        var row = document.createElement('div');
        row.className = 'user-row' + (u.is_active ? '' : ' inactive');

        var check = document.createElement('input');
        check.type = 'checkbox';
        check.className = 'user-row-check';
        check.checked = !!usersState.selected[u.id];
        if (u.id === MY_USER_ID) {
            check.disabled = true;
        } else {
            check.addEventListener('click', function (e) { e.stopPropagation(); });
            check.addEventListener('change', function () {
                usersState.selected[u.id] = check.checked;
                updateUsersBulkBar();
            });
        }
        row.appendChild(check);

        var main = document.createElement('div');
        main.className = 'user-row-main';
        var roleBadge = u.role === 'it' ? '<span class="badge ' + (u.badge_color === 'purple' ? 'badge-purple' : (u.badge_color === 'blue' ? 'badge-blue' : 'badge-neutral')) + '">IT</span>' : '';
        var inactiveBadge = !u.is_active ? '<span class="badge badge-inactive">Деактивирован</span>' : '';
        var jobTitleHtml = u.job_title ? escapeHtml(u.job_title) + ' · ' : '';
        main.innerHTML =
            '<div class="user-row-name">' + escapeHtml(u.full_name) + ' ' + roleBadge + inactiveBadge + '</div>' +
            '<div class="user-row-phone">' + jobTitleHtml + (u.phone ? escapeHtml(u.phone) : '<span class="muted">телефон не указан</span>') + '</div>';
        main.addEventListener('click', function () { openUserSheet(u); });
        row.appendChild(main);

        if (u.phone) {
            var callLink = document.createElement('a');
            callLink.className = 'call-btn';
            callLink.href = telHref(u.phone);
            callLink.title = 'Позвонить';
            callLink.textContent = '📞';
            callLink.addEventListener('click', function (e) { e.stopPropagation(); });
            row.appendChild(callLink);
        }

        listEl.appendChild(row);
    });
}

function openUserSheet(u) {
    usersState.editingId = u ? u.id : null;
    document.getElementById('user-sheet-title').textContent = u ? 'Изменить сотрудника' : 'Добавить сотрудника';
    document.getElementById('user-sheet-error').style.display = 'none';
    document.getElementById('user-full-name').value = u ? u.full_name : '';
    document.getElementById('user-phone').value = u ? (u.phone || '') : '';
    document.getElementById('user-job-title').value = u ? (u.job_title || '') : '';
    document.getElementById('user-password').value = '';
    document.getElementById('user-password-label').textContent = u ? 'Новый пароль' : 'Пароль';
    document.getElementById('user-password').placeholder = u ? 'Оставьте пустым, чтобы не менять' : 'По умолчанию: 123456789';
    document.getElementById('user-force-change').checked = true;
    document.getElementById('user-role').value = u ? u.role : 'employee';
    document.getElementById('user-badge-color').value = u && u.badge_color ? u.badge_color : '';
    document.getElementById('user-badge-field').style.display = (u ? u.role : 'employee') === 'it' ? 'block' : 'none';
    var isSelf = !!(u && u.id === MY_USER_ID);
    document.getElementById('user-active-row').style.display = u ? 'flex' : 'none';
    document.getElementById('user-active').checked = u ? !!u.is_active : true;
    document.getElementById('user-active').disabled = isSelf;
    document.getElementById('user-delete-btn').style.display = u && !isSelf ? 'block' : 'none';
    document.getElementById('user-sheet').classList.add('show');
}

function closeUserSheet() {
    document.getElementById('user-sheet').classList.remove('show');
}

function saveUser() {
    var errorBox = document.getElementById('user-sheet-error');
    errorBox.style.display = 'none';
    var fd = new FormData();
    if (usersState.editingId) fd.append('id', usersState.editingId);
    fd.append('full_name', document.getElementById('user-full-name').value.trim());
    fd.append('phone', document.getElementById('user-phone').value.trim());
    fd.append('job_title', document.getElementById('user-job-title').value.trim());
    fd.append('password', document.getElementById('user-password').value);
    fd.append('force_change', document.getElementById('user-force-change').checked ? '1' : '');
    fd.append('role', document.getElementById('user-role').value);
    fd.append('badge_color', document.getElementById('user-badge-color').value);
    fd.append('is_active', document.getElementById('user-active').checked ? '1' : '');

    var saveBtn = document.getElementById('user-save-btn');
    saveBtn.disabled = true;
    apiFetch('users_save.php', { method: 'POST', body: fd }).then(function (data) {
        saveBtn.disabled = false;
        if (!data.ok) {
            errorBox.textContent = data.error || 'Не удалось сохранить';
            errorBox.style.display = 'block';
            return;
        }
        closeUserSheet();
        toast('Сохранено');
        loadUsers();
    }).catch(function () {
        saveBtn.disabled = false;
        errorBox.textContent = 'Ошибка сети';
        errorBox.style.display = 'block';
    });
}

function deleteUsersByIds(ids) {
    return apiFetch('users_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: ids }),
    }).then(function (data) {
        if (!data.ok) { toast(data.error || 'Не удалось удалить'); return; }
        var parts = [];
        if (data.deleted.length) parts.push('удалено: ' + data.deleted.length);
        if (data.deactivated.length) parts.push('деактивировано (есть история заявок): ' + data.deactivated.length);
        if (data.skipped.length) parts.push('пропущено: ' + data.skipped.length);
        toast(parts.join(', ') || 'Готово');
        loadUsers();
    }).catch(function () { toast('Ошибка сети'); });
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
    var hadFiles = pendingFiles.length > 0;
    if (hadFiles) showUploadProgress('upload');

    var fd = new FormData();
    fd.append('ticket_id', state.ticketId);
    fd.append('body', body);
    pendingFiles.forEach(function (f) { fd.append('files[]', f); });

    submitFormWithProgress('send_message.php', fd, function (frac) { setUploadProgress('upload', frac); }).then(function (data) {
        sendBtn.disabled = false;
        if (hadFiles) hideUploadProgress('upload');
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
    }).catch(function () { sendBtn.disabled = false; if (hadFiles) hideUploadProgress('upload'); toast('Ошибка сети'); });
}

// ---------------- push-уведомления ----------------

function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; i++) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && !!VAPID_PUBLIC_KEY;
}

function sendSubscriptionToServer(sub) {
    fetch('api/push_subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(sub),
    }).catch(function () {});
}

function subscribeToPush(reg) {
    return reg.pushManager.getSubscription().then(function (existing) {
        if (existing) {
            sendSubscriptionToServer(existing);
            return existing;
        }
        return reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY),
        }).then(function (sub) {
            sendSubscriptionToServer(sub);
            return sub;
        });
    });
}

function maybeShowPushBanner(reg) {
    if (!pushSupported()) return;
    if (Notification.permission === 'denied') return;
    if (Notification.permission === 'granted') {
        subscribeToPush(reg);
        return;
    }
    if (localStorage.getItem('push_dismissed') === '1') return;
    document.getElementById('push-banner').style.display = 'flex';
}

function initPush() {
    if (!pushSupported()) return;
    navigator.serviceWorker.register('sw.js').then(function (reg) {
        maybeShowPushBanner(reg);

        document.getElementById('push-enable').addEventListener('click', function () {
            Notification.requestPermission().then(function (perm) {
                document.getElementById('push-banner').style.display = 'none';
                if (perm === 'granted') {
                    subscribeToPush(reg).then(function () { toast('Уведомления включены'); });
                } else {
                    toast('Уведомления не разрешены в браузере');
                }
            });
        });

        document.getElementById('push-dismiss').addEventListener('click', function () {
            document.getElementById('push-banner').style.display = 'none';
            localStorage.setItem('push_dismissed', '1');
        });
    }).catch(function () {});
}

// ---------------- boot ----------------

function boot() {
    // На фоновой вкладке опрос сообщений не нужен — экономит запросы и
    // батарею; при возврате на вкладку сразу подтягиваем пропущенное.
    document.addEventListener('visibilitychange', function () {
        if (state.view !== 'ticket') return;
        if (document.hidden) {
            if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
        } else {
            pollTicket();
            if (!state.pollTimer) state.pollTimer = setInterval(pollTicket, 2500);
        }
    });

    document.querySelectorAll('.tab').forEach(function (el) {
        el.addEventListener('click', function () { switchTab(el.getAttribute('data-tab')); });
    });

    document.getElementById('back-btn').addEventListener('click', function () {
        showView('list');
        setTitle(TAB_TITLES[state.tab]);
        loadList(state.tab);
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
            var action = el.getAttribute('data-action');
            if (action === 'report') loadReport();
            if (action === 'users') loadUsers();
            if (action === 'logout') window.location.href = '../logout.php';
            if (action === 'notifications') {
                localStorage.removeItem('push_dismissed');
                if ('serviceWorker' in navigator) {
                    navigator.serviceWorker.ready.then(maybeShowPushBanner);
                }
            }
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
        apiFetch('close.php', { method: 'POST', body: fd }).then(function (data) {
            closeCloseSheet();
            if (data.ok) { toast('Заявка закрыта'); openTicket(closeTicketId); }
            else toast(data.error || 'Не удалось закрыть заявку');
        });
    });

    var attachBtn = document.getElementById('attach-btn');
    var fileInput = document.getElementById('file-input');
    attachBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
        var picked = [];
        for (var i = 0; i < fileInput.files.length; i++) {
            if (pendingFiles.length + picked.length >= 10) break;
            picked.push(fileInput.files[i]);
        }
        fileInput.value = '';
        if (!picked.length) return;
        Promise.all(picked.map(compressImageFile)).then(function (compressed) {
            compressed.forEach(function (f) { pendingFiles.push(f); });
            renderPreview();
        });
    });

    document.getElementById('send-btn').addEventListener('click', sendMessage);
    document.getElementById('body').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });

    document.getElementById('users-search').addEventListener('input', function () {
        renderUsers(this.value);
    });
    document.getElementById('users-add-btn').addEventListener('click', function () { openUserSheet(null); });
    document.getElementById('users-delete-selected').addEventListener('click', function () {
        var ids = Object.keys(usersState.selected).filter(function (k) { return usersState.selected[k]; }).map(Number);
        if (!ids.length) return;
        if (!confirm('Удалить выбранных сотрудников (' + ids.length + ')? Тех, у кого уже есть заявки в истории, система деактивирует вместо удаления.')) return;
        deleteUsersByIds(ids);
    });
    document.getElementById('user-sheet-backdrop').addEventListener('click', closeUserSheet);
    document.getElementById('user-role').addEventListener('change', function () {
        document.getElementById('user-badge-field').style.display = this.value === 'it' ? 'block' : 'none';
    });
    document.getElementById('user-save-btn').addEventListener('click', saveUser);
    document.getElementById('user-delete-btn').addEventListener('click', function () {
        if (!usersState.editingId) return;
        if (!confirm('Удалить сотрудника? Если у него уже есть заявки в истории, система деактивирует его вместо удаления.')) return;
        deleteUsersByIds([usersState.editingId]).then(closeUserSheet);
    });

    switchTab('new');
    initPush();

    var params = new URLSearchParams(window.location.search);
    var ticketParam = params.get('ticket');
    if (ticketParam) {
        openTicket(parseInt(ticketParam, 10));
    }
}

document.addEventListener('DOMContentLoaded', boot);
