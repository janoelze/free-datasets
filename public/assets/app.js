// Progressive enhancement: instant filtering on the home page, copy buttons, theme toggle.
// Everything works without this file; filtering then submits the GET form.
(function () {
  'use strict';

  var root = document.documentElement;

  /* ---------- theme ---------- */
  function effectiveTheme() {
    var t = root.dataset.theme;
    if (t === 'light' || t === 'dark') return t;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function initTheme() {
    var btn = document.querySelector('[data-theme-toggle]');
    if (!btn) return;
    var label = function () {
      var next = effectiveTheme() === 'dark' ? 'light' : 'dark';
      btn.textContent = next === 'dark' ? 'Dark mode' : 'Light mode';
      btn.setAttribute('aria-label', 'Switch to ' + next + ' theme');
    };
    btn.hidden = false;
    label();
    btn.addEventListener('click', function () {
      var next = effectiveTheme() === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      try { localStorage.setItem('theme', next); } catch (e) { /* private mode */ }
      label();
    });
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) mq.addEventListener('change', label);
    }
  }

  /* ---------- copy buttons ---------- */
  function initCopy() {
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
      var target = document.querySelector(btn.getAttribute('data-copy'));
      if (!target) return;
      btn.hidden = false;
      btn.addEventListener('click', function () {
        var text = target.textContent;
        var done = function () {
          btn.textContent = 'Copied';
          btn.classList.add('done');
          setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('done'); }, 1600);
        };
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(target) && done(); });
        } else if (fallbackCopy(target)) {
          done();
        }
      });
    });
  }
  function fallbackCopy(el) {
    var range = document.createRange();
    range.selectNodeContents(el);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    sel.removeAllRanges();
    return ok;
  }

  /* ---------- "Use with your agent" panel ---------- */
  // Works as plain <details> + CSS tabs; this only lets Escape close it and return focus.
  function initAgentPanel() {
    var panel = document.querySelector('[data-agent]');
    if (!panel) return;
    panel.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Escape' || !panel.open) return;
      ev.preventDefault();
      panel.open = false;
      var summary = panel.querySelector('summary');
      if (summary) summary.focus();
    });
  }

  /* ---------- instant filtering (home) ---------- */
  function initFilters() {
    var form = document.querySelector('[data-filter-form]');
    if (!form || !window.fetch || !window.DOMParser || !window.URLSearchParams) return;
    var search = form.querySelector('input[name="q"]');
    var cache = new Map();
    var controller = null;
    var timer = null;
    var seq = 0;

    function formUrl() {
      var params = new URLSearchParams();
      new FormData(form).forEach(function (value, key) {
        value = String(value).trim();
        if (value === '') return;
        params.set(key, value); // later fields (visible controls) win over hidden ones
      });
      if (params.get('sort') === 'name') params.delete('sort');
      var qs = params.toString().replace(/%2C/gi, ',');
      return form.getAttribute('action') + (qs ? '?' + qs : '');
    }

    function load(url) {
      if (cache.has(url)) return Promise.resolve(cache.get(url));
      if (controller) controller.abort();
      controller = new AbortController();
      return fetch(url, { headers: { Accept: 'text/html' }, signal: controller.signal, credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) throw new Error('HTTP ' + res.status);
          return res.text();
        })
        .then(function (html) {
          if (cache.size > 60) cache.clear();
          cache.set(url, html);
          return html;
        });
    }

    function swap(doc, id) {
      var fresh = doc.getElementById(id);
      var old = document.getElementById(id);
      if (fresh && old) old.replaceWith(document.importNode(fresh, true));
    }

    function syncControls(doc) {
      var other = doc.querySelector('[data-filter-form]');
      if (!other) return;
      form.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
        var twin = other.querySelector('input[type="checkbox"][name="' + cb.name + '"]');
        if (twin) cb.checked = twin.checked;
      });
      form.querySelectorAll('select').forEach(function (sel) {
        var twin = other.querySelector('select[name="' + sel.name + '"]');
        if (twin) { sel.innerHTML = twin.innerHTML; sel.value = twin.value; }
      });
      var q = other.querySelector('input[name="q"]');
      if (q && search && document.activeElement !== search) search.value = q.value;
    }

    function update(url, opts) {
      opts = opts || {};
      var mine = ++seq;
      history.replaceState(null, '', url);
      var results = document.getElementById('results');
      if (results && !cache.has(url)) results.classList.add('loading');
      return load(url).then(function (html) {
        if (mine !== seq) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        swap(doc, 'results');
        swap(doc, 'rail-cats');
        swap(doc, 'hidden-filters');
        if (opts.sync) syncControls(doc);
        document.title = doc.title;
        var canonical = document.querySelector('link[rel="canonical"]');
        var freshCanonical = doc.querySelector('link[rel="canonical"]');
        if (canonical && freshCanonical) canonical.href = freshCanonical.href;
      }).catch(function (err) {
        if (err && err.name === 'AbortError') return;
        window.location.href = url; // fall back to a normal navigation
      }).then(function () {
        if (mine !== seq) return;
        var r = document.getElementById('results');
        if (r) r.classList.remove('loading');
      });
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      clearTimeout(timer);
      update(formUrl());
    });
    form.addEventListener('change', function (ev) {
      if (ev.target === search) return;
      clearTimeout(timer);
      update(formUrl());
    });
    if (search) {
      search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { update(formUrl()); }, 140);
      });
      search.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && search.value !== '') {
          ev.preventDefault();
          search.value = '';
          clearTimeout(timer);
          update(formUrl());
        }
      });
    }

    // Category links in the rail, chips and row category links filter in place. Links whose href
    // is a crawlable landing page (/c/x) carry the in-place filter URL in data-filter-href.
    document.addEventListener('click', function (ev) {
      var a = ev.target.closest && ev.target.closest('a[data-filter-link]');
      if (!a || ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
      var href = a.getAttribute('data-filter-href') || a.getAttribute('href');
      if (!href || !href.startsWith(form.getAttribute('action'))) return;
      ev.preventDefault();
      clearTimeout(timer);
      update(href, { sync: true }).then(function () {
        var head = document.getElementById('results-count');
        if (a.closest('#rail-cats') && head && window.innerWidth > 760) return;
        if (head && head.getBoundingClientRect().top < 0) head.scrollIntoView({ block: 'start' });
      });
    });

    // "/" focuses the search box (unless the user is typing somewhere).
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== '/' || ev.metaKey || ev.ctrlKey || ev.altKey) return;
      var t = ev.target;
      if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
      if (!search) return;
      ev.preventDefault();
      search.focus();
      search.select();
    });
  }

  function init() {
    initTheme();
    initCopy();
    initAgentPanel();
    initFilters();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
