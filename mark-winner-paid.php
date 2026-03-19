<?php
// ajax/mark-winner-paid.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include '../includes/db.php';

// Check if user is logged in and has permission
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get POST data
$winner_id = isset($_POST['winner_id']) ? intval($_POST['winner_id']) : 0;
$paid_date = isset($_POST['paid_date']) ? $_POST['paid_date'] : date('Y-m-d');
$payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
$transaction_no = isset($_POST['transaction_no']) ? $_POST['transaction_no'] : null;
$payment_notes = isset($_POST['payment_notes']) ? $_POST['payment_notes'] : null;

if ($winner_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid winner ID']);
    exit;
}

// Update member payment details
$sql = "UPDATE members SET 
            paid_date = ?,
            payment_method = ?,
            transaction_no = ?,
            payment_notes = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND winner_amount > 0";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ssssi', $paid_date, $payment_method, $transaction_no, $payment_notes, $winner_id);

if ($stmt->execute()) {
    // Log the payment
    $log_sql = "INSERT INTO payment_logs (member_id, amount, payment_date, payment_method, transaction_no, notes, created_by, created_at) 
                SELECT 
                    id,
                    winner_amount,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                FROM members 
                WHERE id = ?";
    
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param('ssssii', $paid_date, $payment_method, $transaction_no, $payment_notes, $_SESSION['user_id'], $winner_id);
    $log_stmt->execute();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Payment marked as paid successfully!',
        'paid_date' => date('d M Y', strtotime($paid_date))
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to update payment: ' . $conn->error
    ]);
}
?>