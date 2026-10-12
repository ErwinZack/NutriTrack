-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 11, 2026 at 05:39 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `nutri_track_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `admin_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin') NOT NULL DEFAULT 'admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `profile_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `log_id` int(11) NOT NULL,
  `role` varchar(20) NOT NULL COMMENT 'Who performed the action: admin or health_worker',
  `user_id` int(11) NOT NULL COMMENT 'ID of the admin or health worker',
  `action` varchar(100) NOT NULL COMMENT 'Short action name e.g. LOGIN, ADD_RESIDENT, DELETE_RECORD',
  `description` text DEFAULT NULL COMMENT 'Longer description of what happened',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP address of the user at the time of action',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'When the action happened'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_otp`
--

CREATE TABLE `email_otp` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_role` varchar(20) NOT NULL,
  `otp_code` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `health_records`
--

CREATE TABLE `health_records` (
  `record_id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `worker_id` int(11) NOT NULL,
  `record_date` date NOT NULL,
  `weight` decimal(5,2) NOT NULL,
  `height` decimal(5,2) NOT NULL,
  `bmi` decimal(5,2) GENERATED ALWAYS AS (`weight` / pow(`height` / 100,2)) STORED,
  `status` varchar(50) DEFAULT NULL,
  `recommendation` text DEFAULT NULL,
  `tip` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ai_status` varchar(50) DEFAULT NULL,
  `ai_recommendation` text DEFAULT NULL,
  `ai_tip` text DEFAULT NULL,
  `ai_generated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `health_workers`
--

CREATE TABLE `health_workers` (
  `worker_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('health_worker') NOT NULL DEFAULT 'health_worker',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `date_hired` date DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `attempts` int(11) DEFAULT 0,
  `lockout_until` datetime DEFAULT NULL,
  `last_attempt` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `recipient_id` int(11) NOT NULL COMMENT 'worker_id or admin_id depending on role',
  `recipient_role` enum('health_worker','admin') NOT NULL COMMENT 'Which role this notification belongs to',
  `sender_id` int(11) DEFAULT NULL,
  `sender_role` enum('health_worker','admin') DEFAULT NULL,
  `type` enum('info','warning','success') NOT NULL DEFAULT 'info' COMMENT 'Controls the icon and color in the UI',
  `message` varchar(255) NOT NULL COMMENT 'Short notification text shown in dropdown',
  `is_read` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = unread, 1 = read',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `residents`
--

CREATE TABLE `residents` (
  `resident_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `birth_date` date NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL,
  `system_name` varchar(100) NOT NULL DEFAULT 'NutriTrack',
  `contact_email` varchar(100) DEFAULT NULL,
  `barangay_name` varchar(100) NOT NULL DEFAULT 'Barangay Calasiao',
  `municipality` varchar(100) NOT NULL DEFAULT 'San Miguel',
  `province` varchar(100) NOT NULL DEFAULT 'Pangasinan',
  `health_center` varchar(150) NOT NULL DEFAULT 'Barangay Health Center',
  `pdf_prepared_by` varchar(100) NOT NULL DEFAULT 'Admin',
  `pdf_noted_by` varchar(100) NOT NULL DEFAULT 'Barangay Health Officer',
  `pdf_noted_by_title` varchar(100) NOT NULL DEFAULT 'Barangay Health Officer',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`log_id`);

--
-- Indexes for table `email_otp`
--
ALTER TABLE `email_otp`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `health_records`
--
ALTER TABLE `health_records`
  ADD PRIMARY KEY (`record_id`),
  ADD KEY `resident_id` (`resident_id`),
  ADD KEY `worker_id` (`worker_id`);

--
-- Indexes for table `health_workers`
--
ALTER TABLE `health_workers`
  ADD PRIMARY KEY (`worker_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_recipient` (`recipient_id`,`recipient_role`),
  ADD KEY `idx_is_read` (`is_read`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `residents`
--
ALTER TABLE `residents`
  ADD PRIMARY KEY (`resident_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=979;

--
-- AUTO_INCREMENT for table `email_otp`
--
ALTER TABLE `email_otp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=215;

--
-- AUTO_INCREMENT for table `health_records`
--
ALTER TABLE `health_records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `health_workers`
--
ALTER TABLE `health_workers`
  MODIFY `worker_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=163;

--
-- AUTO_INCREMENT for table `residents`
--
ALTER TABLE `residents`
  MODIFY `resident_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `health_records`
--
ALTER TABLE `health_records`
  ADD CONSTRAINT `health_records_ibfk_1` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `health_records_ibfk_2` FOREIGN KEY (`worker_id`) REFERENCES `health_workers` (`worker_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;








-- ============================================================
-- MFA Cooldown Migration
-- NutriTrack System
-- Description: Adds trusted device/session columns to admins
--              and health_workers tables, and adds mfa_cooldown_hours
--              setting to system_settings table.
-- Run this file once via phpMyAdmin or MySQL CLI.
-- ============================================================

-- ------------------------------------------------------------
-- 1. Add MFA trust columns to `admins` table
-- ------------------------------------------------------------
ALTER TABLE `admins`
  ADD COLUMN `mfa_trusted_token` VARCHAR(64) DEFAULT NULL 
    COMMENT 'Secure random token stored in browser cookie for MFA trust',
  ADD COLUMN `mfa_trusted_until` DATETIME DEFAULT NULL 
    COMMENT 'Timestamp when the MFA trust expires (default: 8 hours from last verified)';

-- ------------------------------------------------------------
-- 2. Add MFA trust columns to `health_workers` table
-- ------------------------------------------------------------
ALTER TABLE `health_workers`
  ADD COLUMN `mfa_trusted_token` VARCHAR(64) DEFAULT NULL 
    COMMENT 'Secure random token stored in browser cookie for MFA trust',
  ADD COLUMN `mfa_trusted_until` DATETIME DEFAULT NULL 
    COMMENT 'Timestamp when the MFA trust expires (default: 8 hours from last verified)';

-- ------------------------------------------------------------
-- 3. Add MFA cooldown duration setting to `system_settings`
--    Default: 8 hours (recommended for health worker shift coverage)
-- ------------------------------------------------------------
ALTER TABLE `system_settings`
  ADD COLUMN `mfa_cooldown_hours` INT(11) NOT NULL DEFAULT 8 
    COMMENT 'MFA cooldown duration in hours. Admin-configurable. Default is 8 hours.';

-- ------------------------------------------------------------
-- Done! Verify with:
-- DESCRIBE admins;
-- DESCRIBE health_workers;
-- DESCRIBE system_settings;
-- ------------------------------------------------------------