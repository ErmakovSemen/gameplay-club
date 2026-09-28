<?php
/**
 * ⚡ GAME PLAY — config.example.php
 *
 * Скопируй этот файл в config.php и заполни своими значениями:
 *     cp config.example.php config.php
 *
 * config.php НЕ попадает в git (см. .gitignore) и закрыт от скачивания
 * через .htaccess. Реальные секреты держи только в нём.
 */

// Токен бота от @BotFather
const BOT_TOKEN = 'ВСТАВЬ_ТОКЕН_ОТ_BOTFATHER';

// Telegram user id админов (узнать свой: @userinfobot)
const ADMIN_IDS = [506619424];

// Секрет вебхука. Telegram разрешает ТОЛЬКО символы A-Z a-z 0-9 _ - (1–256 шт.)
// Сгенерировать: php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
const WH_SECRET = 'СГЕНЕРИРУЙ_СЛУЧАЙНУЮ_СТРОКУ_ЛАТИНИЦЕЙ';

// Ключ для запуска reminder.php и set_webhook.php по URL из планировщика.
const CRON_KEY = 'СГЕНЕРИРУЙ_ДРУГУЮ_СЛУЧАЙНУЮ_СТРОКУ';
