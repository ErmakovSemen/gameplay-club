<?php
/**
 * ⚡ GAME PLAY — HTTP-API страницы /booking.
 *
 *   GET  api.php?action=config                         справочник зон/тарифов/дат
 *   GET  api.php?action=slots&zone=&tariff=&date=      свободные окна на дату
 *   POST api.php  (JSON) {action:"book", zone, tariff, date, hour, name, phone}
 *
 * Логика — в ../site_booking.php, база и цены — те же, что у бота.
 */

require_once __DIR__ . '/../site_booking.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('[booking/api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['ok' => false, 'error' => 'Сервис бронирования временно недоступен. Позвоните: ' . CLUB_PHONE], 500);
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'config') out(site_config());
    if ($action === 'slots') {
        out(site_slots((string)($_GET['zone'] ?? ''), (string)($_GET['tariff'] ?? ''), (string)($_GET['date'] ?? '')));
    }
    out(['ok' => false, 'error' => 'Неизвестный запрос'], 404);
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $in = json_decode($raw ?: '', true);
    if (!is_array($in)) $in = $_POST;
    if (($in['action'] ?? '') !== 'book') out(['ok' => false, 'error' => 'Неизвестный запрос'], 404);
    // ловушка для ботов: настоящие пользователи это поле не видят и не заполняют
    if (!empty($in['website'])) out(['ok' => true, 'id' => 0]);
    $res = site_book($in);
    out($res, $res['ok'] ? 200 : 409);
}

out(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
