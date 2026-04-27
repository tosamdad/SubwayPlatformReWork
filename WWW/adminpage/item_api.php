<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$mode = $_GET['mode'] ?? '';

if ($mode == 'get_categories') {
    $role_param = $_GET['role'] ?? '';
    try {
        // 모든 관리자는 본인 키값으로만 카테고리 조회
        $stmt = $pdo->prepare("SELECT DISTINCT category_name FROM items WHERE role_type = ? AND admin_id = ? ORDER BY category_name");
        $stmt->execute([$role_param, $user_id]);
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($categories);
    } catch (Exception $e) {
        echo json_encode([]);
    }
} else if ($mode == 'get_items') {
    $role_param = $_GET['role'] ?? '';
    $category = $_GET['category'] ?? '';
    try {
        // 모든 관리자는 본인 키값으로만 항목 조회
        $stmt = $pdo->prepare("SELECT item_id, item_name, sort_order FROM items WHERE role_type = ? AND category_name = ? AND admin_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$role_param, $category, $user_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($items);
    } catch (Exception $e) {
        echo json_encode([]);
    }
} else if ($mode == 'get_other_formats') {
    try {
        // 본인을 제외하고 항목을 보유한 다른 관리자 목록과 항목 개수 요약
        $stmt = $pdo->prepare("
            SELECT admin_id, 
                   SUM(CASE WHEN role_type = 'Safety' THEN 1 ELSE 0 END) as safety_count,
                   SUM(CASE WHEN role_type = 'Worker' THEN 1 ELSE 0 END) as worker_count
            FROM items 
            WHERE admin_id != ? 
            GROUP BY admin_id 
            HAVING safety_count > 0 OR worker_count > 0
        ");
        $stmt->execute([$user_id]);
        $formats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($formats);
    } catch (Exception $e) {
        echo json_encode([]);
    }
} else if ($mode == 'get_format_details') {
    $target_admin_id = $_GET['admin_id'] ?? '';
    try {
        // 특정 관리자의 전체 항목 상세 조회
        $stmt = $pdo->prepare("SELECT role_type, category_name, item_name, photo_count FROM items WHERE admin_id = ? ORDER BY role_type, sort_order ASC");
        $stmt->execute([$target_admin_id]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($details);
    } catch (Exception $e) {
        echo json_encode([]);
    }
}
?>
