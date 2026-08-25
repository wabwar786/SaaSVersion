
# Restaurant Software V13 — Workable Reset

This build resets the PHP integration around the approved V5 UI.

## What was fixed

- Approved HTML/CSS/layout is kept unchanged.
- Original approved `shared_store.js` is restored.
- Original approved `live_store.js` is restored.
- Approved demo inventory, recipes, purchasing data, dashboard data, POS data and KDS data are visible again.
- The PHP layer no longer replaces approved table rows with plain database rows.
- Existing modal workflows are no longer destroyed by the DB bridge.
- Inventory / Purchasing / Recipe local demo actions are mirrored to MySQL in the background.
- POS/KOT keeps the approved POS logic and mirrors finalized bills/KOT to MySQL.
- POS server resolves approved local item IDs by menu item name.
- Customer local demo IDs are resolved safely.
- Customer App order is mirrored as a pending online order.
- Generic approved placeholder actions now open a real modal instead of only a toast.
- Generic table rows open a record-detail popup.
- Online Order buttons and Customer QR buttons now respond.
- Full database demo seed includes demo user, pending signup, shift, riders, reservations, expenses, promotions, staff, notifications and sample orders.
- Existing `aio_local` database is reused.
- Existing working PHP installations/runtimes are reused when possible.
- No online MySQL exposure is required.

## Start

Double-click:

`START_RESTAURANT.bat`

Default local administrator:

- `admin@urbanspoon.local`
- `Admin@123`

V13 uses local ports 8920–8930.

<!-- build: V17.1 build 2026-08-25 -->
