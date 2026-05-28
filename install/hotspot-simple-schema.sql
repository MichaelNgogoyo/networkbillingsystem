-- Simplified Hotspot Authentication Schema
-- Replaces previous complex schema with simple phone + PIN authentication

-- Drop old tables if they exist
DROP TABLE IF EXISTS `hotspot_payment_sessions`;
DROP TABLE IF EXISTS `hotspot_user_sessions`;
DROP TABLE IF EXISTS `hotspot_trial_sessions`;
DROP TABLE IF EXISTS `hotspot_guest_sessions`;
DROP TABLE IF EXISTS `hotspot_users`;

-- Simple users table: phone + PIN only
CREATE TABLE `hotspot_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(15) NOT NULL UNIQUE,
  `pin` varchar(255) NOT NULL,
  `trial_used` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive','blocked') DEFAULT 'active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone_unique` (`phone`),
  KEY `phone_idx` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User sessions (for auth tracking)
CREATE TABLE `hotspot_user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `session_id` varchar(255) NOT NULL UNIQUE,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime,
  `ip_address` varchar(15),
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id_unique` (`session_id`),
  KEY `user_id_idx` (`user_id`),
  KEY `phone_idx` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Guest sessions (trial and paid hotspot users)
CREATE TABLE `hotspot_guest_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(15) NOT NULL,
  `username` varchar(100) NOT NULL UNIQUE,
  `access_type` enum('trial','paid') NOT NULL DEFAULT 'trial',
  `mac_address` varchar(17),
  `device_ip` varchar(15),
  `plan` varchar(100),
  `session_id` varchar(255),
  `status` enum('active','expired','revoked') DEFAULT 'active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime,
  PRIMARY KEY (`id`),
  KEY `phone_idx` (`phone`),
  KEY `expires_at_idx` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
