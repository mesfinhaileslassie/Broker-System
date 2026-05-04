-- Database: broker_system

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE,
    phone VARCHAR(20) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,  -- For web login
    telebirr_pin VARCHAR(4),              -- 4-digit PIN for Telebirr simulation
    role ENUM('admin', 'user', 'company') DEFAULT 'user',
    balance DECIMAL(12,2) DEFAULT 0.00,
    escrow_held DECIMAL(12,2) DEFAULT 0.00,
    is_verified BOOLEAN DEFAULT FALSE,
    kyc_document VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_phone (phone),
    INDEX idx_role (role)
);

CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    business_name VARCHAR(150) NOT NULL,
    tax_id VARCHAR(50),
    subscription_plan ENUM('monthly', 'yearly', 'none') DEFAULT 'none',
    subscription_expiry DATE,
    subscription_amount DECIMAL(10,2),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
);

CREATE TABLE listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    type ENUM('product', 'job', 'rental') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    price DECIMAL(12,2) NOT NULL,
    deposit_percent INT DEFAULT 30,      -- Set by admin
    commission_percent INT DEFAULT 15,   -- Set by admin
    status ENUM('active', 'sold', 'cancelled', 'pending') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id),
    INDEX idx_type (type),
    INDEX idx_status (status)
);

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
        'pending_deposit',    'awaiting_buyer_deposit',
        'awaiting_seller_deposit', 'deposits_complete',
        'in_progress',        'completed',
        'disputed',           'cancelled'
    ) DEFAULT 'pending_deposit',
    payment_code_5digit VARCHAR(5),      -- Generated for Telebirr
    code_expires_at TIMESTAMP,
    escrow_held DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (buyer_id) REFERENCES users(id),
    FOREIGN KEY (seller_id) REFERENCES users(id),
    FOREIGN KEY (listing_id) REFERENCES listings(id),
    INDEX idx_status (status),
    INDEX idx_payment_code (payment_code_5digit),
    UNIQUE KEY uk_payment_code (payment_code_5digit)
);

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    type ENUM('deposit_buyer', 'deposit_seller', 'commission', 'remaining_balance', 'release_to_seller'),
    telebirr_code_5digit VARCHAR(5),
    status ENUM('pending', 'confirmed', 'failed') DEFAULT 'pending',
    confirmed_at TIMESTAMP NULL,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_transaction (transaction_id),
    INDEX idx_telebirr_code (telebirr_code_5digit)
);

CREATE TABLE disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    raised_by INT NOT NULL,
    reason TEXT NOT NULL,
    evidence TEXT,
    status ENUM('open', 'under_review', 'resolved', 'rejected') DEFAULT 'open',
    admin_decision TEXT,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id),
    INDEX idx_transaction (transaction_id)
);

CREATE TABLE ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    rater_id INT NOT NULL,
    rated_id INT NOT NULL,
    score TINYINT CHECK (score BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id),
    INDEX idx_rated (rated_id)
);

CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_user_id INT,
    from_company_id INT,
    to_admin BOOLEAN DEFAULT TRUE,
    subject VARCHAR(200),
    message TEXT NOT NULL,
    is_replied BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_to_admin (to_admin)
);

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    company_id INT,
    title VARCHAR(100),
    message TEXT,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_read (is_read)
);

CREATE TABLE favorites (
    user_id INT NOT NULL,
    listing_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, listing_id),
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
);

-- Admins table (optional, but admin is a user with role='admin')




-- Add system_settings table
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO system_settings (setting_key, setting_value) VALUES
('deposit_percent', '30'),
('commission_percent', '15'),
('escrow_days', '14'),
('site_name', 'Ethio Brokerplace')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Add is_suspended column to users if not exists
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_suspended BOOLEAN DEFAULT FALSE;

-- Create balance_adjustments table for audit
CREATE TABLE IF NOT EXISTS balance_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    admin_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    operation ENUM('add', 'subtract') NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (admin_id) REFERENCES users(id)
);




