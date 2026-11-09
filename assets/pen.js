/*
 * Skrid pen: a small pen at the bottom left of the site's pages, for whoever is logged in to Skrid
 * (or, on 127.0.0.1, has been). Click = this page in Skrid (view.php), where you can edit it.
 *
 * Shown only when the "skrid_pen" cookie is there (set by Skrid at login), so visitors never see it.
 * Add to the site's pages:  <script src="/skrid/assets/pen.js" defer></script>
 * or load it only for Skrid users, from a site script:
 *   if (/(^|; )skrid_pen=1/.test(document.cookie)) { var s = document.createElement('script'); s.src = '/skrid/assets/pen.js'; s.defer = true; document.head.appendChild(s); }
 */
(function () {
  if (!/(^|; )skrid_pen=1/.test(document.cookie) || window.top !== window) return;
  var me = document.currentScript || document.querySelector('script[src*="assets/pen.js"]');
  if (!me) return;
  var base = new URL('..', me.src);                       // …/skrid/
  if (base.origin !== location.origin || location.pathname.indexOf(base.pathname) === 0) return;   // already in Skrid

  function show() {
    if (document.getElementById('skrid-pen')) return;
    var fr = /^fr\b/i.test(document.documentElement.lang || navigator.language || '');
    var label = fr ? 'Modifier cette page avec Skrid' : 'Edit this page with Skrid';
    var host = document.createElement('div');
    host.id = 'skrid-pen';
    host.style.cssText = 'all:initial;position:fixed;z-index:2147483000;' +
      'left:calc(16px + env(safe-area-inset-left, 0px));bottom:calc(16px + env(safe-area-inset-bottom, 0px));';
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    root.innerHTML = '<style>' +
      'a{display:grid;place-items:center;width:44px;height:44px;border-radius:50%;box-sizing:border-box;' +
      'background:rgba(24,24,28,.82);color:#fff;border:1px solid rgba(255,255,255,.18);text-decoration:none;' +
      'box-shadow:0 6px 20px rgba(0,0,0,.28);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);' +
      'opacity:.78;transition:opacity .15s,transform .15s,background .15s}' +
      'a:hover,a:focus-visible{opacity:1;transform:translateY(-2px);background:rgba(37,99,235,.92)}' +
      'a:focus-visible{outline:2px solid #fff;outline-offset:2px}' +
      'svg{width:20px;height:20px}' +
      '@media print{a{display:none}}' +
      '</style>' +
      '<a part="pen" title="' + label + '" aria-label="' + label + '">' +
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4zM13.5 6.5l4 4" ' +
      'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';
    var a = root.querySelector('a');
    function href() { return base.href + 'view.php?p=' + encodeURIComponent(location.pathname) + location.hash; }
    a.href = href();
    a.addEventListener('pointerenter', function () { a.href = href(); });   // the #anchor may have changed
    a.addEventListener('focus', function () { a.href = href(); });
    document.body.appendChild(host);
  }

  if (document.body) show();
  else document.addEventListener('DOMContentLoaded', show);
})();
