<?php
// delete-user.php - Delete User Handler
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check login and admin role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

// Prevent self-deletion via direct URL (extra safety, though UI already disables it)
if (!isset($_GET['id']) || $_GET['id'] == $_SESSION['user_id']) {
    $_SESSION['error'] = 'You cannot delete your own account.';
    header('Location: users.php');
    exit;
}

$user_id = (int)$_GET['id'];

include 'includes/db.php';

// Fetch user details for logging or confirmation (optional but useful)
$stmt = $conn->prepare("SELECT username, full_name FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = 'User not found.';
    header('Location: users.php');
    exit;
}

$user = $result->fetch_assoc();

// Perform deletion
$delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
$delete_stmt->bind_param('i', $user_id);

if ($delete_stmt->execute()) {
    // Optional: Log the deletion (e.g., to a logs table or file)
    // For simplicity, we'll just set a success message
    $_SESSION['success'] = "User '{$user['full_name']}' (@{$user['username']}) has been permanently deleted.";
} else {
    $_SESSION['error'] = 'Failed to delete user. Please try again.';
}

$delete_stmt->close();
$stmt->close();
$conn->close();

header('Location: users.php');
exit;
?>