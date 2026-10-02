<?php
/**
 * ════════════════════════════════════════════════════════
 *  ⚡ GAME PLAY — бронирование с сайта (/booking)
 *
 *  Библиотека без HTTP-кода: подключается из booking/api.php
 *  и из тестов. Использует те же функции, базу, цены и
 *  вместимость, что и Telegram-бот (core.php), поэтому брони
 *  с сайта и из бота живут в одной таблице и видны друг другу.
 *
 *  Клиенты с сайта хранятся в таблице users с ОТРИЦАТЕЛЬНЫМ id
 *  (Telegram-id всегда положительные): так админка, CSV-выгрузка
 *  и уведомления админам работают без изменений, а бот не
 *  пытается писать таким пользователям в Telegram.
 * ════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/core.php';

/** Подписи зон для сайта (в боте — с эмодзи, здесь — чисто). */
const SITE_ZONES = [
    'bootcamp' => ['title' => 'Bootcamp', 'tag' => 'VIP', 'sub' => 'RTX 5060 · 32 ГБ · 300 Гц, изогнутые'],
    'normal'   => ['title' => 'Normal',   'tag' => '',    'sub' => 'RTX 3060 · 16 ГБ · 180 Гц'],
    'ps_vip'   => ['title' => 'PS5 Pro · VIP', 'tag' => 'VIP', 'sub' => 'PlayStation 5 Pro · 65″ 120 Гц · VIP зона'],
    'ps'       => ['title' => 'PS5 · GamePlay', 'tag' => '', 'sub' => 'PlayStation 5 · 55″ 120 Гц · обычная зона'],
];

const SITE_TARIFFS = [
    'kiber' => ['title' => 'Киберчас', 'sub' => '1 час'],
    'tripl' => ['title' => 'Трипл',    'sub' => '3 часа'],
    'ultra' => ['title' => 'Ультра',   'sub' => '5 часов'],
    'night' => ['title' => 'Ночь',     'sub' => '20:00 – 8:00'],
];

/** Сколько активных будущих броней может держать один телефон. */
const SITE_MAX_ACTIVE = 3;

function is_site_user(int $uid): bool {
    return $uid < 0;
}

/**
 * Телефон → только цифры в формате 7XXXXXXXXXX.
 * null, если это не похоже на номер.
 */
function site_phone_digits(string $raw): ?string {
    $d = preg_replace('/\D+/', '', $raw);
    if ($d === '') return null;
    if (strlen($d) === 10) $d = '7' . $d;                 // 906… → 7906…
    if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
    if (strlen($d) < 10 || strlen($d) > 15) return null;
    return $d;
}

function site_phone_pretty(string $digits): string {
    if (strlen($digits) === 11 && $digits[0] === '7') {
        return sprintf('+7 %s %s %s %s', substr($digits, 1, 3), substr($digits, 4, 3),
            substr($digits, 7, 2), substr($digits, 9, 2));
    }
    return '+' . $digits;
}

/** Имя: 2–40 символов, как в боте. */
function site_clean_name(string $raw): ?string {
    $nm = preg_replace('/\s+/u', ' ', trim($raw));
    $len = u_len($nm);
    if ($len < 2 || $len > 40) return null;
    return $nm;
}

/**
 * Пользователь сайта по телефону: находим существующего (id < 0)
 * или создаём нового с id = min(id) - 1. Имя обновляем на последнее.
 */
function site_user_for(string $digits, string $name): int {
    $pretty = site_phone_pretty($digits);
    $st = db()->prepare('SELECT id FROM users WHERE id < 0 AND phone = ?');
    $st->execute([$pretty]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        $id = (int)$id;
        set_user($id, 'real_name', $name);
        return $id;
    }
    $min = (int)db()->query('SELECT COALESCE(MIN(id), 0) FROM users')->fetchColumn();
    $id = min($min, 0) - 1;
    db()->prepare('INSERT INTO users (id, name, real_name, phone, created) VALUES (?,?,?,?,?)')
        ->execute([$id, 'site', $name, $pretty, date('c')]);
    return $id;
}

/** Даты, на которые можно бронировать (как в боте: сегодня + BOOK_DAYS_AHEAD-1). */
function site_days(): array {
    $out = [];
    for ($i = 0; $i < BOOK_DAYS_AHEAD; $i++) {
        $d = new DateTime("today +$i day");
        $n = (int)$d->format('N');
        $out[] = [
            'date'    => $d->format('Y-m-d'),
            'label'   => $i === 0 ? 'Сегодня' : ($i === 1 ? 'Завтра' : $d->format('j.m')),
            'wday'    => WDAYS[$n - 1],
            'weekend' => $n >= 5,
        ];
    }
    return $out;
}

/** Всё, что нужно странице, чтобы нарисовать шаги без лишних запросов. */
function site_config(): array {
    $zones = [];
    foreach (SITE_ZONES as $key => $z) {
        $p = PRICES[$key];
        $zones[] = [
            'key'   => $key,
            'title' => $z['title'],
            'tag'   => $z['tag'],
            'sub'   => $z['sub'],
            'cap'   => ZONES[$key]['cap'],
            'from'  => min($p['day']['wd'][0], $p['day']['we'][0], $p['eve']['wd'][0], $p['eve']['we'][0]),
            'prices'=> $p,
        ];
    }
    $tariffs = [];
    foreach (SITE_TARIFFS as $key => $t) {
        $tariffs[] = ['key' => $key, 'title' => $t['title'], 'sub' => $t['sub'], 'hours' => TARIFFS[$key][1]];
    }
    return [
        'zones'      => $zones,
        'tariffs'    => $tariffs,
        'days'       => site_days(),
        'day_start'  => DAY_START,
        'eve_start'  => EVENING_START,
        'night_hour' => NIGHT_HOUR,
        'phone'      => CLUB_PHONE,
        'address'    => CLUB_ADDRESS,
        'channel'    => CLUB_CHANNEL,
        'now'        => (new DateTime())->format('c'),
    ];
}

function site_date_ok(string $date): bool {
    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) return false;
    return $dt >= new DateTime('today') && $dt <= new DateTime('today +' . (BOOK_DAYS_AHEAD - 1) . ' day');
}

/**
 * Свободные окна на дату: для каждого часа — сколько мест свободно и цена.
 * Прошедшие часы не возвращаются. Для ночного тарифа — одно окно в NIGHT_HOUR.
 */
function site_slots(string $zone, string $tariff, string $date): array {
    if (!isset(ZONES[$zone]) || !isset(TARIFFS[$tariff]) || !site_date_ok($date)) {
        return ['ok' => false, 'error' => 'Неверные параметры'];
    }
    $hours = TARIFFS[$tariff][1];
    $now = new DateTime();
    $slots = [];
    $range = $tariff === 'night' ? [NIGHT_HOUR] : range(0, 23);
    foreach ($range as $h) {
        $start = new DateTime(sprintf('%s %02d:00', $date, $h));
        if ($start < $now) continue;
        $free = free_seats($zone, $start, $hours);
        $slots[] = [
            'hour'  => $h,
            'free'  => max(0, $free),
            'price' => calc_price($zone, $tariff, $start, $h),
        ];
    }
    return ['ok' => true, 'zone' => $zone, 'tariff' => $tariff, 'date' => $date, 'hours' => $hours, 'slots' => $slots];
}

/**
 * Создать бронь. Проверки те же, что в do_confirm() бота:
 * будущее время, окно в пределах BOOK_DAYS_AHEAD, свободное место —
 * последнее проверяется в транзакции, чтобы два одновременных запроса
 * не заняли одно место.
 */
function site_book(array $in): array {
    $zone   = (string)($in['zone'] ?? '');
    $tariff = (string)($in['tariff'] ?? '');
    $date   = (string)($in['date'] ?? '');
    $hour   = $in['hour'] ?? null;
    $name   = site_clean_name((string)($in['name'] ?? ''));
    $digits = site_phone_digits((string)($in['phone'] ?? ''));

    if (!isset(ZONES[$zone]))        return ['ok' => false, 'error' => 'Выберите зону'];
    if (!isset(TARIFFS[$tariff]))    return ['ok' => false, 'error' => 'Выберите тариф'];
    if (!site_date_ok($date))        return ['ok' => false, 'error' => 'Эту дату нельзя забронировать'];
    if (!is_numeric($hour) || (int)$hour < 0 || (int)$hour > 23)
                                     return ['ok' => false, 'error' => 'Выберите время'];
    if ($name === null)              return ['ok' => false, 'error' => 'Напишите имя — от 2 до 40 символов'];
    if ($digits === null)            return ['ok' => false, 'error' => 'Похоже, это не номер телефона'];
    $hour = (int)$hour;
    if ($tariff === 'night' && $hour !== NIGHT_HOUR)
                                     return ['ok' => false, 'error' => 'Ночной тариф начинается в ' . NIGHT_HOUR . ':00'];

    $hours = TARIFFS[$tariff][1];
    $start = new DateTime(sprintf('%s %02d:00', $date, $hour));
    if ($start < new DateTime())     return ['ok' => false, 'error' => 'Это время уже прошло, выберите другое'];
    $price = calc_price($zone, $tariff, $start, $hour);

    db()->exec('BEGIN IMMEDIATE');
    try {
        $uid = site_user_for($digits, $name);

        // лимит на телефон: активные брони, которые ещё не закончились
        $active = 0;
        foreach (active_bookings() as $r) {
            if ((int)$r['user_id'] !== $uid) continue;
            $end = (new DateTime($r['start']))->modify('+' . $r['hours'] . ' hour');
            if ($end > new DateTime()) $active++;
        }
        if ($active >= SITE_MAX_ACTIVE) {
            db()->exec('ROLLBACK');
            return ['ok' => false, 'error' => 'На этот номер уже есть ' . SITE_MAX_ACTIVE . ' активные брони. Позвоните нам: ' . CLUB_PHONE];
        }
        if (free_seats($zone, $start, $hours) <= 0) {
            db()->exec('ROLLBACK');
            return ['ok' => false, 'error' => 'Место только что заняли. Выберите другое время', 'code' => 'full'];
        }
        $bid = add_booking($uid, $zone, $start->format('c'), $hours, $tariff, $price);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        return ['ok' => false, 'error' => 'Не получилось сохранить бронь, попробуйте ещё раз'];
    }

    $end = (clone $start)->modify("+$hours hour");
    $pretty = site_phone_pretty($digits);
    foreach (ADMIN_IDS as $aid) {
        send($aid, "🆕 <b>Новая бронь #$bid</b> · 🌐 с сайта\n" . DIV . "\n"
            . '👤 ' . esc($name) . " · $pretty\n"
            . '🕹 ' . ZONES[$zone]['name'] . "\n"
            . '🎟 ' . TARIFFS[$tariff][0] . "\n"
            . '📅 ' . fmt_dt($start) . "\n💰 $price ₽");
    }

    return [
        'ok'     => true,
        'id'     => $bid,
        'zone'   => SITE_ZONES[$zone]['title'],
        'tariff' => SITE_TARIFFS[$tariff]['title'] . ' · ' . SITE_TARIFFS[$tariff]['sub'],
        'start'  => $start->format('c'),
        'end'    => $end->format('c'),
        'when'   => fmt_dt($start),
        'until'  => $end->format('H:00'),
        'price'  => $price,
        'name'   => $name,
        'phone'  => $pretty,
    ];
}
