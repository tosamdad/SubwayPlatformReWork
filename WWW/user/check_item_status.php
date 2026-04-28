<?php
require_once '../inc/db_config.php';
session_start();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? '';
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

$item_id = $_POST['item_id'] ?? '';
$platform_id = $_POST['platform_id'] ?? '';
$photo_index = (int)($_POST['photo_index'] ?? 1);

if (!$item_id || !$platform_id) {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$role_type = $_SESSION['role_type'] ?? '';
$admin_id = $user_id; 
if ($role_type === 'Worker' || $role_type === 'Safety') {
    $admin_id = $_SESSION['parent_admin_id'] ?? '';
}

$storage_type = 'local';
if ($admin_id) {
    try {
        $stmt_config = $pdo->prepare("SELECT sc.type FROM members m JOIN storage_configs sc ON m.storage_config_id = sc.config_id WHERE m.member_id = ?");
        $stmt_config->execute([$admin_id]);
        $config_type = $stmt_config->fetchColumn();
        if ($config_type) $storage_type = $config_type;
    } catch (Exception $e) {}
}

try {
    $stmt = $pdo->prepare("SELECT user_id FROM photo_logs WHERE item_id = ? AND platform_id = ? AND photo_index = ?");
    $stmt->execute([$item_id, $platform_id, $photo_index]);
    $log = $stmt->fetch();

    if ($log) {
        $owner_id = $log['user_id'];
        $is_owner = ($owner_id === $user_id);
        
        echo json_encode([
            'success' => true,
            'is_filled' => true,
            'is_owner' => $is_owner,
            'owner_id' => $owner_id,
            'storage_type' => $storage_type,
            'message' => $is_owner ? '본인의 작업물입니다.' : '다른 사용자('.$owner_id.')가 이미 완료하였습니다.'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'is_filled' => false,
            'is_owner' => false,
            'owner_id' => null,
            'storage_type' => $storage_type,
            'message' => '업로드 가능'
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '시스템 오류: ' . $e->getMessage()]);
}
