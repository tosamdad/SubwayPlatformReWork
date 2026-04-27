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
$admin_id = $user_id; // 본인이 Admin이면 본인
if ($role_type === 'Worker') {
    $admin_id = $_SESSION['parent_admin_id'] ?? '';
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
            WHERE m.user_id = ?
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
        // R2는 Presigned URL로 직접 업로드하므로 이 라우터로 바이너리가 오면 안됨
        echo json_encode(['success' => false, 'message' => 'R2 스토리지는 다이렉트 업로드만 지원합니다.']);
        break;
    case 'local':
    default:
        require_once 'storage_adapters/local.php';
        break;
}
