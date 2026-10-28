# ArtroFlex ACTIVE DE — лендинг de1.artro-flex.com

Немецкая копия лендинга m1 (`ArtroFlex_mary`, репозиторий ArtroFlexITm1): та же вёрстка на HTML/CSS и
серверная часть на PHP, весь текст — на немецком (обращение на «Sie»), лиды уходят с гео **DE**.

Отличия от m1:

| | m1 | de1 |
|---|---|---|
| Домен | m1.artro-flex.com | de1.artro-flex.com |
| Язык страницы | it | de |
| Телефон | `+39`, 10–11 цифр | `+49`, 9–11 цифр без ведущего 0 |
| `country` / `lang` в лидах | IT / it | DE / de |
| `landing_version` | artro-it-m1 | artro-de-de1 |
| База MySQL | artrofle_m1 | artrofle_de1 |
| Часовой пояс журнала | Рим | Берлин |

## Как идёт заявка

Форма (их две: в карточке цены первого экрана — `hero`, внизу — `main`) → `api/lead.php`:

1. **Проверка** — имя 2–60 символов; телефон с фиксированным `+49`, после него 9–11 цифр, первая не 0
   (немцы пишут номер с ведущим 0 — `0151 …`, `030 …` — он убирается; вставленный `+49`/`0049`/`49` отрезается);
   галочка согласия; ловушка для ботов (скрытое поле `website`); повтор той же заявки с тем же `request_id` не создаёт дубль.
2. **LeadCenter** (`https://leadcenter.artro-flex.com/api/lead`, ключ источника в `X-Api-Key`) — основной получатель,
   с `country: DE`; в Webvork отправляет он. Если LeadCenter не ответил и задан `WEBVORK_TOKEN` — заявка уходит
   в Webvork напрямую (`country=DE`, `lang=de`).
3. **Журнал** — таблица `lead_log` в MySQL `artrofle_de1` (каждая попытка со статусом, ответами и временем),
   ошибки — в `_data/logs/ГГГГ-ММ.log`.

Посетителю «Anfrage erhalten!» показывается только после того, как получатель принял заявку.

**Важно для LeadCenter:** партнёр Webvork по умолчанию шлёт `lang=it` и `offer_id=208`. Для источника de1
нужен отдельный партнёр (или вкладка) Webvork с `lang=de` и немецким `offer_id`, если он отличается.

## Аналитика посещений

`assets/js/track.js` → `api/track.php` → таблица `track_event`. Без cookies и без IP. События: загрузка,
просмотр блоков (≥ 1 с на экране), клики по элементам с `data-track`, глубина прокрутки, шаги форм, время на странице.
`?notrack=1` — не учитывать свой визит, `?trackdebug=1` — события только в консоль.

Дашборд — `https://de1.artro-flex.com/admin/stats.php` (пароль `STATS_PASSWORD`), интерфейс на русском.

Таблицы создаются сами при первом обращении. Данные хранятся 90 дней.

## Пиксель Meta

ID `973449535833462` (тот же, что на m1) — в `assets/js/config.js → analytics.metaPixelId` (пусто — пиксель выключен).
`PageView` при загрузке, `InitiateCheckout` при начале заполнения формы, `Lead` — только когда заявку принял получатель.

## Секреты и деплой

Каждый push в `main` выкладывает сайт по FTPS (`.github/workflows/deploy.yml`). Перед выкладкой проверяется синтаксис PHP
и из секретов репозитория собирается `api/secrets.php` (в git не попадает, закрыт от веба `api/.htaccess`).

| Секрет GitHub | Значение |
|---|---|
| `FTP_SERVER` | `ftp.artro-flex.com` |
| `FTP_USERNAME` | `de1@artro-flex.com` |
| `FTP_PASSWORD` | пароль FTP-аккаунта de1 |
| `DB_PASSWORD` | пароль пользователя MySQL `artrofle_de1` |
| `DB_NAME`, `DB_USER` | необязательно, по умолчанию `artrofle_de1` |
| `LEADCENTER_KEY` | API-ключ источника de1 из LeadCenter → Источники |
| `STATS_PASSWORD` | пароль дашборда |
| `WEBVORK_TOKEN` | необязательно: запасная прямая отправка в Webvork |

Вместо секретов GitHub можно загрузить `artroflex-de1-config.php` (образец — `config.example.php`) уровнем выше
папки сайта — он имеет приоритет.

**Проверка после деплоя:** `https://de1.artro-flex.com/api/lead.php?health=config` — без раскрытия секретов
показывает `db_connected`, `leadcenter_set`, `leadcenter_ping` (ответ LeadCenter на ключ) и т. д.
