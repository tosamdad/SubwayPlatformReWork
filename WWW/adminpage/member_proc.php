<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    die("Unauthorized access.");
}

$mode = $_REQUEST['mode'] ?? '';

if ($mode == 'toggle') {
    // 상태 토글 처리 (사용중/사용안함)
    $id = $_GET['id'] ?? '';
    $status = $_GET['status'] ?? 1;

    try {
        if ($role == 'SuperAdmin') {
            $stmt = $pdo->prepare("UPDATE members SET is_active = ? WHERE member_id = ?");
            $stmt->execute([$status, $id]);
        } else {
            // 일반 Admin은 자신이 생성한 계정만 토글 가능
            $stmt = $pdo->prepare("UPDATE members SET is_active = ? WHERE member_id = ? AND parent_admin_id = ?");
            $stmt->execute([$status, $id, $user_id]);
        }
        header("Location: members.php?status=all");
        exit;
    } catch (Exception $e) {
        header("Location: members.php?msg=" . urlencode('상태 변경 중 오류가 발생했습니다.'));
        exit;
    }

} else if ($mode == 'add') {
    // 신규 등록
    $member_id = $_POST['member_id'] ?? '';
    $password = $_POST['password'] ?? '';
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $role_type = $_POST['role_type'] ?? 'Worker';
    $storage_config_id = $_POST['storage_config_id'] ?? 1;
    
    // 부모 관리자 결정
    $parent_admin_id = null;
    if ($role == 'Admin') {
        $parent_admin_id = $user_id; // 본인이 부모
        // 부모의 storage_config_id 상속
        $stmt_p = $pdo->prepare("SELECT storage_config_id FROM members WHERE member_id = ?");
        $stmt_p->execute([$user_id]);
        $storage_config_id = $stmt_p->fetchColumn() ?: 1;
    } else if ($role == 'SuperAdmin') {
        $parent_admin_id = $_POST['parent_admin_id'] ?? null;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO members (member_id, password, name, phone, role_type, is_active, parent_admin_id, storage_config_id) VALUES (?, ?, ?, ?, ?, 1, ?, ?)");
        $stmt->execute([$member_id, $hashed_password, $name, $phone, $role_type, $parent_admin_id, $storage_config_id]);
        header("Location: members.php");
        exit;
    } catch (Exception $e) {
        if ($e->getCode() == 23000) {
            header("Location: members.php?msg=" . urlencode('이미 존재하는 아이디입니다.'));
        } else {
            header("Location: members.php?msg=" . urlencode('등록 중 오류가 발생했습니다.'));
        }
        exit;
    }

} else if ($mode == 'edit') {
    // 정보 수정
    $member_id = $_POST['member_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $role_type = $_POST['role_type'] ?? '';
    $password = $_POST['password'] ?? '';
    $storage_config_id = $_POST['storage_config_id'] ?? 1;
    
    // 부모 관리자 (SuperAdmin만 수정 가능)
    $parent_admin_id = $_POST['parent_admin_id'] ?? null;

    try {
        // 권한 체크: 일반 Admin은 본인이 생성한 계정만 수정 가능
        if ($role == 'Admin') {
            $check = $pdo->prepare("SELECT parent_admin_id FROM members WHERE member_id = ?");
            $check->execute([$member_id]);
            if ($check->fetchColumn() !== $user_id) die("Permission denied.");
        }

        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            if ($role == 'SuperAdmin') {
                $stmt = $pdo->prepare("UPDATE members SET name = ?, phone = ?, role_type = ?, password = ?, parent_admin_id = ?, storage_config_id = ? WHERE member_id = ?");
                $stmt->execute([$name, $phone, $role_type, $hashed_password, $parent_admin_id, $storage_config_id, $member_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE members SET name = ?, phone = ?, password = ? WHERE member_id = ?");
                $stmt->execute([$name, $phone, $hashed_password, $member_id]);
            }
        } else {
            if ($role == 'SuperAdmin') {
                $stmt = $pdo->prepare("UPDATE members SET name = ?, phone = ?, role_type = ?, parent_admin_id = ?, storage_config_id = ? WHERE member_id = ?");
                $stmt->execute([$name, $phone, $role_type, $parent_admin_id, $storage_config_id, $member_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE members SET name = ?, phone = ? WHERE member_id = ?");
                $stmt->execute([$name, $phone, $member_id]);
            }
        }
        header("Location: members.php");
        exit;
    } catch (Exception $e) {
        header("Location: members.php?msg=" . urlencode('수정 중 오류가 발생했습니다.'));
        exit;
    }
}
?>
