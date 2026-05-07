<?php
// Cloudflare R2 스토리지 어댑터
// api_upload_router.php 에 의해 include 되어 실행됩니다.

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

if (filesize($tmp_file) === 0) {
    @unlink($tmp_file);
    echo json_encode(['success' => false, 'message' => '빈 파일은 업로드할 수 없습니다.']);
    exit;
}

$image_info = @getimagesize($tmp_file);
if ($image_info === false) {
    @unlink($tmp_file);
    echo json_encode(['success' => false, 'message' => '손상된 이미지 파일입니다.']);
    exit;
}

$mime_type = $image_info['mime'];

// 코드 정보 조회
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

    $idx_str = str_pad($photo_index, 2, '0', STR_PAD_LEFT);
    $filename = $codes['const_code'] . "_" . $codes['site_code'] . "_" . $codes['platform_code'] . "_" . $codes['item_code'] . $date_suffix . "_" . $idx_str . ".jpg";
    
    // R2 업로드 경로
    $r2_key = "uploads/" . $codes['const_code'] . "/" . $codes['site_code'] . "/" . $codes['platform_code'] . "/" . $filename;

} catch (Exception $e) {
    @unlink($tmp_file);
    echo json_encode(['success' => false, 'message' => '코드 정보 조회 실패: ' . $e->getMessage()]);
    exit;
}

// R2 설정
$access_key = $credentials['access_key'] ?? '';
$secret_key = $credentials['secret_key'] ?? '';
$endpoint = $credentials['endpoint'] ?? '';
$bucket = $credentials['bucket'] ?? '';

if (!$access_key || !$secret_key || !$endpoint || !$bucket) {
    @unlink($tmp_file);
    echo json_encode(['success' => false, 'message' => 'R2 스토리지 설정이 올바르지 않습니다.']);
    exit;
}

// Endpoint URL 파싱
$parsed_url = parse_url($endpoint);
$host = $parsed_url['host'] ?? '';

// AWS Signature V4 생성 함수
function getSignatureV4($method, $url, $access_key, $secret_key, $payload, $headers = []) {
    $parsed_url = parse_url($url);
    $host = $parsed_url['host'];
    $path = $parsed_url['path'];
    
    $service = 's3';
    $region = 'auto';
    
    $amz_date = gmdate('Ymd\THis\Z');
    $date_stamp = gmdate('Ymd');
    
    $headers['host'] = $host;
    $headers['x-amz-date'] = $amz_date;
    $headers['x-amz-content-sha256'] = hash('sha256', $payload);
    
    ksort($headers);
    
    $canonical_headers = '';
    $signed_headers = '';
    foreach ($headers as $k => $v) {
        $canonical_headers .= strtolower($k) . ':' . trim($v) . "\n";
        $signed_headers .= strtolower($k) . ';';
    }
    $signed_headers = rtrim($signed_headers, ';');
    
    $canonical_request = $method . "\n" . $path . "\n\n" . $canonical_headers . "\n" . $signed_headers . "\n" . $headers['x-amz-content-sha256'];
    
    $algorithm = 'AWS4-HMAC-SHA256';
    $credential_scope = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
    
    $string_to_sign = $algorithm . "\n" . $amz_date . "\n" . $credential_scope . "\n" . hash('sha256', $canonical_request);
    
    $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . $secret_key, true);
    $k_region = hash_hmac('sha256', $region, $k_date, true);
    $k_service = hash_hmac('sha256', $service, $k_region, true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
    
    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
    
    $authorization = $algorithm . ' Credential=' . $access_key . '/' . $credential_scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
    
    return [
        'Authorization: ' . $authorization,
        'x-amz-date: ' . $amz_date,
        'x-amz-content-sha256: ' . $headers['x-amz-content-sha256']
    ];
}

$file_content = file_get_contents($tmp_file);
$upload_url = $endpoint . '/' . $bucket . '/' . $r2_key;

$auth_headers = getSignatureV4('PUT', $upload_url, $access_key, $secret_key, $file_content, [
    'content-type' => $mime_type
]);

$ch = curl_init($upload_url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, $file_content);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($auth_headers, [
    'Content-Type: ' . $mime_type,
    'Content-Length: ' . strlen($file_content)
]));

curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

@unlink($tmp_file);

if ($http_code == 200 || $http_code == 201) {
    $db_save_path = $r2_key;

    try {
        $sql_check = "SELECT log_id, user_id FROM photo_logs WHERE platform_id = ? AND item_id = ? AND photo_index = ?";
        if ($is_safety) {
            $sql_check .= " AND DATE(timestamp) = " . $pdo->quote($selected_date);
        }
        
        $stmt = $pdo->prepare($sql_check);
        $stmt->execute([$platform_id, $item_id, $photo_index]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            if ($existing['user_id'] !== $user_id) {
                echo json_encode(['success' => false, 'message' => '다른 사용자('.$existing['user_id'].')가 이미 완료하였습니다.', 'force_refresh' => true]);
                exit;
            }
            $update = $pdo->prepare("UPDATE photo_logs SET photo_url = ?, timestamp = ? WHERE log_id = ?");
            $update->execute([$db_save_path, date('Y-m-d H:i:s'), $existing['log_id']]);
        } else {
            $target_timestamp = ($is_safety) ? $selected_date . " " . date('H:i:s') : date('Y-m-d H:i:s');
            $insert = $pdo->prepare("INSERT INTO photo_logs (platform_id, item_id, user_id, photo_url, photo_index, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
            $insert->execute([$platform_id, $item_id, $user_id, $db_save_path, $photo_index, $target_timestamp]);
        }

        echo json_encode(['success' => true, 'photo_url' => $db_save_path]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'DB 기록 실패: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'R2 업로드 실패 (HTTP Code: ' . $http_code . ')', 'response' => $response]);
}
