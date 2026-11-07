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
  // Blocks (duplicate / move / delete), same rule for repeats.
  const seenB = {};
  document.querySelectorAll('[data-skrid-block]').forEach((el) => { seenB[el.dataset.skridBlock] = (seenB[el.dataset.skridBlock] || 0) + 1; });
  const blockEls = new Map();                 // k → element
  document.querySelectorAll('[data-skrid-block]').forEach((el) => {
    if (seenB[el.dataset.skridBlock] > 1) el.removeAttribute('data-skrid-block');
    else blockEls.set(Number(el.dataset.skridBlock), el);
  });
  const blockInfo = cfg.blocks || [];         // k → [enclosing block or -1, can move up, can move down]
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
    grip: '<svg viewBox="0 0 24 24" aria-hidden="true"><g fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></g></svg>',
    parent: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14L4 9l5-5M4 9h10a6 6 0 0 1 6 6v5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    up: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    down: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    duplicate: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 4H6a2 2 0 0 0-2 2v10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    trash: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    data: '<svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="6" rx="7" ry="2.8" fill="none" stroke="currentColor" stroke-width="2"/><path d="M5 6v12c0 1.5 3.1 2.8 7 2.8s7-1.3 7-2.8V6M5 12c0 1.5 3.1 2.8 7 2.8s7-1.3 7-2.8" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
  };

  ui.innerHTML = `
<style>
  :host { all: initial; }
  * { box-sizing: border-box; }
  .wrap { position: fixed; left: 0; right: 0; bottom: 16px; display: flex; justify-content: center; padding: 0 10px; pointer-events: none; }
  .short, .ico { display: none; }
  .bar, .tools, .btools, .cpanel, .toast {
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
  .toast .actions button.danger { background: #dc2626; }
  .toast .actions button.danger:hover { background: #b91c1c; }
  .frame { position: fixed; border: 2px dashed #a78bfa; border-radius: 8px; pointer-events: none;
    background: rgb(167 139 250 / 0.06); }
  .btools { position: fixed; display: flex; align-items: center; gap: 2px; padding: 4px; border-radius: 12px; }
  .btools .tag { padding: 0 8px 0 6px; max-width: 170px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    font: 12px/1 ui-monospace, SFMono-Regular, Menlo, monospace; color: #c4b5fd; }
  .btools button { min-height: 32px; width: 34px; padding: 0; border-radius: 8px; }
  .btools button[disabled] { opacity: 0.35; cursor: default; background: none; }
  .btools .danger:hover { background: rgb(239 68 68 / 0.35); }
  .btools .grip { cursor: grab; touch-action: none; }
  .btools .tag { cursor: pointer; }
  .btools .cls { width: auto; padding: 0 9px; font: 600 12px/1 ui-monospace, SFMono-Regular, Menlo, monospace; }
  .btools .cls[aria-expanded="true"], .btools .tag[aria-expanded="true"] { background: rgb(167 139 250 / 0.3); }
  .ghost { position: fixed; pointer-events: none; padding: 5px 10px; border-radius: 8px; background: #7c3aed; color: #fff;
    font: 600 12px/1 ui-monospace, SFMono-Regular, Menlo, monospace; box-shadow: 0 8px 20px -6px rgb(0 0 0 / 0.5); }
  .drop { position: fixed; pointer-events: none; border-radius: 3px; background: #8b5cf6; box-shadow: 0 0 0 2px rgb(255 255 255 / 0.8); }
  .drop.box { background: rgb(139 92 246 / 0.1); border: 2px dashed #8b5cf6; box-shadow: none; border-radius: 8px; }
  .drop.bad { background: #ef4444; }
  .drop.box.bad { background: rgb(239 68 68 / 0.08); border-color: #ef4444; }
  .droplabel { position: fixed; pointer-events: none; padding: 4px 8px; border-radius: 6px; white-space: nowrap;
    font: 500 12px/1.2 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #fff; background: rgb(76 29 149 / 0.95); }
  .droplabel.bad { background: rgb(185 28 28 / 0.95); }
  .cpanel { position: fixed; width: min(380px, calc(100vw - 16px)); padding: 8px; border-radius: 12px; }
  .chips { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
  .chips .none { color: rgb(248 250 252 / 0.55); padding: 2px 4px; }
  button.chip { min-height: 26px; padding: 0 8px; border-radius: 6px; background: rgb(255 255 255 / 0.1);
    font: 12px/1 ui-monospace, SFMono-Regular, Menlo, monospace; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
  button.chip.off { text-decoration: line-through; opacity: 0.5; }
  button.chip.new { background: rgb(16 185 129 / 0.3); }
  .crow { display: flex; gap: 4px; }
  .crow input { all: unset; box-sizing: border-box; flex: 1; min-width: 0; height: 32px; padding: 0 10px; border-radius: 8px;
    background: rgb(255 255 255 / 0.1); color: #fff; font: 12px ui-monospace, SFMono-Regular, Menlo, monospace; }
  .crow button { min-height: 32px; padding: 0 12px; border-radius: 8px; }
  .cpanel .hint { margin-top: 6px; color: rgb(248 250 252 / 0.55); font-size: 11.5px; line-height: 1.4; }
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
<div class="frame" hidden></div>
<div class="btools" role="toolbar" aria-label="${T.block}" hidden>
  <button type="button" data-bop="drag" class="grip" title="${T.blockDrag}" aria-label="${T.blockDrag}">${icon.grip}</button>
  <span class="tag" data-bop="classes" title="${T.blockClasses}"></span>
  <button type="button" data-bop="classes" class="cls" title="${T.blockClasses}" aria-label="${T.blockClasses}" aria-expanded="false">.class</button>
  <button type="button" data-bop="parent" title="${T.blockParent}" aria-label="${T.blockParent}">${icon.parent}</button>
  <span class="sep"></span>
  <button type="button" data-bop="up" title="${T.blockUp}" aria-label="${T.blockUp}">${icon.up}</button>
  <button type="button" data-bop="down" title="${T.blockDown}" aria-label="${T.blockDown}">${icon.down}</button>
  <button type="button" data-bop="duplicate" title="${T.blockDuplicate}" aria-label="${T.blockDuplicate}">${icon.duplicate}</button>
  <button type="button" data-bop="delete" class="danger" title="${T.blockDelete}" aria-label="${T.blockDelete}">${icon.trash}</button>
</div>
<div class="cpanel" role="dialog" aria-label="${T.blockClasses}" hidden>
  <div class="chips"></div>
  <div class="crow"><input type="text" spellcheck="false" autocomplete="off" placeholder="${T.classAdd}" aria-label="${T.classAdd}"><button type="button" class="primary" data-cp="apply">${T.classApply}</button><button type="button" data-cp="cancel">${T.cancel}</button></div>
  <div class="hint">${T.classHint}</div>
</div>
<div class="drop" hidden></div>
<div class="droplabel" hidden></div>
<div class="ghost" hidden></div>
<div class="menu" role="menu" aria-label="${T.styles}" hidden></div>
<div class="wrap"><div class="bar" role="region" aria-label="Skrid"></div></div>`;

  const bar = ui.querySelector('.bar');
  const tools = ui.querySelector('.tools');
  const linkrow = ui.querySelector('.linkrow');
  const linkInput = linkrow.querySelector('input');
  const toastEl = ui.querySelector('.toast');
  const menu = ui.querySelector('.menu');
  const blockSel = ui.querySelector('.blocksel');
  const frame = ui.querySelector('.frame');
  const btools = ui.querySelector('.btools');
  const cpanel = ui.querySelector('.cpanel');
  const cinput = cpanel.querySelector('input');
  const dropEl = ui.querySelector('.drop');
  const dropLabel = ui.querySelector('.droplabel');
  const ghost = ui.querySelector('.ghost');
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
    actions.forEach(([label, fn, cls]) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = label;
      if (cls) b.className = cls;
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
    if (m !== 'edit') selectBlock(null);
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
    placeBlock();
    refresh();
  }

  // The text toolbar first: the block toolbar keeps out of its way.
  function placeTools() {
    placeTextTools();
    placeBlock();
  }

  function placeTextTools() {
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
    // While editing, a click in or on an editable text, inside an editable card or in a block places the caret
    // or selects the block: never follow those links (Browse mode follows them).
    if (mode === 'edit' && (e.target.closest('[data-skrid]') || a.closest('[data-skrid], [data-skrid-block]') || a.querySelector('[data-skrid]'))) {
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

  // Returns true when everything is saved. reload: false when the caller reloads the page itself.
  async function save({ reload = true } = {}) {
    if (busy || cfg.backup) return false;
    const list = changed();
    if (!list.length) { if (reload) toast(T.changes0); return true; }
    let done = false;
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
        done = true;
        if (reload) toast(T.saved);
        if (reload && typeof data.count === 'number' && data.count !== cfg.count) {
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
    return done;
  }

  // ------------------------------------------------------------------ blocks: select, drag, duplicate, move, classes, delete
  // A click in Edit mode selects the nearest block around it (a dashed frame + a small toolbar);
  // "↰" climbs to the block around it. Every change is written at once (texts being edited are saved
  // first), then the page reloads, because the numbering of texts and blocks changes, and offers Undo.
  let selBlock = null;                        // k of the selected block, or null
  const srcClasses = (k) => String((blockInfo[k] || [])[3] || '').split(/\s+/).filter(Boolean);   // as written in the file

  function blockLabel(k) {
    const el = blockEls.get(k);
    if (!el) return '';
    const cls = srcClasses(k)[0];
    return el.tagName.toLowerCase() + (cls ? `.${cls}` : '');
  }

  function selectBlock(k) {
    if (cp && cp.k !== k) closeClasses(false);
    selBlock = k !== null && blockEls.has(k) ? k : null;
    if (selBlock !== null) {
      const [parent, up, down] = blockInfo[selBlock] || [-1, 0, 0];
      const tag = btools.querySelector('.tag');
      tag.textContent = blockLabel(selBlock);
      tag.title = `${srcClasses(selBlock).join(' ') || blockLabel(selBlock)}\n${T.blockClasses}`;
      btools.querySelector('[data-bop="parent"]').disabled = !blockEls.has(parent);
      btools.querySelector('[data-bop="up"]').disabled = !up;
      btools.querySelector('[data-bop="down"]').disabled = !down;
    }
    placeBlock();
  }

  function placeBlock() {
    const el = selBlock !== null && !drag && blockEls.get(selBlock);
    const r = el && el.getBoundingClientRect();
    if (!r || (!r.width && !r.height)) { frame.hidden = true; btools.hidden = true; cpanel.hidden = true; return; }
    frame.hidden = false;
    btools.hidden = false;
    Object.assign(frame.style, { top: `${r.top - 5}px`, left: `${r.left - 5}px`, width: `${r.width + 10}px`, height: `${r.height + 10}px` });
    const w = btools.offsetWidth;
    const h = btools.offsetHeight;
    const vh = window.innerHeight;
    const left = Math.max(8, Math.min(r.right + 5 - w, window.innerWidth - w - 8));
    let top = r.top - h - 10;                                   // above the block, on the right
    const t = !tools.hidden && tools.getBoundingClientRect();
    const hits = (y) => t && y < t.bottom + 4 && y + h > t.top - 4 && left < t.right + 4 && left + w > t.left - 4;
    if (top < 8 || hits(top)) top = r.bottom + 10;              // under it
    if (top + h > vh - 80 || hits(top)) top = Math.max(8, Math.min(r.top + 8, vh - h - 80));   // inside, top right
    btools.style.top = `${Math.round(top)}px`;
    btools.style.left = `${Math.round(left)}px`;
    if (cp) {                                                    // the classes panel hangs under the toolbar
      cpanel.hidden = false;
      const pw = cpanel.offsetWidth;
      const ph = cpanel.offsetHeight;
      let pt = top + h + 6;
      if (pt + ph > vh - 80) pt = Math.max(8, top - ph - 6);
      cpanel.style.top = `${Math.round(pt)}px`;
      cpanel.style.left = `${Math.round(Math.max(8, Math.min(left + w - pw, window.innerWidth - pw - 8)))}px`;
    }
  }

  document.addEventListener('mousedown', (e) => {
    if (mode !== 'edit' || cfg.backup || inUi(e) || e.button !== 0) return;
    const b = e.target.closest && e.target.closest('[data-skrid-block]');
    selectBlock(b ? Number(b.dataset.skridBlock) : null);
  }, true);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && selBlock !== null && !active && menu.hidden && !cp) selectBlock(null);
  });
  window.addEventListener('scroll', () => { if (selBlock !== null) placeBlock(); }, { passive: true });
  window.addEventListener('resize', () => { if (selBlock !== null) placeBlock(); });

  btools.addEventListener('mousedown', (e) => e.preventDefault());   // keep the caret where it is
  btools.addEventListener('click', (e) => {
    const b = e.target.closest('button, .tag');
    if (!b || b.disabled || selBlock === null) return;
    const op = b.dataset.bop;
    if (op === 'drag') return;                                  // pointer events, below
    if (op === 'classes') { openClasses(); return; }
    if (op === 'parent') { selectBlock((blockInfo[selBlock] || [-1])[0]); return; }
    if (op === 'delete') {
      const k = selBlock;
      toast(fmt(T.blockDeleteAsk, blockLabel(k)), {
        sticky: true,
        actions: [[T.blockDelete, () => blockOp(k, 'delete'), 'danger'], [T.cancel, () => {}]],
      });
      return;
    }
    blockOp(selBlock, op);
  });

  // Write a block change, then reload. Returns false when it did not happen.
  async function blockOp(k, op, extra = {}) {
    if (busy) return false;
    if (active) { active.blur(); deactivate(); }
    if (changed().length && !(await save({ reload: false }))) return false;   // texts first, so nothing is lost
    busy = true;
    refresh();
    try {
      const { status, ok, data } = await post('block', { page: cfg.page, hash, block: k, op, ...extra });
      if (status === 409) {
        toast(T.conflict, { error: true, sticky: true, actions: [[T.reload, () => location.reload()]] });
      } else if (!ok) {
        toast(T[`drop.${data.error}`] || fmt(T.failed, data.error || status), { error: true });
      } else if (data.same) {
        busy = false;
        refresh();
        return true;                                            // dropped where it already was
      } else {
        hash = data.hash;
        baseline.clear();
        store.set('skrid-after', JSON.stringify({ page: cfg.page, op, block: data.block, backup: data.backup, became: data.became || '', y: window.scrollY }));
        location.reload();
        return true;
      }
    } catch (err) {
      toast(fmt(T.failed, err.message), { error: true });
    }
    busy = false;
    refresh();
    return false;
  }

  // ------------------------------------------------------------------ drag and drop
  // The ⠿ handle carries the block anywhere in the zone. A line (before / after a block) or a dashed box
  // (inside it) shows where it goes, in red with the reason when HTML does not allow it there.
  // Same rules as skrid_drop_fit() in lib/scan.php; the server checks again and has the last word.
  const LISTS = ['UL', 'OL', 'MENU'];
  const TABLE_PARTS = ['TABLE', 'THEAD', 'TBODY', 'TFOOT'];
  const FLOW = new Set(['MAIN', 'BODY', 'DIV', 'SECTION', 'ARTICLE', 'ASIDE', 'HEADER', 'FOOTER', 'NAV', 'LI', 'DD', 'FIGURE',
    'BLOCKQUOTE', 'DETAILS', 'FIELDSET', 'ADDRESS', 'TD', 'TH', 'A', 'DIALOG', 'SEARCH']);
  const CONTAINERS = new Set([...FLOW, ...LISTS, ...TABLE_PARTS, 'DL', 'HGROUP']);
  const SECTIONING = ['ARTICLE', 'SECTION', 'MAIN', 'ASIDE'];
  const BLOCKY = 'address,article,aside,blockquote,dd,details,div,dl,dt,fieldset,figure,footer,h1,h2,h3,h4,h5,h6,header,hgroup,'
    + 'li,menu,nav,ol,p,pre,section,table,tr,ul,form,hr,iframe,video,audio,canvas,svg';
  const root = document.querySelector('[data-skrid-root]');
  let drag = null;                            // {k, el, x, y, target, raf}

  const parentBlock = (el) => el.parentElement && el.parentElement.closest('[data-skrid-block], [data-skrid-root]');

  function fit(el, parent) {
    const tag = el.tagName;
    const anc = [];
    for (let p = parent; p; p = p.parentElement) anc.push(p.tagName);
    const ptag = anc[0] || 'BODY';
    const gtag = anc[1] || '';
    const has = (sel) => !!el.querySelector(sel);
    if (anc.includes('A') && (tag === 'A' || has('a, button, input, select, textarea'))) return { code: 'link-in-link' };
    if ((anc.includes('HEADER') || anc.includes('FOOTER')) && (tag === 'HEADER' || tag === 'FOOTER' || has('header, footer'))) return { code: 'header-in-header' };
    if (anc.includes('ADDRESS') && (/^(H[1-6]|HEADER|FOOTER|SECTION|ARTICLE|ASIDE|NAV|ADDRESS)$/.test(tag)
      || has('h1, h2, h3, h4, h5, h6, header, footer, section, article, aside, nav, address'))) return { code: 'in-address' };
    if (TABLE_PARTS.includes(ptag)) return tag === 'TR' ? { became: '' } : { code: 'table-needs-row' };
    if (tag === 'TR') return { code: 'row-needs-table' };
    if (LISTS.includes(ptag)) return { became: tag === 'LI' ? '' : (tag === 'P' ? 'p>li' : 'li') };
    const dtdd = tag === 'DIV' && has('dt, dd') && !has('dl');
    if (ptag === 'DL' || (ptag === 'DIV' && gtag === 'DL')) {
      return (tag === 'DT' || tag === 'DD' || (dtdd && ptag === 'DL')) ? { became: '' } : { code: 'dl-needs-dt-dd' };
    }
    if (dtdd) return { code: 'dl-group' };
    if (ptag === 'HGROUP') return /^(H[1-6]|P)$/.test(tag) ? { became: '' } : { code: 'hgroup' };
    if (!FLOW.has(ptag)) return { code: 'no-block-here' };
    if (['LI', 'DT', 'DD'].includes(tag)) return { became: has(BLOCKY) ? 'unwrap' : `${tag.toLowerCase()}>p` };
    if (root && root.tagName === 'BODY' && ['HEADER', 'FOOTER', 'NAV'].includes(tag) && !anc.some((x) => SECTIONING.includes(x))) {
      return { code: 'becomes-chrome' };
    }
    return { became: '' };
  }

  function fitNote(became) {
    if (!became) return '';
    if (became === 'li' || became === 'unwrap') return T[`fit.${became}`];
    const [a, b] = became.split('>');
    return fmt(T['fit.to'], a, b);
  }

  function isRow(H) {
    const p = H.parentElement;
    if (!p) return false;
    const cs = getComputedStyle(p);
    if (cs.display.includes('flex')) return !cs.flexDirection.startsWith('column');
    const sib = H.previousElementSibling || H.nextElementSibling;
    if (!sib) return false;
    const a = H.getBoundingClientRect();
    const b = sib.getBoundingClientRect();
    return Math.abs(a.top - b.top) < Math.min(a.height, b.height) / 2 && Math.abs(a.left - b.left) > 1;
  }

  function evaluate(H, where, row = false) {
    const t = H === root ? -1 : Number(H.dataset.skridBlock);
    let parent;
    if (where === 'inside') {
      if (H !== root && H.hasAttribute('data-skrid')) return { H, t, where, code: 'not-container' };
      const kids = [...H.querySelectorAll('[data-skrid-block]')].filter((c) => c !== drag.el && !drag.el.contains(c) && parentBlock(c) === H);
      parent = kids.length ? kids[kids.length - 1].parentElement : H;
    } else {
      parent = H.parentElement;
    }
    if (drag.el === H || drag.el.contains(parent)) return { H, t, where, row, code: 'into-itself' };
    return { H, t, where, row, ...fit(drag.el, parent) };
  }

  // The block under the pointer decides: its top / bottom edge (left / right in a row) = before / after it,
  // its middle = inside it. When the dragged block is not allowed there (a stat row over the <dd> of
  // another row), the blocks around it are tried, before or after them, and the first that fits wins.
  function findTarget(x, y) {
    const hit = document.elementFromPoint(x, y);
    if (!hit || drag.el.contains(hit)) return null;
    let H = hit.closest('[data-skrid-block]');
    if (!H) return root && root.contains(hit) ? evaluate(root, 'inside') : null;
    let first = null;
    for (let depth = 0; H; depth++) {
      const r = H.getBoundingClientRect();
      const row = isRow(H);
      const rel = row ? (x - r.left) / Math.max(1, r.width) : (y - r.top) / Math.max(1, r.height);
      const canIn = depth === 0 && CONTAINERS.has(H.tagName) && !H.hasAttribute('data-skrid');
      const where = rel < (canIn ? 0.28 : 0.5) ? 'before' : (rel >= (canIn ? 0.72 : 0.5) ? 'after' : 'inside');
      const tg = evaluate(H, where, row);
      if (!tg.code) return tg;
      first = first || tg;
      const up = parentBlock(H);
      H = up && up !== root ? up : null;
    }
    return first;
  }

  function showDrop(tg) {
    if (!tg) { dropEl.hidden = true; dropLabel.hidden = true; return; }
    const bad = !!tg.code;
    const r = tg.H.getBoundingClientRect();
    dropEl.className = `drop${tg.where === 'inside' ? ' box' : ''}${bad ? ' bad' : ''}`;
    let s;
    if (tg.where === 'inside') s = { top: r.top + 2, left: r.left + 2, width: r.width - 4, height: Math.max(8, r.height - 4) };
    else if (tg.row) s = { top: r.top, left: tg.where === 'before' ? r.left - 5 : r.right + 2, width: 3, height: r.height };
    else s = { top: tg.where === 'before' ? r.top - 5 : r.bottom + 2, left: r.left, width: r.width, height: 3 };
    Object.assign(dropEl.style, { top: `${s.top}px`, left: `${s.left}px`, width: `${s.width}px`, height: `${s.height}px` });
    dropEl.hidden = false;
    const place = tg.H === root ? T['where.root'] : `${T[`where.${tg.where}`]} ${blockLabel(tg.t)}`;
    dropLabel.textContent = [place, bad ? (T[`drop.${tg.code}`] || tg.code) : fitNote(tg.became)].filter(Boolean).join(' · ');
    dropLabel.className = `droplabel${bad ? ' bad' : ''}`;
    dropLabel.hidden = false;
    Object.assign(dropLabel.style, { left: `${Math.min(drag.x + 14, window.innerWidth - dropLabel.offsetWidth - 8)}px`, top: `${drag.y + 44}px` });
  }

  function updateDrag() {
    Object.assign(ghost.style, { left: `${drag.x + 14}px`, top: `${drag.y + 14}px` });
    drag.target = findTarget(drag.x, drag.y);
    showDrop(drag.target);
  }

  function autoScroll() {                     // near the top or bottom edge, the page scrolls
    if (!drag) return;
    const vh = window.innerHeight;
    const dy = drag.y < 70 ? -(70 - drag.y) / 3 : (drag.y > vh - 130 ? (drag.y - (vh - 130)) / 3 : 0);
    if (dy) { window.scrollBy(0, dy); updateDrag(); }
    drag.raf = requestAnimationFrame(autoScroll);
  }

  function startDrag(e) {
    if (selBlock === null || busy) return;
    e.preventDefault();
    closeClasses(false);
    if (active) { active.blur(); deactivate(); }
    const el = blockEls.get(selBlock);
    drag = { k: selBlock, el, x: e.clientX, y: e.clientY, target: null, raf: 0 };
    el.classList.add('skrid-dragging');
    html.classList.add('skrid-dragmode');
    ghost.textContent = blockLabel(selBlock);
    ghost.hidden = false;
    placeBlock();                             // hides the frame and the toolbar while dragging
    window.addEventListener('pointermove', onDragMove, true);
    window.addEventListener('pointerup', onDragEnd, true);
    window.addEventListener('pointercancel', stopDrag, true);
    updateDrag();
    drag.raf = requestAnimationFrame(autoScroll);
  }

  function onDragMove(e) {
    if (!drag) return;
    e.preventDefault();
    drag.x = e.clientX;
    drag.y = e.clientY;
    updateDrag();
  }

  function stopDrag() {
    if (!drag) return null;
    const d = drag;
    drag = null;
    cancelAnimationFrame(d.raf);
    window.removeEventListener('pointermove', onDragMove, true);
    window.removeEventListener('pointerup', onDragEnd, true);
    window.removeEventListener('pointercancel', stopDrag, true);
    d.el.classList.remove('skrid-dragging');
    html.classList.remove('skrid-dragmode');
    ghost.hidden = true;
    dropEl.hidden = true;
    dropLabel.hidden = true;
    placeBlock();
    return d;
  }

  function onDragEnd(e) {
    e.preventDefault();
    const d = stopDrag();
    const tg = d && d.target;
    if (!tg) return;
    if (tg.code) { toast(T[`drop.${tg.code}`] || tg.code, { error: true }); return; }
    blockOp(d.k, 'drop', { target: tg.t, where: tg.where });
  }

  btools.addEventListener('pointerdown', (e) => { if (e.button === 0 && e.target.closest('[data-bop="drag"]')) startDrag(e); });
  document.addEventListener('keydown', (e) => {
    if (drag && e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); stopDrag(); }
  }, true);

  // ------------------------------------------------------------------ classes of a block
  // The classes as written in the file, as chips: a click removes one (or brings it back), the field adds
  // some. The page shows the result at once; "Apply" writes the class attribute, and only that.
  let cp = null;                              // {k, el, orig, cur}

  function setExpanded(v) { btools.querySelectorAll('[data-bop="classes"]').forEach((b) => b.setAttribute('aria-expanded', String(v))); }

  function openClasses() {
    if (selBlock === null) return;
    if (cp) { closeClasses(false); return; }
    cp = { k: selBlock, el: blockEls.get(selBlock), orig: srcClasses(selBlock), cur: srcClasses(selBlock) };
    renderClasses();
    setExpanded(true);
    placeBlock();
    cinput.value = '';
    cinput.focus();
  }

  function preview(next) {                    // only the classes that change: what the site's scripts added stays
    cp.cur.filter((c) => !next.includes(c)).forEach((c) => cp.el.classList.remove(c));
    next.filter((c) => !cp.cur.includes(c)).forEach((c) => cp.el.classList.add(c));
    cp.cur = next;
    renderClasses();
    placeBlock();
  }

  function renderClasses() {
    const all = [...cp.orig, ...cp.cur.filter((c) => !cp.orig.includes(c))];
    const box = cpanel.querySelector('.chips');
    box.innerHTML = '';
    if (!all.length) {
      const s = document.createElement('span');
      s.className = 'none';
      s.textContent = T.classNone;
      box.appendChild(s);
    }
    all.forEach((c) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = `chip${cp.cur.includes(c) ? (cp.orig.includes(c) ? '' : ' new') : ' off'}`;
      b.textContent = c;
      b.title = c;
      b.dataset.c = c;
      box.appendChild(b);
    });
  }

  function addTyped() {
    const toks = cinput.value.trim().split(/\s+/).filter(Boolean);
    if (!toks.length) return true;
    if (toks.some((c) => /["'<>`]/.test(c) || c.length > 120)) { toast(T.classBad, { error: true }); return false; }
    preview([...cp.cur, ...toks.filter((c) => !cp.cur.includes(c))]);
    cinput.value = '';
    return true;
  }

  function closeClasses(keep) {
    if (!cp) return;
    if (!keep) preview(cp.orig);
    cp = null;
    cpanel.hidden = true;
    setExpanded(false);
    placeBlock();
  }

  async function applyClasses() {
    if (!cp || !addTyped()) return;
    const { k, el, orig, cur } = cp;
    closeClasses(true);                       // the preview stays while it is written
    if (cur.join(' ') === orig.join(' ')) return;
    if (!(await blockOp(k, 'classes', { classes: cur.join(' ') }))) {
      cur.filter((c) => !orig.includes(c)).forEach((c) => el.classList.remove(c));
      orig.filter((c) => !cur.includes(c)).forEach((c) => el.classList.add(c));
    }
  }

  cpanel.addEventListener('mousedown', (e) => { if (!e.target.closest('input')) e.preventDefault(); });
  cpanel.addEventListener('click', (e) => {
    if (!cp) return;
    const chip = e.target.closest('.chip');
    if (chip) {
      const c = chip.dataset.c;
      preview(cp.cur.includes(c) ? cp.cur.filter((x) => x !== c) : [...cp.cur, c]);
      return;
    }
    const b = e.target.closest('[data-cp]');
    if (b) { if (b.dataset.cp === 'cancel') closeClasses(false); else applyClasses(); }
  });
  cinput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); if (cinput.value.trim()) addTyped(); else applyClasses(); }
    else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeClasses(false); }
  });

  // After the reload: back where we were, the block selected, and an Undo.
  function afterBlockOp() {
    let a = null;
    try { a = JSON.parse(store.get('skrid-after') || 'null'); } catch (e) { /* ignore */ }
    store.set('skrid-after', '');
    if (!a || a.page !== cfg.page || cfg.backup) return;
    if (typeof a.y === 'number') window.scrollTo(0, a.y);
    if (a.undone) { toast(T.undone); return; }
    if (mode !== 'edit') setMode('edit', true);
    const el = a.block !== null && a.block !== undefined && blockEls.get(a.block);
    if (el) {
      selectBlock(a.block);
      const r = el.getBoundingClientRect();
      if (r.top < 0 || r.top > window.innerHeight - 120) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
      if (a.op === 'duplicate') {
        // The copy is ready to be rewritten: its first text is active, all selected.
        const txt = el.matches('[data-skrid]') ? el : el.querySelector('[data-skrid]');
        if (txt) {
          activate(txt);
          txt.focus();
          const range = document.createRange();
          range.selectNodeContents(txt);
          const s = document.getSelection();
          s.removeAllRanges();
          s.addRange(range);
        }
      }
    }
    const msg = { duplicate: T.blockDuplicated, delete: T.blockDeleted, classes: T.classesSaved }[a.op]
      || [T.blockMoved, fitNote(a.became)].filter(Boolean).join(' ');
    toast(msg, {
      duration: 12000,
      actions: a.backup ? [[T.undo, async () => {
        if (changed().length && !(await save({ reload: false }))) return;
        const { status, ok, data } = await post('restore', { page: cfg.page, backup: a.backup, hash });
        if (ok) {
          store.set('skrid-after', JSON.stringify({ page: cfg.page, undone: true, y: window.scrollY }));
          location.reload();
        } else if (status === 409) toast(T.conflict, { error: true, sticky: true, actions: [[T.reload, () => location.reload()]] });
        else toast(fmt(T.failed, data.error || status), { error: true });
      }]] : [],
    });
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
  afterBlockOp();
})();
