<?php
/**
 * Приём событий поведения с лендинга (navigator.sendBeacon, JSON в теле запроса) — как в it2.
 * Пишет в таблицу track_event. IP и cookies не используются. Ответ всегда 204 — лендингу он не нужен.
 */
declare(strict_types=1);

const MAX_EVENTS = 60;   // за один запрос
const KEEP_DAYS  = 90;   // хранение событий

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

// Боты и сканеры не нужны в статистике
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|headless|lighthouse|preview/i', $ua)) {
    http_response_code(204);
    exit;
}

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($in) || empty($in['pv']) || empty($in['e']) || !is_array($in['e'])) {
    http_response_code(204);
    exit;
}

$pv  = substr(preg_replace('/[^a-z0-9]/', '', strtolower((string)$in['pv'])), 0, 16);
$dev = (($in['dev'] ?? '') === 'mobile') ? 'mobile' : 'desktop';
$src = substr(preg_replace('/[^\w.\-]/u', '', (string)($in['src'] ?? '')), 0, 100);
$cmp = substr(preg_replace('/[^\w.\-]/u', '', (string)($in['cmp'] ?? '')), 0, 100);
$lv  = substr(preg_replace('/[^a-z0-9.\-]/', '', strtolower((string)($in['lv'] ?? ''))), 0, 20); // вариант лендинга
if ($pv === '') {
    http_response_code(204);
    exit;
}

$types = ['load', 'view', 'click', 'scroll', 'form', 'leave', 'depth'];
$rows = [];
foreach (array_slice($in['e'], 0, MAX_EVENTS) as $e) {
    if (!is_array($e) || count($e) < 4) continue;
    $type = (string)$e[0];
    $el   = (string)$e[1];
    if (!in_array($type, $types, true) || !preg_match('/^[a-z0-9\-]{0,40}$/', $el)) continue;
    $rows[] = [$type, $el, max(-1, min(86400, (int)$e[2])), max(0, min(86400, (int)$e[3]))];
}

$cfg = require __DIR__ . '/config.php';
define('LP_DB', 1);
require __DIR__ . '/db.php';
$pdo = lp_db($cfg);
if ($pdo && $rows) {
    try {
        $now = gmdate('Y-m-d H:i:s');
        $st = $pdo->prepare('INSERT INTO track_event (created_at, pv, type, el, val, t, device, src, cmp, lv) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $pdo->beginTransaction();
        foreach ($rows as $r) {
            $st->execute([$now, $pv, $r[0], $r[1], $r[2], $r[3], $dev, $src, $cmp, $lv]);
        }
        $pdo->commit();
        if (mt_rand(1, 500) === 1) {
            $pdo->exec('DELETE FROM track_event WHERE created_at < UTC_TIMESTAMP() - INTERVAL ' . KEEP_DAYS . ' DAY');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('track.php: insert failed: ' . $e->getMessage());
    }
}
http_response_code(204);
