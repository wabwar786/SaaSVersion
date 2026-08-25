
-- =========================================================
-- BASIC SEED DATA FOR FIRST RESTAURANT TENANT / SITE
-- Run after schema on either cloud or local.
-- Password hashes are intentionally NOT seeded here.
-- Application should create the first admin with Argon2id.
-- =========================================================

SET NAMES utf8mb4;

SET @tenant_id = '11111111-1111-1111-1111-111111111111';
SET @org_id    = '22222222-2222-2222-2222-222222222222';
SET @site_id   = '33333333-3333-3333-3333-333333333333';

INSERT INTO tenants(id, code, name, timezone, default_currency)
VALUES (@tenant_id, 'URBAN-SPOON', 'Urban Spoon', 'Asia/Karachi', 'PKR')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO organizations(id, tenant_id, organization_type, industry_code, name)
VALUES (@org_id, @tenant_id, 'BUSINESS', 'RESTAURANT', 'Urban Spoon')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO sites(id, tenant_id, organization_id, code, name, site_type, timezone, currency)
VALUES (@site_id, @tenant_id, @org_id, 'ISB-F10', 'Islamabad — F10', 'BRANCH', 'Asia/Karachi', 'PKR')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO units(id, code, name, unit_type, decimal_places) VALUES
('00000000-0000-0000-0000-000000000001','G','Gram','WEIGHT',3),
('00000000-0000-0000-0000-000000000002','KG','Kilogram','WEIGHT',3),
('00000000-0000-0000-0000-000000000003','ML','Millilitre','VOLUME',3),
('00000000-0000-0000-0000-000000000004','L','Litre','VOLUME',3),
('00000000-0000-0000-0000-000000000005','PCS','Piece','COUNT',0)
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO platform_modules(id,module_key,name,industry_code,sort_order) VALUES
('10000000-0000-0000-0000-000000000001','dashboard','Dashboard','RESTAURANT',1),
('10000000-0000-0000-0000-000000000002','shift','Opening & Closing Shift','RESTAURANT',2),
('10000000-0000-0000-0000-000000000003','pos','Sale Point / POS','RESTAURANT',3),
('10000000-0000-0000-0000-000000000004','tablet','Order Taker Tablet','RESTAURANT',4),
('10000000-0000-0000-0000-000000000005','kds','Kitchen / KDS','RESTAURANT',5),
('10000000-0000-0000-0000-000000000006','tables','Tables & Floors','RESTAURANT',6),
('10000000-0000-0000-0000-000000000007','orders','Running Orders','RESTAURANT',7),
('10000000-0000-0000-0000-000000000008','online','Online Orders','RESTAURANT',8),
('10000000-0000-0000-0000-000000000009','inventory','Inventory','RESTAURANT',9),
('10000000-0000-0000-0000-000000000010','purchasing','Purchasing','RESTAURANT',10),
('10000000-0000-0000-0000-000000000011','recipe','Recipe & Food Cost','RESTAURANT',11),
('10000000-0000-0000-0000-000000000012','menu','Menu & Categories','RESTAURANT',12),
('10000000-0000-0000-0000-000000000013','wastage','Wastage / Adjustment','RESTAURANT',13),
('10000000-0000-0000-0000-000000000014','transfer','Stock Transfer','RESTAURANT',14),
('10000000-0000-0000-0000-000000000015','count','Physical Stock Count','RESTAURANT',15),
('10000000-0000-0000-0000-000000000016','suppliers','Suppliers','RESTAURANT',16),
('10000000-0000-0000-0000-000000000017','customers','Customers','RESTAURANT',17),
('10000000-0000-0000-0000-000000000018','delivery','Delivery','RESTAURANT',18),
('10000000-0000-0000-0000-000000000019','riders','Rider Management','RESTAURANT',19),
('10000000-0000-0000-0000-000000000020','reservations','Reservations','RESTAURANT',20),
('10000000-0000-0000-0000-000000000021','loyalty','Loyalty / Membership','RESTAURANT',21),
('10000000-0000-0000-0000-000000000022','expenses','Expenses','RESTAURANT',22),
('10000000-0000-0000-0000-000000000023','accounting','Accounting / Cash','RESTAURANT',23),
('10000000-0000-0000-0000-000000000024','promotions','Discounts / Promotions','RESTAURANT',24),
('10000000-0000-0000-0000-000000000025','staff','Staff / Roles','RESTAURANT',25),
('10000000-0000-0000-0000-000000000026','void','Void / Refund','RESTAURANT',26),
('10000000-0000-0000-0000-000000000027','reports','Reports','RESTAURANT',27),
('10000000-0000-0000-0000-000000000028','fbr','FBR / Digital Invoice','RESTAURANT',28),
('10000000-0000-0000-0000-000000000029','printers','Printers / Devices','RESTAURANT',29),
('10000000-0000-0000-0000-000000000030','offline','Offline / Sync','RESTAURANT',30),
('10000000-0000-0000-0000-000000000031','users','Users & Access',NULL,31),
('10000000-0000-0000-0000-000000000032','settings','Settings',NULL,32)
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO stock_locations(id, tenant_id, site_id, code, name, location_type) VALUES
('40000000-0000-0000-0000-000000000001',@tenant_id,@site_id,'DRY','Dry Store','STORE'),
('40000000-0000-0000-0000-000000000002',@tenant_id,@site_id,'COLD','Cold Room','COLD_ROOM'),
('40000000-0000-0000-0000-000000000003',@tenant_id,@site_id,'FREEZER','Freezer','FREEZER'),
('40000000-0000-0000-0000-000000000004',@tenant_id,@site_id,'BAR','Bar Store','BAR')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO payment_methods(id, tenant_id, site_id, code, name, method_type) VALUES
('50000000-0000-0000-0000-000000000001',@tenant_id,@site_id,'CASH','Cash','CASH'),
('50000000-0000-0000-0000-000000000002',@tenant_id,@site_id,'CARD','Card','CARD'),
('50000000-0000-0000-0000-000000000003',@tenant_id,@site_id,'RAAST','Raast','BANK'),
('50000000-0000-0000-0000-000000000004',@tenant_id,@site_id,'EASYPAISA','Easypaisa','WALLET'),
('50000000-0000-0000-0000-000000000005',@tenant_id,@site_id,'JAZZCASH','JazzCash','WALLET'),
('50000000-0000-0000-0000-000000000006',@tenant_id,@site_id,'BANK','Bank Transfer','BANK'),
('50000000-0000-0000-0000-000000000007',@tenant_id,@site_id,'COD','Cash on Delivery','COD')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Printer routing example
INSERT INTO printers(id,tenant_id,site_id,name,printer_type,station_code,connection_type,ip_address,port_no,paper_width_mm) VALUES
('60000000-0000-0000-0000-000000000001',@tenant_id,@site_id,'Main Kitchen Printer','KITCHEN','MAIN','NETWORK','192.168.10.21',9100,80),
('60000000-0000-0000-0000-000000000002',@tenant_id,@site_id,'BBQ Printer','KITCHEN','BBQ','NETWORK','192.168.10.22',9100,80),
('60000000-0000-0000-0000-000000000003',@tenant_id,@site_id,'Pizza Printer','KITCHEN','PIZZA','NETWORK','192.168.10.23',9100,80),
('60000000-0000-0000-0000-000000000004',@tenant_id,@site_id,'Drinks Printer','KITCHEN','DRINKS','NETWORK','192.168.10.24',9100,80),
('60000000-0000-0000-0000-000000000005',@tenant_id,@site_id,'Dessert Printer','KITCHEN','DESSERT','NETWORK','192.168.10.25',9100,80)
ON DUPLICATE KEY UPDATE ip_address=VALUES(ip_address);

INSERT INTO menu_categories(id,tenant_id,site_id,name,sort_order) VALUES
('70000000-0000-0000-0000-000000000001',@tenant_id,@site_id,'Pakistani',1),
('70000000-0000-0000-0000-000000000002',@tenant_id,@site_id,'Pizza',2),
('70000000-0000-0000-0000-000000000003',@tenant_id,@site_id,'BBQ',3),
('70000000-0000-0000-0000-000000000004',@tenant_id,@site_id,'Fast Food',4),
('70000000-0000-0000-0000-000000000005',@tenant_id,@site_id,'Drinks',5),
('70000000-0000-0000-0000-000000000006',@tenant_id,@site_id,'Desserts',6),
('70000000-0000-0000-0000-000000000007',@tenant_id,@site_id,'Sides',7)
ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order);

INSERT INTO menu_category_printer_routes(id,tenant_id,site_id,category_id,printer_id,is_primary) VALUES
('71000000-0000-0000-0000-000000000001',@tenant_id,@site_id,'70000000-0000-0000-0000-000000000001','60000000-0000-0000-0000-000000000001',1),
('71000000-0000-0000-0000-000000000002',@tenant_id,@site_id,'70000000-0000-0000-0000-000000000002','60000000-0000-0000-0000-000000000003',1),
('71000000-0000-0000-0000-000000000003',@tenant_id,@site_id,'70000000-0000-0000-0000-000000000003','60000000-0000-0000-0000-000000000002',1),
('71000000-0000-0000-0000-000000000004',@tenant_id,@site_id,'70000000-0000-0000-0000-000000000004','60000000-0000-0000-0000-000000000001',1),
('71000000-0000-0000-0000-000000000005',@tenant_id,@site_id,'70000000-0000-0000-0000-000000000005','60000000-0000-0000-0000-000000000004',1),
('71000000-0000-0000-0000-000000000006',@tenant_id,@site_id,'70000000-0000-0000-0000-000000000006','60000000-0000-0000-0000-000000000005',1),
('71000000-0000-0000-0000-000000000007',@tenant_id,@site_id,'70000000-0000-0000-0000-000000000007','60000000-0000-0000-0000-000000000001',1)
ON DUPLICATE KEY UPDATE is_primary=VALUES(is_primary);

-- build: V17.1 build 2026-08-25
