<?php
/**
 * ════════════════════════════════════════════════════════
 *  ⚡ GAME PLAY — ядро Telegram-бота (PHP, webhook)
 *  Подключается из webhook.php, reminder.php, set_webhook.php
 * ════════════════════════════════════════════════════════
 */

// ══════════ СЕКРЕТЫ ══════════
// BOT_TOKEN, ADMIN_IDS, WH_SECRET, CRON_KEY лежат в config.php,
// который не коммитится в git. Шаблон — config.example.php.

// (тесты объявляют эти константы сами и config.php не читают)
if (!defined('BOT_TOKEN')) {
    if (!is_file(__DIR__ . '/config.php')) {
        http_response_code(500);
        exit('Нет config.php — скопируй config.example.php в config.php и заполни его.');
    }
    require_once __DIR__ . '/config.php';
}

// ══════════ НАСТРОЙКИ КЛУБА ══════════

/**
 * Какой IP-протокол использовать для обращений к api.telegram.org.
 * CURL_IPRESOLVE_WHATEVER — пробовать оба (подходит почти всегда).
 * Переопределить можно в config.php: например, CURL_IPRESOLVE_V6,
 * если провайдер режет IPv4 к Telegram, или V4 при сломанном IPv6.
 */
if (!defined('GP_IPRESOLVE')) {
    define('GP_IPRESOLVE', CURL_IPRESOLVE_WHATEVER);
}

const CLUB_PHONE   = '+7 906 035 46 32';
const CLUB_SITE    = 'https://gameplaycc.ru';
const CLUB_CHANNEL = 'https://t.me/gameplaypcclub';
const CLUB_ADDRESS = 'г. Электросталь, проспект Ленина, 40/8';

const DAY_START       = 8;   // «День»  = старт с 8:00 до 15:59
const EVENING_START   = 16;  // «Вечер» = старт с 16:00 (и до 7:59 утра)
const NIGHT_HOUR      = 20;  // ночной пакет 20:00–8:00
const BOOK_DAYS_AHEAD = 7;

date_default_timezone_set('Europe/Moscow');

// ══════════ ЗОНЫ / ТАРИФЫ / ЦЕНЫ ══════════

const ZONES = [
    'normal'   => ['name' => '🖥 NORMAL',         'cap' => 10],
    'bootcamp' => ['name' => '🔥 BOOTCAMP · VIP', 'cap' => 5],
    'ps'       => ['name' => '🎮 PlayStation 5',  'cap' => 1],
    'ps_vip'   => ['name' => '👑 PS5 Pro · VIP',  'cap' => 1],
];

const TARIFFS = [
    'kiber' => ['⚡ Киберчас · 1 час', 1],
    'tripl' => ['🎯 Трипл · 3 часа', 3],
    'ultra' => ['🚀 Ультра · 5 часов', 5],
    'night' => ['🌙 Ночные часы · 20:00–8:00', 12],
];

const PRICES = [
    'normal' => [
        'day'   => ['wd' => [120, 320, 480], 'we' => [140, 360, 560]],
        'eve'   => ['wd' => [140, 360, 560], 'we' => [160, 420, 640]],
        'night' => ['wd' => 580, 'we' => 660],
    ],
    'bootcamp' => [
        'day'   => ['wd' => [170, 450, 680], 'we' => [190, 520, 780]],
        'eve'   => ['wd' => [190, 520, 780], 'we' => [220, 590, 900]],
        'night' => ['wd' => 800, 'we' => 920],
    ],
    'ps' => [
        'day'   => ['wd' => [270, 620, 950],  'we' => [320, 700, 1100]],
        'eve'   => ['wd' => [300, 700, 1100], 'we' => [350, 800, 1250]],
        'night' => ['wd' => 1300, 'we' => 1500],
    ],
    'ps_vip' => [
        'day'   => ['wd' => [320, 820, 1200],  'we' => [370, 900, 1350]],
        'eve'   => ['wd' => [350, 900, 1350],  'we' => [400, 1000, 1450]],
        'night' => ['wd' => 1500, 'we' => 1750],
    ],
];

const WDAYS = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];
const DIV = '━━━━━━━━━━━━━━━━━━';
const STATUS_RU = ['active' => '✅ активна', 'cancelled' => '❌ отменена', 'done' => '🏁 завершена'];

// ══════════ БАЗА ДАННЫХ ══════════

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = defined('GP_DB') ? GP_DB : __DIR__ . '/gameplay.db';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY, name TEXT, real_name TEXT, phone TEXT,
            state TEXT, draft TEXT, created TEXT)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            zone TEXT NOT NULL, start TEXT NOT NULL, hours INTEGER NOT NULL,
            tariff TEXT NOT NULL, price INTEGER NOT NULL,
            status TEXT DEFAULT 'active', reminded INTEGER DEFAULT 0, created TEXT)");
    }
    return $pdo;
}

function get_user(int $uid): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE id=?');
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

function save_user(int $uid, string $tg_name): void {
    if (!get_user($uid)) {
        db()->prepare('INSERT INTO users (id, name, created) VALUES (?,?,?)')
            ->execute([$uid, $tg_name, date('c')]);
    }
}

function set_user(int $uid, string $field, ?string $val): void {
    db()->prepare("UPDATE users SET $field=? WHERE id=?")->execute([$val, $uid]);
}

function display_name(?array $u): string {
    if (!$u) return '?';
    return $u['real_name'] ?: ($u['name'] ?: '?');
}

function get_draft(int $uid): array {
    $u = get_user($uid);
    return $u && $u['draft'] ? (json_decode($u['draft'], true) ?: []) : [];
}

function set_draft(int $uid, array $d): void {
    set_user($uid, 'draft', json_encode($d, JSON_UNESCAPED_UNICODE));
}

function add_booking(int $uid, string $zone, string $startIso, int $hours, string $tariff, int $price): int {
    db()->prepare('INSERT INTO bookings (user_id, zone, start, hours, tariff, price, created)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute([$uid, $zone, $startIso, $hours, $tariff, $price, date('c')]);
    return (int)db()->lastInsertId();
}

function active_bookings(?string $zone = null): array {
    if ($zone) {
        $st = db()->prepare("SELECT * FROM bookings WHERE status='active' AND zone=?");
        $st->execute([$zone]);
    } else {
        $st = db()->query("SELECT * FROM bookings WHERE status='active'");
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ══════════ ЛОГИКА КЛУБА ══════════

function busy_count(string $zone, DateTime $start, int $hours): int {
    $rows = active_bookings($zone);
    $peak = 0;
    for ($i = 0; $i < $hours; $i++) {
        $t = (clone $start)->modify("+$i hour");
        $cnt = 0;
        foreach ($rows as $r) {
            $bs = new DateTime($r['start']);
            $be = (clone $bs)->modify('+' . $r['hours'] . ' hour');
            if ($bs <= $t && $t < $be) $cnt++;
        }
        $peak = max($peak, $cnt);
    }
    return $peak;
}

function free_seats(string $zone, DateTime $start, int $hours): int {
    return ZONES[$zone]['cap'] - busy_count($zone, $start, $hours);
}

function calc_price(string $zone, string $tariff, DateTime $d, int $hour): int {
    $key = ((int)$d->format('N')) >= 5 ? 'we' : 'wd';  // пт(5)–вс(7) = выходные
    if ($tariff === 'night') return PRICES[$zone]['night'][$key];
    $idx = ['kiber' => 0, 'tripl' => 1, 'ultra' => 2][$tariff];
    $period = ($hour >= DAY_START && $hour < EVENING_START) ? 'day' : 'eve';
    return PRICES[$zone][$period][$key][$idx];
}

function bar(int $busy, int $cap, int $width = 10): string {
    $filled = $cap ? (int)round($busy / $cap * $width) : 0;
    return str_repeat('▰', $filled) . str_repeat('▱', $width - $filled);
}

function fmt_dt(DateTime $dt): string {
    return $dt->format('d.m') . ' (' . WDAYS[(int)$dt->format('N') - 1] . ') ' . $dt->format('H:00');
}

function fmt_range(array $r): string {
    $s = new DateTime($r['start']);
    $e = (clone $s)->modify('+' . $r['hours'] . ' hour');
    return $s->format('H:00') . '–' . $e->format('H:00');
}

function u_len(string $s): int {
    if (function_exists('mb_strlen')) return mb_strlen($s, 'UTF-8');
    return preg_match_all('/./u', $s) ?: strlen($s);
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ══════════ TELEGRAM API ══════════

function api(string $method, array $params = []): ?array {
    if (defined('GP_TEST')) {                       // для тестов без сети
        $GLOBALS['gp_calls'][] = [$method, $params];
        return ['ok' => true, 'result' => ['message_id' => 1]];
    }
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/$method");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        // Протокол не форсим. На Timeweb часть IPv4-адресов api.telegram.org
        // недоступна, а IPv6 работает; на другом хостинге бывает наоборот.
        // curl сам пробует оба и берёт тот, что отвечает.
        CURLOPT_IPRESOLVE => GP_IPRESOLVE,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function send(int $chat, string $text, ?array $kbRows = null): void {
    $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML',
          'disable_web_page_preview' => true];
    if ($kbRows !== null) $p['reply_markup'] = ['inline_keyboard' => $kbRows];
    api('sendMessage', $p);
}

function edit(int $chat, int $msgId, string $text, ?array $kbRows = null): void {
    $p = ['chat_id' => $chat, 'message_id' => $msgId, 'text' => $text,
          'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($kbRows !== null) $p['reply_markup'] = ['inline_keyboard' => $kbRows];
    $r = api('editMessageText', $p);
    if (!$r || empty($r['ok'])) send($chat, $text, $kbRows);   // фолбэк
}

function answer_cb(string $cbId, string $text = '', bool $alert = false): void {
    api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => $text, 'show_alert' => $alert]);
}

function send_document(int $chat, string $filepath, string $caption = ''): void {
    if (defined('GP_TEST')) { $GLOBALS['gp_calls'][] = ['sendDocument', $filepath]; return; }
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chat,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'document' => new CURLFile($filepath),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_IPRESOLVE => GP_IPRESOLVE,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function btn(string $text, string $data): array {
    return ['text' => $text, 'callback_data' => $data];
}

function is_admin(int $uid): bool {
    return in_array($uid, ADMIN_IDS, true);
}
