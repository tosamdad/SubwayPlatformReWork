<?php
require_once 'inc/db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('로그인이 필요합니다.');
}

$path = $_GET['path'] ?? '';
if (empty($path) || strpos($path, 'uploads/') !== 0) {
    http_response_code(400);
    exit('잘못된 요청입니다.');
}

$user_id = $_SESSION['user_id'];
$role_type = $_SESSION['role_type'] ?? '';

// 1. 관리자의 Storage Config 찾기
$admin_id = $user_id;
if ($role_type === 'Worker' || $role_type === 'Safety') {
    $admin_id = $_SESSION['parent_admin_id'] ?? '';
}

$storage_type = 'local';
$credentials = [];

if ($admin_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT sc.type, sc.credentials 
            FROM members m 
            JOIN storage_configs sc ON m.storage_config_id = sc.config_id 
            WHERE m.member_id = ?
        ");
        $stmt->execute([$admin_id]);
        $config = $stmt->fetch();
        if ($config) {
            $storage_type = $config['type'];
            $credentials = json_decode($config['credentials'], true) ?? [];
        }
    } catch (Exception $e) {}
}

if ($storage_type === 'r2') {
    $access_key = $credentials['access_key'] ?? '';
    $secret_key = $credentials['secret_key'] ?? '';
    $endpoint = $credentials['endpoint'] ?? '';
    $bucket = $credentials['bucket'] ?? '';

    if (!$access_key || !$secret_key || !$endpoint || !$bucket) {
        http_response_code(500);
        exit('R2 설정 오류');
    }

    // AWS Signature V4 생성 함수 (GET용)
    function getSignatureV4($method, $url, $access_key, $secret_key, $headers = []) {
        $parsed_url = parse_url($url);
        $host = $parsed_url['host'];
        $path = $parsed_url['path'];
        
        $service = 's3';
        $region = 'auto';
        
        $amz_date = gmdate('Ymd\THis\Z');
        $date_stamp = gmdate('Ymd');
        
        $headers['host'] = $host;
        $headers['x-amz-date'] = $amz_date;
        $headers['x-amz-content-sha256'] = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'; // Empty payload hash
        
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

    $request_url = $endpoint . '/' . $bucket . '/' . $path;
    $auth_headers = getSignatureV4('GET', $request_url, $access_key, $secret_key);

    $ch = curl_init($request_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $auth_headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($http_code == 200) {
        header('Content-Type: ' . $content_type);
        echo $response;
    } else {
        http_response_code(404);
        exit('파일을 찾을 수 없습니다. (R2)');
    }
} else {
    // Local
    $full_path = __DIR__ . '/' . $path;
    if (file_exists($full_path)) {
        $mime_type = mime_content_type($full_path);
        header('Content-Type: ' . $mime_type);
        readfile($full_path);
    } else {
        http_response_code(404);
        exit('파일을 찾을 수 없습니다. (Local)');
    }
}
