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

// 2. 사진 로그 조회
$admin_item_filter = "";
if ($role === 'Admin') {
    $admin_item_filter = " AND i.admin_id = " . $pdo->quote($user_id);
}

$stmt_logs = $pdo->prepare("
    SELECT pl.photo_url, i.item_name, i.item_code, pl.photo_index
    FROM photo_logs pl
    JOIN items i ON pl.item_id = i.item_id
    WHERE pl.platform_id = ? $admin_item_filter
    ORDER BY i.sort_order ASC, pl.photo_index ASC
");
$stmt_logs->execute([$platform_id]);
$logs = $stmt_logs->fetchAll();

// 3. CSV 생성
$filename = "List_" . $p_info['const_name'] . "_" . $p_info['site_name'] . "_" . $p_info['platform_name'] . ".csv";
$filename = str_replace(['/', '\\', '?', '%', '*', ':', '|', '"', '<', '>', ' '], '_', $filename);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// UTF-8 BOM (for Excel)
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// 헤더
fputcsv($output, ['공사명', '역명', '승강장명', '항목키', '항목명', '순번', '현재파일명', '변경될파일명', '변경명령어(CMD)']);

$prefix = $p_info['const_name'] . '_' . $p_info['site_name'] . '_' . $p_info['platform_name'];
$prefix = str_replace(['/', '\\', '?', '%', '*', ':', '|', '"', '<', '>', ' '], '_', $prefix);

foreach ($logs as $l) {
    $original_name = basename($l['photo_url']);
    $ext = pathinfo($original_name, PATHINFO_EXTENSION);
    
    // 변경될 파일명: 공사명_역명_승강장_키값_순번.확장자
    $new_name = $prefix . '_' . $l['item_code'] . '_' . $l['photo_index'] . '.' . $ext;
    $new_name = str_replace(['/', '\\', '?', '%', '*', ':', '|', '"', '<', '>', ' '], '_', $new_name);

    // 윈도우용 rename 명령어 생성
    $rename_cmd = "ren \"$original_name\" \"$new_name\"";

    fputcsv($output, [
        $p_info['const_name'],
        $p_info['site_name'],
        $p_info['platform_name'],
        $l['item_code'],
        $l['item_name'],
        $l['photo_index'],
        $original_name,
        $new_name,
        $rename_cmd
    ]);
}

fclose($output);
exit;
