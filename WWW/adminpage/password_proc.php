<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

if (!$role || !$user_id) {
    die("Unauthorized access.");
}

$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
    header('Location: password_change.php?msg=' . urlencode('모든 필드를 입력해 주세요.'));
    exit;
}

if ($new_password !== $confirm_password) {
    header('Location: password_change.php?msg=' . urlencode('새 비밀번호가 일치하지 않습니다.'));
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
        
        header('Location: password_change.php?msg=' . urlencode('비밀번호가 성공적으로 변경되었습니다.'));
        exit;
    } else {
        header('Location: password_change.php?msg=' . urlencode('현재 비밀번호가 올바르지 않습니다.'));
        exit;
    }

} catch (Exception $e) {
    header('Location: password_change.php?msg=' . urlencode('오류가 발생했습니다. 다시 시도해 주세요.'));
    exit;
}
?>
