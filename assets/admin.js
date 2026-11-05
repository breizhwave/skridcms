/* Skrid admin: search, title & description, restore, password, language, log out. */
(() => {
  'use strict';
  const csrf = document.querySelector('meta[name="skrid-csrf"]').content;
  const api = document.querySelector('meta[name="skrid-api"]').content;

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

  function say(el, text, bad = false) {
    el.textContent = text;
    el.classList.toggle('bad', bad);
  }

  // Page tree: search keeps a page's parents visible and opens them; collapse / expand all.
  const search = document.querySelector('[data-search]');
  const tree = document.querySelector('[data-tree]');
  if (search && tree) {
    const kids = (li) => [...li.querySelectorAll(':scope > details > ul > li, :scope > ul > li')];
    const filter = (li, q) => {
      let below = false;
      kids(li).forEach((k) => { below = filter(k, q) || below; });
      const self = q === '' || li.dataset.text.includes(q);
      li.hidden = !(self || below);
      const d = li.querySelector(':scope > details');
      if (d && q !== '' && below) d.open = true;
      return self || below;
    };
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      [...tree.children].forEach((li) => filter(li, q));
    });
    document.querySelectorAll('[data-fold]').forEach((b) => b.addEventListener('click', () => {
      tree.querySelectorAll('details').forEach((d, i) => { d.open = b.dataset.fold === 'open' || i === 0; });
    }));
  }

  // Title & description, with counters and a Google-like preview.
  const meta = document.querySelector('[data-meta]');
  if (meta) {
    const title = meta.querySelector('[name="title"]');
    const desc = meta.querySelector('[name="description"]');
    const out = meta.querySelector('.msg-inline');
    const update = () => {
      meta.querySelectorAll('[data-count-for]').forEach((c) => {
        const f = document.getElementById(c.dataset.countFor);
        const n = [...f.value].length;
        c.textContent = `${n} / ${c.dataset.max}`;
        c.classList.toggle('bad', n > Number(c.dataset.max));
      });
      meta.querySelector('[data-serp-title]').textContent = title.value;
      meta.querySelector('[data-serp-desc]').textContent = desc.value;
    };
    title.addEventListener('input', update);
    desc.addEventListener('input', update);
    update();
    meta.addEventListener('submit', async (e) => {
      e.preventDefault();
      const { ok, status, data } = await post('meta', { page: meta.dataset.page, hash: meta.dataset.hash, title: title.value, description: desc.value });
      if (ok) {
        meta.dataset.hash = data.hash;
        say(out, document.documentElement.lang === 'fr' ? 'Enregistré.' : 'Saved.');
      } else {
        say(out, status === 409 ? (document.documentElement.lang === 'fr' ? 'La page a changé : rechargez.' : 'The page changed: reload.') : `Error: ${data.error || status}`, true);
      }
    });
  }

  // Restore: a second click confirms (no browser dialog).
  document.querySelectorAll('[data-restore]').forEach((b) => {
    const label = b.textContent;
    b.addEventListener('click', async () => {
      if (!b.classList.contains('confirm')) {
        document.querySelectorAll('[data-restore].confirm').forEach((o) => { o.classList.remove('confirm'); o.textContent = o.dataset.label; });
        b.dataset.label = label;
        b.classList.add('confirm');
        b.textContent = b.dataset.confirm;
        return;
      }
      b.disabled = true;
      const { ok, data, status } = await post('restore', { page: b.dataset.page, backup: b.dataset.restore });
      if (ok) location.reload();
      else { b.disabled = false; b.textContent = `Error: ${data.error || status}`; }
    });
  });

  // Password.
  const pw = document.querySelector('[data-password]');
  if (pw) {
    const out = pw.querySelector('.msg-inline');
    pw.addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = new FormData(pw);
      const { ok, data, status } = await post('password', { current: f.get('current'), new: f.get('new'), new2: f.get('new2') });
      if (ok) { pw.reset(); say(out, out.dataset.ok); } else say(out, data.error || String(status), true);
    });
  }

  // Text styles.
  const st = document.querySelector('[data-styles]');
  if (st) {
    const out = st.querySelector('.msg-inline');
    st.addEventListener('submit', async (e) => {
      e.preventDefault();
      const { ok, data, status } = await post('styles', {
        builtin: st.querySelector('[name="builtin"]').checked,
        inline: st.querySelector('[name="inline"]').value,
        block: st.querySelector('[name="block"]').value,
      });
      if (ok) say(out, out.dataset.ok); else say(out, data.error || String(status), true);
    });
  }

  // Editor language.
  const lang = document.querySelector('[data-lang]');
  if (lang) {
    lang.addEventListener('change', async () => {
      const { ok } = await post('lang', { lang: lang.value });
      if (ok) location.reload();
    });
  }

  // Log out.
  document.querySelectorAll('[data-logout]').forEach((b) => {
    b.addEventListener('click', async () => {
      await post('logout', {});
      location.href = b.dataset.to || '/';
    });
  });
})();
