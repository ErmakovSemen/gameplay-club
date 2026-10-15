<?php
/**
 * ════════════════════════════════════════════════════════
 *  ⚡ GAME PLAY — staff.php: всё для персонала клуба
 *
 *  · карточки броней в рабочем чате с кнопками
 *    «Пришёл» / «Не пришёл» / «Снять бронь» (с подтверждением);
 *  · команды в рабочем чате: /today, /now, /help;
 *  · напоминание «гость не отмечен» через NOSHOW_PROMPT_MIN минут
 *    после начала брони и утренняя сводка в DIGEST_HOUR — их
 *    отправляет staff_tick(), который раз в минуту зовёт poller.php.
 *
 *  Кнопками в рабочем чате может пользоваться любой его участник
 *  (поэтому в чате должны быть только сотрудники), в личке с ботом —
 *  только админы из ADMIN_IDS. Под каждой отметкой видно, кто её сделал.
 *
 *  Подключается из webhook.php и site_booking.php.
 * ════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/core.php';

// Настройки можно переопределить в config.php.
if (!defined('DIGEST_HOUR'))       define('DIGEST_HOUR', 10);               // час утренней сводки; -1 — выключить
if (!defined('NOSHOW_PROMPT_MIN')) define('NOSHOW_PROMPT_MIN', BOOKING_HOLD_MIN); // 0 — не напоминать

const STAFF_MAX_LIST = 30;   // сколько броней максимум в одном сообщении /today

// ══════════ БРОНИ И ГРУППЫ ══════════

function booking_get(int $id): ?array {
    $st = db()->prepare('SELECT * FROM bookings WHERE id=?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Бронь на компанию — несколько строк одного клиента на одну зону и одно время. */
function booking_group(array $r): array {
    $st = db()->prepare('SELECT * FROM bookings WHERE user_id=? AND zone=? AND start=? ORDER BY id');
    $st->execute([$r['user_id'], $r['zone'], $r['start']]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** wait — ждём, arrived — пришёл, noshow — не пришёл, removed — снята персоналом, cancelled — отменена, done */
function booking_state(array $r): string {
    if ($r['status'] === 'active') return !empty($r['arrived_at']) ? 'arrived' : 'wait';
    if ($r['status'] === 'cancelled') return !empty($r['staff']) ? 'removed' : 'cancelled';
    return (string)$r['status'];
}

const STATE_RU = [
    'wait' => '⏳ ждём', 'arrived' => '✅ пришёл', 'noshow' => '🚫 не пришёл',
    'removed' => '❌ снята', 'cancelled' => '❌ отменена', 'done' => '🏁 завершена',
];

function state_label(array $r): string {
    $s = booking_state($r);
    return STATE_RU[$s] ?? $s;
}

function staff_name(array $from): string {
    $n = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
    if ($n === '' && !empty($from['username'])) $n = '@' . $from['username'];
    if ($n === '') $n = 'id' . (int)($from['id'] ?? 0);
    return preg_replace('/^(.{40}).+$/us', '$1…', $n);
}

function hm(?string $iso): string {
    return $iso ? (new DateTime($iso))->format('H:i') : '';
}

/** Итоговая строка состояния брони (или брони на компанию). */
function staff_status_line(array $g): string {
    $n = count($g);
    $by = [];
    foreach ($g as $x) $by[booking_state($x)][] = $x;
    if (count($by) > 1) {
        $parts = [];
        foreach ($by as $s => $xs) $parts[] = (STATE_RU[$s] ?? $s) . ' ' . count($xs);
        return implode(' · ', $parts);
    }
    $s = array_key_first($by);
    $x = $g[0];
    $who = esc((string)$x['staff']);
    $pl = $n > 1;
    switch ($s) {
        case 'wait':      return '⏳ Ждём ' . ($pl ? 'гостей' : 'гостя');
        case 'arrived':   return ($pl ? '✅ Пришли' : '✅ Пришёл') . " · $who, " . hm($x['arrived_at']);
        case 'noshow':    return ($pl ? '🚫 Не пришли' : '🚫 Не пришёл') . " — место освобождено · $who, " . hm($x['staff_at']);
        case 'removed':   return "❌ Бронь снята · $who, " . hm($x['staff_at']);
        case 'cancelled': return '❌ Бронь отменена гостем';
        default:          return STATE_RU[$s] ?? $s;
    }
}

// ══════════ КАРТОЧКА БРОНИ ══════════

/**
 * Карточка брони для персонала: [текст, кнопки].
 * $kind: 'b' — новая бронь, 'n' — напоминание «гость не отмечен».
 * $mode: '' — обычный вид, 'can' / 'nos' — просьба подтвердить снятие / неявку.
 * Кнопки показываются, пока в брони есть гости, которых ещё ждут.
 */
function staff_card(int $id, string $kind = 'b', string $mode = ''): array {
    $r = booking_get($id);
    if (!$r) return ["Бронь #$id не найдена.", []];
    $g = booking_group($r);
    $n = count($g);
    $first = (int)$g[0]['id'];
    $u = get_user((int)$r['user_id']);
    $site = (int)$r['user_id'] < 0;
    $phone = $u && $u['phone'] ? $u['phone'] : '—';
    $start = new DateTime($r['start']);
    $end = (clone $start)->modify('+' . $r['hours'] . ' hour');
    $price = (int)$r['price'];

    if ($kind === 'n') {
        $title = "⏰ <b>Гость не отмечен · бронь #$first</b>\n"
            . '<i>Прошло ' . NOSHOW_PROMPT_MIN . ' мин от начала. Пришёл — отметьте. Не пришёл — освободите место.</i>';
    } elseif ($n > 1) {
        $title = "🆕 <b>Бронь на компанию: $n " . seats_word($n) . '</b> · ' . ($site ? '🌐 с сайта' : '🤖 из бота');
    } else {
        $title = "🆕 <b>Новая бронь #$first</b> · " . ($site ? '🌐 с сайта' : '🤖 из бота');
    }
    $lines = [
        $title, DIV,
        '👤 ' . esc(display_name($u)) . ' · ' . esc($phone),
        '🕹 ' . ZONES[$r['zone']]['name'] . ($n > 1 ? " · 👥 $n " . seats_word($n) : ''),
        '🎟 ' . TARIFFS[$r['tariff']][0],
        '📅 ' . fmt_dt($start) . ' – ' . $end->format('H:00'),
        $n > 1 ? "💰 $n × $price ₽ = " . ($n * $price) . " ₽\n🔖 #" . implode(', #', array_column($g, 'id')) : "💰 $price ₽",
        DIV,
        staff_status_line($g),
    ];
    $states = array_map('booking_state', $g);
    if ($site && array_intersect($states, ['removed', 'noshow'])) {
        $lines[] = '📞 Клиент с сайта, Telegram у него нет — позвоните: ' . esc($phone);
    }

    $waiting = in_array('wait', $states, true);
    $kb = [];
    if ($waiting && $mode === 'can') {
        $lines[] = "\n❓ <b>Снять бронь?</b> Место освободится" . ($site ? ', клиенту с сайта нужно будет позвонить.' : ', гостю придёт уведомление.');
        $kb = [[btn('✅ Да, снять', "st:canok:$first:$kind")], [btn('⬅️ Назад', "st:back:$first:$kind")]];
    } elseif ($waiting && $mode === 'nos') {
        $lines[] = "\n❓ <b>" . ($n > 1 ? 'Гости не пришли' : 'Гость не пришёл') . '?</b> Место освободится'
            . ($site ? ', клиенту с сайта стоит позвонить.' : ', гостю придёт уведомление.');
        $kb = [[btn('✅ Да, ' . ($n > 1 ? 'не пришли' : 'не пришёл'), "st:nosok:$first:$kind")], [btn('⬅️ Назад', "st:back:$first:$kind")]];
    } elseif ($waiting) {
        $kb = [
            [btn($n > 1 ? '✅ Пришли' : '✅ Пришёл', "st:arr:$first:$kind"),
             btn($n > 1 ? '🚫 Не пришли' : '🚫 Не пришёл', "st:nos:$first:$kind")],
            [btn($n > 1 ? '❌ Снять всю бронь' : '❌ Снять бронь', "st:can:$first:$kind")],
        ];
    }
    return [implode("\n", $lines), $kb];
}

/** Отправить карточку брони персоналу (в рабочий чат или админам в личку). */
function notify_booking(int $id, string $kind = 'b'): void {
    [$t, $k] = staff_card($id, $kind);
    notify_staff($t, $k ?: null);
}

// ══════════ ДЕЙСТВИЯ ПЕРСОНАЛА ══════════

/** Сменить статус активным броням. Возвращает, сколько броней реально изменилось. */
function staff_set_status(array $rows, string $status, string $who): int {
    $st = db()->prepare("UPDATE bookings SET status=?, staff=?, staff_at=? WHERE id=? AND status='active'");
    $n = 0;
    foreach ($rows as $x) { $st->execute([$status, $who, date('c'), $x['id']]); $n += $st->rowCount(); }
    return $n;
}

/** Сообщить гостю из бота, что бронь сняли или отметили неявку. Клиентам сайта писать некуда. */
function staff_tell_client(array $r, string $status): void {
    $uid = (int)$r['user_id'];
    if ($uid <= 0) return;
    $id = (int)$r['id'];
    if ($status === 'noshow') {
        send($uid, "😔 Бронь <b>#$id</b> снята: прошло больше " . BOOKING_HOLD_MIN . " минут от начала, а мы тебя не дождались.\n"
            . 'Если ты уже в пути — позвони: ☎️ ' . CLUB_PHONE . ' — постараемся найти место.');
    } else {
        send($uid, "😔 Бронь <b>#$id</b> отменена администратором.\nВопросы: ☎️ " . CLUB_PHONE);
    }
}

/** Нажатие кнопки под карточкой брони: st:{arr|nos|nosok|can|canok|back}:{id}:{b|n} */
function staff_action(array $cb): void {
    $cbId = $cb['id'];
    $chat = (int)$cb['message']['chat']['id'];
    $msgId = (int)$cb['message']['message_id'];
    if (!preg_match('#^st:(arr|nos|nosok|can|canok|back):(\d+):([bn])$#', $cb['data'] ?? '', $m)) { answer_cb($cbId); return; }
    [, $act, $id, $kind] = $m;
    $id = (int)$id;
    $r = booking_get($id);
    if (!$r) { answer_cb($cbId, 'Бронь не найдена', true); return; }
    $who = staff_name($cb['from'] ?? []);
    $g = booking_group($r);
    $waiting = array_values(array_filter($g, fn($x) => booking_state($x) === 'wait'));
    $redraw = function (string $mode = '') use ($chat, $msgId, $id, $kind) {
        [$t, $k] = staff_card($id, $kind, $mode);
        edit($chat, $msgId, $t, $k);
    };
    if ($act === 'back') { $redraw(); answer_cb($cbId); return; }
    if (!$waiting) {
        $redraw();
        answer_cb($cbId, 'С этой бронью уже всё решено: ' . strip_tags(staff_status_line($g)), true);
        return;
    }
    $start = new DateTime($r['start']);
    $early = new DateTime() < $start;
    $site = (int)$r['user_id'] < 0;
    $u = get_user((int)$r['user_id']);
    $phone = $u && $u['phone'] ? $u['phone'] : '—';

    switch ($act) {
        case 'can':
            $redraw('can'); answer_cb($cbId); return;
        case 'nos':
        case 'nosok':
            if ($early) {
                answer_cb($cbId, 'Бронь начнётся в ' . $start->format('H:i') . ' — «не пришёл» можно отметить после начала. '
                    . 'Если гость отказался заранее, нажмите «Снять бронь».', true);
                return;
            }
            if ($act === 'nos') { $redraw('nos'); answer_cb($cbId); return; }
            $done = staff_set_status($waiting, 'noshow', $who);
            break;
        case 'canok':
            $done = staff_set_status($waiting, 'cancelled', $who);
            break;
        case 'arr':
            $st = db()->prepare("UPDATE bookings SET arrived_at=?, staff=?, staff_at=? WHERE id=? AND status='active' AND arrived_at IS NULL");
            foreach ($waiting as $x) $st->execute([date('c'), $who, date('c'), $x['id']]);
            $redraw();
            answer_cb($cbId, '✅ Отмечено: ' . (count($waiting) > 1 ? 'гости пришли' : 'гость пришёл'));
            return;
        default:
            answer_cb($cbId); return;
    }
    $redraw();
    if ($done > 0) staff_tell_client($r, $act === 'nosok' ? 'noshow' : 'cancelled');
    answer_cb($cbId, $site ? "Готово, место свободно. Клиент с сайта — позвоните ему: $phone"
                           : 'Готово, место свободно. Гостю отправлено уведомление в Telegram.', $site);
}

// ══════════ СВОДКИ ДЛЯ ПЕРСОНАЛА ══════════

/** Брони, сгруппированные по компаниям: [[строки группы], ...] в порядке начала. */
function group_rows(array $rows): array {
    $groups = [];
    foreach ($rows as $r) $groups[$r['user_id'] . '|' . $r['zone'] . '|' . $r['start']][] = $r;
    uasort($groups, fn($a, $b) => strcmp($a[0]['start'], $b[0]['start']) ?: $a[0]['id'] <=> $b[0]['id']);
    return array_values($groups);
}

function staff_group_line(array $g, bool $withTime = true): string {
    $r = $g[0];
    $n = count($g);
    $u = get_user((int)$r['user_id']);
    $states = array_unique(array_map('booking_state', $g));
    $st = count($states) === 1 ? (STATE_RU[$states[0]] ?? $states[0]) : strip_tags(staff_status_line($g));
    return ($withTime ? '<b>' . fmt_range($r) . '</b> · ' : '') . ZONES[$r['zone']]['name'] . ($n > 1 ? " ×$n" : '') . "\n"
        . '     👤 ' . esc(display_name($u)) . ' · ' . esc($u && $u['phone'] ? $u['phone'] : '—')
        . ((int)$r['user_id'] < 0 ? ' · 🌐' : '') . " · $st";
}

/** Брони на день (по времени начала): для /today и утренней сводки. */
function staff_today_text(?DateTime $day = null, bool $digest = false): string {
    $day = $day ?: new DateTime();
    $st = db()->prepare('SELECT * FROM bookings WHERE start LIKE ? ORDER BY start, id');
    $st->execute([$day->format('Y-m-d') . '%']);
    $groups = group_rows($st->fetchAll(PDO::FETCH_ASSOC));
    $head = ($digest ? '☀️ <b>Доброе утро! Брони на сегодня</b>' : '📅 <b>Брони на сегодня</b>')
        . ' · ' . $day->format('d.m') . ' (' . WDAYS[(int)$day->format('N') - 1] . ')';
    if (!$groups) return "$head\n" . DIV . "\nБроней пока нет.";
    $live = array_filter($groups, fn($g) => $g[0]['status'] === 'active');
    $seats = array_sum(array_map('count', $live));
    $lines = [$head, 'Активных броней: <b>' . count($live) . '</b> · мест: <b>' . $seats . '</b>', DIV];
    foreach (array_slice($groups, 0, STAFF_MAX_LIST) as $g) $lines[] = staff_group_line($g) . "\n";
    if (count($groups) > STAFF_MAX_LIST) $lines[] = '…и ещё ' . (count($groups) - STAFF_MAX_LIST) . ' — полный список в /admin у бота.';
    $lines[] = '<i>🌐 — бронь с сайта: Telegram у клиента нет, связь только по телефону.</i>';
    return implode("\n", $lines);
}

/** Кто сейчас в клубе и кого ждём в ближайший час. */
function staff_now_text(?DateTime $now = null): string {
    $now = $now ?: new DateTime();
    $soon = (clone $now)->modify('+1 hour');
    $cur = []; $next = [];
    foreach (group_rows(active_bookings()) as $g) {
        $s = new DateTime($g[0]['start']);
        $e = (clone $s)->modify('+' . $g[0]['hours'] . ' hour');
        if ($s <= $now && $now < $e) $cur[$g[0]['zone']][] = $g;
        elseif ($s > $now && $s <= $soon) $next[] = $g;
    }
    $lines = ['🟢 <b>Сейчас в клубе</b> · ' . $now->format('d.m H:i'), DIV];
    foreach (ZONES as $z => $info) {
        $gs = $cur[$z] ?? [];
        $busy = array_sum(array_map('count', $gs));
        $lines[] = '<b>' . $info['name'] . "</b> — занято $busy из {$info['cap']}";
        foreach ($gs as $g) {
            $e = (new DateTime($g[0]['start']))->modify('+' . $g[0]['hours'] . ' hour');
            $lines[] = '     ' . str_replace("\n     ", ' · ', staff_group_line($g, false)) . ' · до ' . $e->format('H:00');
        }
    }
    $lines[] = DIV;
    $lines[] = '<b>В ближайший час:</b>';
    if (!$next) $lines[] = 'никого не ждём';
    foreach ($next as $g) $lines[] = staff_group_line($g);
    $lines[] = '<i>Учтены только онлайн-брони (сайт и бот). Гостей без брони бот не видит.</i>';
    return implode("\n", $lines);
}

function staff_help_text(): string {
    return "🤖 <b>Бот в рабочем чате</b>\n" . DIV . "\n"
        . "Сюда приходят новые брони с сайта и из бота, брони на компанию и отмены.\n\n"
        . "<b>Кнопки под бронью</b>\n"
        . "✅ <b>Пришёл</b> — гость на месте.\n"
        . "🚫 <b>Не пришёл</b> — после начала брони: место освобождается, гостю из бота придёт сообщение.\n"
        . "❌ <b>Снять бронь</b> — если гость отказался. Оба действия просят подтверждения.\n"
        . "Под бронью видно, кто и когда её отметил.\n\n"
        . '⏰ Если через ' . NOSHOW_PROMPT_MIN . " мин после начала гость не отмечен, бот напомнит.\n"
        . (DIGEST_HOUR >= 0 ? '☀️ В ' . DIGEST_HOUR . ":00 — сводка броней на день.\n" : '')
        . "\n<b>Команды</b>\n"
        . "/today — брони на сегодня\n"
        . "/now — кто сейчас в клубе и кого ждём в ближайший час\n"
        . "/help — эта справка\n\n"
        . "🌐 Клиенту с сайта бот написать не может — звоните по номеру из брони.\n"
        . 'Админ-панель (сетка, CSV, статистика) — в личке с ботом: /admin';
}

/** Ответ на команду в рабочем чате (/today, /now, /help) или null, если команда не наша. */
function staff_command(string $cmd): ?string {
    switch ($cmd) {
        case 'today': return staff_today_text();
        case 'now':   return staff_now_text();
        case 'help':  return staff_help_text();
    }
    return null;
}

// ══════════ ФОНОВЫЕ ЗАДАЧИ (раз в минуту из poller.php) ══════════

function staff_tick(?DateTime $now = null): void {
    if (!work_chat_id()) return;   // без рабочего чата ничего не шлём, чтобы не засорять личку админам
    $now = $now ?: new DateTime();

    // 1) гость не отмечен через NOSHOW_PROMPT_MIN минут после начала
    if (NOSHOW_PROMPT_MIN > 0) {
        $rows = db()->query("SELECT * FROM bookings WHERE status='active' AND arrived_at IS NULL AND pinged=0 ORDER BY start, id")
            ->fetchAll(PDO::FETCH_ASSOC);
        $claim = db()->prepare('UPDATE bookings SET pinged=1 WHERE id=? AND pinged=0');
        foreach ($rows as $r) {
            $s = new DateTime($r['start']);
            if ($now < (clone $s)->modify('+' . NOSHOW_PROMPT_MIN . ' minutes')) continue;
            // забираем всю группу разом: так напоминание уйдёт один раз, даже если задачу запустят дважды
            $mine = 0;
            foreach (booking_group($r) as $x) { $claim->execute([$x['id']]); $mine += $claim->rowCount(); }
            if (!$mine) continue;
            if ($now >= (clone $s)->modify('+' . $r['hours'] . ' hour')) continue;   // бронь уже закончилась
            notify_booking((int)$r['id'], 'n');
        }
    }

    // 2) утренняя сводка — один раз в день, в течение трёх часов после DIGEST_HOUR
    $h = (int)$now->format('G');
    if (DIGEST_HOUR >= 0 && $h >= DIGEST_HOUR && $h < DIGEST_HOUR + 3) {
        $st = db()->prepare('INSERT OR IGNORE INTO meta (k, v) VALUES (?, ?)');
        $st->execute(['digest:' . $now->format('Y-m-d'), $now->format('c')]);
        if ($st->rowCount() === 1) notify_staff(staff_today_text($now, true));
    }
}

/** Подсказки команд: в рабочем чате — /today /now /help, админам в личке — ещё /admin. */
function staff_register_commands(): void {
    $staff = [
        ['command' => 'today', 'description' => 'Брони на сегодня'],
        ['command' => 'now',   'description' => 'Кто сейчас в клубе'],
        ['command' => 'help',  'description' => 'Что умеет бот в рабочем чате'],
    ];
    if ($wc = work_chat_id()) api('setMyCommands', ['commands' => $staff, 'scope' => ['type' => 'chat', 'chat_id' => $wc]]);
    $admin = [
        ['command' => 'admin', 'description' => 'Админ-панель'],
        ['command' => 'today', 'description' => 'Брони на сегодня'],
        ['command' => 'now',   'description' => 'Кто сейчас в клубе'],
        ['command' => 'book',  'description' => 'Забронировать место'],
        ['command' => 'my',    'description' => 'Мои брони'],
        ['command' => 'start', 'description' => 'Главное меню'],
    ];
    foreach (ADMIN_IDS as $a) api('setMyCommands', ['commands' => $admin, 'scope' => ['type' => 'chat', 'chat_id' => (int)$a]]);
}
