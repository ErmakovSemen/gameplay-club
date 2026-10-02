<?php
/**
 * ⚡ GAME PLAY — privacy_cleanup.php
 * Исполняет сроки хранения из политики обработки персональных данных (/privacy/).
 *
 *   php privacy_cleanup.php                       обезличить всех, чья последняя бронь
 *                                                 закончилась больше PD_RETENTION_YEARS лет назад
 *   php privacy_cleanup.php --phone="+7 906 ..."  удалить данные человека по его запросу
 *                                                 (отзыв согласия, требование уничтожить данные)
 *   php privacy_cleanup.php --tg=123456789        то же по Telegram ID
 *   добавь  --dry-run, чтобы только посмотреть
 *
 * Брони не удаляются (нужны для учёта), но после обезличивания в них не остаётся
 * ни имени, ни телефона. Запуск — только из консоли сервера; из браузера закрыт.
 * Cron раз в сутки:  15 4 * * *  php /var/www/gameplay/privacy_cleanup.php
 */

if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/core.php';

/** Обезличить одного пользователя: имя, телефон, черновик, согласия. */
function pd_anonymize(int $uid): void {
    db()->prepare("UPDATE users SET name='удалено', real_name=NULL, phone=NULL, state=NULL, draft=NULL WHERE id=?")
        ->execute([$uid]);
    db()->prepare('DELETE FROM consents WHERE user_id=?')->execute([$uid]);
    // будущие брони человека, отозвавшего согласие, обслужить уже не сможем — снимаем
    $now = date('c');
    db()->prepare("UPDATE bookings SET status='cancelled' WHERE user_id=? AND status='active' AND start > ?")
        ->execute([$uid, $now]);
}

/** id всех, у кого истёк срок хранения. Пользователи без броней — по дате создания. */
function pd_expired_users(?DateTime $now = null): array {
    $now = $now ?: new DateTime();
    $limit = (clone $now)->modify('-' . PD_RETENTION_YEARS . ' year');
    $out = [];
    foreach (db()->query("SELECT id, created FROM users WHERE COALESCE(phone,'') <> '' OR COALESCE(real_name,'') <> '' OR name <> 'удалено'")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $st = db()->prepare('SELECT start, hours FROM bookings WHERE user_id=?');
        $st->execute([$u['id']]);
        $last = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $end = (new DateTime($b['start']))->modify('+' . $b['hours'] . ' hour');
            if (!$last || $end > $last) $last = $end;
        }
        $ref = $last ?: ($u['created'] ? new DateTime($u['created']) : null);
        if ($ref && $ref < $limit) $out[] = (int)$u['id'];
    }
    return $out;
}

/** Найти пользователей по телефону (сравниваем только цифры, 8 → 7). */
function pd_find_by_phone(string $raw): array {
    $norm = function (string $p): string {
        $d = preg_replace('/\D+/', '', $p);
        if (strlen($d) === 10) $d = '7' . $d;
        if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
        return $d;
    };
    $want = $norm($raw);
    if (strlen($want) < 10) return [];
    $ids = [];
    foreach (db()->query("SELECT id, phone FROM users WHERE phone IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if ($norm((string)$u['phone']) === $want) $ids[] = (int)$u['id'];
    }
    return $ids;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $opt = getopt('', ['phone:', 'tg:', 'dry-run']);
    $dry = isset($opt['dry-run']);
    if (isset($opt['phone']))   { $ids = pd_find_by_phone($opt['phone']); $why = 'по запросу (телефон)'; }
    elseif (isset($opt['tg']))  { $ids = get_user((int)$opt['tg']) ? [(int)$opt['tg']] : []; $why = 'по запросу (Telegram ID)'; }
    else                        { $ids = pd_expired_users(); $why = 'истёк срок хранения'; }
    foreach ($ids as $id) { if (!$dry) pd_anonymize($id); }
    echo ($dry ? '[dry-run] ' : '') . 'Обезличено: ' . count($ids) . " ($why)\n";
}
