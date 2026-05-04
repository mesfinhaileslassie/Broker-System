<?php
// ajax/search_users.php - AJAX endpoint for real-time user search

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check if admin is logged in
if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conn = getDbConnection();

// Get search parameters
$search = $_GET['search'] ?? '';
$role = $_GET['role'] ?? '';
$status = $_GET['status'] ?? '';
$verification = $_GET['verification'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where = [];
$params = [];
$types = "";

// Search across multiple fields
if (!empty($search)) {
    $where[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if (!empty($role)) {
    $where[] = "role = ?";
    $params[] = $role;
    $types .= "s";
}

if ($status === 'active') {
    $where[] = "is_suspended = 0";
} elseif ($status === 'banned') {
    $where[] = "is_suspended = 1";
}

if ($verification === 'verified') {
    $where[] = "is_verified = 1";
} elseif ($verification === 'unverified') {
    $where[] = "is_verified = 0";
}

$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Get total count
$countSql = "SELECT COUNT(*) as total FROM users $whereClause";
$stmt = $conn->prepare($countSql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

// Get users
$sql = "SELECT id, full_name, email, phone, city, role, balance, is_verified, is_suspended, created_at 
        FROM users $whereClause 
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = [
        'id' => $row['id'],
        'full_name' => $row['full_name'],
        'email' => $row['email'],
        'phone' => $row['phone'],
        'city' => $row['city'],
        'role' => $row['role'],
        'balance' => $row['balance'],
        'is_verified' => (bool)$row['is_verified'],
        'is_suspended' => (bool)$row['is_suspended'],
        'created_at' => $row['created_at']
    ];
}

$conn->close();

echo json_encode([
    'success' => true,
    'users' => $users,
    'total' => $total,
    'total_pages' => $totalPages,
    'current_page' => $page,
    'search_term' => $search
]);
?>