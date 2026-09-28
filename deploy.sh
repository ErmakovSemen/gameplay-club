#!/usr/bin/env bash
#
# ⚡ GAME PLAY — выкладка на сервер.
#
#   ./deploy.sh user@host:/путь/к/корню/сайта
#   ./deploy.sh user@host:/путь/к/корню/сайта --dry-run   # только показать, что изменится
#
# Что делает:
#   · заливает сайт и файлы бота по rsync поверх SSH
#   · НЕ трогает config.php на сервере (там боевые секреты)
#   · НЕ трогает базу gameplay.db и её -wal/-shm файлы
#   · не заливает тесты, git и служебные файлы
#
set -euo pipefail

TARGET="${1:-}"
if [[ -z "$TARGET" ]]; then
    echo "Использование: ./deploy.sh user@host:/путь/к/сайту [--dry-run]" >&2
    exit 1
fi
shift || true

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/"

# перед выкладкой прогоняем тесты — не хочется залить сломанное
if command -v php >/dev/null 2>&1; then
    echo "▶ Прогон тестов…"
    php "${SRC}tests/test_bot.php" >/dev/null
    echo "✓ Тесты пройдены"
else
    echo "⚠ PHP не найден локально — тесты пропущены"
fi

echo "▶ Выкладка в $TARGET"
rsync -avz --human-readable --progress "$@" \
    --exclude '.git/' \
    --exclude '.gitignore' \
    --exclude '.claude/' \
    --exclude '.DS_Store' \
    --exclude 'tests/' \
    --exclude 'deploy.sh' \
    --exclude 'README.md' \
    --exclude 'config.php' \
    --exclude '*.db' \
    --exclude '*.db-wal' \
    --exclude '*.db-shm' \
    "$SRC" "$TARGET"

cat <<'EOF'

✓ Файлы залиты.

Что проверить на сервере, если это первая выкладка:
  1. Создать config.php из config.example.php и вписать секреты:
       cp config.example.php config.php && nano config.php
  2. Права на запись для SQLite (база создастся сама):
       chmod 755 .   # каталог сайта должен быть доступен на запись веб-серверу
  3. Привязать вебхук, один раз в браузере:
       https://ВАШ-ДОМЕН/set_webhook.php?key=CRON_KEY
  4. Cron каждые 5 минут:
       */5 * * * * php /путь/к/сайту/reminder.php
EOF
