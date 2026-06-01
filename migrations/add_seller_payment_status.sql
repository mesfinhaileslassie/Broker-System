-- Optional: track seller listing payment progress on listings table.
-- Safe to run once; skip if column already exists.

ALTER TABLE listings
ADD COLUMN seller_payment_status ENUM(
    'pending',
    'deposit_paid',
    'partially_paid',
    'fully_paid'
) NOT NULL DEFAULT 'pending' AFTER status;
