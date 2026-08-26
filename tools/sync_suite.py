#!/usr/bin/env python3
"""
Sync test suite — sab scenarios ek saath. Zip sirf tab jab sab PASS ho.

Usage: python3 sync_suite.py <offline_dir>
"""
import json, os, re, subprocess, sys, time

OFF = sys.argv[1] if len(sys.argv) > 1 else "/tmp/offV1"
CLOUD_DB = "aio_cloud"
LOCAL_DB = "aio_offline"
MYSQL = ["mysql", "-uroot", "-pPakistan_123#", "-N", "-B"]

results = []


def sql(db, q):
    r = subprocess.run(MYSQL + [db, "-e", q], capture_output=True, text=True)
    return r.stdout.strip(), r.stderr.strip()


def php(code):
    full = ('require "runtime/boot.php"; SealedApp::boot(getcwd()); '
            'require "sealed://src/bootstrap.php"; ' + code)
    r = subprocess.run(["php", "-r", full], cwd=OFF,
                       env={**os.environ, "AIO_TEST_DB": "1"},
                       capture_output=True, text=True)
    return r.stdout.strip(), r.stderr.strip()


def check(name, ok, detail=""):
    results.append((name, ok, detail))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + (f"  [{detail}]" if detail else ""))


def sync(trigger="manual"):
    out, err = php(f'echo json_encode(Aio\\Services\\Sync::run("{trigger}"));')
    try:
        return json.loads(out[out.index("{"):])
    except Exception:
        return {"ok": False, "message": f"parse failed: {out[:120]} {err[:120]}"}


def errors_for(res, table):
    return [e["error"] for e in (res.get("table_errors") or []) if e["table"] == table]


RUN = time.strftime("%H%M%S")
tid, _ = sql(LOCAL_DB, "SELECT tenant_id FROM users LIMIT 1")
sid, _ = sql(LOCAL_DB, "SELECT site_id FROM menu_items LIMIT 1")
pwh, _ = sql(LOCAL_DB, "SELECT password_hash FROM users LIMIT 1")

print("\n=== 1. HEALTHY SYNC ===")
r = sync()
check("clean sync succeeds", r.get("ok") is True, r.get("message") or "")
tot = None
for _ in range(6):                      # catch-up ke baad zero par aana chahiye
    r2 = sync()
    tot = sum((r2.get("pushed") or {}).values()) + sum((r2.get("pulled") or {}).values())
    if tot == 0:
        break
check("settles to zero rows", tot == 0, f"{tot} rows after catch-up")
check("no table errors", not (r2.get("table_errors") or []))

print("\n=== 2. SPEED ===")
t0 = time.time(); sync(); el = time.time() - t0
check("sync under 5 seconds", el < 5, f"{el:.2f}s")

print("\n=== 3. DUPLICATE KEY (conflict) ===")
sql(CLOUD_DB, f"INSERT IGNORE INTO users(id,tenant_id,username,email,full_name,"
              f"password_hash,password_algo,status,is_tenant_admin,created_at,updated_at) "
              f"VALUES('c0000001-0000-0000-0000-0000000{RUN}','{tid}','dupc{RUN}',"
              f"'dup{RUN}@suite.pk','Cloud Dup','{pwh}','BCRYPT','ACTIVE',0,NOW(6),NOW(6))")
sql(LOCAL_DB, f"INSERT IGNORE INTO users(id,tenant_id,username,email,full_name,"
              f"password_hash,password_algo,status,is_tenant_admin,created_at,updated_at) "
              f"VALUES('10000001-0000-0000-0000-0000000{RUN}','{tid}','dupl{RUN}',"
              f"'dup{RUN}@suite.pk','Local Dup','{pwh}','BCRYPT','ACTIVE',0,NOW(6),NOW(6))")
r = sync()
msgs = errors_for(r, "users")
check("conflict reported with reason", bool(msgs) and "already used" in msgs[0].lower(),
      msgs[0][:60] if msgs else "no message")
name, _ = sql(CLOUD_DB, f"SELECT full_name FROM users WHERE email='dup{RUN}@suite.pk'")
check("cloud row NOT overwritten", name == "Cloud Dup", name)
stuck = [sync() for _ in range(3)]
check("does not stay stuck", all(not errors_for(x, "users") for x in stuck))

print("\n=== 4. TABLE MISSING ON CLOUD ===")
# notification_queue: is par koi FK nahi, is liye drop ho sakti hai
sql(LOCAL_DB, "DELETE FROM sync_state WHERE scope LIKE '%notification_queue'")
ins = sql(LOCAL_DB, f"INSERT IGNORE INTO notification_queue(id,tenant_id,channel,recipient,"
                    f"status,attempts,available_at,updated_at) "
                    f"VALUES('4a000001-0000-0000-0000-00000000000e','{tid}','SMS','03001234567',"
                    f"'PENDING',0,NOW(6),NOW(6))")
cnt, _ = sql(LOCAL_DB, "SELECT COUNT(*) FROM notification_queue WHERE recipient='03001234567'")
check("test row created locally", cnt == "1", ins[1][:60])
sql(CLOUD_DB, "DROP TABLE IF EXISTS nq_bk")
sql(CLOUD_DB, "CREATE TABLE nq_bk LIKE notification_queue")
sql(CLOUD_DB, "INSERT INTO nq_bk SELECT * FROM notification_queue")
sql(CLOUD_DB, "DROP TABLE notification_queue")
r = sync()
msgs = errors_for(r, "notification_queue")
check("missing table reported (not silent)",
      bool(msgs) and "does not exist" in msgs[0].lower(),
      msgs[0][:60] if msgs else "SILENT - no message")
sql(CLOUD_DB, "CREATE TABLE notification_queue LIKE nq_bk")
sql(CLOUD_DB, "INSERT INTO notification_queue SELECT * FROM nq_bk")
sql(CLOUD_DB, "DROP TABLE nq_bk")

print("\n=== 5. DATA TOO LONG (row-level error) ===")
sql(LOCAL_DB, "DELETE FROM sync_state WHERE scope='push:riders'")
sql(CLOUD_DB, "DELETE FROM riders")          # warna ALTER khud fail ho jata hai
sql(LOCAL_DB, "DELETE FROM sync_state WHERE scope LIKE '%riders'")
sql(CLOUD_DB, "ALTER TABLE riders MODIFY name VARCHAR(5) NOT NULL")
sql(LOCAL_DB, f"INSERT IGNORE INTO riders(id,tenant_id,site_id,name,status,cash_held,created_at,updated_at) "
              f"VALUES('4b000001-0000-0000-0000-00000000000f','{tid}','{sid}',"
              f"'A Very Long Rider Name Here','ACTIVE',0,NOW(6),NOW(6))")
cnt, _ = sql(LOCAL_DB, "SELECT COUNT(*) FROM riders WHERE name LIKE 'A Very Long%'")
check("long-name row created locally", cnt == "1")
r = sync()
msgs = errors_for(r, "riders")
has_sql = bool(msgs) and ("sqlstate" in msgs[0].lower() or "too long" in msgs[0].lower()
                          or "truncat" in msgs[0].lower())
check("row error shows real SQL reason", has_sql, msgs[0][:70] if msgs else "no message")
tries = [sync() for _ in range(3)]
check("gives up after retries (not endless)", not errors_for(tries[-1], "riders"))
q, _ = sql(LOCAL_DB, "SELECT COUNT(*) FROM sync_activity WHERE status='REJECTED' AND table_name='riders'")
check("rejected rows quarantined in log", q.isdigit() and int(q) > 0, f"{q} record(s)")
sql(CLOUD_DB, "ALTER TABLE riders MODIFY name VARCHAR(120) NOT NULL")

print("\n=== 6. SCHEMA COMPARISON ===")
sql(CLOUD_DB, "ALTER TABLE riders MODIFY phone VARCHAR(3) NULL")
out, _ = php('echo json_encode(Aio\\Services\\Sync::schemaDiff(["riders"]));')
try:
    diff = json.loads(out[out.index("["):])
except Exception:
    diff = []
found = [d for d in diff if d.get("column") == "phone"]
check("detects shrunken cloud column", bool(found),
      found[0]["issue"][:55] if found else "not detected")
sql(CLOUD_DB, "ALTER TABLE riders MODIFY phone VARCHAR(40) NULL")

print("\n=== 7. DIAGNOSE (Check button) ===")
out, _ = php('$o=[];foreach(Aio\\Services\\Sync::diagnose() as $c)'
             '$o[]=["s"=>$c["step"],"ok"=>$c["ok"]];echo json_encode($o);')
try:
    steps = json.loads(out[out.index("["):])
except Exception:
    steps = []
names = [s["s"] for s in steps]
check("all diagnostic steps present", len(steps) >= 10, f"{len(steps)} steps")
check("build match check present", "Build match" in names)
check("schema match check present", "Schema match" in names)
core = [s for s in steps if s["s"] in ("Configuration", "Cloud URL", "Sync token",
                                       "Connection", "Cloud response", "Token accepted")]
check("core checks pass", all(s["ok"] for s in core))

print("\n=== 8. NODE VISIBILITY ON CLOUD ===")
n, _ = sql(CLOUD_DB, f"SELECT COUNT(*) FROM sync_nodes WHERE tenant_id='{tid}'")
check("node registered on cloud", n.isdigit() and int(n) > 0, f"{n} node(s)")
b, _ = sql(CLOUD_DB, f"SELECT app_version FROM sync_nodes WHERE tenant_id='{tid}' LIMIT 1")
check("node reports its build", bool(b) and b != "NULL", b)

print("\n=== 9. TWO-WAY SYNC ===")
stamp = time.strftime("%H%M%S")
ins = sql(CLOUD_DB, f"INSERT IGNORE INTO customers(id,tenant_id,full_name,customer_type,status,created_at,updated_at) "
                    f"VALUES('cc000001-0000-0000-0000-0000000000{stamp[-2:]}','{tid}','Cloud Cust {stamp}','Walk-in','ACTIVE',NOW(6),NOW(6))")
made, _ = sql(CLOUD_DB, f"SELECT COUNT(*) FROM customers WHERE full_name='Cloud Cust {stamp}'")
check("cloud test row created", made == "1", ins[1][:70])
sync()
got, _ = sql(LOCAL_DB, f"SELECT COUNT(*) FROM customers WHERE full_name='Cloud Cust {stamp}'")
check("cloud -> local (pull)", got == "1", f"found={got}")
ins = sql(LOCAL_DB, f"INSERT IGNORE INTO customers(id,tenant_id,full_name,customer_type,status,created_at,updated_at) "
                    f"VALUES('11000001-0000-0000-0000-0000000000{stamp[-2:]}','{tid}','Local Cust {stamp}','Walk-in','ACTIVE',NOW(6),NOW(6))")
made, _ = sql(LOCAL_DB, f"SELECT COUNT(*) FROM customers WHERE full_name='Local Cust {stamp}'")
check("local test row created", made == "1", ins[1][:70])
sync()
got, _ = sql(CLOUD_DB, f"SELECT COUNT(*) FROM customers WHERE full_name='Local Cust {stamp}'")
check("local -> cloud (push)", got == "1", f"found={got}")

print("\n=== 10. BILL NUMBER ISOLATION ===")
out, _ = php('echo Aio\\Services\\PageData::billPrefix();')
pre = out.strip()
check("offline node has bill prefix", pre != "", pre or "(empty)")

print("\n=== 11. SYNC LOG ===")
runs, _ = sql(LOCAL_DB, "SELECT COUNT(*) FROM sync_runs")
check("run log recorded", runs.isdigit() and int(runs) > 0, f"{runs} runs")
det, _ = sql(LOCAL_DB, "SELECT COUNT(*) FROM sync_runs WHERE detail_json IS NOT NULL AND detail_json<>'[]'")
check("per-table detail stored", det.isdigit() and int(det) > 0, f"{det} with detail")
act, _ = sql(CLOUD_DB, f"SELECT COUNT(*) FROM sync_activity WHERE tenant_id='{tid}'")
check("cloud activity recorded", act.isdigit() and int(act) > 0, f"{act} entries")

# cleanup
sql(CLOUD_DB, f"DELETE FROM users WHERE email='dup{RUN}@suite.pk'")
sql(LOCAL_DB, f"DELETE FROM users WHERE email='dup{RUN}@suite.pk'")
sql(CLOUD_DB, "DELETE FROM customers WHERE full_name LIKE '%Cust 1%' OR full_name LIKE '%Cust 0%'")
sql(LOCAL_DB, "DELETE FROM riders WHERE name LIKE 'Suite%' OR name LIKE 'A Very Long%'")

passed = sum(1 for _, ok, _ in results if ok)
total = len(results)
print("\n" + "=" * 58)
print(f"  {passed}/{total} passed")
if passed < total:
    print("\n  FAILURES:")
    for n, ok, d in results:
        if not ok:
            print(f"    - {n}  {d}")
print("=" * 58)
sys.exit(0 if passed == total else 1)
