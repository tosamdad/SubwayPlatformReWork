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
$role_filter = $_GET['role'] ?? 'All'; // All | Safety | Worker
if (!$platform_id) die('잘못된 접근입니다.');

// 승강장 정보 및 소속 정보 가져오기
$stmt = $pdo->prepare("
    SELECT p.*, s.site_name, s.site_id, c.const_name, c.admin_id 
    FROM platforms p 
    JOIN sites s ON p.site_id = s.site_id 
    JOIN constructions c ON s.const_id = c.const_id 
    WHERE p.platform_id = ?
");
$stmt->execute([$platform_id]);
$platform = $stmt->fetch();

if (!$platform) die('존재하지 않는 승강장입니다.');

if ($role == 'Admin' && $platform['admin_id'] !== $user_id) {
    die('접근 권한이 없는 현장입니다.');
}

// 해당 현장 담당 관리자의 마스터 아이템 목록 가져오기
$role_sql = ($role_filter === 'All') ? "" : " AND role_type = " . $pdo->quote($role_filter);
$items_stmt = $pdo->prepare("SELECT * FROM items WHERE is_visible_mobile = 1 AND admin_id = ? $role_sql ORDER BY role_type, sort_order");
$items_stmt->execute([$platform['admin_id']]);
$all_items = $items_stmt->fetchAll();

// 현재 제외된 항목들 가져오기
$ex_stmt = $pdo->prepare("SELECT item_id FROM platform_excluded_items WHERE platform_id = ?");
$ex_stmt->execute([$platform_id]);
$excluded_ids = $ex_stmt->fetchAll(PDO::FETCH_COLUMN);

// 각 항목별 사진 업로드 여부 확인 (제외 체크 시 경고용)
$photo_counts = [];
$stmt_pcheck = $pdo->prepare("SELECT item_id, COUNT(*) as cnt FROM photo_logs WHERE platform_id = ? GROUP BY item_id");
$stmt_pcheck->execute([$platform_id]);
while($row = $stmt_pcheck->fetch()) {
    $photo_counts[$row['item_id']] = $row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL Admin - 항목 설정</title>
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
                    <a href="site_view.php?site_id=<?php echo $platform['site_id']; ?>&platform_id=<?php echo $platform_id; ?>" class="text-decoration-none small text-muted mb-2 d-inline-block">
                        <i class="bi bi-arrow-left"></i> 현장 모니터링으로 돌아가기
                    </a>
                    <h2 class="fw-bold text-dark">공정 항목 설정 (<?php echo h($platform['platform_name']); ?>)</h2>
                    <div class="text-muted">
                        <span class="badge bg-secondary me-1"><?php echo h($platform['const_name']); ?></span>
                        <span class="badge bg-secondary me-1"><?php echo h($platform['site_name']); ?></span>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary rounded-pill shadow-sm fw-bold px-4" onclick="openAddModal()">
                        <i class="bi bi-plus-circle me-1"></i> 새 항목 추가
                    </button>
                    <div class="filter-tabs btn-group p-1 bg-white border rounded-pill shadow-sm">
                        <a href="?id=<?php echo $platform_id; ?>&role=All" class="btn <?php echo $role_filter == 'All' ? 'btn-primary' : 'btn-light'; ?> rounded-pill">전체</a>
                        <a href="?id=<?php echo $platform_id; ?>&role=Safety" class="btn <?php echo $role_filter == 'Safety' ? 'btn-warning text-white' : 'btn-light'; ?> rounded-pill">안전</a>
                        <a href="?id=<?php echo $platform_id; ?>&role=Worker" class="btn <?php echo $role_filter == 'Worker' ? 'btn-success' : 'btn-light'; ?> rounded-pill">작업자</a>
                    </div>
                </div>
            </header>

            <form action="project_proc.php" method="POST" onsubmit="return confirmSave()">
                <input type="hidden" name="mode" value="update_excluded_items">
                <input type="hidden" name="platform_id" value="<?php echo $platform_id; ?>">
                <input type="hidden" name="role_filter" value="<?php echo $role_filter; ?>">

                <div class="section-card shadow-sm">
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 60px;" class="text-center">제외</th>
                                    <th style="width: 100px;">권한</th>
                                    <th style="width: 120px;">카테고리</th>
                                    <th>항목 명칭</th>
                                    <th style="width: 80px;" class="text-center">사진수</th>
                                    <th style="width: 150px;" class="text-center">순서변경</th>
                                    <th style="width: 100px;" class="text-center">관리</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_items as $item): 
                                    $is_excluded = in_array($item['item_id'], $excluded_ids);
                                    $has_photos = ($photo_counts[$item['item_id']] ?? 0) > 0;
                                ?>
                                <tr class="item-row <?php echo $is_excluded ? 'excluded' : ''; ?>" data-has-photos="<?php echo $has_photos ? '1' : '0'; ?>">
                                    <td class="text-center">
                                        <input type="checkbox" name="exclude_ids[]" value="<?php echo $item['item_id']; ?>" 
                                               class="form-check-input exclude-check" <?php echo $is_excluded ? 'checked' : ''; ?>>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill <?php echo $item['role_type'] == 'Safety' ? 'badge-safety' : 'badge-worker'; ?>">
                                            <?php echo $item['role_type'] == 'Safety' ? '안전' : '작업자'; ?>
                                        </span>
                                    </td>
                                    <td class="text-muted small"><?php echo h($item['category_name']); ?></td>
                                    <td class="fw-semibold"><?php echo h($item['item_name']); ?></td>
                                    <td class="text-center small"><?php echo $item['photo_count']; ?>장</td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="item_proc.php?mode=move_up&id=<?php echo $item['item_id']; ?>&role=<?php echo $role_filter; ?>&ref=platform&pid=<?php echo $platform_id; ?>" class="btn btn-outline-secondary"><i class="bi bi-chevron-up"></i></a>
                                            <a href="item_proc.php?mode=move_down&id=<?php echo $item['item_id']; ?>&role=<?php echo $role_filter; ?>&ref=platform&pid=<?php echo $platform_id; ?>" class="btn btn-outline-secondary"><i class="bi bi-chevron-down"></i></a>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-link text-muted p-0 me-2" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($item)); ?>)"><i class="bi bi-pencil-square"></i></button>
                                        <a href="item_proc.php?mode=delete&id=<?php echo $item['item_id']; ?>&role=<?php echo $role_filter; ?>&ref=platform&pid=<?php echo $platform_id; ?>" class="btn btn-sm btn-link text-danger p-0" onclick="return confirm('이 항목을 영구 삭제하시겠습니까? (마스터 목록에서 삭제)')"><i class="bi bi-trash"></i></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($all_items)): ?>
                                    <tr><td colspan="7" class="text-center py-5 text-muted">등록된 항목이 없습니다.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 text-end pt-3 border-top">
                        <button type="submit" class="btn btn-primary px-5 py-2 fw-bold rounded-pill">제외 설정 저장하기</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
            <form action="item_proc.php" method="POST">
                <input type="hidden" name="mode" id="modalMode" value="add">
                <input type="hidden" name="item_id" id="modalItemId" value="">
                <input type="hidden" name="ref" value="platform">
                <input type="hidden" name="pid" value="<?php echo $platform_id; ?>">
                <input type="hidden" name="role_filter" value="<?php echo $role_filter; ?>">

                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="modalTitle">새 항목 추가</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">권한 구분</label>
                        <select name="role_type" id="modalRoleType" class="form-select rounded-3">
                            <option value="Worker">작업자 항목</option>
                            <option value="Safety">안전관리 항목</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">카테고리</label>
                        <input type="text" name="category_name" id="modalCategory" class="form-control rounded-3" placeholder="예: 기초공사, 마감공사">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">항목 명칭 (타이틀)</label>
                        <input type="text" name="item_name" id="modalItemName" class="form-control rounded-3" placeholder="예: 안전모 착용 확인" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold">필요 사진 수</label>
                        <input type="number" name="photo_count" id="modalPhotoCount" class="form-control rounded-3" value="1" min="1" max="10">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">취소</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">저장하기</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const itemModal = new bootstrap.Modal(document.getElementById('itemModal'));

function openAddModal() {
    document.getElementById('modalMode').value = 'add';
    document.getElementById('modalTitle').innerText = '새 항목 추가';
    document.getElementById('modalItemId').value = '';
    document.getElementById('modalCategory').value = '';
    document.getElementById('modalItemName').value = '';
    document.getElementById('modalPhotoCount').value = '1';
    document.getElementById('modalRoleType').value = '<?php echo ($role_filter != "All") ? $role_filter : "Worker"; ?>';
    itemModal.show();
}

function openEditModal(item) {
    document.getElementById('modalMode').value = 'edit';
    document.getElementById('modalTitle').innerText = '항목 수정';
    document.getElementById('modalItemId').value = item.item_id;
    document.getElementById('modalCategory').value = item.category_name;
    document.getElementById('modalItemName').value = item.item_name;
    document.getElementById('modalPhotoCount').value = item.photo_count;
    document.getElementById('modalRoleType').value = item.role_type;
    itemModal.show();
}

function confirmSave() {
    return confirm('제외 설정을 저장하시겠습니까?');
}

// 제외 체크박스 변경 시 사진 유무 확인
document.querySelectorAll('.exclude-check').forEach(ck => {
    ck.addEventListener('change', function() {
        const tr = this.closest('tr');
        const hasPhotos = tr.dataset.hasPhotos === '1';
        
        if (this.checked) {
            if (hasPhotos) {
                if (!confirm('경고: 해당 항목에 이미 촬영된 사진이 존재합니다.\n제외 설정 시 모바일에서 보이지 않으며 관리에 혼선이 생길 수 있습니다.\n계속하시겠습니까?')) {
                    this.checked = false;
                    return;
                }
            }
            tr.classList.add('excluded');
        } else {
            tr.classList.remove('excluded');
        }
    });
});
</script>
</body>
</html>
