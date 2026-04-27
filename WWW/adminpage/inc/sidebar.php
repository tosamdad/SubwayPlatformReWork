<?php
// 현재 페이지 파일명 확인
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';
?>
<!-- Sidebar -->
<div class="col-md-2 admin-sidebar d-none d-md-block">
    <div class="dropdown mb-5 px-2">
        <div class="d-flex align-items-center cursor-pointer" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
            <div class="me-3 text-end">
                <div class="fw-bold small text-dark"><?php echo h($_SESSION['user_name'] ?? '관리자'); ?></div>
                <div class="text-muted" style="font-size: 0.7rem;"><?php echo h($role == 'SuperAdmin' ? '최고 관리자' : '현장 관리자'); ?></div>
            </div>
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                <i class="bi bi-person-fill"></i>
            </div>
        </div>
        <ul class="dropdown-menu shadow border-0 mt-2">
            <li><a class="dropdown-item py-2" href="password_change.php"><i class="bi bi-key me-2"></i> 비밀번호 변경</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger py-2" href="../logout.php"><i class="bi bi-power me-2"></i> 로그아웃</a></li>
        </ul>
    </div>

    <nav class="nav flex-column">
        <a class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
            <i class="bi bi-speedometer2 me-2"></i> 대시보드
        </a>
        <?php if ($role == 'SuperAdmin'): ?>
            <!-- 슈퍼관리자 전용 메뉴 -->
            <a class="nav-link <?php echo $current_page == 'members.php' || $current_page == 'member_form.php' ? 'active' : ''; ?>" href="members.php">
                <i class="bi bi-shield-lock me-2"></i> 관리자 관리
            </a>
            <a class="nav-link <?php echo $current_page == 'projects.php' ? 'active' : ''; ?>" href="projects.php">
                <i class="bi bi-kanban me-2"></i> 공정 생성
            </a>
        <?php else: ?>
            <!-- 현장관리자(Admin) 전용 메뉴 -->
            <a class="nav-link <?php echo $current_page == 'members.php' || $current_page == 'member_form.php' ? 'active' : ''; ?>" href="members.php">
                <i class="bi bi-people me-2"></i> 사용자 관리
            </a>        
            <a class="nav-link <?php echo $current_page == 'items.php' || $current_page == 'item_form.php' ? 'active' : ''; ?>" href="items.php">
                <i class="bi bi-camera-fill me-2"></i> 공정사진포맷
            </a>
            <a class="nav-link <?php echo $current_page == 'projects.php' ? 'active' : ''; ?>" href="projects.php">
                <i class="bi bi-kanban me-2"></i> 공정 생성
            </a>
        <?php endif; ?>
        
        <?php if ($role == 'Admin'): ?>
        <div class="mt-4 mb-2 px-3 small text-muted fw-bold">현장 모니터링</div>
        <div class="monitoring-menu-container px-2">
        <?php
        try {
            // Admin은 본인의 공사 목록만 필터링
            $const_sql = "SELECT * FROM constructions WHERE admin_id = ? ORDER BY const_id DESC";
            $const_stmt = $pdo->prepare($const_sql);
            $const_stmt->execute([$user_id]);
            
            $sidebar_consts = $const_stmt->fetchAll();

            foreach ($sidebar_consts as $s_const) {
                echo '<div class="const-menu-group mb-3">';
                echo '<div class="small fw-bold text-dark mb-1 px-2 py-1 bg-light rounded" style="font-size:0.75rem;"><i class="bi bi-building me-1"></i> '.h($s_const['const_name']).'</div>';

                $site_stmt = $pdo->prepare("SELECT * FROM sites WHERE const_id = ? ORDER BY site_id ASC");
                $site_stmt->execute([$s_const['const_id']]);
                $sidebar_sites = $site_stmt->fetchAll();

                foreach ($sidebar_sites as $s_site) {
                    echo '<div class="site-menu-group ms-2 mb-1">';
                    echo '<div class="nav-link py-1 small d-flex align-items-center mb-0 opacity-75" style="cursor: default; font-size: 0.7rem;">';
                    echo '<i class="bi bi-geo-alt me-1"></i>'.h($s_site['site_name']);
                    echo '</div>';

                    $sidebar_plats = $pdo->prepare("
                        SELECT p.*, 
                            (SELECT SUM(photo_count) FROM items WHERE item_id NOT IN (SELECT item_id FROM platform_excluded_items WHERE platform_id = p.platform_id)) as total_count,
                            (SELECT COUNT(*) FROM photo_logs WHERE platform_id = p.platform_id) as uploaded_count
                        FROM platforms p 
                        WHERE p.site_id = ? 
                        ORDER BY p.platform_id ASC
                    ");
                    $sidebar_plats->execute([$s_site['site_id']]);
                    $plats = $sidebar_plats->fetchAll();

                    echo '<div class="ms-3 border-start ps-2">';
                    foreach ($plats as $s_plat) {
                        $is_plat_active = (($_GET['platform_id'] ?? '') == $s_plat['platform_id']);
                        $prog = ($s_plat['total_count'] > 0) ? round(($s_plat['uploaded_count'] / $s_plat['total_count']) * 100) : 0;
                        
                        echo '<a class="nav-link '.($is_plat_active ? 'active fw-bold' : '').' py-1 small d-flex justify-content-between align-items-center" style="font-size: 0.7rem; padding: 0.2rem 0.5rem;" href="site_view.php?site_id='.$s_site['site_id'].'&platform_id='.$s_plat['platform_id'].'">';
                        echo '<span><i class="bi bi-record-fill me-1" style="font-size: 0.35rem;"></i>'.h($s_plat['platform_name']).'</span>';
                        echo '<span class="badge '.($prog == 100 ? 'bg-success' : 'bg-secondary').' opacity-75" style="font-size: 0.55rem; padding: 0.2em 0.4em;">'.$prog.'%</span>';
                        echo '</a>';
                    }
                    echo '</div>';
                    echo '</div>';
                }
                echo '</div>';
            }
        } catch (Exception $e) {}
        ?>
        </div>
        <?php endif; ?>
    </nav>
</div>

<!-- 커스텀 Alert 모달 (관리자용) -->
<div class="modal fade" id="customAlertModal" tabindex="-1" aria-hidden="true" style="z-index: 1070;">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow" style="border-radius: 12px;">
      <div class="modal-body text-center p-4">
        <div class="mb-3"><i class="bi bi-info-circle text-primary" style="font-size: 2.5rem;"></i></div>
        <p id="customAlertMessage" class="mb-4 text-dark fw-medium" style="word-break: keep-all;"></p>
        <button type="button" class="btn btn-primary w-100 py-2 rounded-3" data-bs-dismiss="modal">확인</button>
      </div>
    </div>
  </div>
</div>

<!-- 커스텀 Confirm 모달 (관리자용) -->
<div class="modal fade" id="customConfirmModal" tabindex="-1" aria-hidden="true" style="z-index: 1070;">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow" style="border-radius: 12px;">
      <div class="modal-body text-center p-4">
        <div class="mb-3"><i class="bi bi-exclamation-triangle text-warning" style="font-size: 2.5rem;"></i></div>
        <p id="customConfirmMessage" class="mb-4 text-dark fw-medium" style="word-break: keep-all;"></p>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-light flex-fill py-2 rounded-3" data-bs-dismiss="modal">취소</button>
            <button type="button" id="customConfirmOkBtn" class="btn btn-danger flex-fill py-2 rounded-3">확인</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
let adminAlertModal = null;
let adminConfirmModal = null;

function showAlert(message) {
    document.getElementById('customAlertMessage').innerHTML = message.replace(/\n/g, '<br>');
    if (!adminAlertModal) adminAlertModal = new bootstrap.Modal(document.getElementById('customAlertModal'));
    adminAlertModal.show();
}

function showConfirm(message, onConfirm) {
    document.getElementById('customConfirmMessage').innerHTML = message.replace(/\n/g, '<br>');
    if (!adminConfirmModal) adminConfirmModal = new bootstrap.Modal(document.getElementById('customConfirmModal'));
    
    const okBtn = document.getElementById('customConfirmOkBtn');
    const newOkBtn = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOkBtn, okBtn);
    
    newOkBtn.addEventListener('click', function() {
        adminConfirmModal.hide();
        if (typeof onConfirm === 'function') onConfirm();
    });
    
    adminConfirmModal.show();
}

// URL 파라미터 msg가 있으면 자동으로 showAlert 호출
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const msg = urlParams.get('msg');
    if (msg) {
        // 주소창에서 msg 파라미터 제거 (미관상 & 새로고침 시 반복 방지)
        const newUrl = window.location.pathname + window.location.search.replace(/([?&])msg=[^&]*(&|$)/, '$1').replace(/[?&]$/, '');
        window.history.replaceState({}, document.title, newUrl);
        
        // 약간의 지연 후 알림 표시 (부트스트랩 로드 대기)
        setTimeout(() => showAlert(decodeURIComponent(msg)), 300);
    }
});
</script>
