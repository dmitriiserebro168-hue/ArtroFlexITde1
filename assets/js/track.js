// ===== Аналитика поведения (свой сервер: api/track.php → MySQL, отчёт — admin/stats.php) =====
// Перенесено с it3b (it2). Без cookies и localStorage: «визит» = один просмотр страницы,
// id живёт только в памяти вкладки. IP не хранится. События: load, view (элемент реально увидели),
// click, scroll (25/50/75/90/100 %), form (фокус в полях, отправка, ошибки, успех),
// leave (время на странице и максимальная глубина). Элементы размечены атрибутом data-track.
// ?notrack=1 — не собирать (для себя); ?trackdebug=1 — события в консоль, на сервер не отправляются.
(function () {
  try {
    if (/[?&]notrack=1\b/.test(location.search)) return;
    if (location.protocol === 'file:') return;
    var DEBUG = /[?&]trackdebug=1\b/.test(location.search);
    var ENDPOINT = 'api/track.php';
    var qs = new URLSearchParams(location.search);
    var meta = {
      lv: ((window.LANDING_CONFIG || {}).landingVersion || '').slice(0, 20), // вариант A/B
      pv: (Date.now().toString(36) + Math.random().toString(36).slice(2, 10)).slice(0, 16),
      src: (qs.get('utm_source') || '').slice(0, 100),
      cmp: (qs.get('utm_campaign') || '').slice(0, 100),
      dev: window.matchMedia('(max-width: 767px)').matches ? 'mobile' : 'desktop'
    };
    var t0 = Date.now(), buf = [], once = {}, visibleMs = 0,
        visibleSince = document.visibilityState === 'visible' ? Date.now() : 0, maxDepth = 0, left = false;

    function push(type, el, val) {
      buf.push([type, el || '', val == null ? 0 : Math.round(val), Math.round((Date.now() - t0) / 1000)]);
      if (DEBUG) console.info('[track]', type, el || '', val == null ? '' : val);
      if (buf.length >= 25) flush();
    }
    function pushOnce(key, type, el, val) { if (once[key]) return; once[key] = 1; push(type, el, val); }
    function flush() {
      while (buf.length) {
        var body = JSON.stringify({ lv: meta.lv, pv: meta.pv, src: meta.src, cmp: meta.cmp, dev: meta.dev, e: buf.splice(0, 50) });
        if (DEBUG) continue;
        try {
          if (navigator.sendBeacon) navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'text/plain' }));
          else fetch(ENDPOINT, { method: 'POST', body: body, keepalive: true });
        } catch (e) {}
      }
    }

    push('load');

    // Просмотры: элемент считается увиденным, если ≥1 с на экране видна ≥ половина элемента
    // (или он занимает ≥ половины экрана — для высоких секций). Один раз за визит.
    var timers = {};
    var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        var id = en.target.getAttribute('data-track');
        var vis = en.intersectionRect.height;
        var need = Math.min(en.boundingClientRect.height * 0.5, window.innerHeight * 0.5);
        if (en.isIntersecting && vis >= need) {
          if (!timers[id] && !once['v:' + id]) timers[id] = setTimeout(function () { pushOnce('v:' + id, 'view', id); }, 1000);
        } else if (timers[id]) { clearTimeout(timers[id]); timers[id] = null; }
      });
    }, { threshold: [0, 0.1, 0.25, 0.5, 0.75, 1] }) : null;
    function observe(root) {
      if (!io) return;
      (root || document).querySelectorAll('[data-track]').forEach(function (n) { io.observe(n); });
    }
    observe();
    window.lpTrackObserve = observe; // для элементов, добавленных позже (отзывы из редактора)

    // Клики: ближайший размеченный элемент (кнопка внутри секции → кнопка)
    document.addEventListener('click', function (e) {
      var n = e.target.closest && e.target.closest('[data-track]');
      if (n) push('click', n.getAttribute('data-track'));
    }, true);
    // FAQ: раскрытие вопроса
    document.addEventListener('toggle', function (e) {
      var d = e.target;
      if (d.tagName === 'DETAILS' && d.open && d.getAttribute('data-track')) push('click', d.getAttribute('data-track'));
    }, true);

    // Глубина прокрутки
    function onScroll() {
      var h = Math.max(document.documentElement.scrollHeight - window.innerHeight, 1);
      var d = Math.min(100, Math.round(window.scrollY / h * 100));
      if (d > maxDepth) maxDepth = d;
      [25, 50, 75, 90, 100].forEach(function (m) { if (d >= m) pushOnce('s:' + m, 'scroll', '', m); });
    }
    window.addEventListener('scroll', onScroll, { passive: true });

    // Формы: первый фокус в полях, попытки отправки, ошибки, успех.
    // Событие = <форма>-<что>: hero-… — форма в карточке цены первого экрана, main-… — основная внизу
    // (например hero-focus-name, main-submit, hero-lead-ok).
    document.querySelectorAll('[data-lead-root]').forEach(function (root) {
      var fn = root.getAttribute('data-lead-root');
      var q = function (k) { return root.querySelector('[data-f="' + k + '"]'); };
      var form = q('form');
      if (!form) return;
      form.addEventListener('focusin', function (e) {
        var k = e.target.getAttribute('data-f');
        if (k === 'name') pushOnce('f:' + fn + ':name', 'form', fn + '-focus-name');
        if (k === 'phone') pushOnce('f:' + fn + ':phone', 'form', fn + '-focus-phone');
      });
      form.addEventListener('submit', function () { push('form', fn + '-submit'); }, true);
      // Ошибки показываются снятием атрибута hidden
      [['name-err', 'error-name'], ['phone-err', 'error-phone'], ['consent-err', 'error-consent'], ['error', 'error-alert']].forEach(function (p) {
        var n = q(p[0]);
        if (n) new MutationObserver(function () { if (!n.hidden) push('form', fn + '-' + p[1]); })
          .observe(n, { attributes: true, attributeFilter: ['hidden'] });
      });
      var ok = q('success');
      if (ok) new MutationObserver(function () { if (!ok.hidden && root.hasAttribute('data-sent')) pushOnce('f:' + fn + ':ok', 'form', fn + '-lead-ok'); })
        .observe(ok, { attributes: true, attributeFilter: ['hidden'] });
    });

    // Время на странице (только пока вкладка видна) и уход
    function leave() {
      if (left) return; left = true;
      if (visibleSince) { visibleMs += Date.now() - visibleSince; visibleSince = 0; }
      push('leave', '', Math.round(visibleMs / 1000));
      push('depth', '', maxDepth);
      flush();
    }
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') { leave(); }
      else { left = false; visibleSince = Date.now(); } // вернулся на вкладку: при следующем уходе запишем общее время
    });
    window.addEventListener('pagehide', leave);
    setInterval(flush, 10000);
    setTimeout(flush, 3000);
  } catch (e) { if (window.console) console.warn('[track] disabled:', e); }
})();
