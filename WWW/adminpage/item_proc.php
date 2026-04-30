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
            $stmt = $pdo->prepare("SELECT sort_order FROM items WHERE item_id = ?");
            $stmt->execute([$target_item_id]);
            $target_order = (int)$stmt->fetchColumn();
            
            // 현장 추가 항목인 경우, 마스터와 겹쳐도 되므로 그냥 해당 순서값만 가져옴
            // (platform_id ASC 정렬 덕분에 마스터 다음 순서로 배치됨)
            $new_order = $target_order;
            
            // 만약 동일한 sort_order를 가진 다른 추가 항목이 있다면 걔네만 밀어줌
            if ($pid) {
                $pdo->prepare("UPDATE items SET sort_order = sort_order + 1 WHERE role_type = ? AND platform_id = ? AND sort_order > ?")->execute([$role_type, $pid, $target_order]);
            } else {
                $pdo->prepare("UPDATE items SET sort_order = sort_order + 1 WHERE role_type = ? AND platform_id IS NULL AND admin_id = ? AND sort_order > ?")->execute([$role_type, $user_id, $target_order]);
            }
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
        
        if ($ref == 'platform' && empty($check['platform_id'])) {
            redirect($role_param, $ref, $pid);
        }

        $pcheck = $pdo->prepare("SELECT COUNT(*) FROM photo_logs WHERE item_id = ?");
        $pcheck->execute([$id]);
        if ($pcheck->fetchColumn() > 0) {
            redirect($role_param, $ref, $pid);
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
        $stmt_info = $pdo->prepare("SELECT platform_id, admin_id, role_type, sort_order FROM items WHERE item_id = ?");
        $stmt_info->execute([$id]);
        $current = $stmt_info->fetch();

        if ($current && !empty($current['platform_id'])) {
            $curr_order = (int)$current['sort_order'];
            $role_type = $current['role_type'];
            $c_pid = $current['platform_id'];
            $c_admin = $current['admin_id'];

            // 현장 전용 페이지에서의 정렬 로직 (마스터 포함 통합 리스트 기준)
            $where_combined = "( (platform_id IS NULL AND admin_id = " . $pdo->quote($c_admin) . " AND item_id NOT IN (SELECT item_id FROM platform_excluded_items WHERE platform_id = " . (int)$c_pid . ")) OR platform_id = " . (int)$c_pid . " )";

            if ($direction == 'up') {
                $stmt_target = $pdo->prepare("
                    SELECT item_id, sort_order, platform_id FROM items 
                    WHERE role_type = ? AND $where_combined 
                    AND (sort_order < ? OR (sort_order = ? AND IFNULL(platform_id, 0) < IFNULL(?, 0)))
                    ORDER BY sort_order DESC, IFNULL(platform_id, 0) DESC LIMIT 1
                ");
                $stmt_target->execute([$role_type, $curr_order, $curr_order, $c_pid]);
            } else {
                $stmt_target = $pdo->prepare("
                    SELECT item_id, sort_order, platform_id FROM items 
                    WHERE role_type = ? AND $where_combined 
                    AND (sort_order > ? OR (sort_order = ? AND IFNULL(platform_id, 0) > IFNULL(?, 0)))
                    ORDER BY sort_order ASC, IFNULL(platform_id, 0) ASC LIMIT 1
                ");
                $stmt_target->execute([$role_type, $curr_order, $curr_order, $c_pid]);
            }
            $target = $stmt_target->fetch();

            if ($target) {
                if ($target['platform_id'] == $c_pid) {
                    // 같은 현장 전용 항목끼리는 스왑
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE items SET sort_order = ? WHERE item_id = ?")->execute([$target['sort_order'], $id]);
                    $pdo->prepare("UPDATE items SET sort_order = ? WHERE item_id = ?")->execute([$curr_order, $target['item_id']]);
                    $pdo->commit();
                } else {
                    // 마스터 항목을 넘어가는 경우, 마스터 항목의 순서를 따라가되 정렬 조건에 의해 위치 결정
                    $new_order = (int)$target['sort_order'];
                    if ($direction == 'up') {
                        // 위로 가려면 마스터 순서보다 하나 작게 (마스터 앞에 서게 됨)
                        $new_order = $new_order - 1;
                    } 
                    // 아래로 가려면 마스터 순서와 같게 (platform_id ASC 조건에 의해 마스터 뒤에 서게 됨)
                    $pdo->prepare("UPDATE items SET sort_order = ? WHERE item_id = ?")->execute([$new_order, $id]);
                }
            }
        } else if ($current && empty($current['platform_id'])) {
            // 마스터 항목 페이지에서의 정렬 (기존 로직 유지)
            $curr_order = $current['sort_order'];
            $stmt_target = ($direction == 'up') 
                ? $pdo->prepare("SELECT item_id, sort_order FROM items WHERE role_type = ? AND platform_id IS NULL AND admin_id = ? AND sort_order < ? ORDER BY sort_order DESC LIMIT 1")
                : $pdo->prepare("SELECT item_id, sort_order FROM items WHERE role_type = ? AND platform_id IS NULL AND admin_id = ? AND sort_order > ? ORDER BY sort_order ASC LIMIT 1");
            
            $stmt_target->execute([$current['role_type'], $current['admin_id'], $curr_order]);
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
