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
        // 항목 타입 확인
        $stmt_type = $pdo->prepare("SELECT role_type FROM items WHERE item_id = ?");
        $stmt_type->execute([$item_id]);
        $role_type_item = $stmt_type->fetchColumn();
        $is_safety = ($role_type_item === 'Safety');
        $selected_date = $_POST['date'] ?? date('Y-m-d');

        // 1. 기존 파일 경로 및 소유자 조회 (인덱스별, Safety는 일자별)
        $sql = "SELECT log_id, photo_url, user_id FROM photo_logs WHERE platform_id = ? AND item_id = ? AND photo_index = ?";
        if ($is_safety) {
            $sql .= " AND DATE(timestamp) = " . $pdo->quote($selected_date);
        }
        $stmt = $pdo->prepare($sql);
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

            // 2. 스토리지 타입 확인 및 삭제
            if (!empty($photo_url)) {
                $admin_id = $_SESSION['user_id'];
                if ($_SESSION['role_type'] === 'Worker' || $_SESSION['role_type'] === 'Safety') {
                    $admin_id = $_SESSION['parent_admin_id'] ?? '';
                }

                $storage_type = 'local';
                $credentials = [];

                if ($admin_id) {
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
                }

                if ($storage_type === 'r2') {
                    $access_key = $credentials['access_key'] ?? '';
                    $secret_key = $credentials['secret_key'] ?? '';
                    $endpoint = $credentials['endpoint'] ?? '';
                    $bucket = $credentials['bucket'] ?? '';

                    if ($access_key && $secret_key && $endpoint && $bucket) {
                        if (!function_exists('getSignatureV4')) {
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
                                $headers['x-amz-content-sha256'] = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
                                
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
                        }

                        $request_url = $endpoint . '/' . $bucket . '/' . $photo_url;
                        $auth_headers = getSignatureV4('DELETE', $request_url, $access_key, $secret_key);

                        $ch = curl_init($request_url);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $auth_headers);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

                        curl_exec($ch);
                        curl_close($ch);
                    }
                } else {
                    $file_path = '../' . $photo_url;
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
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
