<?php
// includes/payment_code.php - Shared Telebirr payment code expiry (10 minutes)

if (!defined('PAYMENT_CODE_EXPIRY_MINUTES')) {
    define('PAYMENT_CODE_EXPIRY_MINUTES', 10);
}

function paymentCodeMaxSeconds() {
    return (int) PAYMENT_CODE_EXPIRY_MINUTES * 60;
}

function ensurePaymentCodeTimezone($conn) {
    date_default_timezone_set('Africa/Addis_Ababa');
    if ($conn) {
        $conn->query("SET time_zone = '+03:00'");
    }
}

/**
 * Expire pending codes that are past due or legacy 30-minute windows.
 */
function expireLegacyLongPaymentCodes($conn, $transaction_id, $user_id, $type) {
    $transaction_id = (int) $transaction_id;
    $user_id = (int) $user_id;
    $type = $conn->real_escape_string($type);
    $max_sec = paymentCodeMaxSeconds();

    $conn->query("
        UPDATE payment_codes
        SET status = 'expired'
        WHERE transaction_id = $transaction_id
          AND user_id = $user_id
          AND type = '$type'
          AND status = 'pending'
          AND (
              expires_at <= NOW()
              OR TIMESTAMPDIFF(SECOND, NOW(), expires_at) > $max_sec
          )
    ");
}

function normalizePaymentCodeSeconds($seconds) {
    $seconds = max(0, (int) $seconds);
    return min(paymentCodeMaxSeconds(), $seconds);
}

/**
 * @return array{id:int,code:string,amount:float,seconds_remaining:int}|null
 */
function findValidPaymentCode($conn, $transaction_id, $user_id, $type) {
    ensurePaymentCodeTimezone($conn);
    expireLegacyLongPaymentCodes($conn, $transaction_id, $user_id, $type);

    $transaction_id = (int) $transaction_id;
    $user_id = (int) $user_id;
    $type = $conn->real_escape_string($type);
    $max_sec = paymentCodeMaxSeconds();

    $result = $conn->query("
        SELECT id, code, amount,
               TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
        FROM payment_codes
        WHERE transaction_id = $transaction_id
          AND user_id = $user_id
          AND type = '$type'
          AND status = 'pending'
          AND expires_at > NOW()
          AND TIMESTAMPDIFF(SECOND, NOW(), expires_at) BETWEEN 1 AND $max_sec
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $row['seconds_remaining'] = normalizePaymentCodeSeconds($row['seconds_remaining']);
        return $row;
    }

    return null;
}

/**
 * @return array{code:string,code_id:int,amount:float,seconds_remaining:int,created:bool,expiry_minutes:int}
 */
function getOrCreatePaymentCode($conn, $transaction_id, $user_id, $amount, $type) {
    ensurePaymentCodeTimezone($conn);

    $existing = findValidPaymentCode($conn, $transaction_id, $user_id, $type);
    if ($existing) {
        return [
            'code' => $existing['code'],
            'code_id' => (int) $existing['id'],
            'amount' => (float) $existing['amount'],
            'seconds_remaining' => (int) $existing['seconds_remaining'],
            'created' => false,
            'expiry_minutes' => PAYMENT_CODE_EXPIRY_MINUTES,
        ];
    }

    expireLegacyLongPaymentCodes($conn, $transaction_id, $user_id, $type);

    $transaction_id = (int) $transaction_id;
    $user_id = (int) $user_id;
    $amount = (float) $amount;
    $mins = (int) PAYMENT_CODE_EXPIRY_MINUTES;
    $type_sql = $conn->real_escape_string($type);

    do {
        $code = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $chk = $conn->prepare('SELECT id FROM payment_codes WHERE code = ? LIMIT 1');
        $chk->bind_param('s', $code);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
    } while ($exists);

    $code_sql = $conn->real_escape_string($code);
    $conn->query("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at)
        VALUES ('$code_sql', $transaction_id, $amount, $user_id, '$type_sql', DATE_ADD(NOW(), INTERVAL $mins MINUTE), 'pending', NOW())
    ");
    $code_id = (int) $conn->insert_id;

    $sec_row = $conn->query("
        SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
        FROM payment_codes WHERE id = $code_id
    ")->fetch_assoc();

    $seconds = normalizePaymentCodeSeconds($sec_row['seconds_remaining'] ?? ($mins * 60));

    return [
        'code' => $code,
        'code_id' => $code_id,
        'amount' => $amount,
        'seconds_remaining' => $seconds,
        'created' => true,
        'expiry_minutes' => $mins,
    ];
}

/**
 * Lookup a code for Telebirr / external verify (no session). Reactivates wrongly expired valid codes.
 *
 * @return array{ok:bool,error?:string,row?:array}
 */
function lookupPaymentCodeForExternalVerify($conn, $code) {
    ensurePaymentCodeTimezone($conn);
    $code = preg_replace('/[^0-9]/', '', (string) $code);
    if (strlen($code) !== 5) {
        return ['ok' => false, 'error' => 'Invalid code format. Must be 5 digits.'];
    }

    $code_sql = $conn->real_escape_string($code);
    $row = $conn->query("
        SELECT pc.*,
               COALESCE(l.title, CONCAT('Transaction #', t.id)) AS item_name,
               TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) AS seconds_remaining
        FROM payment_codes pc
        JOIN transactions t ON pc.transaction_id = t.id
        LEFT JOIN listings l ON t.listing_id = l.id
        WHERE pc.code = '$code_sql'
        LIMIT 1
    ")->fetch_assoc();

    if (!$row) {
        return ['ok' => false, 'error' => 'Invalid payment code. Please generate a code from your listing page.'];
    }

    $seconds = (int) $row['seconds_remaining'];
    $status = $row['status'];

    if ($status === 'used') {
        return ['ok' => false, 'error' => 'Code already used'];
    }

    if ($seconds <= 0) {
        if ($status === 'pending') {
            $conn->query("UPDATE payment_codes SET status = 'expired' WHERE id = " . (int) $row['id']);
        }
        return ['ok' => false, 'error' => 'Code expired. Please generate a new code on the website.'];
    }

    // Recover code if web polling marked it expired while still within the time window
    if ($status !== 'pending') {
        if ($status === 'expired') {
            $conn->query("UPDATE payment_codes SET status = 'pending' WHERE id = " . (int) $row['id']);
            $row['status'] = 'pending';
        } else {
            return ['ok' => false, 'error' => 'Code already ' . $status];
        }
    }

    $row['seconds_remaining'] = normalizePaymentCodeSeconds($seconds);
    return ['ok' => true, 'row' => $row];
}

/**
 * Status poll helper — do not expire sibling codes, only the row if truly past due.
 */
function refreshPaymentCodeExpiryState($conn, $code, $user_id) {
    ensurePaymentCodeTimezone($conn);
    $code = $conn->real_escape_string(preg_replace('/[^0-9]/', '', (string) $code));
    $user_id = (int) $user_id;

    $row = $conn->query("
        SELECT id, transaction_id, type, status,
               TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
        FROM payment_codes
        WHERE code = '$code' AND user_id = $user_id
        LIMIT 1
    ")->fetch_assoc();

    if (!$row || $row['status'] !== 'pending') {
        return $row;
    }

    if ((int) $row['seconds_remaining'] <= 0) {
        $conn->query("UPDATE payment_codes SET status = 'expired' WHERE id = " . (int) $row['id']);
        $row['status'] = 'expired';
        $row['seconds_remaining'] = 0;
    } else {
        $row['seconds_remaining'] = normalizePaymentCodeSeconds($row['seconds_remaining']);
    }

    return $row;
}
