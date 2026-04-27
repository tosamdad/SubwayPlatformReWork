<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    header('Location: ./index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL Admin - 비밀번호 변경</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        .admin-sidebar { background: #ffffff; border-right: 1px solid #e2e8f0; min-height: 100vh; padding: 2rem 1.5rem; }
        .admin-content { padding: 2.5rem; }
        .section-card { background: white; border-radius: 1rem; padding: 2.5rem; border: 1px solid #e2e8f0; max-width: 500px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .form-label { font-weight: 600; color: #475569; font-size: 0.9rem; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <?php include_once 'inc/sidebar.php'; ?>

        <div class="col-md-10 admin-content d-flex flex-column align-items-center">
            <header class="mb-5 text-center">
                <h2 class="fw-bold text-dark">비밀번호 변경</h2>
                <p class="text-muted small">계정 보안을 위해 주기적인 비밀번호 변경을 권장합니다.</p>
            </header>

            <div class="section-card w-100">
                <?php if (isset($_GET['msg'])): ?>
                    <div class="alert <?php echo $_GET['msg'] == 'success' ? 'alert-success' : 'alert-danger'; ?> py-2 small mb-4 border-0">
                        <?php 
                            if ($_GET['msg'] == 'success') echo "비밀번호가 성공적으로 변경되었습니다.";
                            else if ($_GET['msg'] == 'error_curr') echo "현재 비밀번호가 일치하지 않습니다.";
                            else if ($_GET['msg'] == 'error_match') echo "새 비밀번호가 서로 일치하지 않습니다.";
                            else echo "변경 중 오류가 발생했습니다.";
                        ?>
                    </div>
                <?php endif; ?>

                <form action="password_proc.php" method="POST">
                    <div class="mb-4">
                        <label class="form-label">현재 비밀번호</label>
                        <input type="password" name="current_password" class="form-control form-control-lg rounded-3" required placeholder="기존 비밀번호 입력">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">새 비밀번호</label>
                        <input type="password" name="new_password" class="form-control form-control-lg rounded-3" required placeholder="8자 이상 권장">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">새 비밀번호 확인</label>
                        <input type="password" name="confirm_password" class="form-control form-control-lg rounded-3" required placeholder="다시 한번 입력">
                    </div>

                    <div class="d-grid gap-2 pt-2">
                        <button type="submit" class="btn btn-primary py-2 fw-bold rounded-pill shadow-sm">
                            비밀번호 변경하기
                        </button>
                        <a href="projects.php" class="btn btn-light rounded-pill text-muted">취소</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
