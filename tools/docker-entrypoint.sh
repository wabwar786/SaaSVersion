#!/usr/bin/env bash
set -e
cd /var/www/html
export AIO_CONFIG=config/cloud.php

# Static .html in public/ would bypass router.php (no CSRF/auth injection). Remove them.
rm -f public/*.html 2>/dev/null || true

# --- Railway gives a dynamic PORT; Apache must listen on it ---
# --- Session storage MUST be writable, warna login kabhi tikta nahi aur
#     har POST par "Invalid CSRF token" aata hai (khamosh failure: PHP ka
#     session warning display_errors=0 ki wajah se kahin nazar nahi aata).
mkdir -p storage/sessions storage/logs
chown -R www-data:www-data storage 2>/dev/null || true
chmod -R u+rwX,g+rwX storage 2>/dev/null || true
if su -s /bin/sh www-data -c 'test -w storage/sessions' 2>/dev/null; then
  echo "[boot] session storage writable OK"
else
  echo "[boot] WARNING: storage/sessions NOT writable by www-data - login/CSRF will fail"
fi

PORT="${PORT:-8080}"
sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost \*:[0-9]+>!<VirtualHost *:${PORT}>!" /etc/apache2/sites-available/000-default.conf
echo "[boot] Apache listening on ${PORT}"

# --- Guarantee exactly ONE MPM (prefork) at RUNTIME — cache-proof.
# (Build-layer fixes can be skipped by Docker cache on some platforms.)
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf 2>/dev/null || true
a2enmod mpm_prefork >/dev/null 2>&1 || true
echo "[boot] MPM normalized to prefork"

# --- wait for the database (up to ~60s) ---
php -r '
$c=require "config/cloud.php"; $d=$c["db"];
for($i=0;$i<30;$i++){
  try{ new PDO("mysql:host={$d["host"]};port={$d["port"]};dbname={$d["database"]};charset=utf8mb4",$d["username"],$d["password"],[PDO::ATTR_TIMEOUT=>3]); echo "[boot] DB reachable\n"; exit(0);}
  catch(Throwable $e){ fwrite(STDERR,"[boot] waiting for db... ".$e->getMessage()."\n"); sleep(2);} }
exit(1);' || echo "[boot] WARNING: DB not reachable; migrations skipped this boot"

# --- FAST migrations (schema/columns) — inke baghair app chal hi nahi sakta ---
php scripts/install_schema.php   || echo "[boot] schema step skipped"
php scripts/migrate_platform.php || echo "[boot] platform migrate skipped"
php scripts/migrate_sync.php     || echo "[boot] sync migrate skipped"
php scripts/migrate_bridge.php   || echo "[boot] bridge migrate skipped"
php scripts/migrate_menu_image.php || echo "[boot] menu image migrate skipped"
php scripts/migrate_branding.php  || echo "[boot] branding migrate skipped"
php scripts/migrate_qr_orders.php || echo "[boot] qr orders migrate skipped"
php scripts/migrate_devices.php   || echo "[boot] devices migrate skipped"
php scripts/migrate_shifts.php    || echo "[boot] shifts migrate skipped"
php scripts/migrate_sync_columns.php || echo "[boot] sync columns migrate skipped"
php scripts/migrate_sync_log.php    || echo "[boot] sync log migrate skipped"
php scripts/migrate_delete_support.php || echo "[boot] delete support migrate skipped"
php scripts/migrate_print_rule_default.php || echo "[boot] print_rule migrate skipped"
php scripts/migrate_fiscal.php || echo "[boot] fiscal migrate skipped"
php scripts/migrate_platform_admin.php || echo "[boot] platform admin migrate skipped"
php scripts/seed_platform_modules.php || echo "[boot] modules seed skipped"
php scripts/migrate_module_ids.php || echo "[boot] module ids migrate skipped"
php -r 'require "src/bootstrap.php"; \Aio\Services\Platform::ensureSuperUser(); echo "[boot] super admin ready\n";' || true

# --- Platform (super admin) login: bina shell ke reset karne ka rasta ---
#     Railway par shell na ho to service ki Variables mein
#     SUPER_ADMIN_EMAIL aur SUPER_ADMIN_PASSWORD daal kar redeploy karein.
#
#     `--once` LAZMI hai. Pehle yeh HAR boot par chalta tha, yani jab tak
#     variables Railway mein pade rehte, har upload par password wapas
#     usi par chala jata tha -- chahe user ne Account screen se badal
#     liya ho. Deploy ko data (khaas kar credentials) kabhi nahi chhoona
#     chahiye. Ab marker ki wajah se ek hi dafa lagta hai.
if [ -n "${SUPER_ADMIN_EMAIL:-}" ] && [ -n "${SUPER_ADMIN_PASSWORD:-}" ]; then
  php scripts/reset_super_admin.php --once --create \
      --email="$SUPER_ADMIN_EMAIL" --password="$SUPER_ADMIN_PASSWORD" || true
else
  # Sirf list (koi password nahi) - taake log se pata chale kaunsi email chahiye
  php scripts/reset_super_admin.php 2>/dev/null | head -12 || true
fi

# --- Apache PEHLE start hota hai taake Railway ka healthcheck foran pass ho.
#     (Pehle sab migrations ke baad start hota tha; collation ka column-level
#     pass minutes le sakta hai -> healthcheck timeout -> 502 Bad Gateway.) ---
echo "[boot] starting Apache"
apache2-foreground &
APACHE_PID=$!

# --- HEAVY / one-time migrations background mein, app ko block kiye baghair ---
(
  sleep 2
  php scripts/migrate_collation.php     || echo "[boot] collation migrate skipped"
  php scripts/migrate_site_defaults.php || echo "[boot] site defaults skipped"
  php scripts/migrate_ui_menu.php       || echo "[boot] ui menu backfill skipped"
  echo "[boot] background migrations done"
) >/proc/1/fd/1 2>&1 &

wait $APACHE_PID

# build: V17.1 build 2026-08-25
