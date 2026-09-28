<?php
/**
 * ⚡ GAME PLAY — reminder.php
 * Напоминания за час до брони. Запускается планировщиком (cron)
 * каждые 5 минут:
 *   вариант А (cron команда):  php /полный/путь/reminder.php
 *   вариант Б (cron по URL):   https://gameplaycc.ru/reminder.php?key=CRON_KEY
 */

require_once __DIR__ . '/core.php';

// защита при запуске по URL
if (php_sapi_name() !== 'cli') {
    if (($_GET['key'] ?? '') !== CRON_KEY) { http_response_code(403); exit; }
}

$now = new DateTime();
$limit = (clone $now)->modify('+60 minutes');
$sent = 0;

foreach (active_bookings() as $r) {
    if ((int)$r['reminded']) continue;
    $s = new DateTime($r['start']);
    if ($s < $now || $s > $limit) continue;

    $created = new DateTime($r['created']);
    // бронь сделали меньше чем за 70 минут до начала — напоминание не нужно
    if (($s->getTimestamp() - $created->getTimestamp()) <= 70 * 60) {
        db()->prepare('UPDATE bookings SET reminded=1 WHERE id=?')->execute([$r['id']]);
        continue;
    }
    $mins = max(1, (int)round(($s->getTimestamp() - $now->getTimestamp()) / 60));
    $when = $mins >= 55 ? 'Через час' : "Уже через $mins мин";
    send((int)$r['user_id'],
        "🔔 <b>Напоминание!</b>\n" . DIV . "\n"
        . "$when твоя бронь <b>#{$r['id']}</b>:\n"
        . '🕹 ' . ZONES[$r['zone']]['name'] . ' · ' . fmt_dt($s) . "\n"
        . '📍 ' . CLUB_ADDRESS . "\n" . DIV . "\n"
        . 'Очень ждём тебя! Хорошей игры 💜⚡');
    db()->prepare('UPDATE bookings SET reminded=1 WHERE id=?')->execute([$r['id']]);
    $sent++;
}
echo "OK, отправлено напоминаний: $sent\n";
