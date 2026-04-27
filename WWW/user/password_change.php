<?php
require_once '../inc/db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$platform_name = "비밀번호 변경";
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL - 비밀번호 변경</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; padding-bottom: 30px; font-family: 'Pretendard', sans-serif; }
        .change-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: none; margin-top: 2rem; }
        .form-control { border-radius: 12px; padding: 0.75rem 1rem; border: 1px solid #e2e8f0; background-color: #fcfdfe; }
        .form-control:focus { box-shadow: 0 0 0 4px rgba(0, 82, 155, 0.1); border-color: #00529b; }
        .btn-submit { background: linear-gradient(135deg, #00529b 0%, #003d74 100%); border: none; border-radius: 12px; padding: 0.8rem; font-weight: 700; transition: transform 0.2s; }
        .btn-submit:active { transform: scale(0.98); }
    </style>
</head>
<body>

<?php include_once 'inc/nav.php'; ?>

<div class="container px-4">
    <div class="card change-card p-4">
        <div class="text-center mb-4">
            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                <i class="bi bi-shield-lock fs-2"></i>
            </div>
            <h4 class="fw-bold text-dark">비밀번호 변경</h4>
            <p class="text-muted small">안전한 계정 사용을 위해 비밀번호를 관리해 주세요.</p>
        </div>

        <form action="password_proc.php" method="POST" id="passwordForm">
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">현재 비밀번호</label>
                <input type="password" name="current_password" class="form-control" placeholder="현재 비밀번호 입력" required>
            </div>
            <hr class="my-4 opacity-5">
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">새 비밀번호</label>
                <input type="password" name="new_password" id="new_password" class="form-control" placeholder="새 비밀번호 입력" required>
            </div>
            <div class="mb-4">
                <label class="form-label small fw-bold text-muted">새 비밀번호 확인</label>
                <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="새 비밀번호 다시 입력" required>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-submit">비밀번호 변경하기</button>
        </form>
    </div>
</div>

<!-- 커스텀 Alert/Confirm 모달 (nav.php에 넣지 않고 페이지별로 유지하되 nav에 통합 가능) -->
<div class="modal fade" id="customAlertModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered px-4">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <div class="modal-body text-center p-4">
        <i id="alertIcon" class="bi bi-info-circle text-primary mb-3" style="font-size: 2.5rem; display: block;"></i>
        <p id="customAlertMessage" class="mb-4 text-dark" style="font-size: 1.05rem; word-break: keep-all;"></p>
        <button type="button" id="alertOkBtn" class="btn btn-primary w-100 rounded-pill py-2" data-bs-dismiss="modal">확인</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let alertModalObj = null;

function showAlert(message, type = 'info', onHide = null) {
    const msgEl = document.getElementById('customAlertMessage');
    const iconEl = document.getElementById('alertIcon');
    msgEl.innerHTML = message.replace(/\n/g, '<br>');
    
    if (type === 'success') {
        iconEl.className = 'bi bi-check-circle text-success mb-3';
    } else if (type === 'error') {
        iconEl.className = 'bi bi-exclamation-circle text-danger mb-3';
    } else {
        iconEl.className = 'bi bi-info-circle text-primary mb-3';
    }

    if (!alertModalObj) alertModalObj = new bootstrap.Modal(document.getElementById('customAlertModal'));
    
    const modalEl = document.getElementById('customAlertModal');
    if (onHide) {
        modalEl.addEventListener('hidden.bs.modal', onHide, { once: true });
    }
    
    alertModalObj.show();
}

document.getElementById('passwordForm').onsubmit = function(e) {
    const n = document.getElementById('new_password').value;
    const c = document.getElementById('confirm_password').value;
    if (n !== c) {
        e.preventDefault();
        showAlert('새 비밀번호가 서로 일치하지 않습니다.', 'error');
        return false;
    }
    return true;
};

// URL 파라미터 체크
window.onload = function() {
    const urlParams = new URLSearchParams(window.location.search);
    const msg = urlParams.get('msg');
    if (msg) {
        if (msg === 'success') {
            showAlert('비밀번호가 성공적으로 변경되었습니다.', 'success', () => { location.href = 'main.php'; });
        } else if (msg === 'error_curr') {
            showAlert('현재 비밀번호가 올바르지 않습니다.', 'error');
        } else if (msg === 'error_match') {
            showAlert('새 비밀번호가 일치하지 않습니다.', 'error');
        } else {
            showAlert('비밀번호 변경 중 오류가 발생했습니다.', 'error');
        }
        // URL 클린업
        window.history.replaceState({}, document.title, window.location.pathname);
    }
};
</script>
</body>
</html>
