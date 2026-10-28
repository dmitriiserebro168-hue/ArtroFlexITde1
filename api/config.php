<?php
/*
 * Настройки сервера лендинга de1.artro-flex.com. Файл хранится в git, секретов в нём нет.
 *
 * Секреты (пароль базы, ключ LeadCenter, пароль статистики, токен Webvork) берутся из:
 *   1) /home/artrofle/artroflex-de1-config.php — уровнем выше папки сайта, если загружен вручную;
 *   2) api/secrets.php — собирается GitHub Actions из секретов репозитория при каждом деплое.
 * Образец формата — config.example.php в корне репозитория.
 */
return (function (): array {
    $secret = [];
    foreach ([dirname(__DIR__, 2) . '/artroflex-de1-config.php', __DIR__ . '/secrets.php'] as $f) {
        if (!is_file($f)) continue;
        // Ошибка в файле секретов не должна ронять весь сайт: запоминаем её для ?health=config
        try {
            $c = include $f;
        } catch (Throwable $e) {
            $GLOBALS['LP_SECRETS_ERROR'] = basename($f) . ': ' . get_class($e) . ' — ' . $e->getMessage() . ' (строка ' . $e->getLine() . ')';
            error_log('config.php: ' . $GLOBALS['LP_SECRETS_ERROR']);
            continue;
        }
        if (is_array($c)) { $secret = $c; $GLOBALS['LP_SECRETS_FILE'] = basename($f); break; }
        $GLOBALS['LP_SECRETS_ERROR'] = basename($f) . ': файл должен заканчиваться «return array(…);»';
    }
    $s = function (string $key, string $default = '') use ($secret): string {
        $v = (string)($secret[$key] ?? '');
        return $v !== '' ? $v : $default;
    };
    // Пустые значения в блоке не считаются заданными (секрет GitHub может быть не создан)
    $block = function (string $key) use ($secret): array {
        return is_array($secret[$key] ?? null) ? array_filter($secret[$key], fn($v) => $v !== '' && $v !== null) : [];
    };

    return [
        // ---------- LeadCenter — основной получатель заявок, в Webvork отправляет он ----------
        // ['url' => 'https://leadcenter.artro-flex.com/api/lead', 'key' => '<API-ключ источника>']
        'leadcenter' => $block('leadcenter') + ['url' => 'https://leadcenter.artro-flex.com/api/lead'],

        // ---------- Webvork напрямую — только запасной путь, если LeadCenter не ответил ----------
        // Без токена запасного пути нет: заявка, не принятая LeadCenter, получает ошибку и пишется в журнал.
        'partner' => [
            'url'           => 'https://api.webvork.com/v1/new-lead',
            'fallback_url'  => 'https://api2.webvork.com/v1/new-lead',
            'method'        => 'POST',
            'format'        => 'form',
            'auth_header'   => '',                       // токен передаётся полем token
            'auth_value'    => '',
            'timeout'       => 8,
            'retries'       => 1,                        // 2 попытки × 2 адреса
            // наш ключ => поле Webvork (null — не передавать)
            'map' => [
                'name'         => 'name',
                'phone'        => 'phone',
                'ip'           => 'ip',
                'utm_source'   => 'utm_source',
                'utm_medium'   => 'utm_medium',
                'utm_campaign' => 'utm_campaign',
                'utm_content'  => 'utm_content',
                'utm_term'     => 'utm_term',
            ],
            // Постоянные поля: задаются на сервере, с клиента их подменить нельзя
            'static' => [
                'token'    => $s('token'),
                'offer_id' => $s('offer_id', '208'),
                'country'  => 'DE',
                'lang'     => 'de',
            ],
            // Ответ Webvork: {"status":"ok","guid":"…"}
            'success_check' => ['field' => 'status', 'value' => 'ok'],
        ],

        // ---------- Защита ----------
        'allowed_origins' => ['https://de1.artro-flex.com', 'https://www.de1.artro-flex.com'],
        'require_consent' => true,                       // должно совпадать с form.requireConsent в assets/js/config.js

        // Каталог для служебных файлов (закрыт от веба _data/.htaccess)
        'data_dir'        => __DIR__ . '/../_data',

        // MySQL для журнала заявок (lead_log) и аналитики посещений (track_event)
        'db'              => $block('db') + ['host' => 'localhost', 'name' => 'artrofle_de1', 'user' => 'artrofle_de1'],
        // Telegram о лидах шлёт LeadCenter; здесь — только для заявок, ушедших в Webvork напрямую
        'telegram'        => $block('telegram'),
        // Письма об ошибках отправки лидов (необязательно)
        'alert_email'     => $s('alert_email'),
        // Пароль дашборда /admin/stats.php (пусто — дашборд закрыт)
        'stats_password'  => $s('stats_password'),

        // Срок хранения служебных записей (дней). Имена и телефоны на диске не хранятся.
        'retention_days'  => 30,
    ];
})();
