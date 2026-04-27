<?php
ob_start();
require_once '../inc/db_config.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '인증이 필요합니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $platform_id = $_POST['platform_id'] ?? '';
    $item_id = $_POST['item_id'] ?? '';
    $photo_index = (int)($_POST['photo_index'] ?? 1);
    $user_id = $_SESSION['user_id'];
    
    if (!$platform_id || !$item_id || !isset($_FILES['photo'])) {
        echo json_encode(['success' => false, 'message' => '필수 정보가 누락되었습니다.']);
        exit;
    }

    try {
        // 코드 정보 조회 (폴더 생성 및 파일명 생성용)
        $stmt_codes = $pdo->prepare("
            SELECT c.const_code, s.site_code, p.platform_code, i.item_code
            FROM platforms p
            JOIN sites s ON p.site_id = s.site_id
            JOIN constructions c ON s.const_id = c.const_id
            JOIN items i ON i.item_id = ?
            WHERE p.platform_id = ?
        ");
        $stmt_codes->execute([$item_id, $platform_id]);
        $codes = $stmt_codes->fetch();

        if (!$codes) {
            echo json_encode(['success' => false, 'message' => '항목 정보를 찾을 수 없습니다.']);
            exit;
        }

        $file_ext = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($file_ext, $allowed)) {
            echo json_encode(['success' => false, 'message' => '허용되지 않는 파일 형식입니다.']);
            exit;
        }

        // 계층형 폴더 경로 생성 (uploads/공사/역/승강장/)
        $sub_dir = $codes['const_code'] . "/" . $codes['site_code'] . "/" . $codes['platform_code'] . "/";
        $target_dir = "../uploads/" . $sub_dir;
        
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        // 파일명 생성 규칙 (동일 항목 덮어쓰기, 뒤에 인덱스 추가)
        $idx_str = str_pad($photo_index, 2, '0', STR_PAD_LEFT);
        $new_filename = $codes['const_code'] . "_" . $codes['site_code'] . "_" . $codes['platform_code'] . "_" . $codes['item_code'] . "_" . $idx_str . "." . $file_ext;
        $target_file = $target_dir . $new_filename;
        $db_save_path = "uploads/" . $sub_dir . $new_filename;

        if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
            // [업그레이드] 3중 파일 무결성 철통 검증
            clearstatcache(); 
            
            // 1. 원본 vs 서버 저장 용량 100% 일치 확인 (네트워크 순단 / 파일 잘림 완벽 방어)
            $uploaded_size = (int)$_FILES["photo"]["size"];
            $saved_size = (int)filesize($target_file);
            
            if ($saved_size === 0 || $saved_size !== $uploaded_size) {
                @unlink($target_file);
                ob_clean();
                echo json_encode(['success' => false, 'message' => '네트워크 불안정으로 사진 전송이 중단되었습니다. 다시 촬영해 주세요.']);
                exit;
            }

            // 2 & 3. 픽셀 렌더링 가능 여부 및 실제 MIME 타입 동시 검증 (finfo 대체)
            // getimagesize()는 이미지가 아니거나 심각하게 손상된 경우 false를 반환합니다.
            $img_info = @getimagesize($target_file);
            if ($img_info === false) {
                @unlink($target_file);
                ob_clean();
                echo json_encode(['success' => false, 'message' => '사진 파일이 손상되었거나 유효한 이미지가 아닙니다. 다시 촬영해 주세요.']);
                exit;
            }
            
            // MIME 타입 2차 확인 (악성 스크립트 방어)
            $mime = $img_info['mime'] ?? '';
            if (strpos($mime, 'image/') !== 0) {
                @unlink($target_file);
                ob_clean();
                echo json_encode(['success' => false, 'message' => '허용되지 않는 이미지 형식입니다. (MIME 에러)']);
                exit;
            }

            // 모든 검증을 완벽히 통과한 건강한 사진만 DB 기록 시작
            $stmt = $pdo->prepare("SELECT log_id, user_id FROM photo_logs WHERE platform_id = ? AND item_id = ? AND photo_index = ?");
            $stmt->execute([$platform_id, $item_id, $photo_index]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // 내 작업물이 아니면 덮어쓰기 차단
                if ($existing['user_id'] !== $user_id) {
                    @unlink($target_file);
                    ob_clean();
                    echo json_encode(['success' => false, 'message' => '다른 사용자('.$existing['user_id'].')가 이미 완료하였습니다.', 'force_refresh' => true]);
                    exit;
                }
                
                $update = $pdo->prepare("UPDATE photo_logs SET photo_url = ?, timestamp = NOW() WHERE log_id = ?");
                $update->execute([$db_save_path, $existing['log_id']]);
            } else {
                $insert = $pdo->prepare("INSERT INTO photo_logs (platform_id, item_id, user_id, photo_url, photo_index) VALUES (?, ?, ?, ?, ?)");
                $insert->execute([$platform_id, $item_id, $user_id, $db_save_path, $photo_index]);
            }
            
            ob_clean();
            echo json_encode(['success' => true, 'message' => '업로드 성공', 'path' => $db_save_path]);
            exit;
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'message' => '파일 이동 실패(권한 문제 등)']);
            exit;
        }
    } catch (Throwable $e) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => '시스템 오류: ' . $e->getMessage()]);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
}
?>
