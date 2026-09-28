<?php
/**
 * ⚡ GAME PLAY — set_webhook.php
 * Открой этот файл в браузере ОДИН РАЗ после загрузки на хостинг:
 *   https://gameplaycc.ru/set_webhook.php?key=CRON_KEY
 * Он привяжет бота к webhook.php. После успеха файл можно удалить.
 */

require_once __DIR__ . '/core.php';

if (($_GET['key'] ?? '') !== CRON_KEY) { http_response_code(403); exit('Нет доступа'); }

// адрес вебхука определяем автоматически по текущему домену
$url = 'https://' . $_SERVER['HTTP_HOST'] . str_replace('set_webhook.php', 'webhook.php', $_SERVER['SCRIPT_NAME']);

$res = api('setWebhook', [
    'url' => $url,
    'secret_token' => WH_SECRET,
    'drop_pending_updates' => true,
    'allowed_updates' => ['message', 'callback_query'],
]);
header('Content-Type: text/plain; charset=utf-8');
echo "Вебхук: $url\n\n";
echo "Ответ Telegram:\n" . json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
$info = api('getWebhookInfo');
echo "Проверка getWebhookInfo:\n" . json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
