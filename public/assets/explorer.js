// Live API explorer on dataset pages ("Try it"). Loaded only there; the page works without it.
// Requests go directly from the browser when the API allows it (CORS, GET, no special headers),
// otherwise through POST {base}/explore, this site's locked-down proxy (see docs/ARCHITECTURE.md).
(function () {
  'use strict';

  var RENDER_CAP = 200 * 1024;      // characters rendered into the page
  var DIRECT_CAP = 2 * 1024 * 1024; // bytes read from a direct response
  var DIRECT_TIMEOUT = 15000;
  var PROXY_TIMEOUT = 30000;

  function init() {
    var form = document.querySelector('[data-explorer]');
    if (!form || !window.fetch || !window.JSON || !window.URL) return;
    var cfg;
    try { cfg = JSON.parse(form.querySelector('[data-explorer-config]').textContent); } catch (e) { return; }

    var $ = function (sel) { return form.querySelector(sel); };
    var urlInput = $('[data-x-url]');
    var methodSelect = $('[data-x-method]');
    var bodyWrap = $('[data-x-body-wrap]');
    var bodyInput = $('[data-x-body]');
    var sendBtn = $('[data-x-send]');
    var transportBox = $('[data-x-transport]');
    var hint = $('[data-x-hint]');
    var curlBtn = $('[data-x-curl]');
    var result = document.querySelector('[data-x-result]');
    var nojs = document.querySelector('[data-x-nojs]');

    var busy = false;
    var chosen = null; // transport picked by the user, or null for the default

    form.hidden = false;
    if (nojs) nojs.hidden = true;

    function method() { return methodSelect ? methodSelect.value : 'GET'; }
    function canDirect(m) { return cfg.cors && !cfg.requiredHeaders && m === 'GET'; }
    function canProxy() { return cfg.mode === 'proxy'; }
    function transport() {
      var m = method();
      if (chosen === 'proxy' && canProxy()) return 'proxy';
      if (chosen === 'direct' && canDirect(m)) return 'direct';
      return canDirect(m) ? 'direct' : (canProxy() ? 'proxy' : null);
    }

    /* ---------- transport choice ---------- */
    function renderTransport() {
      var m = method();
      var t = transport();
      transportBox.textContent = '';
      if (canDirect(m) && canProxy()) {
        var group = el('div', 'x-seg');
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-label', 'How the request is sent');
        [['direct', 'Direct from your browser'], ['proxy', 'Via this site’s proxy']].forEach(function (opt) {
          var label = el('label', 'x-opt');
          var input = document.createElement('input');
          input.type = 'radio';
          input.name = 'x-transport';
          input.value = opt[0];
          input.checked = t === opt[0];
          input.addEventListener('change', function () { chosen = input.value; renderTransport(); validate(); });
          label.appendChild(input);
          label.appendChild(document.createTextNode(opt[1]));
          group.appendChild(label);
        });
        transportBox.appendChild(group);
      } else {
        var why;
        if (t === 'direct') why = 'Sent directly from your browser.';
        else if (t === 'proxy') {
          why = !cfg.cors ? 'Sent via this site’s proxy: this API does not allow calls from browser JavaScript (CORS).'
            : cfg.requiredHeaders ? 'Sent via this site’s proxy: this API needs headers a browser cannot set.'
            : 'Sent via this site’s proxy: browsers only send GET requests directly here.';
        } else why = 'This request cannot be sent from here (the proxy does not carry requests for this API).';
        transportBox.appendChild(el('p', 'x-via', why));
      }
    }

    /* ---------- validation ---------- */
    // Spaces and non-ASCII characters are percent-encoded; control characters are refused.
    function cleanUrl(raw) {
      var s = raw.trim();
      if (/[\u0000-\u001f\u007f]/.test(s)) throw new Error('The URL contains line breaks or control characters.');
      if (s.indexOf('\\') !== -1) throw new Error('Backslashes are not allowed in the URL.');
      return s.replace(/[^\x21-\x7e]/gu, function (ch) { return encodeURIComponent(ch); });
    }

    function problem() {
      var raw = urlInput.value;
      var m = method();
      var t = transport();
      if (raw.trim() === '') return 'Enter a URL.';
      var ph = raw.match(/\{[^{}]*\}/);
      if (ph) return 'Replace ' + ph[0] + ' in the URL with a real value first.';
      var url;
      try { url = cleanUrl(raw); } catch (e) { return e.message; }
      var u;
      try { u = new URL(url); } catch (e) { return 'This is not a valid absolute URL.'; }
      if (u.protocol !== 'https:') return 'Only https:// URLs can be sent.';
      if (u.username || u.password || /^[^/?#]*@/.test(url.replace(/^https:\/\//i, ''))) return 'URLs with a user name or password are not allowed.';
      if (cfg.hosts.indexOf(u.hostname) === -1) return 'The explorer only sends requests to this API’s own hosts: ' + cfg.hosts.join(', ') + '.';
      if (u.port && u.port !== '443') return 'Port ' + u.port + ' is not used by this API.';
      if (url.length > 2000) return 'The URL is too long (2,000 characters at most).';
      if (t === 'proxy' && cfg.key) {
        var params = new URLSearchParams(u.search);
        var personal = false;
        params.forEach(function (v, k) {
          if (k.toLowerCase() === cfg.key.name.toLowerCase() && v !== '' && v !== cfg.key.demo) personal = true;
        });
        if (personal) return 'Only the demo key can be sent through this site’s proxy. Personal keys are not proxied.';
      }
      if (m === 'POST' && bodyInput && new Blob([bodyInput.value]).size > cfg.maxBody) return 'The body is too large (' + cfg.maxBody.toLocaleString('en') + ' bytes at most).';
      if (!t) return 'This request cannot be sent from here.';
      return '';
    }

    function validate() {
      var p = problem();
      hint.textContent = p;
      hint.classList.toggle('bad', p !== '');
      sendBtn.disabled = busy;
      return p;
    }

    /* ---------- curl ---------- */
    function shellQuote(s) {
      if (s !== '' && /^[A-Za-z0-9_./:=@%+,-]+$/.test(s)) return s;
      return "'" + s.replace(/'/g, "'\\''") + "'";
    }
    function curlCommand() {
      var m = method();
      var url;
      try { url = cleanUrl(urlInput.value); } catch (e) { url = urlInput.value.trim(); }
      var lines = ['curl' + (m === 'POST' ? ' -X POST' : '') + ' ' + shellQuote(url)];
      var hasType = false;
      cfg.headers.forEach(function (h) {
        if (h[0].toLowerCase() === 'content-type') hasType = true;
        lines.push('-H ' + shellQuote(h[0] + ': ' + h[1]));
      });
      if (m === 'POST') {
        if (!hasType) lines.push('-H ' + shellQuote('Content-Type: ' + cfg.postType));
        if (bodyInput && bodyInput.value !== '') lines.push('--data ' + shellQuote(bodyInput.value));
      }
      return lines.join(' \\\n  ');
    }

    /* ---------- sending ---------- */
    function send(forced) {
      if (busy) return;
      if (forced) { chosen = forced; renderTransport(); }
      var p = validate();
      if (p) { urlInput.focus(); return; }
      var m = method();
      var req = { method: m, url: cleanUrl(urlInput.value), body: m === 'POST' && bodyInput ? bodyInput.value : '' };
      var t = transport();
      setBusy(true);
      var started = performance.now();
      (t === 'direct' ? sendDirect(req) : sendProxy(req)).then(function (res) {
        if (res.duration_ms == null) res.duration_ms = Math.round(performance.now() - started);
        renderResult(res);
      }, function (err) {
        renderError(err);
      }).then(function () { setBusy(false); });
    }

    function setBusy(on) {
      busy = on;
      sendBtn.disabled = on;
      sendBtn.textContent = on ? 'Sending…' : 'Send';
      form.setAttribute('aria-busy', on ? 'true' : 'false');
      if (on) {
        result.hidden = false;
        result.classList.add('loading');
      } else {
        result.classList.remove('loading');
      }
    }

    function withTimeout(ms) {
      var ctl = new AbortController();
      var timer = setTimeout(function () { ctl.abort(); }, ms);
      return { signal: ctl.signal, done: function () { clearTimeout(timer); } };
    }

    function readCapped(res, cap) {
      if (!res.body || !res.body.getReader) {
        return res.arrayBuffer().then(function (buf) {
          return { bytes: new Uint8Array(buf.slice(0, cap)), size: buf.byteLength, truncated: buf.byteLength > cap };
        });
      }
      var reader = res.body.getReader();
      var chunks = [];
      var size = 0;
      function pump() {
        return reader.read().then(function (r) {
          if (r.done) return false;
          chunks.push(r.value);
          size += r.value.byteLength;
          if (size > cap) { reader.cancel().catch(function () {}); return true; }
          return pump();
        });
      }
      return pump().then(function (truncated) {
        var out = new Uint8Array(Math.min(size, cap));
        var off = 0;
        chunks.forEach(function (c) {
          var n = Math.min(c.byteLength, out.length - off);
          if (n > 0) out.set(c.subarray(0, n), off);
          off += n;
        });
        return { bytes: out, size: Math.min(size, cap), truncated: truncated };
      });
    }

    function sendDirect(req) {
      var to = withTimeout(DIRECT_TIMEOUT);
      return fetch(req.url, { method: 'GET', mode: 'cors', credentials: 'omit', cache: 'no-store', redirect: 'follow', signal: to.signal })
        .then(function (res) {
          return readCapped(res, DIRECT_CAP).then(function (b) {
            to.done();
            var type = res.headers.get('content-type') || '';
            var text = null;
            if (!/^\s*(image|audio|video|font)\/|^\s*application\/(octet-stream|pdf|zip|gzip)/i.test(type)) {
              try { text = new TextDecoder('utf-8', { fatal: !b.truncated }).decode(b.bytes); } catch (e) { text = null; }
            }
            var headers = {};
            res.headers.forEach(function (v, k) { if (interesting(k)) headers[k] = v; });
            return {
              via: 'direct', status: res.status, status_text: res.statusText, headers: headers, size: b.size,
              truncated: b.truncated, body: text, content_type: type, final_url: res.url,
              redirects: res.redirected ? [{ location: res.url }] : [],
              note: text === null ? 'Binary response (' + (type || 'no content type') + ', ' + fmtBytes(b.size) + ') not shown.' : null
            };
          });
        }, function (e) {
          to.done();
          var err = new Error(e && e.name === 'AbortError'
            ? 'No response within ' + DIRECT_TIMEOUT / 1000 + ' seconds.'
            : 'Your browser could not complete the request. The API may not allow browser calls (CORS) for this endpoint, or the network failed.');
          err.title = e && e.name === 'AbortError' ? 'Timed out' : 'Request blocked or failed';
          err.retryProxy = canProxy();
          throw err;
        });
    }

    function sendProxy(req) {
      var to = withTimeout(PROXY_TIMEOUT);
      var payload = { slug: cfg.slug, method: req.method, url: req.url };
      if (req.method === 'POST') payload.body = req.body;
      return fetch(cfg.proxy, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: to.signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload)
      }).then(function (res) {
        return res.text().then(function (text) {
          to.done();
          var j = null;
          try { j = JSON.parse(text); } catch (e) { j = null; }
          if (!res.ok || !j || typeof j.status !== 'number' || j.type) {
            var err = new Error(j && j.detail ? j.detail : 'The proxy answered HTTP ' + res.status + '.');
            err.title = j && j.title ? j.title : 'Proxy error';
            err.status = res.status;
            if (res.status === 429) {
              var after = parseInt(res.headers.get('Retry-After') || '', 10);
              err.title = 'Rate limited';
              if (after > 0) err.message += ' Try again in ' + (after > 90 ? Math.ceil(after / 60) + ' minutes' : after + ' seconds') + '.';
            }
            throw err;
          }
          j.via = 'proxy';
          return j;
        });
      }, function (e) {
        to.done();
        var err = new Error(e && e.name === 'AbortError' ? 'The proxy did not answer in time.' : 'Could not reach this site’s proxy. Check your connection.');
        err.title = 'Proxy unreachable';
        throw err;
      });
    }

    function interesting(name) {
      return /^(content-type|content-length|location|retry-after|cache-control|etag|last-modified)$|^(x-ratelimit|ratelimit|x-rate-limit)/i.test(name);
    }

    /* ---------- rendering ---------- */
    function el(tag, cls, text) {
      var n = document.createElement(tag);
      if (cls) n.className = cls;
      if (text != null) n.textContent = text;
      return n;
    }
    function fmtBytes(n) {
      if (n < 1024) return n + ' B';
      if (n < 1024 * 1024) return (n / 1024).toFixed(n < 10240 ? 1 : 0) + ' KB';
      return (n / 1048576).toFixed(1) + ' MB';
    }
    function prettyBody(text, type) {
      var t = text.replace(/^﻿/, '');
      if (/json/i.test(type) || /^\s*[\[{]/.test(t)) {
        try { return { text: JSON.stringify(JSON.parse(t), null, 2), json: true }; } catch (e) { /* not (complete) JSON */ }
      }
      return { text: text, json: false };
    }

    function renderResult(r) {
      result.textContent = '';
      result.hidden = false;
      var head = el('div', 'x-status');
      var code = el('span', 'x-code ' + (r.status < 300 ? 'ok' : r.status < 400 ? 'redir' : 'bad'), r.status + (r.status_text ? ' ' + r.status_text : ''));
      head.appendChild(code);
      head.appendChild(el('span', 'num', r.duration_ms + ' ms'));
      head.appendChild(el('span', 'num', fmtBytes(r.size || 0) + (r.truncated ? '+' : '')));
      head.appendChild(el('span', 'x-how', r.via === 'direct' ? 'direct from your browser' : 'via this site’s proxy'));
      if (r.cached) head.appendChild(el('span', 'x-how', 'cached (≤ 30 s)'));
      result.appendChild(head);

      var names = Object.keys(r.headers || {});
      if (names.length) {
        var dl = el('dl', 'x-headers');
        names.forEach(function (k) {
          var row = el('div');
          row.appendChild(el('dt', null, k));
          row.appendChild(el('dd', null, r.headers[k]));
          dl.appendChild(row);
        });
        result.appendChild(dl);
      }

      var notes = [];
      (r.redirects || []).forEach(function (x) {
        notes.push('Redirected' + (x.status ? ' (' + x.status + ')' : '') + ' to ' + x.location);
      });
      if (r.note) notes.push(r.note);
      if (r.truncated) notes.push(r.via === 'direct'
        ? 'Only the first ' + fmtBytes(DIRECT_CAP) + ' of the response were read.'
        : 'The proxy stopped reading after ' + fmtBytes(r.size) + '; the response is longer.');

      var shownText = '';
      var full = '';
      var isJson = false;
      if (r.body != null) {
        var p = prettyBody(r.body, r.content_type || '');
        full = p.text;
        isJson = p.json;
        shownText = full.length > RENDER_CAP ? full.slice(0, RENDER_CAP) : full;
        if (full.length > RENDER_CAP) notes.push('Truncated: showing the first ' + fmtBytes(RENDER_CAP) + ' of ' + fmtBytes(full.length) + '.');
      }
      notes.forEach(function (n) { result.appendChild(el('p', 'x-msg', n)); });

      if (r.body != null) {
        var box = el('div', 'code');
        var bh = el('div', 'code-head');
        bh.appendChild(el('span', 'micro', 'Response' + (isJson ? ' · JSON' : r.content_type ? ' · ' + r.content_type.split(';')[0] : '')));
        var copy = el('button', 'copy', 'Copy');
        copy.type = 'button';
        copy.addEventListener('click', function () { copyText(full, copy); });
        bh.appendChild(copy);
        box.appendChild(bh);
        var pre = el('pre', 'response x-pre');
        pre.appendChild(el('code', null, shownText === '' ? '(empty body)' : shownText));
        box.appendChild(pre);
        result.appendChild(box);
      }
    }

    function renderError(err) {
      result.textContent = '';
      result.hidden = false;
      var head = el('div', 'x-status');
      head.appendChild(el('span', 'x-code bad', err.title || 'Error'));
      if (err.status) head.appendChild(el('span', 'num', 'HTTP ' + err.status));
      result.appendChild(head);
      result.appendChild(el('p', 'x-msg', err.message || 'Something went wrong.'));
      if (err.retryProxy) {
        var b = el('button', 'x-retry', 'Retry via this site’s proxy');
        b.type = 'button';
        b.addEventListener('click', function () { send('proxy'); });
        result.appendChild(b);
      }
    }

    /* ---------- clipboard ---------- */
    function copyText(text, btn) {
      var done = function () {
        var old = btn.textContent;
        btn.textContent = 'Copied';
        btn.classList.add('done');
        setTimeout(function () { btn.textContent = old; btn.classList.remove('done'); }, 1600);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () { if (fallbackCopy(text)) done(); });
      } else if (fallbackCopy(text)) done();
    }
    function fallbackCopy(text) {
      var ta = el('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      ta.remove();
      return ok;
    }

    /* ---------- wiring ---------- */
    function syncMethod() {
      if (bodyWrap) bodyWrap.hidden = method() !== 'POST';
      renderTransport();
      validate();
    }

    form.addEventListener('submit', function (ev) { ev.preventDefault(); send(); });
    [urlInput, bodyInput].forEach(function (input) {
      if (!input) return;
      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && (ev.metaKey || ev.ctrlKey)) { ev.preventDefault(); send(); }
      });
      input.addEventListener('input', validate);
    });
    if (methodSelect) methodSelect.addEventListener('change', syncMethod);
    curlBtn.addEventListener('click', function () { copyText(curlCommand(), curlBtn); });

    form.querySelectorAll('[data-x-endpoint]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ep = cfg.endpoints[parseInt(btn.getAttribute('data-x-endpoint'), 10)];
        if (!ep) return;
        urlInput.value = ep.url;
        if (methodSelect && cfg.methods.indexOf(ep.method) !== -1) {
          methodSelect.value = ep.method;
          if (ep.method === 'POST' && bodyInput && bodyInput.value === '' && cfg.example.method === 'POST') bodyInput.value = cfg.example.body;
        }
        syncMethod();
        urlInput.focus();
        var m = ep.url.match(/\{[^{}]*\}/);
        if (m) urlInput.setSelectionRange(m.index, m.index + m[0].length);
      });
    });

    syncMethod();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
