<?php
error_reporting(0);
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    echo json_encode(['success' => false, 'clerks' => []]);
    exit();
}
require_once 'db.php';
header('Content-Type: application/json');
$clerks = [];
$stmt = $conn->query("SELECT user_id AS id, full_name AS name FROM users WHERE role_id = 3 AND status = 'Active' ORDER BY full_name ASC");
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) { $clerks[] = $row; }
}
echo json_encode(['success' => true, 'clerks' => $clerks]);
$conn->close();