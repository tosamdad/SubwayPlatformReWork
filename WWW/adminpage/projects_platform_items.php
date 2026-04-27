<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    header('Location: ./index.php');
    exit;
}

$platform_id = $_GET['id'] ?? '';
if (!$platform_id) die('잘못된 접근입니다.');

// 승강장 정보 및 소속 정보 가져오기 (권한 체크용 admin_id 포함)
$stmt = $pdo->prepare("
    SELECT p.*, s.site_name, c.const_name, c.admin_id 
    FROM platforms p 
    JOIN sites s ON p.site_id = s.site_id 
    JOIN constructions c ON s.const_id = c.const_id 
    WHERE p.platform_id = ?
");
$stmt->execute([$platform_id]);
$platform = $stmt->fetch();

if (!$platform) die('존재하지 않는 승강장입니다.');

// 권한 체크: 일반 Admin은 본인 소유의 현장만 설정 가능
if ($role == 'Admin' && $platform['admin_id'] !== $user_id) {
    die('접근 권한이 없는 현장입니다.');
}

// 해당 현장 담당 관리자의 마스터 아이템 목록만 가져오기 (admin_id 필터링)
$items_stmt = $pdo->prepare("SELECT * FROM items WHERE is_visible_mobile = 1 AND admin_id = ? ORDER BY role_type, sort_order");
$items_stmt->execute([$platform['admin_id']]);
$all_items = $items_stmt->fetchAll();

// 현재 제외된 항목들 가져오기
$ex_stmt = $pdo->prepare("SELECT item_id FROM platform_excluded_items WHERE platform_id = ?");
$ex_stmt->execute([$platform_id]);
$excluded_ids = $ex_stmt->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL Admin - 승강장별 항목 설정</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        .admin-sidebar { background: #ffffff; border-right: 1px solid #e2e8f0; min-height: 100vh; padding: 2rem 1.5rem; }
        .admin-content { padding: 2.5rem; }
        .section-card { background: white; border-radius: 1rem; padding: 2rem; border: 1px solid #e2e8f0; }
        .item-row { padding: 0.75rem 1rem; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; }
        .item-row:hover { background: #f8fafc; }
        .excluded { background: #fee2e2 !important; color: #991b1b; }
        .badge-worker { background-color: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .badge-safety { background-color: #fefce8; color: #a16207; border: 1px solid #fef08a; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <?php include_once 'inc/sidebar.php'; ?>

        <div class="col-md-10 admin-content">
            <header class="mb-5">
                <a href="projects.php" class="text-decoration-none small text-muted mb-2 d-inline-block">
                    <i class="bi bi-arrow-left"></i> 공정 목록으로 돌아가기
                </a>
                <h2 class="fw-bold text-dark">승강장별 사진 항목 개별 설정</h2>
                <div class="text-muted">
                    <span class="badge bg-secondary me-1"><?php echo h($platform['const_name']); ?></span>
                    <span class="badge bg-secondary me-1"><?php echo h($platform['site_name']); ?></span>
                    <span class="fw-bold text-primary"><?php echo h($platform['platform_name']); ?></span>
                </div>
            </header>

            <div class="alert alert-info shadow-sm mb-4">
                <i class="bi bi-info-circle-fill me-2"></i> 
                기본 사진 포맷 중 이 승강장에서 <strong>제외할 항목</strong>을 선택해 주세요. 체크된 항목은 모바일 작업 리스트에 나타나지 않습니다.
            </div>

            <form action="project_proc.php" method="POST">
                <input type="hidden" name="mode" value="update_excluded_items">
                <input type="hidden" name="platform_id" value="<?php echo $platform_id; ?>">

                <div class="section-card shadow-sm">
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 60px;" class="text-center">제외</th>
                                    <th style="width: 120px;">권한</th>
                                    <th style="width: 150px;">카테고리</th>
                                    <th>항목 명칭</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_items as $item): 
                                    $is_excluded = in_array($item['item_id'], $excluded_ids);
                                ?>
                                <tr class="item-row <?php echo $is_excluded ? 'excluded' : ''; ?>">
                                    <td class="text-center">
                                        <input type="checkbox" name="exclude_ids[]" value="<?php echo $item['item_id']; ?>" 
                                               class="form-check-input" <?php echo $is_excluded ? 'checked' : ''; ?>>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill <?php echo $item['role_type'] == 'Safety' ? 'badge-safety' : 'badge-worker'; ?>">
                                            <?php echo $item['role_type'] == 'Safety' ? '안전관리' : '작업자'; ?>
                                        </span>
                                    </td>
                                    <td class="text-muted small"><?php echo h($item['category_name']); ?></td>
                                    <td class="fw-semibold"><?php echo h($item['item_name']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($all_items)): ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">등록된 마스터 항목이 없습니다. <br><strong>[공정사진 포맷]</strong> 메뉴에서 먼저 리스트를 생성해주세요.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 text-end pt-3 border-top">
                        <button type="submit" class="btn btn-primary px-5 py-2 fw-bold rounded-pill">설정 저장하기</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 클릭 시 로우 배경색 토글 등 시각적 효과 추가 가능
document.querySelectorAll('input[type="checkbox"]').forEach(ck => {
    ck.addEventListener('change', function() {
        const tr = this.closest('tr');
        if (this.checked) tr.classList.add('excluded');
        else tr.classList.remove('excluded');
    });
});
</script>
</body>
</html>
