<?php
// includes/db.php - 100% WORKING VERSION

$servername = "localhost";
$username   = "u329947844_srivari";
$password   = "Srivari@29";                    // ← Your REAL password
$dbname     = "u329947844_srivari";        // ← Your REAL database name

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    error_log("DB Connection failed: " . mysqli_connect_error());
    die("Database connection failed. Please contact administrator.");
}

// Optional: Set charset to avoid emoji/unicode issues
mysqli_set_charset($conn, "utf8mb4");
?>