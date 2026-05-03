<?php
require_once '../inc/db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$role_type = $_SESSION['role_type'] ?? 'Worker';
$parent_admin_id = $_SESSION['parent_admin_id'] ?? '';

// 1. 최근 작업 승강장 조회 (가장 최근에 사진을 올린 곳)
$recent_platform = null;
try {
    $stmt_recent = $pdo->prepare("
        SELECT p.platform_id, p.platform_name, s.site_name, c.const_name
        FROM photo_logs pl
        JOIN platforms p ON pl.platform_id = p.platform_id
        JOIN sites s ON p.site_id = s.site_id
        JOIN constructions c ON s.const_id = c.const_id
        WHERE pl.user_id = ?
        ORDER BY pl.timestamp DESC LIMIT 1
    ");
    $stmt_recent->execute([$user_id]);
    $recent_platform = $stmt_recent->fetch();
} catch (Exception $e) {}

// 2. 관리자 키 기반 데이터 트리 구성 (공사 > 역사 > 승강장)
$data_tree = [];
try {
    // 본인 관리자의 공사 목록
    $stmt_const = $pdo->prepare("SELECT * FROM constructions WHERE admin_id = ? ORDER BY created_at DESC");
    $stmt_const->execute([$parent_admin_id]);
    $consts = $stmt_const->fetchAll();

    foreach ($consts as $c) {
        $sites_data = [];
        $stmt_sites = $pdo->prepare("SELECT * FROM sites WHERE const_id = ? ORDER BY site_id ASC");
        $stmt_sites->execute([$c['const_id']]);
        $sites = $stmt_sites->fetchAll();

        foreach ($sites as $s) {
            $plats_data = [];
            $stmt_plats = $pdo->prepare("SELECT * FROM platforms WHERE site_id = ? ORDER BY platform_id ASC");
            $stmt_plats->execute([$s['site_id']]);
            $plats = $stmt_plats->fetchAll();

            foreach ($plats as $p) {
                // 권한별 공정율 계산
                // 전체 사진 개수 (마스터 - 제외 + 현장 전용)
                $stmt_total = $pdo->prepare("
                    SELECT SUM(photo_count) FROM items 
                    WHERE role_type = ? AND is_visible_mobile = 1
                    AND (
                        (platform_id IS NULL AND admin_id = ? AND item_id NOT IN (SELECT item_id FROM platform_excluded_items WHERE platform_id = ?))
                        OR platform_id = ?
                    )
                ");
                $stmt_total->execute([$role_type, $parent_admin_id, $p['platform_id'], $p['platform_id']]);
                $total_cnt = (int)$stmt_total->fetchColumn();

                // 완료된 사진 개수
                $stmt_done = $pdo->prepare("
                    SELECT COUNT(*) FROM photo_logs pl
                    JOIN items i ON pl.item_id = i.item_id
                    WHERE pl.platform_id = ? AND i.role_type = ?
                    AND (
                        (i.platform_id IS NULL AND i.admin_id = ? AND i.item_id NOT IN (SELECT item_id FROM platform_excluded_items WHERE platform_id = ?))
                        OR i.platform_id = ?
                    )
                ");
                $stmt_done->execute([$p['platform_id'], $role_type, $parent_admin_id, $p['platform_id'], $p['platform_id']]);
                $done_cnt = (int)$stmt_done->fetchColumn();

                $progress = ($total_cnt > 0) ? round(($done_cnt / $total_cnt) * 100) : 0;

                $plats_data[] = array_merge($p, [
                    'total' => $total_cnt,
                    'done' => $done_cnt,
                    'progress' => $progress
                ]);
            }
            $sites_data[] = array_merge($s, ['platforms' => $plats_data]);
        }
        $data_tree[] = array_merge($c, ['sites' => $sites_data]);
    }
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>공정사진 - 메인</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --korail-blue: #00529b; --korail-light: #f1f5f9; }
        body { background-color: #f8fafc; font-family: 'Pretendard', sans-serif; }
        
        .recent-btn { 
            background: #2563eb; color: white; border-radius: 0.75rem; padding: 0.85rem 1rem; margin-bottom: 1.5rem; border: none;
            display: flex; align-items: center; justify-content: space-between; text-decoration: none; font-weight: 700;
            box-shadow: 0 4px 12px rgba(37,99,235,0.2);
        }
        .recent-btn:active { transform: scale(0.98); opacity: 0.9; }
        
        .const-group { margin-bottom: 2rem; }
        .const-title { font-size: 1.05rem; font-weight: 800; color: #1e293b; margin-bottom: 1rem; display: flex; align-items: center; gap: 8px; }
        .const-title::before { content: ''; display: block; width: 4px; height: 18px; background: var(--korail-blue); border-radius: 2px; }

        .site-card { background: white; border-radius: 1.25rem; padding: 1.1rem; margin-bottom: 1rem; border: 1px solid #e2e8f0; }
        .site-name { font-size: 0.9rem; font-weight: 700; color: #334155; margin-bottom: 0.8rem; }
        
        .plat-grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
        .plat-btn { 
            background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 0.85rem; padding: 0.85rem 1rem; text-decoration: none; color: inherit;
            display: flex; flex-direction: column; transition: all 0.2s;
        }
        .plat-btn:active { background: #f1f5f9; transform: scale(0.98); }
        .plat-name { font-weight: 700; font-size: 0.95rem; margin-bottom: 4px; color: #1e293b; }
        
        .progress-mini { height: 5px; background: #e2e8f0; border-radius: 10px; overflow: hidden; margin-top: 6px; }
        .progress-mini-bar { height: 100%; background: var(--korail-blue); border-radius: 10px; }
        .prog-text { font-size: 0.7rem; font-weight: 600; color: #64748b; margin-top: 2px; }
    </style>
</head>
<body>

<?php 
$platform_name = "공정사진";
include_once 'inc/nav.php'; 
?>

<div class="container px-3 mt-3">
    
    <!-- 마지막 작업 승강장 이동 버튼 (공사명 위에 배치) -->
    <?php if ($recent_platform): ?>
    <a href="work_list.php?platform_id=<?php echo $recent_platform['platform_id']; ?>" class="recent-btn">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-clock-history"></i>
            <span>마지막 작업 승강장 이동 (<?php echo h($recent_platform['platform_name']); ?>)</span>
        </div>
        <i class="bi bi-chevron-right small"></i>
    </a>
    <?php endif; ?>

    <!-- 공사 트리 구조 리스트 -->
    <?php if (empty($data_tree)): ?>
        <div class="text-center py-5">
            <i class="bi bi-folder-x fs-1 text-muted opacity-25"></i>
            <p class="text-muted mt-3">할당된 공사 정보가 없습니다.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($data_tree as $const): ?>
        <div class="const-group">
            <div class="const-title"><?php echo h($const['const_name']); ?></div>
            
            <?php foreach ($const['sites'] as $site): ?>
                <div class="site-card shadow-sm">
                    <div class="site-name">
                        <i class="bi bi-geo-alt-fill text-danger me-1"></i> <?php echo h($site['site_name']); ?>
                    </div>
                    
                    <div class="plat-grid">
                        <?php foreach ($site['platforms'] as $plat): ?>
                            <a href="work_list.php?platform_id=<?php echo $plat['platform_id']; ?>" class="plat-btn shadow-sm">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="plat-name"><?php echo h($plat['platform_name']); ?></span>
                                    <i class="bi bi-chevron-right text-muted small"></i>
                                </div>
                                <div class="mt-2">
                                    <div class="d-flex justify-content-between align-items-end">
                                        <span class="prog-text"><?php echo $plat['done']; ?> / <?php echo $plat['total']; ?> 완료</span>
                                        <span class="prog-text text-primary"><?php echo $plat['progress']; ?>%</span>
                                    </div>
                                    <div class="progress-mini">
                                        <div class="progress-mini-bar" style="width: <?php echo $plat['progress']; ?>%"></div>
                                    </div>
                                </div>
                            </a>
                        <?php foreach ($plat as $item) {} // dummy for loop for safety ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

</div>


<!-- Bootstrap JS (메뉴 동작 필수) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
