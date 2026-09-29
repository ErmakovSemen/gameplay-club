<?php
/**
 * ⚡ GAME PLAY — cert_check.php
 *
 * Следит за сроком HTTPS-сертификата и пишет админу в Telegram,
 * когда пора продлевать.
 *
 * Зачем: сертификат выпущен через ручную DNS-проверку (HTTP-проверка
 * Let's Encrypt на этом сервере не проходит — часть их проверяющих
 * узлов до него не достаёт). Такие сертификаты acme.sh сама не продлевает,
 * поэтому без напоминания сайт однажды молча остался бы без HTTPS.
 *
 * Запуск раз в сутки по cron:
 *   0 9 * * * php /var/www/gameplay/cert_check.php
 */

require_once __DIR__ . '/core.php';

const CERT_PATH  = '/etc/ssl/gameplay/fullchain.pem';
const WARN_DAYS  = 21;   // с этого момента начинаем напоминать
const STATE_FILE = __DIR__ . '/.cert_check_state';

if (!is_readable(CERT_PATH)) {
    fwrite(STDERR, "Не читается " . CERT_PATH . "\n");
    exit(1);
}

$data = openssl_x509_parse(file_get_contents(CERT_PATH));
if (!$data || empty($data['validTo_time_t'])) {
    fwrite(STDERR, "Не разобрался в сертификате\n");
    exit(1);
}

$left = (int)floor(($data['validTo_time_t'] - time()) / 86400);
$until = date('d.m.Y', $data['validTo_time_t']);

echo "Сертификат действует до $until, осталось дней: $left\n";

if ($left > WARN_DAYS) {
    @unlink(STATE_FILE);   // всё хорошо — сбрасываем отметку об отправке
    exit(0);
}

// Не спамим: одно напоминание в сутки.
$today = date('Y-m-d');
if (is_file(STATE_FILE) && trim(file_get_contents(STATE_FILE)) === $today) {
    echo "Сегодня уже напоминали\n";
    exit(0);
}

$urgent = $left <= 7;
$text = ($urgent ? "🚨 <b>СРОЧНО: сертификат сайта скоро истечёт</b>\n"
                 : "⏳ <b>Пора продлить сертификат сайта</b>\n")
    . DIV . "\n"
    . "Домен: <b>" . CLUB_SITE . "</b>\n"
    . "Действует до: <b>$until</b>\n"
    . "Осталось дней: <b>$left</b>\n" . DIV . "\n"
    . "Продление в ручном режиме, само не произойдёт.\n"
    . "Что делать — в README репозитория, раздел «Продление сертификата».\n\n"
    . "Коротко: на сервере запустить выпуск, добавить две TXT-записи\n"
    . "в DNS домена и завершить выпуск.";

foreach (ADMIN_IDS as $aid) {
    send((int)$aid, $text);
}
file_put_contents(STATE_FILE, $today);
echo "Напоминание отправлено админам: " . implode(', ', ADMIN_IDS) . "\n";
