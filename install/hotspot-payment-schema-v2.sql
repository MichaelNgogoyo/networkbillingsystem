-- Hotspot Payment Integration Schema
-- Run this SQL on your PHPNuxBill database

-- Hotspot Guest Sessions (Trial and temporary paid users)
DROP TABLE IF EXISTS `hotspot_guest_sessions`;
CREATE TABLE `hotspot_guest_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(15) NOT NULL,
  `username` varchar(100) NOT NULL,
  `access_type` enum('trial','paid') NOT NULL DEFAULT 'trial',
  `mac_address` varchar(17),
  `device_ip` varchar(15),
  `plan` varchar(100),
  `duration` varchar(50),
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime,
  `session_id` varchar(255),
  `status` enum('active','expired','revoked') DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username_unique` (`username`),
  KEY `phone_idx` (`phone`),
  KEY `expires_at_idx` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hotspot Users (registered users for password management)
DROP TABLE IF EXISTS `hotspot_users`;
CREATE TABLE `hotspot_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(15) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(100),
  `last_name` varchar(100),
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime,
  `status` enum('active','inactive','blocked') DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone_unique` (`phone`),
  UNIQUE KEY `email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User Sessions (for auth tracking)
DROP TABLE IF EXISTS `hotspot_user_sessions`;
CREATE TABLE `hotspot_user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `session_id` varchar(255) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime,
  `last_activity` datetime,
  `ip_address` varchar(15),
  `user_agent` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id_unique` (`session_id`),
  KEY `user_id_idx` (`user_id`),
  KEY `phone_idx` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment Sessions (M-Pesa transactions)
DROP TABLE IF EXISTS `hotspot_payment_sessions`;
CREATE TABLE `hotspot_payment_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(15) NOT NULL,
  `amount` decimal(10, 2) NOT NULL,
  `plan` varchar(100) NOT NULL,
  `duration` varchar(50) NOT NULL,
  `reference` varchar(100) NOT NULL,
  `checkout_id` varchar(100),
  `mac_address` varchar(100),
  `device_ip` varchar(50),
  `status` enum('pending','completed','failed','timeout') DEFAULT 'pending',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime,
  `error_message` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_unique` (`reference`),
  KEY `phone_idx` (`phone`),
  KEY `status_idx` (`status`),
  KEY `created_at_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Trial Sessions (separate tracking for trial usage)
DROP TABLE IF EXISTS `hotspot_trial_sessions`;
CREATE TABLE `hotspot_trial_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(15) NOT NULL,
  `username` varchar(100) NOT NULL,
  `trial_count` int(11) DEFAULT 1,
  `total_trial_minutes` int(11) DEFAULT 0,
  `last_trial_at` datetime,
  `first_trial_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone_unique` (`phone`),
  KEY `phone_idx` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
