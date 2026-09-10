-- Migration: Create Trial System for Hotspot
-- Date: 2026-06-01
-- Purpose: Set up trial plan and MAC address tracking for hotspot users

SET FOREIGN_KEY_CHECKS=0;

-- 1. Create bandwidth profile for trial (if not exists)
INSERT INTO tbl_bandwidth (id, name_bw, rate_down, rate_down_unit, rate_up, rate_up_unit, burst)
SELECT 999, 'Trial 512Kbps', 512, 'Kbps', 512, 'Kbps', '512k' 
WHERE NOT EXISTS (SELECT 1 FROM tbl_bandwidth WHERE name_bw = 'Trial 512Kbps');

-- 2. Create trial plan (10 minutes, Hotspot, unlimited data)
-- This plan will be used for free trial users
INSERT INTO tbl_plans (id, name_plan, id_bw, price, type, typebp, limit_type, time_limit, time_unit, validity, validity_unit, routers, is_radius, prepaid, plan_type, enabled)
SELECT 
  9999,
  'Trial - 10 Minutes Free',
  999,
  '0',
  'Hotspot',
  'Limited',
  'Time_Limit',
  10,
  'Mins',
  10,
  'Mins',
  '192.168.88.1',
  1,
  'no',
  'Personal',
  1
WHERE NOT EXISTS (SELECT 1 FROM tbl_plans WHERE name_plan = 'Trial - 10 Minutes Free');

-- 3. Create MAC address tracking table for 30-day trial prevention
DROP TABLE IF EXISTS `tbl_hotspot_mac_trial`;
CREATE TABLE `tbl_hotspot_mac_trial` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `mac_address` varchar(17) COLLATE utf8mb4_general_ci NOT NULL UNIQUE COMMENT 'Device MAC address',
  `customer_id` int NOT NULL COMMENT 'Customer who used trial',
  `used_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When trial was used',
  `expires_date` datetime NOT NULL COMMENT '30 days after used_date',
  `status` enum('active', 'expired') COLLATE utf8mb4_general_ci DEFAULT 'active',
  FOREIGN KEY (customer_id) REFERENCES tbl_customers(id) ON DELETE CASCADE,
  KEY idx_mac (mac_address),
  KEY idx_expires (expires_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Tracks MAC addresses that used trial to prevent abuse';

-- 4. Create phone number index for customer lookup
ALTER TABLE tbl_customers ADD INDEX idx_phone (phonenumber(15));

-- 5. Widen password columns to fit bcrypt hashes (60 chars) used by hotspot PIN auth
ALTER TABLE tbl_customers MODIFY COLUMN password VARCHAR(255) NOT NULL;
ALTER TABLE tbl_customers MODIFY COLUMN pppoe_password VARCHAR(255) NOT NULL DEFAULT '';

SET FOREIGN_KEY_CHECKS=1;
