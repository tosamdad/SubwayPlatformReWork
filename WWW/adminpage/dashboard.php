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

// 3. 최근 활동 (최근 10개 업로드 사진)
$recent_activities = [];
try {
    $sql_recent = "
        SELECT pl.photo_url, pl.timestamp, pl.user_id, i.item_name, i.role_type, p.platform_name, s.site_name
        FROM photo_logs pl
        JOIN items i ON pl.item_id = i.item_id
        JOIN platforms p ON pl.platform_id = p.platform_id
        JOIN sites s ON p.site_id = s.site_id
        JOIN constructions c ON s.const_id = c.const_id
        WHERE 1=1 $admin_filter
        ORDER BY pl.timestamp DESC
        LIMIT 10
    ";
    $recent_activities = $pdo->query($sql_recent)->fetchAll();
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

            <!-- 3. 최근 활동 -->
            <div class="section-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0">최근 업로드 내역</h5>
                </div>
                
                <?php if (empty($recent_activities)): ?>
                    <div class="text-center py-4 text-muted">아직 업로드된 사진이 없습니다.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>미리보기</th>
                                    <th>현장 / 승강장</th>
                                    <th>항목 명칭</th>
                                    <th>역할</th>
                                    <th>작업자</th>
                                    <th>업로드 시간</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_activities as $act): ?>
                                <tr>
                                    <td>
                                        <img src="../view_photo.php?path=<?php echo urlencode($act['photo_url']); ?>" class="recent-img border" alt="사진">
                                    </td>
                                    <td>
                                        <div class="fw-bold small"><?php echo h($act['site_name']); ?></div>
                                        <div class="text-muted" style="font-size: 0.75rem;"><?php echo h($act['platform_name']); ?></div>
                                    </td>
                                    <td class="small fw-semibold"><?php echo h($act['item_name']); ?></td>
                                    <td>
                                        <?php if ($act['role_type'] == 'Safety'): ?>
                                            <span class="badge bg-warning text-dark opacity-75">안전관리</span>
                                        <?php else: ?>
                                            <span class="badge bg-success opacity-75">작업자</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><i class="bi bi-person me-1"></i><?php echo h($act['user_id']); ?></td>
                                    <td class="small text-muted"><?php echo h($act['timestamp']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
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
