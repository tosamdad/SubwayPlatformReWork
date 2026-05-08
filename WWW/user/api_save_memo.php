<?php
require_once '../inc/db_config.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$const_id = $_POST['const_id'] ?? '';
$site_id = $_POST['site_id'] ?? '';
$platform_id = $_POST['platform_id'] ?? '';
$item_id = $_POST['item_id'] ?? '';
$memo_text = $_POST['memo_text'] ?? '';
$memo_date = $_POST['memo_date'] ?? null; // 날짜 추가

if (!$platform_id || !$item_id) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

// 날짜가 빈 문자열이면 NULL로 처리 (Worker 항목 등 누적용)
if (empty($memo_date)) $memo_date = null;

try {
    $memo_text = trim($memo_text);

    if ($memo_text === '') {
        // 내용이 비어있으면 데이터 삭제
        $sql = "DELETE FROM item_memos WHERE platform_id = ? AND item_id = ?";
        $params = [$platform_id, $item_id];
        
        if ($memo_date) {
            $sql .= " AND memo_date = ?";
            $params[] = $memo_date;
        } else {
            $sql .= " AND memo_date IS NULL";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        // 내용이 있으면 UPSERT (ON DUPLICATE KEY UPDATE)
        $stmt = $pdo->prepare("
            INSERT INTO item_memos (const_id, site_id, platform_id, item_id, memo_text, user_id, memo_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE memo_text = VALUES(memo_text), user_id = VALUES(user_id)
        ");
        $stmt->execute([$const_id, $site_id, $platform_id, $item_id, $memo_text, $user_id, $memo_date]);
    }
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
