<?php
/**
 * migration_order.php — SCHEMA MIGRATIONS KI EK HI FEHRIST.
 *
 * MASLA JO YEH HAL KARTA HAI:
 * Cloud (`tools/docker-entrypoint.sh`) 30+ migrations chalata tha, magar
 * offline ka `INSTALL_UPDATE.bat` sirf PAANCH:
 *
 *     migrate_sync_columns migrate_delete_support
 *     migrate_print_rule_default migrate_fiscal migrate_module_ids
 *
 * Nateeja: main koi nayi table ya column add karoon (misal `app_errors`,
 * ya V141 ke module groups), to offline customer ko CODE to aa jata tha
 * magar SCHEMA nahi. Software update ho kar toot jata — aur wajah kahin
 * nazar na aati.
 *
 * Yeh bilkul wohi shakal thi jo `tools\*.ps1` ki hard-coded list mein thi
 * (V142 ka `native_helper.ps1` wala bug). Ek hi cheez do jagah likhi ho
 * to ek jagah bhool jana sirf waqt ki baat hai.
 *
 * AB: fehrist SIRF yahan hai. Cloud ka entrypoint aur offline ka update
 * dono `scripts/run_migrations.php` ke zariye isay parhte hain. Nayi
 * migration add karni ho to EK line, EK file — dono taraf khud chal
 * jayegi.
 *
 * TARTEEB AHEM HAI. Yeh wahi tarteeb hai jo cloud par aazmai hui hai:
 *   - `install_schema` sab se pehle (tables banti hain)
 *   - baaqi migrations columns/indexes daalti hain
 *   - seeds un ke baad (unhen tables chahiye hoti hain)
 *   - `migrate_module_ids` seeds ke BAAD (module rows mojood hon)
 *
 * 'optional' => true ka matlab: yeh step na chale to update na ruke
 * (misal demo data, ya aisi migration jo sirf kuch installs par lagti
 * hai). 'cloud_only' => true wale steps offline node par skip hote hain.
 */
declare(strict_types=1);

return [
    /* ---------- tables ---------- */
    ['script' => 'install_schema'],

    /* ---------- structure ---------- */
    ['script' => 'migrate_platform'],
    ['script' => 'migrate_sync'],
    ['script' => 'migrate_bridge'],
    ['script' => 'migrate_menu_image'],
    ['script' => 'migrate_branding'],
    ['script' => 'migrate_qr_orders'],
    ['script' => 'migrate_devices'],
    ['script' => 'migrate_shifts'],
    ['script' => 'migrate_error_log'],
    ['script' => 'migrate_sync_directives'],
    ['script' => 'migrate_sync_columns'],
    ['script' => 'migrate_sync_log'],
    ['script' => 'migrate_delete_support'],
    ['script' => 'migrate_print_rule_default'],
    ['script' => 'migrate_fiscal'],
    ['script' => 'migrate_security'],
    ['script' => 'migrate_modules'],
    ['script' => 'migrate_selfservice'],
    ['script' => 'migrate_licence_key'],
    ['script' => 'migrate_retail'],

    /* ---------- seeds (tables ke baad) ---------- */
    ['script' => 'seed_industry_modules'],
    ['script' => 'seed_roles',            'optional' => true],
    ['script' => 'migrate_role_scope'],
    ['script' => 'demo_reset',            'optional' => true],
    ['script' => 'migrate_platform_admin','cloud_only' => true],
    ['script' => 'seed_platform_modules'],

    /* module ids seeds ke BAAD — rows mojood honi chahiyen */
    ['script' => 'migrate_module_ids'],

    /* ---------- late / housekeeping ----------
       'stage' => 'background': cloud par yeh Apache START HONE KE BAAD
       chalte hain. `migrate_collation` ka column-level pass minutes le
       sakta hai; pehle sab migrations Apache se PEHLE chalti thin aur
       Railway ka healthcheck timeout ho kar 502 Bad Gateway deta tha.
       Offline node par aisi koi majboori nahi — wahan sab ek saath. */
    ['script' => 'migrate_collation',     'optional' => true, 'stage' => 'background'],
    ['script' => 'migrate_site_defaults', 'optional' => true, 'stage' => 'background'],
    ['script' => 'migrate_ui_menu',       'optional' => true, 'stage' => 'background'],

    /* ---------- consistency checks (schema nahi badalte) ---------- */
    ['script' => 'check_module_groups',   'optional' => true, 'check' => true],
    ['script' => 'check_offline_tools',   'optional' => true, 'check' => true, 'cloud_only' => true],
];
