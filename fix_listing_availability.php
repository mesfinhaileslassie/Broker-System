<?php
// fix_listing_availability.php - Run this once to fix the database

require_once 'config/database.php';

$conn = getDbConnection();

echo "=== Fixing Listing Availability System ===\n\n";

// 1. Add columns if they don't exist
$columns_to_add = [
    "availability_status" => "ENUM('available', 'reserved', 'sold', 'unavailable') DEFAULT 'available'",
    "sold_to_user_id" => "INT DEFAULT NULL",
    "sold_at" => "DATETIME DEFAULT NULL"
];

foreach ($columns_to_add as $col => $definition) {
    $check = $conn->query("SHOW COLUMNS FROM listings LIKE '$col'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE listings ADD COLUMN $col $definition");
        echo "✅ Added column: $col\n";
    } else {
        echo "⏭️ Column already exists: $col\n";
    }
}

// 2. Update any transactions that have been paid but listings not marked as reserved
$paid_transactions = $conn->query("
    SELECT DISTINCT t.listing_id, t.buyer_id, p.user_id
    FROM transactions t
    JOIN payments p ON t.id = p.transaction_id
    JOIN listings l ON t.listing_id = l.id
    WHERE p.status = 'confirmed' 
    AND p.type = 'deposit_buyer'
    AND (l.availability_status IS NULL OR l.availability_status = 'available')
    AND l.type = 'product'
");

$updated = 0;
while ($txn = $paid_transactions->fetch_assoc()) {
    $update = $conn->query("
        UPDATE listings 
        SET availability_status = 'reserved',
            sold_to_user_id = {$txn['buyer_id']},
            sold_at = NOW()
        WHERE id = {$txn['listing_id']}
    ");
    if ($update && $conn->affected_rows > 0) {
        $updated++;
        echo "✅ Fixed listing ID: {$txn['listing_id']}\n";
    }
}

echo "\n=== Fix Complete ===\n";
echo "Updated $updated listings to 'reserved' status.\n";
echo "Run this script again if you see the issue persist.\n";

$conn->close();
?>