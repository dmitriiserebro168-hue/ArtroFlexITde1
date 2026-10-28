/*
 * Настройки лендинга de1.artro-flex.com (немецкая копия m1, гео DE).
 */
window.LANDING_CONFIG = {
  // Идентификатор лендинга: уходит в журнал заявок (lead_log.landing_version),
  // в LeadCenter и в аналитику (track_event.lv)
  landingVersion: "artro-de-de1",
  offerId: "208",

  form: {
    endpoint: "api/lead.php",
    timeoutMs: 40000,   // LeadCenter, при сбое — Webvork (2 адреса × 2 попытки)
    // Галочка «прошу перезвонить» (отмечена по умолчанию, как на m1).
    // Отключать вместе с require_consent в api/config.php.
    requireConsent: true,
    privacyVersion: "privacy-de-de1-1"
  },

  analytics: {
    // Пиксель Meta: PageView при загрузке, Lead — когда заявку принял получатель (eventID = id заявки).
    // Пусто — пиксель не загружается. На визитах с ?notrack=1 и в демо-режиме не загружается.
    metaPixelId: "973449535833462"
  },

  // Демо-режим: заявки не отправляются, ответ сервера имитируется (имя «fehler» — ошибка).
  // Включается сам при открытии файла с диска (file://).
  demo: false
};
