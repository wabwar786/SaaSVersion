#!/usr/bin/env python3
"""Factory reset verification: FULL reset ke baad koi table mein data na bache."""
import json, subprocess, sys, urllib.request, urllib.error, http.cookiejar, re

CLOUD = "http://127.0.0.1:8941"
SLUG = sys.argv[1] if len(sys.argv) > 1 else "royal-grill"


def sql(q, db="aio_cloud"):
    r = subprocess.run(["mysql", "-uroot", "-pPakistan_123#", "-N", "-B", db, "-e", q],
                       capture_output=True, text=True)
    return r.stdout.strip()


op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def api(action, payload=None):
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(f"{CLOUD}/api.php?action={action}", data=data,
                                 method="POST" if data else "GET")
    if data:
        req.add_header("Content-Type", "application/json")
        req.add_header("X-CSRF-Token", CSRF)
    try:
        return json.loads(op.open(req, timeout=120).read().decode())
    except urllib.error.HTTPError as e:
        return json.loads(e.read().decode())


page = op.open(f"{CLOUD}/super_admin.html", timeout=20).read().decode()
CSRF = (re.search(r'APP_CSRF="([a-f0-9]+)"', page) or [None, ""])[1]
r = api("sa-login", {"email": "super@admin.local", "password": "Super@123"})
print("super login:", r.get("ok"))

tid = sql(f"SELECT id FROM tenants WHERE slug='{SLUG}'")
name = sql(f"SELECT name FROM tenants WHERE slug='{SLUG}'")
sites = sql(f"SELECT GROUP_CONCAT(CONCAT(\"'\",id,\"'\")) FROM sites WHERE tenant_id='{tid}'") or "''"
print(f"business: {name}  ({tid[:8]}…)")

tables = [t for t in sql(
    "SELECT DISTINCT table_name FROM information_schema.columns "
    "WHERE table_schema='aio_cloud' AND column_name IN ('tenant_id','site_id') "
    "ORDER BY table_name").splitlines() if t]


def snapshot():
    out = {}
    for t in tables:
        cols = sql(f"SELECT GROUP_CONCAT(column_name) FROM information_schema.columns "
                   f"WHERE table_schema='aio_cloud' AND table_name='{t}'")
        key = "tenant_id" if "tenant_id" in cols.split(",") else "site_id"
        where = f"tenant_id='{tid}'" if key == "tenant_id" else f"site_id IN ({sites})"
        n = sql(f"SELECT COUNT(*) FROM `{t}` WHERE {where}")
        if n.isdigit() and int(n) > 0:
            out[t] = int(n)
    return out


before = snapshot()
print(f"\nBEFORE reset: {len(before)} tables have data, {sum(before.values())} rows total")

# backup lena zaroori hai (reset guard)
b = op.open(f"{CLOUD}/api.php?action=sa-backup&tenant_id={tid}&scope=FULL", timeout=120).read()
open("/tmp/reset_backup.json", "wb").write(b)
print(f"backup taken: {len(b)} bytes")

r = api("sa-factory-reset", {"tenant_id": tid, "mode": "FULL", "confirm_name": name})
if not r.get("ok"):
    print("RESET FAILED:", r.get("message")); sys.exit(1)
print(f"reset: {r['total']} rows deleted from {r['tables']} tables")

after = snapshot()

# Sirf yeh bachna chahiye
ALLOWED = {
    "users": 1, "user_roles": 1, "roles": None, "role_modules": None,
    "payment_methods": None, "stock_locations": None, "units": None,
    "printers": None, "floors": None, "dining_tables": None,
    "menu_categories": None, "menu_category_printer_routes": None,
    "expense_categories": None,
    # business ka shell — kabhi delete nahi hota
    "sites": None, "organizations": None,
    "sync_state": None, "sync_activity": None, "sync_nodes": None,
    "sync_runs": None, "admin_backups": None, "admin_imports": None,
    "admin_audit": None, "tenant_subscriptions": None, "subscription_payments": None,
}

print(f"\nAFTER reset: {len(after)} tables still have data")
leftovers = []
for t, n in sorted(after.items()):
    if t in ALLOWED:
        cap = ALLOWED[t]
        mark = "ok (re-seeded)" if cap is None else ("ok" if n <= cap else f"TOO MANY ({n})")
        print(f"   {t:28} {n:5}  {mark}")
        if cap is not None and n > cap:
            leftovers.append(t)
    else:
        print(f"   {t:28} {n:5}  LEFTOVER")
        leftovers.append(t)

admin = sql(f"SELECT username FROM users WHERE tenant_id='{tid}'")
print(f"\nadmin login kept: {admin or '(NONE — BAD)'}")

ok = (not leftovers) and bool(admin)
print("\n" + "=" * 56)
print("  FACTORY RESET " + ("CLEAN — only admin login + defaults remain" if ok
      else f"INCOMPLETE — leftovers: {', '.join(leftovers)}"))
print("=" * 56)
sys.exit(0 if ok else 1)
