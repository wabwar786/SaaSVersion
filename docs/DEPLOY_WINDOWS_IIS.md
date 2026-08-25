# Windows Server / IIS deployment

1. Install PHP 8.1+ Non-Thread-Safe build and enable `pdo_mysql`, `mysqli`, `openssl`, `mbstring`.
2. Install/configure IIS FastCGI for PHP.
3. Create a site/application whose physical path is **`<project>\public`**.
4. Keep `config`, `src`, `scripts`, and `storage` outside the IIS document root.
5. Give the IIS app-pool identity read access to the project and write access only to `storage/` if logs are enabled.
6. MySQL should listen on `127.0.0.1` for single-PC use. For trusted LAN edge use, bind only to the private interface and firewall it tightly.
7. Never expose MySQL 3306 publicly.
8. When cloud sync is enabled later, configure only an HTTPS API endpoint and API credential stored server-side.

<!-- build: V17.1 build 2026-08-25 -->
