<?php
// Образец секретов лендинга de1. Обычно не нужен: GitHub Actions собирает api/secrets.php
// из секретов репозитория (см. .github/workflows/deploy.yml). Если удобнее вручную —
// заполните, сохраните как artroflex-de1-config.php и загрузите уровнем выше папки сайта
// (cPanel → File Manager). Он имеет приоритет над api/secrets.php.
return array(
    'db' => array(
        'host' => 'localhost',
        'name' => 'artrofle_de1',
        'user' => 'artrofle_de1',
        'pass' => 'ПАРОЛЬ_ПОЛЬЗОВАТЕЛЯ_БАЗЫ',
    ),
    // LeadCenter — основной получатель заявок. key — API-ключ источника из LeadCenter → Источники.
    'leadcenter' => array(
        'url' => 'https://leadcenter.artro-flex.com/api/lead',
        'key' => '',
    ),
    // Пароль дашборда https://de1.artro-flex.com/admin/stats.php
    'stats_password' => 'ПРИДУМАЙТЕ_ПАРОЛЬ',
    // Необязательно: токен Webvork для запасной прямой отправки, если LeadCenter не ответил
    'token' => '',
);
