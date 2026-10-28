<?php
/*
 * Подключение к MySQL для журнала заявок (lead_log) и аналитики (track_event) — как в it2.
 * Доступ к базе — блок 'db' в секретах (api/secrets.php собирается при деплое из секретов GitHub).
 * База необязательна: если она не настроена, заявки всё равно уходят в LeadCenter.
 */
declare(strict_types=1);

if (!defined('LP_DB')) {
    http_response_code(404);
    exit;
}

/** PDO или null (база не настроена/недоступна). Создаёт таблицы при первом подключении. */
function lp_db(array $cfg): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    $pdo = null;
    $c = $cfg['db'] ?? [];
    if (empty($c['name']) || !class_exists('PDO')) return null;
    try {
        $pdo = new PDO(
            'mysql:host=' . ($c['host'] ?? 'localhost') . ';dbname=' . $c['name'] . ';charset=utf8mb4',
            (string)($c['user'] ?? ''),
            (string)($c['pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
        $pdo->exec("CREATE TABLE IF NOT EXISTS lead_log (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at  DATETIME NOT NULL COMMENT 'UTC',
            status      VARCHAR(20) NOT NULL COMMENT 'ok | api_error | network | invalid_name | invalid_phone | invalid_consent | bot | config | duplicate',
            lead_id     VARCHAR(40) NULL COMMENT 'наш id заявки',
            guid        VARCHAR(64) NULL COMMENT 'guid лида в Webvork',
            api_msg     VARCHAR(255) NULL COMMENT 'сообщение Webvork при ошибке',
            name        VARCHAR(100) NULL,
            phone       VARCHAR(20) NULL,
            ip          VARCHAR(45) NULL,
            utm         VARCHAR(1200) NULL COMMENT 'JSON',
            user_agent  VARCHAR(255) NULL,
            referer     VARCHAR(255) NULL,
            attempts    TEXT NULL COMMENT 'JSON: попытки отправки, HTTP-коды, ответы',
            duration_ms INT UNSIGNED NULL,
            landing_version VARCHAR(20) NULL COMMENT 'вариант лендинга',
            KEY idx_landing_version (landing_version),
            KEY idx_created (created_at),
            KEY idx_status (status),
            KEY idx_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lead_meta (
            k VARCHAR(32) NOT NULL PRIMARY KEY,
            v VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS track_event (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL COMMENT 'UTC',
            pv         VARCHAR(16) NOT NULL COMMENT 'id просмотра страницы (визита)',
            type       VARCHAR(10) NOT NULL COMMENT 'load | view | click | scroll | form | leave | depth',
            el         VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'data-track элемента / событие формы',
            val        INT NOT NULL DEFAULT 0 COMMENT 'scroll: %, leave: секунды, depth: %',
            t          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'секунда от загрузки страницы',
            device     VARCHAR(8) NOT NULL DEFAULT '',
            src        VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'utm_source',
            cmp        VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'utm_campaign',
            lv         VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'вариант лендинга (landing_version); пусто — A до разделения',
            KEY idx_created (created_at),
            KEY idx_pv (pv),
            KEY idx_type_el (type, el),
            KEY idx_lv (lv)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // База может быть общей с вариантом A (созданной до разделения A/B): добавляем столбцы версии
        $addCol = function (string $table, string $col, string $ddl) use ($pdo): void {
            try {
                if (!$pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch()) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl, ADD KEY idx_$col ($col)");
                }
            } catch (Throwable $e) {
                error_log("lp_db: add column $table.$col: " . $e->getMessage());
            }
        };
        $addCol('track_event', 'lv', "lv VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'вариант лендинга'");
        $addCol('lead_log', 'landing_version', "landing_version VARCHAR(20) NULL COMMENT 'вариант лендинга'");
    } catch (Throwable $e) {
        $GLOBALS['LP_DB_ERROR'] = $e->getMessage();
        error_log('lp_db: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}
