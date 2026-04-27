<?php
require_once '../inc/db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
    header('Location: password_change.php?msg=error');
    exit;
}

if ($new_password !== $confirm_password) {
    header('Location: password_change.php?msg=error_match');
    exit;
}

try {
    // 1. 현재 비밀번호 확인
    $stmt = $pdo->prepare("SELECT password FROM members WHERE member_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user && password_verify($current_password, $user['password'])) {
        // 2. 새 비밀번호 해싱 및 업데이트
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE members SET password = ? WHERE member_id = ?");
        $update->execute([$hashed_password, $user_id]);
        
        header('Location: password_change.php?msg=success');
        exit;
    } else {
        header('Location: password_change.php?msg=error_curr');
        exit;
    }

} catch (Exception $e) {
    header('Location: password_change.php?msg=error');
    exit;
}
?>
