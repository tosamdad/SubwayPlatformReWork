<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    header('Location: ./index.php');
    exit;
}

// 공사 목록 가져오기 (권한 필터링)
try {
    $const_sql = ($role == 'SuperAdmin') ? "SELECT * FROM constructions ORDER BY const_id DESC" : "SELECT * FROM constructions WHERE admin_id = ? ORDER BY const_id DESC";
    $stmt = $pdo->prepare($const_sql);
    if ($role != 'SuperAdmin') $stmt->execute([$user_id]);
    else $stmt->execute();
    $constructions = $stmt->fetchAll();

    $sites_stmt = $pdo->query("SELECT * FROM sites ORDER BY site_id");
    $all_sites = $sites_stmt->fetchAll();

    $platforms_stmt = $pdo->query("SELECT * FROM platforms ORDER BY platform_id");
    $all_platforms = $platforms_stmt->fetchAll();

    // SuperAdmin용 관리자 목록 (공사 등록 시 선택용)
    $admins = [];
    if ($role == 'SuperAdmin') {
        $admins = $pdo->query("SELECT member_id, name FROM members WHERE role_type = 'Admin' AND is_active = 1 ORDER BY name ASC")->fetchAll();
    }
} catch (Exception $e) { 
    $constructions = []; $all_sites = []; $all_platforms = []; 
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL Admin - 공정 생성 관리</title>
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
        .project-hierarchy { list-style: none; padding-left: 0; }
        .const-item { border-left: 4px solid #2563eb; background: #f8fafc; border-radius: 0.5rem; padding: 1.25rem; margin-bottom: 1.5rem; }
        .site-item { padding: 0.75rem 1rem; background: white; border: 1px solid #e2e8f0; border-radius: 0.5rem; margin-top: 0.75rem; margin-left: 2rem; }
        .platform-item { padding: 0.5rem 0.75rem; background: #ffffff; border-bottom: 1px dashed #e2e8f0; margin-left: 4rem; display: flex; justify-content: space-between; align-items: center; }
        .platform-item:last-child { border-bottom: none; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <?php include_once 'inc/sidebar.php'; ?>

        <div class="col-md-10 admin-content">
            <header class="d-flex justify-content-between align-items-center mb-5">
                <div>
                    <h2 class="fw-bold text-dark mb-1">공정 생성 및 관리</h2>
                    <p class="text-muted small mb-0">공사 > 현장 > 승강장으로 이어지는 공정 계층을 설계합니다.</p>
                </div>
                <?php if ($role == 'SuperAdmin'): ?>
                <button onclick="openConstModal()" class="btn btn-primary rounded-pill px-4 shadow-sm">
                    <i class="bi bi-building-add me-2"></i> 신규 공사 등록
                </button>
                <?php endif; ?>
            </header>

            <div class="section-card">
                <div class="project-hierarchy">
                    <?php foreach ($constructions as $const): ?>
                        <div class="const-item border shadow-sm">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="fw-bold mb-0 text-primary">
                                    <i class="bi bi-building me-2"></i> 
                                    <small class="badge bg-primary me-2"><?php echo h($const['const_code']); ?></small>
                                    <?php echo h($const['const_name']); ?>
                                    <?php if ($role == 'SuperAdmin'): ?>
                                        <small class="text-muted ms-2 fw-normal" style="font-size: 0.7rem;">(담당: <?php echo h($const['admin_id'] ?? '미지정'); ?>)</small>
                                    <?php endif; ?>
                                </h4>
                                <div class="btn-group btn-group-sm">
                                    <?php if ($role == 'SuperAdmin'): ?>
                                        <button class="btn btn-outline-primary" onclick="openSiteModal(<?php echo $const['const_id']; ?>)">현장 추가</button>
                                        <button class="btn btn-outline-secondary" onclick="editConst(<?php echo $const['const_id']; ?>, '<?php echo h($const['const_name']); ?>', '<?php echo h($const['admin_id']); ?>')"><i class="bi bi-pencil"></i></button>
                                        <button class="btn btn-outline-danger" onclick="deleteNode('const', <?php echo $const['const_id']; ?>)"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Sites -->
                            <?php 
                            $sites = array_filter($all_sites, function($s) use ($const) { return $s['const_id'] == $const['const_id']; });
                            foreach ($sites as $site):
                            ?>
                                <div class="site-item shadow-sm">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-semibold text-dark">
                                            <i class="bi bi-geo-alt me-2 text-success"></i> 
                                            <small class="text-success me-2">[<?php echo h($site['site_code']); ?>]</small>
                                            <?php echo h($site['site_name']); ?>
                                        </span>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-sm btn-outline-success" onclick="openPlatformModal(<?php echo $site['site_id']; ?>)">승강장 추가</button>
                                            <button class="btn btn-sm btn-link text-muted" onclick="editSite(<?php echo $site['site_id']; ?>, '<?php echo h($site['site_name']); ?>')"><i class="bi bi-pencil-square"></i></button>
                                            <?php if ($role == 'SuperAdmin'): ?>
                                                <button class="btn btn-sm btn-link text-danger" onclick="deleteNode('site', <?php echo $site['site_id']; ?>)"><i class="bi bi-x-circle"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Platforms -->
                                    <div class="mt-2 bg-light rounded-3 p-2">
                                        <?php 
                                        $platforms = array_filter($all_platforms, function($p) use ($site) { return $p['site_id'] == $site['site_id']; });
                                        foreach ($platforms as $plat):
                                        ?>
                                            <div class="platform-item">
                                                <span class="small text-muted">
                                                    <i class="bi bi-reception-4 me-2"></i> 
                                                    <code class="me-2"><?php echo h($plat['platform_code']); ?></code>
                                                    <?php echo h($plat['platform_name']); ?>
                                                </span>
                                                <div class="d-flex gap-2 align-items-center">
                                                    <a href="projects_platform_items.php?id=<?php echo $plat['platform_id']; ?>" class="btn btn-xs btn-outline-info py-0 px-2 small" style="font-size: 0.75rem;">
                                                        <i class="bi bi-list-check"></i> 항목 설정
                                                    </a>
                                                    <button class="btn btn-link text-muted p-0" onclick="editPlatform(<?php echo $plat['platform_id']; ?>, '<?php echo h($plat['platform_name']); ?>')"><i class="bi bi-pencil-square"></i></button>
                                                    <button class="btn btn-link text-danger p-0" onclick="deleteNode('platform', <?php echo $plat['platform_id']; ?>)"><i class="bi bi-dash-circle"></i></button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($platforms)): ?>
                                            <div class="text-center small text-muted py-2">등록된 승강장이 없습니다.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($constructions)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                            <p class="text-muted">관리 중인 공정 정보가 없습니다.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals -->
<?php if ($role == 'SuperAdmin'): ?>
<div class="modal fade" id="constModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="project_proc.php" method="get" class="modal-content">
            <input type="hidden" name="mode" value="add_const">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">신규 공사 등록</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold">공사 명칭</label>
                    <input type="text" name="name" class="form-control form-control-lg rounded-3" required placeholder="예: 경부선 PSD 개량 공사">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold">담당 관리자</label>
                    <select name="admin_id" class="form-select rounded-3" required>
                        <option value="">담당 관리자 선택</option>
                        <?php foreach ($admins as $ad): ?>
                            <option value="<?php echo h($ad['member_id']); ?>"><?php echo h($ad['name']); ?> (<?php echo h($ad['member_id']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">취소</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4">등록하기</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="siteModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="project_proc.php" method="get" class="modal-content">
            <input type="hidden" name="mode" value="add_site">
            <input type="hidden" name="const_id" id="modal_const_id">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">현장(역사) 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold">현장 명칭</label>
                    <input type="text" name="name" class="form-control form-control-lg rounded-3" required placeholder="예: 서울역">
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">취소</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4">등록하기</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editConstModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="project_proc.php" method="get" class="modal-content">
            <input type="hidden" name="mode" value="edit_const">
            <input type="hidden" name="id" id="modal_edit_const_id">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">공사 정보 수정</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold">공사 명칭</label>
                    <input type="text" name="name" id="modal_edit_const_name" class="form-control form-control-lg rounded-3" required>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold">담당 관리자 변경</label>
                    <select name="admin_id" id="modal_edit_const_admin" class="form-select rounded-3" required>
                        <option value="">담당 관리자 선택</option>
                        <?php foreach ($admins as $ad): ?>
                            <option value="<?php echo h($ad['member_id']); ?>"><?php echo h($ad['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">취소</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4">수정완료</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="platformModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="project_proc.php" method="get" class="modal-content">
            <input type="hidden" name="mode" value="add_platform">
            <input type="hidden" name="site_id" id="modal_site_id">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">승강장 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold">승강장 명칭</label>
                    <input type="text" name="name" class="form-control form-control-lg rounded-3" required placeholder="예: 1-2번 승강장">
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">취소</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4">등록하기</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editSiteModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="project_proc.php" method="get" class="modal-content">
            <input type="hidden" name="mode" value="edit_site">
            <input type="hidden" name="id" id="modal_edit_site_id">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">현장(역사) 정보 수정</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold">현장 명칭</label>
                    <input type="text" name="name" id="modal_edit_site_name" class="form-control form-control-lg rounded-3" required>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">취소</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4">수정완료</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editPlatformModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="project_proc.php" method="get" class="modal-content">
            <input type="hidden" name="mode" value="edit_platform">
            <input type="hidden" name="id" id="modal_edit_platform_id">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">승강장 정보 수정</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold">승강장 명칭</label>
                    <input type="text" name="name" id="modal_edit_platform_name" class="form-control form-control-lg rounded-3" required>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">취소</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4">수정완료</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const constModal = document.getElementById('constModal') ? new bootstrap.Modal(document.getElementById('constModal')) : null;
const siteModal = document.getElementById('siteModal') ? new bootstrap.Modal(document.getElementById('siteModal')) : null;
const platformModal = new bootstrap.Modal(document.getElementById('platformModal'));
const editConstModal = document.getElementById('editConstModal') ? new bootstrap.Modal(document.getElementById('editConstModal')) : null;
const editSiteModal = new bootstrap.Modal(document.getElementById('editSiteModal'));
const editPlatformModal = new bootstrap.Modal(document.getElementById('editPlatformModal'));

function openConstModal() { if(constModal) constModal.show(); }
function openSiteModal(constId) {
    if(siteModal) {
        document.getElementById('modal_const_id').value = constId;
        siteModal.show();
    }
}
function openPlatformModal(siteId) {
    document.getElementById('modal_site_id').value = siteId;
    platformModal.show();
}
function editConst(id, name, adminId) {
    if(editConstModal) {
        document.getElementById('modal_edit_const_id').value = id;
        document.getElementById('modal_edit_const_name').value = name;
        document.getElementById('modal_edit_const_admin').value = adminId;
        editConstModal.show();
    }
}
function editSite(id, name) {
    document.getElementById('modal_edit_site_id').value = id;
    document.getElementById('modal_edit_site_name').value = name;
    editSiteModal.show();
}
function editPlatform(id, name) {
    document.getElementById('modal_edit_platform_id').value = id;
    document.getElementById('modal_edit_platform_name').value = name;
    editPlatformModal.show();
}
function deleteNode(type, id) {
    showConfirm('삭제하시겠습니까? 하위 항목이 있는 경우 삭제가 제한될 수 있습니다.', () => {
        location.href = `project_proc.php?mode=delete&type=${type}&id=${id}`;
    });
}
</script>
</body>
</html>
