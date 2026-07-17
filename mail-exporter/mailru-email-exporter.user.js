// ==UserScript==
// @name         Sestroclinic Mail Exporter (e.mail.ru -> mbox)
// @namespace    sestroclinic-mail-export
// @version      0.2.0
// @description  Скачивает все письма из e.mail.ru (VK WorkMail) в .mbox файлы для импорта в Thunderbird через ImportExportTools NG. Не использует IMAP.
// @author       you
// @match        https://e.mail.ru/*
// @run-at       document-start
// @grant        none
// ==/UserScript==

/*
  КАК ЭТО РАБОТАЕТ (коротко, подробности — в README.md рядом со скриптом)

  У e.mail.ru закрытый (не документированный) веб-API. Мы не знаем заранее
  точные названия параметров и полей JSON, поэтому скрипт не "угадывает"
  ссылки, а ПОДСЛУШИВАЕТ реальные запросы, которые сам сайт делает во время
  обычной работы (перехватывает fetch/XHR). Тебе нужно один раз открыть
  папку и полистать список писем (список), и открыть одно письмо (карточка
  письма) — скрипт запомнит эти два вида запросов и дальше сам будет их
  повторять с увеличением offset и с разными id писем, собирая все письма
  в .mbox файлы (по несколько сотен писем в файле, чтобы не упереться в
  память браузера).

  CSRF-токен в ссылках короткоживущий и подписан сервером — подделать его
  нельзя, поэтому скрипт всегда берёт САМЫЙ СВЕЖИЙ токен из реальных
  запросов сайта, а не тот, что был в первом подслушанном запросе.
*/

(function () {
  'use strict';

  const NS = 'MKSMailExport';
  if (window[NS]) return; // не инициализируем дважды при hot-reload SPA
  const state = {
    token: null,           // самый свежий увиденный csrf-токен (query param "token")
    ownEmail: null,        // адрес текущего ящика (для From/Return-Path и имени файлов)
    seenRequests: [],      // журнал подслушанных запросов (для панели/диагностики)
    listTemplate: null,    // {urlObj, offsetParam, limitParam, limitValue}
    threadTemplate: null,  // {urlObj}
    folders: new Map(),    // id/label -> {sampleUrl, count}
    running: false,
    paused: false,
    debug: false,
    log: [],
  };
  window[NS] = state;

  // ---------------------------------------------------------------------
  // Утилиты
  // ---------------------------------------------------------------------

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  function logLine(msg) {
    const line = `[${new Date().toLocaleTimeString()}] ${msg}`;
    state.log.push(line);
    if (state.log.length > 2000) state.log.shift();
    console.log('[MKSMailExport]', msg);
    renderLog();
  }

  function utf8ToBytes(str) {
    return new TextEncoder().encode(str);
  }

  function bytesToBase64(bytes) {
    let binary = '';
    const chunk = 0x8000;
    for (let i = 0; i < bytes.length; i += chunk) {
      binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
    }
    return btoa(binary);
  }

  function utf8ToBase64(str) {
    return bytesToBase64(utf8ToBytes(str));
  }

  function base64Wrap(b64, width = 76) {
    const lines = [];
    for (let i = 0; i < b64.length; i += width) lines.push(b64.slice(i, i + width));
    return lines.join('\r\n');
  }

  // RFC 2047 encoded-word для заголовков с кириллицей (Subject, display-name).
  function encodeHeaderWord(str) {
    if (!str) return '';
    if (/^[\x00-\x7F]*$/.test(str)) return str; // чистый ASCII — кодировать не нужно
    const bytes = utf8ToBytes(str);
    const words = [];
    const chunkBytes = 30; // держим base64-кусок короче ~76 символов на строку
    for (let i = 0; i < bytes.length; i += chunkBytes) {
      const slice = bytes.subarray(i, i + chunkBytes);
      words.push(`=?UTF-8?B?${bytesToBase64(slice)}?=`);
    }
    return words.join(' ');
  }

  function asctime(date) {
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const p2 = (n) => String(n).padStart(2, '0');
    return `${days[date.getUTCDay()]} ${months[date.getUTCMonth()]} ${p2(date.getUTCDate())} ` +
      `${p2(date.getUTCHours())}:${p2(date.getUTCMinutes())}:${p2(date.getUTCSeconds())} ${date.getUTCFullYear()}`;
  }

  function rfc2822Date(date) {
    return date.toUTCString().replace('GMT', '+0000');
  }

  function parseDateSmart(v) {
    if (v == null) return new Date();
    if (typeof v === 'number') {
      // секунды или миллисекунды?
      return new Date(v > 2e10 ? v : v * 1000);
    }
    if (/^\d+$/.test(String(v))) return parseDateSmart(Number(v));
    const d = new Date(v);
    return isNaN(d.getTime()) ? new Date() : d;
  }

  function safeFileNamePart(s) {
    return String(s).replace(/[^a-zA-Z0-9а-яА-ЯёЁ_-]+/g, '_').slice(0, 60);
  }

  // ---------------------------------------------------------------------
  // Глубокий поиск полей в JSON-ответе неизвестной структуры
  // ---------------------------------------------------------------------

  function findFieldDeep(obj, keyRegex, seen) {
    seen = seen || new Set();
    if (!obj || typeof obj !== 'object' || seen.has(obj)) return undefined;
    seen.add(obj);
    if (Array.isArray(obj)) {
      for (const item of obj) {
        const r = findFieldDeep(item, keyRegex, seen);
        if (r !== undefined) return r;
      }
      return undefined;
    }
    for (const k of Object.keys(obj)) {
      if (keyRegex.test(k)) return obj[k];
    }
    for (const k of Object.keys(obj)) {
      const r = findFieldDeep(obj[k], keyRegex, seen);
      if (r !== undefined) return r;
    }
    return undefined;
  }

  // Ищет первый массив объектов, похожих на "письма" (есть subject-подобное
  // или date-подобное поле хотя бы у одного элемента).
  function findMessageArray(obj, seen) {
    seen = seen || new Set();
    if (!obj || typeof obj !== 'object' || seen.has(obj)) return null;
    seen.add(obj);
    if (Array.isArray(obj)) {
      const looksLikeMessages = obj.length > 0 && obj.every((it) => it && typeof it === 'object') &&
        obj.some((it) => Object.keys(it).some((k) => /subject|snippet|from|correspond/i.test(k)));
      if (looksLikeMessages) return obj;
      for (const item of obj) {
        const r = findMessageArray(item, seen);
        if (r) return r;
      }
      return null;
    }
    for (const k of Object.keys(obj)) {
      const r = findMessageArray(obj[k], seen);
      if (r) return r;
    }
    return null;
  }

  function formatAddress(x) {
    if (!x) return '';
    if (typeof x === 'string') return x;
    if (Array.isArray(x)) return x.map(formatAddress).filter(Boolean).join(', ');
    const email = x.email || x.address || x.mail || x.mailbox || '';
    const name = x.name || x.displayName || x.personal || x.title || '';
    if (name && email) return `${encodeHeaderWord(name)} <${email}>`;
    return email || name || '';
  }

  // ---------------------------------------------------------------------
  // Сниффер: перехват fetch и XMLHttpRequest, чтобы подсмотреть реальные
  // запросы приложения (URL, параметры, тело ответа) без их изменения.
  // ---------------------------------------------------------------------

  function classifyAndRemember(urlStr, bodyText) {
    let u;
    try { u = new URL(urlStr, location.href); } catch (e) { return; }
    if (!/\/api\/v1\//.test(u.pathname)) return;
    if (u.searchParams.get('token')) state.token = u.searchParams.get('token');
    if (u.searchParams.get('email') && !state.ownEmail) state.ownEmail = u.searchParams.get('email');

    const entry = { url: u, pathname: u.pathname, ts: Date.now(), bodyText };
    state.seenRequests.push(entry);
    if (state.seenRequests.length > 500) state.seenRequests.shift();

    if (u.pathname === '/api/v1/threads/thread') {
      state.threadTemplate = { urlObj: u };
      logLine(`Подсмотрел запрос письма: ${u.pathname}`);
    } else if (u.pathname === '/api/v1/threads' || /\/api\/v1\/(threads|messages|letters)$/.test(u.pathname)) {
      tryLearnListTemplate(u);
    }

    // Пытаемся определить папку по ответу (список писем содержит folder id/имя где-то рядом)
    if (bodyText && u.pathname !== '/api/v1/threads/thread') {
      try {
        const json = JSON.parse(bodyText);
        const arr = findMessageArray(json);
        if (arr) rememberFolderFromRequest(u, arr.length);
      } catch (e) { /* не JSON или частичный текст — пропускаем */ }
    }
    renderPanel();
  }

  function rememberFolderFromRequest(u, count) {
    // Папку определяем по совокупности параметров запроса (кроме offset/limit/token/_),
    // т.к. не знаем заранее, как называется параметр id папки.
    const skip = new Set(['token', '_', 'offset', 'limit', 'count', 'last_modified']);
    if (state.listTemplate) {
      if (state.listTemplate.offsetParam) skip.add(state.listTemplate.offsetParam);
      if (state.listTemplate.limitParam) skip.add(state.listTemplate.limitParam);
    }
    const key = [...u.searchParams.entries()].filter(([k]) => !skip.has(k)).map(([k, v]) => `${k}=${v}`).sort().join('&');
    if (!state.folders.has(key)) {
      state.folders.set(key, { sampleUrl: u, count, label: key || '(без параметров)' });
    } else {
      state.folders.get(key).count = count;
    }
  }

  const listSamplesByPathname = new Map(); // pathname -> [urlObj, urlObj, ...]

  function tryLearnListTemplate(u) {
    const arr = listSamplesByPathname.get(u.pathname) || [];
    arr.push(u);
    listSamplesByPathname.set(u.pathname, arr.slice(-6));
    if (state.listTemplate && state.listTemplate.urlObj.pathname === u.pathname) return;

    const samples = listSamplesByPathname.get(u.pathname);
    if (samples.length < 2) return;

    // Ищем числовой параметр, который меняется между двумя разными запросами
    // на один и тот же путь — это offset. Параметр, который остаётся малым
    // и постоянным (обычно 20-100) — вероятно limit/count.
    const a = samples[samples.length - 2];
    const b = samples[samples.length - 1];
    let offsetParam = null;
    for (const [k, v] of a.searchParams.entries()) {
      if (!/^\d+$/.test(v)) continue;
      const vb = b.searchParams.get(k);
      if (vb != null && /^\d+$/.test(vb) && vb !== v) { offsetParam = k; break; }
    }
    if (!offsetParam) return;

    let limitParam = null, limitValue = null;
    for (const [k, v] of b.searchParams.entries()) {
      if (k === offsetParam) continue;
      if (/^\d{1,4}$/.test(v)) { limitParam = k; limitValue = Number(v); break; }
    }

    state.listTemplate = { urlObj: b, offsetParam, limitParam, limitValue: limitValue || 20 };
    logLine(`Определил шаблон списка писем: путь=${u.pathname}, offset-параметр="${offsetParam}"` +
      (limitParam ? `, limit-параметр="${limitParam}"=${limitValue}` : ', limit не определён (возьму 20)'));
  }

  function installSniffer() {
    const origFetch = window.fetch;
    window.fetch = function (input, init) {
      const p = origFetch.apply(this, arguments);
      try {
        const url = typeof input === 'string' ? input : input && input.url;
        if (url && url.includes('/api/v1/')) {
          p.then((resp) => {
            resp.clone().text().then((t) => classifyAndRemember(url, t)).catch(() => classifyAndRemember(url, null));
          }).catch(() => {});
        }
      } catch (e) { /* не мешаем реальному запросу при любой нашей ошибке */ }
      return p;
    };

    const OrigXHR = window.XMLHttpRequest;
    const origOpen = OrigXHR.prototype.open;
    const origSend = OrigXHR.prototype.send;
    OrigXHR.prototype.open = function (method, url) {
      this.__mksUrl = url;
      return origOpen.apply(this, arguments);
    };
    OrigXHR.prototype.send = function () {
      this.addEventListener('load', () => {
        try {
          if (this.__mksUrl && String(this.__mksUrl).includes('/api/v1/')) {
            classifyAndRemember(this.__mksUrl, this.responseText);
          }
        } catch (e) {}
      });
      return origSend.apply(this, arguments);
    };
  }

  // ---------------------------------------------------------------------
  // Сетевые запросы самого экспортёра (используют подслушанный шаблон,
  // но всегда со свежим token'ом)
  // ---------------------------------------------------------------------

  async function apiGet(urlObj, overrides) {
    const u = new URL(urlObj.toString());
    if (state.token) u.searchParams.set('token', state.token);
    u.searchParams.set('_', String(Date.now()));
    if (overrides) for (const [k, v] of Object.entries(overrides)) u.searchParams.set(k, String(v));

    let attempt = 0;
    while (true) {
      attempt++;
      const resp = await fetch(u.toString(), { credentials: 'include' });
      if (resp.status === 429 || resp.status === 503) {
        const wait = Math.min(60000, 2000 * 2 ** attempt);
        logLine(`Сервер попросил притормозить (${resp.status}), жду ${wait / 1000}с...`);
        await sleep(wait);
        continue;
      }
      if (resp.status === 401 || resp.status === 403) {
        logLine('Токен протух (401/403). Открой любое письмо в почте руками, потом нажми "Продолжить".');
        await waitForResume();
        continue;
      }
      const text = await resp.text();
      classifyAndRemember(u.toString(), text); // заодно освежаем token, если он там был
      let json;
      try { json = JSON.parse(text); } catch (e) {
        logLine(`Не смог разобрать JSON ответа (${u.pathname}), пропускаю: ${e.message}`);
        return null;
      }
      return json;
    }
  }

  function waitForResume() {
    state.paused = true;
    renderPanel();
    return new Promise((resolve) => {
      state.resumeResolve = resolve;
    });
  }

  // ---------------------------------------------------------------------
  // Построение .eml (RFC822) из одного письма неизвестной JSON-структуры
  // ---------------------------------------------------------------------

  function extractMessage(msgJson, threadId, idx) {
    const subject = findFieldDeep(msgJson, /^subject$/i) || '(без темы)';
    const fromRaw = findFieldDeep(msgJson, /^(from|sender|fromcorrespondent)$/i);
    const toRaw = findFieldDeep(msgJson, /^(to|tocorrespondents?|recipients)$/i);
    const ccRaw = findFieldDeep(msgJson, /^(cc|cccorrespondents?)$/i);
    const dateRaw = findFieldDeep(msgJson, /^(date|senddate|deliverydate|receivedate)$/i);
    const html = findFieldDeep(msgJson, /^(html|bodyhtml|contenthtml)$/i);
    const text = findFieldDeep(msgJson, /^(text|bodytext|plain|contenttext)$/i);
    const msgIdRaw = findFieldDeep(msgJson, /^(messageid|message_id)$/i);
    const attaches = findFieldDeep(msgJson, /^(attach(es|ments)?)$/i) || [];

    const date = parseDateSmart(dateRaw);
    const from = formatAddress(fromRaw) || (state.ownEmail || '');
    const to = formatAddress(toRaw);
    const cc = formatAddress(ccRaw);
    const domain = (state.ownEmail || 'sestroclinic.ru').split('@').pop() || 'sestroclinic.ru';
    const messageId = msgIdRaw ? String(msgIdRaw) : `<export-${threadId}-${idx}@${domain}>`;

    return { subject, from, to, cc, date, html, text, messageId, attaches, raw: msgJson };
  }

  async function fetchAttachmentBase64(att) {
    const url = att.url || att.href || att.downloadUrl || att.download_url || att.link;
    if (!url) return null;
    try {
      const resp = await fetch(new URL(url, location.href).toString(), { credentials: 'include' });
      if (!resp.ok) return null;
      const buf = new Uint8Array(await resp.arrayBuffer());
      return bytesToBase64(buf);
    } catch (e) {
      return null;
    }
  }

  async function buildEml(msg) {
    const boundary = `----mks-${Math.random().toString(16).slice(2)}${Date.now().toString(16)}`;
    const headers = [];
    headers.push(`From: ${msg.from}`);
    if (msg.to) headers.push(`To: ${msg.to}`);
    if (msg.cc) headers.push(`Cc: ${msg.cc}`);
    headers.push(`Subject: ${encodeHeaderWord(msg.subject)}`);
    headers.push(`Date: ${rfc2822Date(msg.date)}`);
    headers.push(`Message-ID: ${msg.messageId}`);
    headers.push('MIME-Version: 1.0');

    const attachParts = [];
    for (const att of msg.attaches) {
      const b64 = await fetchAttachmentBase64(att);
      if (!b64) {
        logLine(`Вложение "${att.name || att.filename || '?'}" пропущено (нет ссылки на скачивание в ответе API)`);
        continue;
      }
      const filename = att.name || att.filename || 'attachment.bin';
      const mime = att.contentType || att.mimeType || att.type || 'application/octet-stream';
      attachParts.push(
        `--${boundary}\r\n` +
        `Content-Type: ${mime}; name="${encodeHeaderWord(filename)}"\r\n` +
        `Content-Disposition: attachment; filename="${encodeHeaderWord(filename)}"\r\n` +
        `Content-Transfer-Encoding: base64\r\n\r\n` +
        `${base64Wrap(b64)}\r\n`
      );
    }

    const altBoundary = `----mks-alt-${Math.random().toString(16).slice(2)}`;
    const bodyParts = [];
    if (msg.text) {
      bodyParts.push(`--${altBoundary}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n${base64Wrap(utf8ToBase64(msg.text))}\r\n`);
    }
    if (msg.html) {
      bodyParts.push(`--${altBoundary}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n${base64Wrap(utf8ToBase64(msg.html))}\r\n`);
    }
    if (!msg.text && !msg.html) {
      bodyParts.push(`--${altBoundary}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n${base64Wrap(utf8ToBase64('(тело письма не найдено в ответе API)'))}\r\n`);
    }
    const alternative = `--${boundary}\r\nContent-Type: multipart/alternative; boundary="${altBoundary}"\r\n\r\n` +
      bodyParts.join('') + `--${altBoundary}--\r\n`;

    let full;
    if (attachParts.length) {
      headers.push(`Content-Type: multipart/mixed; boundary="${boundary}"`);
      full = headers.join('\r\n') + '\r\n\r\n' + alternative + attachParts.join('') + `--${boundary}--\r\n`;
    } else {
      // без вложений можно сразу multipart/alternative верхним уровнем
      headers.push(`Content-Type: multipart/alternative; boundary="${altBoundary}"`);
      full = headers.join('\r\n') + '\r\n\r\n' + bodyParts.join('') + `--${altBoundary}--\r\n`;
    }

    if (state.debug) {
      full += `\r\nX-MKS-Raw-Json-Debug: см. отдельный файл в консоли (window.${NS}.log)\r\n`;
      console.debug('[MKSMailExport] raw message json', msg.raw);
    }

    return full;
  }

  // mboxrd-экранирование строк тела, начинающихся с "From "
  function escapeMboxBody(text) {
    return text.split('\n').map((line) => line.replace(/^(>*From )/, '>$1')).join('\n');
  }

  function emlToMboxEntry(eml, fromAddr, date) {
    const envelope = `From ${(fromAddr || 'MAILER-DAEMON').replace(/\s+/g, '_')} ${asctime(date)}`;
    // eml у нас с \r\n, mbox традиционно с \n — приводим к \n и экранируем тело
    const unixEml = eml.replace(/\r\n/g, '\n');
    const sepIdx = unixEml.indexOf('\n\n');
    const head = sepIdx === -1 ? unixEml : unixEml.slice(0, sepIdx);
    const body = sepIdx === -1 ? '' : unixEml.slice(sepIdx + 2);
    return `${envelope}\n${head}\n\n${escapeMboxBody(body)}\n\n`;
  }

  // ---------------------------------------------------------------------
  // Скачивание накопленного .mbox куска
  // ---------------------------------------------------------------------

  function downloadMboxChunk(folderLabel, partNum, content) {
    const blob = new Blob([content], { type: 'application/mbox' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${safeFileNamePart(state.ownEmail || 'mailbox')}_${safeFileNamePart(folderLabel)}_part${partNum}.mbox`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
    logLine(`Сохранён файл: ${a.download}`);
  }

  // ---------------------------------------------------------------------
  // IndexedDB — прогресс, чтобы можно было продолжить после перезагрузки
  // ---------------------------------------------------------------------

  const dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open('mks-mail-export', 1);
    req.onupgradeneeded = () => {
      req.result.createObjectStore('progress');
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });

  async function idbGet(key) {
    const db = await dbPromise;
    return new Promise((resolve) => {
      const tx = db.transaction('progress', 'readonly').objectStore('progress').get(key);
      tx.onsuccess = () => resolve(tx.result);
      tx.onerror = () => resolve(undefined);
    });
  }

  async function idbSet(key, value) {
    const db = await dbPromise;
    return new Promise((resolve) => {
      const tx = db.transaction('progress', 'readwrite').objectStore('progress').put(value, key);
      tx.onsuccess = () => resolve();
      tx.onerror = () => resolve();
    });
  }

  // ---------------------------------------------------------------------
  // Основной цикл экспорта одной папки
  // ---------------------------------------------------------------------

  async function exportFolder(folderKey) {
    const folder = state.folders.get(folderKey);
    if (!folder) return;
    if (!state.listTemplate) {
      logLine('Не подслушан шаблон списка писем — открой эту папку в интерфейсе и полистай список вниз.');
      return;
    }
    if (!state.threadTemplate) {
      logLine('Не подслушан шаблон письма — открой любое письмо в интерфейсе.');
      return;
    }

    const progressKey = `folder:${folderKey}`;
    const progress = (await idbGet(progressKey)) || { offset: 0, processedIds: [], part: 1, done: false };
    const processedSet = new Set(progress.processedIds);
    let buffer = '';
    let bufferCount = 0;
    const FLUSH_EVERY = 300; // писем на файл — компромисс между размером файла и памятью

    logLine(`Начинаю экспорт папки "${folder.label}" с offset=${progress.offset}`);

    while (state.running && !progress.done) {
      if (state.paused) { await sleep(500); continue; }

      const overrides = {};
      for (const [k, v] of folder.sampleUrl.searchParams.entries()) overrides[k] = v;
      overrides[state.listTemplate.offsetParam] = progress.offset;
      if (state.listTemplate.limitParam) overrides[state.listTemplate.limitParam] = state.listTemplate.limitValue;

      const listJson = await apiGet(state.listTemplate.urlObj, overrides);
      await sleep(400 + Math.random() * 300);
      if (!listJson) { progress.done = true; break; }

      const items = findMessageArray(listJson) || [];
      if (items.length === 0) { progress.done = true; break; }

      for (const item of items) {
        if (!state.running) break;
        while (state.paused) await sleep(500);

        const threadId = findFieldDeep(item, /^(id|threadid|thread_id|uidl)$/i);
        if (threadId == null) continue;
        const idStr = String(threadId);
        if (processedSet.has(idStr)) continue;

        const threadJson = await apiGet(state.threadTemplate.urlObj, { id: idStr });
        await sleep(400 + Math.random() * 300);
        if (!threadJson) { processedSet.add(idStr); continue; }

        const messagesArr = findFieldDeep(threadJson, /^messages$/i);
        const messages = Array.isArray(messagesArr) && messagesArr.length ? messagesArr : [threadJson];

        for (let i = 0; i < messages.length; i++) {
          const msg = extractMessage(messages[i], idStr, i);
          const eml = await buildEml(msg);
          buffer += emlToMboxEntry(eml, msg.from, msg.date);
          bufferCount++;
        }

        processedSet.add(idStr);
        progress.processedIds = [...processedSet];
        // progress.offset (курсор страницы) обновляется только после полной
        // страницы, см. ниже — дедупликация внутри страницы идёт через processedIds.

        if (bufferCount >= FLUSH_EVERY) {
          downloadMboxChunk(folder.label, progress.part, buffer);
          progress.part += 1;
          buffer = '';
          bufferCount = 0;
          await idbSet(progressKey, progress);
        }
        renderPanel();
      }

      progress.offset = (overrides[state.listTemplate.offsetParam] || 0) +
        (state.listTemplate.limitParam ? state.listTemplate.limitValue : items.length);
      await idbSet(progressKey, progress);

      if (state.listTemplate.limitParam && items.length < state.listTemplate.limitValue) {
        progress.done = true;
      }
    }

    if (bufferCount > 0) {
      downloadMboxChunk(folder.label, progress.part, buffer);
      progress.part += 1;
    }
    await idbSet(progressKey, progress);
    logLine(`Готово: папка "${folder.label}" (обработано писем: ${processedSet.size}).`);
  }

  // ---------------------------------------------------------------------
  // Панель управления (UI)
  // ---------------------------------------------------------------------

  let panelEl, logEl, foldersEl, statusEl;

  function buildPanel() {
    panelEl = document.createElement('div');
    panelEl.id = 'mks-mail-export-panel';
    panelEl.style.cssText = `
      position: fixed; right: 12px; bottom: 12px; width: 380px; max-height: 70vh;
      background: #1e1e1e; color: #eee; font: 12px/1.4 monospace; z-index: 999999;
      border: 1px solid #444; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,.5);
      display: flex; flex-direction: column; overflow: hidden;
    `;
    panelEl.innerHTML = `
      <div id="mks-header" style="cursor:move;background:#2d2d2d;padding:8px;font-weight:bold;display:flex;justify-content:space-between;">
        <span>📬 Экспорт почты sestroclinic</span>
        <span id="mks-collapse" style="cursor:pointer;">_</span>
      </div>
      <div id="mks-body" style="padding:8px;overflow:auto;">
        <div id="mks-status" style="margin-bottom:6px;"></div>
        <div style="margin-bottom:6px;">
          <label><input type="checkbox" id="mks-debug"> debug (дамп сырого JSON в консоль)</label>
        </div>
        <div id="mks-folders" style="margin-bottom:6px;"></div>
        <div style="margin-bottom:6px;">
          <button id="mks-resume">▶ Продолжить (после обновления токена)</button>
          <button id="mks-stop">⏹ Остановить всё</button>
        </div>
        <div id="mks-log" style="background:#111;padding:4px;height:160px;overflow:auto;white-space:pre-wrap;"></div>
      </div>
    `;
    document.documentElement.appendChild(panelEl);

    logEl = panelEl.querySelector('#mks-log');
    foldersEl = panelEl.querySelector('#mks-folders');
    statusEl = panelEl.querySelector('#mks-status');

    panelEl.querySelector('#mks-debug').addEventListener('change', (e) => { state.debug = e.target.checked; });
    panelEl.querySelector('#mks-stop').addEventListener('click', () => { state.running = false; logLine('Остановлено пользователем.'); });
    panelEl.querySelector('#mks-resume').addEventListener('click', () => {
      state.paused = false;
      if (state.resumeResolve) { state.resumeResolve(); state.resumeResolve = null; }
      renderPanel();
    });

    let collapsed = false;
    panelEl.querySelector('#mks-collapse').addEventListener('click', () => {
      collapsed = !collapsed;
      panelEl.querySelector('#mks-body').style.display = collapsed ? 'none' : 'block';
    });
    makeDraggable(panelEl, panelEl.querySelector('#mks-header'));
  }

  function makeDraggable(el, handle) {
    let sx, sy, ex, ey, dragging = false;
    handle.addEventListener('mousedown', (e) => {
      dragging = true; sx = e.clientX; sy = e.clientY;
      const r = el.getBoundingClientRect(); ex = r.left; ey = r.top;
      e.preventDefault();
    });
    window.addEventListener('mousemove', (e) => {
      if (!dragging) return;
      el.style.left = `${ex + (e.clientX - sx)}px`;
      el.style.top = `${ey + (e.clientY - sy)}px`;
      el.style.right = 'auto'; el.style.bottom = 'auto';
    });
    window.addEventListener('mouseup', () => { dragging = false; });
  }

  function renderLog() {
    if (!logEl) return;
    logEl.textContent = state.log.slice(-200).join('\n');
    logEl.scrollTop = logEl.scrollHeight;
  }

  function renderPanel() {
    if (!statusEl) return;
    statusEl.innerHTML = `
      Ящик: <b>${state.ownEmail || '?'}</b><br>
      Шаблон списка писем: ${state.listTemplate ? '✅ найден' : '❌ не найден — полистай список писем'}<br>
      Шаблон письма: ${state.threadTemplate ? '✅ найден' : '❌ не найден — открой письмо'}<br>
      Статус: ${state.paused ? '⏸ на паузе (нужен токен)' : (state.running ? '▶ работает' : '⏹ остановлен')}
    `;

    foldersEl.innerHTML = '';
    for (const [key, folder] of state.folders.entries()) {
      const row = document.createElement('div');
      row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;';
      row.innerHTML = `<span title="${key}">${folder.label.slice(0, 40)} (${folder.count ?? '?'})</span>`;
      const btn = document.createElement('button');
      btn.textContent = '⬇ Экспорт';
      btn.addEventListener('click', () => {
        state.running = true;
        exportFolder(key);
      });
      row.appendChild(btn);
      foldersEl.appendChild(row);
    }
    if (state.folders.size === 0) {
      foldersEl.textContent = 'Папки появятся здесь, когда ты откроешь их в интерфейсе почты.';
    }
  }

  // ---------------------------------------------------------------------
  // Старт
  // ---------------------------------------------------------------------

  installSniffer();

  function boot() {
    buildPanel();
    renderPanel();
    logLine('Скрипт запущен. Открой папку и полистай письма, затем открой одно письмо — так скрипт увидит нужные запросы.');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
