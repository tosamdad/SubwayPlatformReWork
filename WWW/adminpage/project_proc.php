<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    die("Unauthorized access.");
}

$mode = $_REQUEST['mode'] ?? '';

if ($mode == 'add_const') {
    // 공사 등록은 SuperAdmin만 가능
    if ($role !== 'SuperAdmin') die("Permission denied.");
    
    $name = $_GET['name'] ?? '';
    $admin_id = $_GET['admin_id'] ?? '';
    if ($name && $admin_id) {
        $stmt = $pdo->prepare("INSERT INTO constructions (const_name, admin_id) VALUES (?, ?)");
        $stmt->execute([$name, $admin_id]);
        $new_id = $pdo->lastInsertId();
        $code = 'A' . str_pad($new_id, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE constructions SET const_code = ? WHERE const_id = ?")->execute([$code, $new_id]);
    }
    header('Location: projects.php');

} else if ($mode == 'add_site') {
    // 역사 등록은 SuperAdmin만 가능
    if ($role !== 'SuperAdmin') die("Permission denied.");

    $const_id = $_GET['const_id'] ?? '';
    $name = $_GET['name'] ?? '';
    if ($const_id && $name) {
        $stmt = $pdo->prepare("INSERT INTO sites (const_id, site_name) VALUES (?, ?)");
        $stmt->execute([$const_id, $name]);
        $new_id = $pdo->lastInsertId();
        $code = 'B' . str_pad($new_id, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE sites SET site_code = ? WHERE site_id = ?")->execute([$code, $new_id]);
    }
    header('Location: projects.php');

} else if ($mode == 'add_platform') {
    $site_id = $_GET['site_id'] ?? '';
    $name = $_GET['name'] ?? '';
    if ($site_id && $name) {
        $stmt = $pdo->prepare("INSERT INTO platforms (site_id, platform_name) VALUES (?, ?)");
        $stmt->execute([$site_id, $name]);
        $new_id = $pdo->lastInsertId();
        $code = 'C' . str_pad($new_id, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE platforms SET platform_code = ? WHERE platform_id = ?")->execute([$code, $new_id]);
    }
    header('Location: projects.php');

} else if ($mode == 'edit_const') {
    $id = $_GET['id'] ?? '';
    $name = $_GET['name'] ?? '';
    $admin_id = $_GET['admin_id'] ?? ''; // SuperAdmin의 경우 변경 가능
    
    if ($id && $name) {
        if ($role === 'SuperAdmin' && $admin_id) {
            $stmt = $pdo->prepare("UPDATE constructions SET const_name = ?, admin_id = ? WHERE const_id = ?");
            $stmt->execute([$name, $admin_id, $id]);
        } else {
            // 일반 Admin은 명칭만 수정 (담당자는 SuperAdmin만 변경 가능)
            $stmt = $pdo->prepare("UPDATE constructions SET const_name = ? WHERE const_id = ?");
            $stmt->execute([$name, $id]);
        }
    }
    header('Location: projects.php');

} else if ($mode == 'edit_site') {
    $id = $_GET['id'] ?? '';
    $name = $_GET['name'] ?? '';
    if ($id && $name) {
        $stmt = $pdo->prepare("UPDATE sites SET site_name = ? WHERE site_id = ?");
        $stmt->execute([$name, $id]);
    }
    header('Location: projects.php');

} else if ($mode == 'edit_platform') {
    $id = $_GET['id'] ?? '';
    $name = $_GET['name'] ?? '';
    if ($id && $name) {
        $stmt = $pdo->prepare("UPDATE platforms SET platform_name = ? WHERE platform_id = ?");
        $stmt->execute([$name, $id]);
    }
    header('Location: projects.php');

} else if ($mode == 'delete') {
    // 삭제 권한은 신중해야 하므로 SuperAdmin만 상위(Const, Site) 삭제 가능하도록 제한 (선택적)
    // 여기서는 기존 로직 유지하되 상위 레벨 삭제는 SuperAdmin 전용으로 설정
    $type = $_GET['type'] ?? '';
    $id = $_GET['id'] ?? '';
    
    if (($type == 'const' || $type == 'site') && $role !== 'SuperAdmin') {
        die("Permission denied for deletion.");
    }

    try {
        if ($type == 'const') {
            $check = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE const_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                echo "<script>alert('하위 현장이 존재하여 삭제할 수 없습니다.'); location.href='projects.php';</script>";
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM constructions WHERE const_id = ?");
        } else if ($type == 'site') {
            $check = $pdo->prepare("SELECT COUNT(*) FROM platforms WHERE site_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                echo "<script>alert('하위 승강장이 존재하여 삭제할 수 없습니다.'); location.href='projects.php';</script>";
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM sites WHERE site_id = ?");
        } else if ($type == 'platform') {
            $check = $pdo->prepare("SELECT COUNT(*) FROM photo_logs WHERE platform_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                echo "<script>alert('사진 기록이 있는 승강장은 삭제할 수 없습니다.'); location.href='projects.php';</script>";
                exit;
            }
            $pdo->prepare("DELETE FROM platform_excluded_items WHERE platform_id = ?")->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM platforms WHERE platform_id = ?");
        }

        if (isset($stmt)) {
            $stmt->execute([$id]);
        }
        header('Location: projects.php');
        exit;
    } catch (Exception $e) {
        echo "<script>alert('삭제 오류: " . addslashes($e->getMessage()) . "'); location.href='projects.php';</script>";
        exit;
    }

} else if ($mode == 'update_excluded_items') {
    $platform_id = $_POST['platform_id'] ?? '';
    $exclude_ids = $_POST['exclude_ids'] ?? [];

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM platform_excluded_items WHERE platform_id = ?")->execute([$platform_id]);
        if (!empty($exclude_ids)) {
            $ins = $pdo->prepare("INSERT INTO platform_excluded_items (platform_id, item_id) VALUES (?, ?)");
            foreach ($exclude_ids as $item_id) {
                $ins->execute([$platform_id, $item_id]);
            }
        }
        $pdo->commit();
        header("Location: projects_platform_items.php?id=$platform_id&msg=success");
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('저장 오류'); history.back();</script>";
    }
}
?>
