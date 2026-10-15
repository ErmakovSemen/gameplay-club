<?php
/**
 * ════════════════════════════════════════════════════════
 *  ⚡ GAME PLAY — webhook.php
 *  Принимает обновления от Telegram и отвечает.
 *  Положи рядом с core.php в корень сайта (или в /bot/).
 * ════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/staff.php';

// GP_TEST — тесты, GP_POLL — режим опроса (poller.php).
// В обоих случаях этот файл подключают ради функций, а не ради обработки запроса.
if (!defined('GP_TEST') && !defined('GP_POLL')) {
    // Принимаем только POST от Telegram с нашим секретом
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(200); exit('GAME PLAY bot ⚡'); }
    $secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($secret !== WH_SECRET) { http_response_code(403); exit; }
    $update = json_decode(file_get_contents('php://input'), true);
    if (!$update) { http_response_code(200); exit; }
    // Сразу отвечаем Telegram, чтобы не было таймаутов, и продолжаем работу
    ignore_user_abort(true);
    http_response_code(200);
    header('Content-Length: 0');
    header('Connection: close');
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    handle_update($update);
    exit;
}

// ══════════ ТЕКСТЫ / ЭКРАНЫ ══════════

function main_menu_text(): string {
    return "⚡ <b>GAME PLAY</b> ⚡\n"
        . "<i>Игровой клуб · Электросталь · 24/7</i>\n" . DIV . "\n"
        . "🖥 Мощные ПК: RTX 3060 и RTX 5060\n"
        . "🎮 PlayStation 5 и PS5 Pro\n"
        . "💜 Уютная атмосфера, цены — от 120 ₽ в час\n"
        . "☎️ " . CLUB_PHONE . "\n"
        . '🌐 <a href="' . CLUB_SITE . '">Наш сайт</a>' . "\n"
        . '📣 <a href="' . CLUB_CHANNEL . '">Наш канал</a>' . "\n"
        . DIV . "\nВыбери, что нужно 👇";
}

function main_menu_kb(): array {
    return [
        [btn('🎮 Забронировать место', 'book')],
        [btn('📊 Загруженность', 'load'), btn('💰 Цены', 'prices')],
        [btn('📋 Мои брони', 'my'), btn('ℹ️ О клубе', 'info')],
    ];
}

function view_book(): array {
    $rows = [];
    foreach (ZONES as $z => $info) {
        $rows[] = [btn($info['name'] . '  ·  ' . $info['cap'] . ' мест', "zone:$z")];
    }
    $rows[] = [btn('⬅️ Назад', 'menu')];
    return ["🎮 <b>Бронирование</b>\n" . DIV . "\nШаг 1 из 4 · Выбери зону:", $rows];
}

function view_load(): array {
    $t = new DateTime(date('Y-m-d H:00'));
    $lines = ["📊 <b>Загруженность клуба</b>", '<i>' . fmt_dt(new DateTime()) . '</i>', DIV, '<b>Сейчас:</b>'];
    foreach (ZONES as $z => $info) {
        $busy = busy_count($z, $t, 1);
        $free = $info['cap'] - $busy;
        $lines[] = $info['name'] . "\n" . bar($busy, $info['cap']) . "  свободно <b>$free/{$info['cap']}</b>";
    }
    $lines[] = DIV;
    $lines[] = '<b>Прогноз по броням (ПК-зоны):</b>';
    $cap = ZONES['normal']['cap'] + ZONES['bootcamp']['cap'];
    for ($i = 1; $i <= 6; $i++) {
        $ft = (clone $t)->modify("+$i hour");
        $busy = busy_count('normal', $ft, 1) + busy_count('bootcamp', $ft, 1);
        $lines[] = $ft->format('H:00') . '  ' . bar($busy, $cap) . '  ' . ($cap - $busy) . ' св.';
    }
    $lines[] = DIV;
    $lines[] = '<i>Учитываются онлайн-брони с сайта и из бота. Живая очередь — по телефону 👇</i>';
    $lines[] = '☎️ ' . CLUB_PHONE;
    return [implode("\n", $lines),
            [[btn('🎮 Забронировать', 'book')], [btn('⬅️ Назад', 'menu')]]];
}

function price_block(string $zone): string {
    $p = PRICES[$zone];
    $f = fn($v) => str_pad((string)$v, 4, ' ', STR_PAD_LEFT);
    return '<b>' . ZONES[$zone]['name'] . "</b>\n"
        . "<code>            будни   вых.</code>\n"
        . '☀️ <b>День</b> · ' . DAY_START . ':00–' . EVENING_START . ":00\n"
        . "<code> Киберчас   {$f($p['day']['wd'][0])}   {$f($p['day']['we'][0])}</code>\n"
        . "<code> Трипл      {$f($p['day']['wd'][1])}   {$f($p['day']['we'][1])}</code>\n"
        . "<code> Ультра     {$f($p['day']['wd'][2])}   {$f($p['day']['we'][2])}</code>\n\n"
        . '🌆 <b>Вечер</b> · с ' . EVENING_START . ":00\n"
        . "<code> Киберчас   {$f($p['eve']['wd'][0])}   {$f($p['eve']['we'][0])}</code>\n"
        . "<code> Трипл      {$f($p['eve']['wd'][1])}   {$f($p['eve']['we'][1])}</code>\n"
        . "<code> Ультра     {$f($p['eve']['wd'][2])}   {$f($p['eve']['we'][2])}</code>\n\n"
        . '🌙 <b>Ночь</b> · ' . NIGHT_HOUR . ":00–8:00\n"
        . "<code> Пакет      {$f($p['night']['wd'])}   {$f($p['night']['we'])}</code>\n";
}

function view_prices(): array {
    $text = "💰 <b>Цены GAME PLAY</b> (₽)\n<i>будни = пн–чт · выходные = пт–вс</i>\n" . DIV . "\n"
        . price_block('normal') . "\n" . price_block('bootcamp') . "\n"
        . price_block('ps') . "\n" . price_block('ps_vip');
    return [$text, [[btn('🎮 Забронировать', 'book')], [btn('⬅️ Назад', 'menu')]]];
}

function view_my(int $uid): array {
    $st = db()->prepare("SELECT * FROM bookings WHERE user_id=? AND status='active' ORDER BY start");
    $st->execute([$uid]);
    $rows = array_filter($st->fetchAll(PDO::FETCH_ASSOC), function ($r) {
        $end = (new DateTime($r['start']))->modify('+' . $r['hours'] . ' hour');
        return $end > new DateTime();
    });
    if (!$rows) {
        return ["📋 <b>Мои брони</b>\n" . DIV . "\nПока пусто 🙃 Самое время исправить!",
                [[btn('🎮 Забронировать', 'book')], [btn('⬅️ Назад', 'menu')]]];
    }
    $lines = ['📋 <b>Мои брони</b>', DIV];
    $btns = [];
    foreach ($rows as $r) {
        $s = new DateTime($r['start']);
        $lines[] = "<b>#{$r['id']}</b> · " . ZONES[$r['zone']]['name'] . "\n"
            . '     ' . TARIFFS[$r['tariff']][0] . "\n"
            . '     📅 ' . fmt_dt($s) . " · 💰 {$r['price']} ₽\n";
        $btns[] = [btn("❌ Отменить #{$r['id']}", "cancel:{$r['id']}")];
    }
    $btns[] = [btn('⬅️ Назад', 'menu')];
    return [implode("\n", $lines), $btns];
}

function view_info(): array {
    $text = "⚡ <b>GAME PLAY</b>\n<i>Игровой клуб · Электросталь</i>\n" . DIV . "\n"
        . "🖥 <b>NORMAL</b> — 10 мест · RTX 3060 · 180 Гц\n"
        . "🔥 <b>BOOTCAMP VIP</b> — 5 мест · RTX 5060 · 300 Гц\n"
        . "🎮 <b>PS5</b> (1 ТБ) · ТВ 55″ 120 Гц\n"
        . "👑 <b>PS5 Pro</b> (2 ТБ) · ТВ 65″ 120 Гц · VIP зона\n" . DIV . "\n"
        . "🕐 Работаем <b>24/7</b>\n📍 " . CLUB_ADDRESS . "\n☎️ " . CLUB_PHONE . "\n"
        . "🌐 Сайт: gameplaycc.ru\n📣 Канал: @gameplaypcclub\n"
        . '📄 <a href="' . PD_PRIVACY_URL . '">Конфиденциальность</a> · <a href="' . CLUB_SITE . '/terms/">Условия</a>';
    return [$text, [[btn('🎮 Забронировать', 'book')], [btn('⬅️ Назад', 'menu')]]];
}

// ══════════ БРОНИРОВАНИЕ ══════════

/**
 * Черновик брони пригоден для подтверждения: все поля на месте
 * и значения существуют в справочниках (защита от подделанных
 * callback_data и от черновиков, оставшихся от прежней версии).
 */
function draft_valid(array $d): bool {
    return isset($d['zone'], $d['tariff'], $d['date'], $d['hour'])
        && isset(ZONES[$d['zone']])
        && isset(TARIFFS[$d['tariff']])
        && (bool)DateTime::createFromFormat('!Y-m-d', (string)$d['date'])
        && is_int($d['hour']) && $d['hour'] >= 0 && $d['hour'] <= 23;
}

function show_tariffs(int $chat, int $msgId, string $zone): void {
    $rows = [];
    foreach (TARIFFS as $t => $info) $rows[] = [btn($info[0], "tarif:$t")];
    $rows[] = [btn('⬅️ Назад', 'book')];
    edit($chat, $msgId, "🎮 <b>Бронирование</b> · " . ZONES[$zone]['name'] . "\n" . DIV
        . "\nШаг 2 из 4 · Выбери тариф:", $rows);
}

function show_dates(int $chat, int $msgId, string $zone, string $tariff): void {
    $rows = []; $row = [];
    for ($i = 0; $i < BOOK_DAYS_AHEAD; $i++) {
        $d = new DateTime("today +$i day");
        $label = $i === 0 ? 'Сегодня' : ($i === 1 ? 'Завтра' : $d->format('d.m'));
        $label .= ' (' . WDAYS[(int)$d->format('N') - 1] . ')';
        $row[] = btn($label, 'date:' . $d->format('Y-m-d'));
        if (count($row) === 2) { $rows[] = $row; $row = []; }
    }
    if ($row) $rows[] = $row;
    $rows[] = [btn('⬅️ Назад', "zone:$zone")];
    edit($chat, $msgId, "🎮 <b>Бронирование</b> · " . ZONES[$zone]['name'] . "\n"
        . TARIFFS[$tariff][0] . "\n" . DIV . "\nШаг 3 из 4 · Выбери дату:", $rows);
}

function show_hours(int $chat, int $msgId, string $cbId, array $d): void {
    $zone = $d['zone']; $tariff = $d['tariff'];
    $hours = TARIFFS[$tariff][1];
    $date = $d['date'];

    if ($tariff === 'night') {
        $start = new DateTime("$date " . NIGHT_HOUR . ':00');
        if (free_seats($zone, $start, $hours) <= 0 || $start < new DateTime()) {
            answer_cb($cbId, '😔 На эту ночь мест нет, выбери другую дату', true);
            return;
        }
        $d['hour'] = NIGHT_HOUR;
        set_draft($chat, $d);
        ask_details_or_confirm($chat, $msgId, $cbId);
        return;
    }

    $rows = []; $row = [];
    for ($h = 0; $h < 24; $h++) {
        $start = new DateTime(sprintf('%s %02d:00', $date, $h));
        if ($start < new DateTime()) continue;
        $free = free_seats($zone, $start, $hours);
        $row[] = $free > 0 ? btn(sprintf('%02d:00', $h), "hour:$h") : btn('✖', 'full');
        if (count($row) === 4) { $rows[] = $row; $row = []; }
    }
    if ($row) $rows[] = $row;
    if (!$rows) { answer_cb($cbId, '😔 На эту дату свободных окон нет', true); return; }
    $rows[] = [btn('⬅️ Назад', "tarif:$tariff")];
    $dd = new DateTime($date);
    edit($chat, $msgId, "🎮 <b>Бронирование</b> · " . ZONES[$zone]['name'] . "\n"
        . TARIFFS[$tariff][0] . ' · ' . $dd->format('d.m') . ' (' . WDAYS[(int)$dd->format('N') - 1] . ")\n"
        . DIV . "\nШаг 4 из 4 · Выбери время начала:\n<i>✖ — мест нет</i>", $rows);
}

function consent_text(): string {
    return "📄 <b>Согласие на обработку данных</b>\n" . DIV . "\n"
        . "Чтобы записать бронь, нам нужны твоё имя и номер телефона — по ним администратор найдёт бронь в клубе.\n\n"
        . 'Нажимая «Даю согласие», ты соглашаешься на их обработку на условиях '
        . '<a href="' . PD_CONSENT_URL . '">согласия</a> и <a href="' . PD_PRIVACY_URL . '">политики конфиденциальности</a>.';
}

function consent_kb(): array {
    return [[btn('✅ Даю согласие', 'pdc:yes')], [btn('❌ Не сейчас', 'menu')]];
}

function ask_details_or_confirm(int $chat, ?int $msgId, ?string $cbId): void {
    $u = get_user($chat);
    if (!has_consent($chat)) {
        set_user($chat, 'state', 'consent');
        if ($msgId) edit($chat, $msgId, consent_text(), consent_kb()); else send($chat, consent_text(), consent_kb());
        if ($cbId) answer_cb($cbId);
        return;
    }
    if (!$u || !$u['phone']) {
        set_user($chat, 'state', 'phone');
        $text = "📱 <b>Почти готово!</b>\n" . DIV . "\n"
            . "Оставь номер телефона — по нему мы найдём твою бронь на месте.\n\n"
            . "Нажми кнопку ниже 👇 или напиши номер вручную.";
        if ($msgId) edit($chat, $msgId, $text); else send($chat, $text);
        api('sendMessage', ['chat_id' => $chat, 'text' => '☎️', 'reply_markup' => [
            'keyboard' => [[['text' => '📱 Отправить мой номер', 'request_contact' => true]]],
            'resize_keyboard' => true, 'one_time_keyboard' => true]]);
    } elseif (!$u['real_name']) {
        set_user($chat, 'state', 'name');
        $text = "👤 <b>Как тебя записать?</b>\n" . DIV . "\n"
            . "Напиши имя (можно имя и фамилию) — так администратор быстро найдёт твою бронь.";
        if ($msgId) edit($chat, $msgId, $text); else send($chat, $text);
    } else {
        show_confirm($chat, $msgId);
    }
    if ($cbId) answer_cb($cbId);
}

function show_confirm(int $chat, ?int $msgId): void {
    $d = get_draft($chat);
    if (!draft_valid($d)) { send($chat, 'Сессия устарела, начни заново: /book'); return; }
    $hours = TARIFFS[$d['tariff']][1];
    $start = new DateTime(sprintf('%s %02d:00', $d['date'], $d['hour']));
    $end = (clone $start)->modify("+$hours hour");
    $price = calc_price($d['zone'], $d['tariff'], $start, (int)$d['hour']);
    $u = get_user($chat);
    $text = "🧾 <b>Проверь бронь</b>\n" . DIV . "\n"
        . '👤 Имя: <b>' . esc(display_name($u)) . "</b>\n"
        . '🕹 Зона: <b>' . ZONES[$d['zone']]['name'] . "</b>\n"
        . '🎟 Тариф: <b>' . TARIFFS[$d['tariff']][0] . "</b>\n"
        . '📅 Начало: <b>' . fmt_dt($start) . "</b>\n"
        . '🏁 Конец: <b>' . fmt_dt($end) . "</b>\n"
        . "💰 Цена: <b>$price ₽</b> <i>(оплата в клубе)</i>\n" . DIV . "\nВсё верно?";
    $kbb = [[btn('✅ Подтвердить', 'confirm')], [btn('❌ Отменить', 'menu')]];
    if ($msgId) edit($chat, $msgId, $text, $kbb); else send($chat, $text, $kbb);
}

function do_confirm(int $chat, int $msgId, string $cbId): void {
    $d = get_draft($chat);
    if (!draft_valid($d)) { answer_cb($cbId, 'Сессия устарела, начни заново', true); return; }
    if (!has_consent($chat)) { ask_details_or_confirm($chat, $msgId, $cbId); return; }
    $hours = TARIFFS[$d['tariff']][1];
    $start = new DateTime(sprintf('%s %02d:00', $d['date'], $d['hour']));
    if ($start < new DateTime()) {
        answer_cb($cbId, '⏰ Это время уже прошло. Выбери другое.', true);
        return;
    }
    $price = calc_price($d['zone'], $d['tariff'], $start, (int)$d['hour']);

    // Проверка мест и вставка — одной транзакцией: иначе два одновременных
    // «Подтвердить» могут занять последнее место дважды.
    db()->exec('BEGIN IMMEDIATE');
    try {
        if (free_seats($d['zone'], $start, $hours) <= 0) {
            db()->exec('ROLLBACK');
            answer_cb($cbId, '😔 Упс, место только что заняли. Выбери другое время.', true);
            return;
        }
        $bid = add_booking($chat, $d['zone'], $start->format('c'), $hours, $d['tariff'], $price);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        answer_cb($cbId, 'Не получилось сохранить бронь, попробуй ещё раз', true);
        return;
    }
    set_draft($chat, []);
    set_user($chat, 'state', null);
    edit($chat, $msgId,
        "🎉 <b>Бронь #$bid подтверждена!</b>\n" . DIV . "\n"
        . '🕹 ' . ZONES[$d['zone']]['name'] . "\n"
        . '🎟 ' . TARIFFS[$d['tariff']][0] . "\n"
        . '📅 ' . fmt_dt($start) . "\n"
        . "💰 $price ₽ · оплата в клубе\n" . DIV . "\n"
        . '📍 ' . CLUB_ADDRESS . "\n"
        . 'Очень ждём тебя! Хорошей игры 💜⚡',
        [[btn('🏠 В меню', 'menu')]]);
    answer_cb($cbId, 'Бронь создана! 🎉');
    notify_booking($bid);
}

// ══════════ АДМИН ══════════

function admin_kb(): array {
    return [
        [btn('📅 Сегодня', 'adm:day:0'), btn('📅 Завтра', 'adm:day:1')],
        [btn('🗓 Сетка занятости', 'adm:grid:0')],
        [btn('📋 Все активные', 'adm:all')],
        [btn('📊 CSV-отчёт', 'adm:export'), btn('📈 Статистика', 'adm:stats')],
    ];
}

function booking_line(array $r): string {
    $u = get_user((int)$r['user_id']);
    $st = state_label($r) . (!empty($r['staff']) && booking_state($r) !== 'wait' ? ' · ' . esc($r['staff']) : '');
    $phone = $u && $u['phone'] ? $u['phone'] : '—';
    return "<b>#{$r['id']}</b> · " . fmt_range($r) . ' · ' . ZONES[$r['zone']]['name'] . "\n"
        . '     👤 ' . esc(display_name($u)) . ' · ' . esc($phone) . ((int)$r['user_id'] < 0 ? ' · 🌐' : '') . "\n"
        . '     🎟 ' . TARIFFS[$r['tariff']][0] . " · 💰 {$r['price']} ₽ · $st";
}

function adm_day(int $chat, int $msgId, int $off): void {
    $d = new DateTime("today +$off day");
    $label = $off === 0 ? 'Сегодня' : ($off === 1 ? 'Завтра' : $d->format('d.m'));
    $st = db()->prepare("SELECT * FROM bookings WHERE start LIKE ? ORDER BY start");
    $st->execute([$d->format('Y-m-d') . '%']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $lines = ["👑 <b>Брони · $label " . $d->format('d.m') . ' (' . WDAYS[(int)$d->format('N') - 1] . ')</b>', DIV];
    $btns = [];
    if (!$rows) $lines[] = 'Броней на этот день нет.';
    foreach ($rows as $r) {
        $lines[] = booking_line($r) . "\n";
        if ($r['status'] === 'active') $btns[] = [btn("❌ Снять #{$r['id']}", "acancel:{$r['id']}:d$off")];
    }
    $btns[] = [btn('⬅️ В админ-меню', 'adm:menu')];
    edit($chat, $msgId, implode("\n", $lines), $btns);
}

function adm_grid(int $chat, int $msgId, int $off): void {
    $d = new DateTime("today +$off day");
    $label = $off === 0 ? 'Сегодня' : ($off === 1 ? 'Завтра' : $d->format('d.m'));
    $ruler = '';
    foreach ([0, 4, 8, 12, 16, 20] as $h) $ruler .= str_pad((string)$h, 4);
    $ruler = substr($ruler, 0, 24);
    $lines = ["👑 <b>Сетка занятости · $label (" . WDAYS[(int)$d->format('N') - 1] . ')</b>',
              '<i>цифра = занято мест в этот час, · = свободно</i>', DIV];
    foreach (ZONES as $z => $info) {
        $row = '';
        for ($h = 0; $h < 24; $h++) {
            $t = new DateTime($d->format('Y-m-d') . sprintf(' %02d:00', $h));
            $b = busy_count($z, $t, 1);
            $row .= $b === 0 ? '·' : ($b <= 9 ? (string)$b : '#');
        }
        $lines[] = $info['name'] . ' · мест: ' . $info['cap'];
        $lines[] = "<code>$ruler</code>";
        $lines[] = "<code>$row</code>\n";
    }
    $btns = [
        [btn('◀️ День назад', 'adm:grid:' . max(0, $off - 1)),
         btn('День вперёд ▶️', 'adm:grid:' . min(BOOK_DAYS_AHEAD - 1, $off + 1))],
        [btn('⬅️ В админ-меню', 'adm:menu')],
    ];
    edit($chat, $msgId, implode("\n", $lines), $btns);
}

function adm_all(int $chat, int $msgId): void {
    $rows = array_filter(active_bookings(), function ($r) {
        return (new DateTime($r['start']))->modify('+' . $r['hours'] . ' hour') > new DateTime();
    });
    usort($rows, fn($a, $b) => strcmp($a['start'], $b['start']));
    $lines = ['👑 <b>Все активные брони (' . count($rows) . ')</b>', DIV];
    $btns = [];
    if (!$rows) $lines[] = 'Активных броней нет.';
    foreach (array_slice($rows, 0, 25) as $r) {
        $s = new DateTime($r['start']);
        $u = get_user((int)$r['user_id']);
        $phone = $u && $u['phone'] ? $u['phone'] : '—';
        $lines[] = "<b>#{$r['id']}</b> · " . fmt_dt($s) . ' · ' . ZONES[$r['zone']]['name'] . "\n"
            . '     👤 ' . esc(display_name($u)) . ' · ' . esc($phone) . ((int)$r['user_id'] < 0 ? ' · 🌐' : '')
            . " · {$r['price']} ₽ · " . state_label($r);
        $btns[] = [btn("❌ Снять #{$r['id']}", "acancel:{$r['id']}:a")];
    }
    $btns[] = [btn('⬅️ В админ-меню', 'adm:menu')];
    edit($chat, $msgId, implode("\n", $lines), $btns);
}

function adm_stats(int $chat, int $msgId): void {
    $month = date('Y-m');
    $st = db()->prepare('SELECT * FROM bookings WHERE start LIKE ?');
    $st->execute([$month . '%']);
    $c = ['wait' => 0, 'arrived' => 0, 'noshow' => 0, 'removed' => 0, 'cancelled' => 0, 'done' => 0];
    $site = 0; $bot = 0; $sum = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $s = booking_state($r);
        $c[$s] = ($c[$s] ?? 0) + 1;
        if (in_array($s, ['wait', 'arrived', 'done'], true)) {
            $sum += (int)$r['price'];
            if ((int)$r['user_id'] < 0) $site++; else $bot++;
        }
    }
    $kept = $c['wait'] + $c['arrived'] + $c['done'];
    $marked = $c['arrived'] + $c['noshow'];
    $clients = db()->query('SELECT SUM(id > 0) b, SUM(id < 0) s FROM users')->fetch(PDO::FETCH_ASSOC);
    edit($chat, $msgId,
        "📈 <b>Статистика · $month</b>\n<i>места по дате начала брони</i>\n" . DIV . "\n"
        . "🧾 Броней (мест) без отмен: <b>$kept</b>\n"
        . "     🌐 с сайта: <b>$site</b> · 🤖 из бота: <b>$bot</b>\n"
        . "✅ Отмечены «пришёл»: <b>{$c['arrived']}</b>\n"
        . "🚫 Не пришли: <b>{$c['noshow']}</b>"
        . ($marked ? ' (' . round($c['noshow'] / $marked * 100) . '% от отмеченных)' : '') . "\n"
        . '❌ Отмены: <b>' . ($c['removed'] + $c['cancelled']) . "</b> (гостем {$c['cancelled']}, персоналом {$c['removed']})\n"
        . "💰 Сумма броней без отмен и неявок: <b>$sum ₽</b>\n"
        . "<i>это стоимость броней, а не полученные деньги</i>\n"
        . '👥 Клиентов в базе: <b>' . ((int)$clients['b'] + (int)$clients['s']) . '</b> (бот ' . (int)$clients['b'] . ', сайт ' . (int)$clients['s'] . ')',
        [[btn('⬅️ В админ-меню', 'adm:menu')]]);
}

function adm_export(int $chat, string $cbId): void {
    answer_cb($cbId, 'Готовлю отчёт…');
    $rows = db()->query('SELECT * FROM bookings ORDER BY start DESC')->fetchAll(PDO::FETCH_ASSOC);
    $csv = "\xEF\xBB\xBF";  // BOM, чтобы Excel открыл кириллицу
    $head = ['№', 'Дата', 'День', 'Начало', 'Конец', 'Зона', 'Тариф', 'Часов',
             'Цена ₽', 'Статус', 'Имя', 'Телефон', 'Имя в TG', 'Создана', 'Источник', 'Пришёл в', 'Отметил'];
    $csv .= implode(';', $head) . "\r\n";
    foreach ($rows as $r) {
        $s = new DateTime($r['start']);
        $e = (clone $s)->modify('+' . $r['hours'] . ' hour');
        $u = get_user((int)$r['user_id']);
        $cr = new DateTime($r['created']);
        $vals = [
            $r['id'], $s->format('d.m.Y'), WDAYS[(int)$s->format('N') - 1],
            $s->format('H:i'), $e->format('H:i'),
            trim(preg_replace('/^\S+\s/u', '', ZONES[$r['zone']]['name'])),
            trim(explode('·', preg_replace('/^\S+\s/u', '', TARIFFS[$r['tariff']][0]))[0]),
            $r['hours'], $r['price'],
            trim(preg_replace('/^\S+\s/u', '', state_label($r))),
            $u ? ($u['real_name'] ?: '') : '', $u ? ($u['phone'] ?: '') : '',
            $u ? ($u['name'] ?: '') : '', $cr->format('d.m.Y H:i'),
            (int)$r['user_id'] < 0 ? 'сайт' : 'бот',
            $r['arrived_at'] ? (new DateTime($r['arrived_at']))->format('d.m.Y H:i') : '',
            (string)($r['staff'] ?? ''),
        ];
        $vals = array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $vals);
        $csv .= implode(';', $vals) . "\r\n";
    }
    $csv .= "\r\n";
    $csv .= implode(';', ['ID', 'Имя', 'Имя в TG', 'Телефон', 'Броней всего', 'Первый визит']) . "\r\n";
    foreach (db()->query('SELECT * FROM users ORDER BY created')->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $cs = db()->prepare('SELECT COUNT(*) c FROM bookings WHERE user_id=?');
        $cs->execute([$u['id']]);
        $cnt = $cs->fetch(PDO::FETCH_ASSOC)['c'];
        $cr = $u['created'] ? (new DateTime($u['created']))->format('d.m.Y') : '';
        $vals = array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"',
            [$u['id'], $u['real_name'] ?: '', $u['name'] ?: '', $u['phone'] ?: '', $cnt, $cr]);
        $csv .= implode(';', $vals) . "\r\n";
    }
    $tmp = sys_get_temp_dir() . '/GamePlay_брони_' . date('d.m.Y_H-i') . '.csv';
    file_put_contents($tmp, $csv);
    send_document($chat, $tmp,
        '📊 <b>Отчёт GAME PLAY</b> · ' . date('d.m.Y H:i') . "\n"
        . 'Сверху все брони, снизу база клиентов. Открывается в Excel.');
    @unlink($tmp);
}

/** Куда вернуться из подтверждения: a — «Все активные», dN — «Брони на день N». */
function adm_back(string $org): string {
    return preg_match('/^d(\d)$/', $org, $m) ? 'adm:day:' . $m[1] : 'adm:all';
}

function adm_cancel_ask(int $chat, int $msgId, string $cbId, int $bid, string $org): void {
    $r = booking_get($bid);
    if (!$r || $r['status'] !== 'active') { answer_cb($cbId, 'Бронь не найдена или уже снята', true); return; }
    $site = (int)$r['user_id'] < 0;
    edit($chat, $msgId, "❓ <b>Снять бронь #$bid?</b>\n" . DIV . "\n" . booking_line($r) . "\n" . DIV . "\n"
        . 'Место освободится. ' . ($site ? 'Это клиент с сайта: Telegram у него нет, после снятия позвоните ему.' : 'Гостю придёт уведомление в Telegram.'),
        [[btn('✅ Да, снять', "acancelok:$bid:$org")], [btn('⬅️ Назад', adm_back($org))]]);
    answer_cb($cbId);
}

function adm_cancel(int $chat, int $msgId, string $cbId, int $bid, string $org, string $who): void {
    $r = booking_get($bid);
    if (!$r || !staff_set_status([$r], 'cancelled', $who)) { answer_cb($cbId, 'Бронь не найдена или уже снята', true); return; }
    staff_tell_client($r, 'cancelled');
    $u = get_user((int)$r['user_id']);
    $phone = $u && $u['phone'] ? $u['phone'] : '—';
    $site = (int)$r['user_id'] < 0;
    notify_staff("❌ <b>Бронь #$bid снята администратором</b> · " . esc($who) . "\n"
        . '👤 ' . esc(display_name($u)) . ' · ' . esc($phone) . "\n"
        . '🕹 ' . ZONES[$r['zone']]['name'] . ' · 📅 ' . fmt_dt(new DateTime($r['start'])) . "\n"
        . ($site ? '📞 Клиент с сайта, Telegram у него нет — позвоните ему.' : '💬 Гостю отправлено уведомление в Telegram.'),
        null, $chat);
    answer_cb($cbId, "Бронь #$bid снята ✅" . ($site ? "\nКлиент с сайта — позвоните ему: $phone" : ''), true);
    if (preg_match('/^d(\d)$/', $org, $m)) adm_day($chat, $msgId, (int)$m[1]); else adm_all($chat, $msgId);
}

// ══════════ РОУТИНГ ══════════

function handle_update(array $upd): void {
    if (isset($upd['message'])) handle_message($upd['message']);
    elseif (isset($upd['callback_query'])) handle_callback($upd['callback_query']);
}

/**
 * Сообщение не из личного чата (рабочая группа, канал). Бот там ничего не
 * записывает и не отвечает, кроме /chatid — по ней админ узнаёт id группы
 * (и темы, если в группе включены темы), чтобы вписать его в config.php.
 */
function handle_group_message(array $m): void {
    $text = trim($m['text'] ?? '');
    if (!preg_match('#^/([a-z_]+)(@\w+)?(\s|$)#i', $text, $mm)) return;
    $cmd = strtolower($mm[1]);
    $chat = (int)$m['chat']['id'];
    $thread = isset($m['message_thread_id']) && !empty($m['is_topic_message']) ? (int)$m['message_thread_id'] : 0;
    $p = ['chat_id' => $chat, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($thread) $p['message_thread_id'] = $thread;
    if ($cmd === 'chatid') {
        if (!is_admin((int)($m['from']['id'] ?? 0))) {
            $p['text'] = 'Эта команда — для администраторов клуба.';
        } else {
            $p['text'] = "🛠 <b>Рабочий чат</b>\n" . DIV . "\n"
                . "Впиши в <code>config.php</code> на сервере:\n\n"
                . "<code>const WORK_CHAT_ID = $chat;</code>\n"
                . ($thread ? "<code>const WORK_CHAT_THREAD = $thread;</code>  ← эта тема\n" : '')
                . "\nПотом: <code>systemctl restart gameplay-bot</code>";
        }
        api('sendMessage', $p);
        return;
    }
    // остальные команды — только в рабочем чате, в чужих группах бот молчит
    if (!work_chat_id() || $chat !== work_chat_id()) return;
    $reply = staff_command($cmd);
    if ($reply === null) return;
    $p['text'] = $reply;
    api('sendMessage', $p);
}

function handle_message(array $m): void {
    if (($m['chat']['type'] ?? 'private') !== 'private') { handle_group_message($m); return; }
    $chat = (int)$m['chat']['id'];
    $name = trim(($m['from']['first_name'] ?? '') . ' ' . ($m['from']['last_name'] ?? ''));
    save_user($chat, $name ?: ('id' . $chat));
    $u = get_user($chat);
    $text = trim($m['text'] ?? '');

    // контакт с номером
    if (isset($m['contact']) && $u['state'] === 'phone') {
        set_user($chat, 'phone', $m['contact']['phone_number']);
        api('sendMessage', ['chat_id' => $chat, 'text' => '✅ Номер сохранён!',
            'reply_markup' => ['remove_keyboard' => true]]);
        after_phone($chat);
        return;
    }
    // номер текстом
    if ($u['state'] === 'phone' && $text !== '' && $text[0] !== '/') {
        $digits = preg_replace('/\D/', '', $text);
        if (strlen($digits) < 10) {
            send($chat, '🤔 Похоже, это не номер. Попробуй ещё раз или нажми кнопку ниже.');
            return;
        }
        set_user($chat, 'phone', $text);
        api('sendMessage', ['chat_id' => $chat, 'text' => '✅ Номер сохранён!',
            'reply_markup' => ['remove_keyboard' => true]]);
        after_phone($chat);
        return;
    }
    // имя
    if ($u['state'] === 'name' && $text !== '' && $text[0] !== '/') {
        $nm = preg_replace('/\s+/u', ' ', trim($text));
        $len = u_len($nm);
        if ($len < 2 || $len > 40) {
            send($chat, '🤔 Напиши, пожалуйста, обычное имя — от 2 до 40 символов.');
            return;
        }
        set_user($chat, 'real_name', $nm);
        set_user($chat, 'state', null);
        send($chat, '✅ Записал: <b>' . esc($nm) . '</b>');
        show_confirm($chat, null);
        return;
    }

    // команды
    $cmd = explode('@', explode(' ', $text)[0])[0];
    switch ($cmd) {
        case '/start':
            set_user($chat, 'state', null);
            send($chat, main_menu_text(), main_menu_kb());
            break;
        case '/book':
            set_user($chat, 'state', null);
            set_draft($chat, []);
            [$t, $k] = view_book();
            send($chat, $t, $k);
            break;
        case '/load':   [$t, $k] = view_load();   send($chat, $t, $k); break;
        case '/prices': [$t, $k] = view_prices(); send($chat, $t, $k); break;
        case '/my':     [$t, $k] = view_my($chat); send($chat, $t, $k); break;
        case '/admin':
            if (is_admin($chat)) send($chat, "👑 <b>Админ-панель GAME PLAY</b>\n" . DIV . "\nЧто показать?", admin_kb());
            break;
        case '/today':
        case '/now':
            if (is_admin($chat)) send($chat, staff_command(substr($cmd, 1)));
            break;
        case '/stats':
            if (is_admin($chat)) {
                // показываем как в панели, но новым сообщением
                send($chat, '📈 Статистика в админ-панели: /admin');
            }
            break;
    }
}

function after_phone(int $chat): void {
    $u = get_user($chat);
    if (!$u['real_name']) {
        set_user($chat, 'state', 'name');
        send($chat, "👤 <b>Как тебя записать?</b>\n" . DIV . "\n"
            . 'Напиши имя (можно имя и фамилию) — так администратор быстро найдёт твою бронь.');
    } else {
        set_user($chat, 'state', null);
        show_confirm($chat, null);
    }
}

function handle_callback(array $cb): void {
    $type = $cb['message']['chat']['type'] ?? 'private';
    if (str_starts_with($cb['data'] ?? '', 'st:')) {
        // кнопки под бронями: в рабочем чате — любой его участник, в личке — только админы
        $allowed = $type === 'private'
            ? is_admin((int)($cb['from']['id'] ?? 0))
            : (work_chat_id() !== 0 && (int)($cb['message']['chat']['id'] ?? 0) === work_chat_id());
        if ($allowed) staff_action($cb); else answer_cb($cb['id'], 'Эти кнопки — для персонала клуба', true);
        return;
    }
    if ($type !== 'private') { answer_cb($cb['id']); return; }
    $chat = (int)$cb['message']['chat']['id'];
    $msgId = (int)$cb['message']['message_id'];
    $cbId = $cb['id'];
    $data = $cb['data'] ?? '';
    $uid = (int)$cb['from']['id'];
    save_user($uid, trim(($cb['from']['first_name'] ?? '') . ' ' . ($cb['from']['last_name'] ?? '')));

    // пользовательские экраны
    if ($data === 'menu') { set_user($uid, 'state', null); edit($chat, $msgId, main_menu_text(), main_menu_kb()); answer_cb($cbId); return; }
    if ($data === 'book') { set_draft($uid, []); [$t, $k] = view_book(); edit($chat, $msgId, $t, $k); answer_cb($cbId); return; }
    if ($data === 'load') { [$t, $k] = view_load(); edit($chat, $msgId, $t, $k); answer_cb($cbId); return; }
    if ($data === 'prices') { [$t, $k] = view_prices(); edit($chat, $msgId, $t, $k); answer_cb($cbId); return; }
    if ($data === 'my') { [$t, $k] = view_my($uid); edit($chat, $msgId, $t, $k); answer_cb($cbId); return; }
    if ($data === 'info') { [$t, $k] = view_info(); edit($chat, $msgId, $t, $k); answer_cb($cbId); return; }
    if ($data === 'full') { answer_cb($cbId, 'На это время всё занято 😔'); return; }
    if ($data === 'pdc:yes') {
        record_consent($uid, 'bot');
        set_user($uid, 'state', null);
        if (draft_valid(get_draft($uid))) { ask_details_or_confirm($chat, $msgId, $cbId); }
        else { answer_cb($cbId, 'Спасибо! Теперь можно бронировать'); edit($chat, $msgId, main_menu_text(), main_menu_kb()); }
        return;
    }

    // поток бронирования
    if (str_starts_with($data, 'zone:')) {
        $zone = substr($data, 5);
        if (!isset(ZONES[$zone])) { answer_cb($cbId, 'Неизвестная зона, начни заново: /book', true); return; }
        $d = get_draft($uid); $d['zone'] = $zone; unset($d['tariff'], $d['date'], $d['hour']);
        set_draft($uid, $d);
        show_tariffs($chat, $msgId, $zone);
        answer_cb($cbId); return;
    }
    if (str_starts_with($data, 'tarif:')) {
        $tariff = substr($data, 6);
        if (!isset(TARIFFS[$tariff])) { answer_cb($cbId, 'Неизвестный тариф, начни заново: /book', true); return; }
        $d = get_draft($uid);
        if (empty($d['zone']) || !isset(ZONES[$d['zone']])) { answer_cb($cbId, 'Сессия устарела, начни заново', true); return; }
        $d['tariff'] = $tariff; set_draft($uid, $d);
        show_dates($chat, $msgId, $d['zone'], $tariff);
        answer_cb($cbId); return;
    }
    if (str_starts_with($data, 'date:')) {
        $date = substr($data, 5);
        // дата должна быть настоящей и в пределах окна бронирования
        $dt = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date
            || $dt < new DateTime('today')
            || $dt > new DateTime('today +' . (BOOK_DAYS_AHEAD - 1) . ' day')) {
            answer_cb($cbId, 'Эту дату выбрать нельзя, начни заново: /book', true); return;
        }
        $d = get_draft($uid);
        if (empty($d['zone']) || !isset(ZONES[$d['zone']])
            || empty($d['tariff']) || !isset(TARIFFS[$d['tariff']])) {
            answer_cb($cbId, 'Сессия устарела, начни заново', true); return;
        }
        $d['date'] = $date; set_draft($uid, $d);
        show_hours($chat, $msgId, $cbId, $d);
        answer_cb($cbId); return;
    }
    if (str_starts_with($data, 'hour:')) {
        $hour = substr($data, 5);
        if (!ctype_digit($hour) || (int)$hour > 23) { answer_cb($cbId, 'Такого времени нет', true); return; }
        $d = get_draft($uid);
        if (empty($d['date'])) { answer_cb($cbId, 'Сессия устарела, начни заново', true); return; }
        $d['hour'] = (int)$hour; set_draft($uid, $d);
        ask_details_or_confirm($chat, $msgId, $cbId);
        return;
    }
    if ($data === 'confirm') { do_confirm($chat, $msgId, $cbId); return; }

    // отмена своей брони
    if (str_starts_with($data, 'cancel:')) {
        $bid = (int)substr($data, 7);
        $st = db()->prepare('SELECT * FROM bookings WHERE id=? AND user_id=?');
        $st->execute([$bid, $uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['status'] !== 'active') { answer_cb($cbId, 'Бронь не найдена', true); return; }
        db()->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([$bid]);
        answer_cb($cbId, "Бронь #$bid отменена");
        [$t, $k] = view_my($uid);
        edit($chat, $msgId, $t, $k);
        $u = get_user($uid);
        notify_staff("❌ <b>Отмена брони #$bid</b>\n👤 " . esc(display_name($u)) . ' · ' . esc($u['phone'] ?: '—') . "\n"
            . '🕹 ' . ZONES[$r['zone']]['name'] . ' · 📅 ' . fmt_dt(new DateTime($r['start'])));
        return;
    }

    // админ
    if (str_starts_with($data, 'adm:') || str_starts_with($data, 'acancel')) {
        if (!is_admin($uid)) { answer_cb($cbId); return; }
        if ($data === 'adm:menu') { edit($chat, $msgId, "👑 <b>Админ-панель GAME PLAY</b>\n" . DIV . "\nЧто показать?", admin_kb()); answer_cb($cbId); return; }
        if (str_starts_with($data, 'adm:day:')) { adm_day($chat, $msgId, (int)substr($data, 8)); answer_cb($cbId); return; }
        if (str_starts_with($data, 'adm:grid:')) { adm_grid($chat, $msgId, (int)substr($data, 9)); answer_cb($cbId); return; }
        if ($data === 'adm:all') { adm_all($chat, $msgId); answer_cb($cbId); return; }
        if ($data === 'adm:stats') { adm_stats($chat, $msgId); answer_cb($cbId); return; }
        if ($data === 'adm:export') { adm_export($chat, $cbId); return; }
        if (preg_match('#^acancel:(\d+)(?::(a|d\d))?$#', $data, $m)) { adm_cancel_ask($chat, $msgId, $cbId, (int)$m[1], $m[2] ?? 'a'); return; }
        if (preg_match('#^acancelok:(\d+):(a|d\d)$#', $data, $m)) { adm_cancel($chat, $msgId, $cbId, (int)$m[1], $m[2], staff_name($cb['from'])); return; }
    }

    answer_cb($cbId);
}
