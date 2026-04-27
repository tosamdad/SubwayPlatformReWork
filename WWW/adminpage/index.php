<?php
require_once '../inc/db_config.php';
session_start();

// 이미 관리자로 로그인된 경우 대시보드로 이동
if (isset($_SESSION['role_type']) && ($_SESSION['role_type'] === 'Admin' || $_SESSION['role_type'] === 'SuperAdmin')) {
    $dir = dirname($_SERVER['PHP_SELF']);
    header('Location: ' . $dir . '/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($user_id) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM members WHERE member_id = ? AND role_type IN ('Admin', 'SuperAdmin') AND is_active = 1");
            $stmt->execute([$user_id]);
            $member = $stmt->fetch();

            if ($member && password_verify($password, $member['password'])) {
                $_SESSION['user_id'] = $member['member_id'];
                $_SESSION['user_name'] = $member['name'];
                $_SESSION['role_type'] = $member['role_type'];
                $_SESSION['parent_admin_id'] = $member['parent_admin_id'];

                // 경로 오인 방지를 위해 서버 절대 경로 기반으로 리다이렉트
                $dir = dirname($_SERVER['PHP_SELF']);
                header('Location: ' . $dir . '/dashboard.php');
                exit;
            } else {
                $error = '관리자 정보가 일치하지 않거나 권한이 없습니다.';
            }
        } catch (Exception $e) {
            $error = '인증 중 오류가 발생했습니다.';
        }
    } else {
        $error = '아이디와 비밀번호를 입력해주세요.';
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL Admin - 관리자 로그인</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --admin-primary: #2563eb;
            --admin-dark: #0f172a;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f1f5f9;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-wrapper {
            display: flex;
            width: 100%;
            max-width: 900px;
            height: 550px;
            background: white;
            border-radius: 1.5rem;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.1);
        }
        .login-left {
            flex: 1;
            background: var(--admin-dark);
            color: white;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .login-right {
            flex: 1;
            padding: 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .brand-logo {
            font-weight: 800;
            font-size: 1.5rem;
            letter-spacing: -0.05em;
            margin-bottom: 1rem;
        }
        .brand-logo span { color: var(--admin-primary); }
        .form-control {
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            margin-bottom: 1.25rem;
        }
        .btn-admin {
            background-color: var(--admin-primary);
            color: white;
            border-radius: 0.75rem;
            padding: 0.8rem;
            font-weight: 600;
            border: none;
            width: 100%;
            transition: all 0.2s;
        }
        .btn-admin:hover {
            background-color: #1d4ed8;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-left">
        <div class="brand-logo">KORAIL <span>Admin</span></div>
        <h2 class="fw-bold mb-4">공정 관리 시스템<br>중앙 통제실</h2>
        <p class="text-white-50 small">본 페이지는 시스템 관리자 전용입니다. 권한이 없는 사용자의 접근을 엄격히 제한합니다.</p>
        <div class="mt-auto small text-white-25">© 2026 KORAIL Infrastructure Management</div>
    </div>
    
    <div class="login-right">
        <h4 class="fw-bold mb-1">환영합니다.</h4>
        <p class="text-muted small mb-4">관리자 계정 정보를 입력하여 접속하십시오.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small border-0 bg-danger-subtle text-danger mb-4"><?php echo h($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-1">
                <label class="form-label small fw-semibold text-muted">관리자 아이디</label>
                <input type="text" name="user_id" class="form-control" placeholder="Admin ID" required>
            </div>
            
            <div class="mb-4">
                <label class="form-label small fw-semibold text-muted">비밀번호</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>
            
            <button type="submit" class="btn btn-admin shadow-sm">시스템 접속</button>
        </form>
    </div>
</div>

</body>
</html>
