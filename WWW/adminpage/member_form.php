<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    header('Location: ./index.php');
    exit;
}

$id = $_GET['id'] ?? '';
$mode = $id ? 'edit' : 'add';
$member = null;

if ($mode == 'edit') {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE member_id = ?");
    $stmt->execute([$id]);
    $member = $stmt->fetch();
    if (!$member) {
        header("Location: members.php?msg=" . urlencode('존재하지 않는 사용자입니다.'));
        exit;
    }
    
    // 권한 체크: 일반 Admin은 본인이 생성한 계정만 수정 가능
    if ($role == 'Admin' && $member['parent_admin_id'] !== $user_id) {
        header("Location: members.php?msg=" . urlencode('수정 권한이 없습니다.'));
        exit;
    }
}

// SuperAdmin용 관리자 목록 (작업자 등록 시 상위 관리자 지정용)
$admin_list = [];
if ($role == 'SuperAdmin') {
    $admin_list = $pdo->query("SELECT member_id, name FROM members WHERE role_type = 'Admin' ORDER BY name ASC")->fetchAll();
}
$storage_list = $pdo->query("SELECT config_id, name FROM storage_configs ORDER BY config_id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL Admin - <?php echo $mode == 'add' ? ($role == 'SuperAdmin' ? '관리자 등록' : '사용자 등록') : ($role == 'SuperAdmin' ? '관리자 수정' : '사용자 수정'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        .admin-sidebar { background: #ffffff; border-right: 1px solid #e2e8f0; min-height: 100vh; padding: 2rem 1.5rem; }
        .admin-content { padding: 2.5rem; }
        .section-card { background: white; border-radius: 1rem; padding: 2.5rem; border: 1px solid #e2e8f0; max-width: 650px; }
        .form-label { font-weight: 600; color: #475569; font-size: 0.9rem; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <?php include_once 'inc/sidebar.php'; ?>

        <div class="col-md-10 admin-content">
            <header class="mb-5">
                <a href="members.php" class="text-decoration-none small text-muted mb-2 d-inline-block">
                    <i class="bi bi-arrow-left"></i> 목록으로 돌아가기
                </a>
                <h2 class="fw-bold text-dark"><?php echo $mode == 'add' ? ($role == 'SuperAdmin' ? '신규 관리자 등록' : '신규 사용자 등록') : ($role == 'SuperAdmin' ? '관리자 정보 수정' : '사용자 정보 수정'); ?></h2>
            </header>

            <div class="section-card shadow-sm mx-auto">
                <form action="member_proc.php" method="POST">
                    <input type="hidden" name="mode" value="<?php echo $mode; ?>">
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-primary"><?php echo $role == 'SuperAdmin' ? '관리자 계정 ID' : '사원 아이디 (사원번호)'; ?></label>
                            <input type="text" name="member_id" class="form-control" value="<?php echo h($id); ?>" <?php echo $mode == 'edit' ? 'readonly' : 'required'; ?> placeholder="사용할 아이디">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">비밀번호 <?php echo $mode == 'edit' ? '(변경 시에만)' : ''; ?></label>
                            <input type="password" name="password" class="form-control" <?php echo $mode == 'add' ? 'required' : ''; ?> placeholder="••••••••">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">성함</label>
                            <input type="text" name="name" class="form-control" value="<?php echo $member ? h($member['name']) : ''; ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">전화번호</label>
                            <input type="text" name="phone" class="form-control" value="<?php echo $member ? h($member['phone']) : ''; ?>" placeholder="010-0000-0000">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <?php if ($role == 'SuperAdmin'): ?>
                                <label class="form-label">권한 등급</label>
                                <input type="text" class="form-control bg-light" value="현장 관리자 (Admin)" readonly>
                                <input type="hidden" name="role_type" value="Admin">
                            <?php else: ?>
                                <label class="form-label">권한 등급</label>
                                <select name="role_type" class="form-select" required>
                                    <option value="Worker" <?php echo ($member && $member['role_type'] == 'Worker') ? 'selected' : ''; ?>>작업자 (Worker)</option>
                                    <option value="Safety" <?php echo ($member && $member['role_type'] == 'Safety') ? 'selected' : ''; ?>>안전관리자 (Safety)</option>
                                </select>
                            <?php endif; ?>
                        </div>
                        <?php if ($role == 'SuperAdmin'): ?>
                        <div class="col-md-6">
                            <label class="form-label">스토리지 설정</label>
                            <select name="storage_config_id" class="form-select" required>
                                <?php foreach ($storage_list as $storage): ?>
                                    <option value="<?php echo $storage['config_id']; ?>" <?php echo ($member && $member['storage_config_id'] == $storage['config_id']) ? 'selected' : ''; ?>>
                                        <?php echo h($storage['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="d-grid gap-2 border-top pt-4 mt-4">
                        <button type="submit" class="btn btn-primary py-2 fw-bold rounded-pill">
                            <?php echo $mode == 'add' ? '계정 생성하기' : '수정사항 저장하기'; ?>
                        </button>
                        <a href="members.php" class="btn btn-light rounded-pill text-muted">취소</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
