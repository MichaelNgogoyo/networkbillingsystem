-- Hotspot Payment Sessions Table
-- Tracks all M-Pesa payments initiated from hotspot login page

CREATE TABLE IF NOT EXISTS `hotspot_payment_sessions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `phone` VARCHAR(20) NOT NULL COMMENT 'Customer phone number (254XXXXXXXXX)',
    `amount` INT UNSIGNED NOT NULL COMMENT 'Amount in KES',
    `plan` VARCHAR(100) NOT NULL COMMENT 'Package name (e.g., "1 Hour - 10 KES")',
    `duration` VARCHAR(50) NOT NULL COMMENT 'Duration (e.g., "1H", "7D", "1M")',
    `system` VARCHAR(50) DEFAULT 'unlimited' COMMENT 'System type or speed tier',
    `reference` VARCHAR(100) NOT NULL COMMENT 'Internal payment reference (WIFI_TIMESTAMP_MAC)',
    `checkout_id` VARCHAR(100) UNIQUE COMMENT 'M-Pesa CheckoutRequestID',
    `mac_address` VARCHAR(50) NOT NULL COMMENT 'Device MAC address',
    `ip_address` VARCHAR(50) NOT NULL COMMENT 'Device IP address from MikroTik',
    `status` ENUM('pending', 'completed', 'failed', 'expired') DEFAULT 'pending' COMMENT 'Payment status',
    `mpesa_receipt` VARCHAR(100) COMMENT 'M-Pesa receipt number on successful payment',
    `transaction_date` VARCHAR(50) COMMENT 'M-Pesa transaction timestamp',
    `transaction_amount` DECIMAL(10,2) COMMENT 'Amount actually paid',
    `hotspot_username` VARCHAR(100) COMMENT 'Temporary MikroTik username created after payment',
    `hotspot_password` VARCHAR(255) COMMENT 'Temporary MikroTik password (hashed or encrypted)',
    `failure_reason` TEXT COMMENT 'Reason for payment failure',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY `idx_phone` (`phone`),
    KEY `idx_checkout_id` (`checkout_id`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_mac_address` (`mac_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='M-Pesa payment sessions from hotspot login page';


-- Hotspot Guest Sessions Table  
-- Tracks temporary guest users created via paid packages

CREATE TABLE IF NOT EXISTS `hotspot_guest_sessions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `phone` VARCHAR(20) NOT NULL COMMENT 'Customer phone number',
    `username` VARCHAR(100) NOT NULL UNIQUE COMMENT 'MikroTik hotspot username',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'Hashed password for MikroTik',
    `plan` VARCHAR(100) NOT NULL COMMENT 'Package purchased',
    `duration` VARCHAR(50) NOT NULL COMMENT 'Access duration',
    `system` VARCHAR(50) DEFAULT 'unlimited' COMMENT 'Speed/system tier',
    `mac_address` VARCHAR(50) COMMENT 'Device MAC address',
    `ip_address` VARCHAR(50) COMMENT 'Device IP address',
    `router_name` VARCHAR(100) COMMENT 'MikroTik router identity',
    `payment_ref` VARCHAR(100) COMMENT 'Reference to payment_sessions table',
    `status` ENUM('active', 'expired', 'revoked') DEFAULT 'active' COMMENT 'User account status',
    `data_used` BIGINT DEFAULT 0 COMMENT 'Data used in bytes (if tracking)',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP COMMENT 'When access expires',
    `last_activity` TIMESTAMP COMMENT 'Last connection time',
    
    KEY `idx_phone` (`phone`),
    KEY `idx_username` (`username`),
    KEY `idx_status` (`status`),
    KEY `idx_expires_at` (`expires_at`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Active hotspot guest sessions from M-Pesa payments';


-- Trial Sessions Table
-- Tracks free trial access

CREATE TABLE IF NOT EXISTS `hotspot_trial_sessions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `phone` VARCHAR(20) COMMENT 'Customer phone (optional)',
    `username` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Trial username',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'Hashed password',
    `mac_address` VARCHAR(50) NOT NULL COMMENT 'Device MAC',
    `ip_address` VARCHAR(50) NOT NULL COMMENT 'Device IP',
    `duration` VARCHAR(50) NOT NULL COMMENT 'Trial duration',
    `status` ENUM('active', 'expired', 'revoked') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP COMMENT 'Trial expiry time',
    
    KEY `idx_username` (`username`),
    KEY `idx_status` (`status`),
    KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Free trial hotspot access sessions';
