<?php
// includes/validation.php - Input Validation Functions

/**
 * Validate email address
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Ethiopian format)
 */
function validatePhone($phone) {
    // Remove spaces and special characters
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Check Ethiopian phone number patterns
    $patterns = [
        '/^\+251[0-9]{9}$/',      // +251XXXXXXXXX
        '/^0[0-9]{9}$/',           // 0XXXXXXXXX
        '/^[0-9]{10}$/'            // XXXXXXXXXX
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $phone)) {
            return true;
        }
    }
    return false;
}

/**
 * Validate password strength
 */
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    return $errors;
}

/**
 * Validate amount (positive number, max 2 decimal places)
 */
function validateAmount($amount) {
    if (!is_numeric($amount) || $amount <= 0) {
        return false;
    }
    // Check for more than 2 decimal places
    if (preg_match('/\.[0-9]{3,}$/', (string)$amount)) {
        return false;
    }
    return true;
}

/**
 * Validate date format (YYYY-MM-DD)
 */
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Sanitize string input
 */
function sanitizeString($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize integer
 */
function sanitizeInt($input) {
    return filter_var($input, FILTER_VALIDATE_INT);
}

/**
 * Sanitize float
 */
function sanitizeFloat($input) {
    return filter_var($input, FILTER_VALIDATE_FLOAT);
}

/**
 * Validate listing type
 */
function validateListingType($type) {
    $valid_types = ['product', 'job', 'rental'];
    return in_array($type, $valid_types);
}

/**
 * Validate transaction status
 */
function validateTransactionStatus($status) {
    $valid_statuses = [
        'pending_deposit', 'awaiting_buyer_deposit', 'awaiting_seller_deposit',
        'deposits_complete', 'in_progress', 'completed', 'disputed', 'cancelled'
    ];
    return in_array($status, $valid_statuses);
}

/**
 * Validate file upload
 */
function validateFileUpload($file, $max_size = 5242880, $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']) {
    $errors = [];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload failed";
        return $errors;
    }
    
    if ($file['size'] > $max_size) {
        $errors[] = "File size exceeds " . ($max_size / 1048576) . "MB limit";
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        $errors[] = "Invalid file type. Allowed: JPG, PNG, GIF, WEBP";
    }
    
    return $errors;
}

/**
 * Validate URL
 */
function validateUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Validate required fields
 */
function validateRequired($data, $fields) {
    $errors = [];
    foreach ($fields as $field) {
        if (empty($data[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
        }
    }
    return $errors;
}

/**
 * Validate email exists in database
 */
function validateEmailExists($conn, $email, $exclude_id = null) {
    $sql = "SELECT id FROM users WHERE email = ?";
    $params = [$email];
    $types = "s";
    
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Validate CSRF token
 */
function validateCSRF($token, $session_token) {
    return isset($token) && isset($session_token) && hash_equals($session_token, $token);
}

/**
 * Validate JSON string
 */
function validateJSON($string) {
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

/**
 * Validate bank account number (basic check)
 */
function validateBankAccount($account_number) {
    // Remove spaces and special characters
    $account_number = preg_replace('/[^0-9]/', '', $account_number);
    return strlen($account_number) >= 8 && strlen($account_number) <= 20;
}

/**
 * Validate Ethiopian Tax Identification Number (TIN)
 */
function validateTIN($tin) {
    // TIN is 10-15 digits
    return preg_match('/^[0-9]{10,15}$/', $tin);
}

/**
 * Validate business registration number
 */
function validateBusinessNumber($number) {
    // Basic validation - alphanumeric, 6-20 characters
    return preg_match('/^[A-Z0-9]{6,20}$/i', $number);
}

/**
 * Sanitize array input recursively
 */
function sanitizeArray($array) {
    if (!is_array($array)) {
        return sanitizeString($array);
    }
    
    $result = [];
    foreach ($array as $key => $value) {
        $result[$key] = is_array($value) ? sanitizeArray($value) : sanitizeString($value);
    }
    return $result;
}

/**
 * Get validation error summary
 */
function getValidationSummary($errors) {
    if (empty($errors)) {
        return null;
    }
    
    return [
        'has_errors' => true,
        'count' => count($errors),
        'errors' => $errors,
        'message' => implode(', ', $errors)
    ];
}
?>