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
    /* V148 — tax rates `ui_records/pos_settings` se `site_settings` par.
       Background mein hai kyunke yeh sirf data move karti hai; koi
       screen is par nahi ruki hui. */
    ['script' => 'migrate_tax_settings',  'optional' => true, 'stage' => 'background'],
    /* V151 — billing rate (tenants par) aur invoices ka khata. */
    ['script' => 'migrate_billing',       'optional' => true, 'stage' => 'background'],
    /* V154 — retail ka stock aur documents. V153 tak supermarket ka
       stock sirf GHAT sakta tha: POS bechta tha, aur maal AANE ka koi
       rasta hi nahi tha. Yeh un tables ko banati hai jin par GRN,
       return, count aur wastage chalte hain. Sirf nayi `rtl_*` tables —
       restaurant ki kisi table ko haath nahi lagta. */
    ['script' => 'migrate_retail_ops',    'optional' => true, 'stage' => 'background'],
    /* V157 — do chhoti columns: `orders.discount_pct` (hold/unhold par
       "10%" wapas aaye, raqam nahi) aur `customers.discount_pct`
       (customer ka tay-shuda discount). Dono default 0, purani rows par
       koi asar nahi. */
    ['script' => 'migrate_discounts',     'optional' => true, 'stage' => 'background'],
    /* V171 — `orders.delivery_charge`. Home Delivery ka kiraya ab apni
       jagah likha jata hai, khane ki keemat ya service charge mein
       chhupaya nahi jata. Tax se bahar, default 0, purane bill ka total
       nahi badalta. */
    ['script' => 'migrate_delivery_charge', 'optional' => true, 'stage' => 'background'],
    /* V158 — `menu_items.sort_order`, taake POS par tarteeb restaurant
       ki marzi se ho (naam ke alphabet se nahi). Saath hi jin sites ne
       category ki tarteeb kabhi set nahi ki, un ki categories ko
       1,2,3... de deta hai — nazar aane wali tarteeb badalti nahi, bas
       screen par badalne ke qabil ho jati hai. */
    ['script' => 'migrate_menu_sort',     'optional' => true, 'stage' => 'background'],
    /* V175 — purani `features_json` liston mein `activate`/`dashboard`
       daal deti hai. In ke baghair RENEW ki screen band ho jati thi
       (router page ko POS par wapas bhej deta tha). Code ab in ko girne
       nahi deta; yeh script sirf purana data theek karti hai taake
       Super Admin ki Features screen bhi wohi sach dikhaye. */
    ['script' => 'migrate_always_on',     'optional' => true, 'stage' => 'background'],
    /* V149 — licence ka pehra. Yeh script likhi gayi thi, documented
       bhi thi, magar sirf `windows_bootstrap.ps1` (pehli install) mein
       chalti thi — yani grace period khatam hone par kabhi kuch hota hi
       nahi tha. Ab har update par bhi, aur har start par bhi
       (`tools/start_offline.ps1`). Cloud par khud skip kar jati hai. */
    ['script' => 'licence_enforce',       'optional' => true, 'stage' => 'background'],

    /* ---------- consistency checks (schema nahi badalte) ---------- */
    ['script' => 'check_module_groups',   'optional' => true, 'check' => true],
    ['script' => 'check_settings_single_row', 'optional' => true, 'check' => true],
    ['script' => 'check_offline_tools',   'optional' => true, 'check' => true, 'cloud_only' => true],
    /* V153 — `migrate_collation` ki chhuti hui columns ka pehra.
       MariaDB foreign-key wale column ko convert karne se mana kar deti
       hai; script wo skip kar ke aage barh jati hai (theek hai), magar
       phir `tenants.id` aur `invoices.tenant_id` alag collation par reh
       jate aur har JOIN chalte hue toot jata. Ab deploy log mein
       `COLLATION_OK` likha hoga — ya kaun si columns peeche hain. */
    ['script' => 'check_collation',       'optional' => true, 'check' => true],
    /* V154 — retail ki wiring ka pehra. V153 ke audit mein teen bug mile
       aur teeno ki shakal ek thi: ek fehrist do jagah likhi hui, dono
       chup chaap alag ho gayin. Screen khulti thi, "Saved" aata tha,
       peeche kuch nahi hota tha — aur koi error kahin nazar nahi aata
       tha. Yeh script wahi milan har deploy par kar deti hai. */
    ['script' => 'check_retail_wiring',   'optional' => true, 'check' => true],
    /* V172 — tooti hui stock wiring ka pehra. Asal customer par
       Complete Payment par FK error aaya tha kyunke ek recipe aise
       inventory item ki taraf ishara kar rahi thi jo mojood hi nahi
       tha; bill band hi nahi hota tha. Bill ab band ho jata hai, magar
       us item ka stock katna band ho jata hai — is liye har deploy par
       saaf likh diya jata hai ke kaun si recipe theek karni hai. */
    ['script' => 'check_stock_links',     'optional' => true, 'check' => true],
];

// build: V175 build 2026-10-29
