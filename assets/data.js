/*
 * Skrid data editor: a .json file of the site as a form.
 * Top-level lists become sections of items (title, year and picture in the summary line); each item opens on
 * its fields. Items can be added, duplicated, moved and deleted; fields added or removed. The raw JSON is one
 * click away. Types are kept: a number stays a number while it looks like one, {} stays {}.
 */
(() => {
  'use strict';
  const root = document.querySelector('[data-editor]');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const T = cfg.texts || {};
  const fmt = (s, ...a) => a.reduce((r, v) => r.replace(/%[sd]/, v), s || '');
  const csrf = document.querySelector('meta[name="skrid-csrf"]').content;
  const api = document.querySelector('meta[name="skrid-api"]').content;

  const formEl = root.querySelector('[data-form]');
  const rawBox = root.querySelector('[data-rawbox]');
  const rawEl = root.querySelector('[data-raw]');
  const rawErr = root.querySelector('[data-raw-error]');
  const saveBtn = root.querySelector('[data-save]');
  const discardBtn = root.querySelector('[data-discard]');
  const statusEl = root.querySelector('[data-status]');
  const findEl = root.querySelector('[data-find]');
  const tools = root.querySelector('[data-tools]');

  let model;
  let hash = cfg.hash;
  let dirty = false;
  let view = 'form';
  const source = JSON.parse(document.getElementById('sk-data').textContent);
  if (cfg.error) { view = 'raw'; rawEl.value = source; } else model = source;

  // ------------------------------------------------------------------ helpers
  const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
  const isItems = (v) => Array.isArray(v) && v.every(isObj);           // [] counts: a list that is empty for now
  const el = (tag, attrs = {}, ...kids) => {
    const e = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (v === false || v === null || v === undefined) return;
      if (k === 'class') e.className = v;
      else if (k === 'text') e.textContent = v;
      else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
      else e.setAttribute(k, v === true ? '' : v);
    });
    kids.flat().forEach((k) => { if (k !== null && k !== undefined && k !== false) e.append(k); });
    return e;
  };
  const norm = (s) => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  const words = (v) => (typeof v === 'string' || typeof v === 'number' ? [String(v)] : Array.isArray(v) ? v.flatMap(words) : isObj(v) ? Object.values(v).flatMap(words) : []);
  const humanize = (k) => {
    const s = String(k).replace(/[_-]+/g, ' ').replace(/([a-z\d])([A-Z])/g, '$1 $2').trim().toLowerCase();
    return s.charAt(0).toUpperCase() + s.slice(1);
  };
  const IMG_KEY = /(^|_|-)(img|image|images|photo|picture|thumb|thumbnail|cover|logo|icon|src|poster)s?$/i;
  const IMG_URL = /\.(jpe?g|png|webp|gif|svg|avif)(\?.*)?$/i;
  const isImage = (key, v) => typeof v === 'string' && v.trim() !== '' && (IMG_URL.test(v.trim()) || (IMG_KEY.test(key) && /^(https?:|\/|[\w-]+\/)/.test(v.trim())));
  const imgSrc = (v) => (/^(https?:)?\/\//i.test(v) || v.startsWith('/') ? v : cfg.site + v.replace(/^\.\//, ''));
  const NUM = /^-?\d+(\.\d+)?$/;
  let uid = 0;

  // Which field names a summary line shows, by language of the editor.
  const TITLE_KEYS = cfg.lang === 'fr'
    ? ['titre', 'title', 'nom', 'name', 'label', 'heading', 'caption', 'alt', 'id']
    : ['title', 'titre', 'name', 'nom', 'label', 'heading', 'caption', 'alt', 'id'];
  const YEAR_KEYS = ['year', 'annee', 'année', 'date', 'when'];
  const first = (o, keys) => {
    for (const k of keys) if (typeof o[k] === 'string' ? o[k].trim() : typeof o[k] === 'number') return [k, String(o[k]).trim()];
    return [null, ''];
  };
  function pictureOf(o) {
    for (const [k, v] of Object.entries(o)) {
      if (isImage(k, v)) return v.trim();
      if (isItems(v)) for (const x of v) for (const [k2, v2] of Object.entries(x)) if (isImage(k2, v2)) return v2.trim();
    }
    return '';
  }

  // ------------------------------------------------------------------ state
  const open = new WeakSet();                 // items shown open
  function changed() {
    dirty = true;
    refresh();
  }
  function refresh(msg = '', bad = false) {
    saveBtn.disabled = !dirty || !cfg.writable;
    discardBtn.hidden = !dirty;
    statusEl.textContent = msg || (dirty ? T.unsaved : '');
    statusEl.classList.toggle('dirty', dirty && !msg);
    statusEl.classList.toggle('bad', bad);
  }

  // ------------------------------------------------------------------ fields
  /** Input for obj[key] (string, number, true/false, null, list of plain values). */
  function valueInput(obj, key, id, onChange) {
    const v = obj[key];
    if (typeof v === 'boolean') {
      const cb = el('input', { type: 'checkbox', id, onchange: () => { obj[key] = cb.checked; onChange(); } });
      cb.checked = v;
      return el('label', { class: 'dbool' }, cb, el('span', { text: v ? T.yes : T.no }));
    }
    if (Array.isArray(v)) {                     // plain values, one per line
      const nums = v.length > 0 && v.every((x) => typeof x === 'number');
      const ta = el('textarea', { id, rows: Math.max(2, v.length + 1), class: 'dlines', placeholder: T.onePerLine });
      ta.value = v.join('\n');
      ta.addEventListener('input', () => {
        obj[key] = ta.value.split('\n').map((x) => x.trim()).filter((x) => x !== '').map((x) => (nums && NUM.test(x) ? Number(x) : x));
        onChange();
      });
      return ta;
    }
    const text = v === null ? '' : String(v);
    const wasNumber = typeof v === 'number';
    const long = text.length > 72 || text.includes('\n');
    const input = long ? el('textarea', { id, rows: 2 }) : el('input', { id, type: 'text', inputmode: wasNumber ? 'decimal' : null });
    input.value = text;
    if (v === null) input.placeholder = 'null';
    const grow = () => { if (input.tagName === 'TEXTAREA') { input.style.height = 'auto'; input.style.height = `${input.scrollHeight + 2}px`; } };
    input.addEventListener('input', () => {
      const t = input.value;
      if (wasNumber && NUM.test(t.trim())) obj[key] = Number(t.trim());
      else if (v === null && t === '') obj[key] = null;
      else obj[key] = t;
      grow();
      onChange();
    });
    requestAnimationFrame(grow);
    return input;
  }

  /** A row: field name, value, helpers (picture preview, link), remove button. */
  function fieldRow(obj, key, ctx) {
    const id = `f${++uid}`;
    const v = obj[key];
    const row = el('div', { class: 'drow', 'data-key': key });
    const label = el('label', { for: id, class: 'dkey', text: key, title: key });
    const remove = el('button', { type: 'button', class: 'dx', title: fmt(T.removeField, key), 'aria-label': fmt(T.removeField, key) }, '×');
    remove.addEventListener('click', () => {
      delete obj[key];
      ctx.rerender();
      changed();
    });

    if (isItems(v) && (v.length || ctx.listKeys.has(key))) {
      row.classList.add('dnest');
      row.append(el('span', { class: 'dkey', text: key }), listEditor(v, { compact: true, key, onChange: ctx.onChange }), remove);
      return row;
    }
    if (isObj(v)) {
      row.classList.add('dnest');
      row.append(el('span', { class: 'dkey', text: key }), objectEditor(v, { onChange: ctx.onChange, siblings: [] }), remove);
      return row;
    }

    const box = el('div', { class: 'dval' });
    const helpers = el('span', { class: 'dhelp' });
    const preview = () => {
      helpers.textContent = '';
      const cur = obj[key];
      if (isImage(key, cur)) {
        const img = el('img', { class: 'dprev', alt: '', loading: 'lazy', src: imgSrc(cur.trim()) });
        img.addEventListener('error', () => { img.replaceWith(el('span', { class: 'dbadimg', title: T.imageMissing, text: '?' })); });
        helpers.append(el('a', { href: imgSrc(cur.trim()), target: '_blank', rel: 'noopener', title: T.openImage }, img));
      } else if (typeof cur === 'string' && /^https?:\/\/\S+$/i.test(cur.trim())) {
        helpers.append(el('a', { class: 'dlink', href: cur.trim(), target: '_blank', rel: 'noopener', title: T.openLink }, '↗'));
      }
    };
    const input = valueInput(obj, key, id, () => { preview(); ctx.onChange(); changed(); });
    box.append(input, helpers);
    preview();
    row.append(label, box, remove);
    return row;
  }

  /** Every field of an object, plus "add a field" (names suggested from the other items of the list). */
  function objectEditor(obj, ctx) {
    const wrap = el('div', { class: 'dfields' });
    const listKeys = new Set();
    ctx.siblings.forEach((s) => Object.entries(s).forEach(([k, v]) => { if (isItems(v)) listKeys.add(k); }));
    const inner = { onChange: ctx.onChange, listKeys, rerender: () => render() };
    function render() {
      wrap.textContent = '';
      Object.keys(obj).forEach((k) => wrap.append(fieldRow(obj, k, inner)));
      // Add a field.
      const missing = [...new Set(ctx.siblings.flatMap((s) => Object.keys(s)))].filter((k) => !(k in obj));
      const listId = `dl${++uid}`;
      const name = el('input', { type: 'text', list: listId, placeholder: T.newField, 'aria-label': T.newField, class: 'dnewkey' });
      const add = el('button', { type: 'button', class: 'btn small' }, T.addField);
      const doAdd = () => {
        const k = name.value.trim();
        if (!k) { name.focus(); return; }
        if (k in obj) { name.select(); return; }
        const example = ctx.siblings.map((s) => s[k]).find((x) => x !== undefined);
        obj[k] = Array.isArray(example) ? [] : isObj(example) ? {} : typeof example === 'boolean' ? false : '';
        render();
        changed();
        const inp = wrap.querySelector(`.drow[data-key="${CSS.escape(k)}"] input, .drow[data-key="${CSS.escape(k)}"] textarea`);
        if (inp) inp.focus();
      };
      add.addEventListener('click', doAdd);
      name.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } });
      wrap.append(el('div', { class: 'daddfield' }, name, el('datalist', { id: listId }, missing.map((k) => el('option', { value: k }))), add));
    }
    render();
    return wrap;
  }

  // ------------------------------------------------------------------ lists of items
  function blankLike(tpl) {
    const o = {};
    Object.entries(tpl || {}).forEach(([k, v]) => {
      o[k] = Array.isArray(v) ? [] : isObj(v) ? blankLike(v) : typeof v === 'boolean' ? false : '';
    });
    return o;
  }

  function summary(item) {
    const [tk, title] = first(item, TITLE_KEYS);
    const [, year] = first(item, YEAR_KEYS);
    const other = TITLE_KEYS.slice(0, 2).filter((k) => k !== tk).map((k) => (typeof item[k] === 'string' ? item[k].trim() : '')).find(Boolean)
      || first(item, ['subtitle', 'client', 'town', 'ville', 'description'])[1];
    const pic = pictureOf(item);
    const frag = [];
    frag.push(pic ? el('img', { class: 'dthumb', alt: '', loading: 'lazy', src: imgSrc(pic), onerror: (e) => { e.target.style.visibility = 'hidden'; } }) : el('span', { class: 'dthumb none' }));
    if (year) frag.push(el('span', { class: 'dyear', text: year }));
    frag.push(el('span', { class: 'dtitles' },
      el('span', { class: 'dtitle', text: (title || T.untitled).replace(/<[^>]*>/g, ' ') }),
      other && other !== title ? el('span', { class: 'dsub', text: other.replace(/<[^>]*>/g, ' ') }) : null));
    return frag;
  }

  function listEditor(arr, ctx) {
    const box = el('div', { class: ctx.compact ? 'dlist compact' : 'dlist' });
    const ol = el('ol', { class: 'ditems' });
    const tplOf = () => arr[0] || null;
    const render = () => {
      ol.textContent = '';
      arr.forEach((item, i) => ol.append(itemEditor(arr, item, i, { ...ctx, rerender: render })));
      if (ctx.onCount) ctx.onCount(arr.length);
    };
    const add = (where) => {
      const item = blankLike(tplOf());
      if (where === 'top') arr.unshift(item); else arr.push(item);
      open.add(item);
      render();
      changed();
      const li = [...ol.children][where === 'top' ? 0 : arr.length - 1];
      if (li) {
        li.scrollIntoView({ block: 'center', behavior: 'smooth' });
        const inp = li.querySelector('.dfields input, .dfields textarea');
        if (inp) setTimeout(() => inp.focus({ preventScroll: true }), 250);
      }
    };
    render();
    if (!ctx.compact) {
      box.append(
        el('p', { class: 'dadd' }, el('button', { type: 'button', class: 'btn small', onclick: () => add('top') }, T.addTop)),
        ol,
        el('p', { class: 'dadd' }, el('button', { type: 'button', class: 'btn small ghost', onclick: () => add('end') }, T.addEnd)),
      );
    } else {
      box.append(ol, el('p', { class: 'dadd' }, el('button', { type: 'button', class: 'btn small ghost', onclick: () => add('end') }, T.addItem)));
    }
    box.render = render;
    return box;
  }

  function itemEditor(arr, item, i, ctx) {
    const li = el('li', { class: 'ditem' });
    li.item = item;
    const acts = el('span', { class: 'dacts' });
    const act = (name, label, fn, disabled = false) => {
      const b = el('button', { type: 'button', class: `dact ${name}`, title: label, 'aria-label': label, disabled }, { up: '↑', down: '↓', dup: '⧉', del: '🗑' }[name]);
      b.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); fn(b); });
      acts.append(b);
    };
    const move = (d) => {
      const j = i + d;
      if (j < 0 || j >= arr.length) return;
      [arr[i], arr[j]] = [arr[j], arr[i]];
      ctx.rerender();
      changed();
      const moved = [...document.querySelectorAll('.ditem')].find((x) => x.item === item);
      if (moved) moved.querySelector(`.dact.${d < 0 ? 'up' : 'down'}`)?.focus();
    };
    act('up', T.moveUp, () => move(-1), i === 0);
    act('down', T.moveDown, () => move(1), i === arr.length - 1);
    act('dup', T.duplicate, () => {
      const copy = JSON.parse(JSON.stringify(item));
      arr.splice(i + 1, 0, copy);
      open.add(copy);
      ctx.rerender();
      changed();
    });
    act('del', T.remove, (b) => {
      if (!b.classList.contains('confirm')) {
        b.classList.add('confirm');
        b.textContent = T.removeConfirm;
        setTimeout(() => { if (b.isConnected) { b.classList.remove('confirm'); b.textContent = '🗑'; } }, 3000);
        return;
      }
      arr.splice(i, 1);
      ctx.rerender();
      changed();
    });

    const siblings = arr.filter((x) => x !== item);
    if (ctx.compact) {
      li.classList.add('compact');
      li.append(el('div', { class: 'dhead' }, el('span', { class: 'dnum', text: String(i + 1) }), acts),
        objectEditor(item, { onChange: () => ctx.onChange(), siblings }));
      return li;
    }
    const sumEl = el('summary', { class: 'dsum' });
    const paint = () => { sumEl.textContent = ''; sumEl.append(el('span', { class: 'twisty', 'aria-hidden': 'true' }), ...summary(item), acts); };
    paint();
    const det = el('details', {}, sumEl);
    let body = null;
    const fill = () => {
      if (body) return;
      body = objectEditor(item, { onChange: () => { paint(); ctx.onChange(); }, siblings });
      det.append(body);
    };
    det.addEventListener('toggle', () => {
      if (det.open) { open.add(item); fill(); } else open.delete(item);
    });
    if (open.has(item)) { det.open = true; fill(); }
    li.append(det);
    li.search = () => norm(words(item).join(' '));
    return li;
  }

  // ------------------------------------------------------------------ the whole file
  function renderForm() {
    formEl.textContent = '';
    const sections = [];
    const loose = [];
    const addSection = (label, key, arr) => {
      const count = el('span', { class: 'muted dcount' });
      const setCount = (n) => { count.textContent = n === 1 ? T.count1 : fmt(T.countN, n); };
      const list = listEditor(arr, { onChange: () => {}, onCount: setCount, key });
      setCount(arr.length);
      const sec = el('section', { class: 'dsec', 'data-section': key ?? '' },
        el('header', { class: 'dsec-head' }, el('h2', {}, label, key !== null ? el('code', { text: key }) : null), count),
        list);
      sec.list = list;
      sections.push(sec);
    };
    if (Array.isArray(model) && isItems(model)) addSection(T.items, null, model);
    else if (isObj(model)) {
      Object.keys(model).forEach((k) => {
        if (isItems(model[k])) addSection(humanize(k), k, model[k]);
        else loose.push(k);
      });
    }
    if (isObj(model) && loose.length) {
      const fields = el('div', { class: 'dfields' });
      loose.forEach((k) => fields.append(fieldRow(model, k, { onChange: () => {}, listKeys: new Set(), rerender: renderForm })));
      formEl.append(el('section', { class: 'dsec' }, el('header', { class: 'dsec-head' }, el('h2', { text: sections.length ? T.other : T.fields })), fields));
    } else if (!isObj(model) && !sections.length) {
      formEl.append(el('p', { class: 'msg', text: T.rawOnly }));
    }
    sections.forEach((s) => formEl.append(s));
    applyFind();
  }

  // ------------------------------------------------------------------ search, and the item named in ?q=
  function topItems() { return [...formEl.querySelectorAll('.dsec > .dlist > .ditems > .ditem')]; }

  function applyFind() {
    const q = norm(findEl.value);
    formEl.querySelectorAll('.dsec').forEach((sec) => {
      const items = [...sec.querySelectorAll(':scope > .dlist > .ditems > .ditem')];
      if (!items.length) return;
      let shown = 0;
      items.forEach((li) => {
        const ok = !q || (li.search && li.search().includes(q));
        li.hidden = !ok;
        if (ok) shown++;
      });
      sec.classList.toggle('dempty', q !== '' && shown === 0);
      const c = sec.querySelector('.dcount');
      if (c) c.textContent = q ? fmt(T.found, shown, items.length) : (items.length === 1 ? T.count1 : fmt(T.countN, items.length));
    });
  }
  findEl.addEventListener('input', applyFind);

  function openBest(q) {
    const want = norm(q).split(' ').filter((w) => w.length > 2);
    if (!want.length) return;
    let best = null;
    let score = 0;
    topItems().forEach((li) => {
      const hay = li.search ? li.search() : '';
      const s = hay.includes(norm(q)) ? 1000 : want.filter((w) => hay.includes(w)).length;
      if (s > score) { score = s; best = li; }
    });
    if (!best || score < Math.min(2, want.length)) return;
    const det = best.querySelector('details');
    if (det) det.open = true;
    best.classList.add('flash');
    setTimeout(() => best.scrollIntoView({ block: 'start', behavior: 'smooth' }), 60);
    setTimeout(() => best.classList.remove('flash'), 2600);
  }

  // ------------------------------------------------------------------ form / raw JSON
  function setView(v) {
    if (v === view) return true;
    if (v === 'form') {
      try {
        model = JSON.parse(rawEl.value);
      } catch (e) {
        rawError(e);
        return false;
      }
      rawErr.hidden = true;
      cfg.error = '';
      renderForm();
    } else {
      rawEl.value = JSON.stringify(model, null, cfg.indent || '    ');
    }
    showView(v);
    return true;
  }
  function showView(v) {
    view = v;
    formEl.hidden = v !== 'form';
    rawBox.hidden = v !== 'raw';
    findEl.disabled = v !== 'form';
    root.querySelectorAll('[data-view]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.view === v)));
    root.querySelectorAll('[data-fold]').forEach((b) => { b.hidden = v !== 'form'; });
  }
  function rawError(e) {
    let msg = e.message || String(e);
    const m = msg.match(/position (\d+)/);
    if (m) {
      const upto = rawEl.value.slice(0, Number(m[1]));
      const line = upto.split('\n').length;
      msg = fmt(T.badJsonAt, line, msg);
      rawEl.focus();
      rawEl.setSelectionRange(Number(m[1]), Number(m[1]) + 1);
    }
    rawErr.textContent = `${T.badJson} ${msg}`;
    rawErr.hidden = false;
  }
  rawEl.addEventListener('input', () => { rawErr.hidden = true; changed(); });
  root.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => setView(b.dataset.view)));
  root.querySelectorAll('[data-fold]').forEach((b) => b.addEventListener('click', () => {
    topItems().forEach((li) => { const d = li.querySelector('details'); if (d && !li.hidden) d.open = b.dataset.fold === 'open'; });
  }));

  // ------------------------------------------------------------------ save
  async function post(action, body) {
    const r = await fetch(`${api}?action=${action}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body),
    });
    let d = {};
    try { d = await r.json(); } catch (e) { /* not JSON */ }
    if (r.status === 401) location.href = `index.php?next=${encodeURIComponent(location.pathname + location.search)}`;
    return { ok: r.ok && d.ok, status: r.status, data: d };
  }

  let busy = false;
  async function save() {
    if (busy || !dirty) return;
    if (view === 'raw') {
      try { model = JSON.parse(rawEl.value); } catch (e) { rawError(e); return; }
    }
    busy = true;
    saveBtn.disabled = true;
    statusEl.textContent = T.saving;
    try {
      const { ok, status, data } = await post('data_save', { file: cfg.file, hash, json: JSON.stringify(model) });
      if (ok) {
        hash = data.hash;
        dirty = false;
        if (view === 'raw' && typeof data.json === 'string') rawEl.value = data.json.replace(/\n$/, '');
        refresh(T.saved);
      } else if (status === 409) {
        refresh(T.conflict, true);
      } else {
        refresh(fmt(T.failed, data.error || status), true);
      }
    } catch (e) {
      refresh(fmt(T.failed, e.message), true);
    } finally {
      busy = false;
      saveBtn.disabled = !dirty;
    }
  }
  saveBtn.addEventListener('click', save);
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); }
  });
  discardBtn.addEventListener('click', () => {
    if (!discardBtn.classList.contains('confirm')) {
      discardBtn.classList.add('confirm');
      discardBtn.dataset.label = discardBtn.textContent;
      discardBtn.textContent = T.discardConfirm;
      setTimeout(() => { discardBtn.classList.remove('confirm'); discardBtn.textContent = discardBtn.dataset.label; }, 3500);
      return;
    }
    dirty = false;
    location.reload();
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = T.leave; } });

  // Restore a copy (second click confirms).
  document.querySelectorAll('[data-data-restore]').forEach((b) => {
    const label = b.textContent;
    b.addEventListener('click', async () => {
      if (!b.classList.contains('confirm')) {
        document.querySelectorAll('[data-data-restore].confirm').forEach((o) => { o.classList.remove('confirm'); o.textContent = o.dataset.label; });
        b.dataset.label = label;
        b.classList.add('confirm');
        b.textContent = b.dataset.confirm;
        return;
      }
      b.disabled = true;
      const { ok, data, status } = await post('data_restore', { file: b.dataset.file, backup: b.dataset.dataRestore });
      if (ok) { dirty = false; location.reload(); } else { b.disabled = false; b.textContent = `${data.error || status}`; }
    });
  });

  // Sticky tools sit under the admin's top bar.
  const top = document.querySelector('.top');
  const placeTools = () => { tools.style.top = `${top ? top.offsetHeight : 0}px`; };
  window.addEventListener('resize', placeTools);
  placeTools();

  // ------------------------------------------------------------------ start
  if (cfg.error) {
    showView('raw');
    rawErr.textContent = `${T.badJson} ${cfg.error}`;
    rawErr.hidden = false;
  } else {
    renderForm();
    if (cfg.q) openBest(cfg.q);
  }
  if (!cfg.writable) refresh(T.readonly, true);
  else refresh();
})();
