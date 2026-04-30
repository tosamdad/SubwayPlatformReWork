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
    $pid = $_GET['pid'] ?? '';
    try {
        $where_p = $pid ? "platform_id = " . (int)$pid : "platform_id IS NULL AND admin_id = " . $pdo->quote($user_id);
        $stmt = $pdo->prepare("SELECT DISTINCT category_name FROM items WHERE role_type = ? AND $where_p ORDER BY category_name");
        $stmt->execute([$role_param]);
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($categories);
    } catch (Exception $e) {
        echo json_encode([]);
    }
} else if ($mode == 'get_items') {
    $role_param = $_GET['role'] ?? '';
    $category = $_GET['category'] ?? '';
    $pid = $_GET['pid'] ?? '';
    try {
        $where_p = $pid ? "platform_id = " . (int)$pid : "platform_id IS NULL AND admin_id = " . $pdo->quote($user_id);
        $stmt = $pdo->prepare("SELECT item_id, item_name, sort_order FROM items WHERE role_type = ? AND category_name = ? AND $where_p ORDER BY sort_order ASC");
        $stmt->execute([$role_param, $category]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($items);
    } catch (Exception $e) {
        echo json_encode([]);
    }
} else if ($mode == 'get_other_formats') {
    try {
        $stmt = $pdo->prepare("
            SELECT admin_id, 
                   SUM(CASE WHEN role_type = 'Safety' THEN 1 ELSE 0 END) as safety_count,
                   SUM(CASE WHEN role_type = 'Worker' THEN 1 ELSE 0 END) as worker_count
            FROM items 
            WHERE admin_id != ? AND platform_id IS NULL
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
        $stmt = $pdo->prepare("SELECT role_type, category_name, item_name, photo_count FROM items WHERE admin_id = ? AND platform_id IS NULL ORDER BY role_type, sort_order ASC");
        $stmt->execute([$target_admin_id]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($details);
    } catch (Exception $e) {
        echo json_encode([]);
    }
}
?>
