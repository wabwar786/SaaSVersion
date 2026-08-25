# Cloud Sync Policy

The online/cloud database is not directly exposed to this PHP app.

Recommended future flow:

`aio_local -> sync_outbox -> background worker -> HTTPS Cloud API -> aio_cloud`

Cloud-to-branch changes use the reverse API queue. Each event uses UUIDs and idempotency keys. Financial and stock transactions are append-only events; do not use last-write-wins for money or stock.
