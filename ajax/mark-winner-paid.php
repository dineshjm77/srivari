<?php
// ajax/mark-winner-paid.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set JSON header
header('Content-Type: application/json');

session_start();
include '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

// Get POST data
$winner_id = isset($_POST['winner_id']) ? intval($_POST['winner_id']) : 0;
$paid_date = isset($_POST['paid_date']) ? $_POST['paid_date'] : date('Y-m-d');
$payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
$transaction_no = isset($_POST['transaction_no']) ? trim($_POST['transaction_no']) : null;
$payment_notes = isset($_POST['payment_notes']) ? trim($_POST['payment_notes']) : null;

if ($winner_id == 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid winner ID']);
    exit;
}

// Get winner details to verify
$sql_check = "SELECT winner_amount FROM members WHERE id = ?";
$stmt_check = $conn->prepare($sql_check);
$stmt_check->bind_param("i", $winner_id);
$stmt_check->execute();
$result_check = $stmt_check->get_result();
$winner = $result_check->fetch_assoc();
$stmt_check->close();

if (!$winner) {
    echo json_encode(['success' => false, 'message' => 'Winner not found']);
    exit;
}

// Update winner payment status
$sql_update = "UPDATE members SET 
                paid_date = ?,
                payment_method = ?,
                transaction_no = ?,
                payment_notes = ?,
                winner_paid = 1
              WHERE id = ?";

$stmt_update = $conn->prepare($sql_update);
$stmt_update->bind_param("ssssi", 
    $paid_date, 
    $payment_method, 
    $transaction_no, 
    $payment_notes, 
    $winner_id
);

if ($stmt_update->execute()) {
    // Log the payment
    $sql_log = "INSERT INTO payment_logs (member_id, amount, payment_date, payment_method, transaction_no, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt_log = $conn->prepare($sql_log);
    $stmt_log->bind_param("idssssi", 
        $winner_id,
        $winner['winner_amount'],
        $paid_date,
        $payment_method,
        $transaction_no,
        $payment_notes,
        $_SESSION['user_id']
    );
    $stmt_log->execute();
    $stmt_log->close();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Winner marked as paid successfully! Amount: ₹' . number_format($winner['winner_amount'], 2)
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update payment status']);
}

$stmt_update->close();
?>