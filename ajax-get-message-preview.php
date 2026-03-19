<?php
// ajax-get-message-preview.php
session_start();
include 'includes/db.php';

$member_id = $_POST['member_id'] ?? 0;
$message_type = $_POST['message_type'] ?? 'reminder';
$custom_message = $_POST['custom_message'] ?? '';

if ($member_id == 0) {
    echo "Invalid member ID";
    exit;
}

// Fetch member data
$sql = "SELECT m.*, p.title AS plan_title FROM members m 
        JOIN plans p ON m.plan_id = p.id 
        WHERE m.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    echo "Member not found";
    exit;
}

// Function to generate message preview
function generateMessagePreview($member, $message_type, $custom_message = '') {
    $customer_name = htmlspecialchars($member['customer_name']);
    $agreement_no = $member['agreement_number'];
    $plan_title = $member['plan_title'];
    
    $preview = "";
    
    switch ($message_type) {
        case 'reminder':
            $preview = "🌟 Payment Reminder - Sri Vari Chits Private Limited 🌟\n\n";
            $preview .= "Dear $customer_name,\n\n";
            $preview .= "This is a friendly reminder for your upcoming chit payment:\n\n";
            $preview .= "• Agreement No: $agreement_no\n";
            $preview .= "• Plan: $plan_title\n\n";
            $preview .= "────────────────────\n";
            $preview .= "🌟 கட்டண நினைவூட்டல் - ஸ்ரீ வரி சிட்ஸ் பிரைவேட் லிமிடெட் 🌟\n\n";
            $preview .= "அன்புள்ள $customer_name,\n\n";
            $preview .= "உங்கள் வரவிருக்கும் சிட்டுக் கட்டணத்திற்கான நினைவூட்டல்:\n\n";
            $preview .= "• ஒப்பந்த எண்: $agreement_no\n";
            $preview .= "• திட்டம்: $plan_title\n";
            break;
            
        case 'payment_confirmation':
            $preview = "✅ Payment Confirmation - Sri Vari Chits Private Limited ✅\n\n";
            $preview .= "Dear $customer_name,\n\n";
            $preview .= "We have received your chit payment. Thank you!\n\n";
            $preview .= "• Agreement No: $agreement_no\n";
            $preview .= "• Plan: $plan_title\n";
            $preview .= "• Payment Date: " . date('d-m-Y') . "\n\n";
            $preview .= "────────────────────\n";
            $preview .= "✅ கட்டண உறுதிப்படுத்தல் - ஸ்ரீ வரி சிட்ஸ் பிரைவேட் லிமிடெட் ✅\n\n";
            $preview .= "அன்புள்ள $customer_name,\n\n";
            $preview .= "உங்கள் சிட்டுக் கட்டணம் பெறப்பட்டது. நன்றி!\n\n";
            $preview .= "• ஒப்பந்த எண்: $agreement_no\n";
            $preview .= "• திட்டம்: $plan_title\n";
            $preview .= "• கட்டண தேதி: " . date('d-m-Y') . "\n";
            break;
            
        case 'bid_winner':
            $preview = "🎉 Congratulations! Bid Winner Announcement 🎉\n\n";
            $preview .= "Dear $customer_name,\n\n";
            $preview .= "We are pleased to inform you that you have been declared as the Bid Winner!\n\n";
            $preview .= "• Agreement No: $agreement_no\n";
            $preview .= "• Plan: $plan_title\n\n";
            $preview .= "────────────────────\n";
            $preview .= "🎉 வாழ்த்துகள்! ஏல வெற்றியாளர் அறிவிப்பு 🎉\n\n";
            $preview .= "அன்புள்ள $customer_name,\n\n";
            $preview .= "நீங்கள் ஏல வெற்றியாளராக அறிவிக்கப்பட்டுள்ளதை தெரிவித்து மகிழ்கிறோம்!\n\n";
            $preview .= "• ஒப்பந்த எண்: $agreement_no\n";
            $preview .= "• திட்டம்: $plan_title\n";
            break;
            
        case 'custom':
            if (!empty($custom_message)) {
                $preview = "📢 Message from Sri Vari Chits Private Limited 📢\n\n";
                $preview .= "Dear $customer_name,\n\n";
                $preview .= "$custom_message\n\n";
                $preview .= "• Agreement No: $agreement_no\n";
                $preview .= "• Plan: $plan_title\n\n";
                $preview .= "────────────────────\n";
                $preview .= "📢 ஸ்ரீ வரி சிட்ஸ் பிரைவேட் லிமிடெட்டிலிருந்து செய்தி 📢\n\n";
                $preview .= "அன்புள்ள $customer_name,\n\n";
                $preview .= "$custom_message\n\n";
                $preview .= "• ஒப்பந்த எண்: $agreement_no\n";
                $preview .= "• திட்டம்: $plan_title\n";
            }
            break;
            
        default:
            $preview = "Sri Vari Chits Private Limited\n\n";
            $preview .= "Dear $customer_name,\n\n";
            $preview .= "This is regarding your chit agreement $agreement_no.\n";
            $preview .= "────────────────────\n";
            $preview .= "ஸ்ரீ வரி சிட்ஸ் பிரைவேட் லிமிடெட்\n\n";
            $preview .= "அன்புள்ள $customer_name,\n\n";
            $preview .= "இது உங்கள் சிட்டு ஒப்பந்தம் $agreement_no தொடர்பானது.\n";
    }
    
    return $preview;
}

echo generateMessagePreview($member, $message_type, $custom_message);
?>