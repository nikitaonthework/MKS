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

function wireAttach(attachBtnId, fileInputId, previewId) {
    var attachBtn = document.getElementById(attachBtnId);
    var fileInput = document.getElementById(fileInputId);
    var previewEl = document.getElementById(previewId);
    if (!attachBtn || !fileInput) return;
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
            renderPreview(previewEl);
        });
    });
}

// Отправка формы через XMLHttpRequest вместо fetch — только у него есть
// событие прогресса ЗАГРУЗКИ (upload.progress), нужное для заполняющегося
// индикатора при отправке фото/документов.
function submitFormWithProgress(url, formData, onProgress) {
    return new Promise(function (resolve, reject) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url);
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

function showError(box, text) {
    if (!box) return;
    box.className = 'error-box';
    box.textContent = text;
    box.style.display = 'block';
}
function showNotice(box, text) {
    if (!box) return;
    box.className = 'info-box';
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
        if (pendingFiles.length) showUploadProgress('upload');

        var fd = new FormData();
        fd.append('csrf', window.CSRF_TOKEN || '');
        fd.append('body', body);
        pendingFiles.forEach(function (f) { fd.append('files[]', f); });

        submitFormWithProgress('api/create_ticket.php', fd, function (frac) { setUploadProgress('upload', frac); })
            .then(function (data) {
                if (data.ok) {
                    if (data.attachment_errors && data.attachment_errors.length) {
                        alert('Заявка отправлена, но не все файлы удалось прикрепить:\n' + data.attachment_errors.join('\n'));
                    }
                    window.location.href = 'ticket.php?id=' + data.ticket_id;
                } else {
                    hideUploadProgress('upload');
                    showError(errorBox, data.error || 'Не удалось отправить заявку.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Отправить заявку';
                }
            })
            .catch(function () {
                hideUploadProgress('upload');
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
            var hadFiles = pendingFiles.length > 0;
            if (hadFiles) showUploadProgress('upload');

            var fd = new FormData();
            fd.append('csrf', window.CSRF_TOKEN || '');
            fd.append('ticket_id', cfg.ticketId);
            fd.append('body', body);
            pendingFiles.forEach(function (f) { fd.append('files[]', f); });

            submitFormWithProgress('api/send_message.php', fd, function (frac) { setUploadProgress('upload', frac); })
                .then(function (data) {
                    sendBtn.disabled = false;
                    if (hadFiles) hideUploadProgress('upload');
                    if (data.ok) {
                        bodyEl.value = '';
                        pendingFiles = [];
                        renderPreview(previewEl);
                        var row = buildMessageRow(data.message, cfg.currentUserId);
                        messagesEl.appendChild(row);
                        messagesEl.scrollTop = messagesEl.scrollHeight;
                        lastId = data.message.id;
                        if (data.attachment_errors && data.attachment_errors.length) {
                            showNotice(errorBox, 'Не все файлы удалось прикрепить: ' + data.attachment_errors.join('; '));
                        }
                    } else {
                        showError(errorBox, data.error || 'Не удалось отправить сообщение.');
                    }
                })
                .catch(function () {
                    sendBtn.disabled = false;
                    if (hadFiles) hideUploadProgress('upload');
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
            fd.append('csrf', window.CSRF_TOKEN || '');
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

    var pollTimer = null;
    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(poll, 2500);
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }
    // На фоновой вкладке опрос не нужен — экономит запросы и батарею; при
    // возврате на вкладку сразу подтягиваем то, что пропустили.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
        } else {
            poll();
            startPolling();
        }
    });
    if (!document.hidden) {
        startPolling();
    }
}

// ---------------- поиск по закрытым заявкам ----------------

function initSearchPage() {
    var input = document.getElementById('search-input');
    var resultsEl = document.getElementById('search-results');
    var loaderEl = document.getElementById('search-loader');
    var emptyEl = document.getElementById('search-empty');
    var emptyTextEl = document.getElementById('search-empty-text');
    var debounceTimer = null;
    var currentRequestId = 0;

    function badgeAssignee(t) {
        if (!t.assignee_name) return '';
        var color = t.assignee_color === 'purple' ? '#8b5cf6' : (t.assignee_color === 'blue' ? '#38bdf8' : '#94a3b8');
        return '<span class="badge badge-assignee" style="color:' + color + '; border-color:' + color + ';">' + escapeHtmlSearch(t.assignee_name) + '</span>';
    }

    function escapeHtmlSearch(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    function renderResults(tickets) {
        resultsEl.innerHTML = '';
        tickets.forEach(function (t) {
            var a = document.createElement('a');
            a.className = 'ticket-row' + (t.mine ? ' mine' : '');
            a.href = 'ticket.php?id=' + t.id;
            a.innerHTML =
                '<div class="ticket-id">№' + t.id + '</div>' +
                '<div class="ticket-main">' +
                  '<div class="ticket-subject">' + escapeHtmlSearch(t.subject) + '</div>' +
                  '<div class="ticket-meta"><span>' + escapeHtmlSearch(t.author_name) + '</span>' + badgeAssignee(t) + '</div>' +
                '</div>' +
                '<span class="badge badge-closed">✔ Закрыта</span>';
            resultsEl.appendChild(a);
        });
    }

    function runSearch(query) {
        var requestId = ++currentRequestId;
        loaderEl.style.display = 'block';
        emptyEl.style.display = 'none';
        fetch('api/search_tickets.php?q=' + encodeURIComponent(query))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (requestId !== currentRequestId) return; // ответ на устаревший запрос — игнорируем
                loaderEl.style.display = 'none';
                if (!data.ok) {
                    resultsEl.innerHTML = '';
                    emptyTextEl.textContent = data.error || 'Ошибка поиска';
                    emptyEl.style.display = 'block';
                    return;
                }
                if (!data.tickets.length) {
                    resultsEl.innerHTML = '';
                    emptyTextEl.textContent = 'Ничего не найдено. Попробуйте другое слово или создайте новую заявку.';
                    emptyEl.style.display = 'block';
                    return;
                }
                emptyEl.style.display = 'none';
                renderResults(data.tickets);
            })
            .catch(function () {
                if (requestId !== currentRequestId) return;
                loaderEl.style.display = 'none';
                resultsEl.innerHTML = '';
                emptyTextEl.textContent = 'Ошибка сети. Попробуйте ещё раз.';
                emptyEl.style.display = 'block';
            });
    }

    input.addEventListener('input', function () {
        var query = input.value.trim();
        clearTimeout(debounceTimer);
        resultsEl.innerHTML = '';
        if (!query) {
            loaderEl.style.display = 'none';
            emptyTextEl.textContent = 'Начните вводить запрос — например, название устройства или суть проблемы.';
            emptyEl.style.display = 'block';
            return;
        }
        debounceTimer = setTimeout(function () { runSearch(query); }, 300);
    });
}

// ---------------- push-уведомления (ответ IT-отдела на заявку) ----------------

function urlBase64ToUint8ArrayEmp(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; i++) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

function pushSupportedEmp() {
    return 'serviceWorker' in navigator && 'PushManager' in window && typeof VAPID_PUBLIC_KEY !== 'undefined' && !!VAPID_PUBLIC_KEY;
}

function sendSubscriptionToServerEmp(sub) {
    fetch('api/push_subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(sub),
    }).catch(function () {});
}

function subscribeToPushEmp(reg) {
    return reg.pushManager.getSubscription().then(function (existing) {
        if (existing) {
            sendSubscriptionToServerEmp(existing);
            return existing;
        }
        return reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8ArrayEmp(VAPID_PUBLIC_KEY),
        }).then(function (sub) {
            sendSubscriptionToServerEmp(sub);
            return sub;
        });
    });
}

function buildPushBannerEmp() {
    var bar = document.createElement('div');
    bar.className = 'push-banner-float';
    bar.innerHTML =
        '<span>Включить уведомления, когда IT-отдел ответит на заявку?</span>' +
        '<div class="push-banner-actions">' +
          '<button type="button" class="btn btn-sm btn-outline" id="emp-push-dismiss">Не сейчас</button>' +
          '<button type="button" class="btn btn-sm btn-primary" id="emp-push-enable">Включить</button>' +
        '</div>';
    document.body.appendChild(bar);
    return bar;
}

function maybeShowPushBannerEmp(reg) {
    if (!pushSupportedEmp()) return;
    if (Notification.permission === 'denied') return;
    if (Notification.permission === 'granted') {
        subscribeToPushEmp(reg);
        return;
    }
    if (localStorage.getItem('emp_push_dismissed') === '1') return;

    var bar = buildPushBannerEmp();
    document.getElementById('emp-push-enable').addEventListener('click', function () {
        Notification.requestPermission().then(function (perm) {
            bar.remove();
            if (perm === 'granted') {
                subscribeToPushEmp(reg);
            }
        });
    });
    document.getElementById('emp-push-dismiss').addEventListener('click', function () {
        bar.remove();
        localStorage.setItem('emp_push_dismissed', '1');
    });
}

function initPushEmp() {
    if (!pushSupportedEmp()) return;
    navigator.serviceWorker.register('sw.js').then(function (reg) {
        maybeShowPushBannerEmp(reg);
    }).catch(function () {});
}

document.addEventListener('DOMContentLoaded', initPushEmp);
