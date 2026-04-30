<?php
require_once '../inc/db_config.php';
session_start();

// 관리자 권한 체크
$role = $_SESSION['role_type'] ?? '';
if ($role !== 'Admin' && $role !== 'SuperAdmin') {
    die('권한이 없습니다.');
}

try {
    // 1. platform_id 컬럼 추가 (이미 있으면 무시됨)
    $stmt = $pdo->query("SHOW COLUMNS FROM items LIKE 'platform_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE items ADD COLUMN platform_id INT NULL AFTER admin_id");
        echo "✅ items 테이블에 platform_id 컬럼이 추가되었습니다.<br>";
    } else {
        echo "ℹ️ platform_id 컬럼이 이미 존재합니다.<br>";
    }

    echo "<br><a href='projects_platform_items.php'>관리 페이지로 돌아가기</a>";

} catch (Exception $e) {
    echo "❌ 오류 발생: " . $e->getMessage();
}
?>
