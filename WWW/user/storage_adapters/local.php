<?php
// 로컬 스토리지 어댑터 (기존 upload_proc.php 핵심 로직)
// 이 파일은 api_upload_router.php 에 의해 include 되어 실행됩니다.

// $platform_id, $item_id 등은 $_POST에서 가져옴
$item_id = $_POST['item_id'] ?? '';
$platform_id = $_POST['platform_id'] ?? '';
$photo_index = (int)($_POST['photo_index'] ?? 1);

if (empty($item_id) || empty($platform_id)) {
    echo json_encode(['success' => false, 'message' => '필수 정보가 누락되었습니다.']);
    exit;
}

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] != UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => '파일 업로드 중 오류가 발생했습니다.']);
    exit;
}

$tmp_file = $_FILES['photo']['tmp_name'];

// 1단계: 빈 파일 또는 크기가 0인 경우 차단
if (filesize($tmp_file) === 0) {
    @unlink($tmp_file);
    echo json_encode(['success' => false, 'message' => '빈 파일은 업로드할 수 없습니다. 다시 촬영해 주세요.']);
    exit;
}

// 2단계: 이미지 구조 및 MIME 타입 검증
$image_info = @getimagesize($tmp_file);
if ($image_info === false) {
    @unlink($tmp_file);
    echo json_encode(['success' => false, 'message' => '손상된 이미지 파일입니다. (네트워크 불안정) 다시 촬영해 주세요.']);
    exit;
}

$mime_type = $image_info['mime'];
$allowed_mime_types = ['image/jpeg', 'image/png', 'image/webp'];

if (!in_array($mime_type, $allowed_mime_types)) {
    @unlink($tmp_file);
    echo json_encode(['success' => false, 'message' => '허용되지 않는 이미지 형식입니다. (JPEG, PNG, WebP 가능)']);
    exit;
}

// 3단계: 코드 정보 조회 (폴더 생성 및 파일명 생성용)
try {
    $stmt_codes = $pdo->prepare("
        SELECT c.const_code, s.site_code, p.platform_code, i.item_code, i.role_type
        FROM platforms p
        JOIN sites s ON p.site_id = s.site_id
        JOIN constructions c ON s.const_id = c.const_id
        JOIN items i ON i.item_id = ?
        WHERE p.platform_id = ?
    ");
    $stmt_codes->execute([$item_id, $platform_id]);
    $codes = $stmt_codes->fetch();

    if (!$codes) {
        @unlink($tmp_file);
        echo json_encode(['success' => false, 'message' => '항목 정보를 찾을 수 없습니다.']);
        exit;
    }

    $is_safety = ($codes['role_type'] === 'Safety');
    $selected_date = $_POST['date'] ?? date('Y-m-d');
    $date_suffix = $is_safety ? "_" . str_replace('-', '', $selected_date) : "";

    // 계층형 폴더 경로 생성 (uploads/공사/역/승강장/)
    $sub_dir = $codes['const_code'] . "/" . $codes['site_code'] . "/" . $codes['platform_code'] . "/";
    $target_dir = $base_url . $sub_dir;
    
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    // 파일명 생성 규칙 (뒤에 인덱스 추가, 예: _01, _02)
    $idx_str = str_pad($photo_index, 2, '0', STR_PAD_LEFT);
    $filename = $codes['const_code'] . "_" . $codes['site_code'] . "_" . $codes['platform_code'] . "_" . $codes['item_code'] . $date_suffix . "_" . $idx_str . ".jpg";
    $target_file = $target_dir . $filename;
    $db_save_path = "uploads/" . $sub_dir . $filename;

} catch (Exception $e) {
    @unlink($tmp_file);
    echo json_encode(['success' => false, 'message' => '코드 정보 조회 실패: ' . $e->getMessage()]);
    exit;
}

if (move_uploaded_file($tmp_file, $target_file)) {
    try {
        // 동시성 검증 (인덱스별로 체크, Safety는 일자별로 체크)
        $sql_check = "SELECT log_id, user_id FROM photo_logs WHERE platform_id = ? AND item_id = ? AND photo_index = ?";
        if ($is_safety) {
            $sql_check .= " AND DATE(timestamp) = " . $pdo->quote($selected_date);
        }
        
        $stmt = $pdo->prepare($sql_check);
        $stmt->execute([$platform_id, $item_id, $photo_index]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            if ($existing['user_id'] !== $user_id) {
                @unlink($target_file);
                echo json_encode(['success' => false, 'message' => '다른 사용자('.$existing['user_id'].')가 이미 완료하였습니다.', 'force_refresh' => true]);
                exit;
            }
            
            $update = $pdo->prepare("UPDATE photo_logs SET photo_url = ?, timestamp = ? WHERE log_id = ?");
            // Safety인 경우 선택된 날짜와 현재 시각을 조합하여 저장하거나 그냥 NOW()를 씀.
            // 여기서는 실제 작업 시각을 위해 NOW()를 쓰되, 점검일 기준 조회는 timestamp의 DATE 파트로 함.
            $update->execute([$db_save_path, date('Y-m-d H:i:s'), $existing['log_id']]);
        } else {
            // Safety 항목이고 오늘이 아닌 과거 날짜를 선택했다면 timestamp를 해당 날짜로 맞춰줌 (기록용)
            $target_timestamp = ($is_safety) ? $selected_date . " " . date('H:i:s') : date('Y-m-d H:i:s');
            $insert = $pdo->prepare("INSERT INTO photo_logs (platform_id, item_id, user_id, photo_url, photo_index, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
            $insert->execute([$platform_id, $item_id, $user_id, $db_save_path, $photo_index, $target_timestamp]);
        }

        echo json_encode(['success' => true, 'photo_url' => $db_save_path]);
    } catch (Exception $e) {
        @unlink($target_file);
        echo json_encode(['success' => false, 'message' => 'DB 기록 실패: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => '파일 저장에 실패했습니다.']);
}
