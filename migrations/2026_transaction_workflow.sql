-- Transaction workflow columns (run once on existing brokersystem database)
-- Safe to re-run: skip statements that error if column already exists.

ALTER TABLE transactions
    ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER remaining_balance;

ALTER TABLE transactions
    ADD COLUMN payment_status ENUM(
        'pending',
        'deposit_paid',
        'partially_paid',
        'fully_paid'
    ) NOT NULL DEFAULT 'pending' AFTER amount_paid;

ALTER TABLE transactions
    ADD COLUMN funds_status ENUM(
        'pending',
        'held_in_escrow',
        'seller_confirmed',
        'buyer_confirmed',
        'ready_for_release',
        'released',
        'completed',
        'disputed',
        'cancelled'
    ) NOT NULL DEFAULT 'pending' AFTER payment_status;

ALTER TABLE transactions
    ADD COLUMN seller_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER funds_status;

ALTER TABLE transactions
    ADD COLUMN buyer_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER seller_confirmed;

ALTER TABLE transactions
    ADD COLUMN seller_confirmed_at TIMESTAMP NULL DEFAULT NULL AFTER buyer_confirmed;

ALTER TABLE transactions
    ADD COLUMN buyer_confirmed_at TIMESTAMP NULL DEFAULT NULL AFTER seller_confirmed_at;

ALTER TABLE transactions
    ADD COLUMN funds_released_at TIMESTAMP NULL DEFAULT NULL AFTER buyer_confirmed_at;

-- Extend payments.type for full buyer payment (optional; skip if duplicate enum value error)
ALTER TABLE payments
    MODIFY COLUMN type ENUM(
        'deposit_buyer',
        'deposit_seller',
        'commission',
        'remaining_balance',
        'full_payment',
        'release_to_seller'
    ) NOT NULL;

-- Extend payment_codes.type if table exists
-- ALTER TABLE payment_codes MODIFY COLUMN type VARCHAR(32) NOT NULL;
