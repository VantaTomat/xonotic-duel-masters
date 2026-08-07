<?php
include_once("../_intro.php");

// 1. Secure CSRF check using strict type guard and hash_equals
$post_csrf = $_POST['CSRF_TOKKEN'] ?? '';
$session_csrf = $_SESSION['csrftokken'] ?? '';

if (empty($session_csrf) || empty($post_csrf) || !is_string($post_csrf) || !hash_equals($session_csrf, $post_csrf)) {
    exit();
}

$LOGIN_FAIL = true;

if (!isset($_SESSION['user_id'])) {
    exit();
}

$data = [
    'status' => 'error',
    'msg' => ''
];

// 2. Prepared statement instead of interpolated SQL
$stmt = $mysqli->prepare("DELETE FROM {$dbprefix}Notifications WHERE target_user_id = ?");

if ($stmt) {
    // Bind the user_id as an integer ('i')
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();

    $data['status'] = 'success';
    $data['msg'] = 'Notifications have been deleted.';
} else {
    $data['msg'] = 'Database error occurred while clearing notifications.';
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($data);
?>
