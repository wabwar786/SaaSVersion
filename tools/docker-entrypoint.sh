#!/usr/bin/env bash
set -e
cd /var/www/html
export AIO_CONFIG=config/cloud.php

# Static .html in public/ would bypass router.php (no CSRF/auth injection). Remove them.
rm -f public/*.html 2>/dev/null || true

# --- Railway gives a dynamic PORT; Apache must listen on it ---
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

# --- idempotent schema + migrations + platform seed ---
php scripts/install_schema.php   || echo "[boot] schema step skipped"
php scripts/migrate_platform.php || echo "[boot] platform migrate skipped"
php scripts/migrate_sync.php     || echo "[boot] sync migrate skipped"
php scripts/migrate_bridge.php   || echo "[boot] bridge migrate skipped"
php scripts/migrate_collation.php || echo "[boot] collation migrate skipped"
php scripts/migrate_site_defaults.php || echo "[boot] site defaults skipped"
php scripts/migrate_ui_menu.php || echo "[boot] ui menu backfill skipped"
php scripts/migrate_menu_image.php || echo "[boot] menu image migrate skipped"
php scripts/migrate_branding.php  || echo "[boot] branding migrate skipped"
php scripts/seed_platform_modules.php || echo "[boot] modules seed skipped"
php -r 'require "src/bootstrap.php"; \Aio\Services\Platform::ensureSuperUser(); echo "[boot] super admin ready\n";' || true

echo "[boot] starting Apache"
exec apache2-foreground

# build: V17.1 build 2026-08-25
