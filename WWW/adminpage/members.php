<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    header('Location: ./index.php');
    exit;
}

// 상태 필터링 처리 (전체: all, 사용중: 1, 사용안함: 0)
$status_filter = $_GET['status'] ?? '1';
$role_filter = $_GET['role'] ?? 'all';

// 기본 권한 필터링
if ($role == 'SuperAdmin') {
    // SuperAdmin은 오직 관리자급(Admin, SuperAdmin)만 관리함
    $where_clause = "WHERE role_type IN ('Admin', 'SuperAdmin')";
} else {
    // 일반 Admin은 자신이 생성한 작업자/안전관리자만 볼 수 있음
    $where_clause = "WHERE role_type IN ('Worker', 'Safety') AND parent_admin_id = " . $pdo->quote($user_id);
}

if ($status_filter !== 'all') {
    $where_clause .= " AND is_active = " . (int)$status_filter;
}

// 일반 Admin일 때만 추가 역할 필터링 (SuperAdmin은 위에서 고정됨)
if ($role == 'Admin' && $role_filter !== 'all') {
    $where_clause .= " AND role_type = " . $pdo->quote($role_filter);
}

try {
    $stmt = $pdo->query("SELECT * FROM members $where_clause ORDER BY created_at DESC");
    $members = $stmt->fetchAll();
} catch (Exception $e) { $members = []; }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL Admin - <?php echo $role == 'SuperAdmin' ? '관리자 관리' : '사용자 관리'; ?></title>
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
        .table thead th { background-color: #f1f5f9; font-weight: 600; color: #475569; border-bottom: none; }
        .status-badge { font-size: 0.75rem; padding: 0.35rem 0.65rem; border-radius: 0.5rem; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <?php include_once 'inc/sidebar.php'; ?>

        <div class="col-md-10 admin-content">
            <header class="d-flex justify-content-between align-items-center mb-5">
                <div>
                    <h2 class="fw-bold text-dark mb-1"><?php echo $role == 'SuperAdmin' ? '관리자 관리' : '사용자 관리'; ?></h2>
                    <p class="text-muted small mb-0">
                        <?php echo $role == 'SuperAdmin' ? '현장 관리자 계정을 생성하고 관리합니다.' : '현장 작업자 및 안전관리자의 계정을 관리합니다.'; ?>
                    </p>
                </div>
                <a href="member_form.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
                    <i class="bi bi-person-plus-fill me-2"></i> <?php echo $role == 'SuperAdmin' ? '신규 관리자 추가' : '신규 사용자 추가'; ?>
                </a>
            </header>

            <div class="section-card shadow-sm">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <?php if ($role == 'Admin'): ?>
                        <select id="roleFilter" class="form-select form-select-sm" style="width: 160px;" onchange="applyFilters()">
                            <option value="all" <?php echo $role_filter == 'all' ? 'selected' : ''; ?>>전체 권한</option>
                            <option value="Safety" <?php echo $role_filter == 'Safety' ? 'selected' : ''; ?>>안전관리자</option>
                            <option value="Worker" <?php echo $role_filter == 'Worker' ? 'selected' : ''; ?>>작업자</option>
                        </select>
                        <?php endif; ?>
                        <div class="btn-group btn-group-sm" role="group">
                            <a href="?status=1&role=<?php echo $role_filter; ?>" class="btn <?php echo $status_filter == '1' ? 'btn-dark' : 'btn-outline-dark'; ?>">사용 중</a>
                            <a href="?status=0&role=<?php echo $role_filter; ?>" class="btn <?php echo $status_filter == '0' ? 'btn-dark' : 'btn-outline-dark'; ?>">사용 안함</a>
                            <a href="?status=all&role=<?php echo $role_filter; ?>" class="btn <?php echo $status_filter == 'all' ? 'btn-dark' : 'btn-outline-dark'; ?>">전체 상태</a>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3">성함</th>
                                <th>아이디</th>
                                <th>전화번호</th>
                                <th>권한</th>
                                <?php if ($role == 'SuperAdmin'): ?><th>소속 관리자</th><?php endif; ?>
                                <th>등록일</th>
                                <th class="text-center">상태</th>
                                <th class="text-end pe-3">관리</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $m): ?>
                            <tr>
                                <td class="ps-3 fw-semibold"><?php echo h($m['name']); ?></td>
                                <td><code><?php echo h($m['member_id']); ?></code></td>
                                <td><?php echo h($m['phone'] ?: '-'); ?></td>
                                <td>
                                    <?php if ($m['role_type'] == 'SuperAdmin'): ?>
                                        <span class="badge bg-dark rounded-pill">최고관리</span>
                                    <?php elseif ($m['role_type'] == 'Admin'): ?>
                                        <span class="badge bg-primary rounded-pill">현장관리</span>
                                    <?php elseif ($m['role_type'] == 'Safety'): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill">안전관리</span>
                                    <?php else: ?>
                                        <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill">작업자</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($role == 'SuperAdmin'): ?>
                                    <td class="small text-muted"><?php echo h($m['parent_admin_id'] ?: '-'); ?></td>
                                <?php endif; ?>
                                <td class="text-muted small"><?php echo substr($m['created_at'], 0, 10); ?></td>
                                <td class="text-center">
                                    <?php if ($m['is_active']): ?>
                                        <span class="status-badge bg-success-subtle text-success border border-success-subtle">사용 중</span>
                                    <?php else: ?>
                                        <span class="status-badge bg-danger-subtle text-danger border border-danger-subtle">사용 안함</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="btn-group btn-group-sm">
                                        <a href="member_form.php?id=<?php echo $m['member_id']; ?>" class="btn btn-outline-secondary">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button type="button" class="btn <?php echo $m['is_active'] ? 'btn-outline-danger' : 'btn-outline-success'; ?>" 
                                                onclick="toggleStatus('<?php echo $m['member_id']; ?>', <?php echo $m['is_active'] ? 0 : 1; ?>)">
                                            <?php echo $m['is_active'] ? '중지' : '복구'; ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($members)): ?>
                                <tr><td colspan="<?php echo $role == 'SuperAdmin' ? '8' : '7'; ?>" class="text-center py-5 text-muted">데이터가 없습니다.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleStatus(id, status) {
    showConfirm(status ? '이 사용자를 활성화하시겠습니까?' : '이 사용자를 비활성화하시겠습니까?', () => {
        location.href = 'member_proc.php?mode=toggle&id=' + id + '&status=' + status;
    });
}

function applyFilters() {
    const role = document.getElementById('roleFilter').value;
    const status = '<?php echo $status_filter; ?>';
    location.href = `?status=${status}&role=${role}`;
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
