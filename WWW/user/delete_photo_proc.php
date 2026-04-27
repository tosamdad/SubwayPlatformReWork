<?php
ob_start();
require_once '../inc/db_config.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $platform_id = $_POST['platform_id'] ?? '';
    $item_id = $_POST['item_id'] ?? '';
    $photo_index = (int)($_POST['photo_index'] ?? 1);

    if (empty($platform_id) || empty($item_id)) {
        echo json_encode(['success' => false, 'message' => '필수 정보가 누락되었습니다.']);
        exit;
    }

    try {
        // 1. 기존 파일 경로 및 소유자 조회 (인덱스별)
        $stmt = $pdo->prepare("SELECT log_id, photo_url, user_id FROM photo_logs WHERE platform_id = ? AND item_id = ? AND photo_index = ?");
        $stmt->execute([$platform_id, $item_id, $photo_index]);
        $existing = $stmt->fetch();

        if ($existing) {
            // 본인 확인 (본인이 올린 사진이 아니면 삭제 거부) - 관리자/슈퍼관리자는 예외 허용
            $role_type = $_SESSION['role_type'] ?? '';
            if ($existing['user_id'] !== $_SESSION['user_id'] && $role_type !== 'Admin' && $role_type !== 'SuperAdmin') {
                ob_clean();
                echo json_encode(['success' => false, 'message' => '본인이 업로드한 사진만 삭제할 수 있습니다.']);
                exit;
            }

            $photo_url = $existing['photo_url'];
            $log_id = $existing['log_id'];

            // 2. 파일 시스템에서 삭제
            if (!empty($photo_url)) {
                $file_path = '../' . $photo_url;
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }

            // 3. DB 기록 완전 삭제
            $delete = $pdo->prepare("DELETE FROM photo_logs WHERE log_id = ?");
            $delete->execute([$log_id]);

            ob_clean();
            echo json_encode(['success' => true, 'message' => '사진이 성공적으로 삭제되었습니다.']);
            exit;
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'message' => '삭제할 사진을 찾을 수 없습니다.']);
            exit;
        }
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => '시스템 오류: ' . $e->getMessage()]);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
}
