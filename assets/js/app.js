(function () {
  'use strict';

  var cfg = window.LANDING_CONFIG || {};
  var isDemo = !!cfg.demo || location.protocol === 'file:';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function storeGet(k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } }
  function storeSet(k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) { /* приватный режим */ } }

  /* ---------- События для dataLayer (без персональных данных) ---------- */
  window.dataLayer = window.dataLayer || [];
  function track(name, params) {
    var data = { event: name };
    for (var k in params || {}) data[k] = params[k];
    window.dataLayer.push(data);
  }

  /* ---------- Пиксель Meta (как на it3b): PageView здесь, Lead — после принятой заявки ---------- */
  var noTrack = /[?&]notrack=1\b/.test(location.search);
  function initPixel() {
    var id = (cfg.analytics || {}).metaPixelId;
    if (!id || isDemo || noTrack) return;
    /* eslint-disable */
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    /* eslint-enable */
    window.fbq('init', id);
    window.fbq('track', 'PageView');
  }
  // InitiateCheckout — один раз за визит, когда посетитель начал заполнять любую форму заявки
  var checkoutFired = false;
  function pixelCheckout() {
    if (checkoutFired || !window.fbq) return;
    checkoutFired = true;
    window.fbq('track', 'InitiateCheckout');
  }

  // Cookie пикселя (_fbp, _fbc) — уходят с заявкой, чтобы LeadCenter передал их в Conversions API при апруве
  function cookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  // Lead — один раз на заявку, без value и без имени/телефона
  function pixelLead(leadId) {
    if (!window.fbq || !leadId) return;
    var key = 'lp_lead_fired_' + leadId;
    if (storeGet(key)) return;
    window.fbq('track', 'Lead', {}, { eventID: leadId });
    storeSet(key, '1');
  }

  /* ---------- UTM и рекламные параметры (как на it3b) ---------- */
  var TRACK_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
    'campaign_id', 'adset_id', 'ad_id', 'fbclid'];
  function captureParams() {
    var q = new URLSearchParams(location.search);
    var saved = {};
    try { saved = JSON.parse(storeGet('lp_params') || '{}') || {}; } catch (e) { saved = {}; }
    TRACK_PARAMS.forEach(function (p) {
      var v = q.get(p);
      if (v) saved[p] = v.slice(0, 200);
    });
    storeSet('lp_params', JSON.stringify(saved));
    return saved;
  }

  /* ---------- «Jetzt bestellen» / «Bestellung» в шапке: сразу в поле имени нижней формы ---------- */
  // Фокус ставится в самом нажатии, чтобы на телефоне открылась клавиатура.
  function initToOrder() {
    $$('[data-to-order]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        var root = $('[data-lead-root="main"]');
        var field = $('#order-name');
        if (!root || !field) return;
        e.preventDefault();
        var card = $('[data-f="card"]', root);
        var target = card && card.hidden ? $('[data-f="success"]', root) : field;
        target.focus({ preventScroll: true });
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    });
  }

  /* ---------- Имя и телефон: правила как на it3b ---------- */
  // Имя: 2–60 символов после обрезки и схлопывания пробелов.
  var NAME_MIN = 2, NAME_MAX = 60;
  function cleanName(v) { return String(v || '').trim().replace(/\s+/g, ' '); }
  function validName(v) { var l = cleanName(v).length; return l >= NAME_MIN && l <= NAME_MAX; }

  // Телефон: префикс +49 фиксирован, вводится национальный номер.
  //  • после +49 — 9–11 цифр, первая не 0 (мобильные 015x/016x/017x дают 10–11 цифр, стационарные — от 9);
  //  • ведущий 0, с которым немцы пишут номер (0151 …, 030 …), убирается;
  //  • если вставили номер с +49 / 0049 / 49 — префикс отрезается.
  var DE_MAX = 11, DE_VALID = /^[1-9]\d{8,10}$/;
  function cleanPhone(v) {
    var raw = String(v || '').trim();
    var d = raw.replace(/\D/g, '');
    if (raw.charAt(0) === '+') { if (d.indexOf('49') === 0) d = d.slice(2); }
    else if (d.indexOf('0049') === 0) d = d.slice(4);
    else if (d.indexOf('49') === 0 && d.length > DE_MAX) d = d.slice(2); // национальный номер не длиннее 11 цифр
    d = d.replace(/^0+/, '');
    return d.slice(0, DE_MAX);
  }
  function normPhone(v) { var d = cleanPhone(v); return DE_VALID.test(d) ? d : null; }
  function prettyPhone(d) {
    // Код отдельно: Берлин, Гамбург, Франкфурт, Мюнхен — 2 цифры, мобильные и остальные — 3
    if (/^(30|40|69|89)\d/.test(d)) return d.replace(/^(\d{2})(\d+)$/, '$1 $2');
    return d.replace(/^(\d{3})(\d+)$/, '$1 $2');
  }

  /* ---------- Формы заявки ----------
     На странице две формы: в карточке цены первого экрана и внизу. Каждая обёрнута
     в [data-lead-root="<имя>"], элементы внутри помечены data-f="…". */
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  function send(payload) {
    if (isDemo) {
      return new Promise(function (res, rej) {
        setTimeout(function () {
          if (/^fehler$/i.test(payload.name)) rej(new Error('demo'));
          else res({ ok: true, lead_id: 'demo-' + payload.request_id.slice(0, 8) });
        }, 900);
      });
    }
    var ctrl = window.AbortController ? new AbortController() : null;
    var t = ctrl && setTimeout(function () { ctrl.abort(); }, (cfg.form && cfg.form.timeoutMs) || 15000);
    return fetch(cfg.form.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload),
      signal: ctrl ? ctrl.signal : undefined,
      credentials: 'same-origin'
    }).then(function (r) {
      if (t) clearTimeout(t);
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok || d.ok !== true || !d.lead_id) throw new Error(d.error || ('http_' + r.status));
        return d;
      });
    }, function (e) { if (t) clearTimeout(t); throw e; });
  }

  function initForms(params) {
    var requireConsent = !(cfg.form && cfg.form.requireConsent === false);
    $$('[data-lead-root]').forEach(function (root) { initLeadForm(root, params, requireConsent); });
  }

  function initLeadForm(root, params, requireConsent) {
    var f = function (k) { return root.querySelector('[data-f="' + k + '"]'); };
    var formName = root.getAttribute('data-lead-root');
    var form = f('form');
    if (!form) return;
    var name = f('name'), phone = f('phone'), consent = f('consent');
    var btn = f('submit'), formErr = f('error');
    var nameErr = f('name-err'), phoneErr = f('phone-err'), consentErr = f('consent-err');
    if (!requireConsent && f('consent-field')) { f('consent-field').remove(); consent = null; }

    var started = false, busy = false, pending = null;

    form.addEventListener('input', function () {
      if (!started) { started = true; track('form_start', { form: formName }); pixelCheckout(); }
    });

    function setErr(input, errEl, show) {
      if (errEl) errEl.hidden = !show;
      input.setAttribute('aria-invalid', show ? 'true' : 'false');
      var fld = input.closest('.field');
      if (fld) { fld.classList.toggle('is-invalid', show); if (show) fld.classList.remove('is-valid'); }
    }
    var phoneField = phone.closest('.field');

    name.addEventListener('input', function () { setErr(name, nameErr, false); });
    name.addEventListener('blur', function () { if (name.value) setErr(name, nameErr, !validName(name.value)); });

    phone.addEventListener('input', function () {
      // Человек набирает префикс вручную («+», «+4», «+49», «0», «00», «004», «0049») — ждём следующую цифру
      if (/^(\+|\+4|\+49|0|00|004|0049)$/.test(phone.value.replace(/\s/g, ''))) {
        phoneField.classList.remove('is-valid'); setErr(phone, phoneErr, false); return;
      }
      var d = cleanPhone(phone.value);
      if (phone.value.replace(/\s/g, '') !== d) phone.value = d;
      var ok = !!normPhone(d);
      phoneField.classList.toggle('is-valid', ok);
      setErr(phone, phoneErr, !ok && d.length >= DE_MAX);
    });
    phone.addEventListener('blur', function () {
      var d = cleanPhone(phone.value);
      if (d) phone.value = prettyPhone(d);
      var ok = !!normPhone(d);
      phoneField.classList.toggle('is-valid', ok);
      setErr(phone, phoneErr, !!d && !ok);
    });
    if (consent) consent.addEventListener('change', function () { if (consent.checked) setErr(consent, consentErr, false); });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy) return; // защита от двойной отправки
      formErr.hidden = true;

      var n = cleanName(name.value);
      var digits = normPhone(phone.value);
      var p = digits ? '+49' + digits : null;   // на сервер уходит +49…
      var badName = !validName(n);
      var badPhone = !p;
      var badConsent = !!consent && !consent.checked;
      setErr(name, nameErr, badName);
      setErr(phone, phoneErr, badPhone);
      if (consent) setErr(consent, consentErr, badConsent);
      if (badName || badPhone || badConsent) {
        (badName ? name : badPhone ? phone : consent).focus();
        return;
      }

      // Один и тот же request_id для повторов с теми же данными → сервер не создаст дубль
      var key = n + '|' + p;
      if (!pending || pending.key !== key) pending = { key: key, id: uuid() };

      var payload = {
        request_id: pending.id,
        name: n,
        phone: p,
        consent: consent ? consent.checked : null,
        privacy_version: cfg.form && cfg.form.privacyVersion,
        offer_id: cfg.offerId,
        landing_version: cfg.landingVersion,
        params: params,
        fbp: cookie('_fbp'),
        fbc: cookie('_fbc'),
        website: f('website') ? f('website').value : ''
      };

      busy = true;
      btn.setAttribute('aria-busy', 'true');
      btn.disabled = true;
      track('form_submit', { form: formName });

      send(payload).then(function (d) {
        track('lead_accepted', { lead_id: d.lead_id, duplicate: !!d.duplicate, form: formName });
        pixelLead(d.lead_id);
        if (f('success-phone')) f('success-phone').textContent = '+49 ' + prettyPhone(digits);
        // После заявки обе формы показывают «заявка принята», чтобы не отправили второй раз.
        // data-sent — какая форма отправила (аналитика засчитывает «заявка принята» только ей).
        root.setAttribute('data-sent', '');
        $$('[data-lead-root]').forEach(function (r) {
          var c = r.querySelector('[data-f="card"]'), s = r.querySelector('[data-f="success"]');
          var sp = r.querySelector('[data-f="success-phone"]');
          if (sp) sp.textContent = '+49 ' + prettyPhone(digits);
          if (c) c.hidden = true;
          if (s) s.hidden = false;
        });
        f('success').focus();
      }).catch(function (err) {
        track('lead_error', { reason: String(err && err.message || err).slice(0, 40), form: formName });
        formErr.textContent = 'Die Anfrage konnte nicht gesendet werden. Bitte versuchen Sie es erneut.';
        formErr.hidden = false;
        formErr.scrollIntoView({ block: 'center' });
      }).then(function () {
        busy = false;
        btn.removeAttribute('aria-busy');
        btn.disabled = false;
      });
    });
  }

  /* ---------- Старт ---------- */
  initToOrder();
  initForms(captureParams());
  initPixel();
  track('page_view', { landing_version: cfg.landingVersion });
})();
