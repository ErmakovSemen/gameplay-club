#!/usr/bin/env bash
#
# ⚡ GAME PLAY — первичная настройка VPS (Ubuntu/Debian).
#
# ПОРЯДОК ВАЖЕН: сначала залить файлы, потом запускать этот скрипт —
# он берёт конфиг из nginx/gameplay.conf.template, который приезжает с rsync.
#
#   на ноутбуке:  ./deploy.sh
#   на сервере:   cd /var/www/gameplay && bash server_setup.sh gameplaycc.ru
#
# Ставит nginx + PHP-FPM, настраивает сайт, выписывает HTTPS-сертификат
# и заводит cron для напоминаний.
#
# ВАЖНО: проект написан под Apache (.htaccess). Под nginx правила .htaccess
# не действуют, поэтому запреты на config.php, core.php и базу перенесены
# в конфиг nginx ниже. Без них токен бота читался бы прямо по URL.
#
set -euo pipefail

DOMAIN="${1:-}"
if [[ -z "$DOMAIN" ]]; then
    echo "Использование: bash server_setup.sh ВАШ-ДОМЕН" >&2
    exit 1
fi
ROOT="/var/www/gameplay"

echo "▶ Ставлю nginx, PHP и утилиты…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx php-fpm php-sqlite3 php-curl php-mbstring \
                       certbot python3-certbot-nginx rsync cron

# версия PHP и сокет FPM определяются автоматически
PHPVER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
SOCK="/run/php/php${PHPVER}-fpm.sock"
echo "  PHP $PHPVER, сокет $SOCK"

echo "▶ Готовлю каталог сайта $ROOT…"
mkdir -p "$ROOT"
# SQLite пишет рядом с базой файлы -wal и -shm, поэтому на запись нужен весь каталог
chown -R www-data:www-data "$ROOT"
chmod 755 "$ROOT"

echo "▶ Пишу конфиг nginx из шаблона nginx/gameplay.conf.template…"
TEMPLATE="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/nginx/gameplay.conf.template"
if [[ ! -f "$TEMPLATE" ]]; then
    echo "Не найден $TEMPLATE — залей каталог nginx/ вместе с остальными файлами." >&2
    exit 1
fi

sed -e "s|__LISTEN__|80|g" \
    -e "s|__SERVER_NAME__|${DOMAIN} www.${DOMAIN}|g" \
    -e "s|__ROOT__|${ROOT}|g" \
    -e "s|__SOCK__|${SOCK}|g" \
    -e "s|__LOGDIR__|/var/log/nginx|g" \
    "$TEMPLATE" > /etc/nginx/sites-available/gameplay

ln -sf /etc/nginx/sites-available/gameplay /etc/nginx/sites-enabled/gameplay
rm -f /etc/nginx/sites-enabled/default

echo "▶ Проверяю конфиг nginx…"
nginx -t
systemctl reload nginx

echo "▶ Выписываю HTTPS-сертификат (Telegram принимает вебхук только по https)…"
if certbot --nginx -d "$DOMAIN" -d "www.$DOMAIN" --non-interactive --agree-tos \
           --register-unsafely-without-email --redirect; then
    echo "  ✓ сертификат получен"
else
    echo "  ⚠ certbot не смог выписать сертификат."
    echo "    Обычно причина одна: DNS домена ещё не указывает на этот сервер."
    echo "    Проверь A-запись и повтори:  certbot --nginx -d $DOMAIN -d www.$DOMAIN"
fi

echo "▶ Завожу cron для напоминаний (каждые 5 минут)…"
CRON_LINE="*/5 * * * * php ${ROOT}/reminder.php >/dev/null 2>&1"
( crontab -l 2>/dev/null | grep -v 'reminder.php' ; echo "$CRON_LINE" ) | crontab -
systemctl enable --now cron

cat <<EOF

════════════════════════════════════════════════
✓ Сервер настроен.

Дальше:
  1. Создай config.php и впиши секреты:
       cd ${ROOT}
       cp config.example.php config.php
       nano config.php
       chown www-data:www-data config.php && chmod 640 config.php

  2. Убедись, что A-запись ${DOMAIN} указывает на этот сервер,
     иначе certbot не выпишет сертификат:
       dig +short A ${DOMAIN}

  3. Привяжи вебхук — один раз, в браузере:
       https://${DOMAIN}/set_webhook.php?key=ТВОЙ_CRON_KEY
     После успешного ответа удали файл:
       rm ${ROOT}/set_webhook.php

  4. Проверь, что секреты закрыты (оба должны отдать 403):
       curl -I https://${DOMAIN}/config.php
       curl -I https://${DOMAIN}/core.php
════════════════════════════════════════════════
EOF
