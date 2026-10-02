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

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/"

TARGET="${1:-}"
SSH_OPT=()

if [[ -z "$TARGET" ]]; then
    # без аргументов берём параметры из .deploy.env (в git он не попадает)
    if [[ -f "${SRC}.deploy.env" ]]; then
        # shellcheck disable=SC1090
        set -a; source "${SRC}.deploy.env"; set +a
        TARGET="${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}"
        if [[ -n "${SSH_KEY:-}" ]]; then
            SSH_OPT=(-e "ssh -i ${SSH_KEY/#\~/$HOME} -o BatchMode=yes")
        fi
        echo "▶ Параметры из .deploy.env"
    else
        echo "Использование: ./deploy.sh user@host:/путь/к/сайту [--dry-run]" >&2
        echo "  либо создай .deploy.env и запусти ./deploy.sh без аргументов" >&2
        exit 1
    fi
else
    shift || true
fi

# перед выкладкой прогоняем тесты — не хочется залить сломанное
if command -v php >/dev/null 2>&1; then
    echo "▶ Прогон тестов…"
    php "${SRC}tests/test_bot.php" >/dev/null
    echo "✓ Тесты пройдены"
else
    echo "⚠ PHP не найден локально — тесты пропущены"
fi

echo "▶ Выкладка в $TARGET"

# rsync не умеет создавать вложенные каталоги на приёмнике, а на чистом
# сервере /var/www ещё нет — создаём каталог назначения заранее.
RSYNC_PATH=()
if [[ "$TARGET" == *:* ]]; then
    REMOTE_DIR="${TARGET#*:}"
    RSYNC_PATH=(--rsync-path="mkdir -p '${REMOTE_DIR}' && rsync")
fi

# ${arr[@]+"${arr[@]}"} — пустой массив под set -u без ошибки и в bash 3.2 (macOS)
rsync -avz --human-readable --progress ${SSH_OPT[@]+"${SSH_OPT[@]}"} ${RSYNC_PATH[@]+"${RSYNC_PATH[@]}"} ${@+"$@"} \
    --exclude '.git/' \
    --exclude '.gitignore' \
    --exclude '.deploy.env' \
    --exclude '.claude/' \
    --exclude '.DS_Store' \
    --exclude 'tests/' \
    --exclude 'docs/' \
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
  5. Cron раз в сутки — сроки хранения персональных данных:
       15 4 * * * php /путь/к/сайту/privacy_cleanup.php
EOF
