<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    die("Unauthorized access.");
}

$platform_id = $_GET['platform_id'] ?? '';
if (!$platform_id) {
    die("Platform ID is required.");
}

// 1. 승강장 정보 및 공사/역사 정보 조회
$stmt_p = $pdo->prepare("
    SELECT p.platform_name, s.site_name, c.const_name, c.admin_id 
    FROM platforms p 
    JOIN sites s ON p.site_id = s.site_id 
    JOIN constructions c ON s.const_id = c.const_id 
    WHERE p.platform_id = ?
");
$stmt_p->execute([$platform_id]);
$p_info = $stmt_p->fetch();

if (!$p_info) {
    die("Platform not found.");
}

// 일반 Admin 권한 체크
if ($role === 'Admin' && $p_info['admin_id'] !== $user_id) {
    die("Permission denied.");
}

// 2. 해당 승강장의 사진 로그 조회 (파일명 변경을 위해 항목 정보 포함)
$admin_item_filter = "";
if ($role === 'Admin') {
    $admin_item_filter = " AND i.admin_id = " . $pdo->quote($user_id);
}

$stmt_logs = $pdo->prepare("
    SELECT pl.photo_url, i.item_name, pl.photo_index, i.role_type, pl.timestamp
    FROM photo_logs pl
    JOIN items i ON pl.item_id = i.item_id
    WHERE pl.platform_id = ? $admin_item_filter
    ORDER BY FIELD(i.role_type, 'Safety', 'Worker'), i.sort_order ASC, pl.photo_index ASC
");
$stmt_logs->execute([$platform_id]);
$logs = $stmt_logs->fetchAll();

if (empty($logs)) {
    echo "<script>alert('압축할 사진이 없습니다.'); history.back();</script>";
    exit;
}

// 3. 스토리지 타입 확인
$admin_id = $p_info['admin_id'];
$storage_type = 'local';
$credentials = [];

try {
    $stmt_sc = $pdo->prepare("
        SELECT sc.type, sc.credentials 
        FROM members m 
        JOIN storage_configs sc ON m.storage_config_id = sc.config_id 
        WHERE m.member_id = ?
    ");
    $stmt_sc->execute([$admin_id]);
    $config = $stmt_sc->fetch();
    if ($config) {
        $storage_type = $config['type'];
        $credentials = json_decode($config['credentials'], true) ?? [];
    }
} catch (Exception $e) {}

// 4. ZIP 파일 생성 준비
$zip = new ZipArchive();
$zip_name = 'NAMED_' . $p_info['const_name'] . '_' . $p_info['site_name'] . '_' . $p_info['platform_name'] . '.zip';
$zip_name = str_replace(['/', '\\', '?', '%', '*', ':', '|', '"', '<', '>'], '_', $zip_name);
$temp_dir = __DIR__ . '/../uploads/temp_zip';
if (!is_dir($temp_dir)) {
    mkdir($temp_dir, 0777, true);
}
$zip_file_path = $temp_dir . '/' . uniqid() . '.zip';

if ($zip->open($zip_file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("Cannot open <$zip_file_path>\n");
}

// 파일명 프리픽스 생성
$prefix = $p_info['const_name'] . '_' . $p_info['site_name'] . '_' . $p_info['platform_name'];
$prefix = preg_replace('/[\(\)\[\]\{\}]/', '', $prefix); 
$prefix = preg_replace('/[^a-zA-Z0-9가-힣_\-]/u', '_', $prefix);
$prefix = preg_replace('/_+/', '_', $prefix);
$prefix = trim($prefix, '_');

if ($storage_type === 'r2') {
    $access_key = $credentials['access_key'] ?? '';
    $secret_key = $credentials['secret_key'] ?? '';
    $endpoint = $credentials['endpoint'] ?? '';
    $bucket = $credentials['bucket'] ?? '';

    if (!$access_key || !$secret_key || !$endpoint || !$bucket) {
        die("R2 설정 오류");
    }

    // AWS Signature V4 생성 함수 (내부 중복 방지를 위해 익명 함수나 체크 필요하지만 여기서는 단순 구현)
    if (!function_exists('getSignatureV4')) {
        function getSignatureV4($method, $url, $access_key, $secret_key, $headers = []) {
            $parsed_url = parse_url($url);
            $host = $parsed_url['host'];
            $path = $parsed_url['path'];
            $service = 's3'; $region = 'auto';
            $amz_date = gmdate('Ymd\THis\Z'); $date_stamp = gmdate('Ymd');
            $headers['host'] = $host;
            $headers['x-amz-date'] = $amz_date;
            $headers['x-amz-content-sha256'] = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
            ksort($headers);
            $canonical_headers = ''; $signed_headers = '';
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
            return ['Authorization: ' . $authorization, 'x-amz-date: ' . $amz_date, 'x-amz-content-sha256: ' . $headers['x-amz-content-sha256']];
        }
    }

    foreach ($logs as $l) {
        $path = $l['photo_url'];
        $request_url = $endpoint . '/' . $bucket . '/' . $path;
        $auth_headers = getSignatureV4('GET', $request_url, $access_key, $secret_key);

        $ch = curl_init($request_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $auth_headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $file_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200 && !empty($file_content)) {
            // 변경될 파일명 계산
            $original_name = basename($path);
            $ext = pathinfo($original_name, PATHINFO_EXTENSION);
            $clean_item_name = preg_replace('/[\(\)\[\]\{\}]/', '', $l['item_name']); 
            $clean_item_name = preg_replace('/[^a-zA-Z0-9가-힣_\-]/u', '_', $clean_item_name);
            $clean_item_name = preg_replace('/_+/', '_', $clean_item_name);
            $clean_item_name = trim($clean_item_name, '_');
            $date_part = ($l['role_type'] === 'Safety') ? '_' . date('Ymd', strtotime($l['timestamp'])) : '';
            $new_name = $prefix . '_' . $clean_item_name . $date_part . '_' . $l['photo_index'] . '.' . $ext;
            
            $zip->addFromString($new_name, $file_content);
        }
    }
} else {
    // Local
    foreach ($logs as $l) {
        $file_path = __DIR__ . '/../' . $l['photo_url'];
        if (file_exists($file_path)) {
            $original_name = basename($file_path);
            $ext = pathinfo($original_name, PATHINFO_EXTENSION);
            $clean_item_name = preg_replace('/[\(\)\[\]\{\}]/', '', $l['item_name']); 
            $clean_item_name = preg_replace('/[^a-zA-Z0-9가-힣_\-]/u', '_', $clean_item_name);
            $clean_item_name = preg_replace('/_+/', '_', $clean_item_name);
            $clean_item_name = trim($clean_item_name, '_');
            $date_part = ($l['role_type'] === 'Safety') ? '_' . date('Ymd', strtotime($l['timestamp'])) : '';
            $new_name = $prefix . '_' . $clean_item_name . $date_part . '_' . $l['photo_index'] . '.' . $ext;

            $zip->addFile($file_path, $new_name);
        }
    }
}

$zip->close();

if (file_exists($zip_file_path)) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_name . '"');
    header('Content-Length: ' . filesize($zip_file_path));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($zip_file_path);
    unlink($zip_file_path);
    exit;
} else {
    die("ZIP 생성 실패");
}
