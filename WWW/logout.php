<?php
session_start();

// 로그아웃 전 권한 확인 (리다이렉트 위치 결정)
$redirect = 'index.php';
if (isset($_SESSION['role_type']) && $_SESSION['role_type'] === 'Admin') {
    $redirect = 'adminpage/index.php';
}

session_unset();
session_destroy();

header('Location: ' . $redirect);
exit;
?>
