<?php
require_once 'inc/db_config.php';
session_start();

// PC 접속 시 관리자 페이지로 리다이렉트
$is_mobile = false;
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$mobile_agents = ['Mobile', 'Android', 'iPhone', 'iPad', 'iPod', 'BlackBerry', 'Windows Phone'];

foreach ($mobile_agents as $agent) {
    if (stripos($user_agent, $agent) !== false) {
        $is_mobile = true;
        break;
    }
}

if (!$is_mobile) {
    header('Location: adminpage/index.php');
    exit;
}

// 아이디 저장 쿠키 확인
$remembered_id = $_COOKIE['remember_id'] ?? '';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role_type'] === 'Admin' || $_SESSION['role_type'] === 'SuperAdmin') {
        header('Location: adminpage/dashboard.php');
    } else {
        header('Location: user/main.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (!empty($user_id) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM members WHERE member_id = ? AND is_active = 1");
            $stmt->execute([$user_id]);
            $member = $stmt->fetch();

            if ($member && password_verify($password, $member['password'])) {
                $_SESSION['user_id'] = $member['member_id'];
                $_SESSION['user_name'] = $member['name'];
                $_SESSION['role_type'] = $member['role_type'];
                $_SESSION['parent_admin_id'] = $member['parent_admin_id'];

                // 아이디 저장 처리
                if ($remember) {
                    setcookie('remember_id', $user_id, time() + (86400 * 30), "/"); // 30일
                } else {
                    setcookie('remember_id', '', time() - 3600, "/");
                }

                if ($member['role_type'] === 'Admin' || $member['role_type'] === 'SuperAdmin') {
                    header('Location: adminpage/dashboard.php');
                } else {
                    header('Location: user/main.php');
                }
                exit;
            } else {
                $error = '아이디 또는 비밀번호가 일치하지 않습니다.';
            }
        } catch (Exception $e) {
            $error = '로그인 중 오류가 발생했습니다.';
        }
    } else {
        $error = '아이디와 비밀번호를 모두 입력해주세요.';
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> 공정관리 - 로그인</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Outfit:wght@500;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --korail-blue: #00529b; --korail-blue-dark: #003d74; }
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; min-height: 100vh; margin: 0; }
        
        .login-wrapper { display: flex; width: 100%; min-height: 100vh; background: white; }
        
        /* 인포그래픽 영역 (좌측) */
        .infographic-side {
            flex: 1.2;
            background: url('assets/img/infographic.png') no-repeat center center;
            background-size: cover;
            position: relative;
            display: flex;
            align-items: flex-end;
            padding: 4rem;
        }
        .infographic-side::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(to bottom, rgba(0,82,155,0.1), rgba(0,20,40,0.8));
        }
        .infographic-content { position: relative; z-index: 1; color: white; }
        .infographic-content h1 { font-family: 'Outfit', sans-serif; font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 800; margin-bottom: 1rem; line-height: 1.1; }
        .infographic-content p { font-size: 1.2rem; opacity: 0.9; font-weight: 300; }

        /* 로그인 폼 영역 (우측) */
        .login-side {
            flex: 0.8;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem;
            background: #ffffff;
        }
        
        .login-card { width: 100%; max-width: 400px; }
        .brand-logo { font-family: 'Outfit', sans-serif; color: var(--korail-blue); font-weight: 800; font-size: 2.5rem; margin-bottom: 0.25rem; letter-spacing: -0.03em; }
        .brand-sub { color: #64748b; margin-bottom: 1.5rem; font-size: 1.05rem; font-weight: 500; }
        
        .form-label { font-weight: 600; color: #334155; margin-bottom: 0.5rem; font-size: 0.9rem; }
        .form-control { 
            border-radius: 0.75rem; 
            padding: 0.85rem 1rem; 
            border: 1.5px solid #e2e8f0; 
            background: #f8fafc;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .form-control:focus {
            background: white;
            border-color: var(--korail-blue);
            box-shadow: 0 0 0 4px rgba(0,82,155,0.1);
            outline: none;
        }
        
        .form-check-input:checked { background-color: var(--korail-blue); border-color: var(--korail-blue); }
        .form-check-label { font-size: 0.85rem; color: #64748b; cursor: pointer; }
        
        .btn-korail { 
            background: var(--korail-blue); 
            color: white; 
            border-radius: 0.75rem; 
            padding: 1rem; 
            font-weight: 700; 
            width: 100%; 
            border: none; 
            transition: all 0.2s;
            margin-top: 1rem;
            font-size: 1.1rem;
        }
        .btn-korail:hover { background: var(--korail-blue-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,82,155,0.2); }
        .btn-korail:active { transform: translateY(0); }
        
        /* 모바일 대응 수정 (한 화면 핏 맞춤) */
        @media (max-width: 1024px) {
            .login-wrapper { flex-direction: column; overflow: hidden; height: 100vh; }
            .infographic-side { 
                flex: none; 
                height: 25vh; 
                min-height: 180px;
                padding: 1.5rem;
                background-position: center 30%;
            }
            .infographic-content h1 { font-size: 1.6rem; margin-bottom: 0.25rem; }
            .infographic-content p { font-size: 0.85rem; margin-bottom: 0; }
            .login-side { flex: 1; padding: 1.5rem; align-items: flex-start; padding-top: 1.5rem; overflow-y: auto; }
            .login-card { max-width: 100%; }
            .brand-logo { font-size: 2.2rem; }
            .brand-sub { margin-bottom: 1.25rem; font-size: 1rem; }
            .form-control { padding: 0.75rem 1rem; }
            .btn-korail { padding: 0.85rem; margin-top: 0.5rem; }
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="infographic-side">
        <div class="infographic-content">
            <h1>스마트 공정 관리 시스템</h1>
            <p>효율적인 공정 관리를 위한 통합 플랫폼입니다.</p>
        </div>
    </div>
    
    <div class="login-side">
        <div class="login-card">
            <div class="brand-logo">공정사진</div>
            <p class="brand-sub">공정관리 시스템 로그인</p>
            
            <?php if ($error): ?>
                <div class="alert alert-danger py-3 border-0 rounded-4 small text-center mb-4">
                    <i class="bi bi-exclamation-circle me-2"></i><?php echo h($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label for="user_id" class="form-label mb-1">접속 ID</label>
                    <input type="text" name="user_id" id="user_id" class="form-control" placeholder="아이디를 입력하세요" value="<?php echo h($remembered_id); ?>" required>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label mb-1">비밀번호</label>
                    <input type="password" name="password" id="password" class="form-control" placeholder="비밀번호를 입력하세요" required>
                </div>

                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                        <label class="form-check-label" for="remember">아이디 저장</label>
                        <input class="form-check-input" type="checkbox" name="remember" id="remember" <?php echo $remembered_id ? 'checked' : ''; ?>>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-korail shadow-sm">로그인하기</button>
            </form>
            
            <div class="mt-4 text-muted small text-center" style="opacity: 0.5; font-size: 0.7rem;">
                &copy; 2026 KORAIL Platform System.
            </div>
        </div>
    </div>
</div>

</body>
</html>
