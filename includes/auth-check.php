<?php
// Authentication check - include this at the top of all protected pages
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Store intended destination for redirect after login
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    
    // Set error message
    $_SESSION['error'] = "Please log in to access this page.";
    
    // Redirect to login page
    header("Location: login.php");
    exit;
}

// Optional: Check user status in database on each request for security
include 'db.php';
$user_id = $_SESSION['user_id'];
$sql = "SELECT status FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    if ($user['status'] !== 'active') {
        // User is inactive, log them out
        session_destroy();
        header("Location: login.php");
        exit;
    }
} else {
    // User not found in database, log them out
    session_destroy();
    header("Location: login.php");
    exit;
}

$stmt->close();
mysqli_close($conn);
?>