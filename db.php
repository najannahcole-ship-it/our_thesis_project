<?php
// ============================================================
// db.php — Shared Database Connection
// Database: juancafe
// Include this in every PHP file that needs the DB.
// Usage: require_once 'db.php';
// After including, use $conn for all queries.
// ============================================================

$conn = new mysqli("localhost", "root", "", "juancafe");

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");


// ============================================================
// HELPER: get the franchisee record for the logged-in user
// ============================================================
// Returns an array with id, franchisee_name, branch_name,
// or null if this user has no linked franchisee record.
// Requires $_SESSION['user_id'] to be set.
// ============================================================
function getFranchiseeByUser($conn, $user_id) {
    $stmt = $conn->prepare(
        "SELECT id, franchisee_name, branch_name FROM franchisees WHERE user_id = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row; // null if not linked yet
}
?>
