<?php
include 'includes/head.php';
include 'includes/db.php';

// Clear any pending results to fix "Commands out of sync" error
while ($conn->more_results()) {
    $conn->next_result();
    if ($result = $conn->store_result()) {
        $result->free();
    }
}

// Create monthly_balances table if it doesn't exist
function createMonthlyBalancesTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS monthly_balances (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        finance_id INT(11) NOT NULL,
        month INT(2) NOT NULL,
        year INT(4) NOT NULL,
        opening_balance DECIMAL(15,2) DEFAULT 0.00,
        closing_balance DECIMAL(15,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_finance_month_year (finance_id, month, year)
    )";
   
    if ($conn->query($sql) === TRUE) {
        return true;
    } else {
        error_log("Error creating monthly_balances table: " . $conn->error);
        return false;
    }
}

// Initialize monthly_balances table
createMonthlyBalancesTable($conn);

// NEW FUNCTION: Get total initial investment from database
function getTotalInitialInvestment($conn) {
    $finance_id = 1;
    $sql = "SELECT SUM(amount) as total_investment FROM initial_investment WHERE finance_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $finance_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['total_investment'] ?? 0.00;
}

// Function to get or calculate previous month's closing balance
function getPreviousMonthClosingBalance($conn, $current_month, $current_year) {
    $finance_id = 1;
    $initial_investment = getTotalInitialInvestment($conn);
   
    // Calculate previous month and year
    if ($current_month == 1) {
        $prev_month = 12;
        $prev_year = $current_year - 1;
    } else {
        $prev_month = $current_month - 1;
        $prev_year = $current_year;
    }
   
    // For January of any year, check if we have data for previous December
    // If no data exists, it means we're at the beginning, so return initial investment
    if ($current_month == 1) {
        $sql_check = "SELECT closing_balance FROM monthly_balances 
                     WHERE finance_id = ? AND month = 12 AND year = ? 
                     LIMIT 1";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("ii", $finance_id, $prev_year);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $has_previous_data = $result_check->num_rows > 0;
        $stmt_check->close();
        
        if (!$has_previous_data) {
            return $initial_investment;
        }
    }
   
    try {
        // Try to get closing balance from monthly_balances table
        $sql = "SELECT closing_balance FROM monthly_balances
                WHERE finance_id = ? AND month = ? AND year = ?
                ORDER BY id DESC LIMIT 1";
       
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $finance_id, $prev_month, $prev_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
       
        if ($row && $row['closing_balance'] !== null) {
            return (float)$row['closing_balance'];
        }
       
        // If no record found, calculate it dynamically
        return calculateMonthClosingBalance($conn, $prev_month, $prev_year);
       
    } catch (Exception $e) {
        // If any error, calculate it dynamically
        return calculateMonthClosingBalance($conn, $prev_month, $prev_year);
    }
}

// Function to calculate closing balance for a specific month
function calculateMonthClosingBalance($conn, $month, $year) {
    $finance_id = 1;
    $initial_investment = getTotalInitialInvestment($conn);
   
    // Get previous month's closing balance
    $previous_balance = getPreviousMonthClosingBalance($conn, $month, $year);
   
    // Calculate current month's transactions
    $emiDetails = getTotalPrincipalAndInterest($conn, '', '', $month, $year, 0);
    $regularEmiPrincipal = (float)str_replace(',', '', $emiDetails['regular_principal']);
    $regularEmiInterest = (float)str_replace(',', '', $emiDetails['regular_interest']);
    $regularOverdueCollected = (float)str_replace(',', '', getTotalOverdueChargesCollected($conn, '', '', $month, $year, 0));
    $documentCharges = (float)str_replace(',', '', getTotalDocumentCharges($conn, '', '', $month, $year, 0));
    $foreclosureDetails = getTotalForeclosureCollected($conn, '', '', $month, $year, 0);
    $expenses = (float)str_replace(',', '', getTotalExpenses($conn, '', '', $month, $year, 0));
    $loansIssued = (float)str_replace(',', '', getTotalLoanIssued($conn, '', '', $month, $year, 0));
   
    // Calculate closing balance
    // Current Balance = Previous Balance + All Income - Expenses - Loans Issued
    $regularEmiIncome = $regularEmiPrincipal + $regularEmiInterest + $regularOverdueCollected;
    $foreclosureIncome = (float)str_replace(',', '', $foreclosureDetails['total_amount']);
    $totalIncome = $regularEmiIncome + $foreclosureIncome + $documentCharges;
    
    $closing_balance = $previous_balance + $totalIncome - $expenses - $loansIssued;
   
    // Store in monthly_balances table for future reference
    storeMonthlyBalance($conn, $month, $year, $previous_balance, $closing_balance);
   
    return $closing_balance;
}

// Function to store monthly balance in database
function storeMonthlyBalance($conn, $month, $year, $opening_balance, $closing_balance) {
    $finance_id = 1;
   
    try {
        $sql = "INSERT INTO monthly_balances (finance_id, month, year, opening_balance, closing_balance)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE opening_balance = VALUES(opening_balance),
                closing_balance = VALUES(closing_balance), updated_at = CURRENT_TIMESTAMP";
       
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiidd", $finance_id, $month, $year, $opening_balance, $closing_balance);
        $stmt->execute();
        $stmt->close();
       
        return true;
    } catch (Exception $e) {
        error_log("Error storing monthly balance: " . $e->getMessage());
        return false;
    }
}

// MODIFIED: Get total investment from database
function getTotalInvestment($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    // Get total investment from initial_investment table
    $total_investment = getTotalInitialInvestment($conn);
    return number_format($total_investment, 2);
}

// MODIFIED: Get total foreclosure amounts collected - returns array with breakdown
function getTotalForeclosureCollected($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $finance_id = 1;
    $sql = "SELECT 
                SUM(principal_amount) as principal_total,
                SUM(interest_amount) as interest_total,
                SUM(overdue_charges) as overdue_total,
                SUM(foreclosure_charge) as foreclosure_charge_total,
                SUM(total_amount) as total_amount
            FROM foreclosures WHERE finance_id = ? AND status = 'paid'";
    $params = [$finance_id];
    $types = "i";
   
    if ($start_date && $end_date) {
        $sql .= " AND paid_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql .= " AND YEAR(paid_date) = ? AND MONTH(paid_date) = ?";
        $params[] = $selected_year;
        $params[] = $selected_month;
        $types .= "ii";
        if ($selected_day > 0) {
            $sql .= " AND DAY(paid_date) = ?";
            $params[] = $selected_day;
            $types .= "i";
        }
    } elseif ($selected_year) {
        $sql .= " AND YEAR(paid_date) = ?";
        $params[] = $selected_year;
        $types .= "i";
    }
   
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return [
        'principal' => $row['principal_total'] ?? 0,
        'interest' => $row['interest_total'] ?? 0,
        'overdue' => $row['overdue_total'] ?? 0,
        'foreclosure_charge' => $row['foreclosure_charge_total'] ?? 0,
        'total_amount' => $row['total_amount'] ?? 0
    ];
}

// Get foreclosure breakdown for display
function getForeclosureBreakdownDisplay($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $foreclosure_data = getTotalForeclosureCollected($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
    
    return [
        'principal' => number_format($foreclosure_data['principal'], 2),
        'interest' => number_format($foreclosure_data['interest'], 2),
        'overdue' => number_format($foreclosure_data['overdue'], 2),
        'foreclosure_charge' => number_format($foreclosure_data['foreclosure_charge'], 2),
        'total' => number_format($foreclosure_data['total_amount'], 2)
    ];
}

function getTotalLoanIssued($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $finance_id = 1;
    $sql = "SELECT SUM(loan_amount) as total FROM customers WHERE finance_id = ?";
    $params = [$finance_id];
    $types = "i";
   
    if ($start_date && $end_date) {
        $sql .= " AND emi_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql .= " AND YEAR(emi_date) = ? AND MONTH(emi_date) = ?";
        $params[] = $selected_year;
        $params[] = $selected_month;
        $types .= "ii";
        if ($selected_day > 0) {
            $sql .= " AND DAY(emi_date) = ?";
            $params[] = $selected_day;
            $types .= "i";
        }
    } elseif ($selected_year) {
        $sql .= " AND YEAR(emi_date) = ?";
        $params[] = $selected_year;
        $types .= "i";
    }
   
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total = $row['total'] ?? 0;
    $stmt->close();
    
    return number_format($total, 2);
}

// CORRECTED: Get total REGULAR EMI Received (ONLY regular EMI, NOT including foreclosure)
function getTotalEMIReceived($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $finance_id = 1;
    
    // Get regular EMI collections (sum of actual payments made)
    $sql_emi = "SELECT SUM(COALESCE(es.principal_paid, 0) + COALESCE(es.interest_paid, 0)) as emi_total
            FROM emi_schedule es
            JOIN customers c ON es.customer_id = c.id
            WHERE es.finance_id = ? AND es.status IN ('paid', 'partial')";
    $params_emi = [$finance_id];
    $types_emi = "i";
   
    if ($start_date && $end_date) {
        $sql_emi .= " AND es.paid_date BETWEEN ? AND ?";
        $params_emi[] = $start_date;
        $params_emi[] = $end_date;
        $types_emi .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql_emi .= " AND YEAR(es.paid_date) = ? AND MONTH(es.paid_date) = ?";
        $params_emi[] = $selected_year;
        $params_emi[] = $selected_month;
        $types_emi .= "ii";
        if ($selected_day > 0) {
            $sql_emi .= " AND DAY(es.paid_date) = ?";
            $params_emi[] = $selected_day;
            $types_emi .= "i";
        }
    } elseif ($selected_year) {
        $sql_emi .= " AND YEAR(es.paid_date) = ?";
        $params_emi[] = $selected_year;
        $types_emi .= "i";
    }
   
    $stmt_emi = $conn->prepare($sql_emi);
    if (!empty($params_emi)) {
        $stmt_emi->bind_param($types_emi, ...$params_emi);
    }
    $stmt_emi->execute();
    $result_emi = $stmt_emi->get_result();
    $row_emi = $result_emi->fetch_assoc();
    $emi_total = $row_emi['emi_total'] ?? 0;
    $stmt_emi->close();
    
    // NOTE: Foreclosure amounts are NOT included here to avoid double counting
    // Foreclosure will be shown separately
   
    return number_format($emi_total, 2);
}

// CORRECTED: Get total principal and interest - returns array with separate regular and foreclosure amounts
function getTotalPrincipalAndInterest($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $finance_id = 1;
    
    // Get REGULAR EMI principal and interest ACTUALLY PAID
    $sql_emi = "SELECT SUM(COALESCE(es.principal_paid, 0)) as principal_total,
                       SUM(COALESCE(es.interest_paid, 0)) as interest_total
            FROM emi_schedule es
            JOIN customers c ON es.customer_id = c.id
            WHERE es.finance_id = ? AND es.status IN ('paid', 'partial')";
    $params_emi = [$finance_id];
    $types_emi = "i";
   
    if ($start_date && $end_date) {
        $sql_emi .= " AND es.paid_date BETWEEN ? AND ?";
        $params_emi[] = $start_date;
        $params_emi[] = $end_date;
        $types_emi .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql_emi .= " AND YEAR(es.paid_date) = ? AND MONTH(es.paid_date) = ?";
        $params_emi[] = $selected_year;
        $params_emi[] = $selected_month;
        $types_emi .= "ii";
        if ($selected_day > 0) {
            $sql_emi .= " AND DAY(es.paid_date) = ?";
            $params_emi[] = $selected_day;
            $types_emi .= "i";
        }
    } elseif ($selected_year) {
        $sql_emi .= " AND YEAR(es.paid_date) = ?";
        $params_emi[] = $selected_year;
        $types_emi .= "i";
    }
   
    $stmt_emi = $conn->prepare($sql_emi);
    if (!empty($params_emi)) {
        $stmt_emi->bind_param($types_emi, ...$params_emi);
    }
    $stmt_emi->execute();
    $result_emi = $stmt_emi->get_result();
    $row_emi = $result_emi->fetch_assoc();
    $emi_principal = $row_emi['principal_total'] ?? 0;
    $emi_interest = $row_emi['interest_total'] ?? 0;
    $stmt_emi->close();
    
    // Get Foreclosure principal and interest separately
    $foreclosure_data = getTotalForeclosureCollected($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
    
    // Return both regular EMI and foreclosure amounts separately
    return [
        'regular_principal' => number_format($emi_principal, 2),
        'regular_interest' => number_format($emi_interest, 2),
        'foreclosure_principal' => number_format($foreclosure_data['principal'], 2),
        'foreclosure_interest' => number_format($foreclosure_data['interest'], 2),
        'foreclosure_overdue' => number_format($foreclosure_data['overdue'], 2),
        'foreclosure_charge' => number_format($foreclosure_data['foreclosure_charge'], 2),
        'foreclosure_total' => number_format($foreclosure_data['total_amount'], 2)
    ];
}

// CORRECTED: Get total overdue charges collected (REGULAR EMI only, foreclosure overdue is separate)
function getTotalOverdueChargesCollected($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $finance_id = 1;
    
    // Get REGULAR EMI overdue charges
    $sql_emi = "SELECT SUM(COALESCE(es.overdue_charges, 0)) as total
            FROM emi_schedule es
            JOIN customers c ON es.customer_id = c.id
            WHERE es.finance_id = ? AND es.status IN ('paid', 'partial') AND es.overdue_charges > 0";
    $params_emi = [$finance_id];
    $types_emi = "i";
   
    if ($start_date && $end_date) {
        $sql_emi .= " AND es.paid_date BETWEEN ? AND ?";
        $params_emi[] = $start_date;
        $params_emi[] = $end_date;
        $types_emi .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql_emi .= " AND YEAR(es.paid_date) = ? AND MONTH(es.paid_date) = ?";
        $params_emi[] = $selected_year;
        $params_emi[] = $selected_month;
        $types_emi .= "ii";
        if ($selected_day > 0) {
            $sql_emi .= " AND DAY(es.paid_date) = ?";
            $params_emi[] = $selected_day;
            $types_emi .= "i";
        }
    } elseif ($selected_year) {
        $sql_emi .= " AND YEAR(es.paid_date) = ?";
        $params_emi[] = $selected_year;
        $types_emi .= "i";
    }
   
    $stmt_emi = $conn->prepare($sql_emi);
    if (!empty($params_emi)) {
        $stmt_emi->bind_param($types_emi, ...$params_emi);
    }
    $stmt_emi->execute();
    $result_emi = $stmt_emi->get_result();
    $row_emi = $result_emi->fetch_assoc();
    $emi_overdue = $row_emi['total'] ?? 0;
    $stmt_emi->close();
    
    // NOTE: Foreclosure overdue charges are NOT included here to avoid double counting
    // They are included in the foreclosure total
    
    return number_format($emi_overdue, 2);
}

function getTotalDocumentCharges($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $finance_id = 1;
    $sql = "SELECT SUM(document_charge) as total FROM customers WHERE finance_id = ?";
    $params = [$finance_id];
    $types = "i";
   
    if ($start_date && $end_date) {
        $sql .= " AND emi_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql .= " AND YEAR(emi_date) = ? AND MONTH(emi_date) = ?";
        $params[] = $selected_year;
        $params[] = $selected_month;
        $types .= "ii";
        if ($selected_day > 0) {
            $sql .= " AND DAY(emi_date) = ?";
            $params[] = $selected_day;
            $types .= "i";
        }
    } elseif ($selected_year) {
        $sql .= " AND YEAR(emi_date) = ?";
        $params[] = $selected_year;
        $types .= "i";
    }
   
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total = $row['total'] ?? 0;
    $stmt->close();
    
    return number_format($total, 2);
}

function getTotalExpenses($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $finance_id = 1;
    $sql = "SELECT SUM(amount) as total FROM expenses WHERE finance_id = ?";
    $params = [$finance_id];
    $types = "i";
   
    if ($start_date && $end_date) {
        $sql .= " AND expense_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql .= " AND YEAR(expense_date) = ? AND MONTH(expense_date) = ?";
        $params[] = $selected_year;
        $params[] = $selected_month;
        $types .= "ii";
        if ($selected_day > 0) {
            $sql .= " AND DAY(expense_date) = ?";
            $params[] = $selected_day;
            $types .= "i";
        }
    } elseif ($selected_year) {
        $sql .= " AND YEAR(expense_date) = ?";
        $params[] = $selected_year;
        $types .= "i";
    }
   
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total = $row['total'] ?? 0;
    $stmt->close();
    
    return number_format($total, 2);
}

function getOutstandingPrincipalAmount($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $finance_id = 1;
   
    // Get total principal amount from all loans issued in the period
    $sql_total_principal = "SELECT SUM(principal_amount) as total_principal FROM customers WHERE finance_id = ?";
    $params_total = [$finance_id];
    $types_total = "i";
   
    if ($start_date && $end_date) {
        $sql_total_principal .= " AND emi_date BETWEEN ? AND ?";
        $params_total[] = $start_date;
        $params_total[] = $end_date;
        $types_total .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql_total_principal .= " AND YEAR(emi_date) = ? AND MONTH(emi_date) = ?";
        $params_total[] = $selected_year;
        $params_total[] = $selected_month;
        $types_total .= "ii";
        if ($selected_day > 0) {
            $sql_total_principal .= " AND DAY(emi_date) = ?";
            $params_total[] = $selected_day;
            $types_total .= "i";
        }
    } elseif ($selected_year) {
        $sql_total_principal .= " AND YEAR(emi_date) = ?";
        $params_total[] = $selected_year;
        $types_total .= "i";
    }
   
    $stmt_total = $conn->prepare($sql_total_principal);
    if (!empty($params_total)) {
        $stmt_total->bind_param($types_total, ...$params_total);
    }
    $stmt_total->execute();
    $result_total = $stmt_total->get_result();
    $row_total = $result_total->fetch_assoc();
    $total_principal = $row_total['total_principal'] ?? 0;
    $stmt_total->close();
   
    // Get total principal collected in the period (from regular EMI payments only)
    $emiDetails = getTotalPrincipalAndInterest($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
    $regular_collected_principal = (float)str_replace(',', '', $emiDetails['regular_principal']);
    $foreclosure_collected_principal = (float)str_replace(',', '', $emiDetails['foreclosure_principal']);
    $total_collected_principal = $regular_collected_principal + $foreclosure_collected_principal;
   
    // Outstanding principal = Total principal issued - Total principal collected
    $outstanding_principal = $total_principal - $total_collected_principal;
   
    return number_format(max(0, $outstanding_principal), 2);
}

// CORRECTED: Get current balance with clear calculation
function getCurrentBalance($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $initial_investment = getTotalInitialInvestment($conn);
    
    // If we're viewing a specific month, calculate running balance
    if ($selected_month !== 'all' && $selected_year && !$start_date && !$end_date) {
        $previousBalance = getPreviousMonthClosingBalance($conn, $selected_month, $selected_year);
    } else {
        // For custom date ranges or all data, use initial investment from database
        $previousBalance = $initial_investment;
    }
   
    // Get income components for current period
    $emiDetails = getTotalPrincipalAndInterest($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
    
    // Regular EMI components
    $regularPrincipalCollected = (float)str_replace(',', '', $emiDetails['regular_principal']);
    $regularInterestCollected = (float)str_replace(',', '', $emiDetails['regular_interest']);
    $regularOverdueCollected = (float)str_replace(',', '', getTotalOverdueChargesCollected($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day));
    
    // Foreclosure components
    $foreclosurePrincipalCollected = (float)str_replace(',', '', $emiDetails['foreclosure_principal']);
    $foreclosureInterestCollected = (float)str_replace(',', '', $emiDetails['foreclosure_interest']);
    $foreclosureOverdueCollected = (float)str_replace(',', '', $emiDetails['foreclosure_overdue']);
    $foreclosureChargesCollected = (float)str_replace(',', '', $emiDetails['foreclosure_charge']);
    $totalForeclosureCollected = (float)str_replace(',', '', $emiDetails['foreclosure_total']);
    
    $documentCharges = (float)str_replace(',', '', getTotalDocumentCharges($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day));
   
    // Get expenses for current period
    $expenses = (float)str_replace(',', '', getTotalExpenses($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day));
   
    // Get loans issued in current period
    $loansIssued = (float)str_replace(',', '', getTotalLoanIssued($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day));
   
    // Current Balance = Previous Balance + All Income - Expenses - Loans Issued
    // All Income includes: Regular EMI (Principal+Interest+Overdue) + Foreclosure + Document Charges
    $regularEmiIncome = $regularPrincipalCollected + $regularInterestCollected + $regularOverdueCollected;
    $foreclosureIncome = $totalForeclosureCollected;
    $totalIncome = $regularEmiIncome + $foreclosureIncome + $documentCharges;
    
    $netCashFlow = $totalIncome - $expenses;
    $currentBalance = $previousBalance + $netCashFlow - $loansIssued;
   
    // Store the current month's balance if viewing specific month
    if ($selected_month !== 'all' && $selected_year && !$start_date && !$end_date) {
        storeMonthlyBalance($conn, $selected_month, $selected_year, $previousBalance, $currentBalance);
    }
   
    return number_format($currentBalance, 2);
}

// CORRECTED: Get profit/loss calculation
function getProfitLoss($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    // Get income components (EXCLUDING PRINCIPAL for profit/loss calculation)
    $emiDetails = getTotalPrincipalAndInterest($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
    
    // Regular EMI income (excluding principal)
    $regularInterestCollected = (float)str_replace(',', '', $emiDetails['regular_interest']);
    $regularOverdueCollected = (float)str_replace(',', '', getTotalOverdueChargesCollected($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day));
    
    // Foreclosure income (excluding principal)
    $foreclosureInterestCollected = (float)str_replace(',', '', $emiDetails['foreclosure_interest']);
    $foreclosureOverdueCollected = (float)str_replace(',', '', $emiDetails['foreclosure_overdue']);
    $foreclosureChargesCollected = (float)str_replace(',', '', $emiDetails['foreclosure_charge']);
    
    $documentCharges = (float)str_replace(',', '', getTotalDocumentCharges($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day));
   
    // Get expenses
    $expenses = (float)str_replace(',', '', getTotalExpenses($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day));
   
    // Profit/Loss = (Interest + Overdue Charges + Document Charges + Foreclosure Income) - Expenses
    // Note: Principal collected is NOT included in profit/loss as it's return of capital
    $profitLoss = ($regularInterestCollected + $regularOverdueCollected + $documentCharges + 
                   $foreclosureInterestCollected + $foreclosureOverdueCollected + $foreclosureChargesCollected) - $expenses;
   
    return number_format($profitLoss, 2);
}

// CORRECTED: Get cash flow breakdown with clear separation
function getCashFlowBreakdown($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $initial_investment = getTotalInitialInvestment($conn);
    
    // Get previous balance
    if ($selected_month !== 'all' && $selected_year && !$start_date && !$end_date) {
        $previousBalance = getPreviousMonthClosingBalance($conn, $selected_month, $selected_year);
    } else {
        $previousBalance = $initial_investment;
    }
   
    // Get current period data
    $loansIssued = (float)str_replace(',', '', getTotalLoanIssued($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day));
    
    // Get EMI collections (regular EMI only, foreclosure separate)
    $emiDetails = getTotalPrincipalAndInterest($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
    
    // Regular EMI components
    $regularPrincipalCollected = (float)str_replace(',', '', $emiDetails['regular_principal']);
    $regularInterestCollected = (float)str_replace(',', '', $emiDetails['regular_interest']);
    $regularOverdueCollected = (float)str_replace(',', '', getTotalOverdueChargesCollected($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day));
    $documentCharges = (float)str_replace(',', '', getTotalDocumentCharges($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day));
    
    // Foreclosure components
    $foreclosurePrincipalCollected = (float)str_replace(',', '', $emiDetails['foreclosure_principal']);
    $foreclosureInterestCollected = (float)str_replace(',', '', $emiDetails['foreclosure_interest']);
    $foreclosureOverdueCollected = (float)str_replace(',', '', $emiDetails['foreclosure_overdue']);
    $foreclosureChargesCollected = (float)str_replace(',', '', $emiDetails['foreclosure_charge']);
    $totalForeclosureCollected = (float)str_replace(',', '', $emiDetails['foreclosure_total']);
    
    $expenses = (float)str_replace(',', '', getTotalExpenses($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day));
   
    // Calculate totals
    $regularEmiIncome = $regularPrincipalCollected + $regularInterestCollected + $regularOverdueCollected;
    $foreclosureIncome = $totalForeclosureCollected;
    $totalIncome = $regularEmiIncome + $foreclosureIncome + $documentCharges;
    
    $netCashFlow = $totalIncome - $expenses;
    $currentBalance = $previousBalance + $netCashFlow - $loansIssued;
   
    return [
        'previous_balance' => number_format($previousBalance, 2),
        'loans_issued' => number_format($loansIssued, 2),
        'regular_emi_income' => number_format($regularEmiIncome, 2),
        'regular_principal' => number_format($regularPrincipalCollected, 2),
        'regular_interest' => number_format($regularInterestCollected, 2),
        'regular_overdue' => number_format($regularOverdueCollected, 2),
        'foreclosure_income' => number_format($foreclosureIncome, 2),
        'foreclosure_principal' => number_format($foreclosurePrincipalCollected, 2),
        'foreclosure_interest' => number_format($foreclosureInterestCollected, 2),
        'foreclosure_overdue' => number_format($foreclosureOverdueCollected, 2),
        'foreclosure_charges' => number_format($foreclosureChargesCollected, 2),
        'document_charges' => number_format($documentCharges, 2),
        'total_income' => number_format($totalIncome, 2),
        'total_expenses' => number_format($expenses, 2),
        'net_cash_flow' => number_format($netCashFlow, 2),
        'current_balance' => number_format($currentBalance, 2)
    ];
}

// Rest of the functions remain the same...
function getCollectionList($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $finance_id = 1;
    $sql = "SELECT c.customer_name, c.agreement_number, 
                   (COALESCE(es.principal_paid, 0) + COALESCE(es.interest_paid, 0) + COALESCE(es.overdue_charges, 0)) as amount, 
                   es.paid_date, 'EMI' as type
            FROM emi_schedule es
            JOIN customers c ON es.customer_id = c.id
            WHERE es.finance_id = ? AND es.status IN ('paid', 'partial')";
    $params = [$finance_id];
    $types = "i";
   
    if ($start_date && $end_date) {
        $sql .= " AND es.paid_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql .= " AND YEAR(es.paid_date) = ? AND MONTH(es.paid_date) = ?";
        $params[] = $selected_year;
        $params[] = $selected_month;
        $types .= "ii";
        if ($selected_day > 0) {
            $sql .= " AND DAY(es.paid_date) = ?";
            $params[] = $selected_day;
            $types .= "i";
        }
    } elseif ($selected_year) {
        $sql .= " AND YEAR(es.paid_date) = ?";
        $params[] = $selected_year;
        $types .= "i";
    }
   
    // Add foreclosure collections
    $sql .= " UNION ALL
            SELECT c.customer_name, c.agreement_number, f.total_amount, f.paid_date, 'Foreclosure' as type
            FROM foreclosures f
            JOIN customers c ON f.customer_id = c.id
            WHERE f.finance_id = ? AND f.status = 'paid'";
    $params[] = $finance_id;
    $types .= "i";
   
    if ($start_date && $end_date) {
        $sql .= " AND f.paid_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql .= " AND YEAR(f.paid_date) = ? AND MONTH(f.paid_date) = ?";
        $params[] = $selected_year;
        $params[] = $selected_month;
        $types .= "ii";
        if ($selected_day > 0) {
            $sql .= " AND DAY(f.paid_date) = ?";
            $params[] = $selected_day;
            $types .= "i";
        }
    } elseif ($selected_year) {
        $sql .= " AND YEAR(f.paid_date) = ?";
        $params[] = $selected_year;
        $types .= "i";
    }
   
    $sql .= " ORDER BY paid_date DESC LIMIT 15";
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $html = '';
   
    while ($row = $result->fetch_assoc()) {
        $typeBadge = $row['type'] == 'Foreclosure'
            ? '<span class="badge bg-danger">Foreclosure</span>'
            : '<span class="badge bg-success">EMI</span>';
       
        $html .= "<tr>
                    <td>{$row['customer_name']}</td>
                    <td>{$row['agreement_number']}</td>
                    <td>₹" . number_format($row['amount'], 2) . "</td>
                    <td>{$row['paid_date']}</td>
                    <td>{$typeBadge}</td>
                </tr>";
    }
    $stmt->close();
   
    return $html ?: "<tr><td colspan='5' class='text-center'>No collections found</td></tr>";
}

function getNonCollectionList($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $finance_id = 1;
    $sql = "SELECT c.customer_name, c.agreement_number, es.emi_amount, es.emi_due_date, es.status
            FROM emi_schedule es
            JOIN customers c ON es.customer_id = c.id
            WHERE es.finance_id = ? AND es.status IN ('unpaid', 'overdue')";
    $params = [$finance_id];
    $types = "i";
   
    if ($start_date && $end_date) {
        $sql .= " AND es.emi_due_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql .= " AND YEAR(es.emi_due_date) = ? AND MONTH(es.emi_due_date) = ?";
        $params[] = $selected_year;
        $params[] = $selected_month;
        $types .= "ii";
        if ($selected_day > 0) {
            $sql .= " AND DAY(es.emi_due_date) = ?";
            $params[] = $selected_day;
            $types .= "i";
        }
    } elseif ($selected_year) {
        $sql .= " AND YEAR(es.emi_due_date) = ?";
        $params[] = $selected_year;
        $types .= "i";
    }
   
    $sql .= " ORDER BY es.emi_due_date ASC LIMIT 10";
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $html = '';
   
    while ($row = $result->fetch_assoc()) {
        $statusBadge = $row['status'] == 'overdue'
            ? '<span class="badge bg-danger">Overdue</span>'
            : '<span class="badge bg-warning">Unpaid</span>';
       
        $html .= "<tr>
                    <td>{$row['customer_name']}</td>
                    <td>{$row['agreement_number']}</td>
                    <td>₹" . number_format($row['emi_amount'], 2) . "</td>
                    <td>{$row['emi_due_date']}</td>
                    <td>{$statusBadge}</td>
                </tr>";
    }
    $stmt->close();
   
    return $html ?: "<tr><td colspan='5' class='text-center'>No pending payments found</td></tr>";
}

function getNewCustomers($conn, $start_date = '', $end_date = '', $selected_month = '', $selected_year = '', $selected_day = 0) {
    $finance_id = 1;
    $sql = "SELECT c.customer_name, c.agreement_number, c.loan_amount, l.loan_name, c.emi_date
            FROM customers c
            JOIN loans l ON c.loan_id = l.id
            WHERE c.finance_id = ?";
    $params = [$finance_id];
    $types = "i";
   
    if ($start_date && $end_date) {
        $sql .= " AND c.emi_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    } elseif ($selected_month !== 'all' && $selected_year) {
        $sql .= " AND YEAR(c.emi_date) = ? AND MONTH(c.emi_date) = ?";
        $params[] = $selected_year;
        $params[] = $selected_month;
        $types .= "ii";
        if ($selected_day > 0) {
            $sql .= " AND DAY(c.emi_date) = ?";
            $params[] = $selected_day;
            $types .= "i";
        }
    } elseif ($selected_year) {
        $sql .= " AND YEAR(c.emi_date) = ?";
        $params[] = $selected_year;
        $types .= "i";
    }
   
    $sql .= " ORDER BY c.id DESC LIMIT 10";
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $html = '';
   
    while ($row = $result->fetch_assoc()) {
        $html .= "<tr>
                    <td>{$row['customer_name']}</td>
                    <td>{$row['agreement_number']}</td>
                    <td>₹" . number_format($row['loan_amount'], 2) . "</td>
                    <td>{$row['loan_name']}</td>
                    <td>{$row['emi_date']}</td>
                </tr>";
    }
    $stmt->close();
   
    return $html ?: "<tr><td colspan='5' class='text-center'>No new customers found</td></tr>";
}

// Get current month, year, and date
$current_month = date('m');
$current_year = date('Y');
$current_date = date('Y-m-d');
$current_day = date('d');

// Handle filter from GET parameters
$selected_month = isset($_GET['month']) ? $_GET['month'] : $current_month;
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;
$selected_day = isset($_GET['day']) ? intval($_GET['day']) : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

if ($selected_month !== 'all' && ($selected_month < 1 || $selected_month > 12)) {
    $selected_month = $current_month;
}

if ($selected_year < 2000 || $selected_year > date('Y') + 1) {
    $selected_year = $current_year;
}

$days_in_month = ($selected_month === 'all') ? 31 : cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);
if ($selected_day > $days_in_month) {
    $selected_day = 0;
}

if ($start_date && $end_date) {
    $start_date_obj = DateTime::createFromFormat('Y-m-d', $start_date);
    $end_date_obj = DateTime::createFromFormat('Y-m-d', $end_date);
    $max_date = (new DateTime())->modify('+1 year')->format('Y-m-d');
   
    if (!$start_date_obj || !$end_date_obj || $start_date > $end_date || $end_date > $max_date) {
        $start_date = '';
        $end_date = '';
    }
}

$breadcrumb_active = ($start_date && $end_date)
    ? "Overall Report for " . (new DateTime($start_date))->format('d M Y') . " - " . (new DateTime($end_date))->format('d M Y')
    : ($selected_month === 'all'
        ? "Overall Report for " . $selected_year . ($selected_day > 0 ? ' - Day ' . $selected_day : '')
        : "Overall Report for " . date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year)) . ($selected_day > 0 ? ' - Day ' . $selected_day : ''));

// Get data for display
$totalInvestment = getTotalInvestment($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$totalLoanIssued = getTotalLoanIssued($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$totalEMIReceived = getTotalEMIReceived($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day); // Regular EMI only
$emiDetails = getTotalPrincipalAndInterest($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$regularPrincipalCollected = $emiDetails['regular_principal'];
$regularInterestCollected = $emiDetails['regular_interest'];
$foreclosurePrincipalCollected = $emiDetails['foreclosure_principal'];
$foreclosureInterestCollected = $emiDetails['foreclosure_interest'];
$foreclosureOverdueCollected = $emiDetails['foreclosure_overdue'];
$foreclosureChargesCollected = $emiDetails['foreclosure_charge'];
$totalForeclosureCollected = $emiDetails['foreclosure_total'];
$regularOverdueCollected = getTotalOverdueChargesCollected($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$totalDocumentCharges = getTotalDocumentCharges($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$foreclosureBreakdown = getForeclosureBreakdownDisplay($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$totalExpenses = getTotalExpenses($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$outstandingAmount = getOutstandingPrincipalAmount($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$currentBalance = getCurrentBalance($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$profitLoss = getProfitLoss($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$cashFlow = getCashFlowBreakdown($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$collectionList = getCollectionList($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$nonCollectionList = getNonCollectionList($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
$newCustomers = getNewCustomers($conn, $start_date, $end_date, $selected_month, $selected_year, $selected_day);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<head>
    <?php include 'includes/head.php'; ?>
</head>
<body>
    <?php include 'includes/topbar.php'; ?>
   
    <div class="startbar d-print-none">
        <?php include 'includes/leftbar-tab-menu.php'; ?>
        <?php include 'includes/leftbar.php'; ?>
        <div class="startbar-overlay d-print-none"></div>
       
        <div class="page-wrapper">
            <div class="page-content">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-12">
                            <div class="page-title-box d-md-flex justify-content-md-between align-items-center">
                                <h4 class="page-title">Overall Report</h4>
                                <ol class="breadcrumb mb-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active"><?php echo $breadcrumb_active; ?></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filter Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">Filter Overall Report</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label for="month" class="form-label fw-bold">Select Month</label>
                                        <select class="form-control" id="month" name="month">
                                            <option value="all" <?php echo ($selected_month === 'all') ? 'selected' : ''; ?>>All Months</option>
                                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                                <option value="<?php echo $m; ?>" <?php echo ($m == $selected_month) ? 'selected' : ''; ?>>
                                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="year" class="form-label fw-bold">Select Year</label>
                                        <select class="form-control" id="year" name="year">
                                            <?php for ($y = 2020; $y <= date('Y') + 1; $y++): ?>
                                                <option value="<?php echo $y; ?>" <?php echo ($y == $selected_year) ? 'selected' : ''; ?>>
                                                    <?php echo $y; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="day" class="form-label fw-bold">Select Day</label>
                                        <select class="form-control" id="day" name="day">
                                            <option value="0" <?php echo ($selected_day == 0) ? 'selected' : ''; ?>>All Days</option>
                                            <?php for ($d = 1; $d <= $days_in_month; $d++): ?>
                                                <option value="<?php echo $d; ?>" <?php echo ($d == $selected_day) ? 'selected' : ''; ?>>
                                                    <?php echo $d; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="start_date" class="form-label fw-bold">Start Date</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="end_date" class="form-label fw-bold">End Date</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary me-2">Apply Filter</button>
                                        <button type="button" id="exportPdfBtn" class="btn btn-success me-2">Export PDF</button>
                                        <?php if (isset($_GET['month']) || isset($_GET['year']) || isset($_GET['day']) || $start_date || $end_date): ?>
                                            <a href="overall-reports.php" class="btn btn-outline-secondary">Clear Filter</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <div class="alert alert-info mb-0">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Filter Info:</strong>
                                            <?php echo ($start_date && $end_date)
                                                ? "Showing data from " . (new DateTime($start_date))->format('d M Y') . " to " . (new DateTime($end_date))->format('d M Y')
                                                : ($selected_month === 'all'
                                                    ? "Showing all data for " . $selected_year . ($selected_day > 0 ? ' - Day ' . $selected_day : '')
                                                    : "Showing data for " . date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year)) . ($selected_day > 0 ? ' - Day ' . $selected_day : '')); ?>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Main Summary Cards -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card" id="balanceSummaryCard">
                                <div class="card-header d-flex align-items-center justify-content-between">
                                    <h6 class="card-title mb-0" style="font-weight: bold; font-size: 1.1rem; color: #333;">Cash Position Summary</h6>
                                    <!-- Add Investment Button -->
                                    <a href="add-investment.php" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-plus me-1"></i> Add Investment
                                    </a>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6 col-lg-4">
                                            <div class="card bg-primary text-white mb-3">
                                                <div class="card-body">
                                                    <h5 class="card-title">Total Investment</h5>
                                                    <h2 class="card-text">₹<?php echo $totalInvestment; ?></h2>
                                                    <small>Initial capital investment</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 col-lg-4">
                                            <div class="card bg-info text-white mb-3">
                                                <div class="card-body">
                                                    <h5 class="card-title">Current Balance</h5>
                                                    <h2 class="card-text">₹<?php echo $currentBalance; ?></h2>
                                                    <small>Cash in hand (Investment + All Income - Expenses - Loans Issued)</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 col-lg-4">
                                            <div class="card bg-warning text-white mb-3">
                                                <div class="card-body">
                                                    <h5 class="card-title">Loans Issued</h5>
                                                    <h2 class="card-text">₹<?php echo $totalLoanIssued; ?></h2>
                                                    <small>Total principal disbursed</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                   
                                    <div class="row">
                                        <div class="col-md-6 col-lg-3">
                                            <div class="card bg-success text-white mb-3">
                                                <div class="card-body">
                                                    <h5 class="card-title">Regular EMI Received</h5>
                                                    <h2 class="card-text">₹<?php echo $totalEMIReceived; ?></h2>
                                                    <small>Regular monthly EMI collections only</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 col-lg-3">
                                            <div class="card bg-dark text-white mb-3">
                                                <div class="card-body">
                                                    <h5 class="card-title">Foreclosure Collected</h5>
                                                    <h2 class="card-text">₹<?php echo $totalForeclosureCollected; ?></h2>
                                                    <small>Total loan settlement amounts</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 col-lg-3">
                                            <div class="card bg-secondary text-white mb-3">
                                                <div class="card-body">
                                                    <h5 class="card-title">Document Charges</h5>
                                                    <h2 class="card-text">₹<?php echo $totalDocumentCharges; ?></h2>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 col-lg-3">
                                            <div class="card bg-danger text-white mb-3">
                                                <div class="card-body">
                                                    <h5 class="card-title">Total Expenses</h5>
                                                    <h2 class="card-text">₹<?php echo $totalExpenses; ?></h2>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                   
                                    <div class="row">
                                        <div class="col-md-6 col-lg-6">
                                            <div class="card bg-dark text-white mb-3">
                                                <div class="card-body">
                                                    <h5 class="card-title">Outstanding Principal</h5>
                                                    <h2 class="card-text">₹<?php echo $outstandingAmount; ?></h2>
                                                    <small>Principal pending collection</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 col-lg-6">
                                            <div class="card <?php echo ((float)str_replace(',', '', $profitLoss) >= 0) ? 'bg-success' : 'bg-danger'; ?> text-white mb-3">
                                                <div class="card-body text-center">
                                                    <h4 class="card-title">Profit / Loss (This Period)</h4>
                                                    <h1 class="card-text">₹<?php echo $profitLoss; ?></h1>
                                                    <small>Interest + Overdue Charges + Document Charges + Foreclosure Income - Expenses</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                   
                                    <!-- Cash Flow Breakdown -->
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <div class="card">
                                                <div class="card-header bg-light">
                                                    <h6 class="card-title mb-0" style="font-weight: bold; font-size: 1.1rem; color: #333;">Cash Flow Breakdown</h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="row text-center">
                                                        <div class="col-md-3">
                                                            <div class="border p-3 rounded">
                                                                <h6>Previous Balance</h6>
                                                                <h4 class="text-primary">₹<?php echo $cashFlow['previous_balance']; ?></h4>
                                                                <small>Cash from previous month</small>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <div class="border p-3 rounded">
                                                                <h6>Cash Inflow</h6>
                                                                <h4 class="text-success">₹<?php echo $cashFlow['total_income']; ?></h4>
                                                                <small>Regular EMI + Foreclosure + Document Charges</small>
                                                                <div class="mt-2 small">
                                                                    <div>Regular EMI: ₹<?php echo $cashFlow['regular_emi_income']; ?></div>
                                                                    <div>Foreclosure: ₹<?php echo $cashFlow['foreclosure_income']; ?></div>
                                                                    <div>Document: ₹<?php echo $cashFlow['document_charges']; ?></div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <div class="border p-3 rounded">
                                                                <h6>Cash Outflow</h6>
                                                                <h4 class="text-danger">₹<?php echo number_format((float)str_replace(',', '', $cashFlow['total_expenses']) + (float)str_replace(',', '', $cashFlow['loans_issued']), 2); ?></h4>
                                                                <small>Expenses + Loans Issued</small>
                                                                <div class="mt-2 small">
                                                                    <div>Expenses: ₹<?php echo $cashFlow['total_expenses']; ?></div>
                                                                    <div>Loans Issued: ₹<?php echo $cashFlow['loans_issued']; ?></div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <div class="border p-3 rounded">
                                                                <h6>Current Balance</h6>
                                                                <h4 class="text-info">₹<?php echo $cashFlow['current_balance']; ?></h4>
                                                                <small>Cash in hand now</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Regular EMI Breakdown -->
                                                    <div class="row mt-4">
                                                        <div class="col-12">
                                                            <div class="card bg-light">
                                                                <div class="card-header">
                                                                    <h6 class="card-title mb-0" style="font-weight: bold; font-size: 1rem; color: #333;">Regular EMI Breakdown</h6>
                                                                </div>
                                                                <div class="card-body">
                                                                    <div class="row text-center">
                                                                        <div class="col-md-3">
                                                                            <div class="border p-2 rounded bg-white">
                                                                                <h6>Principal</h6>
                                                                                <h5 class="text-primary">₹<?php echo $regularPrincipalCollected; ?></h5>
                                                                            </div>
                                                                        </div>
                                                                        <div class="col-md-3">
                                                                            <div class="border p-2 rounded bg-white">
                                                                                <h6>Interest</h6>
                                                                                <h5 class="text-success">₹<?php echo $regularInterestCollected; ?></h5>
                                                                            </div>
                                                                        </div>
                                                                        <div class="col-md-3">
                                                                            <div class="border p-2 rounded bg-white">
                                                                                <h6>Overdue</h6>
                                                                                <h5 class="text-warning">₹<?php echo $regularOverdueCollected; ?></h5>
                                                                            </div>
                                                                        </div>
                                                                        <div class="col-md-3">
                                                                            <div class="border p-2 rounded bg-white">
                                                                                <h6>Total</h6>
                                                                                <h5 class="text-dark">₹<?php echo $cashFlow['regular_emi_income']; ?></h5>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Foreclosure Breakdown -->
                                                    <?php if ((float)str_replace(',', '', $totalForeclosureCollected) > 0): ?>
                                                    <div class="row mt-4">
                                                        <div class="col-12">
                                                            <div class="card bg-light">
                                                                <div class="card-header">
                                                                    <h6 class="card-title mb-0" style="font-weight: bold; font-size: 1rem; color: #333;">Foreclosure Breakdown</h6>
                                                                </div>
                                                                <div class="card-body">
                                                                    <div class="row text-center">
                                                                        <div class="col-md-3">
                                                                            <div class="border p-2 rounded bg-white">
                                                                                <h6>Principal</h6>
                                                                                <h5 class="text-primary">₹<?php echo $foreclosurePrincipalCollected; ?></h5>
                                                                            </div>
                                                                        </div>
                                                                        <div class="col-md-3">
                                                                            <div class="border p-2 rounded bg-white">
                                                                                <h6>Interest</h6>
                                                                                <h5 class="text-success">₹<?php echo $foreclosureInterestCollected; ?></h5>
                                                                            </div>
                                                                        </div>
                                                                        <div class="col-md-3">
                                                                            <div class="border p-2 rounded bg-white">
                                                                                <h6>Overdue</h6>
                                                                                <h5 class="text-warning">₹<?php echo $foreclosureOverdueCollected; ?></h5>
                                                                            </div>
                                                                        </div>
                                                                        <div class="col-md-3">
                                                                            <div class="border p-2 rounded bg-white">
                                                                                <h6>Foreclosure Charge</h6>
                                                                                <h5 class="text-info">₹<?php echo $foreclosureChargesCollected; ?></h5>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div class="row mt-3">
                                                                        <div class="col-12 text-center">
                                                                            <h5 class="text-dark">Total Foreclosure Amount: ₹<?php echo $foreclosureBreakdown['total']; ?></h5>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-12">
                                                            <div class="alert alert-info mb-0">
                                                                <strong>Formula:</strong> Current Balance = Previous Balance + Total Income - Expenses - Loans Issued<br>
                                                                <strong>Total Income =</strong> Regular EMI (Principal+Interest+Overdue) + Foreclosure + Document Charges<br>
                                                                <strong>Important:</strong> Regular EMI and Foreclosure are kept separate to avoid double counting
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Collection Lists -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header d-flex align-items-center justify-content-between">
                                    <h6 class="card-title mb-0" style="font-weight: bold; font-size: 1.1rem; color: #333;">Collection List</h6>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Filter</button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#" onclick="filterCollectionList('all')">All</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="filterCollectionList('today')">Today</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="filterCollectionList('week')">This Week</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="filterCollectionList('month')">This Month</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="collectionTable">
                                            <thead>
                                                <tr>
                                                    <th>Customer Name</th>
                                                    <th>Agreement No</th>
                                                    <th>Amount</th>
                                                    <th>Date</th>
                                                    <th>Type</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php echo $collectionList; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header d-flex align-items-center justify-content-between">
                                    <h6 class="card-title mb-0" style="font-weight: bold; font-size: 1.1rem; color: #333;">Non-Collection List</h6>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Filter</button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#" onclick="filterNonCollectionList('all')">All</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="filterNonCollectionList('today')">Today</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="filterNonCollectionList('week')">This Week</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="filterNonCollectionList('month')">This Month</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="nonCollectionTable">
                                            <thead>
                                                <tr>
                                                    <th>Customer Name</th>
                                                    <th>Agreement No</th>
                                                    <th>Due Amount</th>
                                                    <th>Due Date</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php echo $nonCollectionList; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- New Customers -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header d-flex align-items-center justify-content-between">
                                    <h6 class="card-title mb-0" style="font-weight: bold; font-size: 1.1rem; color: #333;">New Customers</h6>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Filter</button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#" onclick="filterNewCustomers('all')">All</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="filterNewCustomers('today')">Today</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="filterNewCustomers('week')">This Week</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="filterNewCustomers('month')">This Month</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="newCustomersTable">
                                            <thead>
                                                <tr>
                                                    <th>Customer Name</th>
                                                    <th>Agreement No</th>
                                                    <th>Loan Amount</th>
                                                    <th>Loan Type</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php echo $newCustomers; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php include 'includes/rightbar.php'; ?>
                <?php include 'includes/footer.php'; ?>
            </div>
        </div>
    </div>
    <?php include 'includes/scripts.php'; ?>
   
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // PDF Export functionality
            document.getElementById('exportPdfBtn').addEventListener('click', function() {
                const exportBtn = this;
                exportBtn.disabled = true;
                exportBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...';
             
                // Get all current filter values
                const month = document.getElementById('month').value;
                const year = document.getElementById('year').value;
                const day = document.getElementById('day').value;
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
             
                // Build query string
                const params = new URLSearchParams({
                    month: month,
                    year: year,
                    day: day,
                    start_date: startDate,
                    end_date: endDate
                });
             
                // Redirect to PDF generation page
                window.location.href = 'generate-pdf-report.php?' + params.toString();
             
                // Re-enable button after a short delay
                setTimeout(() => {
                    exportBtn.disabled = false;
                    exportBtn.innerHTML = 'Export PDF';
                }, 3000);
            });
            function filterCollectionList(filter) { updateMainFilter(filter); }
            function filterNonCollectionList(filter) { updateMainFilter(filter); }
            function filterNewCustomers(filter) { updateMainFilter(filter); }
            function updateMainFilter(timeFilter) {
                const today = new Date();
                let startDate = '', endDate = '', month = 'all', year = today.getFullYear(), day = 0;
               
                switch(timeFilter) {
                    case 'today':
                        startDate = endDate = today.toISOString().split('T')[0];
                        break;
                    case 'week':
                        const startOfWeek = new Date(today);
                        startOfWeek.setDate(today.getDate() - today.getDay());
                        const endOfWeek = new Date(today);
                        endOfWeek.setDate(today.getDate() + (6 - today.getDay()));
                        startDate = startOfWeek.toISOString().split('T')[0];
                        endDate = endOfWeek.toISOString().split('T')[0];
                        break;
                    case 'month':
                        month = today.getMonth() + 1;
                        year = today.getFullYear();
                        break;
                }
               
                document.getElementById('start_date').value = startDate;
                document.getElementById('end_date').value = endDate;
                document.getElementById('month').value = month;
                document.getElementById('year').value = year;
                document.getElementById('day').value = day;
                document.querySelector('form').submit();
            }
            $('#month, #year').on('change', function() {
                const month = $('#month').val();
                const year = $('#year').val();
                const daySelect = $('#day');
                const currentValue = daySelect.val();
                const days = month === 'all' ? 31 : new Date(year, month, 0).getDate();
               
                daySelect.html('<option value="0">All Days</option>');
                for (let d = 1; d <= days; d++) {
                    const option = $('<option>').val(d).text(d);
                    if (d == currentValue) option.prop('selected', true);
                    daySelect.append(option);
                }
                if (currentValue > days) daySelect.val(0);
            });
        });
    </script>
</body>
</html>