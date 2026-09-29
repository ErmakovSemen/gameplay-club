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
SSL_DIR="/etc/ssl/gameplay"

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
    -e "s|__LISTEN6__|listen [::]:80;|g" \
    -e "s|__LISTEN_SSL__|443 ssl|g" \
    -e "s|__LISTEN_SSL6__|listen [::]:443 ssl;|g" \
    -e "s|__CERT__|${SSL_DIR}/fullchain.pem|g" \
    -e "s|__KEY__|${SSL_DIR}/key.pem|g" \
    -e "s|__SERVER_NAME__|${DOMAIN} www.${DOMAIN}|g" \
    -e "s|__ROOT__|${ROOT}|g" \
    -e "s|__SOCK__|${SOCK}|g" \
    -e "s|__LOGDIR__|/var/log/nginx|g" \
    "$TEMPLATE" > /etc/nginx/sites-available/gameplay

ln -sf /etc/nginx/sites-available/gameplay /etc/nginx/sites-enabled/gameplay
rm -f /etc/nginx/sites-enabled/default

# Конфиг ссылается на файлы сертификата, и без них nginx не стартует.
# До выпуска настоящего кладём самоподписанную заглушку.
mkdir -p "$SSL_DIR"
if [[ ! -s "$SSL_DIR/fullchain.pem" ]]; then
    echo "▶ Временный самоподписанный сертификат (чтобы nginx поднялся)…"
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
        -keyout "$SSL_DIR/key.pem" -out "$SSL_DIR/fullchain.pem" \
        -subj "/CN=$DOMAIN" >/dev/null 2>&1
    chmod 600 "$SSL_DIR/key.pem"
fi

echo "▶ Проверяю конфиг nginx…"
nginx -t
systemctl reload nginx

# ── Настоящий сертификат ──────────────────────────────────────────
# ВАЖНО: на этом сервере HTTP-проверка Let's Encrypt не проходит.
# Их обязательная проверка «с нескольких точек мира» упирается
# в таймаут: часть их проверяющих узлов до сервера не достаёт
# (сеть провайдера). Поэтому выпуск идёт через DNS-проверку —
# ей вообще не нужен доступ к серверу извне.
echo "▶ Ставлю acme.sh…"
if [[ ! -d ~/.acme.sh ]]; then
    curl -s https://get.acme.sh | sh -s email=admin@"$DOMAIN" >/dev/null 2>&1
fi

if [[ -s ~/.acme.sh/${DOMAIN}_ecc/fullchain.cer ]]; then
    echo "▶ Подключаю выпущенный сертификат к nginx…"
    ~/.acme.sh/acme.sh --install-cert -d "$DOMAIN" --ecc \
        --key-file       "$SSL_DIR/key.pem" \
        --fullchain-file "$SSL_DIR/fullchain.pem" \
        --reloadcmd      "systemctl reload nginx"
    echo "  ✓ сертификат подключён, продление будет само перезагружать nginx"
else
    cat <<CERT

  ⚠ Настоящий сертификат ещё не выпущен — сайт пока на самоподписанном.
    Выпустить (проверка через DNS, нужен доступ к записям домена):

      ~/.acme.sh/acme.sh --issue --dns -d $DOMAIN -d www.$DOMAIN \\
        --server letsencrypt --keylength ec-256 \\
        --yes-I-know-dns-manual-mode-enough-go-ahead-please

    Команда напечатает две TXT-записи. Добавь их в DNS домена,
    дождись появления (dig TXT _acme-challenge.$DOMAIN) и заверши:

      ~/.acme.sh/acme.sh --renew -d $DOMAIN --ecc \\
        --yes-I-know-dns-manual-mode-enough-go-ahead-please

    Затем снова запусти этот скрипт — он подключит сертификат к nginx.
CERT
fi

echo "▶ Завожу cron для напоминаний (каждые 5 минут)…"
CRON_LINE="*/5 * * * * php ${ROOT}/reminder.php >/dev/null 2>&1"
# «|| true» обязательны: при пустом crontab и crontab -l, и grep возвращают 1,
# а под set -e -o pipefail это роняло весь скрипт на этом шаге.
( { crontab -l 2>/dev/null || true; } | grep -v 'reminder.php' || true; echo "$CRON_LINE" ) | crontab -
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
