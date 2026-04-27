<?php
$current_page = basename($_SERVER['PHP_SELF']);
$user_name = $_SESSION['user_name'] ?? '사용자';
$user_id = $_SESSION['user_id'] ?? '';
$role_type = $_SESSION['role_type'] ?? 'Worker';
?>
<!-- 상단 내비게이션 바 -->
<header class="work-header d-flex align-items-center justify-content-between px-3">
    <div class="d-flex align-items-center gap-2">
        <?php if ($current_page !== 'main.php'): ?>
            <a href="main.php" class="back-btn text-white"><i class="bi bi-chevron-left fs-4"></i></a>
        <?php endif; ?>
        <div class="platform-title" style="margin: 0;">
            <?php 
            if (isset($platform_html)) {
                echo $platform_html;
            } else {
                echo isset($platform_name) ? h($platform_name) : 'KORAIL'; 
            }
            ?>
        </div>
    </div>
    
    <button class="btn text-white p-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#userMenu" aria-controls="userMenu">
        <i class="bi bi-list fs-2"></i>
    </button>
</header>

<!-- 우측 사이드 메뉴 (Offcanvas) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="userMenu" aria-labelledby="userMenuLabel" style="width: 280px; border-radius: 20px 0 0 20px;">
    <div class="offcanvas-header pb-0">
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body pt-2">
        <!-- 계정 정보 섹션 -->
        <div class="user-profile-section text-center mb-4 p-3 bg-light rounded-4">
            <div class="avatar-circle bg-primary text-white mx-auto mb-2" style="width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                <i class="bi bi-person-fill"></i>
            </div>
            <div class="fw-bold fs-5 text-dark"><?php echo h($user_name); ?></div>
            <div class="text-muted small"><?php echo h($user_id); ?> | <?php echo h($role_type); ?></div>
        </div>

        <!-- 메뉴 리스트 -->
        <div class="list-group list-group-flush border-top">
            <a href="main.php" class="list-group-item list-group-item-action py-3 border-0 d-flex align-items-center">
                <i class="bi bi-house-door me-3 fs-5 text-primary"></i> 홈으로 이동
            </a>
            <a href="password_change.php" class="list-group-item list-group-item-action py-3 border-0 d-flex align-items-center">
                <i class="bi bi-key me-3 fs-5 text-primary"></i> 비밀번호 변경
            </a>
            <hr class="my-2 opacity-10">
            <a href="../logout.php" class="list-group-item list-group-item-action py-3 border-0 d-flex align-items-center text-danger">
                <i class="bi bi-box-arrow-right me-3 fs-5"></i> 로그아웃
            </a>
        </div>
    </div>
    <div class="p-3 text-center text-muted" style="font-size: 0.7rem;">
        &copy; 2026 KORAIL Platform System
    </div>
</div>

<style>
.work-header { 
    background: linear-gradient(135deg, #00529b 0%, #003d74 100%); 
    min-height: 65px;
    padding: 10px 0;
    position: sticky; top: 0; z-index: 1045; 
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    color: white;
}
.platform-title { font-size: 1.2rem; font-weight: 800; letter-spacing: -0.02em; }
.offcanvas { box-shadow: -5px 0 25px rgba(0,0,0,0.1); }
</style>
