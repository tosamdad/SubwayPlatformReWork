<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';

if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    die("Unauthorized access.");
}

$mode = $_REQUEST['mode'] ?? '';
$role_param = $_REQUEST['role'] ?? 'Worker';
$ref = $_REQUEST['ref'] ?? 'master'; // master | platform
$pid = $_REQUEST['pid'] ?? ''; // platform_id if ref is platform

// 공통 리다이렉트 함수
function redirect($role_param, $ref, $pid) {
    if ($ref === 'platform' && $pid) {
        header("Location: projects_platform_items.php?id=$pid&role=$role_param");
    } else {
        header("Location: items.php?role=$role_param");
    }
    exit;
}

if ($mode == 'toggle') {
    $id = $_GET['id'] ?? '';
    $status = $_GET['status'] ?? 1;

    try {
        $stmt = $pdo->prepare("UPDATE items SET is_visible_mobile = ? WHERE item_id = ? AND admin_id = ?");
        $stmt->execute([$status, $id, $user_id]);
        redirect($role_param, $ref, $pid);
    } catch (Exception $e) {
        redirect($role_param, $ref, $pid);
    }

} else if ($mode == 'add') {
    $category_name = $_POST['category_name'] ?? '';
    $item_name = $_POST['item_name'] ?? '';
    $photo_count = $_POST['photo_count'] ?? 1;
    $role_type = $_POST['role_type'] ?? 'Worker';
    $insert_pos = $_POST['insert_pos'] ?? 'last';
    $target_item_id = $_POST['target_item_id'] ?? '';

    try {
        $pdo->beginTransaction();
        
        $where_p = $pid ? "platform_id = " . (int)$pid : "platform_id IS NULL AND admin_id = " . $pdo->quote($user_id);
        
        if ($insert_pos == 'first') {
            $pdo->prepare("UPDATE items SET sort_order = sort_order + 1 WHERE role_type = ? AND $where_p")->execute([$role_type]);
            $new_order = 1;
        } else if ($insert_pos == 'after' && $target_item_id) {
            $stmt = $pdo->prepare("SELECT sort_order FROM items WHERE item_id = ? AND $where_p");
            $stmt->execute([$target_item_id]);
            $target_order = (int)$stmt->fetchColumn();
            $pdo->prepare("UPDATE items SET sort_order = sort_order + 1 WHERE role_type = ? AND sort_order > ? AND $where_p")->execute([$role_type, $target_order]);
            $new_order = $target_order + 1;
        } else {
            $stmt_max = $pdo->prepare("SELECT MAX(sort_order) FROM items WHERE role_type = ? AND $where_p");
            $stmt_max->execute([$role_type]);
            $new_order = (int)$stmt_max->fetchColumn() + 1;
        }

        $stmt = $pdo->prepare("INSERT INTO items (category_name, item_name, photo_count, role_type, is_visible_mobile, sort_order, admin_id, platform_id) VALUES (?, ?, ?, ?, 1, ?, ?, ?)");
        $stmt->execute([$category_name, $item_name, $photo_count, $role_type, $new_order, $user_id, $pid ?: null]);
        $new_id = $pdo->lastInsertId();
        
        $code = 'D' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE items SET item_code = ? WHERE item_id = ?")->execute([$code, $new_id]);
        
        $pdo->commit();
        redirect($role_type, $ref, $pid);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        redirect($role_type, $ref, $pid);
    }

} else if ($mode == 'edit') {
    $item_id = $_POST['item_id'] ?? '';
    $category_name = $_POST['category_name'] ?? '';
    $item_name = $_POST['item_name'] ?? '';
    $photo_count = (int)($_POST['photo_count'] ?? 1);
    $role_type = $_POST['role_type'] ?? '';

    try {
        $stmt_check = $pdo->prepare("SELECT platform_id, admin_id FROM items WHERE item_id = ?");
        $stmt_check->execute([$item_id]);
        $check = $stmt_check->fetch();
        if (!$check || ($check['admin_id'] !== $user_id && (empty($check['platform_id']) || $check['platform_id'] != $pid))) {
            redirect($role_param, $ref, $pid);
        }

        $stmt_old = $pdo->prepare("SELECT photo_count FROM items WHERE item_id = ?");
        $stmt_old->execute([$item_id]);
        $old_count = (int)($stmt_old->fetchColumn() ?: 1);

        if ($photo_count < $old_count) {
            $stmt_check_log = $pdo->prepare("SELECT COUNT(*) FROM photo_logs WHERE item_id = ? AND photo_index > ?");
            $stmt_check_log->execute([$item_id, $photo_count]);
            if ((int)$stmt_check_log->fetchColumn() > 0) {
                $err = rawurlencode("이미 촬영된 사진이 존재하여 개수를 줄일 수 없습니다.");
                header("Location: " . ($ref === 'platform' ? "projects_platform_items.php?id=$pid&role=$role_param" : "item_form.php?id=$item_id&role=$role_type") . "&msg=$err");
                exit;
            }
        }

        $stmt = $pdo->prepare("UPDATE items SET category_name = ?, item_name = ?, photo_count = ?, role_type = ? WHERE item_id = ?");
        $stmt->execute([$category_name, $item_name, $photo_count, $role_type, $item_id]);
        redirect($role_type, $ref, $pid);
    } catch (Exception $e) {
        redirect($role_type, $ref, $pid);
    }

} else if ($mode == 'delete') {
    $id = $_GET['id'] ?? '';

    try {
        $stmt_check = $pdo->prepare("SELECT platform_id, admin_id FROM items WHERE item_id = ?");
        $stmt_check->execute([$id]);
        $check = $stmt_check->fetch();

        if (!$check) redirect($role_param, $ref, $pid);
        
        // 현장 전용 페이지에서 마스터 항목 삭제 방지
        if ($ref == 'platform' && empty($check['platform_id'])) {
            echo "<script>alert('현장 설정 페이지에서는 현장 전용 항목만 삭제할 수 있습니다.'); history.back();</script>";
            exit;
        }

        $pcheck = $pdo->prepare("SELECT COUNT(*) FROM photo_logs WHERE item_id = ?");
        $pcheck->execute([$id]);
        if ($pcheck->fetchColumn() > 0) {
            echo "<script>alert('이미 촬영된 사진이 존재하여 삭제할 수 없습니다.'); history.back();</script>";
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM items WHERE item_id = ?");
        $stmt->execute([$id]);
        redirect($role_param, $ref, $pid);
    } catch (Exception $e) {
        redirect($role_param, $ref, $pid);
    }

} else if ($mode == 'move_up' || $mode == 'move_down') {
    $id = $_GET['id'] ?? '';
    $direction = ($mode == 'move_up') ? 'up' : 'down';

    try {
        $where_p = $pid ? "platform_id = " . (int)$pid : "platform_id IS NULL AND admin_id = " . $pdo->quote($user_id);
        $stmt = $pdo->prepare("SELECT item_id, sort_order, role_type FROM items WHERE item_id = ? AND $where_p");
        $stmt->execute([$id]);
        $current = $stmt->fetch();

        if ($current) {
            $curr_order = $current['sort_order'];
            $role_type = $current['role_type'];

            if ($direction == 'up') {
                $stmt_target = $pdo->prepare("SELECT item_id, sort_order FROM items WHERE role_type = ? AND sort_order < ? AND $where_p ORDER BY sort_order DESC LIMIT 1");
            } else {
                $stmt_target = $pdo->prepare("SELECT item_id, sort_order FROM items WHERE role_type = ? AND sort_order > ? AND $where_p ORDER BY sort_order ASC LIMIT 1");
            }
            $stmt_target->execute([$role_type, $curr_order]);
            $target = $stmt_target->fetch();

            if ($target) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE items SET sort_order = ? WHERE item_id = ?")->execute([$target['sort_order'], $id]);
                $pdo->prepare("UPDATE items SET sort_order = ? WHERE item_id = ?")->execute([$curr_order, $target['item_id']]);
                $pdo->commit();
            }
        }
        redirect($role_param, $ref, $pid);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        redirect($role_param, $ref, $pid);
    }
}
?>
