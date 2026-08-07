<?php
// backend/withdraw.php
$DEBUG = true; 

if ($DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

include_once(__DIR__ . '/../_intro.php'); 
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

// Helper for JSON responses
function jsonRes($status, $msg, $debug = null) {
    echo json_encode(['status' => $status, 'msg' => $msg, 'debug' => $debug]);
    exit();
}

/**
 * Same BTC/USD price lookup as get_balance.php - shares its cache file so
 * this doesn't hit the price API a second time. Used server-side only, to
 * validate the minimum and to record a BTC reference on the request.
 */
function getBtcUsdPrice() {
    $cacheDir = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/btc_price.json';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 180)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if ($decoded !== null) return $decoded;
        }
    }

    $opts = ["http" => ["method" => "GET", "header" => "User-Agent: PHP-Balance-Script/1.0\r\n", "timeout" => 5]];
    $context = stream_context_create($opts);
    $json = @file_get_contents('https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd', false, $context);
    if (!$json) return null;

    $data = json_decode($json, true);
    $price = isset($data['bitcoin']['usd']) ? floatval($data['bitcoin']['usd']) : null;
    if ($price !== null) @file_put_contents($cacheFile, json_encode($price));
    return $price;
}

const MIN_BTC_DUST = 0.0001;

// 1. Get POST Data (JSON)
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = [];

// 2. CSRF check
$post_csrf = $input['CSRF_TOKKEN'] ?? '';
$session_csrf = $_SESSION['csrftokken'] ?? '';

if (empty($session_csrf) || empty($post_csrf) || !is_string($post_csrf) || !hash_equals($session_csrf, $post_csrf)) {
    jsonRes('error', 'Invalid or missing CSRF token.');
}

// 3. Check Login
if (!isset($_SESSION['user_id'])) {
    jsonRes('error', 'Please login to continue.');
}
$user_id = (int)$_SESSION['user_id'];

// 'amount' is USD.
$amount    = filter_var($input['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
$amountBtc = filter_var($input['amount_btc'] ?? null, FILTER_VALIDATE_FLOAT);
$address   = trim($input['address'] ?? '');
$currency  = strtoupper($input['currency'] ?? 'BTC');

// 4. Basic Validation
$price = getBtcUsdPrice();
$minWdUsd = ($price !== null && $price > 0) ? round(MIN_BTC_DUST * $price, 2) : 1.00; // fallback floor if price lookup fails

if (!$amount || $amount < $minWdUsd) {
    jsonRes('error', 'Invalid amount. Minimum withdrawal is $' . number_format($minWdUsd, 2) . '.');
}

if (strlen($address) < 26) { // Basic BTC address length check
    jsonRes('error', 'Invalid wallet address format.');
}

// 5. Start DB Transaction and Acquire Lock
$mysqli->begin_transaction();

// Define a unique lock name for this user
$lockName = "withdraw_lock_user_" . $user_id;

try {
    // Acquire a MySQL advisory lock for this specific user.
    // This serializes concurrent requests, preventing the race condition.
    // We wait up to 5 seconds to acquire the lock.
    $stmtLock = $mysqli->prepare("SELECT GET_LOCK(?, 5)");
    $stmtLock->bind_param("s", $lockName);
    $stmtLock->execute();
    $lockAcquired = $stmtLock->get_result()->fetch_row()[0];
    $stmtLock->close();

    if ($lockAcquired !== 1) {
        throw new Exception("Another transaction is currently processing. Please try again in a moment.");
    }

    // A. Check Current Balance (USD)
    $stmt = $mysqli->prepare("SELECT SUM(amount) as total FROM wallet_transactions WHERE member_id = ? AND currency = ? AND status = 'completed'");
    $stmt->bind_param("is", $user_id, $currency);
    $stmt->execute();
    $balance = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    if ($amount > $balance) {
        throw new Exception("Insufficient balance. You have $" . number_format($balance, 2) . ".");
    }

    // B. Create the Withdrawal Request (amount is USD)
    $sqlReq = "INSERT INTO withdrawal_requests (member_id, amount, currency, payment_method, payment_details, status) 
               VALUES (?, ?, ?, 'Crypto', ?, 'pending')";
    $stmtReq = $mysqli->prepare($sqlReq);
    $stmtReq->bind_param("idss", $user_id, $amount, $currency, $address);
    
    if (!$stmtReq->execute()) {
        throw new Exception("Failed to create request: " . $mysqli->error);
    }
    $withdrawal_id = $mysqli->insert_id;
    $stmtReq->close();

    // C. Deduct Balance Immediately (USD).
    $negativeAmount = -$amount;
    $btcNote = $amountBtc ? (' (~' . number_format($amountBtc, 8) . ' BTC)') : '';
    $desc = "Withdrawal Request #$withdrawal_id" . $btcNote;
    
    $sqlDebit = "INSERT INTO wallet_transactions (member_id, transaction_type, amount, currency, status, description) 
                 VALUES (?, 'withdrawal', ?, ?, 'completed', ?)";
    $stmtDebit = $mysqli->prepare($sqlDebit);
    $stmtDebit->bind_param("idss", $user_id, $negativeAmount, $currency, $desc);
    
    if (!$stmtDebit->execute()) {
        throw new Exception("Failed to update wallet: " . $mysqli->error);
    }
    $stmtDebit->close();

    // If everything is perfect, save to DB
    $mysqli->commit();
    jsonRes('success', 'Withdrawal requested successfully!');

} catch (Exception $e) {
    // If anything fails, undo everything (rollback)
    $mysqli->rollback();
    jsonRes('error', $e->getMessage());
} finally {
    // release the lock so the user isn't stuck if a fatal error occurs
    $stmtUnlock = $mysqli->prepare("SELECT RELEASE_LOCK(?)");
    $stmtUnlock->bind_param("s", $lockName);
    $stmtUnlock->execute();
    $stmtUnlock->close();
}
