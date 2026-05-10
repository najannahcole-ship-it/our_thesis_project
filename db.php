<?php
declare(strict_types=1);

try {
    $pdo = new PDO(
        "mysql:host=" . getenv("DB_HOST") .
        ";port=" . getenv("DB_PORT") .
        ";dbname=" . getenv("DB_NAME") .
        ";charset=utf8mb4",
        getenv("DB_USER"),
        getenv("DB_PASSWORD"),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}