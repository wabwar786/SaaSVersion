# Restaurant Software V14 — Startup Safe

V14 fixes the startup error:

`seed_full_demo.php failed`

The full demo database seed is optional and can no longer stop the application.

Core database/login setup remains required. Approved UI demo data is independent,
so Dashboard, Inventory, Purchasing, Recipe, POS and KDS demo data still loads
even when an optional database demo row cannot be inserted.

## Start
Double-click `START_RESTAURANT.bat`

Login:
- admin@urbanspoon.local
- Admin@123

V14 uses local ports 8940–8950.

Support tools:
- `REPAIR_DEMO_DATA.bat`
- `RESET_DEMO_UI.bat`
- `RESET_ADMIN_LOGIN.bat`
- `CHECK_SYSTEM.bat`

Optional demo-seed errors are written to:
`storage/logs/demo-seed.log`

<!-- build: V17.1 build 2026-08-25 -->
