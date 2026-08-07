<?php
// backend/get_balance.php
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

// --- Helper Functions ---

function jsonResponse($status, $data) {
    echo json_encode(array_merge(['status' => $status], $data));
    exit();
}

/**
 * Fetch BTC Price with a User-Agent to avoid 403 Forbidden errors
 * Returns numeric price (float) or null on failure
 */
function getBtcUsdPrice() {
    $cacheDir = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/btc_price.json';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);

    // cache for 3 minutes
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 180)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if ($decoded !== null) return $decoded;
        }
    }

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: PHP-Balance-Script/1.0\r\n",
            "timeout" => 5
        ]
    ];
    $context = stream_context_create($opts);
    $json = @file_get_contents('https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd', false, $context);

    if (!$json) return null;

    $data = json_decode($json, true);
    $price = isset($data['bitcoin']['usd']) ? floatval($data['bitcoin']['usd']) : null;

    if ($price !== null) {
        // store numeric value
        @file_put_contents($cacheFile, json_encode($price));
    }

    return $price;
}

/**
 * Fetch Network Fee from Mempool.space
 * Returns BTC fee (float) or null
 */
function getBtcNetworkFee() {
    $cacheDir = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/btc_fee.json';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);

    // cache for 60s
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 60)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if ($decoded !== null) return $decoded;
        }
    }

    $opts = ["http" => ["header" => "User-Agent: PHP-Balance-Script/1.0\r\n", "timeout" => 5]];
    $context = stream_context_create($opts);
    $json = @file_get_contents('https://mempool.space/api/v1/fees/recommended', false, $context);

    if (!$json) return null;

    $data = json_decode($json, true);
    $feeSat = isset($data['fastestFee']) ? intval($data['fastestFee']) : null;
    if ($feeSat === null) return null;

    // Estimate: 250 bytes average tx size
    $feeBtc = ($feeSat * 250) / 1e8;
    @file_put_contents($cacheFile, json_encode($feeBtc));
    return $feeBtc;
}

// Minimum viable withdrawal, expressed as a BTC "dust" floor. We convert it
// to USD below using the live price, so the displayed minimum always covers
// a sendable BTC amount even as the price moves. Tune this if 0.0001 BTC
// isn't the right floor for your payout rail.
const MIN_BTC_DUST = 0.0001;

// --- Main Logic ---
try {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse('error', ['msg' => 'Session expired']);
    }
    $user_id = (int)$_SESSION['user_id'];
    // this is ALWAYS filtered on currency = 'USD', regardless of withdrawal method.
    $method = $_GET['coin'] ?? 'BTC';

    // 1. Get Balance (completed transactions). wallet_transactions only ever
    // holds USD rows (tournament prizes), so this must be filtered on 'USD' -
    // never on the withdrawal method - or the sum comes back empty/wrong.
    $stmt = $mysqli->prepare("SELECT SUM(amount) as total FROM wallet_transactions WHERE member_id = ? AND currency = 'USD' AND status = 'completed'");
    if (!$stmt) {
        jsonResponse('error', ['msg' => 'DB prepare failed']);
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $balanceUsd = round((float)($res['total'] ?? 0), 2);

    // 2. Get BTC/USD price, and derive the BTC-equivalent of the USD balance
    // (shown in the "Withdraw Method" box on the frontend).
    $btcPrice = getBtcUsdPrice(); // float or null
    $price_per_unit = ($btcPrice !== null) ? (float)$btcPrice : null;
    $balanceBtc = ($price_per_unit !== null && $price_per_unit > 0)
        ? round($balanceUsd / $price_per_unit, 8)
        : null;

    // 3. Network Fee
    $networkFee = ($method === 'BTC') ? getBtcNetworkFee() : 0;
    $networkFeeDisplay = ($networkFee !== null) ? number_format($networkFee, 8, '.', '') : "Pending";

    // 4. Minimum withdrawal, expressed in USD
    $minWdUsd = ($price_per_unit !== null)
        ? round(MIN_BTC_DUST * $price_per_unit, 2)
        : null;

    // 5. Final output
    jsonResponse('success', [
        'currency'    => $method,
        'balance_usd' => number_format($balanceUsd, 2, '.', ''),
        'balance_btc' => $balanceBtc !== null ? number_format($balanceBtc, 8, '.', '') : null,
        'price'       => $price_per_unit !== null ? number_format($price_per_unit, 2, '.', '') : null,
        'network_fee' => $networkFeeDisplay,
        'min_wd_usd'  => $minWdUsd !== null ? number_format($minWdUsd, 2, '.', '') : null,
        'debug'       => $DEBUG ? "Price: " . ($price_per_unit ?? 'null') . " | User: $user_id" : null
    ]);

} catch (Exception $e) {
    jsonResponse('error', ['msg' => 'Server Error', 'debug' => $e->getMessage()]);
}