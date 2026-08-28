-- Machinery Rental Management System
-- Initial schema: users table (needed for auth flow)

CREATE DATABASE IF NOT EXISTS machinery_rental_system;
USE machinery_rental_system;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer', 'admin', 'operator') NOT NULL DEFAULT 'customer',
    approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
    email_verified TINYINT(1) NOT NULL DEFAULT 1,
    otp_hash VARCHAR(255) DEFAULT NULL,
    otp_expires DATETIME DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- To create your first admin account:
-- 1. Register normally through register.php (creates a 'customer' by default)
-- 2. Then promote that account in phpMyAdmin:
--    UPDATE users SET role = 'admin' WHERE email = 'your@email.com';
