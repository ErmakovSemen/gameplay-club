<?php
/**
 * ⚡ GAME PLAY — локальные тесты логики бота.
 * Сеть не используется: api() в режиме GP_TEST пишет вызовы в $GLOBALS['gp_calls'].
 *
 * Запуск:  php tests/test_bot.php
 */

// ── тестовое окружение: свои секреты и своя временная база ──
define('GP_TEST', true);
define('BOT_TOKEN', 'test:token');
define('ADMIN_IDS', [1001]);
define('WH_SECRET', 'test_secret');
define('CRON_KEY', 'test_cron');
define('GP_DB', sys_get_temp_dir() . '/gameplay_test_' . getmypid() . '.db');

foreach ([GP_DB, GP_DB . '-wal', GP_DB . '-shm'] as $f) @unlink($f);
register_shutdown_function(function () {
    foreach ([GP_DB, GP_DB . '-wal', GP_DB . '-shm'] as $f) @unlink($f);
});

require_once __DIR__ . '/../webhook.php';

// ── мини-фреймворк ──
$GLOBALS['t_ok'] = 0;
$GLOBALS['t_fail'] = [];

function ok(string $what, bool $cond, string $detail = ''): void {
    if ($cond) {
        $GLOBALS['t_ok']++;
        echo "  \033[32m✓\033[0m $what\n";
    } else {
        $GLOBALS['t_fail'][] = $what . ($detail ? " — $detail" : '');
        echo "  \033[31m✗\033[0m $what" . ($detail ? " — $detail" : '') . "\n";
    }
}

function is_eq(string $what, $got, $exp): void {
    ok($what, $got === $exp, 'ожидалось ' . var_export($exp, true) . ', получено ' . var_export($got, true));
}

function group(string $name): void { echo "\n\033[1m$name\033[0m\n"; }

/** очистить журнал вызовов Telegram API */
function calls_reset(): void { $GLOBALS['gp_calls'] = []; }

/** все вызовы метода $method */
function calls_of(string $method): array {
    return array_values(array_filter($GLOBALS['gp_calls'] ?? [], fn($c) => $c[0] === $method));
}

/** склеенный текст всех отправленных/отредактированных сообщений */
function sent_text(): string {
    $out = '';
    foreach ($GLOBALS['gp_calls'] ?? [] as $c) {
        if (in_array($c[0], ['sendMessage', 'editMessageText'], true)) {
            $out .= ($c[1]['text'] ?? '') . "\n";
        }
        if ($c[0] === 'answerCallbackQuery') $out .= ($c[1]['text'] ?? '') . "\n";
    }
    return $out;
}

/** отправить боту текстовое сообщение как пользователь $uid */
function as_message(int $uid, string $text): void {
    calls_reset();
    handle_update(['message' => [
        'chat' => ['id' => $uid],
        'from' => ['id' => $uid, 'first_name' => 'Тест', 'last_name' => 'Юзер'],
        'text' => $text,
    ]]);
}

/** нажать инлайн-кнопку как пользователь $uid */
function as_click(int $uid, string $data): void {
    calls_reset();
    handle_update(['callback_query' => [
        'id' => 'cb1',
        'from' => ['id' => $uid, 'first_name' => 'Тест', 'last_name' => 'Юзер'],
        'message' => ['message_id' => 555, 'chat' => ['id' => $uid]],
        'data' => $data,
    ]]);
}

/** отправить контакт (кнопка «отправить номер») */
function as_contact(int $uid, string $phone): void {
    calls_reset();
    handle_update(['message' => [
        'chat' => ['id' => $uid],
        'from' => ['id' => $uid, 'first_name' => 'Тест'],
        'contact' => ['phone_number' => $phone],
    ]]);
}

// ══════════════════════════════════════════════════════════
group('1. Конфигурация');

ok('WH_SECRET проходит требования Telegram (A-Za-z0-9_-)',
    (bool)preg_match('/^[A-Za-z0-9_-]{1,256}$/', WH_SECRET),
    'Telegram отклонит setWebhook с другими символами');
ok('часовой пояс — Москва', date_default_timezone_get() === 'Europe/Moscow');
ok('все зоны имеют цены', array_keys(ZONES) === array_keys(PRICES));
foreach (ZONES as $z => $info) {
    ok("зона $z: 3 цены дня / 3 вечера / пакет ночи",
        count(PRICES[$z]['day']['wd']) === 3 && count(PRICES[$z]['eve']['we']) === 3
        && is_int(PRICES[$z]['night']['wd']));
}

// ══════════════════════════════════════════════════════════
group('2. Расчёт цены');

$mon = new DateTime('2026-10-05 10:00');   // понедельник = будни
$sat = new DateTime('2026-10-10 10:00');   // суббота    = выходные
$fri = new DateTime('2026-10-09 10:00');   // пятница    = выходные по коду

is_eq('normal/киберчас/будни/день', calc_price('normal', 'kiber', $mon, 10), 120);
is_eq('normal/киберчас/выходные/день', calc_price('normal', 'kiber', $sat, 10), 140);
is_eq('пятница считается выходным', calc_price('normal', 'kiber', $fri, 10), 140);
is_eq('normal/киберчас/будни/вечер (18:00)', calc_price('normal', 'kiber', $mon, 18), 140);
is_eq('граница дня: 15:00 — день', calc_price('normal', 'kiber', $mon, 15), 120);
is_eq('граница вечера: 16:00 — вечер', calc_price('normal', 'kiber', $mon, 16), 140);
is_eq('ночь дешевле 12 киберчасов', calc_price('normal', 'night', $mon, 20) < 12 * 140, true);
is_eq('ps_vip/ультра/выходные/вечер', calc_price('ps_vip', 'ultra', $sat, 18), 1450);
ok('утро (7:00) считается «вечерним» тарифом — так задумано в коде',
    calc_price('normal', 'kiber', $mon, 7) === 140);

// ══════════════════════════════════════════════════════════
group('3. Вместимость и пересечения броней');

$base = new DateTime('2026-12-01 12:00');  // далеко в будущем, не мешает «сейчас»

is_eq('пустая зона normal свободна полностью', free_seats('normal', $base, 1), 10);
is_eq('ps — одно место', free_seats('ps', $base, 1), 1);

add_booking(2001, 'ps', $base->format('c'), 3, 'tripl', 620);
is_eq('после брони ps занят', free_seats('ps', $base, 1), 0);
is_eq('ps занят и на 2-й час брони', free_seats('ps', (clone $base)->modify('+2 hour'), 1), 0);
is_eq('ps свободен после окончания', free_seats('ps', (clone $base)->modify('+3 hour'), 1), 1);
is_eq('ps свободен до начала', free_seats('ps', (clone $base)->modify('-1 hour'), 1), 1);
is_eq('бронь, накрывающая занятый час, видит 0 мест',
    free_seats('ps', (clone $base)->modify('-1 hour'), 5), 0);
is_eq('соседняя зона не затронута', free_seats('ps_vip', $base, 1), 1);

for ($i = 0; $i < 10; $i++) add_booking(3000 + $i, 'normal', $base->format('c'), 1, 'kiber', 120);
is_eq('normal забит 10 бронями', free_seats('normal', $base, 1), 0);
is_eq('busy_count берёт пик по часам', busy_count('normal', (clone $base)->modify('-1 hour'), 3), 10);

// ══════════════════════════════════════════════════════════
group('4. Полный сценарий брони пользователя');

$uid = 4001;
as_message($uid, '/start');
ok('/start показывает меню', str_contains(sent_text(), 'GAME PLAY'));
ok('в меню есть кнопка брони', str_contains(json_encode($GLOBALS['gp_calls'], JSON_UNESCAPED_UNICODE), 'book'));

as_click($uid, 'book');
ok('шаг 1 — выбор зоны', str_contains(sent_text(), 'Шаг 1 из 4'));

as_click($uid, 'zone:normal');
ok('шаг 2 — выбор тарифа', str_contains(sent_text(), 'Шаг 2 из 4'));

as_click($uid, 'tarif:kiber');
ok('шаг 3 — выбор даты', str_contains(sent_text(), 'Шаг 3 из 4'));

$day = (new DateTime('tomorrow'))->format('Y-m-d');
as_click($uid, 'date:' . $day);
ok('шаг 4 — выбор часа', str_contains(sent_text(), 'Шаг 4 из 4'));

as_click($uid, 'hour:14');
ok('спрашивает телефон', str_contains(sent_text(), 'телефон'));

as_message($uid, '123');
ok('короткий номер отклонён', str_contains(sent_text(), 'не номер'));

as_contact($uid, '+79060354632');
ok('после номера спрашивает имя', str_contains(sent_text(), 'Как тебя записать'));

as_message($uid, 'Я');
ok('слишком короткое имя отклонено', str_contains(sent_text(), 'от 2 до 40'));

as_message($uid, 'Семён Ермаков');
$txt = sent_text();
ok('имя принято', str_contains($txt, 'Семён Ермаков'));
ok('показан экран подтверждения', str_contains($txt, 'Проверь бронь'));
ok('в подтверждении есть цена', str_contains($txt, '₽'));

$before = count(active_bookings('normal'));
as_click($uid, 'confirm');
$txt = sent_text();
ok('бронь подтверждена', str_contains($txt, 'подтверждена'));
is_eq('бронь появилась в базе', count(active_bookings('normal')), $before + 1);
ok('админу ушло уведомление о новой брони',
    str_contains($txt, 'Новая бронь') || count(calls_of('sendMessage')) >= 2);

$u = get_user($uid);
is_eq('телефон сохранён', $u['phone'], '+79060354632');
is_eq('имя сохранено', $u['real_name'], 'Семён Ермаков');
is_eq('черновик очищен', get_draft($uid), []);
is_eq('состояние сброшено', $u['state'], null);

// ══════════════════════════════════════════════════════════
group('5. Мои брони и отмена');

as_click($uid, 'my');
ok('бронь видна в «Мои брони»', str_contains(sent_text(), 'Мои брони'));

$st = db()->prepare("SELECT id FROM bookings WHERE user_id=? AND status='active'");
$st->execute([$uid]);
$bid = (int)$st->fetchColumn();
ok('id брони найден', $bid > 0);

// чужой пользователь не может отменить бронь
as_click(4002, 'cancel:' . $bid);
$row = db()->query("SELECT status FROM bookings WHERE id=$bid")->fetchColumn();
is_eq('чужую бронь отменить нельзя', $row, 'active');

as_click($uid, 'cancel:' . $bid);
$row = db()->query("SELECT status FROM bookings WHERE id=$bid")->fetchColumn();
is_eq('свою бронь отменить можно', $row, 'cancelled');

as_click($uid, 'cancel:' . $bid);
ok('повторная отмена не проходит', str_contains(sent_text(), 'не найдена'));

// ══════════════════════════════════════════════════════════
group('6. Защита от двойной брони');

$uid2 = 5001;
save_user($uid2, 'Второй');
set_user($uid2, 'phone', '+70000000000');
set_user($uid2, 'real_name', 'Второй Игрок');

$slot = new DateTime('2026-12-02 13:00');
add_booking(9999, 'ps', $slot->format('c'), 1, 'kiber', 270);   // место уже занято
set_draft($uid2, ['zone' => 'ps', 'tariff' => 'kiber',
                  'date' => $slot->format('Y-m-d'), 'hour' => 13]);
as_click($uid2, 'confirm');
ok('занятый слот не подтверждается', str_contains(sent_text(), 'только что заняли'));
is_eq('дубля в базе нет', count(active_bookings('ps')), 2);  // $base + $slot

// устаревшая сессия
set_draft($uid2, []);
as_click($uid2, 'confirm');
ok('пустой черновик даёт понятную ошибку', str_contains(sent_text(), 'устарела'));

// ══════════════════════════════════════════════════════════
group('7. Экраны: цены, загруженность, о клубе');

as_click($uid, 'prices');
$txt = sent_text();
ok('экран цен открывается', str_contains($txt, 'Цены GAME PLAY'));
foreach (['NORMAL', 'BOOTCAMP', 'PlayStation 5', 'PS5 Pro'] as $z) {
    ok("в ценах есть зона $z", str_contains($txt, $z));
}

as_click($uid, 'load');
$txt = sent_text();
ok('экран загруженности открывается', str_contains($txt, 'Загруженность'));
ok('есть полоса занятости', str_contains($txt, '▱') || str_contains($txt, '▰'));

as_click($uid, 'info');
ok('экран «о клубе» открывается', str_contains(sent_text(), CLUB_ADDRESS));

as_click($uid, 'full');
ok('кнопка «занято» отвечает подсказкой', str_contains(sent_text(), 'занято'));

// ══════════════════════════════════════════════════════════
group('8. Админка');

$adm = 1001;
ok('is_admin работает', is_admin($adm) && !is_admin(4001));

as_message($adm, '/admin');
ok('/admin открывает панель', str_contains(sent_text(), 'Админ-панель'));

as_message(4001, '/admin');
is_eq('не-админ не получает панель', count(calls_of('sendMessage')), 0);

as_click($adm, 'adm:day:0');
ok('брони за сегодня открываются', str_contains(sent_text(), 'Брони ·'));

as_click($adm, 'adm:grid:0');
ok('сетка занятости открывается', str_contains(sent_text(), 'Сетка занятости'));

as_click($adm, 'adm:all');
ok('список активных броней открывается', str_contains(sent_text(), 'Все активные брони'));

as_click($adm, 'adm:stats');
ok('статистика открывается', str_contains(sent_text(), 'Статистика'));

as_click($adm, 'adm:export');
ok('CSV-отчёт формируется и отправляется', count(calls_of('sendDocument')) === 1);

as_click(4001, 'adm:all');
is_eq('не-админ не видит админ-экраны', count(calls_of('editMessageText')), 0);

// админ снимает бронь
$slot2 = new DateTime('2026-12-03 15:00');
$bid2 = add_booking(4001, 'normal', $slot2->format('c'), 1, 'kiber', 120);
as_click($adm, 'acancel:' . $bid2);
is_eq('админ снял бронь',
    db()->query("SELECT status FROM bookings WHERE id=$bid2")->fetchColumn(), 'cancelled');
ok('клиенту пришло уведомление об отмене',
    str_contains(sent_text(), 'отменена администратором'));

as_click(4001, 'acancel:' . $bid);
ok('не-админ не может снять чужую бронь', !str_contains(sent_text(), 'снята'));

// ══════════════════════════════════════════════════════════
group('9. Ночной тариф');

$uid3 = 6001;
save_user($uid3, 'Ночной');
set_user($uid3, 'phone', '+71111111111');
set_user($uid3, 'real_name', 'Ночной Игрок');
$night_date = (new DateTime('tomorrow'))->format('Y-m-d');
set_draft($uid3, ['zone' => 'bootcamp', 'tariff' => 'night', 'date' => $night_date]);
as_click($uid3, 'date:' . $night_date);
$txt = sent_text();
ok('ночной тариф сразу ведёт к подтверждению', str_contains($txt, 'Проверь бронь'));
ok('ночь стартует в 20:00', str_contains($txt, '20:00'));
as_click($uid3, 'confirm');
$st = db()->prepare("SELECT hours FROM bookings WHERE user_id=? ORDER BY id DESC LIMIT 1");
$st->execute([$uid3]);
is_eq('ночная бронь на 12 часов', (int)$st->fetchColumn(), 12);

// ══════════════════════════════════════════════════════════
group('10. Форматирование и утилиты');

is_eq('fmt_dt', fmt_dt(new DateTime('2026-10-05 14:00')), '05.10 (пн) 14:00');
is_eq('fmt_range', fmt_range(['start' => '2026-10-05T14:00:00+03:00', 'hours' => 3]), '14:00–17:00');
is_eq('bar пустой', bar(0, 10), '▱▱▱▱▱▱▱▱▱▱');
is_eq('bar полный', bar(10, 10), '▰▰▰▰▰▰▰▰▰▰');
is_eq('bar половина', bar(5, 10), '▰▰▰▰▰▱▱▱▱▱');
is_eq('esc экранирует HTML', esc('<b>&"x"</b>'), '&lt;b&gt;&amp;&quot;x&quot;&lt;/b&gt;');
is_eq('u_len считает кириллицу', u_len('Семён'), 5);
is_eq('display_name предпочитает real_name',
    display_name(['real_name' => 'Иван', 'name' => 'ivan_tg']), 'Иван');
is_eq('display_name откатывается на tg-имя',
    display_name(['real_name' => null, 'name' => 'ivan_tg']), 'ivan_tg');

// ══════════════════════════════════════════════════════════
group('11. XSS / инъекции в имени');

$uidx = 7001;
save_user($uidx, 'Хакер');
set_user($uidx, 'state', 'name');
as_message($uidx, '<script>alert(1)</script>');
ok('слишком длинное/опасное имя обрабатывается без падения', true);
set_user($uidx, 'state', 'name');
as_message($uidx, '<b>Вася</b>');
set_user($uidx, 'phone', '+7999');
set_draft($uidx, ['zone' => 'normal', 'tariff' => 'kiber',
                  'date' => (new DateTime('tomorrow'))->format('Y-m-d'), 'hour' => 11]);
show_confirm($uidx, null);
ok('HTML в имени экранирован в сообщении',
    str_contains(sent_text(), '&lt;b&gt;') && !str_contains(sent_text(), '<b>Вася</b>'));

// ══════════════════════════════════════════════════════════
group('12. Напоминания (логика reminder.php)');

// бронь через 55 минут, созданная давно → напоминание нужно
$soon = (new DateTime())->modify('+55 minutes')->setTime((int)(new DateTime())->modify('+55 minutes')->format('H'), 0);
$rid = add_booking($uid, 'normal', $soon->format('c'), 1, 'kiber', 120);
db()->prepare('UPDATE bookings SET created=? WHERE id=?')
    ->execute([(new DateTime())->modify('-5 hours')->format('c'), $rid]);

calls_reset();
$now = new DateTime();
$limit = (clone $now)->modify('+60 minutes');
$sent = 0;
foreach (active_bookings() as $r) {
    if ((int)$r['reminded']) continue;
    $s = new DateTime($r['start']);
    if ($s < $now || $s > $limit) continue;
    $created = new DateTime($r['created']);
    if (($s->getTimestamp() - $created->getTimestamp()) <= 70 * 60) {
        db()->prepare('UPDATE bookings SET reminded=1 WHERE id=?')->execute([$r['id']]);
        continue;
    }
    send((int)$r['user_id'], '🔔 Напоминание');
    db()->prepare('UPDATE bookings SET reminded=1 WHERE id=?')->execute([$r['id']]);
    $sent++;
}
ok('напоминание отправлено для брони в пределах часа', $sent >= 1);
is_eq('бронь помечена как «напомнили»',
    (int)db()->query("SELECT reminded FROM bookings WHERE id=$rid")->fetchColumn(), 1);

// повторный проход не дублирует
$sent2 = 0;
foreach (active_bookings() as $r) {
    if ((int)$r['reminded']) continue;
    $s = new DateTime($r['start']);
    if ($s < $now || $s > $limit) continue;
    $sent2++;
}
is_eq('повторно напоминание не уходит', $sent2, 0);

// ══════════════════════════════════════════════════════════
group('13. Валидация подделанных callback_data');

$uidf = 7501;
save_user($uidf, 'Подделка');

as_click($uidf, 'zone:НЕТ_ТАКОЙ_ЗОНЫ');
ok('несуществующая зона отклонена', str_contains(sent_text(), 'Неизвестная зона'));

set_draft($uidf, ['zone' => 'normal']);
as_click($uidf, 'tarif:халява');
ok('несуществующий тариф отклонён', str_contains(sent_text(), 'Неизвестный тариф'));

set_draft($uidf, ['zone' => 'normal', 'tariff' => 'kiber']);
as_click($uidf, 'date:2020-01-01');
ok('дата в прошлом отклонена', str_contains(sent_text(), 'выбрать нельзя'));
as_click($uidf, 'date:2099-12-31');
ok('дата за окном бронирования отклонена', str_contains(sent_text(), 'выбрать нельзя'));
as_click($uidf, 'date:не-дата');
ok('нечисловая дата отклонена', str_contains(sent_text(), 'выбрать нельзя'));
as_click($uidf, 'date:2026-02-30');
ok('несуществующий день месяца отклонён', str_contains(sent_text(), 'выбрать нельзя'));

set_draft($uidf, ['zone' => 'normal', 'tariff' => 'kiber',
                  'date' => (new DateTime('tomorrow'))->format('Y-m-d')]);
as_click($uidf, 'hour:99');
ok('час вне 0–23 отклонён', str_contains(sent_text(), 'Такого времени нет'));
as_click($uidf, 'hour:abc');
ok('нечисловой час отклонён', str_contains(sent_text(), 'Такого времени нет'));

// подтверждение по битому черновику
set_draft($uidf, ['zone' => 'фейк', 'tariff' => 'kiber', 'date' => '2026-12-05', 'hour' => 12]);
as_click($uidf, 'confirm');
ok('битый черновик не создаёт бронь', str_contains(sent_text(), 'устарела'));

// подтверждение времени, которое уже прошло
$past = (new DateTime())->modify('-3 hours');
save_user(7502, 'Опоздал');
set_user(7502, 'phone', '+70000000001');
set_user(7502, 'real_name', 'Опоздавший');
set_draft(7502, ['zone' => 'normal', 'tariff' => 'kiber',
                 'date' => $past->format('Y-m-d'), 'hour' => (int)$past->format('H')]);
$cnt_before = count(active_bookings('normal'));
as_click(7502, 'confirm');
ok('время в прошлом не бронируется', str_contains(sent_text(), 'уже прошло'));
is_eq('бронь в прошлом в базу не попала', count(active_bookings('normal')), $cnt_before);

// ══════════════════════════════════════════════════════════
group('14. Цены на сайте совпадают с ценами бота');

$html = @file_get_contents(__DIR__ . '/../index.html');
if ($html === false) {
    ok('index.html читается', false, 'файл не найден');
} else {
    $text = preg_replace('/\s+/u', ' ', strip_tags(str_replace('<', ' <', $html)));
    // «День»/«Вечер» в исходнике с заглавной, на странице их поднимает CSS text-transform
    $re = '/День Кибер час (\d+) ₽ (\d+) ₽ Трипл (\d+) ₽ (\d+) ₽ Ультра (\d+) ₽ (\d+) ₽ '
        . 'Вечер Кибер час (\d+) ₽ (\d+) ₽ Трипл (\d+) ₽ (\d+) ₽ Ультра (\d+) ₽ (\d+) ₽ '
        . 'Ночные часы (\d+) ₽ (\d+) ₽/iu';
    $n = preg_match_all($re, $text, $m, PREG_SET_ORDER);
    is_eq('на сайте найдено 4 тарифных блока', $n, 4);

    // порядок блоков на странице: BOOTCAMP, NORMAL, PS5 Pro VIP, PS5 GamePlay
    $order = ['bootcamp', 'normal', 'ps_vip', 'ps'];
    foreach ($m as $i => $g) {
        $zone = $order[$i] ?? null;
        if (!$zone) continue;
        $p = PRICES[$zone];
        $site = [
            'day_wd'   => [(int)$g[1], (int)$g[3], (int)$g[5]],
            'day_we'   => [(int)$g[2], (int)$g[4], (int)$g[6]],
            'eve_wd'   => [(int)$g[7], (int)$g[9], (int)$g[11]],
            'eve_we'   => [(int)$g[8], (int)$g[10], (int)$g[12]],
            'night_wd' => (int)$g[13],
            'night_we' => (int)$g[14],
        ];
        is_eq("$zone · день · будни",     $site['day_wd'],   $p['day']['wd']);
        is_eq("$zone · день · выходные",  $site['day_we'],   $p['day']['we']);
        is_eq("$zone · вечер · будни",    $site['eve_wd'],   $p['eve']['wd']);
        is_eq("$zone · вечер · выходные", $site['eve_we'],   $p['eve']['we']);
        is_eq("$zone · ночь · будни",     $site['night_wd'], $p['night']['wd']);
        is_eq("$zone · ночь · выходные",  $site['night_we'], $p['night']['we']);
    }

    // контакты на сайте и в боте тоже должны совпадать
    ok('телефон на сайте совпадает с ботом', str_contains($text, CLUB_PHONE));
    ok('адрес на сайте совпадает с ботом',
        str_contains($text, 'Электросталь') && str_contains($text, 'Ленина, 40/8'));
}

// ══════════════════════════════════════════════════════════
group('15. Устойчивость к мусорным апдейтам');

$junk = [
    ['message' => ['chat' => ['id' => 8001], 'from' => ['id' => 8001], 'text' => '/unknown_command']],
    ['message' => ['chat' => ['id' => 8001], 'from' => ['id' => 8001], 'text' => '']],
    ['message' => ['chat' => ['id' => 8001], 'from' => ['id' => 8001, 'first_name' => 'X'], 'text' => 'просто текст']],
    ['callback_query' => ['id' => 'x', 'from' => ['id' => 8001],
      'message' => ['message_id' => 1, 'chat' => ['id' => 8001]], 'data' => 'ерунда']],
    ['callback_query' => ['id' => 'x', 'from' => ['id' => 8001],
      'message' => ['message_id' => 1, 'chat' => ['id' => 8001]], 'data' => 'zone:НЕТ_ТАКОЙ']],
    ['edited_message' => ['chat' => ['id' => 8001], 'text' => 'правка']],
];
$crashed = null;
foreach ($junk as $i => $up) {
    try { calls_reset(); handle_update($up); }
    catch (Throwable $e) { $crashed = "апдейт #$i: " . $e->getMessage(); break; }
}
ok('мусорные апдейты не роняют бота', $crashed === null, (string)$crashed);

// ══════════════════════════════════════════════════════════
// ИТОГ
echo "\n" . str_repeat('─', 50) . "\n";
if ($GLOBALS['t_fail']) {
    echo "\033[31mПРОВАЛЕНО: " . count($GLOBALS['t_fail']) . "\033[0m, пройдено {$GLOBALS['t_ok']}\n";
    foreach ($GLOBALS['t_fail'] as $f) echo "  · $f\n";
    exit(1);
}
echo "\033[32mВсе тесты пройдены: {$GLOBALS['t_ok']}\033[0m\n";
exit(0);
