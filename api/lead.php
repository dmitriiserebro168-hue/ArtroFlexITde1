<?php
/*
 * Приём заявки и передача партнёру (сервер → сервер).
 * Успех ({"ok":true}) возвращается ТОЛЬКО после подтверждения приёма партнёром.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Ответ лендингу. Если указан $log — сначала запись в журнал lead_log (как в it2). */
function out(int $code, array $body, ?string $log = null, array $logExtra = []): void
{
    if ($log !== null && isset($GLOBALS['cfg'])) lead_log($GLOBALS['cfg'], $log, $logExtra);
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_file(__DIR__ . '/config.php')) {
    out(500, ['ok' => false, 'error' => 'not_configured']);
}
$cfg = require __DIR__ . '/config.php';
date_default_timezone_set('Europe/Berlin');
define('LP_DB', 1);
require __DIR__ . '/db.php';

const LOG_KEEP_DAYS   = 90;  // записи журнала старше — удаляются автоматически
const ALERT_EVERY_MIN = 30;  // письма об ошибках — не чаще

// Контекст текущей заявки для журнала и список попыток отправки
$LEAD = ['started' => microtime(true)];
$ATTEMPTS_LOG = [];

/** Запись о заявке в lead_log. Никогда не бросает исключений. */
function lead_log(array $cfg, string $status, array $extra = []): void
{
    global $LEAD, $ATTEMPTS_LOG;
    try {
        $pdo = lp_db($cfg);
        if ($pdo) {
            $st = $pdo->prepare('INSERT INTO lead_log
                (created_at, status, lead_id, guid, api_msg, name, phone, ip, utm, user_agent, referer, attempts, duration_ms, landing_version)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([
                gmdate('Y-m-d H:i:s'),
                $status,
                $LEAD['lead_id'] ?? null,
                isset($extra['guid']) ? substr((string)$extra['guid'], 0, 64) : null,
                isset($extra['api_msg']) ? mb_substr((string)$extra['api_msg'], 0, 255) : null,
                $LEAD['name'] ?? null,
                $LEAD['phone'] ?? null,
                $LEAD['ip'] ?? null,
                !empty($LEAD['utm']) ? json_encode($LEAD['utm'], JSON_UNESCAPED_UNICODE) : null,
                $LEAD['ua'] ?? null,
                $LEAD['ref'] ?? null,
                $ATTEMPTS_LOG ? json_encode($ATTEMPTS_LOG, JSON_UNESCAPED_UNICODE) : null,
                (int)round((microtime(true) - $LEAD['started']) * 1000),
                $LEAD['lv'] ?? null,
            ]);
            if (mt_rand(1, 100) === 1) {
                $pdo->exec('DELETE FROM lead_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL ' . LOG_KEEP_DAYS . ' DAY');
            }
        }
        if (in_array($status, ['api_error', 'network', 'config'], true)) {
            lead_alert($cfg, $status, (string)($extra['api_msg'] ?? ''));
        }
    } catch (Throwable $e) {
        error_log('lead.php: lead_log failed: ' . $e->getMessage());
    }
}

/** Письмо об ошибке отправки лида на 'alert_email', не чаще ALERT_EVERY_MIN минут. */
function lead_alert(array $cfg, string $status, string $apiMsg): void
{
    global $LEAD, $ATTEMPTS_LOG;
    if (empty($cfg['alert_email'])) return;
    $pdo = lp_db($cfg);
    if ($pdo) {
        $last = $pdo->query("SELECT v FROM lead_meta WHERE k = 'last_alert'")->fetchColumn();
        if ($last && time() - (int)$last < ALERT_EVERY_MIN * 60) return;
        $pdo->prepare("REPLACE INTO lead_meta (k, v) VALUES ('last_alert', ?)")->execute([(string)time()]);
    }
    $host  = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'site');
    $phone = isset($LEAD['phone']) ? substr($LEAD['phone'], 0, 6) . '…' . substr($LEAD['phone'], -2) : '';
    $lines = [
        'Время (UTC): ' . gmdate('Y-m-d H:i:s'),
        'Статус: ' . $status,
        'Ответ партнёра: ' . ($apiMsg !== '' ? $apiMsg : '—'),
        'Телефон: ' . $phone,
        'Попытки:',
    ];
    foreach ($ATTEMPTS_LOG as $a) {
        $lines[] = sprintf('  #%d %s HTTP %d, %d мс %s', $a['n'], $a['host'], $a['http'], $a['ms'], $a['error'] !== '' ? '(' . $a['error'] . ')' : '');
    }
    $lines[] = '';
    $lines[] = 'Подробности — https://' . $host . '/admin/stats.php или таблица lead_log в phpMyAdmin. Следующее письмо не раньше чем через ' . ALERT_EVERY_MIN . ' мин.';
    $subject = '=?UTF-8?B?' . base64_encode('[' . $host . '] Ошибка отправки лида: ' . $status) . '?=';
    @mail((string)$cfg['alert_email'], $subject, implode("\n", $lines),
        "From: noreply@{$host}\r\nContent-Type: text/plain; charset=UTF-8\r\n");
}

$dataDir = rtrim($cfg['data_dir'], '/\\');
foreach (['requests', 'logs'] as $sub) {
    if (!is_dir("$dataDir/$sub")) @mkdir("$dataDir/$sub", 0750, true);
}

function log_event(string $dir, string $level, string $msg, array $ctx = []): void
{
    $line = date('c') . " [$level] $msg " . json_encode($ctx, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents("$dir/logs/" . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function mask_phone(string $p): string
{
    return substr($p, 0, 5) . str_repeat('*', max(0, strlen($p) - 7)) . substr($p, -2);
}

// Оставить только цифры и «+», формат строго +49 и 9–11 цифр без ведущего 0 (как проверяет лендинг)
function normalize_phone(string $raw): ?string
{
    $s = preg_replace('/[^\d+]/', '', $raw);
    return preg_match('/^\+49[1-9]\d{8,10}$/', $s) ? $s : null;
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// ---------- Базовые проверки запроса ----------
// lead.php?health=config — проверка настроек без отправки лида и без раскрытия секретов
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['health'] ?? '') === 'config') {
    $reach = null; // Webvork проверяем, только если задан токен запасного пути
    if (function_exists('curl_init') && (string)($cfg['partner']['static']['token'] ?? '') !== '') {
        $ch = curl_init($cfg['partner']['url']);
        curl_setopt_array($ch, [CURLOPT_CONNECT_ONLY => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8]);
        $reach = curl_exec($ch) !== false;
        curl_close($ch);
    }
    out(200, [
        'ok'                => true,
        'secrets_file'      => $GLOBALS['LP_SECRETS_FILE'] ?? null,
        'secrets_error'     => $GLOBALS['LP_SECRETS_ERROR'] ?? null,
        'webvork_fallback'  => (string)($cfg['partner']['static']['token'] ?? '') !== '',
        'offer_id'          => (string)($cfg['partner']['static']['offer_id'] ?? ''),
        'curl'              => function_exists('curl_init'),
        'data_writable'     => is_writable($dataDir),
        'webvork_reachable' => $reach,
        'db_configured'     => !empty($cfg['db']['name']),
        'db_connected'      => lp_db($cfg) !== null,
        'db_error'          => isset($GLOBALS['LP_DB_ERROR']) ? preg_replace('/\(using password.*$/', '', (string)$GLOBALS['LP_DB_ERROR']) : null,
        'alert_email_set'   => (string)($cfg['alert_email'] ?? '') !== '',
        'telegram_set'      => !empty($cfg['telegram']['token']) && !empty($cfg['telegram']['chat_id']),
        'telegram_chat_id'  => (string)($cfg['telegram']['chat_id'] ?? ''),
        'stats_password_set'=> (string)($cfg['stats_password'] ?? '') !== '',
        'leadcenter_set'    => !empty($cfg['leadcenter']['url']) && !empty($cfg['leadcenter']['key']),
        'leadcenter_ping'   => leadcenter_ping($cfg),
        'leadcenter_last'   => json_decode((string)@file_get_contents($dataDir . '/leadcenter_last.json'), true),
        'db_counts'         => db_counts($cfg),
        'track_last_error'  => json_decode((string)@file_get_contents($dataDir . '/track_last_error.json'), true),
        'php'               => PHP_VERSION,
    ]);
}

/** Сколько записей за сутки в журнале и аналитике — только числа и время, без имён и телефонов. */
function db_counts(array $cfg): ?array
{
    $pdo = lp_db($cfg);
    if (!$pdo) return null;
    try {
        $day = 'created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY';
        return [
            'lead_log_24h'    => $pdo->query("SELECT status, COUNT(*) FROM lead_log WHERE $day GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR),
            'track_24h'       => $pdo->query("SELECT type, COUNT(*) FROM track_event WHERE $day GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR),
            'track_clicks_24h'=> $pdo->query("SELECT el, COUNT(*) FROM track_event WHERE type IN ('click','form') AND $day GROUP BY el")->fetchAll(PDO::FETCH_KEY_PAIR),
            'track_last_utc'  => $pdo->query('SELECT MAX(created_at) FROM track_event')->fetchColumn(),
            // ВРЕМЕННО для отладки: последние события без персональных данных
            'track_recent'    => $pdo->query("SELECT CONCAT(created_at, ' ', LEFT(pv, 5), ' ', device, ' ', type, ' ', el, ' v', val, ' t', t, ' ', src) FROM track_event ORDER BY id DESC LIMIT 80")->fetchAll(PDO::FETCH_COLUMN),
        ];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(405, ['ok' => false, 'error' => 'method']);
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$sameOrigin = $origin === 'https://' . ($_SERVER['HTTP_HOST'] ?? ''); // запрос со своей же страницы
if ($origin !== '' && !$sameOrigin && !empty($cfg['allowed_origins']) && !in_array($origin, $cfg['allowed_origins'], true)) {
    out(403, ['ok' => false, 'error' => 'origin']);
}
$raw = file_get_contents('php://input', false, null, 0, 8192);
$in = json_decode((string)$raw, true);
if (!is_array($in)) {
    out(400, ['ok' => false, 'error' => 'bad_request']);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$now = time();

// Данные для журнала — как пришли, до проверки
$LEAD['name']  = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
$LEAD['phone'] = substr(preg_replace('/[^\d+]/', '', (string)($in['phone'] ?? '')), 0, 20);
$LEAD['ip']    = $ip;
$LEAD['ua']    = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$LEAD['ref']   = mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);
$LEAD['lv']    = substr(preg_replace('/[^a-z0-9.\-]/', '', strtolower((string)($in['landing_version'] ?? ''))), 0, 20) ?: null;
$LEAD['utm']   = [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'campaign_id', 'adset_id', 'ad_id', 'fbclid'] as $k) {
    $v = $in['params'][$k] ?? '';
    if (is_string($v) && $v !== '') $LEAD['utm'][$k] = mb_substr($v, 0, 200);
}

// Honeypot: боту отвечаем «успехом», ничего не отправляя
if (!empty($in['website'])) {
    log_event($dataDir, 'info', 'honeypot');
    out(200, ['ok' => true, 'lead_id' => uuid4()], 'bot');
}

// ---------- Валидация ----------
// Как в it2: имя — 2–60 символов после обрезки и схлопывания пробелов
$name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
$nameLen = mb_strlen($name, 'UTF-8');
if ($nameLen < 2 || $nameLen > 60) {
    out(422, ['ok' => false, 'error' => 'name', 'field' => 'name'], 'invalid_name');
}
$phone = normalize_phone((string)($in['phone'] ?? ''));
if ($phone === null) {
    out(422, ['ok' => false, 'error' => 'phone', 'field' => 'phone'], 'invalid_phone');
}
if (!empty($cfg['require_consent']) && ($in['consent'] ?? false) !== true) {
    out(422, ['ok' => false, 'error' => 'consent', 'field' => 'consent'], 'invalid_consent');
}
$requestId = (string)($in['request_id'] ?? '');
if (!preg_match('/^[0-9a-f-]{36}$/i', $requestId)) {
    out(400, ['ok' => false, 'error' => 'request_id']);
}

// ---------- Идемпотентность: тот же request_id → тот же ответ ----------
$reqFile = "$dataDir/requests/" . hash('sha256', $requestId) . '.json';
$lock = fopen($reqFile . '.lock', 'c');
flock($lock, LOCK_EX);
if (is_file($reqFile)) {
    $prev = json_decode((string)file_get_contents($reqFile), true);
    if (!empty($prev['ok'])) {
        $LEAD['lead_id'] = $prev['lead_id'];
        out(200, ['ok' => true, 'lead_id' => $prev['lead_id'], 'duplicate' => true], 'duplicate');
    }
}


// ---------- Формирование лида ----------
$params = is_array($in['params'] ?? null) ? $in['params'] : [];
$clean = function ($v) { return is_string($v) ? mb_substr(strip_tags($v), 0, 200) : null; };
$leadId = uuid4();
$LEAD['lead_id'] = $leadId;
$createdAt = date('c');
$lead = [
    'lead_id'         => $leadId,
    'name'            => $name,
    'phone'           => $phone,
    'created_at'      => $createdAt,
    'offer_id'        => $clean($in['offer_id'] ?? null),
    'landing_version' => $clean($in['landing_version'] ?? null),
    'campaign_id'     => $clean($params['campaign_id'] ?? null),
    'ad_id'           => $clean($params['ad_id'] ?? null),
    'utm_source'      => $clean($params['utm_source'] ?? null),
    'utm_medium'      => $clean($params['utm_medium'] ?? null),
    'utm_campaign'    => $clean($params['utm_campaign'] ?? null),
    'utm_content'     => $clean($params['utm_content'] ?? null),
    'utm_term'        => $clean($params['utm_term'] ?? null),
    'country'         => 'DE',
    'ip'              => $ip,
    'consent'         => ($in['consent'] ?? null) === true,
    'consent_at'      => $createdAt,
    'privacy_version' => $clean($in['privacy_version'] ?? null),
    'offer_expires_at'=> $clean($in['offer_expires_at'] ?? null),
];

$payload = $cfg['partner']['static'];
// Получатели: LeadCenter (основной) и Webvork напрямую (запасной, только с токеном)
$haveLeadcenter = !empty($cfg['leadcenter']['url']) && !empty($cfg['leadcenter']['key']) && function_exists('curl_init');
$haveWebvork    = (string)($payload['token'] ?? '') !== '';
if (!$haveLeadcenter && !$haveWebvork) {
    log_event($dataDir, 'error', 'no_lead_receiver');
    out(500, ['ok' => false, 'error' => 'not_configured'], 'config');
}
foreach ($cfg['partner']['map'] as $ours => $theirs) {
    if ($theirs !== null && $theirs !== '' && array_key_exists($ours, $lead)) $payload[$theirs] = $lead[$ours];
}

// ---------- LeadCenter — основной получатель заявки: в Webvork отправляет он ----------
/**
 * Синхронная отправка заявки в LeadCenter (блок 'leadcenter' => url, key в секретном конфиге).
 * @return array [HTTP-код, ответ, ошибка curl, мс]
 */
function leadcenter_send(array $cfg, array $payload): array
{
    $lc = $cfg['leadcenter'];
    $code = 0;
    $resp = '';
    $err = '';
    $t0 = microtime(true);
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($lc['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Api-Key: ' . $lc['key']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $resp = is_string($resp) ? $resp : '';
        if ($code === 200 || ($code >= 400 && $code < 500)) break;
        if ($attempt < 2) sleep(1);
    }
    // Результат последней отправки — для lead.php?health=config
    @file_put_contents(rtrim($cfg['data_dir'], '/\\') . '/leadcenter_last.json', json_encode([
        'at' => date('c'), 'http' => $code, 'response' => mb_substr($resp, 0, 300),
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    return [$code, $resp, $err, (int)round((microtime(true) - $t0) * 1000)];
}

/** Cookie пикселя Meta (_fbp / _fbc) в формате fb.1.<время>.<значение>, иначе null. */
function fb_cookie($v): ?string
{
    return is_string($v) && strlen($v) <= 500 && preg_match('/^fb\.\d\.\d+\.[A-Za-z0-9_.\-]+$/', $v) ? $v : null;
}

/** Проверка ключа LeadCenter без создания лида: GET …/api/ping. */
function leadcenter_ping(array $cfg): ?array
{
    $lc = $cfg['leadcenter'] ?? [];
    if (empty($lc['url']) || empty($lc['key']) || !function_exists('curl_init')) return null;
    $ch = curl_init(preg_replace('#/lead/?$#', '/ping', (string)$lc['url']));
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $lc['key']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $code, 'response' => json_decode((string)$resp, true) ?? mb_substr((string)$resp, 0, 200)];
}

// ---------- Отправка партнёру с повтором при временной ошибке ----------
function partner_send(array $p, string $url, array $payload, string $leadId): array
{
    $isJson = $p['format'] === 'json';
    $body = $isJson ? json_encode($payload, JSON_UNESCAPED_UNICODE) : http_build_query($payload);
    $headers = [
        'Content-Type: ' . ($isJson ? 'application/json' : 'application/x-www-form-urlencoded'),
        'Accept: application/json',
        'Idempotency-Key: ' . $leadId,
    ];
    if (!empty($p['auth_header']) && trim((string)$p['auth_value']) !== 'Bearer') {
        $headers[] = $p['auth_header'] . ': ' . $p['auth_value'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $p['method'],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)$p['timeout'],
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $t0 = microtime(true);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, is_string($resp) ? $resp : '', $err, (int)round((microtime(true) - $t0) * 1000)];
}

$p = $cfg['partner'];
$accepted = false;
$attempts = 0;
$lastCode = 0;
$lastErr = '';
$urls = array_values(array_filter([$p['url'], $p['fallback_url'] ?? '']));
$resp = '';
$done = false;
$apiMsg = '';
$guid = '';
$apiRejected = false;
$viaLeadcenter = false;

// Сначала LeadCenter. Принял — готово, в Webvork лид отправит он.
// Не ответил — отправляем в Webvork напрямую (если задан токен), чтобы не потерять заявку.
if ($haveLeadcenter) {
    [$lcCode, $lcResp, $lcErr, $lcMs] = leadcenter_send($cfg, [
        'external_id'     => $leadId,
        'name'            => $name,
        'phone'           => $phone,
        'country'         => 'DE',
        'ip'              => $ip,
        'user_agent'      => $LEAD['ua'],
        'referrer'        => $LEAD['ref'],
        'landing_version' => $lead['landing_version'],
        'site'            => $_SERVER['HTTP_HOST'] ?? '',
        // Cookie пикселя Meta — для Conversions API (Purchase при апруве)
        'fbp'             => fb_cookie($in['fbp'] ?? null),
        'fbc'             => fb_cookie($in['fbc'] ?? null),
    ] + $LEAD['utm']);
    $ATTEMPTS_LOG[] = [
        'n' => 1, 'host' => parse_url((string)$cfg['leadcenter']['url'], PHP_URL_HOST), 'http' => $lcCode,
        'ms' => $lcMs, 'error' => $lcErr, 'body' => mb_substr($lcResp, 0, 1000),
    ];
    $lcJson = json_decode($lcResp, true);
    if ($lcCode === 200 && !empty($lcJson['ok'])) {
        $accepted = true;
        $viaLeadcenter = true;
        $attempts = 1;
        $resp = $lcResp;
        $guid = 'leadcenter#' . ($lcJson['lead_id'] ?? '');
    } else {
        $lastCode = $lcCode;
        $lastErr = $lcErr;
        $resp = $lcResp;
        $apiMsg = (string)($lcJson['error'] ?? '');
        $apiRejected = $lcCode >= 400 && $lcCode < 500; // ключ отклонён, неверные данные — не сеть
        log_event($dataDir, 'error', $haveWebvork ? 'leadcenter_failed_direct_webvork' : 'leadcenter_failed', [
            'lead_id' => $leadId, 'http' => $lcCode, 'curl' => $lcErr, 'resp' => mb_substr($lcResp, 0, 300),
        ]);
    }
}

if (!$accepted && $haveWebvork) do {
    $attempts++;
    // Основной адрес, при сетевой/5xx ошибке — запасной (как в it2)
    foreach ($urls as $url) {
        [$lastCode, $resp, $lastErr, $ms] = partner_send($p, $url, $payload, $leadId);
        $ATTEMPTS_LOG[] = [
            'n' => $attempts, 'host' => parse_url($url, PHP_URL_HOST), 'http' => $lastCode,
            'ms' => $ms, 'error' => $lastErr, 'body' => mb_substr($resp, 0, 1000),
        ];
        $temporary = $lastCode === 0 || $lastCode === 429 || $lastCode >= 500;
        if ($lastCode >= 200 && $lastCode < 300) {
            $j = json_decode($resp, true);
            $accepted = true;
            if (!empty($p['success_check'])) {
                $accepted = is_array($j) && ($j[$p['success_check']['field']] ?? null) == $p['success_check']['value'];
            }
            if (is_array($j)) {
                $guid = (string)($j['guid'] ?? '');
                $apiMsg = (string)($j['msg'] ?? '');
            }
            $apiRejected = !$accepted; // Webvork ответил, но отклонил (дубль, неверный токен…)
            $done = true;
            break;
        }
        if (!$temporary) { $done = true; break; }
    }
    if ($done) break;
    if ($attempts <= $p['retries']) sleep(2);
} while ($attempts <= $p['retries']);

if (!$accepted) {
    log_event($dataDir, 'error', 'partner_failed', [
        'lead_id' => $leadId, 'phone' => mask_phone($phone),
        'http' => $lastCode, 'curl' => $lastErr, 'attempts' => $attempts,
        'resp' => mb_substr((string)($resp ?? ''), 0, 300),
    ]);
    @file_put_contents($reqFile, json_encode(['ok' => false, 'at' => $now]), LOCK_EX);
    out(502, ['ok' => false, 'error' => 'partner'], $apiRejected ? 'api_error' : 'network', ['api_msg' => $apiMsg]);
}

// На диске — только хэш request_id и lead_id, без имени и телефона
@file_put_contents($reqFile, json_encode(['ok' => true, 'lead_id' => $leadId, 'at' => $now]), LOCK_EX);
log_event($dataDir, 'info', 'lead_accepted', ['lead_id' => $leadId, 'attempts' => $attempts, 'utm_campaign' => $lead['utm_campaign']]);

// Изредка чистим старые служебные файлы
if (random_int(1, 50) === 1) {
    $limit = $now - $cfg['retention_days'] * 86400;
    foreach (['requests'] as $sub) {
        foreach (glob("$dataDir/$sub/*") ?: [] as $f) {
            if (filemtime($f) < $limit) @unlink($f);
        }
    }
}

// ---------- Ответ лендингу, затем уведомление в Telegram (не задерживает посетителя) ----------
lead_log($cfg, 'ok', ['guid' => $guid]);
http_response_code(200);
echo json_encode(['ok' => true, 'lead_id' => $leadId], JSON_UNESCAPED_UNICODE);
if (function_exists('litespeed_finish_request')) litespeed_finish_request();
elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

// Лид, принятый LeadCenter, сообщает в Telegram сам LeadCenter — здесь только прямые отправки в Webvork
if (!$viaLeadcenter) tg_notify($cfg, implode("\n", [
    '✅ <b>Новый лид — ' . tg_esc($_SERVER['HTTP_HOST'] ?? 'de1.artro-flex.com') . '</b>',
    '👤 Имя: ' . tg_esc($name),
    '📞 Телефон: ' . tg_esc($phone),
    '📨 Ответ ' . ($viaLeadcenter ? 'LeadCenter' : 'Webvork (напрямую)') . ': <code>' . tg_esc(mb_substr((string)$resp, 0, 500)) . '</code>',
    '🅱️ Вариант: ' . tg_esc($LEAD['lv'] ?? '—'),
    '🎯 Кампания: ' . tg_esc(($LEAD['utm']['utm_campaign'] ?? '—') . ' / ' . ($LEAD['utm']['utm_source'] ?? '—')),
    '🕒 ' . date('d.m.Y H:i') . ' (Берлин)',
]));
exit;

/** HTML-экранирование для parse_mode=HTML */
function tg_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Сообщение в Telegram через Bot API. Ошибки только в журнал, на заявку не влияют. */
function tg_notify(array $cfg, string $html): bool
{
    $t = $cfg['telegram'] ?? [];
    $token = trim((string)($t['token'] ?? ''));
    $chat  = trim((string)($t['chat_id'] ?? ''));
    if ($token === '' || $chat === '' || !function_exists('curl_init')) return false;
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $code === 200 && is_string($resp) && strpos($resp, '"ok":true') !== false;
    if (!$ok) {
        // Токен в журнал не пишем
        error_log('lead.php: telegram failed: HTTP ' . $code . ' ' . mb_substr((string)$resp, 0, 300));
        $dir = rtrim($cfg['data_dir'], '/\\');
        @file_put_contents("$dir/logs/" . date('Y-m') . '.log',
            date('c') . ' [error] telegram_failed ' . json_encode(['http' => $code, 'resp' => mb_substr((string)$resp, 0, 300)], JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX);
    }
    return $ok;
}
