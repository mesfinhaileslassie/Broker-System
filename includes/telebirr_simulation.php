<?php
// includes/telebirr_simulation.php - Telebirr simulation transfer service

/**
 * Get the path to the shared Telebirr simulator JSON storage.
 * This is the same storage used by the desktop Telebirr simulator app.
 *
 * @return string
 */
function getTelebirrJsonFilePath() {
    return __DIR__ . '/../telebirr_users.json';
}

/**
 * Load Telebirr simulator data from JSON storage.
 *
 * @return array
 */
function loadTelebirrData() {
    $path = getTelebirrJsonFilePath();
    if (!file_exists($path)) {
        $data = [
            'users' => [],
            'transactions' => []
        ];
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $data;
    }

    $json = file_get_contents($path);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        $data = [
            'users' => [],
            'transactions' => []
        ];
    }

    if (!isset($data['users']) || !is_array($data['users'])) {
        $data['users'] = [];
    }
    if (!isset($data['transactions']) || !is_array($data['transactions'])) {
        $data['transactions'] = [];
    }

    return $data;
}

/**
 * Save Telebirr simulator data back to JSON storage.
 *
 * @param array $data
 * @return bool
 */
function saveTelebirrData(array $data) {
    $path = getTelebirrJsonFilePath();
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

/**
 * Normalize an Ethiopian Telebirr phone number to +251XXXXXXXXX format.
 *
 * @param string $phone
 * @return string
 */
function normalizeTelebirrPhone($phone) {
    $phone = preg_replace('/[^0-9+]/', '', trim($phone));
    if (strpos($phone, '+251') === 0 && strlen($phone) === 13) {
        return $phone;
    }
    if (strpos($phone, '0') === 0 && strlen($phone) === 10) {
        return '+251' . substr($phone, 1);
    }
    if (strlen($phone) === 9) {
        return '+251' . $phone;
    }
    return $phone;
}

/**
 * Get the platform Telebirr sender phone used for withdrawal payouts.
 *
 * @return string
 */
function getPlatformTelebirrPhone() {
    return '+251900000000';
}

/**
 * Ensure a Telebirr account exists in the simulator data.
 * If the account does not exist, create a placeholder account.
 *
 * @param string $phone
 * @param string $fullName
 * @param string $pin
 * @param float $initialBalance
 * @return array
 */
function ensureTelebirrAccount($phone, $fullName = 'Telebirr User', $pin = '1234', $initialBalance = 0.0) {
    $phone = normalizeTelebirrPhone($phone);
    $data = loadTelebirrData();

    if (isset($data['users'][$phone])) {
        return $data['users'][$phone];
    }

    $data['users'][$phone] = [
        'id' => count($data['users']) + 1,
        'phone' => $phone,
        'full_name' => $fullName,
        'pin' => $pin,
        'balance' => (float) $initialBalance,
        'level' => 1,
        'endekise' => 0.0,
        'rewards' => 0.0,
        'created_at' => date('c')
    ];

    saveTelebirrData($data);
    return $data['users'][$phone];
}

/**
 * Generate a unique Telebirr transfer reference.
 *
 * @return string
 */
function generateTelebirrTransferReference() {
    return 'TBR-' . time() . '-' . bin2hex(random_bytes(4));
}

/**
 * Perform a Telebirr transfer from sender to receiver inside the shared simulator.
 *
 * @param string $senderPhone
 * @param string $receiverPhone
 * @param float $amount
 * @param string|null $description
 * @param string|null $reference
 * @param string|null &$error
 * @return array|false
 */
function performTelebirrTransfer($senderPhone, $receiverPhone, $amount, $description = null, $reference = null, &$error = null) {
    $senderPhone = normalizeTelebirrPhone($senderPhone);
    $receiverPhone = normalizeTelebirrPhone($receiverPhone);
    $amount = round((float) $amount, 2);
    $description = trim($description ?: 'Telebirr withdrawal transfer');
    $reference = $reference ?: generateTelebirrTransferReference();

    if ($amount <= 0) {
        $error = 'Transfer amount must be greater than zero';
        return false;
    }

    $data = loadTelebirrData();
    if (!isset($data['users'][$senderPhone])) {
        ensureTelebirrAccount($senderPhone, 'Platform Telebirr Account', '1234', 10000000.00);
        $data = loadTelebirrData();
    }
    if (!isset($data['users'][$receiverPhone])) {
        ensureTelebirrAccount($receiverPhone, 'Telebirr Recipient', '1234', 0.00);
        $data = loadTelebirrData();
    }

    if ($data['users'][$senderPhone]['balance'] < $amount) {
        $error = 'Platform Telebirr account has insufficient balance';
        return false;
    }

    $data['users'][$senderPhone]['balance'] = round($data['users'][$senderPhone]['balance'] - $amount, 2);
    $data['users'][$receiverPhone]['balance'] = round($data['users'][$receiverPhone]['balance'] + $amount, 2);

    $transactionDate = date('d M Y H:i');
    array_unshift($data['transactions'], [
        'user_phone' => $senderPhone,
        'type' => 'transfer_out',
        'amount' => (float) $amount,
        'fee' => 0.0,
        'description' => $description,
        'reference' => $reference,
        'date' => $transactionDate,
        'status' => 'completed'
    ]);

    array_unshift($data['transactions'], [
        'user_phone' => $receiverPhone,
        'type' => 'transfer_in',
        'amount' => (float) $amount,
        'fee' => 0.0,
        'description' => $description,
        'reference' => $reference,
        'date' => $transactionDate,
        'status' => 'completed'
    ]);

    if (!saveTelebirrData($data)) {
        $error = 'Failed to save Telebirr simulation state';
        return false;
    }

    return [
        'reference' => $reference,
        'sender_phone' => $senderPhone,
        'receiver_phone' => $receiverPhone,
        'amount' => $amount,
        'description' => $description,
        'status' => 'success',
        'transferred_at' => date('Y-m-d H:i:s')
    ];
}
