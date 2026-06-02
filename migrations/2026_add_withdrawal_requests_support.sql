-- Add withdrawal_requests and wallet_transactions schema support for Telebirr and approval flows.
-- Safe to run once on an existing database. Skip statements that fail if the table or column already exists.

CREATE TABLE IF NOT EXISTS withdrawal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    telebirr_phone VARCHAR(20) DEFAULT NULL,
    bank_name VARCHAR(100) DEFAULT '',
    account_number VARCHAR(100) DEFAULT '',
    account_name VARCHAR(100) DEFAULT '',
    status ENUM('pending', 'approved', 'completed', 'rejected', 'failed') NOT NULL DEFAULT 'pending',
    telebirr_transfer_reference VARCHAR(100) DEFAULT NULL,
    telebirr_transfer_status ENUM('pending', 'success', 'failed') DEFAULT NULL,
    telebirr_sender_phone VARCHAR(20) DEFAULT NULL,
    telebirr_receiver_phone VARCHAR(20) DEFAULT NULL,
    telebirr_transfer_amount DECIMAL(12,2) DEFAULT NULL,
    telebirr_transfer_message TEXT,
    admin_notes TEXT,
    processed_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_telebirr_phone (telebirr_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    type VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE withdrawal_requests
    ADD COLUMN IF NOT EXISTS telebirr_phone VARCHAR(20) DEFAULT NULL AFTER amount;

ALTER TABLE withdrawal_requests
    ADD COLUMN IF NOT EXISTS telebirr_transfer_reference VARCHAR(100) DEFAULT NULL AFTER account_name;

ALTER TABLE withdrawal_requests
    ADD COLUMN IF NOT EXISTS telebirr_transfer_status ENUM('pending', 'success', 'failed') DEFAULT NULL AFTER telebirr_transfer_reference;

ALTER TABLE withdrawal_requests
    ADD COLUMN IF NOT EXISTS telebirr_sender_phone VARCHAR(20) DEFAULT NULL AFTER telebirr_transfer_status;

ALTER TABLE withdrawal_requests
    ADD COLUMN IF NOT EXISTS telebirr_receiver_phone VARCHAR(20) DEFAULT NULL AFTER telebirr_sender_phone;

ALTER TABLE withdrawal_requests
    ADD COLUMN IF NOT EXISTS telebirr_transfer_amount DECIMAL(12,2) DEFAULT NULL AFTER telebirr_receiver_phone;

ALTER TABLE withdrawal_requests
    ADD COLUMN IF NOT EXISTS telebirr_transfer_message TEXT AFTER telebirr_transfer_amount;

ALTER TABLE withdrawal_requests
    ADD COLUMN IF NOT EXISTS admin_notes TEXT AFTER telebirr_transfer_message;

ALTER TABLE withdrawal_requests
    ADD COLUMN IF NOT EXISTS processed_by INT DEFAULT NULL AFTER admin_notes;

ALTER TABLE withdrawal_requests
    ADD COLUMN IF NOT EXISTS processed_at TIMESTAMP NULL AFTER processed_by;

ALTER TABLE wallet_transactions
    ADD COLUMN IF NOT EXISTS type VARCHAR(50) NOT NULL AFTER amount;

ALTER TABLE wallet_transactions
    ADD COLUMN IF NOT EXISTS description TEXT AFTER type;

ALTER TABLE wallet_transactions
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER description;
