-- Hotspot Payment Integration Schema
-- Run this SQL on your PHPNuxBill database

-- Hotspot Guest Sessions (Trial and temporary paid users)
CREATE TABLE IF NOT EXISTS `hotspot_guest_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(15) NOT NULL,
  `username` varchar(100) NOT NULL UNIQUE,
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
  UNIQUE KEY `username` (`username`),
  KEY `phone` (`phone`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hotspot Users (registered users for password management)
CREATE TABLE IF NOT EXISTS `hotspot_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(15) NOT NULL UNIQUE,
  `email` varchar(100) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(100),
  `last_name` varchar(100),
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime,
  `status` enum('active','inactive','blocked') DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User Sessions (for auth tracking)
CREATE TABLE IF NOT EXISTS `hotspot_user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `session_id` varchar(255) NOT NULL UNIQUE,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime,
  `last_activity` datetime,
  `ip_address` varchar(15),
  `user_agent` text,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `phone` (`phone`),
  KEY `session_id` (`session_id`),
  FOREIGN KEY (`user_id`) REFERENCES `hotspot_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment Sessions (M-Pesa transactions)
CREATE TABLE IF NOT EXISTS `hotspot_payment_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(15) NOT NULL,
  `amount` decimal(10, 2) NOT NULL,
  `plan` varchar(100) NOT NULL,
  `duration` varchar(50) NOT NULL,
  `reference` varchar(100) NOT NULL UNIQUE,
  `checkout_id` varchar(100),
  `mac_address` varchar(17),
  `device_ip` varchar(15),
  `status` enum('pending','completed','failed','timeout') DEFAULT 'pending',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime,
  `error_message` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  KEY `phone` (`phone`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Trial Sessions (separate tracking for trial usage)
CREATE TABLE IF NOT EXISTS `hotspot_trial_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(15) NOT NULL,
  `username` varchar(100) NOT NULL,
  `trial_count` int(11) DEFAULT 1,
  `total_trial_minutes` int(11) DEFAULT 0,
  `last_trial_at` datetime,
  `first_trial_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`),
  KEY `phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
