-- ==============================================================================
-- JetLog: Basic SQL Queries Reference (CRUD Operations)
-- CREATE (INSERT) | READ (SELECT) | UPDATE | DELETE
-- Database: jetlog_db
-- ==============================================================================

USE `jetlog_db`;

-- ==============================================================================
-- 1. AIRCRAFT REGISTRY (aircraft table)
-- Reference: Page 2, 3 (Aircraft Registry), Page 4 (Aircraft Profile)
-- ==============================================================================

-- 1.1 CREATE (INSERT): Register a new aircraft into the fleet
INSERT INTO `aircraft` (
    `registration_number`,
    `model`,
    `manufacturer`,
    `status`,
    `serial_number`,
    `year_manufactured`,
    `total_flight_hours`,
    `total_flight_cycles`,
    `location`,
    `qr_code_token`
) VALUES (
    'RP-C4501',
    'Cessna 208 Caravan',
    'Cessna',
    'Available',
    '208B-5620',
    2021,
    320.50,
    410,
    'Hangar 1 - Bay B',
    'JETLOG-AIRCRAFT-RP-C4501-TOKEN'
);

-- 1.2 SELECT: List all aircraft with key details
SELECT 
    `aircraft_id`,
    `registration_number`,
    `model`,
    `manufacturer`,
    `status`,
    `total_flight_hours`,
    `location`
FROM `aircraft`
ORDER BY `registration_number` ASC;

-- 1.3 SELECT: Search and Filter (Matches Page 3 Search and Filter bar)
-- Filter by keyword (registration/model), status, and manufacturer
SELECT 
    `registration_number`,
    `model`,
    `manufacturer`,
    `status`
FROM `aircraft`
WHERE (`registration_number` LIKE '%172%' OR `model` LIKE '%172%')
  AND `status` = 'Available'
  AND `manufacturer` = 'Cessna';

-- 1.4 SELECT: Aircraft Profile by Registration (Matches Page 4 Aircraft Profile)
SELECT 
    `registration_number`,
    `model`,
    `manufacturer`,
    `status`,
    `serial_number`,
    `year_manufactured`,
    `total_flight_hours`,
    `total_flight_cycles`,
    `location`,
    `qr_code_token`
FROM `aircraft`
WHERE `registration_number` = 'RP-C1234';

-- 1.5 SELECT: Fleet status summary counts (Matches Page 2 & reports.html)
SELECT 
    `status`,
    COUNT(*) AS `aircraft_count`,
    ROUND(COUNT(*) / (SELECT COUNT(*) FROM `aircraft`) * 100, 1) AS `percentage`
FROM `aircraft`
GROUP BY `status`;

-- 1.6 UPDATE: Update maintenance status and hangar location
UPDATE `aircraft`
SET 
    `status` = 'Under Maintenance',
    `location` = 'Hangar 2 - Heavy Check'
WHERE `registration_number` = 'RP-C1234';

-- 1.7 UPDATE: Update flight hours and cycles after flight
UPDATE `aircraft`
SET 
    `total_flight_hours` = `total_flight_hours` + 3.50,
    `total_flight_cycles` = `total_flight_cycles` + 2
WHERE `registration_number` = 'RP-C1234';

-- 1.8 DELETE: Soft delete / decommission an aircraft
UPDATE `aircraft`
SET `status` = 'Decommissioned'
WHERE `registration_number` = 'RP-C4501';

-- 1.9 DELETE: Hard delete an aircraft (only if no related work orders exist)
DELETE FROM `aircraft`
WHERE `registration_number` = 'RP-C4501';


-- ==============================================================================
-- 2. DISCREPANCIES (discrepancies table)
-- Reference: Page 2 (Quick Action: Report Discrepancy), discrepancy.html
-- ==============================================================================

-- 2.1 CREATE (INSERT): Report a new discrepancy
INSERT INTO `discrepancies` (
    `discrepancy_number`,
    `aircraft_id`,
    `category_id`,
    `description`,
    `priority`,
    `status`,
    `reported_by_user_id`,
    `date_reported`
) VALUES (
    'DISC-2026-043',
    (SELECT `aircraft_id` FROM `aircraft` WHERE `registration_number` = 'RP-C1234'),
    (SELECT `category_id` FROM `discrepancy_categories` WHERE `category_name` = 'Avionics'),
    'Transponder Mode S intermittent reply flag reported by ATC during approach',
    'High',
    'Reported',
    (SELECT `user_id` FROM `users` WHERE `username` = 'demo.technician'),
    CURRENT_DATE()
);

-- 2.2 SELECT: List all active/open discrepancies with aircraft & category
SELECT 
    d.`discrepancy_number`,
    a.`registration_number` AS `aircraft`,
    c.`category_name` AS `category`,
    d.`description`,
    d.`priority`,
    d.`status`,
    CONCAT(u.`first_name`, ' ', u.`last_name`) AS `reported_by`,
    d.`date_reported`
FROM `discrepancies` d
JOIN `aircraft` a ON d.`aircraft_id` = a.`aircraft_id`
JOIN `discrepancy_categories` c ON d.`category_id` = c.`category_id`
JOIN `users` u ON d.`reported_by_user_id` = u.`user_id`
WHERE d.`status` NOT IN ('Resolved', 'Closed')
ORDER BY FIELD(d.`priority`, 'Critical', 'High', 'Medium', 'Low'), d.`date_reported` DESC;

-- 2.3 SELECT: Discrepancy distribution by category (Matches reports.html)
SELECT 
    c.`category_name`,
    COUNT(d.`discrepancy_id`) AS `total_count`,
    ROUND(COUNT(d.`discrepancy_id`) / (SELECT COUNT(*) FROM `discrepancies`) * 100, 1) AS `percentage`
FROM `discrepancy_categories` c
LEFT JOIN `discrepancies` d ON c.`category_id` = d.`category_id`
GROUP BY c.`category_id`, c.`category_name`
ORDER BY `total_count` DESC;

-- 2.4 UPDATE: Change discrepancy status and priority
UPDATE `discrepancies`
SET 
    `status` = 'In Progress',
    `priority` = 'Critical'
WHERE `discrepancy_number` = 'DISC-2026-043';

-- 2.5 DELETE: Delete a cancelled or erroneously entered discrepancy
DELETE FROM `discrepancies`
WHERE `discrepancy_number` = 'DISC-2026-043';


-- ==============================================================================
-- 3. WORK ORDERS (work_orders & work_order_status_history tables)
-- Reference: Page 2 (Recent Work Orders), work-orders.html, work-order-details.html
-- ==============================================================================

-- 3.1 CREATE (INSERT): Create a new work order
INSERT INTO `work_orders` (
    `work_order_number`,
    `aircraft_id`,
    `discrepancy_id`,
    `issue_summary`,
    `priority`,
    `assigned_technician_id`,
    `status`,
    `date_created`,
    `due_date`,
    `qr_code_token`
) VALUES (
    'WO-2026-043',
    (SELECT `aircraft_id` FROM `aircraft` WHERE `registration_number` = 'RP-C1234'),
    (SELECT `discrepancy_id` FROM `discrepancies` WHERE `discrepancy_number` = 'DISC-2026-003'),
    'Cracked engine cowling fastener replacement',
    'Medium',
    (SELECT `user_id` FROM `users` WHERE `username` = 'a.cruz'),
    'Open',
    CURRENT_DATE(),
    DATE_ADD(CURRENT_DATE(), INTERVAL 5 DAY),
    'JETLOG-WO-2026-043-QR'
);

-- 3.2 CREATE (INSERT): Log work order status flow transition (Audit Trail)
-- Flow: Reported -> Assigned -> In Progress -> For Verification -> Completed
INSERT INTO `work_order_status_history` (
    `work_order_id`,
    `status`,
    `changed_by_user_id`,
    `remarks`
) VALUES (
    (SELECT `work_order_id` FROM `work_orders` WHERE `work_order_number` = 'WO-2026-043'),
    'Assigned',
    (SELECT `user_id` FROM `users` WHERE `username` = 'admin'),
    'Assigned to Technician A. Cruz for fastener replacement'
);

-- 3.3 SELECT: Recent Work Orders (Matches Page 2 Dashboard Recent Work Orders table)
SELECT 
    wo.`work_order_number` AS `Work Order`,
    a.`registration_number` AS `Aircraft`,
    wo.`issue_summary` AS `Issue`,
    wo.`priority` AS `Priority`,
    wo.`status` AS `Status`
FROM `work_orders` wo
JOIN `aircraft` a ON wo.`aircraft_id` = a.`aircraft_id`
ORDER BY wo.`date_created` DESC, wo.`work_order_id` DESC
LIMIT 4;

-- 3.4 SELECT: Work Order Details by Number (Matches work-order-details.html)
SELECT 
    wo.`work_order_number`,
    CONCAT(a.`registration_number`, ' (', a.`model`, ')') AS `aircraft`,
    wo.`issue_summary` AS `reported_discrepancy`,
    wo.`priority`,
    CONCAT(u.`first_name`, ' ', u.`last_name`) AS `assigned_technician`,
    wo.`status` AS `current_status`,
    wo.`date_created`,
    wo.`due_date`,
    wo.`technician_remarks`,
    wo.`maintenance_action_performed`,
    wo.`qr_code_token`
FROM `work_orders` wo
JOIN `aircraft` a ON wo.`aircraft_id` = a.`aircraft_id`
LEFT JOIN `users` u ON wo.`assigned_technician_id` = u.`user_id`
WHERE wo.`work_order_number` = 'WO-2026-001';

-- 3.5 SELECT: Active Work Orders for a specific aircraft (Matches Page 4 Aircraft Profile)
SELECT 
    wo.`work_order_number`,
    wo.`issue_summary`,
    wo.`status`,
    wo.`priority`
FROM `work_orders` wo
JOIN `aircraft` a ON wo.`aircraft_id` = a.`aircraft_id`
WHERE a.`registration_number` = 'RP-C1234'
  AND wo.`status` NOT IN ('Completed', 'Cancelled');

-- 3.6 SELECT: Count Work Orders by Status (Matches reports.html status bar)
SELECT 
    `status`,
    COUNT(*) AS `total`,
    ROUND(COUNT(*) / (SELECT COUNT(*) FROM `work_orders`) * 100, 1) AS `percentage`
FROM `work_orders`
GROUP BY `status`
ORDER BY FIELD(`status`, 'Open', 'Assigned', 'In Progress', 'For Verification', 'Completed');

-- 3.7 UPDATE: Assign technician and move status to 'In Progress'
UPDATE `work_orders`
SET 
    `assigned_technician_id` = (SELECT `user_id` FROM `users` WHERE `username` = 'm.santos'),
    `status` = 'In Progress',
    `technician_remarks` = 'Started disassembly and inspection of landing gear hydraulic actuator.'
WHERE `work_order_number` = 'WO-2026-043';

-- 3.8 UPDATE: Complete work order with signoff and actions performed
UPDATE `work_orders`
SET 
    `status` = 'Completed',
    `completed_date` = CURRENT_DATE(),
    `maintenance_action_performed` = 'Replaced worn seal kit, bled hydraulic line, and completed successful pressure cycle test.',
    `verified_by_user_id` = (SELECT `user_id` FROM `users` WHERE `username` = 'r.delrosario'),
    `verified_at` = NOW()
WHERE `work_order_number` = 'WO-2026-043';

-- 3.9 DELETE: Soft-cancel a work order
UPDATE `work_orders`
SET `status` = 'Cancelled'
WHERE `work_order_number` = 'WO-2026-043';

-- 3.10 DELETE: Hard delete a cancelled work order (and cascade delete its history)
DELETE FROM `work_orders`
WHERE `work_order_number` = 'WO-2026-043';


-- ==============================================================================
-- 4. MAINTENANCE SCHEDULE (maintenance_schedules table)
-- Reference: Page 2 (Upcoming Maintenance), schedule.html
-- ==============================================================================

-- 4.1 CREATE (INSERT): Schedule an upcoming inspection
INSERT INTO `maintenance_schedules` (
    `aircraft_id`,
    `maintenance_type`,
    `scheduled_date`,
    `due_date`,
    `status`,
    `notes`
) VALUES (
    (SELECT `aircraft_id` FROM `aircraft` WHERE `registration_number` = 'RP-C1234'),
    'Propeller Dynamic Balance Check',
    '2026-11-15',
    '2026-11-20',
    'Upcoming',
    'Perform vibration spectrum analysis and dynamic balancer weight placement'
);

-- 4.2 SELECT: Upcoming Maintenance table (Matches Page 2 Dashboard)
SELECT 
    a.`registration_number` AS `Aircraft`,
    ms.`maintenance_type` AS `Maintenance Type`,
    DATE_FORMAT(ms.`scheduled_date`, '%b %d, %Y') AS `Scheduled Date`,
    ms.`status` AS `Status`
FROM `maintenance_schedules` ms
JOIN `aircraft` a ON ms.`aircraft_id` = a.`aircraft_id`
WHERE ms.`status` IN ('Upcoming', 'Due Soon', 'Overdue')
ORDER BY ms.`scheduled_date` ASC
LIMIT 3;

-- 4.3 SELECT: Overdue tasks needing immediate attention (Matches KPI Cards)
SELECT 
    ms.`schedule_id`,
    a.`registration_number`,
    ms.`maintenance_type`,
    ms.`scheduled_date`,
    DATEDIFF(CURRENT_DATE(), ms.`scheduled_date`) AS `days_overdue`
FROM `maintenance_schedules` ms
JOIN `aircraft` a ON ms.`aircraft_id` = a.`aircraft_id`
WHERE ms.`status` = 'Overdue'
ORDER BY ms.`scheduled_date` ASC;

-- 4.4 UPDATE: Mark schedule task as 'Completed' and link to work order
UPDATE `maintenance_schedules`
SET 
    `status` = 'Completed',
    `linked_work_order_id` = (SELECT `work_order_id` FROM `work_orders` WHERE `work_order_number` = 'WO-2026-001')
WHERE `schedule_id` = 2;

-- 4.5 UPDATE: Reschedule maintenance date
UPDATE `maintenance_schedules`
SET 
    `scheduled_date` = '2026-11-25',
    `status` = 'Upcoming'
WHERE `schedule_id` = 1;

-- 4.6 DELETE: Remove a scheduled maintenance item
DELETE FROM `maintenance_schedules`
WHERE `schedule_id` = 10;


-- ==============================================================================
-- 5. DIGITAL MAINTENANCE LOGBOOK (maintenance_logbook table)
-- Reference: Page 4 (Recent Maintenance History), logbook.html
-- ==============================================================================

-- 5.1 CREATE (INSERT): Insert an official logbook sign-off entry
INSERT INTO `maintenance_logbook` (
    `aircraft_id`,
    `work_order_id`,
    `activity_type`,
    `technician_id`,
    `log_date`,
    `result_summary`,
    `remarks`,
    `flight_hours_at_log`
) VALUES (
    (SELECT `aircraft_id` FROM `aircraft` WHERE `registration_number` = 'RP-C1234'),
    (SELECT `work_order_id` FROM `work_orders` WHERE `work_order_number` = 'WO-2026-003'),
    'Repair',
    (SELECT `user_id` FROM `users` WHERE `username` = 'a.cruz'),
    CURRENT_DATE(),
    'Right cowl fastener receptacle replaced and secured',
    'Installed new receptacle assembly per Cessna AMM 71-10-00. Torqued and verified flush.',
    1420.50
);

-- 5.2 SELECT: Aircraft Maintenance History (Matches Page 4 Aircraft Profile table)
SELECT 
    DATE_FORMAT(ml.`log_date`, '%b %d, %Y') AS `Date`,
    ml.`activity_type` AS `Activity`,
    CONCAT(u.`first_name`, ' ', u.`last_name`) AS `Technician`,
    ml.`result_summary` AS `Result`
FROM `maintenance_logbook` ml
JOIN `aircraft` a ON ml.`aircraft_id` = a.`aircraft_id`
JOIN `users` u ON ml.`technician_id` = u.`user_id`
WHERE a.`registration_number` = 'RP-C1234'
ORDER BY ml.`log_date` DESC
LIMIT 5;

-- 5.3 SELECT: Digital Maintenance Logbook with Search (Matches logbook.html)
SELECT 
    DATE_FORMAT(ml.`log_date`, '%b %d, %Y') AS `Date`,
    a.`registration_number` AS `Aircraft`,
    ml.`activity_type` AS `Activity`,
    wo.`work_order_number` AS `Work Order`,
    CONCAT(u.`first_name`, ' ', u.`last_name`) AS `Technician`,
    ml.`result_summary` AS `Result`
FROM `maintenance_logbook` ml
JOIN `aircraft` a ON ml.`aircraft_id` = a.`aircraft_id`
JOIN `users` u ON ml.`technician_id` = u.`user_id`
LEFT JOIN `work_orders` wo ON ml.`work_order_id` = wo.`work_order_id`
WHERE (a.`registration_number` LIKE '%RP-C%' OR ml.`result_summary` LIKE '%inspection%')
ORDER BY ml.`log_date` DESC;

-- 5.4 UPDATE: Update logbook entry remarks
UPDATE `maintenance_logbook`
SET `remarks` = 'Correction: Replaced both inner and outer retainers on cowl fastener assembly.'
WHERE `log_id` = 1;

-- 5.5 DELETE: Delete an erroneous logbook record
DELETE FROM `maintenance_logbook`
WHERE `log_id` = 99;


-- ==============================================================================
-- 6. USERS & AUTHENTICATION (users & password_resets tables)
-- Reference: Page 1 (Login & Forgot Password)
-- ==============================================================================

-- 6.1 CREATE (INSERT): Register a new technician
INSERT INTO `users` (
    `username`,
    `email`,
    `password_hash`,
    `first_name`,
    `last_name`,
    `role`,
    `phone`,
    `status`
) VALUES (
    'e.tan',
    'etan@jetlog.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'E.',
    'Tan',
    'Technician',
    '+63 917 111 0007',
    'Active'
);

-- 6.2 CREATE (INSERT): Generate a password reset token (Page 1 Forgot password?)
INSERT INTO `password_resets` (
    `email`,
    `token`,
    `expires_at`
) VALUES (
    'technician@jetlog.com',
    'A9F4E7C1B2384D01E6954271FCAB8901',
    DATE_ADD(NOW(), INTERVAL 1 HOUR)
);

-- 6.3 SELECT: User authentication lookup (Matches Page 1 Login)
SELECT 
    `user_id`,
    `username`,
    `email`,
    `password_hash`,
    `first_name`,
    `last_name`,
    `role`,
    `status`
FROM `users`
WHERE (`email` = 'technician@jetlog.com' OR `username` = 'demo.technician')
  AND `status` = 'Active';

-- 6.4 UPDATE: Record user last login timestamp
UPDATE `users`
SET `last_login` = NOW()
WHERE `username` = 'demo.technician';

-- 6.5 UPDATE: Invalidate password reset token once used
UPDATE `password_resets`
SET `used` = 1
WHERE `token` = 'A9F4E7C1B2384D01E6954271FCAB8901';

-- 6.6 DELETE: Deactivate a technician account (Soft Delete)
UPDATE `users`
SET `status` = 'Inactive'
WHERE `username` = 'e.tan';

-- 6.7 DELETE: Purge expired password reset tokens
DELETE FROM `password_resets`
WHERE `expires_at` < NOW() OR `used` = 1;
