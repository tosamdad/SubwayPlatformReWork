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

if ($mode == 'toggle') {
    $id = $_GET['id'] ?? '';
    $status = $_GET['status'] ?? 1;

    try {
        // 본인 소유 항목만 토글 가능
        $stmt = $pdo->prepare("UPDATE items SET is_visible_mobile = ? WHERE item_id = ? AND admin_id = ?");
        $stmt->execute([$status, $id, $user_id]);
        header("Location: items.php?role=$role_param");
        exit;
    } catch (Exception $e) {
        header("Location: items.php?role=$role_param&msg=" . urlencode('상태 변경 중 오류가 발생했습니다.'));
        exit;
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

        $new_order = 0;
        $admin_quote = $pdo->quote($user_id);

        if ($insert_pos == 'first') {
            $pdo->prepare("UPDATE items SET sort_order = sort_order + 1 WHERE role_type = ? AND admin_id = $admin_quote")->execute([$role_type]);
            $new_order = 1;
        } else if ($insert_pos == 'after' && $target_item_id) {
            $stmt = $pdo->prepare("SELECT sort_order FROM items WHERE item_id = ? AND admin_id = $admin_quote");
            $stmt->execute([$target_item_id]);
            $target_order = (int)$stmt->fetchColumn();
            
            $pdo->prepare("UPDATE items SET sort_order = sort_order + 1 WHERE role_type = ? AND sort_order > ? AND admin_id = $admin_quote")->execute([$role_type, $target_order]);
            $new_order = $target_order + 1;
        } else {
            $stmt_order = $pdo->prepare("SELECT MAX(sort_order) FROM items WHERE role_type = ? AND admin_id = $admin_quote");
            $stmt_order->execute([$role_type]);
            $new_order = (int)$stmt_order->fetchColumn() + 1;
        }

        $stmt = $pdo->prepare("INSERT INTO items (category_name, item_name, photo_count, role_type, is_visible_mobile, sort_order, admin_id) VALUES (?, ?, ?, ?, 1, ?, ?)");
        $stmt->execute([$category_name, $item_name, $photo_count, $role_type, $new_order, $user_id]);
        $new_id = $pdo->lastInsertId();
        $code = 'D' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE items SET item_code = ? WHERE item_id = ?")->execute([$code, $new_id]);
        
        $pdo->commit();
        header("Location: items.php?role=$role_type");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: items.php?role=$role_type&msg=" . urlencode('등록 중 오류가 발생했습니다.'));
        exit;
    }

} else if ($mode == 'edit') {
    $item_id = $_POST['item_id'] ?? '';
    $category_name = $_POST['category_name'] ?? '';
    $item_name = $_POST['item_name'] ?? '';
    $photo_count = (int)($_POST['photo_count'] ?? 1);
    $role_type = $_POST['role_type'] ?? '';

    try {
        // 기존 사진 개수 확인
        $stmt_old = $pdo->prepare("SELECT photo_count FROM items WHERE item_id = ? AND admin_id = ?");
        $stmt_old->execute([$item_id, $user_id]);
        $old_count = (int)($stmt_old->fetchColumn() ?: 1);

        // 사진 개수를 줄이는 경우 체크
        if ($photo_count < $old_count) {
            // 사라질 슬롯 범위(photo_index > 새 개수)에 이미 촬영된 사진이 있는지 확인
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM photo_logs WHERE item_id = ? AND photo_index > ?");
            $stmt_check->execute([$item_id, $photo_count]);
            if ((int)$stmt_check->fetchColumn() > 0) {
                header("Location: item_form.php?id=$item_id&role=$role_type&msg=" . rawurlencode("이미 촬영된 사진이 존재하여 개수를 줄일 수 없습니다.\n해당 슬롯의 사진을 먼저 삭제해 주세요."));
                exit;
            }
        }

        // 본인 소유 항목만 수정 가능
        $stmt = $pdo->prepare("UPDATE items SET category_name = ?, item_name = ?, photo_count = ?, role_type = ? WHERE item_id = ? AND admin_id = ?");
        $stmt->execute([$category_name, $item_name, $photo_count, $role_type, $item_id, $user_id]);
        header("Location: items.php?role=$role_type");
        exit;
    } catch (Exception $e) {
        header("Location: items.php?role=$role_type&msg=" . urlencode('수정 중 오류가 발생했습니다.'));
        exit;
    }

} else if ($mode == 'update_photo_count') {
    $id = $_GET['id'] ?? '';
    $count = (int)($_GET['count'] ?? 1);

    try {
        // 기존 사진 개수 확인 및 줄이는 경우 체크
        $stmt_old = $pdo->prepare("SELECT photo_count FROM items WHERE item_id = ? AND admin_id = ?");
        $stmt_old->execute([$id, $user_id]);
        $old_count = (int)($stmt_old->fetchColumn() ?: 1);

        if ($count < $old_count) {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM photo_logs WHERE item_id = ? AND photo_index > ?");
            $stmt_check->execute([$id, $count]);
            if ((int)$stmt_check->fetchColumn() > 0) {
                header("Location: items.php?role=$role_param&msg=" . urlencode("촬영된 사진이 있어 개수를 줄일 수 없습니다."));
                exit;
            }
        }

        $stmt = $pdo->prepare("UPDATE items SET photo_count = ? WHERE item_id = ? AND admin_id = ?");
        $stmt->execute([$count, $id, $user_id]);
        header("Location: items.php?role=$role_param");
        exit;
    } catch (Exception $e) {
        header("Location: items.php?role=$role_param&msg=" . urlencode('사진 개수 변경 중 오류가 발생했습니다.'));
        exit;
    }
} else if ($mode == 'delete') {
    $id = $_GET['id'] ?? '';

    try {
        // 본인 소유 여부 및 촬영 기록 체크
        $check = $pdo->prepare("SELECT COUNT(*) FROM photo_logs WHERE item_id = ?");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            header("Location: items.php?role=$role_param&msg=" . urlencode('촬영 기록이 존재하는 항목은 삭제할 수 없습니다.'));
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM items WHERE item_id = ? AND admin_id = ?");
        $stmt->execute([$id, $user_id]);
        header("Location: items.php?role=$role_param");
        exit;
    } catch (Exception $e) {
        header("Location: items.php?role=$role_param&msg=" . urlencode('삭제 중 오류가 발생했습니다.'));
        exit;
    }

} else if ($mode == 'move_up' || $mode == 'move_down') {
    $id = $_GET['id'] ?? '';
    $direction = ($mode == 'move_up') ? 'up' : 'down';

    try {
        $admin_quote = $pdo->quote($user_id);
        $stmt = $pdo->prepare("SELECT item_id, sort_order, role_type FROM items WHERE item_id = ? AND admin_id = $admin_quote");
        $stmt->execute([$id]);
        $current = $stmt->fetch();

        if ($current) {
            $curr_order = $current['sort_order'];
            $role_type = $current['role_type'];

            if ($direction == 'up') {
                $stmt_target = $pdo->prepare("SELECT item_id, sort_order FROM items WHERE role_type = ? AND sort_order < ? AND admin_id = $admin_quote ORDER BY sort_order DESC LIMIT 1");
            } else {
                $stmt_target = $pdo->prepare("SELECT item_id, sort_order FROM items WHERE role_type = ? AND sort_order > ? AND admin_id = $admin_quote ORDER BY sort_order ASC LIMIT 1");
            }
            $stmt_target->execute([$role_type, $curr_order]);
            $target = $stmt_target->fetch();

            if ($target) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE items SET sort_order = ? WHERE item_id = ? AND admin_id = ?")->execute([$target['sort_order'], $id, $user_id]);
                $pdo->prepare("UPDATE items SET sort_order = ? WHERE item_id = ? AND admin_id = ?")->execute([$curr_order, $target['item_id'], $user_id]);
                $pdo->commit();
            }
        }
        header("Location: items.php?role=$role_param");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: items.php?role=$role_param&msg=" . urlencode('이동 중 오류가 발생했습니다.'));
        exit;
    }
} else if ($mode == 'import_format') {
    // 다른 관리자의 포맷 일괄 복사
    $source_admin_id = $_GET['source_admin_id'] ?? '';
    if (!$source_admin_id) die("Source Admin ID is required.");

    try {
        $pdo->beginTransaction();

        // 1. 소스 관리자의 모든 항목 가져오기
        $stmt_source = $pdo->prepare("SELECT * FROM items WHERE admin_id = ? ORDER BY role_type, sort_order ASC");
        $stmt_source->execute([$source_admin_id]);
        $source_items = $stmt_source->fetchAll();

        foreach ($source_items as $si) {
            // 2. 현재 내 리스트의 마지막 순서 확인 (각 권한별)
            $stmt_max = $pdo->prepare("SELECT MAX(sort_order) FROM items WHERE role_type = ? AND admin_id = ?");
            $stmt_max->execute([$si['role_type'], $user_id]);
            $new_order = (int)$stmt_max->fetchColumn() + 1;

            // 3. 복사본 삽입
            $stmt_ins = $pdo->prepare("INSERT INTO items (category_name, item_name, photo_count, role_type, is_visible_mobile, sort_order, admin_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([
                $si['category_name'],
                $si['item_name'],
                $si['photo_count'],
                $si['role_type'],
                $si['is_visible_mobile'],
                $new_order,
                $user_id
            ]);

            // 4. 새 아이템 코드 생성
            $new_id = $pdo->lastInsertId();
            $new_code = 'D' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE items SET item_code = ? WHERE item_id = ?")->execute([$new_code, $new_id]);
        }

        $pdo->commit();
        header("Location: items.php?role=$role_param");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: items.php?role=$role_param&msg=" . urlencode('포맷 가져오기 중 오류가 발생했습니다: ' . $e->getMessage()));
        exit;
    }
}
?>
