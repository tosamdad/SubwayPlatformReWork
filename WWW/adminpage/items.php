<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    header('Location: ./index.php');
    exit;
}

$user_name = $_SESSION['user_name'];

// 권한 필터링 (Safety | Worker)
$role_filter = $_GET['role'] ?? 'Safety';

try {
    // 모든 관리자(SuperAdmin 포함)는 본인의 admin_id에 귀속된 항목만 조회함
    $stmt = $pdo->prepare("SELECT * FROM items WHERE role_type = ? AND admin_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$role_filter, $user_id]);
    $items = $stmt->fetchAll();

    // 버튼 노출 여부 결정용: 현재 관리자가 보유한 전체 항목 개수 체크
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM items WHERE admin_id = ?");
    $stmt_count->execute([$user_id]);
    $total_item_count = (int)$stmt_count->fetchColumn();
} catch (Exception $e) { 
    $items = []; 
    $total_item_count = 0;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL Admin - 공정사진포맷</title>
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
        .brand-text { font-weight: 800; letter-spacing: -0.025em; color: #0f172a; }
        
        /* 이동 버튼 스타일 */
        .btn-order { padding: 0.25rem 0.5rem; }
        @media (max-width: 768px) {
            .btn-order { padding: 0.5rem 0.75rem; font-size: 1.1rem; }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <?php include_once 'inc/sidebar.php'; ?>

        <div class="col-md-10 admin-content">
            <header class="d-flex justify-content-between align-items-center mb-5">
                <div>
                    <h2 class="fw-bold text-dark mb-1">공정사진포맷 관리</h2>
                    <p class="text-muted small mb-0">안전관리 및 작업자가 촬영할 사진 항목 마스터를 관리합니다.</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($total_item_count === 0): ?>
                    <button type="button" class="btn btn-outline-primary rounded-pill px-4" onclick="openImportModal()">
                        <i class="bi bi-download me-2"></i> 다른 공정 포맷 가져오기
                    </button>
                    <?php endif; ?>
                    <a href="item_form.php?role=<?php echo $role_filter; ?>" class="btn btn-primary rounded-pill px-4">
                        <i class="bi bi-plus-circle me-2"></i> 신규 항목 추가
                    </a>
                </div>
            </header>

            <div class="section-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="btn-group rounded-pill overflow-hidden border" role="group">
                        <a href="?role=Safety" class="btn <?php echo $role_filter == 'Safety' ? 'btn-primary' : 'btn-light'; ?> px-4">안전관리 (Safety)</a>
                        <a href="?role=Worker" class="btn <?php echo $role_filter == 'Worker' ? 'btn-primary' : 'btn-light'; ?> px-4">작업자 (Worker)</a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center" style="width: 100px;">순서</th>
                                <th style="width: 100px;">코드</th>
                                <th style="width: 150px;">카테고리</th>
                                <th>촬영 항목명</th>
                                <th class="text-center" style="width: 80px;">사진수</th>
                                <th class="text-center" style="width: 120px;">모바일 노출</th>
                                <th class="text-end pe-3" style="width: 250px;">관리</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $idx => $it): ?>
                            <tr>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-secondary btn-order" onclick="moveItem('<?php echo $it['item_id']; ?>', 'up')" <?php echo $idx == 0 ? 'disabled' : ''; ?>>
                                            <i class="bi bi-chevron-up"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-order" onclick="moveItem('<?php echo $it['item_id']; ?>', 'down')" <?php echo $idx == count($items) - 1 ? 'disabled' : ''; ?>>
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </div>
                                </td>
                                <td><code class="text-primary fw-bold"><?php echo h($it['item_code']); ?></code></td>
                                <td><span class="badge bg-secondary-subtle text-secondary border rounded-pill"><?php echo h($it['category_name']); ?></span></td>
                                <td class="fw-bold text-dark"><?php echo h($it['item_name']); ?></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo (int)$it['photo_count']; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm <?php echo $it['is_visible_mobile'] ? 'btn-success' : 'btn-outline-danger'; ?> rounded-pill px-3" 
                                            onclick="toggleVisibility('<?php echo $it['item_id']; ?>', <?php echo $it['is_visible_mobile'] ? 0 : 1; ?>)">
                                        <?php echo $it['is_visible_mobile'] ? '활성' : '숨김'; ?>
                                    </button>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="btn-group btn-group-sm">
                                        <a href="item_form.php?id=<?php echo $it['item_id']; ?>" class="btn btn-outline-primary">
                                            <i class="bi bi-pencil-square"></i> 수정
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" onclick="deleteItem('<?php echo $it['item_id']; ?>')">
                                            <i class="bi bi-trash"></i> 삭제
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($items)): ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted">등록된 촬영 항목이 없습니다.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1.5rem;">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold">다른 공정 포맷 가져오기</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div id="importMainView">
                    <p class="text-muted small mb-4">
                        다른 관리자가 이미 구성해둔 사진 포맷 세트를 내 계정으로 일괄 복사해옵니다.<br>
                        <strong>가져온 후에는 현장에 맞춰 순서 변경 및 항목 수정을 자유롭게 하실 수 있습니다.</strong>
                    </p>
                    
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-hover align-middle border-top">
                            <thead class="table-light">
                                <tr>
                                    <th>포맷 명칭</th>
                                    <th class="text-center">안전관리</th>
                                    <th class="text-center">작업자</th>
                                    <th class="text-end">관리</th>
                                </tr>
                            </thead>
                            <tbody id="importListBody">
                                <!-- 로딩 중... -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="importDetailView" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="showImportMain()">
                            <i class="bi bi-arrow-left"></i> 목록으로
                        </button>
                        <h6 class="fw-bold mb-0" id="previewTitle">포맷 미리보기</h6>
                    </div>
                    <div class="border rounded p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
                        <div id="previewContent">
                            <!-- 상세 항목 리스트 -->
                        </div>
                    </div>
                    <div class="mt-4 text-center">
                        <button type="button" id="btnConfirmImport" class="btn btn-primary rounded-pill px-5 py-2 fw-bold">이 포맷 가져오기</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let importModal;
document.addEventListener('DOMContentLoaded', () => {
    importModal = new bootstrap.Modal(document.getElementById('importModal'));
});

async function openImportModal() {
    showImportMain();
    const tbody = document.getElementById('importListBody');
    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4"><div class="spinner-border text-primary spinner-border-sm me-2"></div>로딩 중...</td></tr>';
    importModal.show();

    try {
        const res = await fetch('item_api.php?mode=get_other_formats');
        const data = await res.json();
        
        tbody.innerHTML = '';
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">가져올 수 있는 다른 포맷이 없습니다.</td></tr>';
            return;
        }

        data.forEach((fmt, index) => {
            const formatTitle = `기본 포맷 세트 ${String(index + 1).padStart(2, '0')}`;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="fw-bold">${formatTitle}</td>
                <td class="text-center"><span class="badge bg-light text-primary border">${fmt.safety_count}개</span></td>
                <td class="text-center"><span class="badge bg-light text-success border">${fmt.worker_count}개</span></td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="previewFormat('${fmt.admin_id}', '${formatTitle}')">미리보기</button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-danger">데이터를 불러오는 중 오류가 발생했습니다.</td></tr>';
    }
}

async function previewFormat(adminId, title) {
    const content = document.getElementById('previewContent');
    document.getElementById('previewTitle').innerText = title;
    content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary spinner-border-sm me-2"></div>상세 정보를 불러오는 중...</div>';
    
    document.getElementById('importMainView').style.display = 'none';
    document.getElementById('importDetailView').style.display = 'block';

    try {
        const res = await fetch(`item_api.php?mode=get_format_details&admin_id=${adminId}`);
        const data = await res.json();
        
        if (data.length === 0) {
            content.innerHTML = '<div class="text-center py-5 text-muted">항목이 없습니다.</div>';
            return;
        }

        const safetyItems = data.filter(i => i.role_type === 'Safety');
        const workerItems = data.filter(i => i.role_type === 'Worker');

        const renderItems = (items) => {
            if (items.length === 0) return '<div class="text-muted small py-2">등록된 항목 없음</div>';
            return items.map((item, idx) => `
                <div class="d-flex py-1 border-bottom-dotted border-bottom align-items-center">
                    <span class="text-muted small me-2" style="width: 20px;">${idx + 1}.</span>
                    <span class="small text-muted me-2" style="min-width: 70px;">[${item.category_name}]</span>
                    <span class="small fw-semibold flex-grow-1" style="font-size: 0.75rem;">${item.item_name}</span>
                    <span class="badge bg-light text-dark border ms-2" style="font-size: 0.65rem;">${item.photo_count}장</span>
                </div>
            `).join('');
        };

        content.innerHTML = `
            <div class="row g-3">
                <div class="col-md-6 border-end">
                    <div class="fw-bold mb-2 pb-1 border-bottom text-primary small">
                        <i class="bi bi-shield-check me-1"></i>안전관리 항목 <span class="badge bg-primary-subtle text-primary ms-1">${safetyItems.length}</span>
                    </div>
                    ${renderItems(safetyItems)}
                </div>
                <div class="col-md-6 ps-md-3">
                    <div class="fw-bold mb-2 pb-1 border-bottom text-success small">
                        <i class="bi bi-person-gear me-1"></i>작업자 항목 <span class="badge bg-success-subtle text-success ms-1">${workerItems.length}</span>
                    </div>
                    ${renderItems(workerItems)}
                </div>
            </div>
        `;
        
        document.getElementById('btnConfirmImport').onclick = () => importFormat(adminId);
    } catch (e) {
        content.innerHTML = '<div class="text-center py-5 text-danger">정보를 불러오지 못했습니다.</div>';
    }
}

function showImportMain() {
    document.getElementById('importMainView').style.display = 'block';
    document.getElementById('importDetailView').style.display = 'none';
}

async function importFormat(sourceAdminId) {
    showConfirm('선택한 포맷의 모든 항목을 내 리스트로 복사해오시겠습니까?\n가져온 후에는 항목 명칭이나 순서를 자유롭게 수정하실 수 있습니다.', () => {
        location.href = `item_proc.php?mode=import_format&source_admin_id=${sourceAdminId}&role=<?php echo $role_filter; ?>`;
    });
}

function toggleVisibility(id, status) {
    location.href = `item_proc.php?mode=toggle&id=${id}&status=${status}&role=<?php echo $role_filter; ?>`;
}

function deleteItem(id) {
    showConfirm('해당 항목을 정말 삭제하시겠습니까?\n이미 촬영된 사진 기록이 있는 경우 삭제가 거부될 수 있습니다.', () => {
        location.href = `item_proc.php?mode=delete&id=${id}&role=<?php echo $role_filter; ?>`;
    });
}

function moveItem(id, direction) {
    location.href = `item_proc.php?mode=move_${direction}&id=${id}&role=<?php echo $role_filter; ?>`;
}
</script>
</body>
</html>
