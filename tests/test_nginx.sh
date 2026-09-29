#!/usr/bin/env bash
#
# ⚡ GAME PLAY — проверка боевого конфига nginx на реальном стеке.
#
#   bash tests/test_nginx.sh
#
# Поднимает локально nginx + PHP-FPM с тем же nginx/gameplay.conf.template,
# который server_setup.sh ставит на сервер, и проверяет, что:
#   · сайт отдаётся
#   · PHP исполняется
#   · config.php, core.php, база и tests/ закрыты (403)
#   · вебхук пускает только POST с верным секретом
#
# Смысл: убедиться, что после выкладки токен бота не окажется
# доступен по прямой ссылке. Под nginx .htaccess не работает.
#
set -euo pipefail

PORT="${PORT:-8911}"
PORT_SSL="${PORT_SSL:-8912}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d)"
ROOT="$WORK/www"
NGINX_PREFIX="$(nginx -V 2>&1 | sed -n 's|.*--prefix=\([^ ]*\).*|\1|p')"
NGINX_ETC="${NGINX_PREFIX:-/opt/homebrew}/etc/nginx"
[[ -d "$NGINX_ETC" ]] || NGINX_ETC=/opt/homebrew/etc/nginx

pass=0; fail=0
check() {  # check <описание> <ожидаемый код> <url> [curl-args...]
    local what="$1" want="$2" url="$3"; shift 3
    local got
    got="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "$@" "$url" || echo 000)"
    if [[ "$got" == "$want" ]]; then
        printf '  \033[32m✓\033[0m %-52s %s\n' "$what" "$got"; pass=$((pass+1))
    else
        printf '  \033[31m✗\033[0m %-52s ожидался %s, получен %s\n' "$what" "$want" "$got"; fail=$((fail+1))
    fi
}

cleanup() {
    [[ -f "$WORK/nginx.pid" ]] && kill "$(cat "$WORK/nginx.pid")" 2>/dev/null || true
    [[ -f "$WORK/php-fpm.pid" ]] && kill "$(cat "$WORK/php-fpm.pid")" 2>/dev/null || true
    sleep 0.3
    rm -rf "$WORK"
}
trap cleanup EXIT

echo "▶ Раскладываю сайт как при выкладке…"
mkdir -p "$ROOT" "$WORK/tmp"
rsync -a --exclude '.git/' --exclude '.gitignore' --exclude '.deploy.env' \
      --exclude 'deploy.sh' --exclude 'README.md' \
      "$SRC/" "$ROOT/"
# боевые файлы, которых нет в git, но которые будут на сервере
cat > "$ROOT/config.php" <<'EOF'
<?php
const BOT_TOKEN = '1234567890:AAFAKEfaketokenfortestingonly1234567';
const ADMIN_IDS = [1];
const WH_SECRET = 'testsecret123';
const CRON_KEY  = 'testcron123';
EOF
echo "фейковая база" > "$ROOT/gameplay.db"
# файл проверки Let's Encrypt — должен отдаваться, несмотря на запрет скрытых путей
mkdir -p "$ROOT/.well-known/acme-challenge"
echo "acme-token-ok" > "$ROOT/.well-known/acme-challenge/probe"

echo "▶ Поднимаю PHP-FPM…"
cat > "$WORK/php-fpm.conf" <<EOF
[global]
error_log = $WORK/php-fpm.log
pid = $WORK/php-fpm.pid
daemonize = yes
[www]
listen = $WORK/php-fpm.sock
pm = static
pm.max_children = 2
EOF
php-fpm --fpm-config "$WORK/php-fpm.conf" 2>/dev/null
sleep 1
[[ -S "$WORK/php-fpm.sock" ]] || { echo "PHP-FPM не поднялся"; cat "$WORK/php-fpm.log"; exit 1; }

echo "▶ Делаю самоподписанный сертификат для теста…"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 \
  -keyout "$WORK/test.key" -out "$WORK/test.crt" \
  -subj "/CN=localhost" >/dev/null 2>&1

echo "▶ Собираю конфиг nginx из боевого шаблона…"
cp "$NGINX_ETC/fastcgi_params" "$WORK/fastcgi_params"
sed -e "s|__LISTEN__|127.0.0.1:$PORT|g" \
    -e "s|__LISTEN6__||g" \
    -e "s|__LISTEN_SSL__|127.0.0.1:$PORT_SSL ssl|g" \
    -e "s|__LISTEN_SSL6__||g" \
    -e "s|__CERT__|$WORK/test.crt|g" \
    -e "s|__KEY__|$WORK/test.key|g" \
    -e "s|__SERVER_NAME__|localhost|g" \
    -e "s|__ROOT__|$ROOT|g" \
    -e "s|__SOCK__|$WORK/php-fpm.sock|g" \
    -e "s|__LOGDIR__|$WORK|g" \
    "$SRC/nginx/gameplay.conf.template" > "$WORK/gameplay.conf"

cat > "$WORK/nginx.conf" <<EOF
worker_processes 1;
error_log $WORK/error.log warn;
pid $WORK/nginx.pid;
events { worker_connections 64; }
http {
    include $NGINX_ETC/mime.types;
    default_type application/octet-stream;
    client_body_temp_path $WORK/tmp;
    fastcgi_temp_path $WORK/tmp-fcgi;
    proxy_temp_path $WORK/tmp-proxy;
    uwsgi_temp_path $WORK/tmp-uwsgi;
    scgi_temp_path $WORK/tmp-scgi;
    include $WORK/gameplay.conf;
}
EOF

echo "▶ Проверяю синтаксис конфига…"
nginx -p "$WORK" -c "$WORK/nginx.conf" -t
nginx -p "$WORK" -c "$WORK/nginx.conf"
sleep 1

BASE="http://127.0.0.1:$PORT"
BASES="https://127.0.0.1:$PORT_SSL"

echo
echo "── HTTP: только проверка сертификата, остальное на https ────"
check "GET /.well-known/acme-challenge/probe — отдаётся" 200 "$BASE/.well-known/acme-challenge/probe"
check "GET /        — редирект на https"       301 "$BASE/"
check "GET /prices  — редирект на https"       301 "$BASE/prices"
loc="$(curl -sk -o /dev/null -w '%{redirect_url}' --max-time 10 "$BASE/" || true)"
if [[ "$loc" == https://* ]]; then
    printf '  \033[32m✓\033[0m %-52s %s\n' "адрес редиректа" "$loc"; pass=$((pass+1))
else
    printf '  \033[31m✗\033[0m %-52s %s\n' "адрес редиректа" "$loc"; fail=$((fail+1))
fi

echo
echo "── HTTPS: сайт ─────────────────────────────────────────────"
check "GET /  — лендинг отдаётся"                 200 "$BASES/"
check "GET /index.html"                           200 "$BASES/index.html"
check "GET /test.php — PHP исполняется"           200 "$BASES/test.php"
check "GET /несуществующее — 404"                 404 "$BASES/nope"

echo
echo "── HTTPS: секреты закрыты ──────────────────────────────────"
check "GET /config.php"                           403 "$BASES/config.php"
check "GET /config.example.php"                   403 "$BASES/config.example.php"
check "GET /core.php"                             403 "$BASES/core.php"
check "GET /gameplay.db"                          403 "$BASES/gameplay.db"
check "GET /gameplay.db-wal"                      403 "$BASES/gameplay.db-wal"
check "GET /tests/test_bot.php"                   403 "$BASES/tests/test_bot.php"
check "GET /nginx/gameplay.conf.template"         403 "$BASES/nginx/gameplay.conf.template"
check "GET /server_setup.sh"                      403 "$BASES/server_setup.sh"
check "GET /.deploy.env"                          403 "$BASES/.deploy.env"
check "GET /.htaccess"                            403 "$BASES/.htaccess"
check "GET /cert_check.php"                       403 "$BASES/cert_check.php"
check "GET /.well-known/прочее — закрыто"         403 "$BASES/.well-known/other"

echo
echo "── HTTPS: вебхук ───────────────────────────────────────────"
check "GET /webhook.php — заглушка"               200 "$BASES/webhook.php"
check "POST без секрета"                          403 "$BASES/webhook.php" -X POST -d '{}'
check "POST с неверным секретом"                  403 "$BASES/webhook.php" \
      -X POST -H 'X-Telegram-Bot-Api-Secret-Token: wrong' -d '{}'
check "POST с верным секретом"                    200 "$BASES/webhook.php" \
      -X POST -H 'X-Telegram-Bot-Api-Secret-Token: testsecret123' \
      -H 'Content-Type: application/json' -d '{"update_id":1}'
check "GET /reminder.php без ключа"               403 "$BASES/reminder.php"
check "GET /set_webhook.php без ключа"            403 "$BASES/set_webhook.php"

echo
echo "── Токен не утекает ────────────────────────────────────────"
body="$(curl -sk --max-time 10 "$BASES/config.php" || true)"
if grep -q 'AAFAKE' <<<"$body"; then
    printf '  \033[31m✗\033[0m %-52s ТОКЕН ВИДЕН В ОТВЕТЕ\n' "тело ответа /config.php"; fail=$((fail+1))
else
    printf '  \033[32m✓\033[0m %-52s токена в ответе нет\n' "тело ответа /config.php"; pass=$((pass+1))
fi

echo
echo "────────────────────────────────────────────────────────────"
if (( fail )); then
    echo -e "\033[31mПРОВАЛЕНО: $fail\033[0m, пройдено $pass"
    echo "--- nginx error.log ---"; tail -20 "$WORK/error.log" 2>/dev/null || true
    exit 1
fi
echo -e "\033[32mКонфиг nginx проверен: все $pass проверок пройдены\033[0m"
