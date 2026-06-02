<?php
// includes/validation.php - Complete Validation & Sanitization System
// UPDATED: Strict Ethiopian phone validation
// REMOVED: TIN validation function

/**
 * ============================================
 * SANITIZATION FUNCTIONS
 * ============================================
 */

function sanitizeString($input) {
    if ($input === null) return '';
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sanitizeInt($input, $default = 0) {
    $filtered = filter_var($input, FILTER_VALIDATE_INT);
    return $filtered !== false ? $filtered : $default;
}

function sanitizeFloat($input, $default = 0.00) {
    $filtered = filter_var($input, FILTER_VALIDATE_FLOAT);
    return $filtered !== false ? $filtered : $default;
}

function sanitizeEmail($email) {
    $email = trim($email);
    return filter_var($email, FILTER_SANITIZE_EMAIL);
}

function sanitizeUrl($url) {
    $url = trim($url);
    return filter_var($url, FILTER_SANITIZE_URL);
}

function sanitizePhone($phone) {
    // Remove all non-digit characters except +
    return preg_replace('/[^0-9+]/', '', $phone);
}

function sanitizeArray($array) {
    if (!is_array($array)) {
        return sanitizeString($array);
    }
    
    $result = [];
    foreach ($array as $key => $value) {
        $result[sanitizeString($key)] = is_array($value) ? sanitizeArray($value) : sanitizeString($value);
    }
    return $result;
}

function sanitizeForDb($conn, $input) {
    return $conn->real_escape_string(trim($input));
}

function sanitizeFilename($filename) {
    $filename = basename($filename);
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    return $filename;
}

/**
 * ============================================
 * VALIDATION FUNCTIONS
 * ============================================
 */

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * STRICT Ethiopian phone validation
 * Must be exactly +251 followed by 9 digits
 * Example: +251912345678
 */
function validatePhone($phone) {
    $clean = preg_replace('/[^0-9+]/', '', $phone);
    // Must start with +251 and have exactly 13 characters total (+251 + 9 digits)
    return preg_match('/^\+251[0-9]{9}$/', $clean) === 1;
}

/**
 * Convert any Ethiopian phone format to standard +251XXXXXXXXX
 */
function normalizePhone($phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    
    // If starts with 0 (e.g., 0912345678), convert to +251
    if (strlen($clean) === 10 && $clean[0] === '0') {
        return '+251' . substr($clean, 1);
    }
    
    // If already has +251 and 9 digits
    if (strlen($clean) === 12 && substr($clean, 0, 3) === '251') {
        return '+' . $clean;
    }
    
    // If already in correct format
    if (preg_match('/^\+251[0-9]{9}$/', $clean)) {
        return $clean;
    }
    
    return $clean;
}

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

function validateAmount($amount) {
    if (!is_numeric($amount) || $amount <= 0) {
        return false;
    }
    if (preg_match('/\.[0-9]{3,}$/', (string)$amount)) {
        return false;
    }
    return true;
}

function validateRequired($data, $fields) {
    $errors = [];
    foreach ($fields as $field) {
        if (empty($data[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
        }
    }
    return $errors;
}

function validateLength($input, $min, $max) {
    $length = strlen(trim($input));
    return $length >= $min && $length <= $max;
}

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function validateUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function validateListingType($type) {
    $valid = ['product', 'job', 'rental'];
    return in_array($type, $valid);
}

function validateTransactionStatus($status) {
    $valid = ['pending_deposit', 'awaiting_buyer_deposit', 'awaiting_seller_deposit',
              'deposits_complete', 'in_progress', 'completed', 'disputed', 'cancelled'];
    return in_array($status, $valid);
}

// TIN VALIDATION FUNCTION REMOVED

function validateBankAccount($account) {
    $account = preg_replace('/[^0-9]/', '', $account);
    return strlen($account) >= 8 && strlen($account) <= 20;
}

function validateFileUpload($file, $maxSize = 5242880, $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']) {
    $errors = [];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload failed (Error code: " . $file['error'] . ")";
        return $errors;
    }
    
    if ($file['size'] > $maxSize) {
        $errors[] = "File size exceeds " . ($maxSize / 1048576) . "MB limit";
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = "Invalid file type. Allowed: JPG, PNG, GIF, WEBP";
    }
    
    return $errors;
}

function validateCSRF($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * ============================================
 * DATABASE VALIDATION
 * ============================================
 */

function emailExists($conn, $email, $excludeId = null) {
    $sql = "SELECT id FROM users WHERE LOWER(email) = LOWER(?)";
    $params = [$email];
    $types = "s";
    
    if ($excludeId) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Check if phone exists (for strict Ethiopian format)
 */
function phoneExists($conn, $phone, $excludeId = null) {
    $normalized = normalizePhone($phone);
    
    $sql = "SELECT id FROM users WHERE phone = ?";
    $params = [$normalized];
    $types = "s";
    
    if ($excludeId) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

function validateListingOwnership($conn, $listingId, $userId) {
    $stmt = $conn->prepare("SELECT id FROM listings WHERE id = ? AND seller_id = ?");
    $stmt->bind_param("ii", $listingId, $userId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function validateTransactionAccess($conn, $transactionId, $userId) {
    $stmt = $conn->prepare("SELECT id FROM transactions WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
    $stmt->bind_param("iii", $transactionId, $userId, $userId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * ============================================
 * INPUT PROCESSING (Combined)
 * ============================================
 */

function processFormInput($data, $rules) {
    $errors = [];
    $sanitized = [];
    
    foreach ($rules as $field => $rule) {
        $value = $data[$field] ?? '';
        
        switch ($rule['type'] ?? 'string') {
            case 'int':
                $sanitized[$field] = sanitizeInt($value);
                break;
            case 'float':
                $sanitized[$field] = sanitizeFloat($value);
                break;
            case 'email':
                $sanitized[$field] = sanitizeEmail($value);
                break;
            case 'phone':
                $sanitized[$field] = sanitizePhone($value);
                break;
            default:
                $sanitized[$field] = sanitizeString($value);
        }
        
        if (($rule['required'] ?? false) && empty($sanitized[$field])) {
            $errors[] = $rule['label'] . " is required";
        }
        
        if (($rule['type'] ?? '') == 'email' && !empty($sanitized[$field]) && !validateEmail($sanitized[$field])) {
            $errors[] = "Please enter a valid email address";
        }
        
        if (($rule['type'] ?? '') == 'phone' && !empty($sanitized[$field]) && !validatePhone($sanitized[$field])) {
            $errors[] = "Please enter a valid Ethiopian phone number (+251XXXXXXXXX)";
        }
        
        if (isset($rule['min']) && strlen($sanitized[$field]) < $rule['min']) {
            $errors[] = $rule['label'] . " must be at least " . $rule['min'] . " characters";
        }
        
        if (isset($rule['max']) && strlen($sanitized[$field]) > $rule['max']) {
            $errors[] = $rule['label'] . " must not exceed " . $rule['max'] . " characters";
        }
        
        if (isset($rule['in']) && !in_array($sanitized[$field], $rule['in'])) {
            $errors[] = "Please select a valid option for " . $rule['label'];
        }
    }
    
    return [
        'success' => empty($errors),
        'errors' => $errors,
        'data' => $sanitized
    ];
}

function getValidationErrorsHTML($errors) {
    if (empty($errors)) return '';
    
    $html = '<div class="alert alert-error" style="background: #fee2e2; color: #dc2626; padding: 12px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #dc2626;">';
    $html .= '<i class="fas fa-exclamation-triangle"></i> <strong>Please fix the following errors:</strong><ul style="margin-top: 8px; margin-left: 20px;">';
    foreach ($errors as $error) {
        $html .= '<li>' . htmlspecialchars($error) . '</li>';
    }
    $html .= '</ul></div>';
    
    return $html;
}

function logValidationError($message, $data = []) {
    $log = date('Y-m-d H:i:s') . " - " . $message;
    if (!empty($data)) {
        $log .= " - Data: " . json_encode($data);
    }
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    error_log($log . PHP_EOL, 3, $logDir . '/validation.log');
}

function validateIntRange($value, $min, $max) {
    $value = sanitizeInt($value);
    return $value >= $min && $value <= $max;
}

function validateAlphaNumeric($string, $allowSpaces = true) {
    $pattern = $allowSpaces ? '/^[a-zA-Z0-9\s]+$/' : '/^[a-zA-Z0-9]+$/';
    return preg_match($pattern, $string);
}

function validateJSON($string) {
    if (empty($string)) return true;
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}
?>