<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';
$parent_admin_id = $_SESSION['parent_admin_id'] ?? '';

if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    header('Location: ./index.php');
    exit;
}

// 필터 조건 설정
$admin_filter = ($role == 'SuperAdmin') ? "" : " AND c.admin_id = " . $pdo->quote($user_id);
$item_admin_filter = ($role == 'SuperAdmin') ? "" : " AND admin_id = " . $pdo->quote($user_id);

// 1. 요약 카드 데이터 (공사/역사/승강장 수)
try {
    $stmt_c = $pdo->query("SELECT COUNT(*) FROM constructions c WHERE 1=1 $admin_filter");
    $const_count = $stmt_c->fetchColumn();

    $stmt_s = $pdo->query("SELECT COUNT(*) FROM sites s JOIN constructions c ON s.const_id = c.const_id WHERE 1=1 $admin_filter");
    $site_count = $stmt_s->fetchColumn();

    $stmt_p = $pdo->query("SELECT COUNT(*) FROM platforms p JOIN sites s ON p.site_id = s.site_id JOIN constructions c ON s.const_id = c.const_id WHERE 1=1 $admin_filter");
    $plat_count = $stmt_p->fetchColumn();
    
    // 전체 항목 수 (기본 템플릿)
    $stmt_i = $pdo->query("SELECT COUNT(*) FROM items WHERE 1=1 $item_admin_filter");
    $item_count = $stmt_i->fetchColumn();

} catch (Exception $e) {
    $const_count = $site_count = $plat_count = $item_count = 0;
}

// 2. 전체 진행률 및 각 역사(Site)별 진행률 계산
$site_progress_data = [];
$total_req_all = 0;
$total_up_all = 0;
$safety_up = 0;
$worker_up = 0;

try {
    // 2-1. 역할별 총 업로드 수 한 번에 조회 (차트용)
    $sql_total_roles = "
        SELECT i.role_type, COUNT(pl.log_id) as cnt 
        FROM photo_logs pl
        JOIN items i ON pl.item_id = i.item_id
        JOIN platforms p ON pl.platform_id = p.platform_id
        JOIN sites s ON p.site_id = s.site_id
        JOIN constructions c ON s.const_id = c.const_id
        WHERE 1=1 $admin_filter
        GROUP BY i.role_type
    ";
    $role_results = $pdo->query($sql_total_roles)->fetchAll();
    foreach ($role_results as $row) {
        if ($row['role_type'] == 'Safety') $safety_up = (int)$row['cnt'];
        if ($row['role_type'] == 'Worker') $worker_up = (int)$row['cnt'];
    }
    $total_up_all = $safety_up + $worker_up;

    // 2-2. 관리자 권한의 모든 역사(Site) 및 승강장 구조 조회
    $sql_structure = "
        SELECT s.site_id, s.site_name, c.const_name, p.platform_id, p.platform_name
        FROM sites s
        JOIN constructions c ON s.const_id = c.const_id
        LEFT JOIN platforms p ON s.site_id = p.site_id
        WHERE 1=1 $admin_filter
        ORDER BY s.site_id ASC, p.platform_id ASC
    ";
    $structure = $pdo->query($sql_structure)->fetchAll();

    // 사이트별/승강장별 집계용
    $site_aggregates = [];
    $platform_progress_data = [];
    $processed_platforms = [];

    foreach ($structure as $row) {
        $sid = $row['site_id'];
        $pid = $row['platform_id'];

        if (!isset($site_aggregates[$sid])) {
            $site_aggregates[$sid] = [
                'name' => $row['site_name'] . ' (' . $row['const_name'] . ')',
                'req' => 0,
                'up' => 0
            ];
        }

        if ($pid && !isset($processed_platforms[$pid])) {
            $processed_platforms[$pid] = true;

            // 해당 승강장의 필요 사진 수
            $stmt_req = $pdo->prepare("
                SELECT SUM(photo_count) FROM items 
                WHERE item_id NOT IN (SELECT item_id FROM platform_excluded_items WHERE platform_id = ?)
                $item_admin_filter
            ");
            $stmt_req->execute([$pid]);
            $req = (int)$stmt_req->fetchColumn();

            // 해당 승강장의 업로드 수
            $stmt_up = $pdo->prepare("SELECT COUNT(*) FROM photo_logs WHERE platform_id = ?");
            $stmt_up->execute([$pid]);
            $up = (int)$stmt_up->fetchColumn();

            $site_aggregates[$sid]['req'] += $req;
            $site_aggregates[$sid]['up'] += $up;
            $total_req_all += $req;

            // 승강장별 데이터 추가
            $p_prog = $req > 0 ? round(($up / $req) * 100) : 0;
            $platform_progress_data[] = [
                'name' => $row['site_name'] . ' - ' . $row['platform_name'],
                'progress' => $p_prog
            ];
        }
    }

    foreach ($site_aggregates as $sid => $data) {
        $prog = $data['req'] > 0 ? round(($data['up'] / $data['req']) * 100) : 0;
        $site_progress_data[] = [
            'name' => $data['name'],
            'progress' => $prog
        ];
    }

    // 2-3. 통합 데이터 구성 (전체 -> 역사별 -> 승강장별)
    $overall_progress = $total_req_all > 0 ? round(($total_up_all / $total_req_all) * 100) : 0;
    $unified_progress_data = [];
    
    // (1) 전체
    $unified_progress_data[] = ['name' => '[전체공사]', 'progress' => $overall_progress, 'type' => 'total'];
    // (2) 역사별
    foreach ($site_progress_data as $sd) {
        if (strpos($sd['name'], '금촌역') !== false) continue;
        $unified_progress_data[] = array_merge($sd, ['type' => 'site']);
    }
    // (3) 승강장별
    foreach ($platform_progress_data as $pd) {
        $unified_progress_data[] = array_merge($pd, ['type' => 'platform']);
    }

} catch (Exception $e) {}

// 3. 안전 점검 달력 데이터
$view_month = $_GET['month'] ?? date('Y-m');
$year = date('Y', strtotime($view_month));
$month = date('m', strtotime($view_month));

$first_day_of_month = date('w', strtotime("$view_month-01"));
$days_in_month = date('t', strtotime("$view_month-01"));

$safety_calendar_data = [];
try {
    $sql_cal = "
        SELECT DATE(pl.timestamp) as work_date, p.platform_name, s.site_name
        FROM photo_logs pl
        JOIN items i ON pl.item_id = i.item_id
        JOIN platforms p ON pl.platform_id = p.platform_id
        JOIN sites s ON p.site_id = s.site_id
        JOIN constructions c ON s.const_id = c.const_id
        WHERE i.role_type = 'Safety' $admin_filter
        AND DATE_FORMAT(pl.timestamp, '%Y-%m') = " . $pdo->quote($view_month) . "
        GROUP BY work_date, p.platform_id
        ORDER BY work_date ASC, s.site_id ASC, p.platform_id ASC
    ";
    $cal_results = $pdo->query($sql_cal)->fetchAll();
    foreach ($cal_results as $row) {
        $safety_calendar_data[$row['work_date']][] = h($row['site_name']) . ' - ' . h($row['platform_name']);
    }
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL Admin - 통합 대시보드</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        .admin-sidebar { background: #ffffff; border-right: 1px solid #e2e8f0; min-height: 100vh; padding: 2rem 1.5rem; }
        .admin-content { padding: 2.5rem; }
        .section-card { background: white; border-radius: 1rem; padding: 1.75rem; margin-bottom: 2rem; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .nav-link { color: #64748b; margin-bottom: 0.75rem; border-radius: 0.75rem; padding: 0.75rem 1rem; font-weight: 500; transition: all 0.2s; }
        .nav-link:hover { background: #f1f5f9; color: #0f172a; }
        .nav-link.active { background: #eff6ff; color: #2563eb; }
        
        .stat-card {
            border-radius: 1rem;
            padding: 1.5rem;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        .stat-card .icon-bg {
            position: absolute;
            right: -10px;
            bottom: -15px;
            font-size: 5rem;
            opacity: 0.15;
        }
        .stat-card h3 { font-size: 2.5rem; font-weight: 800; margin: 0; }
        .stat-card p { margin: 0; font-weight: 500; opacity: 0.9; }

        .bg-blue { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
        .bg-green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .bg-purple { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
        .bg-orange { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }

        .recent-img { width: 50px; height: 50px; object-fit: cover; border-radius: 0.5rem; }

        /* Calendar Styles */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #e2e8f0;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .calendar-day {
            background: white;
            min-height: 120px;
            padding: 0.75rem;
            position: relative;
            transition: background 0.2s;
        }
        .calendar-day.header {
            min-height: auto;
            background: #f8fafc;
            font-weight: 700;
            text-align: center;
            padding: 0.75rem;
            color: #64748b;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .calendar-day.empty { background: #f8fafc; }
        .calendar-day.today { background: #f0f9ff; }
        .calendar-day .day-num { font-weight: 700; font-size: 0.95rem; margin-bottom: 0.5rem; display: block; color: #475569; }
        .calendar-day.today .day-num { color: #2563eb; }
        
        .safety-badge {
            font-size: 0.72rem;
            padding: 0.25rem 0.6rem;
            border-radius: 0.5rem;
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
            margin-bottom: 4px;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 600;
            cursor: help;
        }
        .safety-badge:hover { background: #fef3c7; }
        
        .popover { border-radius: 1rem; border: none; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .popover-header { background: #f8fafc; border-bottom: 1px solid #f1f5f9; font-weight: 700; border-radius: 1rem 1rem 0 0 !important; }
        .popover-body { padding: 1rem; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <?php include_once 'inc/sidebar.php'; ?>

        <div class="col-md-10 admin-content">
            <header class="mb-4">
                <h2 class="fw-bold text-dark mb-1">통합 대시보드</h2>
                <p class="text-muted small">현장 현황 및 공정 진행률을 한눈에 파악합니다.</p>
            </header>

            <!-- 1. 요약 카드 -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card bg-blue">
                        <i class="bi bi-building icon-bg"></i>
                        <p>관리 중인 공사</p>
                        <h3><?php echo number_format($const_count); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card bg-green">
                        <i class="bi bi-geo-alt icon-bg"></i>
                        <p>관리 중인 역사(현장)</p>
                        <h3><?php echo number_format($site_count); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card bg-purple">
                        <i class="bi bi-camera icon-bg"></i>
                        <p>업로드된 총 사진</p>
                        <h3><?php echo number_format($total_up_all); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card bg-orange">
                        <i class="bi bi-bar-chart-fill icon-bg"></i>
                        <p>전체 누적 공정률</p>
                        <h3><?php echo $overall_progress; ?>%</h3>
                    </div>
                </div>
            </div>

            <!-- 2. 차트 영역 -->
            <div class="row g-4 mb-4">
                <div class="col-md-12">
                    <div class="section-card mb-0">
                        <h5 class="fw-bold mb-4">공사 진행률 (%)</h5>
                        <canvas id="unifiedProgressChart" height="120"></canvas>
                        <div class="mt-3 d-flex gap-3 justify-content-center">
                            <div class="small text-muted"><span class="d-inline-block rounded-circle me-1" style="width:10px;height:10px;background:#2563eb"></span> 전체공사</div>
                            <div class="small text-muted"><span class="d-inline-block rounded-circle me-1" style="width:10px;height:10px;background:#60a5fa"></span> 역사별</div>
                            <div class="small text-muted"><span class="d-inline-block rounded-circle me-1" style="width:10px;height:10px;background:#10b981"></span> 승강장별</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. 안전 점검 일정표 (달력) -->
            <div class="section-card shadow-sm border-0">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="fw-bold mb-1">안전 점검 일정표</h5>
                        <p class="text-muted small mb-0">월간 승강장별 안전 점검 수행 현황입니다.</p>
                    </div>
                    <div class="d-flex align-items-center gap-3 bg-light p-1 rounded-pill px-3 border">
                        <a href="?month=<?php echo date('Y-m', strtotime($view_month . ' -1 month')); ?>" class="btn btn-sm btn-light rounded-circle shadow-sm"><i class="bi bi-chevron-left"></i></a>
                        <span class="fw-bold text-dark px-2" style="min-width: 100px; text-align: center;"><?php echo date('Y년 m월', strtotime($view_month)); ?></span>
                        <a href="?month=<?php echo date('Y-m', strtotime($view_month . ' +1 month')); ?>" class="btn btn-sm btn-light rounded-circle shadow-sm"><i class="bi bi-chevron-right"></i></a>
                        <a href="?month=<?php echo date('Y-m'); ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 ms-2 fw-bold" style="font-size: 0.75rem;">오늘</a>
                    </div>
                </div>
                
                <div class="calendar-grid">
                    <!-- 요일 헤더 -->
                    <div class="calendar-day header text-danger">일</div>
                    <div class="calendar-day header">월</div>
                    <div class="calendar-day header">화</div>
                    <div class="calendar-day header">수</div>
                    <div class="calendar-day header">목</div>
                    <div class="calendar-day header">금</div>
                    <div class="calendar-day header text-primary">토</div>

                    <!-- 빈 칸 (첫 날 전까지) -->
                    <?php for ($i = 0; $i < $first_day_of_month; $i++): ?>
                        <div class="calendar-day empty"></div>
                    <?php endfor; ?>

                    <!-- 날짜 채우기 -->
                    <?php 
                    $today = date('Y-m-d');
                    for ($day = 1; $day <= $days_in_month; $day++): 
                        $current_date = sprintf("%s-%02d", $view_month, $day);
                        $is_today = ($current_date == $today);
                        $platforms = $safety_calendar_data[$current_date] ?? [];
                    ?>
                        <div class="calendar-day <?php echo $is_today ? 'today' : ''; ?>">
                            <span class="day-num"><?php echo $day; ?></span>
                            <?php if (!empty($platforms)): ?>
                                <?php 
                                $count = count($platforms);
                                $display_limit = 2;
                                foreach (array_slice($platforms, 0, $display_limit) as $p_name): ?>
                                    <div class="safety-badge" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?php echo $day; ?>일 안전 점검 목록" data-bs-content="<?php echo implode('<br>', $platforms); ?>" data-bs-html="true">
                                        <i class="bi bi-shield-check me-1"></i><?php echo $p_name; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($count > $display_limit): ?>
                                    <div class="text-muted ps-1" style="font-size: 0.65rem; font-weight: 600;">
                                        외 <?php echo ($count - $display_limit); ?>건 더보기...
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>

                    <!-- 빈 칸 (마지막 날 이후) -->
                    <?php 
                    $last_day_cells = ($first_day_of_month + $days_in_month) % 7;
                    if ($last_day_cells > 0):
                        for ($i = 0; $i < (7 - $last_day_cells); $i++): ?>
                            <div class="calendar-day empty"></div>
                        <?php endfor;
                    endif;
                    ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Tooltip/Popover 활성화
const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
const popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
  return new bootstrap.Popover(popoverTriggerEl)
})

// Chart.js 데이터 준비
const unifiedData = <?php echo json_encode($unified_progress_data ?? []); ?>;
const labels = unifiedData.map(d => d.name);
const progressValues = unifiedData.map(d => d.progress);
const colors = unifiedData.map(d => {
    if (d.type === 'total') return 'rgba(37, 99, 235, 0.9)';    // Blue
    if (d.type === 'site') return 'rgba(96, 165, 250, 0.8)';    // Light Blue
    return 'rgba(16, 185, 129, 0.8)';                          // Green
});

const ctx = document.getElementById('unifiedProgressChart');
if (ctx && labels.length > 0) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: '진행률 (%)',
                data: progressValues,
                backgroundColor: colors,
                borderWidth: 0,
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: { callback: value => value + '%' }
                },
                x: {
                    ticks: {
                        font: { size: 11 },
                        maxRotation: 45,
                        minRotation: 0
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '진행률: ' + context.parsed.y + '%';
                        }
                    }
                }
            }
        }
    });
} else if(ctx) {
    ctx.parentElement.innerHTML = '<div class="text-center text-muted py-5">데이터가 없습니다.</div>';
}
</script>
</body>
</html>
