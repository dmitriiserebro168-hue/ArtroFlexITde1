<?php
/**
 * Дашборд поведения посетителей и журнала заявок (по образцу it2/stats.php).
 * Данные — track_event (поведение) и lead_log (заявки).
 * Пароль — stats_password в секретах (секрет GitHub STATS_PASSWORD → api/secrets.php). Пусто — дашборд закрыт.
 */
declare(strict_types=1);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');

$cfg = require __DIR__ . '/../api/config.php';
define('LP_DB', 1);
require __DIR__ . '/../api/db.php';

$password = (string)($cfg['stats_password'] ?? '');

// ---------------- Вход ----------------
$cookieName = 'lp_stats';
$token = $password !== '' ? hash_hmac('sha256', 'stats-ok', $password) : '';
$authed = $token !== '' && isset($_COOKIE[$cookieName]) && hash_equals($token, (string)$_COOKIE[$cookieName]);

function statsCookie(string $name, string $value, int $expires): void
{
    setcookie($name, $value, ['expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
}

if ($password !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    if (hash_equals($password, (string)$_POST['password'])) {
        statsCookie($cookieName, $token, time() + 30 * 86400);
        header('Location: stats.php', true, 303);
        exit;
    }
    sleep(1);
    $loginError = true;
}
if (isset($_GET['logout'])) {
    statsCookie($cookieName, '', 1);
    header('Location: stats.php', true, 303);
    exit;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Элементы лендинга de1 в порядке на странице (атрибут data-track в index.html)
$REGISTRY = [
    'cta-header'          => ['Кнопка', '«Jetzt bestellen» в шапке (телефон)'],
    'nav-joints'          => ['Меню', 'Gelenke'],
    'nav-composition'     => ['Меню', 'Zusammensetzung'],
    'nav-reviews'         => ['Меню', 'Bewertungen'],
    'nav-how'             => ['Меню', 'So bestellen Sie'],
    'nav-order'           => ['Меню', 'Bestellung (компьютер)'],
    'sec-hero'            => ['Секция', 'Первый экран: заголовок «Hilft gegen Gelenkschmerzen!»'],
    'sec-hero-form'       => ['Секция', 'Карточка цены с формой (первый экран)'],
    'link-privacy-hero'   => ['Ссылка', 'Datenschutzerklärung (форма первого экрана)'],
    'btn-submit-hero'     => ['Кнопка', '«JETZT BESTELLEN» (форма первого экрана)'],
    'sec-hero-feats'      => ['Секция', 'Четыре преимущества первого экрана'],
    'sec-hero-strip'      => ['Секция', 'Полоса иконок «Nr. 1, Natürliche Inhaltsstoffe…»'],
    'sec-joints'          => ['Секция', '«Degenerativ-dystrophische Erkrankungen…» и сравнение суставов'],
    'sec-artrosi'         => ['Секция', '«Arthrose (Osteoarthrose)» и 3 стадии'],
    'sec-others'          => ['Секция', '«Weitere Gelenkerkrankungen»'],
    'sec-recovery'        => ['Секция', '«ArtroFlex ACTIVE zur Regeneration nach Verletzungen»'],
    'sec-stress'          => ['Секция', '«Für jede Belastung der Gelenke»'],
    'sec-formula'         => ['Секция', 'Формула: 10 ингредиентов'],
    'sec-reviews'         => ['Секция', 'Отзывы Sabine, Monika, Petra'],
    'sec-how'             => ['Секция', '«So funktioniert die Bestellung»: 3 шага'],
    'sec-trust'           => ['Секция', 'Полоса «Schnelle Lieferung, Bezahlung bei Lieferung…»'],
    'sec-order'           => ['Секция', '«Bestellen Sie ArtroFlex ACTIVE»: текст и упаковка'],
    'sec-form'            => ['Секция', 'Основная форма (внизу)'],
    'link-privacy-main'   => ['Ссылка', 'Datenschutzerklärung (основная форма)'],
    'btn-submit-main'     => ['Кнопка', '«JETZT BESTELLEN» (основная форма)'],
];
// Формы: события form = <форма>-<шаг> (track.js)
$FORMS = ['hero' => 'Первый экран', 'main' => 'Основная (внизу)'];
$FORM_STEPS = [
    'focus-name'    => 'Нажали в поле имени',
    'focus-phone'   => 'Нажали в поле телефона',
    'submit'        => 'Нажали «JETZT BESTELLEN»',
    'error-name'    => 'Ошибка имени',
    'error-phone'   => 'Ошибка телефона',
    'error-consent' => 'Сняли галочку согласия',
    'error-alert'   => 'Ошибка отправки (сервер/партнёр)',
    'lead-ok'       => 'Заявка принята',
];
$SECTIONS = array_values(array_filter(array_keys($REGISTRY), fn($k) => strpos($k, 'sec-') === 0));

$LEAD_STATUS = [
    'ok'              => 'Принята партнёром',
    'api_error'       => 'Отклонена партнёром',
    'network'         => 'Партнёр недоступен',
    'config'          => 'Не настроен токен',
    'invalid_name'    => 'Неверное имя',
    'invalid_phone'   => 'Неверный телефон',
    'invalid_consent' => 'Нет согласия',
    'duplicate'       => 'Повторная отправка',
    'bot'             => 'Бот (ловушка)',
];
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ArtroFlex ACTIVE de1 — статистика</title>
<style>
  :root{--bg:#f4f6f5;--card:#fff;--ink:#163f35;--text:#1c2024;--muted:#5d6a66;--line:#e1e7e4;--bar:#1f6b58;--bar2:#c2410c;--ok:#1f6b58;--bad:#b42318}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
  .wrap{max-width:1140px;margin:0 auto;padding:16px}
  h1{font-size:1.4rem;color:var(--ink);margin:4px 0 12px}
  h2{font-size:1.05rem;color:var(--ink);margin:0 0 10px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:14px}
  .filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
  .filters a,.filters select{border:1px solid var(--line);background:#fff;border-radius:999px;padding:6px 12px;color:var(--ink);text-decoration:none;font:inherit}
  .filters a.on{background:var(--ink);color:#fff;border-color:var(--ink)}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
  .kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px}
  .kpi b{display:block;font-size:1.6rem;color:var(--ink);line-height:1.2}
  .kpi span{color:var(--muted);font-size:.85rem}
  table{width:100%;border-collapse:collapse}
  th,td{padding:8px 6px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}
  th{font-size:.8rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em}
  td.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
  tr.sec td{background:#f6faf8;font-weight:600}
  .kind{display:inline-block;font-size:.72rem;padding:1px 7px;border-radius:999px;background:#e7f2ee;color:var(--ink);margin-right:6px}
  .bar{height:8px;border-radius:4px;background:var(--line);min-width:80px;position:relative;overflow:hidden}
  .bar i{position:absolute;left:0;top:0;bottom:0;background:var(--bar);border-radius:4px}
  .bar.c i{background:var(--bar2)}
  .muted{color:var(--muted)}
  .scroll{overflow-x:auto}
  .st{display:inline-block;font-size:.78rem;font-weight:700;padding:1px 8px;border-radius:999px;white-space:nowrap}
  .st.ok{background:#e7f2ee;color:var(--ok)} .st.bad{background:#fdecea;color:var(--bad)} .st.n{background:#eee;color:var(--muted)}
  details.att summary{cursor:pointer;color:var(--muted)}
  details.att pre{white-space:pre-wrap;word-break:break-all;font-size:.75rem;background:#f6f8f7;padding:8px;border-radius:8px;max-width:520px}
  .login{max-width:360px;margin:12vh auto}
  .login input{width:100%;padding:12px;border:1px solid var(--line);border-radius:10px;font:inherit;margin:8px 0}
  .login button{width:100%;padding:12px;border:0;border-radius:10px;background:var(--ink);color:#fff;font:inherit;font-weight:700}
  .err{color:var(--bad)}
  .tabs{display:flex;gap:8px;margin-bottom:14px}
  .tabs a{padding:8px 14px;border-radius:10px;text-decoration:none;color:var(--ink);background:#fff;border:1px solid var(--line);font-weight:600}
  .tabs a.on{background:var(--ink);color:#fff;border-color:var(--ink)}
</style>
</head>
<body>
<?php if ($password === ''): ?>
  <div class="wrap login card">
    <h1>Дашборд не настроен</h1>
    <p>Задайте секрет <code>STATS_PASSWORD</code> в GitHub (Settings → Secrets and variables → Actions) и перезапустите деплой.</p>
  </div>
</body></html>
<?php exit; endif; ?>
<?php if (!$authed): ?>
  <form class="wrap login card" method="post" action="stats.php">
    <h1>ArtroFlex ACTIVE de1 — статистика</h1>
    <?php if (!empty($loginError)): ?><p class="err">Неверный пароль</p><?php endif; ?>
    <input type="password" name="password" placeholder="Пароль" autofocus autocomplete="current-password">
    <button type="submit">Войти</button>
  </form>
</body></html>
<?php exit; endif; ?>
<?php
$pdo = lp_db($cfg);
if (!$pdo) {
    echo '<div class="wrap card"><h1>Нет подключения к базе</h1><p>Проверьте секреты базы (<code>DB_PASSWORD</code>, при необходимости <code>DB_NAME</code> / <code>DB_USER</code>) в GitHub. Диагностика: <code>/api/lead.php?health=config</code></p>'
       . (!empty($GLOBALS['LP_DB_ERROR']) ? '<p class="err">' . h(preg_replace('/\(using password.*$/', '', (string)$GLOBALS['LP_DB_ERROR'])) . '</p>' : '')
       . '</div></body></html>';
    exit;
}

// ---------------- Фильтры ----------------
$tab  = (($_GET['tab'] ?? '') === 'leads') ? 'leads' : 'behavior';
$days = (int)($_GET['d'] ?? 7);
if (!in_array($days, [1, 7, 30, 90], true)) $days = 7;
$src = (string)($_GET['src'] ?? '');
$dev = in_array($_GET['dev'] ?? '', ['mobile', 'desktop'], true) ? $_GET['dev'] : '';

$where = 'created_at >= UTC_TIMESTAMP() - INTERVAL ' . $days . ' DAY';
$params = [];
if ($src !== '') { $where .= ' AND src = ?'; $params[] = $src; }
if ($dev !== '') { $where .= ' AND device = ?'; $params[] = $dev; }

$leadVer = ''; // в базе artrofle_de1 только этот лендинг — фильтр по версии не нужен

function q(PDO $pdo, string $sql, array $params) { $st = $pdo->prepare($sql); $st->execute($params); return $st; }
function pct($a, $b) { return $b > 0 ? round($a / $b * 100, 1) : 0; }
function link_with(array $over): string { $q = array_merge($_GET, $over); foreach ($q as $k => $v) { if ($v === '' || $v === null) unset($q[$k]); } return 'stats.php' . ($q ? '?' . http_build_query($q) : ''); }
function mask($p) { $p = (string)$p; return strlen($p) > 6 ? substr($p, 0, 6) . str_repeat('•', max(0, strlen($p) - 8)) . substr($p, -2) : $p; }
function berlin($utc) { $d = new DateTime((string)$utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone('Europe/Berlin')); return $d->format('d.m H:i'); }
?>
<div class="wrap">
  <h1>ArtroFlex ACTIVE (de1) — статистика <a class="muted" style="font-size:.8rem;float:right" href="stats.php?logout=1">выйти</a></h1>

  <div class="tabs">
    <a class="<?= $tab === 'behavior' ? 'on' : '' ?>" href="<?= h(link_with(['tab' => ''])) ?>">Поведение посетителей</a>
    <a class="<?= $tab === 'leads' ? 'on' : '' ?>" href="<?= h(link_with(['tab' => 'leads'])) ?>">Журнал заявок</a>
  </div>

  <div class="card filters">
    <?php foreach ([1 => 'Сутки', 7 => '7 дней', 30 => '30 дней', 90 => '90 дней'] as $d => $label): ?>
      <a class="<?= $d === $days ? 'on' : '' ?>" href="<?= h(link_with(['d' => $d])) ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <?php if ($tab === 'behavior'):
      $sources = $pdo->query("SELECT src, COUNT(DISTINCT pv) n FROM track_event WHERE type='load' AND created_at >= UTC_TIMESTAMP() - INTERVAL 90 DAY GROUP BY src ORDER BY n DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC); ?>
      <span class="muted">|</span>
      <?php foreach (['' => 'Все устройства', 'mobile' => 'Телефоны', 'desktop' => 'Компьютеры'] as $k => $label): ?>
        <a class="<?= $k === $dev ? 'on' : '' ?>" href="<?= h(link_with(['dev' => $k])) ?>"><?= $label ?></a>
      <?php endforeach; ?>
      <span class="muted">|</span>
      <select onchange="location.href=this.value">
        <option value="<?= h(link_with(['src' => ''])) ?>">Все источники</option>
        <?php foreach ($sources as $s): ?>
          <option value="<?= h(link_with(['src' => $s['src']])) ?>" <?= $s['src'] === $src && $src !== '' ? 'selected' : '' ?>>
            <?= h($s['src'] !== '' ? $s['src'] : '(без utm_source)') ?> — <?= (int)$s['n'] ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </div>

<?php if ($tab === 'leads'):
    // ---------------- Журнал заявок ----------------
    $lw = 'created_at >= UTC_TIMESTAMP() - INTERVAL ' . $days . ' DAY' . $leadVer;
    $byStatus = $pdo->query("SELECT status, COUNT(*) n FROM lead_log WHERE $lw GROUP BY status ORDER BY n DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
    $totalLog = array_sum($byStatus);
    $okCount  = (int)($byStatus['ok'] ?? 0);
    $avgMs    = (int)$pdo->query("SELECT AVG(duration_ms) FROM lead_log WHERE status='ok' AND $lw")->fetchColumn();
    // Группировка по кампании в PHP — без JSON-функций MySQL (работает на любой версии)
    $byCmp = [];
    foreach ($pdo->query("SELECT status, utm FROM lead_log WHERE $lw") as $r) {
        $u = $r['utm'] ? (json_decode($r['utm'], true) ?: []) : [];
        $c = (string)($u['utm_campaign'] ?? '');
        $byCmp[$c] = $byCmp[$c] ?? ['c' => $c, 'ok' => 0, 'n' => 0];
        $byCmp[$c]['n']++;
        if ($r['status'] === 'ok') $byCmp[$c]['ok']++;
    }
    usort($byCmp, fn($a, $b) => $b['ok'] <=> $a['ok'] ?: $b['n'] <=> $a['n']);
    $byCmp = array_slice($byCmp, 0, 20);
    $rows     = $pdo->query("SELECT * FROM lead_log WHERE $lw ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
?>
  <div class="kpis" style="margin-bottom:14px">
    <div class="kpi"><b><?= $okCount ?></b><span>заявок принято партнёром</span></div>
    <div class="kpi"><b><?= (int)(($byStatus['api_error'] ?? 0) + ($byStatus['network'] ?? 0) + ($byStatus['config'] ?? 0)) ?></b><span>ошибок отправки</span></div>
    <div class="kpi"><b><?= (int)(($byStatus['invalid_name'] ?? 0) + ($byStatus['invalid_phone'] ?? 0) + ($byStatus['invalid_consent'] ?? 0)) ?></b><span>отклонено проверкой формы</span></div>
    <div class="kpi"><b><?= $avgMs ? round($avgMs / 1000, 1) . ' с' : '—' ?></b><span>среднее время отправки</span></div>
  </div>

  <div class="card">
    <h2>Статусы</h2>
    <table>
      <?php foreach ($byStatus as $s => $n): ?>
        <tr><td style="width:220px"><span class="st <?= $s === 'ok' ? 'ok' : (in_array($s, ['api_error','network','config'], true) ? 'bad' : 'n') ?>"><?= h($LEAD_STATUS[$s] ?? $s) ?></span></td>
          <td><div class="bar <?= $s === 'ok' ? '' : 'c' ?>"><i style="width:<?= pct($n, $totalLog) ?>%"></i></div></td>
          <td class="num" style="width:130px"><?= (int)$n ?> · <?= pct($n, $totalLog) ?>%</td></tr>
      <?php endforeach; ?>
      <?php if (!$byStatus): ?><tr><td class="muted">За период записей нет</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="card scroll">
    <h2>По кампаниям (utm_campaign)</h2>
    <table>
      <tr><th>Кампания</th><th class="num">Принято</th><th class="num">Всего попыток</th></tr>
      <?php foreach ($byCmp as $c): ?>
        <tr><td><?= h($c['c'] !== '' ? $c['c'] : '(без utm_campaign)') ?></td><td class="num"><?= (int)$c['ok'] ?></td><td class="num"><?= (int)$c['n'] ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card scroll">
    <h2>Последние записи (до 200)</h2>
    <p class="muted" style="margin-top:-4px">Время — Берлин. Телефон скрыт частично; полные данные — таблица <code>lead_log</code> в phpMyAdmin.</p>
    <table>
      <tr><th>Время</th><th>Статус</th><th>Имя</th><th>Телефон</th><th>Кампания</th><th>Ответ партнёра</th><th class="num">мс</th><th>Попытки</th></tr>
      <?php foreach ($rows as $r):
          $utm = $r['utm'] ? (json_decode($r['utm'], true) ?: []) : [];
          $s = $r['status']; ?>
        <tr>
          <td class="num"><?= h(berlin($r['created_at'])) ?></td>
          <td><span class="st <?= $s === 'ok' ? 'ok' : (in_array($s, ['api_error','network','config'], true) ? 'bad' : 'n') ?>"><?= h($LEAD_STATUS[$s] ?? $s) ?></span></td>
          <td><?= h(mb_substr((string)$r['name'], 0, 30)) ?></td>
          <td class="num"><?= h(mask($r['phone'])) ?></td>
          <td><?= h($utm['utm_campaign'] ?? '') ?></td>
          <td><?= h($r['guid'] ?: ($r['api_msg'] ?: '')) ?></td>
          <td class="num"><?= (int)$r['duration_ms'] ?></td>
          <td><?php if ($r['attempts']): ?><details class="att"><summary><?= count(json_decode($r['attempts'], true) ?: []) ?></summary><pre><?= h(json_encode(json_decode($r['attempts'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="muted">За период записей нет</td></tr><?php endif; ?>
    </table>
  </div>

<?php else:
    // ---------------- Показатели поведения ----------------
    $visits    = (int)q($pdo, "SELECT COUNT(DISTINCT pv) FROM track_event WHERE type='load' AND $where", $params)->fetchColumn();
    $avgTime   = (float)q($pdo, "SELECT AVG(m) FROM (SELECT MAX(val) m FROM track_event WHERE type='leave' AND $where GROUP BY pv) x", $params)->fetchColumn();
    $avgDepth  = (float)q($pdo, "SELECT AVG(m) FROM (SELECT MAX(val) m FROM track_event WHERE type='depth' AND $where GROUP BY pv) x", $params)->fetchColumn();
    $ctaVisits = (int)q($pdo, "SELECT COUNT(DISTINCT pv) FROM track_event WHERE type='click' AND (el LIKE 'cta-%' OR el LIKE 'btn-submit-%') AND $where", $params)->fetchColumn();
    // Визиты без действий: ни одного нажатия на размеченные элементы и ни одного шага в формах
    $idleVisits = (int)q($pdo, "SELECT COUNT(*) FROM (SELECT pv FROM track_event WHERE $where GROUP BY pv
        HAVING SUM(type='load') > 0 AND SUM(type IN ('click','form')) = 0) x", $params)->fetchColumn();
    // Короткие визиты: на странице меньше 10 секунд
    $shortVisits = (int)q($pdo, "SELECT COUNT(*) FROM (SELECT MAX(val) m FROM track_event WHERE type='leave' AND $where GROUP BY pv) x WHERE m < 10", $params)->fetchColumn();

    // Воронка: визиты с шагом в каждой форме и в любой из них
    $funnel = [];
    foreach ($FORM_STEPS as $step => $label) {
        foreach (array_keys($FORMS) as $fn) {
            $funnel[$step][$fn] = (int)q($pdo, "SELECT COUNT(DISTINCT pv) FROM track_event WHERE type='form' AND el=? AND $where", array_merge([$fn . '-' . $step], $params))->fetchColumn();
        }
        $in = implode(',', array_fill(0, count($FORMS), '?'));
        $funnel[$step]['all'] = (int)q($pdo, "SELECT COUNT(DISTINCT pv) FROM track_event WHERE type='form' AND el IN ($in) AND $where",
            array_merge(array_map(fn($fn) => $fn . '-' . $step, array_keys($FORMS)), $params))->fetchColumn();
    }
    $leadsOk = (int)$pdo->query("SELECT COUNT(*) FROM lead_log WHERE status='ok' AND created_at >= UTC_TIMESTAMP() - INTERVAL $days DAY$leadVer")->fetchColumn();

    $scroll = [];
    foreach ([25, 50, 75, 90, 100] as $m) {
        $scroll[$m] = (int)q($pdo, "SELECT COUNT(DISTINCT pv) FROM track_event WHERE type='scroll' AND val=? AND $where", array_merge([$m], $params))->fetchColumn();
    }

    $stats = [];
    $st = q($pdo, "SELECT el,
            COUNT(DISTINCT CASE WHEN type='view'  THEN pv END) AS view_pv,
            SUM(type='click') AS clicks,
            COUNT(DISTINCT CASE WHEN type='click' THEN pv END) AS click_pv
        FROM track_event WHERE type IN ('view','click') AND el <> '' AND $where GROUP BY el", $params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $stats[$r['el']] = $r; }

    // Где уходят: последняя увиденная секция
    $lastSec = array_fill_keys($SECTIONS, 0);
    $order = array_flip($SECTIONS);
    $st = q($pdo, "SELECT pv, el FROM track_event WHERE type='view' AND el LIKE 'sec-%' AND $where", $params);
    $maxByPv = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($order[$r['el']])) continue;
        $i = $order[$r['el']];
        if (!isset($maxByPv[$r['pv']]) || $i > $maxByPv[$r['pv']]) $maxByPv[$r['pv']] = $i;
    }
    foreach ($maxByPv as $i) { $lastSec[$SECTIONS[$i]]++; }
    $lastTotal = array_sum($lastSec);
?>
  <div class="kpis" style="margin-bottom:14px">
    <div class="kpi"><b><?= $visits ?></b><span>визитов</span></div>
    <div class="kpi"><b><?= $avgTime ? gmdate($avgTime >= 3600 ? 'G:i:s' : 'i:s', (int)$avgTime) : '—' ?></b><span>среднее время на странице</span></div>
    <div class="kpi"><b><?= $avgDepth ? round($avgDepth) . '%' : '—' ?></b><span>средняя глубина прокрутки</span></div>
    <div class="kpi"><b><?= pct($ctaVisits, $visits) ?>%</b><span>нажали кнопку (<?= $ctaVisits ?>)</span></div>
    <div class="kpi"><b><?= $idleVisits ?></b><span>визитов без действий — <?= pct($idleVisits, $visits) ?>% (ничего не нажали и не начали заполнять форму)</span></div>
    <div class="kpi"><b><?= $shortVisits ?></b><span>визитов короче 10 секунд — <?= pct($shortVisits, $visits) ?>%</span></div>
    <div class="kpi"><b><?= $funnel['lead-ok']['all'] ?></b><span>отправили заявку (<?= pct($funnel['lead-ok']['all'], $visits) ?>%): первый экран <?= $funnel['lead-ok']['hero'] ?>, основная <?= $funnel['lead-ok']['main'] ?></span></div>
    <div class="kpi"><b><?= $leadsOk ?></b><span>лидов принято партнёром<?= ($src !== '' || $dev !== '') ? ' (без фильтров)' : '' ?></span></div>
  </div>

  <div class="card">
    <h2>Глубина прокрутки</h2>
    <table>
      <?php foreach ($scroll as $m => $n): ?>
        <tr><td style="width:90px">≥ <?= $m ?>%</td>
          <td><div class="bar"><i style="width:<?= pct($n, $visits) ?>%"></i></div></td>
          <td class="num" style="width:130px"><?= $n ?> · <?= pct($n, $visits) ?>%</td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card scroll">
    <h2>Воронка форм</h2>
    <p class="muted" style="margin-top:-4px">Число визитов, где был шаг, и % от всех визитов. «Обе» — визит засчитан один раз, даже если человек пользовался двумя формами.</p>
    <table>
      <tr><th>Шаг</th><?php foreach ($FORMS as $fn => $label): ?><th class="num"><?= h($label) ?></th><?php endforeach; ?><th>Обе формы</th><th class="num"></th></tr>
      <?php foreach ($FORM_STEPS as $k => $label): $all = $funnel[$k]['all']; ?>
        <tr><td><?= h($label) ?></td>
          <?php foreach (array_keys($FORMS) as $fn): ?><td class="num"><?= $funnel[$k][$fn] ?> · <?= pct($funnel[$k][$fn], $visits) ?>%</td><?php endforeach; ?>
          <td><div class="bar <?= strpos($k, 'error') === 0 ? 'c' : '' ?>"><i style="width:<?= pct($all, $visits) ?>%"></i></div></td>
          <td class="num" style="width:110px"><?= $all ?> · <?= pct($all, $visits) ?>%</td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card scroll">
    <h2>Элементы страницы (по порядку)</h2>
    <p class="muted" style="margin-top:-4px">«Увидели» — элемент был на экране ≥ 1 с. CTR — доля увидевших, кто нажал. % — от всех визитов.</p>
    <table>
      <tr><th>Элемент</th><th>Увидели</th><th class="num">визитов</th><th>Нажали</th><th class="num">кликов / визитов</th><th class="num">CTR</th></tr>
      <?php foreach ($REGISTRY as $id => $info):
          $s = $stats[$id] ?? ['view_pv' => 0, 'clicks' => 0, 'click_pv' => 0];
          $isSec = $info[0] === 'Секция'; ?>
        <tr class="<?= $isSec ? 'sec' : '' ?>">
          <td><?php if (!$isSec): ?><span class="kind"><?= h($info[0]) ?></span><?php endif; ?><?= h($info[1]) ?> <span class="muted" style="font-size:.75rem"><?= h($id) ?></span></td>
          <td><div class="bar"><i style="width:<?= pct($s['view_pv'], $visits) ?>%"></i></div></td>
          <td class="num"><?= (int)$s['view_pv'] ?> · <?= pct($s['view_pv'], $visits) ?>%</td>
          <td><div class="bar c"><i style="width:<?= pct($s['click_pv'], $visits) ?>%"></i></div></td>
          <td class="num"><?= (int)$s['clicks'] ?> / <?= (int)$s['click_pv'] ?></td>
          <td class="num"><?= $s['view_pv'] ? pct($s['click_pv'], $s['view_pv']) . '%' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h2>Где уходят: последняя секция, которую увидели</h2>
    <table>
      <?php foreach ($lastSec as $id => $n): ?>
        <tr><td><?= h($REGISTRY[$id][1]) ?></td>
          <td><div class="bar c"><i style="width:<?= pct($n, $lastTotal) ?>%"></i></div></td>
          <td class="num" style="width:130px"><?= $n ?> · <?= pct($n, $lastTotal) ?>%</td></tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

  <p class="muted">Данные хранятся 90 дней. Свои визиты можно не учитывать: открывайте лендинг с <code>?notrack=1</code>.</p>
</div>
</body>
</html>
