-- Create database if not exists (For local or VPS use. On InfinityFree, use the control panel to create it)
CREATE DATABASE IF NOT EXISTS `barber_shop` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `barber_shop`;

-- 1. Admins Table
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `failed_login_attempts` INT DEFAULT 0,
    `last_login_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Services Table
CREATE TABLE IF NOT EXISTS `services` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `price` DECIMAL(10,2) NOT NULL,
    `duration_minutes` INT NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert initial sample services
INSERT INTO `services` (`name`, `description`, `price`, `duration_minutes`) VALUES
('Classic Haircut', 'Traditional precision haircut tailored to your style.', 50.00, 30),
('Skin Fade', 'Clean, sharp, and seamless skin fade.', 60.00, 45),
('Beard Trim', 'Professional beard shaping, trimming, and conditioning.', 35.00, 20),
('Haircut and Beard Combo', 'Complete grooming package for hair and beard.', 80.00, 60),
('Kids Haircut', 'Gentle and stylish haircuts for young gentlemen (under 12).', 40.00, 30),
('Hair Wash and Styling', 'Invigorating hair wash followed by premium styling.', 25.00, 15);

-- 3. Customers Table
CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `email` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Appointments Table
CREATE TABLE IF NOT EXISTS `appointments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `booking_reference` VARCHAR(20) NOT NULL UNIQUE,
    `customer_id` INT NOT NULL,
    `service_id` INT NULL,
    `service_name_snapshot` VARCHAR(100) NOT NULL,
    `price_snapshot` DECIMAL(10,2) NOT NULL,
    `duration_minutes_snapshot` INT NOT NULL,
    `appointment_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `appointment_status` ENUM('pending', 'confirmed', 'completed', 'cancelled', 'rejected') DEFAULT 'pending',
    `payment_status` ENUM('unpaid', 'paid') DEFAULT 'unpaid',
    `payment_method` VARCHAR(50) DEFAULT 'Pay at Shop',
    `customer_notes` TEXT NULL,
    `admin_notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL,
    INDEX `idx_date_status` (`appointment_date`, `appointment_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Payments Table (Offline payment records)
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `appointment_id` INT NOT NULL UNIQUE,
    `amount` DECIMAL(10,2) NOT NULL,
    `payment_method` VARCHAR(50) DEFAULT 'Pay at Shop',
    `received_at` DATETIME NOT NULL,
    `recorded_by_admin_id` INT NOT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`recorded_by_admin_id`) REFERENCES `admins`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Business Settings Table
CREATE TABLE IF NOT EXISTS `business_settings` (
    `setting_key` VARCHAR(50) PRIMARY KEY,
    `setting_value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert initial business settings
INSERT INTO `business_settings` (`setting_key`, `setting_value`) VALUES
('business_name', 'Barbershop'),
('phone', '+966 59 478 7053'),
('address', 'As Sulay, Riyadh 14322, Saudi Arabia'),
('maps_url', 'https://www.google.com/maps/search/?api=1&query=JR9Q%2BC2%2C%20As%20Sulay%2C%20Riyadh%2C%20Saudi%20Arabia'),
('currency', 'SAR'),
('timezone', 'Asia/Riyadh'),
('booking_interval_minutes', '30'),
('advance_booking_days', '30');

-- 7. Working Hours Table
CREATE TABLE IF NOT EXISTS `working_hours` (
    `day_of_week` TINYINT NOT NULL PRIMARY KEY COMMENT '1=Mon, 7=Sun',
    `start_time` TIME NOT NULL DEFAULT '00:00:00',
    `end_time` TIME NOT NULL DEFAULT '23:59:00',
    `is_off` TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert 24-hour default schedule
INSERT INTO `working_hours` (`day_of_week`, `start_time`, `end_time`, `is_off`) VALUES
(1, '00:00:00', '23:59:00', 0),
(2, '00:00:00', '23:59:00', 0),
(3, '00:00:00', '23:59:00', 0),
(4, '00:00:00', '23:59:00', 0),
(5, '00:00:00', '23:59:00', 0),
(6, '00:00:00', '23:59:00', 0),
(7, '00:00:00', '23:59:00', 0);

-- 8. Blocked Times Table
CREATE TABLE IF NOT EXISTS `blocked_times` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `block_date` DATE NOT NULL,
    `start_time` TIME NOT NULL DEFAULT '00:00:00',
    `end_time` TIME NOT NULL DEFAULT '23:59:00',
    `reason` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Scheduling Locks Table (Used for concurrency control during booking)
CREATE TABLE IF NOT EXISTS `scheduling_locks` (
    `lock_date` DATE PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Admin Audit Logs
CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NULL,
    `action_type` VARCHAR(50) NOT NULL,
    `description` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
