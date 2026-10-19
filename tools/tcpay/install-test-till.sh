#!/usr/bin/env bash
# ============================================================================
# TC Pay till package - install a TEST OSPOS till on the TC Pay VPS
#
# Builds this checkout (tcpay/payment-tender branch), installs it at
# /var/www/pos-test/ospos on 127.0.0.1:8082 (reach it through an SSH tunnel),
# connects it to the TC Pay SANDBOX connector, and runs one scripted sale paid
# with TC Pay. Nothing here touches production data or the OpenCart store.
#
# Run as root from the repository root:
#   bash tools/tcpay/install-test-till.sh
#
# Needs: /root/.tcpay-pos-db.env  (OSPOS_DB, OSPOS_USER, OSPOS_PASS)
#        /root/.tcpay-sandbox-opencart-key  (TCPAY_SANDBOX_API_KEY)
# Re-runnable: rebuilds and redeploys code; keeps .env, writable/ and the database.
# Never prints keys or passwords.
# ============================================================================
set -uo pipefail

SRC=$(cd "$(dirname "$0")/../.." && pwd)
DEST=${OSPOS_DEST:-/var/www/pos-test/ospos}
PORT=${OSPOS_PORT:-8082}
BASE="http://127.0.0.1:$PORT"
API=${TCPAY_API:-http://127.0.0.1:3000/api/v1/connector}
SITE=/etc/nginx/tc-pay/sites/pos-test-ospos.conf
ENVFILE=/root/.tcpay-pos-db.env
KEYFILE=/root/.tcpay-sandbox-opencart-key

say()  { printf '\n=== %s ===\n' "$*"; }
fail() { printf '\nSTOPPED: %s\n' "$*"; exit 1; }
json() { python3 -c "import sys,json
try: d=json.load(sys.stdin)
except Exception: print(''); sys.exit()
if not isinstance(d, dict): print(''); sys.exit()
print($1)"; }

[ "$(id -u)" = 0 ] || fail "run as root"
[ -r "$ENVFILE" ] || fail "$ENVFILE not found"
[ -r "$KEYFILE" ] || fail "$KEYFILE not found (run the connector deploy first)"
set -a; . "$ENVFILE"; set +a
[ -n "${OSPOS_DB:-}" ] && [ -n "${OSPOS_USER:-}" ] && [ -n "${OSPOS_PASS:-}" ] || fail "OSPOS_DB / OSPOS_USER / OSPOS_PASS missing in $ENVFILE"
SANDBOX_KEY=$(sed -n 's/^TCPAY_SANDBOX_API_KEY=//p' "$KEYFILE")
[ -n "$SANDBOX_KEY" ] || fail "no sandbox key in $KEYFILE"
export MYSQL_PWD="$OSPOS_PASS"
SQL() { mariadb -u"$OSPOS_USER" "$OSPOS_DB" -N -e "$1"; }

# Explains a failed till call: HTTP status, the start of the response (secrets
# redacted) and OSPOS's own recent errors.
explain() { # $1 label, $2 response file, $3 HTTP status
  echo "--- $1: HTTP $3 ---"
  head -c 600 "$2" | sed -E 's/(sandbox|live)_tc_[A-Za-z0-9]+/<key>/g; s/[0-9a-f]{40,}/<redacted>/g'
  echo; echo "--- OSPOS log (latest errors) ---"
  grep -hE "^(CRITICAL|ERROR|ALERT|EMERGENCY)" "$DEST"/writable/logs/log-* 2>/dev/null | tail -6 | cut -c1-400 \
    | sed -E 's/(sandbox|live)_tc_[A-Za-z0-9]+/<key>/g'
}

say "1. Prerequisites"
PHP_MODULES=$(php -m)
for ext in bcmath curl gd intl mbstring mysqli xml zip; do
  grep -qix "$ext" <<<"$PHP_MODULES" || fail "PHP extension $ext missing (apt-get install php8.3-$ext)"
done
command -v composer >/dev/null || { DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends composer >/dev/null || fail "could not install composer"; }
command -v npm >/dev/null || fail "npm not found"
avail=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo)
echo "php $(php -r 'echo PHP_VERSION;'), composer $(composer --version 2>/dev/null | awk '{print $3}'), node $(node -v), ${avail} MB RAM available"
[ "$avail" -ge 600 ] || fail "less than 600 MB RAM available; not building now"

say "2. Build (composer + npm, in $SRC)"
cd "$SRC" || fail "cannot cd to $SRC"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress -q || fail "composer install failed"
if [ "${SKIP_FRONTEND_BUILD:-0}" = 1 ]; then
  echo "front-end build skipped (SKIP_FRONTEND_BUILD=1)"
else
  npm ci --no-audit --no-fund --loglevel=error >/dev/null || fail "npm ci failed"
  npm run build --silent >/dev/null 2>&1 || fail "npm run build failed"
  [ -d public/resources ] || fail "build did not produce public/resources"
fi
echo "built $(git rev-parse --short HEAD) ($(git branch --show-current))"

say "3. Deploy to $DEST (keeps .env and writable/)"
mkdir -p "$DEST"
rsync -a --delete \
  --exclude '/.env' --exclude '/writable/' --exclude '/.git/' --exclude '/node_modules/' \
  --exclude '/tests/' --exclude '/dist/' --exclude '/design/' --exclude '/docker*' \
  "$SRC"/ "$DEST"/ || fail "rsync failed"
rsync -a --ignore-existing "$SRC"/writable/ "$DEST"/writable/
# The build injects asset tags into these tracked views; the deployed copy keeps
# them, the git checkout goes back to clean so later pulls never conflict.
git -C "$SRC" checkout -q -- app/Views/partial/header.php app/Views/login.php app/Views/configs/manage.php
chown -R www-data:www-data "$DEST/writable"
find "$DEST" -path "$DEST/writable" -prune -o -type d -exec chmod 755 {} + ; find "$DEST" -path "$DEST/writable" -prune -o -type f -exec chmod 644 {} +

if [ ! -f "$DEST/.env" ]; then
  cp "$DEST/.env.example" "$DEST/.env"
  python3 - "$DEST/.env" "$OSPOS_DB" "$OSPOS_USER" "$OSPOS_PASS" "$PORT" <<'PY'
import re, sys
path, db, user, pw, port = sys.argv[1:]
s = open(path).read()
def setkey(key, value):
    # Set every active (uncommented) line for the key; commented examples are left alone.
    global s
    line = f"{key}='{value}'"
    pattern = re.compile(rf"^{re.escape(key)}\s*=.*$", re.M)
    s = pattern.sub(line, s) if pattern.search(s) else s + "\n" + line + "\n"
setkey('CI_ENVIRONMENT', 'production')
setkey('app.allowedHostnames', f'127.0.0.1:{port},localhost:{port}')
for group in ('default', 'development'):
    setkey(f'database.{group}.hostname', 'localhost')
    setkey(f'database.{group}.database', db)
    setkey(f'database.{group}.username', user)
    setkey(f'database.{group}.password', pw)
open(path, 'w').write(s.replace("CI_ENVIRONMENT='production'", "CI_ENVIRONMENT=production"))
PY
  echo "created .env"
fi
# OSPOS's .env.example turns logging off (threshold 0); keep errors (level 4) so failures can be diagnosed.
if grep -qE '^logger\.threshold\s*=' "$DEST/.env"; then
  sed -i -E 's/^logger\.threshold\s*=.*/logger.threshold=4/' "$DEST/.env"
else
  echo 'logger.threshold=4' >> "$DEST/.env"
fi
chown www-data:www-data "$DEST/.env"; chmod 640 "$DEST/.env"
# env:provision writes .env through a temporary file in the same folder, so it
# needs write access to $DEST (owned by root): run it as root, then hand .env back
# to www-data. On a brand-new install it writes the key and then errors because
# the settings table does not exist yet; what matters is that the key was written.
PROVISION_OUT=$(cd "$DEST" && php spark env:provision 2>&1)
chown www-data:www-data "$DEST/.env"; chmod 640 "$DEST/.env"
[ -f "$DEST/.env.lock" ] && chown www-data:www-data "$DEST/.env.lock"
rm -f "$DEST"/.env.tmp.* 2>/dev/null
if ! grep -qE "^encryption\.key\s*=\s*'?(hex2bin:)?[0-9a-f]{64}'?\s*$" "$DEST/.env"; then
  echo "$PROVISION_OUT" | sed -E 's/[0-9a-f]{32,}/<redacted>/g' | grep -v '^#[0-9]' | tail -8
  fail "encryption key was not provisioned"
fi
echo ".env ready (encryption key provisioned)"

say "4. Nginx site on 127.0.0.1:$PORT"
cat > "$SITE" <<NGX
server {
    listen 127.0.0.1:$PORT;
    server_name localhost;
    root $DEST/public;
    index index.php;
    client_max_body_size 8M;
    location / { try_files \$uri \$uri/ /index.php\$is_args\$args; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
NGX
nginx -t >/dev/null 2>&1 || fail "nginx config test failed"
systemctl reload nginx
sleep 1
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/login")
echo "login page: $code"
[ "$code" = 200 ] || fail "OSPOS not answering (see $DEST/writable/logs)"

say "5. Database and admin account"
JAR=$(mktemp); trap 'rm -f "$JAR"' EXIT
if [ -z "$(SQL "SHOW TABLES LIKE 'ospos_employees'")" ]; then
  curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$BASE/login" -d 'username=admin&password=pointofsale'
  [ -n "$(SQL "SHOW TABLES LIKE 'ospos_employees'")" ] || fail "schema was not created"
  echo "schema created"
fi
if ! grep -q '^OSPOS_ADMIN_PASS=' "$ENVFILE"; then
  ADMIN_PASS=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | cut -c1-20)
  HASH=$(ADMIN_PASS="$ADMIN_PASS" php -r 'echo password_hash(getenv("ADMIN_PASS"), PASSWORD_DEFAULT);')
  SQL "UPDATE ospos_employees SET password='$HASH', hash_version=2 WHERE username='admin'"
  ( umask 077; printf 'OSPOS_ADMIN_USER=admin\nOSPOS_ADMIN_PASS=%s\n' "$ADMIN_PASS" >> "$ENVFILE" )
  echo "default admin password replaced (saved in $ENVFILE)"
fi
set -a; . "$ENVFILE"; set +a

say "6. Store currency ZAR"
SQL "UPDATE ospos_app_config SET value='ZAR' WHERE \`key\`='currency_code'; UPDATE ospos_app_config SET value='R' WHERE \`key\`='currency_symbol'; UPDATE ospos_app_config SET value='Africa/Johannesburg' WHERE \`key\`='timezone'"
rm -f "$DEST"/writable/cache/settings* 2>/dev/null
echo "currency: $(SQL "SELECT value FROM ospos_app_config WHERE \`key\`='currency_code'")"

say "7. Log in and configure TC Pay (sandbox)"
rm -f "$JAR"
curl -s -o /dev/null -c "$JAR" -b "$JAR" "$BASE/login"
curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$BASE/login" --data-urlencode "username=$OSPOS_ADMIN_USER" --data-urlencode "password=$OSPOS_ADMIN_PASS"
csrf() { curl -s -c "$JAR" -b "$JAR" "$BASE/$1" | python3 -c 'import re,sys; m=re.search(r"csrf_token = function\(\) \{\s*return \"([^\"]+)\"", sys.stdin.read()); print(m.group(1) if m else "")'; }
TOKEN=$(csrf home)
[ -n "$TOKEN" ] || fail "admin login failed"
echo "logged in as $OSPOS_ADMIN_USER"
R=$(curl -s -c "$JAR" -b "$JAR" -H 'X-Requested-With: XMLHttpRequest' \
  --data-urlencode "csrf_ospos_v4=$TOKEN" \
  --data-urlencode "tcpay_sandbox_key=$SANDBOX_KEY" \
  -d "tcpay_enabled=1&tcpay_environment=sandbox&tcpay_api_url=$API&tcpay_expiry_minutes=15&tcpay_test_buttons=1" \
  "$BASE/config/saveTcpay")
[ "$(echo "$R" | json "d.get('success')")" = "True" ] || fail "saving TC Pay settings failed: $R"
R=$(curl -s -c "$JAR" -b "$JAR" -H 'X-Requested-With: XMLHttpRequest' --data-urlencode "csrf_ospos_v4=$TOKEN" "$BASE/config/testTcpay")
echo "test connection: $(echo "$R" | json "d.get('message')")"
[ "$(echo "$R" | json "d.get('success')")" = "True" ] || fail "connection test failed"
echo "stored key is encrypted: $([ "$(SQL "SELECT value LIKE 'sandbox_tc_%' FROM ospos_app_config WHERE \`key\`='tcpay_sandbox_key'")" = 0 ] && echo yes || echo NO)"

say "8. Scripted sale paid with TC Pay"
ITEM_ID=$(SQL "SELECT item_id FROM ospos_items WHERE item_number='TCPAY-TEST' LIMIT 1")
if [ -z "$ITEM_ID" ]; then
  ITEM_ID=$(SQL "INSERT INTO ospos_items (name, category, item_number, description, cost_price, unit_price, reorder_level, receiving_quantity, allow_alt_description, is_serialized, stock_type, item_type, deleted, qty_per_pack, pack_name)
                 VALUES ('TC Pay test item', 'Test', 'TCPAY-TEST', 'Sandbox test item', 10.00, 49.95, 0, 1, 0, 0, 1, 0, 0, 1, 'Each'); SELECT LAST_INSERT_ID();" | tail -1)
fi
post() { curl -s -c "$JAR" -b "$JAR" -H 'X-Requested-With: XMLHttpRequest' --data-urlencode "csrf_ospos_v4=$TOKEN" "$@"; }
curl -s -o /dev/null -c "$JAR" -b "$JAR" "$BASE/sales"
post -o /dev/null -d "item=$ITEM_ID" "$BASE/sales/add"
RESP=$(mktemp)
CODE=$(post -o "$RESP" -w '%{http_code}' -d "amount=49.95&request_id=$(openssl rand -hex 16)" "$BASE/tcpay/create")
R=$(cat "$RESP")
ID=$(echo "$R" | json "d.get('id','')")
[ -n "$ID" ] || { explain "tcpay/create" "$RESP" "$CODE"; fail "TC Pay create failed"; }
echo "TC Pay intent $(echo "$R" | json "d['reference'] + ' ' + d['amount'] + ' ' + d['status']")"
R=$(post -d "outcome=success" "$BASE/tcpay/simulate/$ID")
echo "simulate payment -> $(echo "$R" | json "d.get('status')"), in cart: $(echo "$R" | json "d.get('in_cart')")"
REF=$(echo "$R" | json "d.get('reference','')")
post -o /dev/null "$BASE/sales/complete"
echo "sale recorded:        $(SQL "SELECT CONCAT('POS ', sale_id, ' ', payment_type, ' R', payment_amount) FROM ospos_sales_payments WHERE payment_type='TC Pay:$REF'")"
echo "sandbox payments:     $(sudo -u postgres psql -X -At -d tcpay_connector_sandbox -c "SELECT count(*) FROM tcpay.payments WHERE idempotency_key='intent:$ID'")  (expect 1)"
echo "production payments:  $(sudo -u postgres psql -X -At -d tcpay -c "SELECT count(*) FROM tcpay.payments WHERE idempotency_key='intent:$ID'")  (expect 0)"

say "Done"
echo "Till: $BASE/ (via SSH tunnel)  user: $OSPOS_ADMIN_USER  password: grep OSPOS_ADMIN_PASS $ENVFILE"
echo "TC Pay settings: Office > Configuration > System > Integrations"
