<?php
require_once '../inc/db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$role_type = $_SESSION['role_type'] ?? 'Worker';

$platform_id = $_GET['platform_id'] ?? 1; 

try {
    $stmt = $pdo->prepare("SELECT p.platform_name, s.site_name, s.site_id, s.const_id FROM platforms p JOIN sites s ON p.site_id = s.site_id WHERE p.platform_id = ?");
    $stmt->execute([$platform_id]);
    $platform = $stmt->fetch();
} catch (Exception $e) { $platform = null; }

$role_filter = "role_type = 'Worker'";
if ($role_type === 'Safety') {
    $role_filter = "role_type IN ('Worker', 'Safety')";
} elseif ($role_type === 'Admin') {
    $role_filter = "1=1";
}

$parent_admin_id = $_SESSION['parent_admin_id'] ?? '';

try {
    // 본인(또는 상위 관리자)에게 귀속된 항목만 가져옴
    $admin_filter = "";
    if ($role_type === 'SuperAdmin') {
        $admin_filter = ""; // 전체
    } else if ($role_type === 'Admin') {
        $admin_filter = " AND admin_id = " . $pdo->quote($user_id);
    } else {
        $admin_filter = " AND admin_id = " . $pdo->quote($parent_admin_id);
    }

    $sql = "SELECT i.* FROM items i 
            WHERE $role_filter AND i.is_visible_mobile = 1
            AND ( (i.platform_id IS NULL $admin_filter) OR i.platform_id = ? )
            AND i.item_id NOT IN (SELECT item_id FROM platform_excluded_items WHERE platform_id = ?)
            ORDER BY i.role_type, i.sort_order ASC, i.platform_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$platform_id, $platform_id]);
    $items = $stmt->fetchAll();

    // 모든 로그를 가져와서 [item_id][photo_index] 형태로 맵핑
    $logs = [];
    $stmt_logs = $pdo->prepare("SELECT * FROM photo_logs WHERE platform_id = ?");
    $stmt_logs->execute([$platform_id]);
    while ($row = $stmt_logs->fetch()) {
        $logs[$row['item_id']][$row['photo_index']] = $row;
    }
    
    // 모든 메모를 가져와서 맵핑
    $memos = [];
    $stmt_memos = $pdo->prepare("SELECT item_id, memo_text FROM item_memos WHERE platform_id = ?");
    $stmt_memos->execute([$platform_id]);
    while ($row = $stmt_memos->fetch()) {
        $memos[$row['item_id']] = $row['memo_text'];
    }
} catch (Exception $e) { $items = []; $logs = []; $memos = []; }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL - 작업 리스트</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
    :root { --korail-blue: #00529b; --success-green: #198754; --pending-orange: #fd7e14; }
    body { background-color: #f8fafc; padding-bottom: 80px; font-family: 'Pretendard', sans-serif; }
    
    .work-header { 
        background: linear-gradient(135deg, #00529b 0%, #003d74 100%); 
        padding: 0.75rem 1rem; 
        position: sticky; top: 0; z-index: 1000; 
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        color: white;
    }
    .header-content { display: flex; align-items: center; gap: 12px; width: 100%; }
    .back-btn { color: white; opacity: 0.8; transition: opacity 0.2s; }
    .back-btn:active { opacity: 1; }
    
    .location-info { display: flex; align-items: center; gap: 6px; }
    .site-tag { font-size: 0.85rem; font-weight: 500; color: rgba(255,255,255,0.7); }
    .sep-icon { font-size: 0.7rem; color: rgba(255,255,255,0.4); }
    .platform-title { font-size: 1.25rem; font-weight: 800; color: white; letter-spacing: -0.02em; }
    
    .item-card { background: white; border-radius: 1.1rem; padding: 1rem 1.25rem; margin: 0.75rem 0.5rem; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 6px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; }
    .item-info { flex-grow: 1; margin-right: 15px; }
    .cat-badge { font-size: 0.65rem; color: #94a3b8; font-weight: 700; text-transform: uppercase; margin-bottom: 4px; display: block; }
    .item-name { font-size: 1.1rem; font-weight: 700; color: #334155; line-height: 1.3; }
    
    .status-btn { width: 52px; height: 52px; border-radius: 1rem; display: flex; align-items: center; justify-content: center; border: none; flex-shrink: 0; }
    .btn-upload { background-color: #fff7ed; color: #ea580c; border: 1px solid #ffedd5; }
    .btn-completed { background-color: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; }
    /* 고도화된 카드 디자인 */
    .item-card {
        background: #fff;
        border-radius: 12px;
        padding: 12px;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        border: 1px solid #f0f0f0;
        transition: all 0.2s;
        position: relative;
    }
    .item-card:active { transform: scale(0.97); }
    .item-card.completed {
        background-color: #d1e7dd; /* 기존보다 더 진한 초록색 배경 */
        border-color: #badbcc;
    }
    .item-info { flex: 1; padding-right: 15px; }
    .item-name { font-weight: 600; font-size: 1.05rem; color: #333; margin-bottom: 8px; }
    
    /* 인라인 사진 슬롯 (확대) */
    .photo-slot {
        width: 90px;
        height: 90px;
        background: #f8f9fa;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
        border: 1.5px dashed #dee2e6;
        cursor: pointer;
    }
    .photo-slot img { width: 100%; height: 100%; object-fit: cover; }
    .upload-overlay {
        position: absolute;
        inset: 0;
        background: rgba(0,0,0,0.4);
        display: none;
        align-items: center;
        justify-content: center;
        color: #fff;
        backdrop-filter: blur(1px);
    }
    .item-card.uploading .upload-overlay { display: flex; }
    .item-card.uploading .photo-slot { border-style: solid; border-color: #0d6efd; }
    
    .status-check {
        position: absolute;
        top: -5px;
        right: -5px;
        background: #28a745;
        color: #fff;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        display: none;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        border: 2px solid #fff;
        z-index: 2;
    }
    .item-card.completed .status-check { display: flex; }
    .item-card.completed .photo-slot { border: 1.5px solid #28a745; }

    /* 다중 사진 슬롯 스타일 */
    .multi-photo-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
        margin-top: 12px;
        width: 100%;
    }
    .multi-slot {
        aspect-ratio: 1;
        background: #f8fafc;
        border: 1px dashed #e2e8f0;
        border-radius: 8px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        position: relative;
        overflow: hidden;
    }
    .multi-slot.has-photo { border: 1px solid #28a745; background: #fff; }
    .multi-slot img { width: 100%; height: 100%; object-fit: cover; }
    .multi-slot .slot-idx { 
        position: absolute; top: 2px; left: 4px; 
        font-size: 0.6rem; font-weight: 800; color: #94a3b8; 
        background: rgba(255,255,255,0.8); padding: 0 4px; border-radius: 4px;
        z-index: 1;
    }
    .multi-slot .status-check-mini {
        position: absolute; bottom: 2px; right: 2px;
        width: 14px; height: 14px; background: #28a745; color: white;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-size: 8px; z-index: 1;
    }
    .multi-slot .status-lock-mini {
        position: absolute; bottom: 2px; right: 2px;
        width: 14px; height: 14px; background: #6c757d; color: white;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-size: 8px; z-index: 1;
    }

    /* 개별 슬롯별 갤러리 버튼 */
    .slot-wrapper {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .mini-gallery-btn {
        width: 100%;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.65rem;
        padding: 5px 0;
        text-align: center;
        color: #64748b;
        font-weight: 800;
        box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }
    .mini-gallery-btn:active { background: #f8fafc; }
    .mini-gallery-btn i { font-size: 0.7rem; margin-right: 2px; }
    
    /* 컴팩트 뷰 토글 상단 고정 바 */
    .compact-toggle-wrapper {
        position: sticky;
        top: 65px; /* nav.php의 work-header 밑으로 살짝 여백 */
        z-index: 999;
        display: flex;
        justify-content: center;
        pointer-events: none; /* 빈 공간 터치 시 스크롤 방해 안함 */
        margin: 5px 0 15px 0;
    }
    .compact-toggle-wrapper .form-check {
        pointer-events: auto; /* 스위치는 터치 가능하게 */
        background: rgba(255, 255, 255, 0.85); /* 텍스트가 읽히도록 최소한의 알약 형태 배경만 */
        backdrop-filter: blur(4px);
        padding: 6px 16px 6px 40px;
        border-radius: 30px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        border: 1px solid rgba(0,0,0,0.05);
    }
    
    /* 컴팩트 뷰 (완료 항목 줄여서 보기) 상태일 때의 CSS */
    body.compact-completed .item-card.completed {
        padding: 0.6rem 1rem;
        margin-bottom: 0.5rem;
    }
    body.compact-completed .item-card.completed .mt-2, /* 갤러리 버튼 영역 */
    body.compact-completed .item-card.completed .photo-slot,
    body.compact-completed .item-card.completed .multi-photo-grid {
        display: none !important;
    }
    
    /* 컴팩트 모드 전용 사진 상태 아이콘 */
    .compact-photo-status { display: none; margin-left: auto; }
    body.compact-completed .item-card.completed .compact-photo-status { 
        display: flex; 
        gap: 4px;
        align-items: center;
    }
    .compact-dot {
        width: 10px; height: 10px; border-radius: 50%;
        background: #28a745;
    }
    .compact-icon {
        font-size: 1.1rem; color: #28a745;
    }
    body.compact-completed .item-card.completed .item-info {
        display: flex;
        align-items: center;
        width: 100%;
        margin-right: 0;
        gap: 10px;
    }
    body.compact-completed .item-card.completed .item-num {
        margin-bottom: 0 !important;
        white-space: nowrap;
    }
    body.compact-completed .item-card.completed .item-name {
        margin-bottom: 0;
        font-size: 0.95rem;
        flex-grow: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    body.compact-completed .item-card.completed .status-check {
        position: static;
        display: flex;
        margin-left: auto;
        width: 22px;
        height: 22px;
        font-size: 14px;
    }
</style>
</head>
<body>

<?php 
// 전체 사진 및 촬영된 사진 수 계산
$total_photos = 0;
$completed_photos = 0;
foreach ($items as $itm) {
    $req_count = (int)($itm['photo_count'] ?? 1);
    $total_photos += $req_count;
    $done_count = isset($logs[$itm['item_id']]) ? count($logs[$itm['item_id']]) : 0;
    $completed_photos += $done_count;
}

$site_name = h($platform['site_name'] ?? '-');
$plat_name = h($platform['platform_name'] ?? '-');

// 진행률 계산
$progress_percent = $total_photos > 0 ? round(($completed_photos / $total_photos) * 100) : 0;

// 상단 타이틀용 커스텀 HTML 생성 (진행률 및 프로그래스 바 강조)
$platform_html = "
    <div class='d-flex flex-column justify-content-center' style='line-height: 1.1; width: 100%; min-width: 220px; padding-right: 10px;'>
        <div class='d-flex justify-content-between align-items-end mb-1'>
            <div>
                <div style='font-size: 0.65rem; color: rgba(255,255,255,0.6); font-weight: 500; margin-bottom: 2px;'>{$site_name}</div>
                <div style='font-size: 1.15rem; font-weight: 800; color: white; letter-spacing: -0.02em;'>{$plat_name}</div>
            </div>
            <div class='text-end pb-1' style='line-height: 1;'>
                <span style='font-size: 1.4rem; font-weight: 900; color: #4ade80;'>{$completed_photos}</span>
                <span style='font-size: 0.9rem; font-weight: 600; color: rgba(255,255,255,0.5);'> / {$total_photos}</span>
            </div>
        </div>
        <div class='progress' style='height: 6px; background-color: rgba(255,255,255,0.2); border-radius: 10px; overflow: hidden; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);'>
            <div class='progress-bar' role='progressbar' style='width: {$progress_percent}%; background-color: #4ade80; border-radius: 10px;' aria-valuenow='{$progress_percent}' aria-valuemin='0' aria-valuemax='100'></div>
        </div>
    </div>
";

include_once 'inc/nav.php'; 
?>

<div class="container-fluid py-2" style="padding-bottom: 80px !important;">
    
    <!-- 완료 항목 축소 토글 스위치 (상단 고정 및 중앙 정렬) -->
    <div class="compact-toggle-wrapper">
        <div class="form-check form-switch d-flex align-items-center m-0">
            <input class="form-check-input shadow-sm m-0" type="checkbox" role="switch" id="compactModeToggle" style="width: 2.5em; cursor: pointer;">
            <label class="form-check-label text-muted small fw-bold ms-2 mt-1" for="compactModeToggle" style="cursor: pointer;">완료한 항목 줄여서 보기</label>
        </div>
    </div>

    <!-- 숨겨진 입력창 -->
    <input type="file" id="cameraInput" accept="image/*" capture="camera" style="display:none;">
    <input type="file" id="galleryInput" accept="image/*" style="display:none;">

    <?php if (empty($items)): ?>
        <div class="text-center py-5">
            <i class="bi bi-clipboard-x fs-1 text-muted opacity-25"></i>
            <p class="text-muted mt-3">표시할 작업 항목이 없습니다.</p>
        </div>
    <?php endif; ?>

    <?php 
    // 마지막 작업 개소 (가장 최근에 업로드한 로그 ID) 찾기
    $last_worked_log_id = null;
    try {
        $stmt_last = $pdo->prepare("SELECT log_id FROM photo_logs WHERE platform_id = ? AND user_id = ? ORDER BY timestamp DESC LIMIT 1");
        $stmt_last->execute([$platform_id, $user_id]);
        $last_worked_log_id = $stmt_last->fetchColumn();
    } catch (Exception $e) {}
    ?>

    <?php foreach ($items as $idx => $item): 
        $photo_count = (int)($item['photo_count'] ?? 1);
        $item_logs = $logs[$item['item_id']] ?? [];
        $completed_count = count($item_logs);
        $is_fully_completed = ($completed_count >= $photo_count);
    ?>
    <div class="item-card <?php echo $is_fully_completed ? 'completed' : ''; ?>" id="card-<?php echo $item['item_id']; ?>">
        
        <div class="item-info">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <div class="item-num text-primary small fw-bold mb-0">
                    TASK <?php echo $idx + 1; ?>
                    <?php 
                    // 해당 아이템의 로그 중 마지막 작업이 있는지 확인
                    $is_last_worked = false;
                    foreach ($item_logs as $l) {
                        if ($l['log_id'] == $last_worked_log_id) {
                            $is_last_worked = true;
                            break;
                        }
                    }
                    if ($is_last_worked): ?>
                        <span class="badge bg-danger ms-2" style="font-size: 0.7rem; vertical-align: text-top;"><i class="bi bi-clock-history me-1"></i>마지막 작업</span>
                    <?php endif; ?>
                </div>
                <?php 
                $memo_text = $memos[$item['item_id']] ?? ''; 
                $has_memo = !empty($memo_text);
                ?>
                <button class="btn btn-sm p-0 text-<?php echo $has_memo ? 'danger' : 'secondary opacity-50'; ?>" onclick="openMemoModal(<?php echo $item['item_id']; ?>, '<?php echo h($memo_text); ?>')" title="메모">
                    <i class="bi bi-chat-text-fill fs-5"></i>
                </button>
            </div>
            <div class="item-name"><?php echo h($item['item_name']); ?></div>
            
            <?php if ($is_fully_completed): ?>
            <!-- 컴팩트 모드에서 보이는 사진 완료 상태 아이콘 -->
            <div class="compact-photo-status">
                <?php if ($photo_count > 1): ?>
                    <?php for($k=0; $k<$photo_count; $k++): ?>
                        <div class="compact-dot"></div>
                    <?php endfor; ?>
                <?php else: ?>
                    <i class="bi bi-image compact-icon"></i>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($photo_count > 1): ?>
                <!-- 다중 사진 슬롯 레이아웃 -->
                <div class="multi-photo-grid">
                    <?php for ($i = 1; $i <= $photo_count; $i++): 
                        $l = $item_logs[$i] ?? null;
                        $has_p = !empty($l);
                        $p_path = $has_p ? $l['photo_url'] : '';
                        $p_owner = $has_p ? $l['user_id'] : '';
                        $is_own = ($has_p && $p_owner === $user_id);
                        $is_oth = ($has_p && !$is_own);
                        $idx_label = str_pad($i, 2, '0', STR_PAD_LEFT);
                    ?>
                        <div class="slot-wrapper">
                            <div class="multi-slot <?php echo $has_p ? 'has-photo' : ''; ?>" 
                                 onclick="<?php echo $has_p ? "openImageViewer('../view_photo.php?path=".urlencode($p_path)."&t=".time()."', {$item['item_id']}, " . ($is_own ? 'true' : 'false') . ", $i)" : "startCapture({$item['item_id']}, $i)"; ?>">
                                <span class="slot-idx"><?php echo $idx_label; ?></span>
                                <?php if ($has_p): ?>
                                    <img src="../view_photo.php?path=<?php echo urlencode($p_path); ?>&t=<?php echo time(); ?>">
                                    <?php if ($is_oth): ?>
                                        <div class="status-lock-mini"><i class="bi bi-lock-fill"></i></div>
                                    <?php else: ?>
                                        <div class="status-check-mini"><i class="bi bi-check-lg"></i></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <i class="bi bi-camera text-primary opacity-25"></i>
                                <?php endif; ?>
                            </div>
                            <?php if (!$has_p): ?>
                                <div class="mini-gallery-btn" onclick="startGallery(<?php echo $item['item_id']; ?>, <?php echo $i; ?>)">
                                    <i class="bi bi-images"></i>갤러리
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            <?php else: ?>
                <!-- 기존 단일 사진 레이아웃 유지 (하단 갤러리 버튼 부분) -->
                <?php 
                $l = $item_logs[1] ?? null;
                $has_p = !empty($l);
                $p_owner = $has_p ? $l['user_id'] : '';
                $is_own = ($has_p && $p_owner === $user_id);
                $is_oth = ($has_p && !$is_own);
                ?>
                <?php if ($is_oth): ?>
                    <div class="mt-2">
                        <span class="badge bg-secondary opacity-75 fw-normal px-2 py-1"><i class="bi bi-lock-fill me-1"></i><?php echo h($p_owner); ?> 완료</span>
                    </div>
                <?php elseif ($has_p && $is_own): ?>
                    <div class="mt-2">
                        <span class="badge bg-success opacity-75 fw-normal px-2 py-1"><i class="bi bi-person-check-fill me-1"></i><?php echo h($p_owner); ?> 완료</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($photo_count == 1): ?>
            <?php 
            $l = $item_logs[1] ?? null;
            $has_p = !empty($l);
            $p_path = $has_p ? $l['photo_url'] : '';
            $p_owner = $has_p ? $l['user_id'] : '';
            $is_own = ($has_p && $p_owner === $user_id);
            $is_oth = ($has_p && !$is_own);
            ?>
            <div class="slot-wrapper align-items-center">
                <div class="photo-slot" id="slot-<?php echo $item['item_id']; ?>" 
                     onclick="<?php echo $has_p ? "openImageViewer('../view_photo.php?path=".urlencode($p_path)."&t=".time()."', {$item['item_id']}, " . ($is_own ? 'true' : 'false') . ", 1)" : "startCapture({$item['item_id']}, 1)"; ?>">
                    <?php if ($has_p): ?>
                        <img src="../view_photo.php?path=<?php echo urlencode($p_path); ?>&t=<?php echo time(); ?>" class="saved-img">
                    <?php else: ?>
                        <i class="bi bi-camera text-primary opacity-50" style="font-size: 2rem;"></i>
                    <?php endif; ?>
                    
                    <div class="upload-overlay">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                    </div>
                    <div class="status-check">
                        <i class="bi <?php echo $is_oth ? 'bi-lock-fill' : 'bi-check-lg'; ?>"></i>
                    </div>
                </div>
                <?php if (!$has_p): ?>
                    <div class="mini-gallery-btn mt-1" style="width: 90px;" onclick="startGallery(<?php echo $item['item_id']; ?>, 1)">
                        <i class="bi bi-images"></i>갤러리
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- 심플 라이트박스 뷰어 -->
<div id="customLightbox" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.15); backdrop-filter:blur(4px); z-index:1050; align-items:center; justify-content:center; flex-direction:column; padding:20px;">
    <div style="position:relative; display:inline-block; max-width:100%; max-height:80vh;">
        <img id="lightboxImage" src="" style="max-width:100%; max-height:80vh; object-fit:contain; border-radius:12px; box-shadow:0 10px 40px rgba(0,0,0,0.5); cursor:pointer;" onclick="closeLightbox()">
        <button id="deletePhotoBtn" class="btn btn-danger position-absolute" style="bottom:-60px; left:50%; transform:translateX(-50%); white-space:nowrap; border-radius:30px; padding:10px 24px; font-weight:bold; box-shadow:0 4px 15px rgba(0,0,0,0.3);"><i class="bi bi-trash me-2"></i>사진 삭제 및 재촬영</button>
    </div>
</div>

<!-- 커스텀 Alert 모달 -->
<div class="modal fade" id="customAlertModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
  <div class="modal-dialog modal-dialog-centered px-4">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <div class="modal-body text-center p-4">
        <i class="bi bi-info-circle text-primary mb-3" style="font-size: 2.5rem; display: block;"></i>
        <p id="customAlertMessage" class="mb-4 text-dark" style="font-size: 1.05rem; word-break: keep-all;"></p>
        <button type="button" class="btn btn-primary w-100 rounded-pill py-2" data-bs-dismiss="modal">확인</button>
      </div>
    </div>
  </div>
</div>

<!-- 커스텀 Confirm 모달 -->
<div class="modal fade" id="customConfirmModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
  <div class="modal-dialog modal-dialog-centered px-4">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <div class="modal-body text-center p-4">
        <i class="bi bi-exclamation-triangle text-warning mb-3" style="font-size: 2.5rem; display: block;"></i>
        <p id="customConfirmMessage" class="mb-4 text-dark" style="font-size: 1.05rem; word-break: keep-all;"></p>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-light flex-fill rounded-pill py-2" data-bs-dismiss="modal">취소</button>
            <button type="button" id="customConfirmOkBtn" class="btn btn-danger flex-fill rounded-pill py-2">삭제하기</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- 메모 팝업 모달 -->
<div class="modal fade" id="memoModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
  <div class="modal-dialog modal-dialog-centered px-3">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <div class="modal-header border-bottom-0 pb-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-journal-text me-2 text-primary"></i>특이사항 메모</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 pt-2">
        <p class="text-muted small mb-3">해당 항목에 특이사항 이나 기입사항이 존재하면 기록해주세요</p>
        <textarea id="memoTextarea" class="form-control" rows="4" placeholder="내용을 입력하세요..." style="border-radius: 8px; resize: none;"></textarea>
        <div class="mt-4 d-flex gap-2">
            <button type="button" class="btn btn-light flex-fill rounded-pill py-2 fw-bold" data-bs-dismiss="modal">취소</button>
            <button type="button" id="saveMemoBtn" class="btn btn-primary flex-fill rounded-pill py-2 fw-bold">저장하기</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- footer.php 제거됨 -->

<!-- Bootstrap JS (모달 등 UI 컴포넌트 필수) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/sync_engine.js"></script>
<script>
let activeItemId = null;
let activePhotoIndex = 1;
const cameraInput = document.getElementById('cameraInput');
const galleryInput = document.getElementById('galleryInput');
let alertModalObj = null;
let confirmModalObj = null;

// 커스텀 Alert/Confirm 함수
function showAlert(message, onClose) {
    document.getElementById('customAlertMessage').innerHTML = message.replace(/\n/g, '<br>');
    if (!alertModalObj) alertModalObj = new bootstrap.Modal(document.getElementById('customAlertModal'));
    
    if (typeof onClose === 'function') {
        const modalEl = document.getElementById('customAlertModal');
        modalEl.addEventListener('hidden.bs.modal', function handler() {
            modalEl.removeEventListener('hidden.bs.modal', handler);
            onClose();
        });
    }
    
    alertModalObj.show();
}

function showConfirm(message, onConfirm) {
    document.getElementById('customConfirmMessage').innerHTML = message.replace(/\n/g, '<br>');
    if (!confirmModalObj) confirmModalObj = new bootstrap.Modal(document.getElementById('customConfirmModal'));
    
    const okBtn = document.getElementById('customConfirmOkBtn');
    // 기존 이벤트 리스너 제거 후 새로 등록
    const newOkBtn = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOkBtn, okBtn);
    
    newOkBtn.addEventListener('click', function() {
        confirmModalObj.hide();
        if (typeof onConfirm === 'function') onConfirm();
    });
    
    confirmModalObj.show();
}

// 컴팩트 뷰 토글 로직
const compactToggle = document.getElementById('compactModeToggle');
if (localStorage.getItem('compactMode') === 'true') {
    compactToggle.checked = true;
    document.body.classList.add('compact-completed');
}
compactToggle.addEventListener('change', function() {
    if (this.checked) {
        document.body.classList.add('compact-completed');
        localStorage.setItem('compactMode', 'true');
    } else {
        document.body.classList.remove('compact-completed');
        localStorage.setItem('compactMode', 'false');
    }
});

// 1. 입력 호출 전 동시성 체크 (선점 방지)
async function checkItemStatusAndProceed(itemId, photoIndex, proceedCallback) {
    try {
        const formData = new FormData();
        formData.append('item_id', itemId);
        formData.append('platform_id', '<?php echo $platform_id; ?>');
        formData.append('photo_index', photoIndex);
        
        const response = await fetch('check_item_status.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success && result.is_filled && !result.is_owner) {
            showAlert(result.message + "\n최신 상태로 화면을 갱신합니다.", () => {
                location.reload();
            });
        } else {
            proceedCallback();
        }
    } catch (e) {
        proceedCallback(); 
    }
}

function startCapture(itemId, photoIndex = 1) {
    checkItemStatusAndProceed(itemId, photoIndex, () => {
        activeItemId = itemId;
        activePhotoIndex = photoIndex;
        cameraInput.click();
    });
}
function startGallery(itemId, photoIndex = 1) {
    checkItemStatusAndProceed(itemId, photoIndex, () => {
        activeItemId = itemId;
        activePhotoIndex = photoIndex;
        galleryInput.click();
    });
}

// 2. 촬영 및 업로드 로직 (공통 핸들러)
async function handlePhotoUpload(e) {
    const file = e.target.files[0];
    if (!file || !activeItemId) return;

    const itemId = activeItemId;
    const photoIndex = activePhotoIndex;
    const card = document.getElementById(`card-${itemId}`);
    
    try {
        card.classList.add('uploading');
        
        const optimizedBlob = await optimizeImage(file);
        
        const formData = new FormData();
        formData.append('photo', optimizedBlob, 'capture.jpg');
        formData.append('item_id', itemId);
        formData.append('platform_id', '<?php echo $platform_id; ?>');
        formData.append('photo_index', photoIndex);

        const response = await fetch('api_upload_router.php', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            location.reload();
        } else {
            throw new Error(result.message);
        }
    } catch (err) {
        showAlert('전송 오류: ' + err.message + '\n다시 시도해 주세요.');
        location.reload();
    } finally {
        e.target.value = '';
        activeItemId = null;
    }
}

cameraInput.onchange = handlePhotoUpload;
galleryInput.onchange = handlePhotoUpload;

// 3. 라이트박스 뷰어 및 삭제 로직
let currentViewerItemId = null;
let currentViewerPhotoIndex = 1;

function openImageViewer(url, itemId, isOwner, photoIndex = 1) {
    const lb = document.getElementById('customLightbox');
    const img = document.getElementById('lightboxImage');
    const delBtn = document.getElementById('deletePhotoBtn');
    
    img.src = url;
    currentViewerItemId = itemId;
    currentViewerPhotoIndex = photoIndex;
    
    // 소유자일 경우에만 삭제 버튼 노출
    if (isOwner) {
        delBtn.style.display = 'block';
    } else {
        delBtn.style.display = 'none';
    }
    
    lb.style.display = 'flex';
    // 스크롤 방지
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    const lb = document.getElementById('customLightbox');
    lb.style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('deletePhotoBtn').addEventListener('click', function() {
    if (!currentViewerItemId) return;
    const btn = this;
    
    // 삭제 모달이 뒤로 숨는 문제 해결: 라이트박스를 먼저 닫음
    closeLightbox();
    
    showConfirm("정말 사진을 삭제하시겠습니까?", async function() {
        btn.disabled = true;
        
        try {
            const formData = new FormData();
            formData.append('item_id', currentViewerItemId);
            formData.append('platform_id', '<?php echo $platform_id; ?>');
            formData.append('photo_index', currentViewerPhotoIndex);

            const response = await fetch('delete_photo_proc.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                location.reload();
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            showAlert('삭제 오류: ' + error.message);
            btn.disabled = false;
        }
    });
});

let currentMemoItemId = null;
let memoModalObj = null;

function openMemoModal(itemId, existingText) {
    currentMemoItemId = itemId;
    document.getElementById('memoTextarea').value = existingText;
    if (!memoModalObj) memoModalObj = new bootstrap.Modal(document.getElementById('memoModal'));
    memoModalObj.show();
}

document.getElementById('saveMemoBtn').addEventListener('click', async function() {
    if (!currentMemoItemId) return;
    const btn = this;
    const memoText = document.getElementById('memoTextarea').value;
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 저장중...';
    
    try {
        const formData = new FormData();
        formData.append('const_id', '<?php echo $platform['const_id'] ?? 0; ?>');
        formData.append('site_id', '<?php echo $platform['site_id'] ?? 0; ?>');
        formData.append('platform_id', '<?php echo $platform_id; ?>');
        formData.append('item_id', currentMemoItemId);
        formData.append('memo_text', memoText);

        const response = await fetch('api_save_memo.php', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            memoModalObj.hide();
            location.reload(); // 새로고침해서 아이콘 상태 반영
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        showAlert('저장 오류: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '저장하기';
    }
});
</script>

</body>
</html>
