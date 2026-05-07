<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    header('Location: ./index.php');
    exit;
}

$site_id = $_GET['site_id'] ?? '';
$platform_id = $_GET['platform_id'] ?? '';
$role_filter = $_GET['role'] ?? 'All'; // All | Safety | Worker
$selected_safety_date = $_GET['date'] ?? ''; // 선택된 안전 점검 일자

if (!$site_id) {
    echo "<script>alert('잘못된 접근입니다.'); location.href='projects.php';</script>";
    exit;
}

// 역사 및 공사 정보 조회 (권한 체크 포함)
$stmt = $pdo->prepare("
    SELECT s.*, c.const_name, c.const_code, c.admin_id 
    FROM sites s 
    JOIN constructions c ON s.const_id = c.const_id 
    WHERE s.site_id = ?
");
$stmt->execute([$site_id]);
$site = $stmt->fetch();

if (!$site) {
    echo "<script>alert('역사 정보를 찾을 수 없습니다.'); location.href='projects.php';</script>";
    exit;
}

// 일반 Admin의 경우 소유권 체크
if ($role == 'Admin' && $site['admin_id'] !== $user_id) {
    echo "<script>alert('접근 권한이 없는 현장입니다.'); location.href='projects.php';</script>";
    exit;
}

// 승강장 목록 조회
$platforms_stmt = $pdo->prepare("SELECT * FROM platforms WHERE site_id = ? ORDER BY platform_id ASC");
$platforms_stmt->execute([$site_id]);
$all_platforms = $platforms_stmt->fetchAll();

// 선택된 승강장 (기본값: 첫 번째)
if (!$platform_id && !empty($all_platforms)) {
    $platform_id = $all_platforms[0]['platform_id'];
}

// 선택된 승강장 데이터 및 공정율 계산
$p_data = null;
if ($platform_id) {
    try {
        // 해당 현장 소유 관리자의 ID를 기준으로 항목 필터링 (다른 관리자의 중복 항목 방지)
        $site_owner_id = $site['admin_id'];
        $admin_item_filter = " AND admin_id = " . $pdo->quote($site_owner_id);

        // 안전 점검 일수 계산 (통계용)
        $stmt_safety_days = $pdo->prepare("SELECT COUNT(DISTINCT DATE(pl.timestamp)) FROM photo_logs pl JOIN items i ON pl.item_id = i.item_id WHERE pl.platform_id = ? AND i.role_type = 'Safety'");
        $stmt_safety_days->execute([$platform_id]);
        $safety_day_count = (int)$stmt_safety_days->fetchColumn();
        if ($safety_day_count < 1) $safety_day_count = 1;

        // 1. Worker Stats 계산
        $stmt_w_total = $pdo->prepare("SELECT SUM(photo_count) FROM items WHERE is_visible_mobile = 1 AND role_type = 'Worker' AND ((platform_id IS NULL $admin_item_filter AND item_id NOT IN (SELECT item_id FROM platform_excluded_items WHERE platform_id = ?)) OR platform_id = ?)");
        $stmt_w_total->execute([$platform_id, $platform_id]);
        $w_total = (int)$stmt_w_total->fetchColumn();

        $stmt_w_up = $pdo->prepare("SELECT COUNT(DISTINCT pl.item_id, pl.photo_index) FROM photo_logs pl JOIN items i ON pl.item_id = i.item_id WHERE pl.platform_id = ? AND i.role_type = 'Worker' AND i.is_visible_mobile = 1 AND ( (i.platform_id IS NULL $admin_item_filter AND i.item_id NOT IN (SELECT item_id FROM platform_excluded_items WHERE platform_id = ?)) OR i.platform_id = ? )");
        $stmt_w_up->execute([$platform_id, $platform_id, $platform_id]);
        $w_up = (int)$stmt_w_up->fetchColumn();

        // 2. Safety Stats 계산 (점검 일수 반영)
        $stmt_s_total = $pdo->prepare("SELECT SUM(photo_count) FROM items WHERE is_visible_mobile = 1 AND role_type = 'Safety' AND ((platform_id IS NULL $admin_item_filter AND item_id NOT IN (SELECT item_id FROM platform_excluded_items WHERE platform_id = ?)) OR platform_id = ?)");
        $stmt_s_total->execute([$platform_id, $platform_id]);
        $s_total_raw = (int)$stmt_s_total->fetchColumn();
        $s_total = $s_total_raw * $safety_day_count;

        $stmt_s_up = $pdo->prepare("SELECT COUNT(*) FROM photo_logs pl JOIN items i ON pl.item_id = i.item_id WHERE pl.platform_id = ? AND i.role_type = 'Safety' AND i.is_visible_mobile = 1 AND ( (i.platform_id IS NULL $admin_item_filter AND i.item_id NOT IN (SELECT item_id FROM platform_excluded_items WHERE platform_id = ?)) OR i.platform_id = ? )");
        $stmt_s_up->execute([$platform_id, $platform_id, $platform_id]);
        $s_up = (int)$stmt_s_up->fetchColumn();

        // 3. 현재 뷰(필터) 기준 실시간 통계 보정 (특정 일자 선택 시)
        $s_total_view = $s_total;
        $s_up_view = $s_up;
        $all_total_view = $w_total + $s_total;
        $all_up_view = $w_up + $s_up;

        if (!empty($selected_safety_date)) {
            // 특정 날짜가 선택된 경우, 해당 날짜의 통계만 추출
            $s_total_view = $s_total_raw; // 1일분 수량
            
            $stmt_s_up_day = $pdo->prepare("
                SELECT COUNT(*) FROM photo_logs pl 
                JOIN items i ON pl.item_id = i.item_id 
                WHERE pl.platform_id = ? AND i.role_type = 'Safety' AND i.is_visible_mobile = 1 
                AND DATE(pl.timestamp) = ?
                AND ( (i.platform_id IS NULL $admin_item_filter AND i.item_id NOT IN (SELECT item_id FROM platform_excluded_items WHERE platform_id = ?)) OR i.platform_id = ? )
            ");
            $stmt_s_up_day->execute([$platform_id, $selected_safety_date, $platform_id, $platform_id]);
            $s_up_view = (int)$stmt_s_up_day->fetchColumn();

            // '전체' 통계도 해당 일자 안전 + 누적 작업자 합계로 보정 (또는 필요에 따라 조정 가능)
            $all_total_view = $w_total + $s_total_view;
            $all_up_view = $w_up + $s_up_view;
        }

        $stats = [
            'All' => [
                'total' => $all_total_view,
                'uploaded' => $all_up_view,
                'progress' => ($all_total_view > 0) ? round(($all_up_view / $all_total_view) * 100) : 0
            ],
            'Safety' => [
                'total' => $s_total_view,
                'uploaded' => $s_up_view,
                'progress' => ($s_total_view > 0) ? round(($s_up_view / $s_total_view) * 100) : 0
            ],
            'Worker' => [
                'total' => $w_total,
                'uploaded' => $w_up,
                'progress' => ($w_total > 0) ? round(($w_up / $w_total) * 100) : 0
            ]
        ];
        
        $p_stmt = $pdo->prepare("SELECT * FROM platforms WHERE platform_id = ?");
        $p_stmt->execute([$platform_id]);
        $p_info = $p_stmt->fetch();
        
        $p_data = [
            'info' => $p_info,
            'stats' => $stats,
            'safety_day_count' => $safety_day_count
        ];

        // 상세 공정 리스트 조회 (화면 표시용)
        $where_role_sql = ($role_filter === 'All') ? "" : " AND i.role_type = '$role_filter' ";
        $stmt_items = $pdo->prepare("
            SELECT i.*,
                   (SELECT COUNT(*) FROM platform_excluded_items WHERE platform_id = ? AND item_id = i.item_id) as is_excluded
            FROM items i
            WHERE (
                (i.platform_id IS NULL $admin_filter_sql)
                OR i.platform_id = ?
            )
            $where_role_sql
            ORDER BY FIELD(i.role_type, 'Safety', 'Worker'), i.sort_order ASC, i.platform_id ASC
        ");
        $stmt_items->execute([$platform_id, $platform_id]);
        $items = $stmt_items->fetchAll();

    // 로그 가져오기 및 분류
    $worker_logs = []; // [item_id][photo_index]
    $safety_logs_by_date = []; // [date][item_id][photo_index]
    $safety_dates = []; 
    
    $stmt_logs = $pdo->prepare("
        SELECT pl.*, i.role_type 
        FROM photo_logs pl
        JOIN items i ON pl.item_id = i.item_id
        WHERE pl.platform_id = ?
    ");
    $stmt_logs->execute([$platform_id]);
    while ($row = $stmt_logs->fetch()) {
        if ($row['role_type'] === 'Worker') {
            $worker_logs[$row['item_id']][$row['photo_index']] = $row;
        } else if ($row['role_type'] === 'Safety') {
            $work_date = date('Y-m-d', strtotime($row['timestamp']));
            $safety_logs_by_date[$work_date][$row['item_id']][$row['photo_index']] = $row;
            if (!in_array($work_date, $safety_dates)) $safety_dates[] = $work_date;
        }
    }
    rsort($safety_dates); // 최신 점검일 순
    
    // 메모 데이터를 가져와서 맵핑
    $item_memos = [];
    $stmt_memos = $pdo->prepare("SELECT item_id, memo_text FROM item_memos WHERE platform_id = ?");
    $stmt_memos->execute([$platform_id]);
    while ($row = $stmt_memos->fetch()) {
        $item_memos[$row['item_id']] = $row['memo_text'];
    }
} catch (Exception $e) { $items = []; $item_logs = []; $item_memos = []; }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL Admin - <?php echo h($site['site_name']); ?> 모니터링</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        .admin-sidebar { background: #ffffff; border-right: 1px solid #e2e8f0; min-height: 100vh; padding: 2rem 1.5rem; }
        .admin-content { padding: 2.5rem; }
        .section-card { background: white; border-radius: 1rem; padding: 1.75rem; margin-bottom: 2rem; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .nav-link { color: #64748b; margin-bottom: 0.75rem; border-radius: 0.75rem; padding: 0.75rem 1rem; font-weight: 500; transition: all 0.2s; }
        .nav-link:hover { background: #f1f5f9; color: #0f172a; }
        .nav-link.active { background: #eff6ff; color: #2563eb; }
        
        .summary-card { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: white; border: none; border-radius: 1.25rem; }
        .stat-box { background: rgba(255,255,255,0.05); border-radius: 1rem; padding: 1rem; border: 1px solid rgba(255,255,255,0.1); }
        
        .progress-bar-custom { height: 8px; background: rgba(255,255,255,0.1); border-radius: 4px; margin-top: 10px; }
        .progress-bar-custom .fill { height: 100%; border-radius: 4px; }
        .fill-all { background: #3b82f6; }
        .fill-safety { background: #f59e0b; }
        .fill-worker { background: #10b981; }

        .photo-card { cursor: pointer; transition: transform 0.2s; position: relative; }
        .photo-card:hover { transform: translateY(-5px); }
        .photo-preview { width: 100%; height: 180px; object-fit: cover; border-radius: 0.5rem; background: #f1f5f9; }
        .empty-photo { width: 100%; height: 180px; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #f8fafc; border: 2px dashed #e2e8f0; border-radius: 0.5rem; color: #94a3b8; }
        
        /* 제외 항목 스타일 (레드) */
        .photo-card.excluded { opacity: 0.7; background-color: #f1f5f9 !important; pointer-events: none; border-color: #ef4444 !important; }
        .photo-card.excluded::after {
            content: '제외 항목';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-10deg);
            font-size: 2rem;
            font-weight: 950;
            color: rgba(239, 68, 68, 0.7);
            border: 4px solid rgba(239, 68, 68, 0.6);
            padding: 0.5rem 1.5rem;
            border-radius: 0.75rem;
            background: rgba(255, 255, 255, 0.4);
            pointer-events: none;
            z-index: 10;
            white-space: nowrap;
        }

        /* 모바일 숨김 스타일 (퍼플) */
        .photo-card.mobile-hidden { opacity: 0.7; background-color: #f1f5f9 !important; pointer-events: none; border-color: #8b5cf6 !important; }
        .photo-card.mobile-hidden::after {
            content: '모바일 숨김';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-10deg);
            font-size: 2rem;
            font-weight: 950;
            color: rgba(139, 92, 246, 0.7);
            border: 4px solid rgba(139, 92, 246, 0.6);
            padding: 0.5rem 1.5rem;
            border-radius: 0.75rem;
            background: rgba(255, 255, 255, 0.4);
            pointer-events: none;
            z-index: 10;
            white-space: nowrap;
        }

        /* 다중 사진 그리드 (관리자용) */
        .admin-photo-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        .admin-photo-slot {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            aspect-ratio: 4/3;
        }
        .admin-photo-slot img { width: 100%; height: 100%; object-fit: cover; }
        .admin-photo-slot .slot-label {
            position: absolute; top: 5px; left: 5px;
            background: rgba(0,0,0,0.5); color: white;
            font-size: 0.65rem; padding: 2px 6px; border-radius: 4px;
            z-index: 1;
        }
        .admin-photo-slot .empty-label {
            height: 100%; display: flex; align-items: center; justify-content: center;
            color: #cbd5e1; font-size: 0.75rem;
        }
        
        .filter-tabs .btn { border-radius: 0.75rem; padding: 0.5rem 1.25rem; font-weight: 600; font-size: 0.9rem; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <?php include_once 'inc/sidebar.php'; ?>

        <div class="col-md-10 admin-content">
            <header class="mb-5 d-flex justify-content-between align-items-end">
                <div>
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge bg-primary me-2"><?php echo h($site['const_code']); ?></span>
                        <span class="text-muted fw-medium"><?php echo h($site['const_name']); ?></span>
                    </div>
                    <h2 class="fw-bold text-dark mb-0"><i class="bi bi-geo-alt-fill text-danger me-2"></i> <?php echo h($site['site_name']); ?> 모니터링</h2>
                </div>
                <div class="d-flex gap-3 align-items-center">
                    <?php if ($platform_id): ?>
                    <a href="download_zip.php?platform_id=<?php echo $platform_id; ?>" class="btn btn-outline-primary rounded-pill shadow-sm fw-bold px-3">
                        <i class="bi bi-file-earmark-zip-fill me-1"></i> 사진 다운로드 (ZIP)
                    </a>
                    <a href="download_excel.php?platform_id=<?php echo $platform_id; ?>" class="btn btn-outline-success rounded-pill shadow-sm fw-bold px-3">
                        <i class="bi bi-file-earmark-spreadsheet-fill me-1"></i> 엑셀 다운로드 (CSV)
                    </a>
                    <a href="projects_platform_items.php?id=<?php echo $platform_id; ?>&role=<?php echo $role_filter; ?>" class="btn btn-outline-dark rounded-pill shadow-sm fw-bold px-3">
                        <i class="bi bi-gear-fill me-1"></i> 항목 설정
                    </a>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($p_data): ?>
            <!-- 선택된 승강장 요약 정보 (강조) -->
            <div class="section-card summary-card p-4 mb-4 shadow">
                <div class="row align-items-center">
                    <div class="col-md-4 border-end border-white border-opacity-10 py-2">
                        <div class="small opacity-50 mb-1 fw-bold">SELECTED PLATFORM</div>
                        <h2 class="fw-bold mb-0 text-white"><?php echo h($p_data['info']['platform_name']); ?></h2>
                    </div>
                    <div class="col-md-8 ps-md-5">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <div class="stat-box">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div class="small fw-bold opacity-50">전체 공정율</div>
                                        <div class="small opacity-75"><?php echo $p_data['stats']['All']['uploaded']; ?>/<?php echo $p_data['stats']['All']['total']; ?></div>
                                    </div>
                                    <div class="fs-3 fw-bold"><?php echo $p_data['stats']['All']['progress']; ?>%</div>
                                    <div class="progress-bar-custom"><div class="fill fill-all" style="width: <?php echo $p_data['stats']['All']['progress']; ?>%"></div></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-box">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div class="small fw-bold opacity-50">안전관리 사진</div>
                                        <div class="small opacity-75">
                                            <span class="badge bg-white text-warning me-1" style="font-size: 0.6rem;">점검 <?php echo $p_data['safety_day_count']; ?>일</span>
                                            <?php echo $p_data['stats']['Safety']['uploaded']; ?>/<?php echo $p_data['stats']['Safety']['total']; ?>
                                        </div>
                                    </div>
                                    <div class="fs-3 fw-bold text-warning"><?php echo $p_data['stats']['Safety']['progress']; ?>%</div>
                                    <div class="progress-bar-custom"><div class="fill fill-safety" style="width: <?php echo min(100, $p_data['stats']['Safety']['progress']); ?>%"></div></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-box">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div class="small fw-bold opacity-50">작업자 항목 사진</div>
                                        <div class="small opacity-75"><?php echo $p_data['stats']['Worker']['uploaded']; ?>/<?php echo $p_data['stats']['Worker']['total']; ?></div>
                                    </div>
                                    <div class="fs-3 fw-bold text-info"><?php echo $p_data['stats']['Worker']['progress']; ?>%</div>
                                    <div class="progress-bar-custom"><div class="fill fill-worker" style="width: <?php echo $p_data['stats']['Worker']['progress']; ?>%"></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 상세 공정 리스트 -->
            <div class="section-card">
                <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i>
                        상세 내역
                    </h5>
                    <div class="d-flex align-items-center gap-3">
                        <div class="filter-tabs btn-group p-1 bg-white border rounded-pill shadow-sm" style="font-size: 0.85rem;">
                            <a href="?site_id=<?php echo $site_id; ?>&platform_id=<?php echo $platform_id; ?>&role=All" 
                               class="btn <?php echo $role_filter == 'All' ? 'btn-primary' : 'btn-light'; ?> rounded-pill px-3">전체</a>
                            
                            <?php foreach ($safety_dates as $sd): 
                                $sd_display = date('md', strtotime($sd)) . '안전';
                                $is_active = ($role_filter == 'Safety' && $selected_safety_date == $sd);
                            ?>
                                <a href="?site_id=<?php echo $site_id; ?>&platform_id=<?php echo $platform_id; ?>&role=Safety&date=<?php echo $sd; ?>" 
                                   class="btn <?php echo $is_active ? 'btn-warning text-white' : 'btn-light'; ?> rounded-pill px-3"><?php echo $sd_display; ?></a>
                            <?php endforeach; ?>

                            <a href="?site_id=<?php echo $site_id; ?>&platform_id=<?php echo $platform_id; ?>&role=Worker" 
                               class="btn <?php echo $role_filter == 'Worker' ? 'btn-success' : 'btn-light'; ?> rounded-pill px-3">작업자</a>
                        </div>
                        <div class="text-muted small fw-medium">
                            항목수: <span class="text-dark fw-bold">
                                <?php 
                                $active_count = 0;
                                foreach($items as $it) {
                                    if(!($it['is_excluded'] > 0) && $it['is_visible_mobile'] == 1) {
                                        if ($role_filter === 'All') {
                                            if ($it['role_type'] === 'Safety') $active_count += $p_data['safety_day_count'];
                                            else $active_count++;
                                        } else if ($role_filter === 'Safety') {
                                            if (!empty($selected_safety_date)) $active_count++;
                                            else $active_count += $p_data['safety_day_count'];
                                        } else {
                                            if ($it['role_type'] === 'Worker') $active_count++;
                                        }
                                    }
                                }
                                echo $active_count;
                                ?>개
                            </span>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <?php 
                    // 항목 카드 렌더링 도우미 함수
                    function renderAdminItemCard($item, $logs, $p_data, $item_memos) {
                        $photo_count = (int)($item['photo_count'] ?? 1);
                        $is_excluded = ($item['is_excluded'] > 0);
                        $is_mobile_hidden = ($item['is_visible_mobile'] == 0);
                        
                        $card_class = "";
                        if ($is_excluded) $card_class = "excluded";
                        else if ($is_mobile_hidden) $card_class = "mobile-hidden";
                        ?>
                        <div class="col-md-4 col-lg-3">
                            <div class="photo-card h-100 border rounded-3 p-3 bg-white shadow-sm <?php echo $card_class; ?>" id="card-<?php echo $item['item_id']; ?>">
                                <div class="mb-3">
                                    <?php if ($photo_count > 1): ?>
                                        <div class="admin-photo-grid">
                                            <?php for ($i = 1; $i <= $photo_count; $i++): 
                                                $l = $logs[$i] ?? null;
                                                $has_p = !empty($l);
                                                $is_owner = true; 
                                            ?>
                                                <div class="admin-photo-slot" onclick="<?php echo $has_p ? "openImageViewer('../view_photo.php?path=".urlencode($l['photo_url'])."&t=".time()."', {$item['item_id']}, true, $i)" : "startCapture({$item['item_id']}, $i)"; ?>">
                                                    <span class="slot-label"><?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?></span>
                                                    <?php if ($has_p): ?>
                                                        <img src="../view_photo.php?path=<?php echo urlencode($l['photo_url']); ?>&t=<?php echo time(); ?>">
                                                    <?php else: ?>
                                                        <div class="empty-label"><i class="bi bi-camera"></i></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    <?php else: ?>
                                        <?php 
                                        $l = $logs[1] ?? null;
                                        $has_p = !empty($l);
                                        ?>
                                        <div id="slot-<?php echo $item['item_id']; ?>">
                                            <?php if ($has_p): ?>
                                                <div class="position-relative">
                                                    <img src="../view_photo.php?path=<?php echo urlencode($l['photo_url']); ?>&t=<?php echo time(); ?>" class="photo-preview shadow-sm" style="cursor: pointer;" alt="공정사진" onclick="openImageViewer('../view_photo.php?path=<?php echo urlencode($l['photo_url']); ?>&t=<?php echo time(); ?>', <?php echo $item['item_id']; ?>, true, 1)">
                                                    <div class="position-absolute top-0 end-0 m-2">
                                                        <button class="btn btn-sm btn-light rounded-circle shadow-sm me-1" onclick="startCapture(<?php echo $item['item_id']; ?>, 1)" title="사진 변경"><i class="bi bi-camera"></i></button>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="empty-photo" style="cursor: pointer;" onclick="startCapture(<?php echo $item['item_id']; ?>, 1)">
                                                    <i class="bi bi-camera fs-1 mb-2 text-primary opacity-50"></i>
                                                    <span class="small fw-bold text-primary opacity-75">사진 업로드 (클릭)</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <code class="small"><?php echo h($item['item_code']); ?></code>
                                        <span class="badge <?php echo $item['role_type'] == 'Safety' ? 'bg-warning text-white' : 'bg-success'; ?> py-1 px-2" style="font-size: 0.6rem;"><?php echo h($item['role_type']); ?></span>
                                    </div>
                                    <div class="fw-bold text-dark text-truncate mb-1" title="<?php echo h($item['item_name']); ?>">
                                        <?php echo h($item['item_name']); ?>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="text-muted small" style="font-size: 0.7rem;"><?php echo h($item['category_name']); ?></div>
                                        <span class="badge <?php echo ($is_excluded || !is_null($item['platform_id'])) ? 'bg-danger bg-opacity-10 text-danger border-danger-subtle' : 'bg-primary bg-opacity-10 text-primary border-primary-subtle'; ?> fw-bold border" style="font-size: 0.65rem;">
                                            <?php echo ($is_excluded || !is_null($item['platform_id'])) ? h($p_data['info']['platform_name']) . '만 적용' : '전체적용'; ?>
                                        </span>
                                    </div>
                                    
                                    <?php 
                                    $latest_time = null; $latest_user = null;
                                    foreach ($logs as $log_row) {
                                        if (!$latest_time || $log_row['timestamp'] > $latest_time) {
                                            $latest_time = $log_row['timestamp']; $latest_user = $log_row['user_id'];
                                        }
                                    }
                                    if ($latest_time): 
                                    ?>
                                        <div class="text-muted border-top pt-2 mt-2 d-flex justify-content-between align-items-center" style="font-size: 0.7rem;">
                                            <span><i class="bi bi-person-fill me-1"></i> <?php echo h($latest_user ?? '-'); ?></span>
                                            <span><i class="bi bi-clock me-1"></i> <?php echo date('m-d H:i', strtotime($latest_time)); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($item_memos[$item['item_id']])): ?>
                                        <div class="mt-2 p-2 rounded" style="background-color: #fff1f2; border: 1px solid #fecdd3; font-size: 0.75rem;">
                                            <div class="fw-bold text-danger mb-1"><i class="bi bi-chat-text-fill me-1"></i>특이사항 메모</div>
                                            <div class="text-dark" style="word-break: keep-all; line-height: 1.4;"><?php echo nl2br(h($item_memos[$item['item_id']])); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php
                    }

                    // 1. 작업자 항목 (Worker)
                    if ($role_filter === 'All' || $role_filter === 'Worker'): ?>
                        <div class="col-12 mt-4 mb-2">
                            <div class="d-flex align-items-center">
                                <h6 class="fw-bold mb-0 text-dark border-start border-4 border-success ps-2">작업자 항목 (누적)</h6>
                                <hr class="flex-grow-1 ms-3 opacity-10">
                            </div>
                        </div>
                        <?php 
                        $has_worker = false;
                        foreach ($items as $item) {
                            if ($item['role_type'] !== 'Worker') continue;
                            $has_worker = true;
                            renderAdminItemCard($item, $worker_logs[$item['item_id']] ?? [], $p_data, $item_memos);
                        }
                        if (!$has_worker) echo '<div class="col-12 text-center py-3 text-muted small">해당 항목이 없습니다.</div>';
                        ?>
                    <?php endif; ?>

                    <?php 
                    // 2. 안전관리 항목 (Safety) - 일자별 그룹화
                    if ($role_filter === 'All' || $role_filter === 'Safety'): ?>
                        <div class="col-12 mt-5 mb-2">
                            <div class="d-flex align-items-center">
                                <h6 class="fw-bold mb-0 text-dark border-start border-4 border-warning ps-2">안전관리 점검 내역 (일자별)</h6>
                                <hr class="flex-grow-1 ms-3 opacity-10">
                            </div>
                        </div>
                        
                        <?php if (empty($safety_dates)): ?>
                            <div class="col-12 text-center py-4 text-muted small">안전 점검 내역이 없습니다.</div>
                        <?php else: ?>
                            <?php 
                            foreach ($safety_dates as $date): 
                                // 특정 날짜가 선택된 경우 해당 날짜만 출력
                                if ($role_filter === 'Safety' && !empty($selected_safety_date) && $selected_safety_date !== $date) continue;
                            ?>
                                <div class="col-12 mt-3">
                                    <div class="bg-light p-2 px-3 rounded-pill d-inline-block fw-bold text-primary mb-3 shadow-sm border border-primary-subtle" style="font-size: 0.85rem;">
                                        <i class="bi bi-calendar-check me-2"></i><?php echo $date; ?> 점검 항목
                                    </div>
                                    <div class="row g-4">
                                        <?php 
                                        foreach ($items as $item) {
                                            if ($item['role_type'] !== 'Safety') continue;
                                            $s_logs = $safety_logs_by_date[$date][$item['item_id']] ?? [];
                                            // 사진이 없어도 작업자 항목처럼 카드 출력 (사용자 요청)
                                            renderAdminItemCard($item, $s_logs, $p_data, $item_memos);
                                        }
                                        ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if (empty($items)): ?>
                        <div class="col-12 text-center py-5">
                            <i class="bi bi-info-circle fs-1 text-muted d-block mb-3"></i>
                            <p class="text-muted">해당 조건의 공정 항목이 없습니다.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
                <div class="section-card text-center py-5 text-muted">
                    승강장을 선택하여 상세 공정 현황을 확인하세요.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 숨겨진 카메라/갤러리 입력창 -->
<input type="file" id="cameraInput" accept="image/*" style="display:none;">

<!-- 심플 라이트박스 뷰어 -->
<div id="customLightbox" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.15); backdrop-filter:blur(4px); z-index:1050; align-items:center; justify-content:center; flex-direction:column; padding:20px;">
    <div style="position:relative; display:inline-block; max-width:100%; max-height:80vh;">
        <img id="lightboxImage" src="" style="max-width:100%; max-height:80vh; object-fit:contain; border-radius:12px; box-shadow:0 10px 40px rgba(0,0,0,0.5); cursor:pointer;" onclick="closeLightbox()">
        <button id="deletePhotoBtn" class="btn btn-danger position-absolute" style="bottom:-60px; left:50%; transform:translateX(-50%); white-space:nowrap; border-radius:30px; padding:10px 24px; font-weight:bold; box-shadow:0 4px 15px rgba(0,0,0,0.3);"><i class="bi bi-trash me-2"></i>사진 삭제 및 재촬영</button>
    </div>
</div>

<!-- 커스텀 Alert 모달 -->
<div class="modal fade" id="customAlertModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
  <div class="modal-dialog modal-dialog-centered px-4">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <div class="modal-body text-center p-4">
        <i class="bi bi-info-circle text-primary mb-3" style="font-size: 2.5rem; display: block;"></i>
        <p id="customAlertMessage" class="mb-4 text-dark" style="font-size: 1.05rem; word-break: keep-all;"></p>
        <button type="button" class="btn btn-primary w-100 rounded-pill py-2" data-bs-dismiss="modal">확인</button>
      </div>
    </div>
  </div>
</div>

<!-- 커스텀 Confirm 모달 -->
<div class="modal fade" id="customConfirmModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
  <div class="modal-dialog modal-dialog-centered px-4">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <div class="modal-body text-center p-4">
        <i class="bi bi-exclamation-triangle text-warning mb-3" style="font-size: 2.5rem; display: block;"></i>
        <p id="customConfirmMessage" class="mb-4 text-dark" style="font-size: 1.05rem; word-break: keep-all;"></p>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-light flex-fill rounded-pill py-2" data-bs-dismiss="modal">취소</button>
            <button type="button" id="customConfirmOkBtn" class="btn btn-danger flex-fill rounded-pill py-2">삭제하기</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../user/js/sync_engine.js"></script>
<script>
let activeItemId = null;
let activePhotoIndex = 1;
const cameraInput = document.getElementById('cameraInput');
let viewerModal = null;
let alertModalObj = null;
let confirmModalObj = null;

// 커스텀 Alert/Confirm 함수
function showAlert(message, onClose) {
    document.getElementById('customAlertMessage').innerHTML = message.replace(/\n/g, '<br>');
    if (!alertModalObj) alertModalObj = new bootstrap.Modal(document.getElementById('customAlertModal'));
    
    if (typeof onClose === 'function') {
        const modalEl = document.getElementById('customAlertModal');
        modalEl.addEventListener('hidden.bs.modal', function handler() {
            modalEl.removeEventListener('hidden.bs.modal', handler);
            onClose();
        });
    }
    
    alertModalObj.show();
}

function showConfirm(message, onConfirm) {
    document.getElementById('customConfirmMessage').innerHTML = message.replace(/\n/g, '<br>');
    if (!confirmModalObj) confirmModalObj = new bootstrap.Modal(document.getElementById('customConfirmModal'));
    
    const okBtn = document.getElementById('customConfirmOkBtn');
    const newOkBtn = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOkBtn, okBtn);
    
    newOkBtn.addEventListener('click', function() {
        confirmModalObj.hide();
        if (typeof onConfirm === 'function') onConfirm();
    });
    
    confirmModalObj.show();
}

// 1. 입력 호출 전 동시성 체크 (선점 방지)
async function checkItemStatusAndProceed(itemId, photoIndex, proceedCallback) {
    try {
        const formData = new FormData();
        formData.append('item_id', itemId);
        formData.append('platform_id', '<?php echo $platform_id; ?>');
        formData.append('photo_index', photoIndex);
        
        const response = await fetch('../user/check_item_status.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success && result.is_filled && !result.is_owner) {
            showAlert(result.message + "\n최신 상태로 화면을 갱신합니다.", () => {
                location.reload();
            });
        } else {
            proceedCallback();
        }
    } catch (e) {
        proceedCallback(); 
    }
}

function startCapture(itemId, photoIndex = 1) {
    checkItemStatusAndProceed(itemId, photoIndex, () => {
        activeItemId = itemId;
        activePhotoIndex = photoIndex;
        cameraInput.click();
    });
}

// 2. 촬영 및 업로드 로직
async function handlePhotoUpload(e) {
    const file = e.target.files[0];
    if (!file || !activeItemId) return;

    const itemId = activeItemId;
    const photoIndex = activePhotoIndex;
    
    try {
        const optimizedBlob = await optimizeImage(file);
        
        const formData = new FormData();
        formData.append('photo', optimizedBlob, 'capture.jpg');
        formData.append('item_id', itemId);
        formData.append('platform_id', '<?php echo $platform_id; ?>');
        formData.append('photo_index', photoIndex);

        const response = await fetch('../user/api_upload_router.php', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            location.reload();
        } else {
            throw new Error(result.message);
        }
    } catch (err) {
        showAlert('전송 오류: ' + err.message + '\n다시 시도해 주세요.');
        location.reload();
    } finally {
        e.target.value = '';
        activeItemId = null;
    }
}

cameraInput.onchange = handlePhotoUpload;

// 3. 라이트박스 뷰어 및 삭제 로직
let currentViewerItemId = null;
let currentViewerPhotoIndex = 1;

function openImageViewer(url, itemId, isOwner, photoIndex = 1) {
    const lb = document.getElementById('customLightbox');
    const img = document.getElementById('lightboxImage');
    const delBtn = document.getElementById('deletePhotoBtn');
    
    img.src = url;
    currentViewerItemId = itemId;
    currentViewerPhotoIndex = photoIndex;
    
    if (isOwner) {
        delBtn.style.display = 'block';
    } else {
        delBtn.style.display = 'none';
    }
    
    lb.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    const lb = document.getElementById('customLightbox');
    lb.style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('deletePhotoBtn').addEventListener('click', function() {
    if (!currentViewerItemId) return;
    const btn = this;
    
    // 삭제 모달이 뒤로 숨는 문제 해결: 라이트박스를 먼저 닫음
    closeLightbox();
    
    showConfirm("정말 사진을 삭제하시겠습니까?", async function() {
        btn.disabled = true;
        
        try {
            const formData = new FormData();
            formData.append('item_id', currentViewerItemId);
            formData.append('platform_id', '<?php echo $platform_id; ?>');
            formData.append('photo_index', currentViewerPhotoIndex);

            const response = await fetch('../user/delete_photo_proc.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                location.reload();
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            showAlert('삭제 오류: ' + error.message);
            btn.disabled = false;
        }
    });
});
</script>
</body>
</html>
