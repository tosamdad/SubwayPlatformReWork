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

// 1. 정보 조회
$stmt_p = $pdo->prepare("
    SELECT p.platform_name, s.site_name, s.site_code, c.const_name, c.const_code, c.admin_id 
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

// 2. 사진 로그 조회
$admin_item_filter = "";
if ($role === 'Admin') {
    $admin_item_filter = " AND i.admin_id = " . $pdo->quote($user_id);
}

$stmt_logs = $pdo->prepare("
    SELECT DISTINCT i.item_code, i.item_name, i.sort_order, i.role_type
    FROM photo_logs pl
    JOIN items i ON pl.item_id = i.item_id
    WHERE pl.platform_id = ? $admin_item_filter
    ORDER BY FIELD(i.role_type, 'Safety', 'Worker'), i.sort_order ASC
");
$stmt_logs->execute([$platform_id]);
$logs = $stmt_logs->fetchAll();

// 3. CSV 생성
$raw_filename = "List_" . $p_info['const_name'] . "_" . $p_info['site_name'] . "_" . $p_info['platform_name'];
$clean_filename = preg_replace('/[\(\)\[\]\{\}]/', '', $raw_filename); // 괄호 제거
$clean_filename = preg_replace('/[^a-zA-Z0-9가-힣_\-]/u', '_', $clean_filename); // 특수문자 -> _
$clean_filename = preg_replace('/_+/', '_', $clean_filename); // 연속 언더바 제거
$filename = trim($clean_filename, '_') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// UTF-8 BOM (for Excel)
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// 헤더
fputcsv($output, ['공사키', '공사명', '역사키', '역사명', '항목키', '항목명']);

foreach ($logs as $l) {
    fputcsv($output, [
        $p_info['const_code'],
        $p_info['const_name'],
        $p_info['site_code'],
        $p_info['site_name'],
        $l['item_code'],
        $l['item_name']
    ]);
}

fclose($output);
exit;
