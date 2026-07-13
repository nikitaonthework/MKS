'use strict';

var pendingFiles = [];

function bytesToLabel(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' МБ';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' КБ';
    return bytes + ' Б';
}

function renderPreview(previewEl) {
    previewEl.innerHTML = '';
    pendingFiles.forEach(function (file, idx) {
        var chip = document.createElement('div');
        chip.className = 'preview-chip';
        var label = document.createElement('span');
        label.textContent = (file.type.indexOf('image/') === 0 ? '🖼 ' : '📎 ') + file.name + ' (' + bytesToLabel(file.size) + ')';
        var remove = document.createElement('span');
        remove.className = 'remove';
        remove.textContent = '×';
        remove.addEventListener('click', function () {
            pendingFiles.splice(idx, 1);
            renderPreview(previewEl);
        });
        chip.appendChild(label);
        chip.appendChild(remove);
        previewEl.appendChild(chip);
    });
}

function wireAttach(attachBtnId, fileInputId, previewId) {
    var attachBtn = document.getElementById(attachBtnId);
    var fileInput = document.getElementById(fileInputId);
    var previewEl = document.getElementById(previewId);
    if (!attachBtn || !fileInput) return;
    attachBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
        for (var i = 0; i < fileInput.files.length; i++) {
            if (pendingFiles.length >= 10) break;
            pendingFiles.push(fileInput.files[i]);
        }
        fileInput.value = '';
        renderPreview(previewEl);
    });
}

function showError(box, text) {
    if (!box) return;
    box.textContent = text;
    box.style.display = 'block';
}
function hideError(box) {
    if (!box) return;
    box.style.display = 'none';
}

function initNewTicketForm() {
    pendingFiles = [];
    var form = document.getElementById('new-ticket-form');
    var previewEl = document.getElementById('preview');
    var errorBox = document.getElementById('form-error');
    wireAttach('attach-btn', 'file-input', 'preview');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        hideError(errorBox);
        var body = document.getElementById('body').value.trim();
        if (!body && pendingFiles.length === 0) {
            showError(errorBox, 'Введите текст заявки или приложите файл.');
            return;
        }
        var submitBtn = document.getElementById('submit-btn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Отправка…';

        var fd = new FormData();
        fd.append('body', body);
        pendingFiles.forEach(function (f) { fd.append('files[]', f); });

        fetch('api/create_ticket.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    window.location.href = 'ticket.php?id=' + data.ticket_id;
                } else {
                    showError(errorBox, data.error || 'Не удалось отправить заявку.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Отправить заявку';
                }
            })
            .catch(function () {
                showError(errorBox, 'Ошибка сети. Попробуйте ещё раз.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Отправить заявку';
            });
    });
}

function openLightbox(src) {
    var lb = document.getElementById('lightbox');
    var img = document.getElementById('lightbox-img');
    if (!lb || !img) return;
    img.src = src;
    lb.classList.add('show');
}
function closeLightbox() {
    var lb = document.getElementById('lightbox');
    if (lb) lb.classList.remove('show');
}

function buildMessageRow(m, currentUserId) {
    var out = m.sender_id === currentUserId;
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
    time.textContent = new Date(m.created_at.replace(' ', 'T')).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    row.appendChild(time);

    return row;
}

function initTicketChat(cfg) {
    pendingFiles = [];
    var messagesEl = document.getElementById('chat-messages');
    var lastId = cfg.lastMessageId;

    messagesEl.scrollTop = messagesEl.scrollHeight;

    if (cfg.canReply) {
        wireAttach('attach-btn', 'file-input', 'preview');
        var sendBtn = document.getElementById('send-btn');
        var bodyEl = document.getElementById('body');
        var errorBox = document.getElementById('form-error');
        var previewEl = document.getElementById('preview');

        function doSend() {
            var body = bodyEl.value.trim();
            if (!body && pendingFiles.length === 0) return;
            hideError(errorBox);
            sendBtn.disabled = true;

            var fd = new FormData();
            fd.append('ticket_id', cfg.ticketId);
            fd.append('body', body);
            pendingFiles.forEach(function (f) { fd.append('files[]', f); });

            fetch('api/send_message.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    sendBtn.disabled = false;
                    if (data.ok) {
                        bodyEl.value = '';
                        pendingFiles = [];
                        renderPreview(previewEl);
                        var row = buildMessageRow(data.message, cfg.currentUserId);
                        messagesEl.appendChild(row);
                        messagesEl.scrollTop = messagesEl.scrollHeight;
                        lastId = data.message.id;
                    } else {
                        showError(errorBox, data.error || 'Не удалось отправить сообщение.');
                    }
                })
                .catch(function () {
                    sendBtn.disabled = false;
                    showError(errorBox, 'Ошибка сети. Попробуйте ещё раз.');
                });
        }

        sendBtn.addEventListener('click', doSend);
        bodyEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                doSend();
            }
        });
    }

    var reopenBtn = document.getElementById('reopen-btn');
    if (reopenBtn) {
        reopenBtn.addEventListener('click', function () {
            if (!confirm('Открыть заявку повторно?')) return;
            reopenBtn.disabled = true;
            var fd = new FormData();
            fd.append('ticket_id', cfg.ticketId);
            fetch('api/reopen_ticket.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok) {
                        window.location.reload();
                    } else {
                        alert(data.error || 'Не удалось открыть заявку.');
                        reopenBtn.disabled = false;
                    }
                });
        });
    }

    function poll() {
        fetch('api/get_messages.php?ticket_id=' + cfg.ticketId + '&after_id=' + lastId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) return;
                var shouldScroll = messagesEl.scrollTop + messagesEl.clientHeight >= messagesEl.scrollHeight - 60;
                var hadNew = false;
                data.messages.forEach(function (m) {
                    if (m.sender_id === cfg.currentUserId) { lastId = m.id; return; }
                    var row = buildMessageRow(m, cfg.currentUserId);
                    messagesEl.appendChild(row);
                    lastId = m.id;
                    hadNew = true;
                });
                if (hadNew && shouldScroll) {
                    messagesEl.scrollTop = messagesEl.scrollHeight;
                }
                if (data.status !== cfg.status) {
                    window.location.reload();
                }
            })
            .catch(function () {});
    }

    setInterval(poll, 4000);
}
