<?php
// API Upload Router
require_once '../inc/db_config.php';
session_start();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? '';
$role_type = $_SESSION['role_type'] ?? '';

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

// 1. 관리자(Admin)의 Storage Config 찾기
$platform_id = $_POST['platform_id'] ?? '';
$admin_id = '';

if ($platform_id) {
    try {
        $stmt_owner = $pdo->prepare("
            SELECT c.admin_id 
            FROM platforms p 
            JOIN sites s ON p.site_id = s.site_id 
            JOIN constructions c ON s.const_id = c.const_id 
            WHERE p.platform_id = ?
        ");
        $stmt_owner->execute([$platform_id]);
        $admin_id = $stmt_owner->fetchColumn();
    } catch (Exception $e) {}
}

if (!$admin_id) {
    $admin_id = $user_id; // 기본값
    if ($role_type === 'Worker' || $role_type === 'Safety') {
        $admin_id = $_SESSION['parent_admin_id'] ?? '';
    }
}

$storage_type = 'local'; // default
$credentials = [];
$base_url = '../uploads/';

if ($admin_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT sc.type, sc.credentials, sc.base_url 
            FROM members m 
            JOIN storage_configs sc ON m.storage_config_id = sc.config_id 
            WHERE m.member_id = ?
        ");
        $stmt->execute([$admin_id]);
        $config = $stmt->fetch();
        if ($config) {
            $storage_type = $config['type'];
            $credentials = json_decode($config['credentials'], true) ?? [];
            $base_url = $config['base_url'];
        }
    } catch (Exception $e) {}
}

// 2. Storage Type에 따라 적절한 어댑터로 라우팅
switch ($storage_type) {
    case 'ftp':
        require_once 'storage_adapters/ftp.php';
        break;
    case 'r2':
        require_once 'storage_adapters/r2.php';
        break;
    case 'local':
    default:
        require_once 'storage_adapters/local.php';
        break;
}
