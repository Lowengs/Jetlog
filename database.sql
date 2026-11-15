-- ==============================================================================
-- JetLog: Aircraft Maintenance Tracking and Management System
-- Database Schema & Seed Data
-- ==============================================================================
-- Target RDBMS: MySQL 5.7+ / 8.0+ / MariaDB 10.4+ (XAMPP Environment)
-- Database Name: jetlog_db
-- Character Set: utf8mb4 / utf8mb4_unicode_ci
-- Based on: JetLog UI Wireframes & Specifications
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `jetlog_db`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `jetlog_db`;

-- Disable foreign key checks during schema creation and data loading
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. Table: users
-- Supports system authentication, technician assignment, and audit trails
-- Reference: Page 1 (Login: Email & Password) & sidebar user "demo.technician"
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `maintenance_logbook`;
DROP TABLE IF EXISTS `work_order_status_history`;
DROP TABLE IF EXISTS `work_orders`;
DROP TABLE IF EXISTS `maintenance_schedules`;
DROP TABLE IF EXISTS `discrepancies`;
DROP TABLE IF EXISTS `discrepancy_categories`;
DROP TABLE IF EXISTS `aircraft`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `user_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(120) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(50) NOT NULL,
  `last_name` VARCHAR(50) NOT NULL,
  `role` ENUM('Administrator', 'Maintenance Supervisor', 'Lead Technician', 'Technician', 'Avionics Specialist', 'Inspector') NOT NULL DEFAULT 'Technician',
  `phone` VARCHAR(30) NULL,
  `status` ENUM('Active', 'Inactive', 'Suspended') NOT NULL DEFAULT 'Active',
  `last_login` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. Table: password_resets
-- Supports "Forgot password?" feature on the login screen
-- Reference: Page 1 (Forgot password?)
-- ------------------------------------------------------------------------------
CREATE TABLE `password_resets` (
  `reset_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(120) NOT NULL,
  `token` VARCHAR(100) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reset_id`),
  INDEX `idx_reset_email` (`email`),
  INDEX `idx_reset_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. Table: aircraft
-- Core registry of all aircraft in the fleet
-- Reference: Page 2, 3 (Aircraft Registry), Page 4 (Aircraft Profile)
-- Total Fleet: 24 aircraft (15 Available, 6 Under Maintenance, 3 Inspection Due)
-- ------------------------------------------------------------------------------
CREATE TABLE `aircraft` (
  `aircraft_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `registration_number` VARCHAR(20) NOT NULL UNIQUE COMMENT 'e.g. RP-C1234, RP-C5678',
  `model` VARCHAR(100) NOT NULL COMMENT 'e.g. Cessna 172, ATR 72, Airbus A320, CRJ 900, PA-28 Cherokee',
  `manufacturer` VARCHAR(100) NOT NULL COMMENT 'Airbus, ATR, Bombardier, Cessna, Piper',
  `status` ENUM('Available', 'Under Maintenance', 'Inspection Due', 'AOG', 'Decommissioned') NOT NULL DEFAULT 'Available',
  `serial_number` VARCHAR(60) NULL,
  `year_manufactured` YEAR NULL,
  `total_flight_hours` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `total_flight_cycles` INT UNSIGNED NOT NULL DEFAULT 0,
  `location` VARCHAR(100) NOT NULL DEFAULT 'Hangar 1',
  `qr_code_token` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Unique token encoded into QR code for mobile scanning',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`aircraft_id`),
  INDEX `idx_aircraft_reg` (`registration_number`),
  INDEX `idx_aircraft_status` (`status`),
  INDEX `idx_aircraft_manufacturer` (`manufacturer`),
  INDEX `idx_aircraft_model` (`model`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. Table: discrepancy_categories
-- Reference: discrepancy.html & reports.html (Avionics, Engine, Landing Gear, Hydraulics, Structure, Other)
-- ------------------------------------------------------------------------------
CREATE TABLE `discrepancy_categories` (
  `category_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_name` VARCHAR(50) NOT NULL UNIQUE,
  `description` VARCHAR(255) NULL,
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. Table: discrepancies
-- Discrepancies reported by pilots, flight crew, and technicians
-- Reference: Page 2 (Quick Action: Report Discrepancy), discrepancy.html
-- ------------------------------------------------------------------------------
CREATE TABLE `discrepancies` (
  `discrepancy_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `discrepancy_number` VARCHAR(30) NOT NULL UNIQUE COMMENT 'e.g. DISC-2026-001',
  `aircraft_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `description` TEXT NOT NULL,
  `priority` ENUM('Low', 'Medium', 'High', 'Critical') NOT NULL DEFAULT 'Medium',
  `status` ENUM('Reported', 'Assigned', 'In Progress', 'Resolved', 'Deferred', 'Closed') NOT NULL DEFAULT 'Reported',
  `reported_by_user_id` INT UNSIGNED NOT NULL,
  `date_reported` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`discrepancy_id`),
  CONSTRAINT `fk_discrepancy_aircraft` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`aircraft_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_discrepancy_category` FOREIGN KEY (`category_id`) REFERENCES `discrepancy_categories` (`category_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_discrepancy_user` FOREIGN KEY (`reported_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX `idx_discrepancy_priority` (`priority`),
  INDEX `idx_discrepancy_status` (`status`),
  INDEX `idx_discrepancy_date` (`date_reported`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. Table: work_orders
-- Maintenance work orders generated from discrepancies or scheduled checks
-- Reference: Page 2 (Recent Work Orders), work-orders.html, work-order-details.html
-- Workflow status: Open -> Assigned -> In Progress -> For Verification -> Completed
-- ------------------------------------------------------------------------------
CREATE TABLE `work_orders` (
  `work_order_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_order_number` VARCHAR(30) NOT NULL UNIQUE COMMENT 'e.g. WO-2026-001',
  `aircraft_id` INT UNSIGNED NOT NULL,
  `discrepancy_id` INT UNSIGNED NULL,
  `issue_summary` VARCHAR(255) NOT NULL,
  `priority` ENUM('Low', 'Medium', 'High', 'Critical') NOT NULL DEFAULT 'Medium',
  `assigned_technician_id` INT UNSIGNED NULL,
  `status` ENUM('Open', 'Assigned', 'In Progress', 'For Verification', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Open',
  `date_created` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `completed_date` DATE NULL,
  `technician_remarks` TEXT NULL,
  `maintenance_action_performed` TEXT NULL,
  `verified_by_user_id` INT UNSIGNED NULL,
  `verified_at` DATETIME NULL,
  `qr_code_token` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Hangar floor work order QR tracking token',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`work_order_id`),
  CONSTRAINT `fk_wo_aircraft` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`aircraft_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_wo_discrepancy` FOREIGN KEY (`discrepancy_id`) REFERENCES `discrepancies` (`discrepancy_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_wo_technician` FOREIGN KEY (`assigned_technician_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_wo_verifier` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_wo_status` (`status`),
  INDEX `idx_wo_priority` (`priority`),
  INDEX `idx_wo_created` (`date_created`),
  INDEX `idx_wo_due` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. Table: work_order_status_history
-- Records every status transition for traceability and hangar floor flow
-- Reference: work-order-details.html (Progress Flow: Reported -> Assigned -> In Progress -> For Verification -> Completed)
-- ------------------------------------------------------------------------------
CREATE TABLE `work_order_status_history` (
  `history_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_order_id` INT UNSIGNED NOT NULL,
  `status` ENUM('Open', 'Reported', 'Assigned', 'In Progress', 'For Verification', 'Completed', 'Cancelled') NOT NULL,
  `changed_by_user_id` INT UNSIGNED NULL,
  `remarks` TEXT NULL,
  `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  CONSTRAINT `fk_history_wo` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`work_order_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_history_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_history_wo` (`work_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. Table: maintenance_schedules
-- Scheduled inspections, routine checks, and time/cycle controlled tasks
-- Reference: Page 2 (Upcoming Maintenance), schedule.html
-- ------------------------------------------------------------------------------
CREATE TABLE `maintenance_schedules` (
  `schedule_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `aircraft_id` INT UNSIGNED NOT NULL,
  `maintenance_type` VARCHAR(150) NOT NULL COMMENT 'e.g. Routine Inspection, Landing Gear Inspection, Scheduled Maintenance',
  `scheduled_date` DATE NOT NULL,
  `due_date` DATE NULL,
  `status` ENUM('Upcoming', 'Due Soon', 'Overdue', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Upcoming',
  `linked_work_order_id` INT UNSIGNED NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`schedule_id`),
  CONSTRAINT `fk_sched_aircraft` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`aircraft_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sched_wo` FOREIGN KEY (`linked_work_order_id`) REFERENCES `work_orders` (`work_order_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_sched_date` (`scheduled_date`),
  INDEX `idx_sched_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. Table: maintenance_logbook
-- Official digital maintenance logbook entries signed off by certified technicians
-- Reference: Page 4 (Recent Maintenance History), logbook.html
-- ------------------------------------------------------------------------------
CREATE TABLE `maintenance_logbook` (
  `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `aircraft_id` INT UNSIGNED NOT NULL,
  `work_order_id` INT UNSIGNED NULL,
  `activity_type` ENUM('Inspection', 'Discrepancy', 'Repair', 'Completed Work Order', 'Scheduled Maintenance') NOT NULL,
  `technician_id` INT UNSIGNED NOT NULL,
  `log_date` DATE NOT NULL,
  `result_summary` VARCHAR(255) NOT NULL,
  `remarks` TEXT NULL,
  `flight_hours_at_log` DECIMAL(10, 2) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  CONSTRAINT `fk_log_aircraft` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`aircraft_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_log_wo` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`work_order_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_log_technician` FOREIGN KEY (`technician_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX `idx_log_date` (`log_date`),
  INDEX `idx_log_activity` (`activity_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- SQL VIEWS FOR REPORTING & REAL-TIME DASHBOARD
-- ==============================================================================

-- View 1: Real-time Dashboard KPI Metric Cards
-- Powers the 6 dashboard summary cards on Page 2 and reports.html
CREATE OR REPLACE VIEW `vw_dashboard_stats` AS
SELECT
  (SELECT COUNT(*) FROM `aircraft`) AS `total_aircraft`,
  (SELECT COUNT(*) FROM `aircraft` WHERE `status` = 'Available') AS `available_aircraft`,
  (SELECT COUNT(*) FROM `aircraft` WHERE `status` = 'Under Maintenance') AS `maintenance_aircraft`,
  (SELECT COUNT(*) FROM `aircraft` WHERE `status` = 'Inspection Due') AS `inspection_due_aircraft`,
  (SELECT COUNT(*) FROM `work_orders` WHERE `status` IN ('Open', 'Assigned', 'In Progress', 'For Verification')) AS `open_work_orders`,
  (SELECT COUNT(*) FROM `work_orders` WHERE `status` = 'Completed') AS `completed_work_orders`,
  (SELECT COUNT(*) FROM `work_orders`) AS `total_work_orders`,
  (SELECT COUNT(*) FROM `maintenance_schedules` WHERE `status` = 'Overdue') AS `overdue_tasks`,
  (SELECT COUNT(*) FROM `maintenance_schedules` WHERE `status` IN ('Upcoming', 'Due Soon') AND `scheduled_date` BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)) AS `upcoming_inspections`;

-- View 2: Recent Work Orders with Aircraft and Technician details
-- Powers the Recent Work Orders table on Page 2
CREATE OR REPLACE VIEW `vw_recent_work_orders` AS
SELECT
  wo.`work_order_id`,
  wo.`work_order_number`,
  a.`registration_number` AS `aircraft_registration`,
  a.`model` AS `aircraft_model`,
  wo.`issue_summary`,
  wo.`priority`,
  wo.`status`,
  CONCAT(u.`first_name`, ' ', u.`last_name`) AS `assigned_technician`,
  wo.`date_created`,
  wo.`due_date`
FROM `work_orders` wo
JOIN `aircraft` a ON wo.`aircraft_id` = a.`aircraft_id`
LEFT JOIN `users` u ON wo.`assigned_technician_id` = u.`user_id`
ORDER BY (wo.`work_order_number` = 'WO-2026-001') DESC, wo.`date_created` DESC, wo.`work_order_id` DESC;

-- View 3: Upcoming Maintenance Schedule
-- Powers the Upcoming Maintenance table on Page 2 and schedule.html
CREATE OR REPLACE VIEW `vw_upcoming_maintenance` AS
SELECT
  ms.`schedule_id`,
  a.`registration_number` AS `aircraft_registration`,
  a.`model` AS `aircraft_model`,
  ms.`maintenance_type`,
  ms.`scheduled_date`,
  ms.`status`,
  wo.`work_order_number`
FROM `maintenance_schedules` ms
JOIN `aircraft` a ON ms.`aircraft_id` = a.`aircraft_id`
LEFT JOIN `work_orders` wo ON ms.`linked_work_order_id` = wo.`work_order_id`
ORDER BY (a.`registration_number` = 'RP-C1234' AND ms.`maintenance_type` = 'Routine Inspection') DESC, ms.`scheduled_date` ASC;

-- View 4: Work Orders Grouped by Status
-- Powers the Work Orders by Status analytics bar chart on reports.html
CREATE OR REPLACE VIEW `vw_work_orders_by_status` AS
SELECT
  `status`,
  COUNT(*) AS `total_count`,
  ROUND((COUNT(*) / (SELECT COUNT(*) FROM `work_orders`)) * 100, 1) AS `percentage`
FROM `work_orders`
GROUP BY `status`
ORDER BY FIELD(`status`, 'Open', 'Assigned', 'In Progress', 'For Verification', 'Completed', 'Cancelled');

-- View 5: Discrepancies by Category
-- Powers the Common Discrepancy Categories chart on reports.html
CREATE OR REPLACE VIEW `vw_discrepancies_by_category` AS
SELECT
  c.`category_name`,
  COUNT(d.`discrepancy_id`) AS `total_count`,
  ROUND((COUNT(d.`discrepancy_id`) / (SELECT COUNT(*) FROM `discrepancies`)) * 100, 1) AS `percentage`
FROM `discrepancy_categories` c
LEFT JOIN `discrepancies` d ON c.`category_id` = d.`category_id`
GROUP BY c.`category_id`, c.`category_name`
ORDER BY `total_count` DESC;

-- View 6: Maintenance Activities Grouped by Month
-- Powers the Monthly Maintenance Activities chart on reports.html
CREATE OR REPLACE VIEW `vw_monthly_maintenance_activities` AS
SELECT
  DATE_FORMAT(ml.`log_date`, '%Y-%m') AS `year_month`,
  DATE_FORMAT(ml.`log_date`, '%b %Y') AS `month_label`,
  COUNT(*) AS `activity_count`
FROM `maintenance_logbook` ml
GROUP BY `year_month`, `month_label`
ORDER BY `year_month` ASC;

-- ==============================================================================
-- SEED DATA INSERTION
-- Populates all tables with the exact data matching the wireframes & PDF document
-- ==============================================================================

-- 1. Insert Users
-- Default password for all seed accounts: "password123" (BCrypt hashed)
-- $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi is standard bcrypt for 'password'
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `first_name`, `last_name`, `role`, `phone`, `status`) VALUES
(1, 'admin', 'admin@jetlog.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'JetLog', 'Administrator', 'Administrator', '+63 917 111 0001', 'Active'),
(2, 'demo.technician', 'technician@jetlog.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Demo', 'Technician', 'Technician', '+63 917 111 0002', 'Active'),
(3, 'a.cruz', 'acruz@jetlog.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'A.', 'Cruz', 'Technician', '+63 917 111 0003', 'Active'),
(4, 'm.santos', 'msantos@jetlog.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'M.', 'Santos', 'Lead Technician', '+63 917 111 0004', 'Active'),
(5, 'j.reyes', 'jreyes@jetlog.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'J.', 'Reyes', 'Avionics Specialist', '+63 917 111 0005', 'Active'),
(6, 'r.delrosario', 'rdelrosario@jetlog.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'R.', 'Del Rosario', 'Inspector', '+63 917 111 0006', 'Active');

-- 2. Insert Aircraft Fleet (24 Aircraft total: 15 Available, 6 Under Maintenance, 3 Inspection Due)
-- Matches: Page 2 Dashboard stats (Total: 24, Available: 15, Under Maintenance: 6, Inspection Due: 3)
-- Matches: Page 3 Registry (RP-C1234, RP-C5678, RP-C9012, RP-C3456, RP-C7890)
INSERT INTO `aircraft` (`aircraft_id`, `registration_number`, `model`, `manufacturer`, `status`, `serial_number`, `year_manufactured`, `total_flight_hours`, `total_flight_cycles`, `location`, `qr_code_token`) VALUES
-- 5 primary showcase aircraft from wireframes
(1, 'RP-C1234', 'Cessna 172', 'Cessna', 'Available', '172S-10452', 2018, 1420.50, 1850, 'Hangar 1 - Bay A', 'JETLOG-AIRCRAFT-RP-C1234-TOKEN'),
(2, 'RP-C5678', 'ATR 72', 'ATR', 'Under Maintenance', 'ATR72-212A-1402', 2016, 8950.25, 6200, 'Hangar 2 - Bay M', 'JETLOG-AIRCRAFT-RP-C5678-TOKEN'),
(3, 'RP-C9012', 'Airbus A320', 'Airbus', 'Inspection Due', 'A320-214-6890', 2017, 14520.80, 8910, 'Hangar 1 - Bay C', 'JETLOG-AIRCRAFT-RP-C9012-TOKEN'),
(4, 'RP-C3456', 'CRJ 900', 'Bombardier', 'Under Maintenance', 'CRJ900-15340', 2015, 11200.40, 7840, 'Hangar 2 - Bay B', 'JETLOG-AIRCRAFT-RP-C3456-TOKEN'),
(5, 'RP-C7890', 'PA-28 Cherokee', 'Piper', 'Available', '28-791012', 2019, 980.10, 1120, 'Hangar 1 - Bay D', 'JETLOG-AIRCRAFT-RP-C7890-TOKEN'),

-- 13 additional Available aircraft (to make total 15 Available)
(6, 'RP-C1001', 'Airbus A320', 'Airbus', 'Available', 'A320-232-7011', 2019, 9210.00, 5420, 'Terminal Apron 1', 'JETLOG-AIRCRAFT-RP-C1001-TOKEN'),
(7, 'RP-C1002', 'Airbus A321', 'Airbus', 'Available', 'A321-271N-8150', 2021, 5400.30, 3100, 'Terminal Apron 2', 'JETLOG-AIRCRAFT-RP-C1002-TOKEN'),
(8, 'RP-C1003', 'ATR 72', 'ATR', 'Available', 'ATR72-600-1550', 2020, 4820.75, 3400, 'Ramp Bay 4', 'JETLOG-AIRCRAFT-RP-C1003-TOKEN'),
(9, 'RP-C1004', 'Cessna 172', 'Cessna', 'Available', '172R-11204', 2017, 2150.00, 2600, 'Flight School Ramp', 'JETLOG-AIRCRAFT-RP-C1004-TOKEN'),
(10, 'RP-C1005', 'Cessna 208 Caravan', 'Cessna', 'Available', '208B-5100', 2016, 4200.10, 3950, 'Cargo Apron 1', 'JETLOG-AIRCRAFT-RP-C1005-TOKEN'),
(11, 'RP-C1006', 'PA-34 Seneca', 'Piper', 'Available', '34-813301', 2018, 1640.60, 1920, 'General Aviation Bay', 'JETLOG-AIRCRAFT-RP-C1006-TOKEN'),
(12, 'RP-C1007', 'CRJ 900', 'Bombardier', 'Available', 'CRJ900-15410', 2017, 8600.50, 5820, 'Terminal Apron 3', 'JETLOG-AIRCRAFT-RP-C1007-TOKEN'),
(13, 'RP-C1008', 'Airbus A320', 'Airbus', 'Available', 'A320-214-7220', 2018, 7920.80, 4980, 'Terminal Apron 4', 'JETLOG-AIRCRAFT-RP-C1008-TOKEN'),
(14, 'RP-C1009', 'ATR 42', 'ATR', 'Available', 'ATR42-500-840', 2014, 12400.00, 8900, 'Ramp Bay 2', 'JETLOG-AIRCRAFT-RP-C1009-TOKEN'),
(15, 'RP-C1010', 'Cessna 182 Skylane', 'Cessna', 'Available', '182T-0820', 2019, 1120.40, 1310, 'Hangar 1 - Bay E', 'JETLOG-AIRCRAFT-RP-C1010-TOKEN'),
(16, 'RP-C1011', 'PA-44 Seminole', 'Piper', 'Available', '44-96024', 2017, 1850.20, 2400, 'Hangar 1 - Bay F', 'JETLOG-AIRCRAFT-RP-C1011-TOKEN'),
(17, 'RP-C1012', 'Airbus A320', 'Airbus', 'Available', 'A320-271N-9040', 2022, 3800.90, 2150, 'Terminal Apron 5', 'JETLOG-AIRCRAFT-RP-C1012-TOKEN'),
(18, 'RP-C1013', 'Bombardier Dash 8', 'Bombardier', 'Available', 'DHC8-402-4512', 2015, 10800.70, 7600, 'Ramp Bay 5', 'JETLOG-AIRCRAFT-RP-C1013-TOKEN'),

-- 4 additional Under Maintenance aircraft (to make total 6 Under Maintenance)
(19, 'RP-C2001', 'Airbus A320', 'Airbus', 'Under Maintenance', 'A320-214-6102', 2014, 16800.30, 10200, 'Hangar 2 - Bay A', 'JETLOG-AIRCRAFT-RP-C2001-TOKEN'),
(20, 'RP-C2002', 'Cessna 172', 'Cessna', 'Under Maintenance', '172S-10890', 2020, 1250.00, 1420, 'Hangar 1 - Maintenance', 'JETLOG-AIRCRAFT-RP-C2002-TOKEN'),
(21, 'RP-C2003', 'ATR 72', 'ATR', 'Under Maintenance', 'ATR72-500-980', 2013, 15100.60, 11400, 'Hangar 2 - Heavy Check', 'JETLOG-AIRCRAFT-RP-C2003-TOKEN'),
(22, 'RP-C2004', 'CRJ 900', 'Bombardier', 'Under Maintenance', 'CRJ900-15280', 2014, 13900.40, 9300, 'Hangar 2 - Bay C', 'JETLOG-AIRCRAFT-RP-C2004-TOKEN'),

-- 2 additional Inspection Due aircraft (to make total 3 Inspection Due)
(23, 'RP-C3001', 'Cessna 172', 'Cessna', 'Inspection Due', '172R-10992', 2015, 2980.20, 3600, 'Ramp Bay 6', 'JETLOG-AIRCRAFT-RP-C3001-TOKEN'),
(24, 'RP-C3002', 'PA-28 Cherokee', 'Piper', 'Inspection Due', '28-821004', 2016, 2400.00, 2850, 'Ramp Bay 7', 'JETLOG-AIRCRAFT-RP-C3002-TOKEN');

-- 3. Insert Discrepancy Categories
-- Matches: discrepancy.html dropdown & reports.html categories
INSERT INTO `discrepancy_categories` (`category_id`, `category_name`, `description`) VALUES
(1, 'Avionics', 'Communication, navigation, autopilot, radar, and instrument display systems'),
(2, 'Engine', 'Powerplant, turbine, propeller, fuel control, and exhaust systems'),
(3, 'Landing Gear', 'Wheels, tires, brakes, retraction mechanisms, and shock struts'),
(4, 'Hydraulics', 'Pumps, actuators, valves, reservoirs, and hydraulic pressure lines'),
(5, 'Structure', 'Fuselage, wings, cowlings, control surfaces, and structural fasteners'),
(6, 'Other', 'Cabin environmental systems, lighting, emergency equipment, and interior fittings');

-- 4. Insert Discrepancies
-- Total 42 reported discrepancies matching reports.html distribution:
-- Avionics: 12 (29%), Engine: 9 (21%), Landing Gear: 8 (19%), Hydraulics: 7 (17%), Structure: 6 (14%)
INSERT INTO `discrepancies` (`discrepancy_id`, `discrepancy_number`, `aircraft_id`, `category_id`, `description`, `priority`, `status`, `reported_by_user_id`, `date_reported`) VALUES
-- Showcased discrepancies from wireframes
(1, 'DISC-2026-001', 2, 4, 'Landing gear hydraulic leak observed on left main gear during post-flight walkaround', 'High', 'In Progress', 2, '2026-10-02'),
(2, 'DISC-2026-002', 3, 1, 'Autopilot fault during approach; uncommanded pitch oscillation below 3,000 ft', 'Critical', 'Assigned', 2, '2026-10-05'),
(3, 'DISC-2026-003', 1, 5, 'Cracked engine cowling fastener on right forward cowl assembly', 'Medium', 'Assigned', 2, '2026-10-01'),
(4, 'DISC-2026-004', 3, 6, 'Cabin air conditioning noise; excessive whistling heard in forward galley', 'Low', 'Reported', 2, '2026-10-04'),
(5, 'DISC-2026-005', 4, 3, 'Tire wear beyond limit on left outer main wheel; cord layer visible', 'Medium', 'Resolved', 2, '2026-10-03'),
(6, 'DISC-2026-006', 1, 3, 'Brake pad wear on right main wheel approaching minimum thickness', 'Medium', 'Reported', 2, '2026-10-05'),

-- Avionics discrepancies (Total 12)
(7, 'DISC-2026-007', 3, 1, 'VHF Navigation 1 intermittent bearing deviation display on PFD', 'Medium', 'Closed', 3, '2026-07-04'),
(8, 'DISC-2026-008', 6, 1, 'Weather radar stabilization error message triggered during descent', 'Medium', 'Closed', 5, '2026-07-18'),
(9, 'DISC-2026-009', 1, 1, 'Turn coordinator needle sticking slightly in left turns', 'Low', 'Closed', 2, '2026-07-29'),
(10, 'DISC-2026-010', 7, 1, 'TCAS advisory false alert while in level cruise flight', 'High', 'Closed', 4, '2026-08-08'),
(11, 'DISC-2026-011', 8, 1, 'ADF receiver tuning knob loose; intermittent frequency reception', 'Low', 'Closed', 3, '2026-08-16'),
(12, 'DISC-2026-012', 12, 1, 'Flight Management Guidance Computer self-test failed on power-up', 'High', 'Closed', 5, '2026-08-27'),
(13, 'DISC-2026-013', 2, 1, 'Radio altimeter flag displayed intermittently below 500 feet', 'Critical', 'Closed', 5, '2026-09-03'),
(14, 'DISC-2026-014', 9, 1, 'Audio panel headphone jack distortion on co-pilot station', 'Low', 'Closed', 2, '2026-09-12'),
(15, 'DISC-2026-015', 3, 1, 'Emergency Locator Transmitter g-switch bracket loose during inspection', 'Medium', 'Closed', 5, '2026-09-22'),
(16, 'DISC-2026-016', 5, 1, 'Transponder Mode C altitude reporting 300ft discrepancy with altimeter', 'High', 'Closed', 3, '2026-09-28'),
(17, 'DISC-2026-017', 13, 1, 'Primary Flight Display backlight flickers on engine startup', 'Medium', 'In Progress', 5, '2026-10-02'),

-- Engine discrepancies (Total 9)
(18, 'DISC-2026-018', 1, 2, 'Engine #1 oil pressure gauge needle oscillating during runup', 'Medium', 'Closed', 3, '2026-07-08'),
(19, 'DISC-2026-019', 4, 2, 'Engine 2 ITT gauge reading 25 degrees higher than normal cruise value', 'Medium', 'Closed', 4, '2026-07-22'),
(20, 'DISC-2026-020', 10, 2, 'Propeller governor slight hunting observed between 2200-2400 RPM', 'Medium', 'Closed', 3, '2026-08-02'),
(21, 'DISC-2026-021', 19, 2, 'CFM56 engine oil filter bypass warning indicator illuminated on landing', 'High', 'Closed', 4, '2026-08-14'),
(22, 'DISC-2026-022', 5, 2, 'Exhaust manifold clamp showing hairline crack near cylinder 3', 'High', 'Closed', 3, '2026-08-24'),
(23, 'DISC-2026-023', 20, 2, 'Magneto drop exceeding 175 RPM on left magneto check', 'High', 'Closed', 2, '2026-09-06'),
(24, 'DISC-2026-024', 2, 2, 'Turbine chip detector warning light on secondary annunciator panel', 'Critical', 'Closed', 4, '2026-09-17'),
(25, 'DISC-2026-025', 11, 2, 'Fuel flow transmitter reading erratic at high power settings', 'Medium', 'Closed', 3, '2026-09-25'),
(26, 'DISC-2026-026', 4, 2, 'Engine oil analysis flagged elevated copper and bronze particulates', 'Critical', 'In Progress', 4, '2026-10-03'),

-- Landing Gear discrepancies (Total 8)
(27, 'DISC-2026-027', 14, 3, 'Nose gear shimmy dampener leaking silicone fluid', 'Medium', 'Closed', 3, '2026-07-12'),
(28, 'DISC-2026-028', 15, 3, 'Left main landing gear squat switch sticking in air mode', 'Critical', 'Closed', 4, '2026-07-26'),
(29, 'DISC-2026-029', 1, 3, 'Nose wheel steering centering spring loose tension', 'Low', 'Closed', 2, '2026-08-06'),
(30, 'DISC-2026-030', 21, 3, 'Main gear door actuator linkage play beyond tolerance', 'High', 'Closed', 4, '2026-08-20'),
(31, 'DISC-2026-031', 7, 3, 'Right main landing gear inboard brake temperature sensor erratic', 'Medium', 'Closed', 5, '2026-09-09'),
(32, 'DISC-2026-032', 8, 3, 'Parking brake valve weeping fluid under full pressure hold', 'Medium', 'Closed', 3, '2026-09-19'),

-- Hydraulics discrepancies (Total 7)
(33, 'DISC-2026-033', 6, 4, 'Blue hydraulic system reservoir level low after overnight park', 'Medium', 'Closed', 4, '2026-07-15'),
(34, 'DISC-2026-034', 18, 4, 'Auxiliary hydraulic power unit pressure relief valve whistling', 'Medium', 'Closed', 4, '2026-08-11'),
(35, 'DISC-2026-035', 22, 4, 'Flap hydraulic actuator packing seal weeping Skydrol', 'High', 'Closed', 4, '2026-08-30'),
(36, 'DISC-2026-036', 3, 4, 'Yellow hydraulic system electrical pump circuit breaker tripped', 'Critical', 'Closed', 5, '2026-09-08'),
(37, 'DISC-2026-037', 16, 4, 'Brake hydraulic accumulator pre-charge pressure low (500 psi)', 'High', 'Closed', 3, '2026-09-21'),
(38, 'DISC-2026-038', 21, 4, 'Hydraulic return filter differential pressure indicator popped', 'Medium', 'Assigned', 4, '2026-10-04'),

-- Structure discrepancies (Total 6)
(39, 'DISC-2026-039', 5, 5, 'Elevator trim tab hinge pin showing slight radial play', 'Medium', 'Closed', 3, '2026-07-20'),
(40, 'DISC-2026-040', 2, 5, 'Passenger boarding door rubber perimeter seal torn near upper hinge', 'Low', 'Closed', 4, '2026-08-18'),
(41, 'DISC-2026-041', 23, 5, 'Minor surface corrosion observed on vertical fin lower fairing', 'Low', 'Closed', 3, '2026-09-14'),
(42, 'DISC-2026-042', 24, 5, 'Right aileron trailing edge sealant eroded along span', 'Low', 'Closed', 3, '2026-09-29');

-- 5. Insert Work Orders
-- Total 42 Work Orders matching reports.html:
-- Open: 1, Assigned: 3, In Progress: 4, For Verification: 1 (Total Open/Active: 9)
-- Completed: 33 (79%)
-- Total: 42 (100%)
INSERT INTO `work_orders` (`work_order_id`, `work_order_number`, `aircraft_id`, `discrepancy_id`, `issue_summary`, `priority`, `assigned_technician_id`, `status`, `date_created`, `due_date`, `completed_date`, `technician_remarks`, `maintenance_action_performed`, `verified_by_user_id`, `verified_at`, `qr_code_token`) VALUES
-- 1. WO-2026-001 (Showcase: In Progress - Page 2 & work-order-details.html)
(1, 'WO-2026-001', 2, 1, 'Landing gear hydraulic leak', 'High', 4, 'In Progress', '2026-10-03', '2026-10-08', NULL, 'Inspected left main landing gear. Found hydraulic fluid seepage at the upper fitting. Replacement seal kit requested from stores. Aircraft remains in the hangar.', 'Cleaned and resealed the hydraulic fitting, refilled hydraulic fluid, and completed a gear swing check. Awaiting verification sign off.', NULL, NULL, 'JETLOG-WO-2026-001-QR'),

-- 2. WO-2026-002 (Showcase: For Verification - Page 2)
(2, 'WO-2026-002', 3, 2, 'Autopilot fault during approach', 'Critical', 5, 'For Verification', '2026-10-05', '2026-10-07', NULL, 'Bench tested pitch servo actuator. Detected intermittent sensor continuity on connector J2. Harness repaired and re-pinned.', 'Reinstalled servo actuator and performed complete autopilot BITE test. Passed all static checks. Aircraft requires verification flight.', 6, NULL, 'JETLOG-WO-2026-002-QR'),

-- 3. WO-2026-003 (Showcase: Assigned - Page 2 & aircraft-profile.html)
(3, 'WO-2026-003', 1, 3, 'Cracked engine cowling fastener', 'Medium', 3, 'Assigned', '2026-10-01', '2026-10-06', NULL, 'Initial inspection verified cracked Camloc fastener receptacle on right forward lower cowl. Parts ordered from inventory.', NULL, NULL, NULL, 'JETLOG-WO-2026-003-QR'),

-- 4. WO-2026-004 (Showcase: Open - Page 2)
(4, 'WO-2026-004', 3, 4, 'Cabin air conditioning noise', 'Low', 4, 'Open', '2026-10-04', '2026-10-12', NULL, 'Logged during turnaround inspection. Scheduled for duct inspection in Hangar 1.', NULL, NULL, NULL, 'JETLOG-WO-2026-004-QR'),

-- 5. WO-2026-005 (Showcase: Completed - work-orders.html)
(5, 'WO-2026-005', 4, 5, 'Tire wear beyond limit', 'Medium', 3, 'Completed', '2026-10-02', '2026-10-04', '2026-10-04', 'Outer tire removed and inspected. Hub assembly inspected with no damage. New Michelin tire fitted and balanced.', 'Replaced left main tire assembly P/N 301-452-0. Torqued axle nut to 180 ft-lbs and safetied. Pressure set to 165 psi nitrogen. Aircraft signed off.', 6, '2026-10-04 16:30:00', 'JETLOG-WO-2026-005-QR'),

-- 6. WO-2026-006 (Showcase: Open - aircraft-profile.html)
(6, 'WO-2026-006', 1, 6, 'Brake pad wear on right main wheel', 'Medium', 3, 'Assigned', '2026-10-05', '2026-10-09', NULL, 'Brake lining thickness measured at 0.080 inch. Wear limit is 0.050 inch. Linings scheduled for replacement during next turnaround.', NULL, NULL, NULL, 'JETLOG-WO-2026-006-QR'),

-- Additional In Progress work orders (to reach 4 In Progress total)
(7, 'WO-2026-007', 4, 26, 'Engine oil analysis investigation', 'Critical', 4, 'In Progress', '2026-10-03', '2026-10-09', NULL, 'Borescope inspection of HP turbine bearings underway. Oil scavenge filters removed for lab spectrometry.', NULL, NULL, NULL, 'JETLOG-WO-2026-007-QR'),
(8, 'WO-2026-008', 13, 17, 'PFD backlight flickering diagnosis', 'Medium', 5, 'In Progress', '2026-10-02', '2026-10-08', NULL, 'Inverter unit tested. Dimming control potentiometer isolated as cause.', NULL, NULL, NULL, 'JETLOG-WO-2026-008-QR'),
(9, 'WO-2026-009', 2, 1, 'Hydraulic lines pressure cycle test', 'High', 4, 'In Progress', '2026-10-04', '2026-10-10', NULL, 'Auxiliary test rig connected to left gear bay. Static 3000 PSI test in progress.', NULL, NULL, NULL, 'JETLOG-WO-2026-009-QR'),

-- Additional Assigned work order (to reach 3 Assigned total: WO-3, WO-6, WO-10)
(10, 'WO-2026-010', 21, 38, 'Hydraulic return filter replacement', 'Medium', 4, 'Assigned', '2026-10-04', '2026-10-11', NULL, 'Replacement element P/N HF-920 staged in bay.', NULL, NULL, NULL, 'JETLOG-WO-2026-010-QR'),

-- 32 Completed Work Orders (Jul, Aug, Sep, Oct 2026) to make 33 Completed total
(11, 'WO-2026-011', 3, 7, 'VHF Nav 1 antenna reseal and test', 'Medium', 3, 'Completed', '2026-07-04', '2026-07-06', '2026-07-05', 'Resealed antenna mount and performed VSWR check.', 'Completed VSWR check within limits (1.2:1).', 6, '2026-07-05 14:00:00', 'JETLOG-WO-2026-011-QR'),
(12, 'WO-2026-012', 1, 18, 'Engine #1 oil pressure transmitter replace', 'Medium', 3, 'Completed', '2026-07-08', '2026-07-10', '2026-07-09', 'Replaced transmitter and performed engine ground run.', 'Ground run normal. Oil pressure stable at 65 PSI.', 6, '2026-07-09 11:30:00', 'JETLOG-WO-2026-012-QR'),
(13, 'WO-2026-013', 14, 27, 'Nose gear shimmy dampener servicing', 'Medium', 3, 'Completed', '2026-07-12', '2026-07-14', '2026-07-13', 'Repacked O-rings and serviced with 5606 fluid.', 'Serviced and leak tested. Taxi test satisfactory.', 6, '2026-07-13 16:15:00', 'JETLOG-WO-2026-013-QR'),
(14, 'WO-2026-014', 6, 33, 'Blue hydraulic system leak check', 'Medium', 4, 'Completed', '2026-07-15', '2026-07-17', '2026-07-16', 'Tightened B-nut on return line. No leaks found.', 'Pressurized to 3000 PSI for 30 minutes. Pass.', 6, '2026-07-16 10:45:00', 'JETLOG-WO-2026-014-QR'),
(15, 'WO-2026-015', 6, 8, 'Weather radar waveguide realignment', 'Medium', 5, 'Completed', '2026-07-18', '2026-07-21', '2026-07-20', 'Realighted waveguide antenna bracket.', 'Antenna swept 180 degrees. Target clarity confirmed.', 6, '2026-07-20 15:20:00', 'JETLOG-WO-2026-015-QR'),
(16, 'WO-2026-016', 5, 39, 'Elevator trim tab hinge replacement', 'Medium', 3, 'Completed', '2026-07-20', '2026-07-23', '2026-07-22', 'Installed new stainless steel hinge pin.', 'Full free travel verified without binding.', 6, '2026-07-22 13:00:00', 'JETLOG-WO-2026-016-QR'),
(17, 'WO-2026-017', 4, 19, 'Engine 2 ITT probe calibration', 'Medium', 4, 'Completed', '2026-07-22', '2026-07-25', '2026-07-24', 'Replaced thermocouple probe #4.', 'Calibration curve aligned within +/- 3 deg C.', 6, '2026-07-24 17:00:00', 'JETLOG-WO-2026-017-QR'),
(18, 'WO-2026-018', 15, 28, 'Squat switch replacement on gear', 'Critical', 4, 'Completed', '2026-07-26', '2026-07-28', '2026-07-27', 'Replaced microswitch and adjusted clearance.', 'Clearance set to 0.040 in. Weight-on-wheels test OK.', 6, '2026-07-27 12:30:00', 'JETLOG-WO-2026-018-QR'),
(19, 'WO-2026-019', 1, 9, 'Turn coordinator replacement', 'Low', 2, 'Completed', '2026-07-29', '2026-07-31', '2026-07-31', 'Installed overhauled gyro unit.', 'Operational test satisfactory on 14V bus.', 6, '2026-07-31 09:45:00', 'JETLOG-WO-2026-019-QR'),
(20, 'WO-2026-020', 10, 20, 'Propeller governor rigging', 'Medium', 3, 'Completed', '2026-08-02', '2026-08-05', '2026-08-04', 'Adjusted high RPM stop screw and control arm.', 'Static RPM verified at 2700 on full power check.', 6, '2026-08-04 14:10:00', 'JETLOG-WO-2026-020-QR'),
(21, 'WO-2026-021', 1, 29, 'Nose gear steering spring replacement', 'Low', 2, 'Completed', '2026-08-06', '2026-08-08', '2026-08-07', 'Fitted new tension spring and lubed steering collar.', 'Rudder pedal steering checked smoothly.', 6, '2026-08-07 11:00:00', 'JETLOG-WO-2026-021-QR'),
(22, 'WO-2026-022', 7, 10, 'TCAS directional antenna cleaning', 'High', 4, 'Completed', '2026-08-08', '2026-08-11', '2026-08-10', 'Cleaned antenna connectors and applied corrosion inhibitor.', 'Built-in diagnostic test passed with zero errors.', 6, '2026-08-10 16:40:00', 'JETLOG-WO-2026-022-QR'),
(23, 'WO-2026-023', 18, 34, 'Hydraulic relief valve adjustment', 'Medium', 4, 'Completed', '2026-08-11', '2026-08-14', '2026-08-13', 'Adjusted relief valve cracking pressure to 3300 PSI.', 'Pressure dump cycle tested 5 times without leakage.', 6, '2026-08-13 15:30:00', 'JETLOG-WO-2026-023-QR'),
(24, 'WO-2026-024', 19, 21, 'Engine oil filter element replacement', 'High', 4, 'Completed', '2026-08-14', '2026-08-16', '2026-08-15', 'Installed new oil filter and flushed filter housing.', 'Differential pressure switch reset. Ground idle normal.', 6, '2026-08-15 13:20:00', 'JETLOG-WO-2026-024-QR'),
(25, 'WO-2026-025', 8, 11, 'ADF control head rebuild', 'Low', 3, 'Completed', '2026-08-16', '2026-08-19', '2026-08-18', 'Lubricated frequency selector rotary detents.', 'Bench aligned to NDB test beacon on 380 kHz.', 6, '2026-08-18 10:15:00', 'JETLOG-WO-2026-025-QR'),
(26, 'WO-2026-026', 2, 40, 'Passenger door perimeter seal replacement', 'Low', 4, 'Completed', '2026-08-18', '2026-08-21', '2026-08-20', 'Installed new silicone extrusion seal.', 'Door closed and cabin pressurization smoke tested OK.', 6, '2026-08-20 14:50:00', 'JETLOG-WO-2026-026-QR'),
(27, 'WO-2026-027', 21, 30, 'Gear door actuator linkage bush replacement', 'High', 4, 'Completed', '2026-08-20', '2026-08-23', '2026-08-22', 'Pressed new bronze bushes into actuator clevis.', 'Zero free play measured under 200 lb test load.', 6, '2026-08-22 16:00:00', 'JETLOG-WO-2026-027-QR'),
(28, 'WO-2026-028', 5, 22, 'Cylinder 3 exhaust clamp replacement', 'High', 3, 'Completed', '2026-08-24', '2026-08-26', '2026-08-25', 'Installed new Inconel exhaust clamp.', 'Torqued to 70 in-lbs and safetied with lockwire.', 6, '2026-08-25 11:45:00', 'JETLOG-WO-2026-028-QR'),
(29, 'WO-2026-029', 12, 12, 'FMGC software reload and verification', 'High', 5, 'Completed', '2026-08-27', '2026-08-30', '2026-08-29', 'Loaded latest navigation database and system firmware.', 'Data loader verified checksum match 100%.', 6, '2026-08-29 17:30:00', 'JETLOG-WO-2026-029-QR'),
(30, 'WO-2026-030', 22, 35, 'Flap actuator cylinder repack', 'High', 4, 'Completed', '2026-08-30', '2026-09-02', '2026-09-01', 'Overhauled cylinder seals with Skydrol compatible packings.', 'Extended and retracted flaps 10 cycles without weep.', 6, '2026-09-01 15:10:00', 'JETLOG-WO-2026-030-QR'),
(31, 'WO-2026-031', 1, 3, 'Scheduled 50-hour cowl and airframe inspection', 'Medium', 3, 'Completed', '2026-09-01', '2026-09-02', '2026-09-02', 'Completed scheduled 50-hour inspection per Cessna MM.', 'All items inspected, signed off, aircraft released.', 6, '2026-09-02 16:00:00', 'JETLOG-WO-2026-031-QR'),
(32, 'WO-2026-032', 2, 13, 'Radio altimeter transceiver unit replacement', 'Critical', 5, 'Completed', '2026-09-03', '2026-09-05', '2026-09-04', 'Installed overhauled Collins altimeter receiver.', 'Ramp test verified exact ground height readouts.', 6, '2026-09-04 13:40:00', 'JETLOG-WO-2026-032-QR'),
(33, 'WO-2026-033', 20, 23, 'Left magneto points replacement & timing', 'High', 2, 'Completed', '2026-09-06', '2026-09-08', '2026-09-07', 'Replaced breaker points and set E-gap to 25 deg BTDC.', 'Magneto RPM drop on runup now within 50 RPM limit.', 6, '2026-09-07 10:20:00', 'JETLOG-WO-2026-033-QR'),
(34, 'WO-2026-034', 3, 36, 'Yellow hydraulic electrical pump contactor replace', 'Critical', 5, 'Completed', '2026-09-08', '2026-09-10', '2026-09-09', 'Replaced 28V DC contactor in electrical avionics bay.', 'Yellow pump pressurizes to 3000 PSI within 4 seconds.', 6, '2026-09-09 16:35:00', 'JETLOG-WO-2026-034-QR'),
(35, 'WO-2026-035', 7, 31, 'Brake temp thermocouple wire repair', 'Medium', 5, 'Completed', '2026-09-09', '2026-09-11', '2026-09-10', 'Spliced damaged wire harness using environmental splices.', 'ECAM brake temp readings match ambient temperature.', 6, '2026-09-10 11:50:00', 'JETLOG-WO-2026-035-QR'),
(36, 'WO-2026-036', 9, 14, 'Co-pilot audio jack panel repair', 'Low', 2, 'Completed', '2026-09-12', '2026-09-14', '2026-09-13', 'Re-soldered ground lead on microphone jack.', 'Audio check with tower crystal clear on both sides.', 6, '2026-09-13 14:15:00', 'JETLOG-WO-2026-036-QR'),
(37, 'WO-2026-037', 1, 3, 'Cowling fastener replacement', 'Medium', 4, 'Completed', '2026-09-14', '2026-09-15', '2026-09-14', 'Replaced lower cowl receptacle and grommet.', 'Cowling fastener replaced and flush aligned.', 6, '2026-09-14 17:00:00', 'JETLOG-WO-2026-037-QR'),
(38, 'WO-2026-038', 23, 41, 'Vertical fin fairing corrosion removal', 'Low', 3, 'Completed', '2026-09-14', '2026-09-16', '2026-09-15', 'Blended surface pitting, applied Alodine and primer.', 'Thickness checked with ultrasonic gauge; well above min.', 6, '2026-09-15 15:45:00', 'JETLOG-WO-2026-038-QR'),
(39, 'WO-2026-039', 2, 24, 'Turbine chip detector inspection and flush', 'Critical', 4, 'Completed', '2026-09-17', '2026-09-19', '2026-09-18', 'Removed magnetic chip plug; inspected under microscope.', 'Fuzz removed; secondary oil flush clear. Sign off.', 6, '2026-09-18 12:10:00', 'JETLOG-WO-2026-039-QR'),
(40, 'WO-2026-040', 8, 32, 'Parking brake hydraulic valve reseal', 'Medium', 3, 'Completed', '2026-09-19', '2026-09-21', '2026-09-20', 'Installed new backup rings on valve spool.', 'Tested under 2500 PSI holding test for 1 hour. Pass.', 6, '2026-09-20 16:30:00', 'JETLOG-WO-2026-040-QR'),
(41, 'WO-2026-041', 16, 37, 'Accumulator nitrogen pre-charge servicing', 'High', 3, 'Completed', '2026-09-21', '2026-09-23', '2026-09-22', 'Pressurized nitrogen bottle to 1000 PSI.', 'Pre-charge pressure verified on gauge. No leakage.', 6, '2026-09-22 14:00:00', 'JETLOG-WO-2026-041-QR'),
(42, 'WO-2026-042', 1, 3, 'Pre-flight routine airframe inspection', 'Low', 3, 'Completed', '2026-09-28', '2026-09-28', '2026-09-28', 'Routine 100-hour inspection phase 1 completed.', 'No major findings, aircraft released to service.', 6, '2026-09-28 17:00:00', 'JETLOG-WO-2026-042-QR');

-- 6. Insert Work Order Status History
-- Demonstrates the audit trail flow: Reported -> Assigned -> In Progress -> For Verification -> Completed
INSERT INTO `work_order_status_history` (`work_order_id`, `status`, `changed_by_user_id`, `remarks`, `changed_at`) VALUES
(1, 'Reported', 2, 'Work order opened upon discrepancy filing', '2026-10-03 08:30:00'),
(1, 'Assigned', 1, 'Assigned to Lead Technician M. Santos', '2026-10-03 09:15:00'),
(1, 'In Progress', 4, 'Hangar inspection commenced, seals ordered', '2026-10-03 10:45:00'),

(2, 'Reported', 2, 'Work order created from autopilot discrepancy', '2026-10-05 07:15:00'),
(2, 'Assigned', 1, 'Assigned to Avionics Specialist J. Reyes', '2026-10-05 08:00:00'),
(2, 'In Progress', 5, 'Pitch servo bench tested and harness repaired', '2026-10-05 09:30:00'),
(2, 'For Verification', 5, 'Bench tests completed, awaiting inspector test flight signoff', '2026-10-05 15:45:00'),

(3, 'Reported', 2, 'Work order created for cowling fastener', '2026-10-01 11:00:00'),
(3, 'Assigned', 1, 'Assigned to Technician A. Cruz', '2026-10-01 13:30:00'),

(5, 'Reported', 2, 'Work order created for tire replacement', '2026-10-02 09:00:00'),
(5, 'Assigned', 1, 'Assigned to Technician A. Cruz', '2026-10-02 10:00:00'),
(5, 'In Progress', 3, 'Wheel removed and new tire mounted', '2026-10-03 08:00:00'),
(5, 'For Verification', 3, 'Torque verified and safety lock installed', '2026-10-04 14:00:00'),
(5, 'Completed', 6, 'Signed off by Inspector R. Del Rosario', '2026-10-04 16:30:00');

-- 7. Insert Maintenance Schedules
-- Matches: Page 2 Dashboard stats (Overdue Tasks: 3, Upcoming Inspections: 5)
-- Matches: Page 2 Upcoming Maintenance table & schedule.html
INSERT INTO `maintenance_schedules` (`schedule_id`, `aircraft_id`, `maintenance_type`, `scheduled_date`, `due_date`, `status`, `linked_work_order_id`, `notes`) VALUES
-- Exact 3 items shown on Page 2 Dashboard Upcoming Maintenance table
(1, 1, 'Routine Inspection', '2026-10-10', '2026-10-15', 'Upcoming', NULL, 'Standard 100-hour airframe and powerplant routine inspection'),
(2, 2, 'Landing Gear Inspection', '2026-10-12', '2026-10-14', 'Due Soon', 1, 'Detailed NDT eddy current check of main gear trunnion and fittings'),
(3, 3, 'Scheduled Maintenance', '2026-10-05', '2026-10-05', 'Overdue', 2, 'A-Check inspection cycle items (300 flight hours limit exceeded)'),

-- Additional items from schedule.html
(4, 4, 'Engine Oil Analysis', '2026-10-18', '2026-10-20', 'Upcoming', 7, 'Spectrometric oil sample draw for engine 1 and engine 2 scavenge filters'),
(5, 5, 'Avionics Check', '2026-10-24', '2026-10-26', 'Upcoming', NULL, 'Annual pitot-static and transponder bi-annual recertification per FAR 91.411'),

-- Additional items to match exact stats (3 Overdue, 5 Upcoming in next 30 days)
(6, 1, 'Emergency Locator Transmitter Test', '2026-10-28', '2026-10-30', 'Upcoming', NULL, 'Annual ELT battery inspection and 406 MHz signal burst test (Page 4)'),
(7, 6, 'Routine 100-Hour Inspection', '2026-11-02', '2026-11-05', 'Upcoming', NULL, 'Scheduled airframe phase 2 inspection'),
(8, 20, 'Cylinder Compression Check', '2026-09-28', '2026-09-30', 'Overdue', NULL, 'Differential pressure compression check on cylinders 1-4'),
(9, 21, 'Airworthiness Directive AD-2026-14 Compliance', '2026-10-01', '2026-10-02', 'Overdue', NULL, 'Mandatory visual inspection of wing root fairing attachment fasteners');

-- 8. Insert Maintenance Logbook
-- Matches: Page 4 (Aircraft Profile Recent Maintenance History) & logbook.html
INSERT INTO `maintenance_logbook` (`log_id`, `aircraft_id`, `work_order_id`, `activity_type`, `technician_id`, `log_date`, `result_summary`, `remarks`, `flight_hours_at_log`) VALUES
-- Exact entries from Page 4 Aircraft Profile Recent Maintenance History
(1, 1, 31, 'Completed Work Order', 5, '2026-09-02', 'Scheduled maintenance completed and signed off', 'Completed scheduled 50-hour inspection per manufacturer maintenance manual. All systems nominal.', 1398.20),
(2, 1, 37, 'Repair', 4, '2026-09-14', 'Cowling fastener replaced', 'Installed new Camloc receptacle assembly on right lower engine cowl. Flush fit verified.', 1405.00),
(3, 1, 42, 'Inspection', 3, '2026-09-28', 'No major findings, aircraft released to service', 'Pre-flight routine airframe inspection completed. Released for cross-country training operations.', 1418.50),

-- Exact entries from logbook.html
(4, 1, 3, 'Inspection', 3, '2026-10-01', 'Minor corrosion found on cowling fastener', 'Routine pre-flight visual check identified superficial surface oxidation on fastener head. Work order WO-2026-003 raised.', 1420.50),
(5, 2, 1, 'Discrepancy', 4, '2026-10-02', 'Hydraulic leak reported on left main landing gear', 'Fluid droplets observed near brake line union. Ground test confirmed slow leak under 2500 PSI pressure.', 8948.10),
(6, 2, 1, 'Repair', 4, '2026-10-03', 'Fitting resealed, pressure test passed', 'Replaced backup O-ring, tightened union to 240 in-lbs, executed full pressure cycle test with zero leaks.', 8950.25),
(7, 4, 5, 'Completed Work Order', 3, '2026-10-04', 'Tire replaced, aircraft returned to service', 'Left main gear tire replaced with new unit. Axle nut torqued and safetied. Aircraft returned to line status.', 11200.40),
(8, 3, 2, 'Discrepancy', 5, '2026-10-05', 'Autopilot fault logged and scheduled for verification', 'Pilot flight report noted pitch oscillations during ILS runway 06 approach. Transferred to avionics hangar.', 14520.80);

-- ==============================================================================
-- End of Schema and Seed Data
-- ==============================================================================

-- ==============================================================================
-- APPENDIX: BASIC SQL CRUD QUERIES (CREATE, SELECT, UPDATE, DELETE)
-- Reference: See `crud_queries.sql` for the full collection of operations.
-- ==============================================================================

/*
-- 1. CREATE (INSERT) EXAMPLES:
-- Insert a new aircraft:
INSERT INTO `aircraft` (`registration_number`, `model`, `manufacturer`, `status`, `qr_code_token`)
VALUES ('RP-C4501', 'Cessna 208 Caravan', 'Cessna', 'Available', 'JETLOG-AIRCRAFT-RP-C4501-TOKEN');

-- Report a discrepancy:
INSERT INTO `discrepancies` (`discrepancy_number`, `aircraft_id`, `category_id`, `description`, `priority`, `status`, `reported_by_user_id`, `date_reported`)
VALUES ('DISC-2026-043', 1, 1, 'Transponder intermittent reply flag', 'High', 'Reported', 2, CURRENT_DATE());

-- Create a work order:
INSERT INTO `work_orders` (`work_order_number`, `aircraft_id`, `issue_summary`, `priority`, `assigned_technician_id`, `status`, `date_created`, `due_date`, `qr_code_token`)
VALUES ('WO-2026-043', 1, 'Transponder Mode S bench test', 'High', 5, 'Open', CURRENT_DATE(), DATE_ADD(CURRENT_DATE(), INTERVAL 3 DAY), 'JETLOG-WO-2026-043-QR');


-- 2. READ (SELECT) EXAMPLES:
-- Search & filter aircraft by status and manufacturer (Page 3):
SELECT `registration_number`, `model`, `manufacturer`, `status`
FROM `aircraft`
WHERE (`registration_number` LIKE '%172%' OR `model` LIKE '%172%')
  AND `status` = 'Available'
  AND `manufacturer` = 'Cessna';

-- Recent work orders with technician names (Page 2):
SELECT 
    wo.`work_order_number`,
    a.`registration_number` AS `aircraft`,
    wo.`issue_summary` AS `issue`,
    wo.`priority`,
    wo.`status`,
    CONCAT(u.`first_name`, ' ', u.`last_name`) AS `technician`
FROM `work_orders` wo
JOIN `aircraft` a ON wo.`aircraft_id` = a.`aircraft_id`
LEFT JOIN `users` u ON wo.`assigned_technician_id` = u.`user_id`
ORDER BY wo.`date_created` DESC, wo.`work_order_id` DESC
LIMIT 4;

-- Active work orders for a specific aircraft (Page 4):
SELECT `work_order_number`, `issue_summary`, `status`, `priority`
FROM `work_orders`
WHERE `aircraft_id` = (SELECT `aircraft_id` FROM `aircraft` WHERE `registration_number` = 'RP-C1234')
  AND `status` NOT IN ('Completed', 'Cancelled');


-- 3. UPDATE EXAMPLES:
-- Update aircraft maintenance status:
UPDATE `aircraft`
SET `status` = 'Under Maintenance', `location` = 'Hangar 2 - Bay M'
WHERE `registration_number` = 'RP-C1234';

-- Update work order progress flow:
UPDATE `work_orders`
SET `status` = 'In Progress', `technician_remarks` = 'Started disassembly and hydraulic test.'
WHERE `work_order_number` = 'WO-2026-001';

-- Complete work order with sign-off:
UPDATE `work_orders`
SET 
    `status` = 'Completed',
    `completed_date` = CURRENT_DATE(),
    `maintenance_action_performed` = 'Replaced seal kit, torqued fittings, pressure test passed.',
    `verified_by_user_id` = 6,
    `verified_at` = NOW()
WHERE `work_order_number` = 'WO-2026-001';


-- 4. DELETE EXAMPLES:
-- Soft delete / decommission an aircraft:
UPDATE `aircraft`
SET `status` = 'Decommissioned'
WHERE `registration_number` = 'RP-C4501';

-- Delete an erroneous / cancelled work order:
DELETE FROM `work_orders`
WHERE `work_order_number` = 'WO-2026-043';

-- Purge expired password reset tokens:
DELETE FROM `password_resets`
WHERE `expires_at` < NOW() OR `used` = 1;
*/

