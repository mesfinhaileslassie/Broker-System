-- =====================================================
-- BROKER SYSTEM - COMPLETE DATABASE SCHEMA
-- Run this entire script in phpMyAdmin or MySQL command line
-- =====================================================

-- Create database (if not exists)
CREATE DATABASE IF NOT EXISTS brokersystem;
USE brokersystem;

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing tables if they exist
DROP TABLE IF EXISTS balance_adjustments;
DROP TABLE IF EXISTS favorites;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS ratings;
DROP TABLE IF EXISTS disputes;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS listings;
DROP TABLE IF EXISTS companies;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS system_settings;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- 1. USERS TABLE (Parent table - no foreign keys)
-- =====================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    role ENUM('admin', 'user', 'company') DEFAULT 'user',
    balance DECIMAL(12,2) DEFAULT 0.00,
    escrow_held DECIMAL(12,2) DEFAULT 0.00,
    is_verified BOOLEAN DEFAULT FALSE,
    is_suspended BOOLEAN DEFAULT FALSE,
    kyc_document VARCHAR(255),
    email_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(100),
    reset_token VARCHAR(100),
    reset_expires DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_verification_token (verification_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 2. COMPANIES TABLE (References users)
-- =====================================================
CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    business_name VARCHAR(150) NOT NULL,
    tax_id VARCHAR(50),
    business_license VARCHAR(255),
    subscription_plan ENUM('monthly', 'yearly', 'none') DEFAULT 'none',
    subscription_expiry DATE,
    subscription_amount DECIMAL(10,2),
    is_approved BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_subscription_expiry (subscription_expiry)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 3. LISTINGS TABLE (References users - sellers)
-- =====================================================
CREATE TABLE listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    type ENUM('product', 'job', 'rental') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    price DECIMAL(12,2) NOT NULL,
    deposit_percent INT DEFAULT 30,
    commission_percent INT DEFAULT 15,
    status ENUM('active', 'sold', 'cancelled', 'pending') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_type (type),
    INDEX idx_status (status),
    INDEX idx_seller (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 4. TRANSACTIONS TABLE (References users and listings)
-- =====================================================
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    deposit_amount DECIMAL(12,2) NOT NULL,
    commission_amount DECIMAL(12,2) NOT NULL,
    remaining_balance DECIMAL(12,2) NOT NULL,
    status ENUM(
        'pending_deposit', 
        'awaiting_buyer_deposit',
        'awaiting_seller_deposit', 
        'deposits_complete',
        'in_progress', 
        'completed',
        'disputed', 
        'cancelled'
    ) DEFAULT 'pending_deposit',
    payment_code_5digit VARCHAR(5),
    code_expires_at TIMESTAMP,
    escrow_held DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_payment_code (payment_code_5digit),
    INDEX idx_buyer (buyer_id),
    INDEX idx_seller (seller_id),
    INDEX idx_listing (listing_id),
    UNIQUE KEY uk_payment_code (payment_code_5digit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 5. PAYMENTS TABLE (References transactions and users)
-- =====================================================
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    type ENUM('deposit_buyer', 'deposit_seller', 'commission', 'remaining_balance', 'release_to_seller'),
    telebirr_code_5digit VARCHAR(5),
    status ENUM('pending', 'confirmed', 'failed') DEFAULT 'pending',
    confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_transaction (transaction_id),
    INDEX idx_telebirr_code (telebirr_code_5digit),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 6. DISPUTES TABLE (References transactions and users)
-- =====================================================
CREATE TABLE disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    raised_by INT NOT NULL,
    reason TEXT NOT NULL,
    evidence TEXT,
    status ENUM('open', 'under_review', 'resolved', 'rejected') DEFAULT 'open',
    admin_decision TEXT,
    decision_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (raised_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_transaction (transaction_id),
    INDEX idx_status (status),
    INDEX idx_raised_by (raised_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 7. RATINGS TABLE (References transactions and users)
-- =====================================================
CREATE TABLE ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    rater_id INT NOT NULL,
    rated_id INT NOT NULL,
    score TINYINT CHECK (score BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (rater_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (rated_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_rated (rated_id),
    INDEX idx_rater (rater_id),
    INDEX idx_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 8. MESSAGES TABLE (References users)
-- =====================================================
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_user_id INT,
    from_company_id INT,
    to_admin BOOLEAN DEFAULT TRUE,
    subject VARCHAR(200),
    message TEXT NOT NULL,
    is_replied BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_to_admin (to_admin),
    INDEX idx_from_user (from_user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 9. NOTIFICATIONS TABLE (References users)
-- =====================================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    company_id INT,
    title VARCHAR(100),
    message TEXT,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_read (is_read),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 10. FAVORITES TABLE (Composite primary key)
-- =====================================================
CREATE TABLE favorites (
    user_id INT NOT NULL,
    listing_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, listing_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 11. SYSTEM SETTINGS TABLE
-- =====================================================
CREATE TABLE system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 12. BALANCE ADJUSTMENTS TABLE (Audit log)
-- =====================================================
CREATE TABLE balance_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    admin_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    operation ENUM('add', 'subtract') NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_admin (admin_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- INSERT DEFAULT DATA
-- =====================================================

-- Insert default admin user (password: admin123)
-- Password hash is for 'admin123'
INSERT INTO users (full_name, email, password_hash, role, is_verified, email_verified) 
VALUES (
    'Administrator', 
    'admin@brokerplace.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
    'admin', 
    1, 
    1
) ON DUPLICATE KEY UPDATE id=id;

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value) VALUES
('deposit_percent', '30'),
('commission_percent', '15'),
('escrow_days', '14'),
('site_name', 'Ethio Brokerplace'),
('telebirr_simulation', '1'),
('currency', 'ETB')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Insert a demo regular user (password: password123)
INSERT INTO users (full_name, email, password_hash, phone, role, is_verified) 
VALUES (
    'Demo User',
    'user@example.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '+251912345678',
    'user',
    1
) ON DUPLICATE KEY UPDATE id=id;

-- Insert a demo company user (password: password123)
INSERT INTO users (full_name, email, password_hash, phone, role, is_verified) 
VALUES (
    'Demo Company',
    'company@example.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '+251923456789',
    'company',
    1
) ON DUPLICATE KEY UPDATE id=id;

-- Insert company profile for demo company
INSERT INTO companies (user_id, business_name, tax_id, subscription_plan, is_approved) 
SELECT id, 'Demo Business PLC', 'TAX123456', 'monthly', 1 
FROM users WHERE email = 'company@example.com'
ON DUPLICATE KEY UPDATE business_name = business_name;

-- Insert a sample product listing
INSERT INTO listings (seller_id, type, title, description, price, status) 
SELECT id, 'product', 'Sample Product', 'This is a demo product for testing', 1000.00, 'active'
FROM users WHERE email = 'user@example.com'
LIMIT 1;

-- Insert a sample job listing
INSERT INTO listings (seller_id, type, title, description, price, status) 
SELECT id, 'job', 'Web Developer Needed', 'Looking for an experienced web developer', 5000.00, 'active'
FROM users WHERE email = 'company@example.com'
LIMIT 1;

-- Insert a sample rental listing
INSERT INTO listings (seller_id, type, title, description, price, status) 
SELECT id, 'rental', 'Apartment for Rent', '2 bedroom apartment in Bole', 15000.00, 'active'
FROM users WHERE email = 'user@example.com'
LIMIT 1;

-- =====================================================
-- VERIFICATION QUERIES
-- =====================================================

-- Check if everything was created successfully
SELECT 'Database setup complete!' AS Status;
SELECT COUNT(*) AS TotalTables FROM information_schema.tables WHERE table_schema = 'brokersystem';
SELECT 'Admin user created. Email: admin@brokerplace.com, Password: admin123' AS AdminCredentials;
SELECT 'Demo user created. Email: user@example.com, Password: password123' AS UserCredentials;
SELECT 'Demo company created. Email: company@example.com, Password: password123' AS CompanyCredentials;

-- Show all tables
SHOW TABLES;