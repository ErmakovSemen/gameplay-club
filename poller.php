<?php
/**
 * ⚡ GAME PLAY — poller.php
 *
 * Бот сам забирает обновления у Telegram (long polling) вместо вебхука.
 *
 * Зачем так: до этого сервера Telegram не достаёт — при вебхуке
 * getWebhookInfo стабильно показывает «Connection timed out», и в логах
 * nginx нет ни одного обращения от Telegram. Исходящая связь при этом
 * работает. Поэтому схему развернули: не Telegram стучится к нам,
 * а мы опрашиваем Telegram.
 *
 * Обработчики те же самые — берутся из webhook.php.
 *
 * Запускается как служба systemd (gameplay-bot.service), см. README.
 * Вручную:  php poller.php
 */

define('GP_POLL', true);
require_once __DIR__ . '/webhook.php';

const OFFSET_FILE = __DIR__ . '/.poller_offset';
const POLL_TIMEOUT = 20;   // должен быть меньше CURLOPT_TIMEOUT в core.php (25)

$running = true;
foreach ([SIGTERM, SIGINT] as $sig) {
    if (function_exists('pcntl_signal')) pcntl_signal($sig, function () use (&$running) { $running = false; });
}

function log_line(string $s): void {
    echo '[' . date('d.m.Y H:i:s') . "] $s\n";
    @ob_flush(); @flush();
}

// Вебхук и опрос вместе не работают — снимаем вебхук, если он стоит.
$info = api('getWebhookInfo')['result'] ?? [];
if (!empty($info['url'])) {
    api('deleteWebhook', ['drop_pending_updates' => false]);
    log_line('вебхук снят: ' . $info['url']);
}

$me = api('getMe');
if (empty($me['ok'])) {
    log_line('ОШИБКА: Telegram недоступен, getMe не прошёл');
    exit(1);
}
log_line('запущен, бот @' . $me['result']['username']);

$offset = is_file(OFFSET_FILE) ? (int)file_get_contents(OFFSET_FILE) : 0;
$fails = 0;

while ($running) {
    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();

    $r = api('getUpdates', [
        'offset'  => $offset,
        'timeout' => POLL_TIMEOUT,
        'allowed_updates' => ['message', 'callback_query'],
    ]);

    if (!$r || empty($r['ok'])) {
        // Сеть до Telegram нестабильна — отступаем, но не сдаёмся.
        $fails++;
        $wait = min(60, 2 ** min($fails, 5));
        log_line("нет ответа от Telegram (подряд: $fails), пауза {$wait}с");
        sleep($wait);
        continue;
    }
    $fails = 0;

    foreach ($r['result'] as $upd) {
        $offset = ((int)$upd['update_id']) + 1;
        try {
            handle_update($upd);
        } catch (Throwable $e) {
            log_line('ошибка обработки update ' . $upd['update_id'] . ': ' . $e->getMessage());
        }
        file_put_contents(OFFSET_FILE, (string)$offset);
    }
}

log_line('остановлен');
