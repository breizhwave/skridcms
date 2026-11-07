/*
 * Skrid bar: browse the site, switch to "Edit texts", click a text, type, save.
 * Runs inside the real page (served by /skrid/view.php). Its own interface lives in a shadow root,
 * so the site's CSS cannot touch it and it cannot touch the site.
 */
(() => {
  'use strict';

  const script = document.currentScript;
  let cfg;
  try { cfg = JSON.parse(script.dataset.skridConfig); } catch (e) { return; }
  const T = cfg.texts || {};
  const fmt = (s, ...a) => a.reduce((r, v) => r.replace('%s', v).replace('%d', v), s);

  // ------------------------------------------------------------------ state
  // A fixed text repeated by a PHP loop shows several times with the same number: not editable on the page.
  const seen = {};
  document.querySelectorAll('[data-skrid]').forEach((el) => { seen[el.dataset.skrid] = (seen[el.dataset.skrid] || 0) + 1; });
  document.querySelectorAll('[data-skrid]').forEach((el) => { if (seen[el.dataset.skrid] > 1) el.removeAttribute('data-skrid'); });
  const els = new Map();                      // n → element
  document.querySelectorAll('[data-skrid]').forEach((el) => els.set(Number(el.dataset.skrid), el));
  const baseline = new Map();                 // n → innerHTML before the first edit
  let hash = cfg.hash;
  let active = null;
  let busy = false;
  let savedRange = null;
  const store = {
    get(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* private mode */ } },
  };
  let mode = cfg.backup ? 'browse' : (cfg.mode === 'edit' ? 'edit' : (store.get('skrid-mode') || 'browse'));
  const html = document.documentElement;
  html.classList.add('skrid-on');

  // ------------------------------------------------------------------ interface (shadow DOM)
  const host = document.createElement('skrid-ui');
  host.setAttribute('style', 'all:initial;position:fixed;inset:0;z-index:2147483647;pointer-events:none;');
  document.body.appendChild(host);
  const ui = host.attachShadow({ mode: 'open' });

  const icon = {
    logo: '<svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9" fill="#2563eb"/><path d="M8.5 20.5c2.6-7.5 5.6-8 6.6-3.6s4 5.2 8.4-3.4" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    bold: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/></svg>',
    italic: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5h8M6 19h8M14.5 5l-5 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    link: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    unlink: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66M4 4l16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    clear: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h12M12 5l-3 14M5 19h6M15 14l5 5M20 14l-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    revert: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h11a5 5 0 0 1 0 10H9M4 9l4-4M4 9l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    admin: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM4 9h16M9 9v10" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>',
    save: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    logout: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h5v16h-5M10 8l-4 4 4 4M6 12h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    data: '<svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="6" rx="7" ry="2.8" fill="none" stroke="currentColor" stroke-width="2"/><path d="M5 6v12c0 1.5 3.1 2.8 7 2.8s7-1.3 7-2.8V6M5 12c0 1.5 3.1 2.8 7 2.8s7-1.3 7-2.8" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
  };

  ui.innerHTML = `
<style>
  :host { all: initial; }
  * { box-sizing: border-box; }
  .wrap { position: fixed; left: 0; right: 0; bottom: 16px; display: flex; justify-content: center; padding: 0 10px; pointer-events: none; }
  .short, .ico { display: none; }
  .bar, .tools, .toast {
    font: 500 13px/1.3 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #f8fafc;
    background: rgb(15 23 42 / 0.92); border: 1px solid rgb(255 255 255 / 0.12);
    box-shadow: 0 10px 30px -10px rgb(0 0 0 / 0.55), 0 2px 6px rgb(0 0 0 / 0.25);
    -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px); pointer-events: auto;
  }
  .bar { display: flex; align-items: center; gap: 6px; max-width: 100%; padding: 6px; border-radius: 999px; }
  .brand { display: flex; align-items: center; gap: 8px; padding: 0 10px 0 4px; font-weight: 700; letter-spacing: 0.01em; white-space: nowrap; }
  .brand svg { width: 24px; height: 24px; display: block; }
  .seg { display: flex; padding: 3px; border-radius: 999px; background: rgb(255 255 255 / 0.08); }
  button, a.b { all: unset; box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    min-height: 32px; padding: 0 12px; border-radius: 999px; cursor: pointer; white-space: nowrap; color: inherit; font: inherit; }
  button:hover, a.b:hover { background: rgb(255 255 255 / 0.12); }
  button:focus-visible, a.b:focus-visible, input:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }
  .seg button[aria-pressed="true"] { background: #f8fafc; color: #0f172a; }
  .primary { background: #2563eb; color: #fff; font-weight: 600; }
  .primary:hover { background: #1d4ed8; }
  .primary[disabled] { background: rgb(255 255 255 / 0.1); color: rgb(248 250 252 / 0.45); cursor: default; }
  .icon { width: 36px; padding: 0; }
  .icon svg, button svg, a.b svg { width: 18px; height: 18px; display: block; }
  .status { padding: 0 8px; color: rgb(248 250 252 / 0.7); white-space: nowrap; }
  .status.dirty { color: #fbbf24; }
  .sep { width: 1px; height: 22px; background: rgb(255 255 255 / 0.15); margin: 0 2px; }
  .tools { position: fixed; display: flex; align-items: center; gap: 2px; padding: 4px; border-radius: 12px; }
  .tools[hidden], .toast[hidden], .linkrow[hidden], [hidden] { display: none !important; }
  .tools button { min-height: 32px; width: 34px; padding: 0; border-radius: 8px; }
  .tools button[aria-pressed="true"] { background: rgb(255 255 255 / 0.18); }
  .linkrow { display: flex; gap: 4px; align-items: center; padding-left: 4px; }
  .linkrow input { all: unset; box-sizing: border-box; width: 240px; height: 32px; padding: 0 10px; border-radius: 8px; background: rgb(255 255 255 / 0.1); color: #fff; font: inherit; }
  .linkrow button { width: auto; padding: 0 12px; }
  .tools .aa { font: 700 14px/1 Georgia, "Times New Roman", serif; letter-spacing: -0.02em; }
  .tools select { all: unset; box-sizing: border-box; height: 32px; max-width: 190px; padding: 0 28px 0 10px; border-radius: 8px;
    background: rgb(255 255 255 / 0.1) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%23f8fafc' stroke-width='1.5'/%3E%3C/svg%3E") no-repeat right 10px center / 10px 6px;
    color: #fff; font: inherit; line-height: 32px; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .tools select option { color: #0f172a; background: #fff; }
  .tools { flex-wrap: wrap; max-width: calc(100vw - 16px); }
  .menu { position: fixed; min-width: 200px; max-height: 60vh; overflow: auto; padding: 6px; border-radius: 12px;
    font: 500 13.5px/1.3 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #f8fafc;
    background: rgb(15 23 42 / 0.96); border: 1px solid rgb(255 255 255 / 0.12); pointer-events: auto;
    box-shadow: 0 16px 40px -12px rgb(0 0 0 / 0.6); }
  .menu .head { padding: 6px 10px 8px; color: rgb(248 250 252 / 0.6); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
  .menu button { display: flex; width: 100%; justify-content: flex-start; min-height: 34px; padding: 0 10px; border-radius: 8px; }
  .menu button[disabled] { opacity: 0.4; cursor: default; }
  .menu .rule { height: 1px; margin: 6px 4px; background: rgb(255 255 255 / 0.12); }
  .menu .here { display: block; width: 100%; min-height: 0; padding: 4px 10px; border-radius: 6px; text-align: left;
    font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, monospace; color: #bfdbfe; white-space: normal; overflow-wrap: anywhere; }
  .menu .here:hover { background: rgb(255 255 255 / 0.1); }
  .menu .here b { color: #f8fafc; font-weight: 700; }
  .menu .hint { padding: 6px 10px 4px; color: rgb(248 250 252 / 0.55); font-size: 12px; line-height: 1.4; }
  .menu .cls { margin-left: auto; padding-left: 16px; color: rgb(248 250 252 / 0.45); font: 12px ui-monospace, Menlo, monospace; }
  .toastwrap { position: fixed; left: 0; right: 0; bottom: 76px; display: flex; justify-content: center; padding: 0 12px; pointer-events: none; }
  .toast { display: flex; align-items: center; gap: 12px; max-width: 560px; padding: 10px 10px 10px 16px; border-radius: 14px; }
  .toast.error { border-color: rgb(248 113 113 / 0.6); }
  .toast .actions { display: flex; gap: 6px; flex-wrap: wrap; }
  .toast .actions button:first-child { background: #0d9488; }
  .toast .actions button:first-child:hover { background: #0f766e; }
  .toast button { min-height: 30px; padding: 0 12px; background: rgb(255 255 255 / 0.12); }
  @media (max-width: 720px) {
    .label, .long, .brand, .status { display: none; }
    .short, .ico { display: inline; }
    .ico svg { width: 18px; height: 18px; }
    .primary { width: 40px; padding: 0; }
    .seg button { padding: 0 10px; }
    .bar { gap: 4px; padding: 5px; overflow-x: auto; }
    .wrap { bottom: 10px; }
    .linkrow input { width: 150px; }
  }
</style>
<div class="toastwrap"><div class="toast" role="status" aria-live="polite" hidden><span class="msg"></span><span class="actions"></span></div></div>
<div class="tools" role="toolbar" aria-label="Skrid" hidden>
  <button type="button" data-cmd="bold" title="${T.bold} (Ctrl+B)" aria-label="${T.bold}">${icon.bold}</button>
  <button type="button" data-cmd="italic" title="${T.italic} (Ctrl+I)" aria-label="${T.italic}">${icon.italic}</button>
  <button type="button" data-cmd="link" title="${T.link} (Ctrl+K)" aria-label="${T.link}">${icon.link}</button>
  <button type="button" data-cmd="unlink" title="${T.unlink}" aria-label="${T.unlink}">${icon.unlink}</button>
  <button type="button" data-cmd="clear" title="${T.clear}" aria-label="${T.clear}">${icon.clear}</button>
  <button type="button" data-cmd="styles" title="${T.styles}" aria-label="${T.styles}"><span class="aa">Aa</span></button>
  <select class="blocksel" aria-label="${T.blockStyle}" title="${T.blockStyle}"></select>
  <span class="sep"></span>
  <button type="button" data-cmd="revert" title="${T.revert}" aria-label="${T.revert}">${icon.revert}</button>
  <span class="linkrow" hidden><input type="text" inputmode="url" placeholder="${T.linkPlaceholder}" aria-label="${T.link}"><button type="button" data-cmd="link-ok">${T.linkOk}</button></span>
</div>
<div class="menu" role="menu" aria-label="${T.styles}" hidden></div>
<div class="wrap"><div class="bar" role="region" aria-label="Skrid"></div></div>`;

  const bar = ui.querySelector('.bar');
  const tools = ui.querySelector('.tools');
  const linkrow = ui.querySelector('.linkrow');
  const linkInput = linkrow.querySelector('input');
  const toastEl = ui.querySelector('.toast');
  const menu = ui.querySelector('.menu');
  const blockSel = ui.querySelector('.blocksel');
  const esc = (v) => String(v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  // Text styles (from Skrid and from the site's settings).
  const styles = cfg.styles || { inline: [], block: [] };
  const blockUniverse = new Set(cfg.blockClasses || []);
  blockSel.innerHTML = `<option value="">${esc(T.blockNormal)}</option>`
    + styles.block.map((st, i) => `<option value="${i}">${esc(st.label)}</option>`).join('');
  if (!styles.block.length) blockSel.hidden = true;
  if (!styles.inline.length) tools.querySelector('[data-cmd="styles"]').hidden = true;

  function dataLink() {
    const d = cfg.data || [];
    if (!d.length) return '';
    const href = d.length === 1 ? d[0].url : `${cfg.admin}?page=${encodeURIComponent(cfg.page)}`;
    const title = d.map((x) => x.file).join(', ');
    return `<a class="b" href="${esc(href)}" title="${esc(title)}">${icon.data}<span class="label">${esc(d.length === 1 ? T.data : T.dataMany)}</span></a>`;
  }

  if (cfg.backup) {
    bar.innerHTML = `
      <span class="brand">${icon.logo}<span>Skrid</span></span>
      <span class="status">${fmt(T.backup, cfg.backup.date)}</span>
      <button type="button" class="primary" data-act="restore">${T.restore}</button>
      <a class="b" href="${cfg.view}?p=${encodeURIComponent(cfg.url)}">${T.backToPage}</a>
      <a class="b icon" href="${cfg.admin}?page=${encodeURIComponent(cfg.page)}" title="${T.admin}" aria-label="${T.admin}">${icon.admin}</a>`;
  } else {
    bar.innerHTML = `
      <span class="brand">${icon.logo}<span>Skrid</span></span>
      <span class="seg" role="group">
        <button type="button" data-mode="browse">${T.browse}</button>
        <button type="button" data-mode="edit"><span class="long">${T.edit}</span><span class="short">${T.editShort}</span></button>
      </span>
      <span class="status" aria-live="polite"></span>
      <button type="button" class="primary" data-act="save" title="${T.save} (Ctrl+S)" disabled><span class="ico">${icon.save}</span><span class="label">${T.save}</span></button>
      <button type="button" class="icon" data-act="discard" title="${T.discard}" aria-label="${T.discard}" hidden>${icon.revert}</button>
      <span class="sep"></span>
      ${dataLink()}
      <a class="b" href="${cfg.admin}?page=${encodeURIComponent(cfg.page)}" title="${T.admin}">${icon.admin}<span class="label">${T.admin}</span></a>
      <button type="button" class="icon" data-act="logout" title="${T.logout}" aria-label="${T.logout}">${icon.logout}</button>`;
  }
  const statusEl = bar.querySelector('.status');
  const saveBtn = bar.querySelector('[data-act="save"]');
  const discardBtn = bar.querySelector('[data-act="discard"]');

  // ------------------------------------------------------------------ messages
  let toastTimer = 0;
  function toast(msg, { error = false, actions = [], sticky = false, duration = 0 } = {}) {
    clearTimeout(toastTimer);
    toastEl.querySelector('.msg').textContent = msg;
    const box = toastEl.querySelector('.actions');
    box.innerHTML = '';
    actions.forEach(([label, fn]) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = label;
      b.addEventListener('click', () => { toastEl.hidden = true; fn(); });
      box.appendChild(b);
    });
    toastEl.classList.toggle('error', error);
    toastEl.hidden = false;
    if (!sticky) toastTimer = setTimeout(() => { toastEl.hidden = true; }, duration || (error ? 8000 : 3500));
  }

  // ------------------------------------------------------------------ changes
  const baseClass = new Map();                // n → paragraph-style classes before the first edit
  const styleClasses = (el) => [...el.classList].filter((c) => blockUniverse.has(c)).sort().join(' ');
  const innerChanged = () => [...baseline.keys()].filter((n) => els.get(n).innerHTML !== baseline.get(n));
  const classChanged = () => [...baseClass.keys()].filter((n) => styleClasses(els.get(n)) !== baseClass.get(n));
  const changed = () => [...new Set([...innerChanged(), ...classChanged()])];

  function refresh() {
    if (cfg.backup) return;
    const list = changed();
    els.forEach((el, n) => el.classList.toggle('skrid-changed', list.includes(n)));
    const k = list.length;
    statusEl.textContent = k === 0 ? (mode === 'edit' ? (cfg.count ? '' : T.none) : '') : (k === 1 ? T.changes1 : fmt(T.changesN, k));
    statusEl.classList.toggle('dirty', k > 0);
    saveBtn.disabled = k === 0 || busy;
    discardBtn.hidden = k === 0;
    bar.querySelectorAll('[data-mode]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.mode === mode)));
  }

  // ------------------------------------------------------------------ parts printed by the page's PHP code
  // view.php wraps them in <!--skrid-dyn--> … <!--/skrid-dyn-->: they get a green outline while editing,
  // and a click opens the data editor on the item that holds the clicked text.
  const dyn = [];
  if (cfg.php) {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_COMMENT);
    const starts = [];
    let c;
    while ((c = walker.nextNode())) {
      const v = c.nodeValue.trim();
      if (v === 'skrid-dyn') starts.push(c);
      else if (v === '/skrid-dyn' && starts.length) {
        const st = starts.pop();
        if (st.parentNode !== c.parentNode) continue;
        for (let n = st.nextSibling; n && n !== c; n = n.nextSibling) {
          if (n.nodeType === 1 && !n.querySelector('[data-skrid]') && !n.matches('script, style, link, meta, template')) {
            n.classList.add('skrid-dyn');
            dyn.push(n);
          }
        }
      }
    }
  }

  function dynClick(e) {
    const part = e.target.closest && e.target.closest('.skrid-dyn');
    if (!part) return;
    e.preventDefault();
    e.stopPropagation();
    if (active) deactivate();
    const item = e.target.closest('li, article, tr, figure, dd');
    const scope = item && part.contains(item) ? item : (e.target.closest('section, div') || part);
    const head = scope.querySelector('h1, h2, h3, h4, h5, h6') || scope;
    const q = (head.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 90);
    const files = cfg.data || [];
    if (!files.length) { toast(T.dynCode, { duration: 6000 }); return; }
    toast(fmt(T.dynHint, files.map((x) => x.name).join(', ')), {
      duration: 12000,
      actions: files.map((x) => [files.length > 1 ? `${T.dynEdit} · ${x.name}` : T.dynEdit,
        () => { location.href = `${x.url}${q ? `&q=${encodeURIComponent(q)}` : ''}`; }]),
    });
  }
  document.addEventListener('click', (e) => { if (mode === 'edit' && !inUi(e)) dynClick(e); }, true);

  // ------------------------------------------------------------------ modes
  function setMode(m, quiet = false) {
    if (cfg.backup) return;
    mode = m;
    store.set('skrid-mode', m);
    if (m !== 'edit' && active) deactivate();
    html.classList.toggle('skrid-editing', m === 'edit');
    if (m === 'edit' && !quiet) {
      if (!cfg.count && !dyn.length) toast(T.none);
      else {
        toast(dyn.length ? `${cfg.count ? T.hint : ''} ${T.dynFlash}`.trim() : T.hint, { duration: dyn.length ? 7000 : 0 });
        html.classList.add('skrid-flash');
        setTimeout(() => html.classList.remove('skrid-flash'), 1200);
      }
    }
    refresh();
  }

  // ------------------------------------------------------------------ editing one element
  const finishOnEnter = /^(H[1-6]|A|SPAN|SMALL|STRONG|EM|B|I|TIME|LABEL|DT|SUMMARY|LEGEND|CAPTION|TH|CITE|Q|MARK)$/;

  function activate(el) {
    if (active === el) return;
    if (active) deactivate();
    const n = Number(el.dataset.skrid);
    if (!baseline.has(n)) baseline.set(n, el.innerHTML);
    if (!baseClass.has(n)) baseClass.set(n, styleClasses(el));
    active = el;
    el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'true');
    placeTools();
  }

  function deactivate() {
    if (!active) return;
    active.removeAttribute('contenteditable');
    active.removeAttribute('spellcheck');
    active = null;
    hideLink();
    closeMenu();
    tools.hidden = true;
    refresh();
  }

  function placeTools() {
    if (!active) { tools.hidden = true; return; }
    tools.hidden = false;
    const r = active.getBoundingClientRect();
    const tw = tools.offsetWidth;
    const th = tools.offsetHeight;
    let top = r.top - th - 12;
    if (top < 8) top = Math.min(r.bottom + 12, window.innerHeight - th - 90);
    const left = Math.max(8, Math.min(r.left, window.innerWidth - tw - 8));
    tools.style.top = `${Math.round(top)}px`;
    tools.style.left = `${Math.round(left)}px`;
    tools.querySelector('[data-cmd="bold"]').setAttribute('aria-pressed', String(document.queryCommandState('bold')));
    tools.querySelector('[data-cmd="italic"]').setAttribute('aria-pressed', String(document.queryCommandState('italic')));
    const i = styles.block.findIndex((st) => st.classes.length && st.classes.every((c) => active.classList.contains(c)));
    blockSel.value = i < 0 ? '' : String(i);
  }

  function inUi(e) { return e.composedPath().includes(host); }

  document.addEventListener('mousedown', (e) => {
    if (mode !== 'edit' || inUi(e)) return;
    const el = e.target.closest && e.target.closest('[data-skrid]');
    if (el) activate(el);
  }, true);

  // In "Browse", a double-click on a text switches to editing it.
  document.addEventListener('dblclick', (e) => {
    if (mode === 'edit' || cfg.backup || inUi(e)) return;
    const el = e.target.closest && e.target.closest('[data-skrid]');
    if (!el) return;
    setMode('edit', true);
    activate(el);
    el.focus();
  });

  document.addEventListener('focusout', (e) => {
    if (!active || e.target !== active) return;
    setTimeout(() => {
      // Still in the element, or working in the toolbar (link field): keep it.
      if (document.activeElement === active || ui.activeElement) return;
      deactivate();
    }, 0);
  });

  document.addEventListener('keydown', (e) => {
    const mod = e.ctrlKey || e.metaKey;
    if (mod && e.key.toLowerCase() === 's' && !cfg.backup) {
      e.preventDefault();
      save();
      return;
    }
    if (!active || e.target !== active) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      active.blur();
      deactivate();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (!e.shiftKey && finishOnEnter.test(active.tagName)) {
        active.blur();
        deactivate();
      } else {
        document.execCommand('insertLineBreak');
      }
    } else if (mod && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      showLink();
    }
  }, true);

  document.addEventListener('paste', (e) => {
    if (!active || !active.contains(e.target)) return;
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text/plain').replace(/\r\n?/g, '\n');
    document.execCommand('insertText', false, text);
  }, true);
  ['dragover', 'drop'].forEach((t) => document.addEventListener(t, (e) => {
    if (active && active.contains(e.target)) e.preventDefault();
  }, true));

  document.addEventListener('input', (e) => {
    if (active && active.contains(e.target)) refresh();
  });
  document.addEventListener('selectionchange', () => { if (active) placeTools(); });
  window.addEventListener('scroll', () => { if (active) placeTools(); }, { passive: true });
  window.addEventListener('resize', () => { if (active) placeTools(); });

  // ------------------------------------------------------------------ toolbar
  tools.addEventListener('mousedown', (e) => {
    // Keep the caret and selection in the text while clicking a button.
    if (e.target.closest('button') && !e.target.closest('.linkrow')) e.preventDefault();
  });

  // Paragraph style: swap the block-style classes of the element, keep its other classes.
  blockSel.addEventListener('change', () => {
    if (!active) return;
    const el = active;
    blockUniverse.forEach((c) => el.classList.remove(c));
    const st = styles.block[Number(blockSel.value)];
    if (blockSel.value !== '' && st) st.classes.forEach((c) => el.classList.add(c));
    el.focus();
    refresh();
    placeTools();
  });

  // ------------------------------------------------------------------ inline styles (right-click on a selection, or Aa)
  const isStyled = (e) => styles.inline.some((st) => e.tagName.toLowerCase() === st.tag
    && st.classes.every((c) => e.classList.contains(c)) && (st.classes.length > 0 || e.classList.length === 0));

  function styledInSelection(range) {
    const found = new Set();
    if (!active || !range) return found;
    [range.startContainer, range.endContainer].forEach((node) => {
      let e = node.nodeType === 1 ? node : node.parentElement;
      while (e && e !== active && active.contains(e)) { if (isStyled(e)) found.add(e); e = e.parentElement; }
    });
    if (!range.collapsed) active.querySelectorAll('*').forEach((e) => { if (isStyled(e) && range.intersectsNode(e)) found.add(e); });
    return found;
  }

  function restoreSelection() {
    if (!active) return null;
    active.focus();
    const sel = document.getSelection();
    if (savedRange) { sel.removeAllRanges(); sel.addRange(savedRange); }
    return sel.rangeCount ? sel.getRangeAt(0) : null;
  }

  // Classes from the editable element down to the element under the cursor (outer first).
  function classPath(target) {
    const list = [];
    let e = target && (target.nodeType === 1 ? target : target.parentElement);
    while (e && active && active.contains(e)) {
      list.unshift(e);
      if (e === active) break;
      e = e.parentElement;
    }
    return list.slice(-5);
  }
  const ownClasses = (e) => [...e.classList].filter((c) => c !== 'skrid-changed');

  function openMenu(x, y, target) {
    if (!active) return;
    const sel = document.getSelection();
    savedRange = sel && sel.rangeCount && active.contains(sel.getRangeAt(0).commonAncestorContainer) ? sel.getRangeAt(0).cloneRange() : null;
    const hasText = savedRange && !savedRange.collapsed;
    const canRemove = styledInSelection(savedRange).size > 0;
    menu.innerHTML = `<div class="head">${esc(T.styles)}</div>`
      + styles.inline.map((st, i) => `<button type="button" role="menuitem" data-style="${i}"${hasText ? '' : ' disabled'}>${esc(st.label)}<span class="cls">${esc([st.tag === 'span' ? '' : st.tag, ...st.classes].filter(Boolean).join(' '))}</span></button>`).join('')
      + `<div class="rule"></div><button type="button" role="menuitem" data-style="remove"${canRemove ? '' : ' disabled'}>${esc(T.removeStyle)}</button>`;
    const path = classPath(target || (savedRange && savedRange.startContainer) || active);
    pathEls = path;
    menu.innerHTML += `<div class="rule"></div><div class="head">${esc(T.here)}</div>`
      + path.map((e, i) => `<button type="button" class="here" data-copy="${i}" title="${esc(T.copyClasses)}"><b>${e.tagName.toLowerCase()}</b>${ownClasses(e).length ? '.' + esc(ownClasses(e).join('.')) : ''}</button>`).join('')
      + `<div class="hint">${esc(T.nativeMenu)}</div>`;
    menu.hidden = false;
    const w = menu.offsetWidth;
    const h = menu.offsetHeight;
    menu.style.left = `${Math.max(8, Math.min(x, window.innerWidth - w - 8))}px`;
    menu.style.top = `${Math.max(8, Math.min(y, window.innerHeight - h - 8))}px`;
    if (!hasText && !canRemove) toast(T.selectFirst);
  }
  let pathEls = [];
  function closeMenu() { menu.hidden = true; }

  menu.addEventListener('mousedown', (e) => e.preventDefault());
  menu.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b || b.disabled) return;
    if (b.dataset.copy !== undefined) {
      const el = pathEls[Number(b.dataset.copy)];
      const text = el ? ownClasses(el).join(' ') : '';
      const done = () => toast(text ? `${T.copied} ${text}` : T.noClass);
      if (text && navigator.clipboard) navigator.clipboard.writeText(text).then(done, done); else done();
      return;
    }
    const range = restoreSelection();
    closeMenu();
    if (!range) return;
    if (b.dataset.style === 'remove') {
      styledInSelection(range).forEach((el) => el.replaceWith(...el.childNodes));
      active.normalize();
    } else {
      const st = styles.inline[Number(b.dataset.style)];
      if (!st || range.collapsed) return;
      // Wrap the selection ourselves: the browser's insertHTML turns classes into inline styles and spaces into &nbsp;.
      const wrapper = document.createElement(st.tag);
      if (st.classes.length) wrapper.className = st.classes.join(' ');
      wrapper.appendChild(range.extractContents());
      range.insertNode(wrapper);
      // Leftovers of a selection that crossed other inline tags (an empty <strong></strong>…).
      active.querySelectorAll('*').forEach((e) => {
        if (e !== wrapper && !e.childNodes.length && !['BR', 'WBR', 'IMG'].includes(e.tagName)) e.remove();
      });
      active.normalize();
      const sel = document.getSelection();
      const r = document.createRange();
      r.selectNodeContents(wrapper);
      sel.removeAllRanges();
      sel.addRange(r);
    }
    refresh();
    placeTools();
  });

  document.addEventListener('contextmenu', (e) => {
    if (mode !== 'edit' || cfg.backup || inUi(e) || e.shiftKey || e.altKey) return;   // Shift: the browser's menu (Inspect)
    const el = e.target.closest && e.target.closest('[data-skrid]');
    if (!el) return;
    e.preventDefault();
    if (active !== el) { activate(el); el.focus(); }
    openMenu(e.clientX, e.clientY, e.target);
  }, true);
  document.addEventListener('mousedown', (e) => { if (!menu.hidden && !inUi(e)) closeMenu(); }, true);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !menu.hidden) { e.stopPropagation(); closeMenu(); } }, true);
  window.addEventListener('scroll', () => { if (!menu.hidden) closeMenu(); }, { passive: true });
  tools.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b || !active) return;
    const cmd = b.dataset.cmd;
    if (cmd === 'bold' || cmd === 'italic') document.execCommand(cmd);
    else if (cmd === 'unlink') document.execCommand('unlink');
    else if (cmd === 'clear') document.execCommand('removeFormat');
    else if (cmd === 'link') { showLink(); return; }
    else if (cmd === 'styles') {
      const r = b.getBoundingClientRect();
      openMenu(r.left, r.bottom + 6);
      return;
    }
    else if (cmd === 'link-ok') { applyLink(); return; }
    else if (cmd === 'revert') {
      const n = Number(active.dataset.skrid);
      if (baseline.has(n)) active.innerHTML = baseline.get(n);
      if (baseClass.has(n)) setStyleClasses(active, baseClass.get(n));
    }
    refresh();
    placeTools();
  });

  function currentLink() {
    const sel = document.getSelection();
    const node = sel && sel.anchorNode;
    const el = node && (node.nodeType === 1 ? node : node.parentElement);
    const a = el && el.closest('a');
    return a && active && active.contains(a) ? a : null;
  }

  function showLink() {
    const sel = document.getSelection();
    savedRange = sel && sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
    const a = currentLink();
    linkInput.value = a ? a.getAttribute('href') || '' : '';
    linkrow.hidden = false;
    placeTools();
    linkInput.focus();
    linkInput.select();
  }
  function hideLink() { linkrow.hidden = true; savedRange = null; }

  function safeHref(v) {
    const s = v.trim().replace(/[\u0000- \u007f]+/g, '');
    if (!s) return '';
    const m = s.match(/^([a-z][a-z0-9+.-]*):/i);
    if (m && !['http', 'https', 'mailto', 'tel'].includes(m[1].toLowerCase())) return null;
    if (!m && /^[\w-]+(\.[\w-]+)+(\/|$)/.test(s)) return `https://${s}`;   // "example.com" → https://
    return s;
  }

  function applyLink() {
    if (!active) return;
    const href = safeHref(linkInput.value);
    if (href === null) { toast(T.linkBad, { error: true }); return; }
    const el = active;
    el.focus();
    const sel = document.getSelection();
    if (savedRange) { sel.removeAllRanges(); sel.addRange(savedRange); }
    const a = currentLink();
    if (!href) {
      document.execCommand('unlink');
    } else if (a && sel.isCollapsed) {
      a.setAttribute('href', href);
    } else if (sel.isCollapsed) {
      document.execCommand('insertHTML', false, `<a href="${href.replace(/"/g, '&quot;')}">${href.replace(/</g, '&lt;')}</a>`);
    } else {
      document.execCommand('createLink', false, href);
    }
    hideLink();
    refresh();
    placeTools();
  }
  linkInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); applyLink(); }
    if (e.key === 'Escape') { e.preventDefault(); hideLink(); if (active) active.focus(); }
  });

  // ------------------------------------------------------------------ links: stay in Skrid while browsing
  document.addEventListener('click', (e) => {
    if (inUi(e)) return;
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a) {
      if (mode === 'edit' && !e.target.closest('[data-skrid]') && e.target.closest('header, footer, nav') && !e.target.closest('main')) {
        toast(T.notEditable);
      }
      return;
    }
    // While editing, a click in a text places the caret: never follow its links.
    // While editing, a click in or on an editable text (or inside an editable card) places the caret: never follow links.
if (mode === 'edit' && (e.target.closest('[data-skrid]') || a.closest('[data-skrid]') || a.querySelector('[data-skrid]'))) {
  e.preventDefault();
  return;
}

    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (a.target && a.target !== '_self') return;
    const raw = a.getAttribute('href');
    if (raw.startsWith('#')) {
      e.preventDefault();
      const id = decodeURIComponent(raw.slice(1));
      const target = id ? (document.getElementById(id) || document.getElementsByName(id)[0]) : document.body;
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      history.replaceState(null, '', location.pathname + location.search + raw);
      return;
    }
    let u;
    try { u = new URL(a.href, document.baseURI); } catch (err) { return; }
    if (u.origin !== location.origin || u.pathname.startsWith(cfg.skrid) || !u.pathname.startsWith(cfg.site)) return;
    const last = u.pathname.split('/').pop();
    if (last && last.includes('.') && !/\.(html?|php)$/i.test(last)) return;  // .pdf, images…: open them for real (a .php that is not a page: view.php sends it on)
    e.preventDefault();
    location.href = `${cfg.view}?p=${encodeURIComponent(u.pathname)}${u.hash}`;
  });

  window.addEventListener('beforeunload', (e) => {
    if (!cfg.backup && changed().length) { e.preventDefault(); e.returnValue = T.leave; }
  });

  // ------------------------------------------------------------------ server
  async function post(action, body) {
    const r = await fetch(`${cfg.api}?action=${action}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
      body: JSON.stringify(body),
    });
    let d = {};
    try { d = await r.json(); } catch (err) { /* not JSON */ }
    if (r.status === 401) { location.href = `${cfg.skrid}index.php?next=${encodeURIComponent(location.pathname + location.search)}`; }
    return { status: r.status, ok: r.ok && d.ok, data: d };
  }

  async function save() {
    if (busy || cfg.backup) return;
    const list = changed();
    if (!list.length) { toast(T.changes0); return; }
    const edits = {};
    const blocks = {};
    innerChanged().forEach((n) => { edits[n] = els.get(n).innerHTML; });
    classChanged().forEach((n) => { blocks[n] = [...els.get(n).classList].filter((c) => blockUniverse.has(c)); });
    busy = true;
    refresh();
    statusEl.textContent = T.saving;
    try {
      const { status, ok, data } = await post('save', { page: cfg.page, hash, edits, blocks });
      if (status === 409) {
        toast(T.conflict, { error: true, sticky: true, actions: [[T.reload, () => { baseline.clear(); location.reload(); }]] });
      } else if (!ok) {
        toast(fmt(T.failed, data.error || status), { error: true });
      } else {
        hash = data.hash;
        list.forEach((n) => {
          const el = els.get(n);
          const clean = data.saved && data.saved[n];
          if (typeof clean === 'string' && el !== active && el.innerHTML !== clean) el.innerHTML = clean;
          if (baseline.has(n)) baseline.set(n, el.innerHTML);
          if (baseClass.has(n)) baseClass.set(n, styleClasses(el));
        });
        toast(T.saved);
        if (typeof data.count === 'number' && data.count !== cfg.count) {
          baseline.clear();
          setTimeout(() => location.reload(), 600);           // the list of texts changed: renumber
        }
      }
    } catch (err) {
      toast(fmt(T.failed, err.message), { error: true });
    } finally {
      busy = false;
      refresh();
    }
  }

  function setStyleClasses(el, list) {
    blockUniverse.forEach((c) => el.classList.remove(c));
    list.split(' ').filter(Boolean).forEach((c) => el.classList.add(c));
  }

  function discard() {
    innerChanged().forEach((n) => { els.get(n).innerHTML = baseline.get(n); });
    classChanged().forEach((n) => setStyleClasses(els.get(n), baseClass.get(n)));
    if (active) deactivate();
    refresh();
  }

  bar.addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.mode) setMode(b.dataset.mode);
    else if (b.dataset.act === 'save') save();
    else if (b.dataset.act === 'discard') discard();
    else if (b.dataset.act === 'logout') {
      if (changed().length) { toast(T.leave, { error: true }); return; }
      await post('logout', {});
      location.href = cfg.url;
    } else if (b.dataset.act === 'restore') {
      const { ok, data, status } = await post('restore', { page: cfg.page, backup: cfg.backup.name });
      if (ok) {
        toast(T.restored);
        setTimeout(() => { location.href = `${cfg.view}?p=${encodeURIComponent(cfg.url)}`; }, 700);
      } else toast(fmt(T.failed, data.error || status), { error: true });
    }
  });

  document.execCommand('styleWithCSS', false, false);
  setMode(mode, true);
  if (mode === 'edit' && !cfg.count && !dyn.length) toast(T.none);
  if (cfg.renderError) toast(fmt(T.renderError, cfg.renderError), { error: true, duration: 15000 });
  refresh();
})();
