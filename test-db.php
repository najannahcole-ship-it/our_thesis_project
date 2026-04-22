<?php
// Include your database.php file
require_once 'database.php';

try {
    // Test a simple query (e.g., count users in your table)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
    $result = $stmt->fetch();
    echo "Connection successful! Total users in database: " . $result['total'];
} catch (PDOException $e) {
    echo "Query failed: " . $e->getMessage();
}
?>