<?php
require_once '../inc/db_config.php';
session_start();

$role = $_SESSION['role_type'] ?? '';
if (!$role || ($role !== 'Admin' && $role !== 'SuperAdmin')) {
    header('Location: ./index.php');
    exit;
}

$id = $_GET['id'] ?? '';
$default_role = $_GET['role'] ?? 'Worker';
$mode = $id ? 'edit' : 'add';
$item = null;

if ($mode == 'edit') {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE item_id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    if (!$item) {
        header("Location: items.php?msg=" . urlencode('존재하지 않는 항목입니다.'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KORAIL Admin - <?php echo $mode == 'add' ? '항목 추가' : '항목 수정'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        .admin-sidebar { background: #ffffff; border-right: 1px solid #e2e8f0; min-height: 100vh; padding: 2rem 1.5rem; }
        .admin-content { padding: 2.5rem; }
        .section-card { background: white; border-radius: 1rem; padding: 2rem; border: 1px solid #e2e8f0; max-width: 600px; }
        .form-label { font-weight: 600; color: #475569; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <?php include_once 'inc/sidebar.php'; ?>

        <div class="col-md-10 admin-content">
            <header class="mb-5">
                <a href="items.php?role=<?php echo $item ? h($item['role_type']) : h($default_role); ?>" class="text-decoration-none small text-muted mb-2 d-inline-block">
                    <i class="bi bi-arrow-left"></i> 목록으로 돌아가기
                </a>
                <h2 class="fw-bold text-dark"><?php echo $mode == 'add' ? '신규 사진 항목 등록' : '사진 항목 수정'; ?></h2>
            </header>

            <div class="section-card shadow-sm">
                <form action="item_proc.php" method="POST">
                    <input type="hidden" name="mode" value="<?php echo $mode; ?>">
                    <?php if ($id): ?>
                        <input type="hidden" name="item_id" value="<?php echo h($id); ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">권한 구분</label>
                        <select name="role_type" id="role_type" class="form-select" required onchange="loadCategories()">
                            <option value="Safety" <?php echo (($item ? $item['role_type'] : $default_role) == 'Safety') ? 'selected' : ''; ?>>안전관리 (Safety)</option>
                            <option value="Worker" <?php echo (($item ? $item['role_type'] : $default_role) == 'Worker') ? 'selected' : ''; ?>>작업자 (Worker)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">카테고리 선택</label>
                        <div class="input-group">
                            <select id="category_select" class="form-select" onchange="onCategorySelectChange()">
                                <option value="">-- 카테고리 선택 --</option>
                                <option value="__new__">+ 직접 입력 (새 카테고리)</option>
                            </select>
                            <input type="text" name="category_name" id="category_input" class="form-control d-none" value="<?php echo $item ? h($item['category_name']) : ''; ?>" placeholder="카테고리명 입력">
                        </div>
                    </div>

                    <div id="existing_items_container" class="mb-4 d-none">
                        <label class="form-label">기존 항목 리스트 (삽입 위치 선택)</label>
                        <div class="list-group mb-2 border rounded shadow-sm overflow-auto" style="max-height: 200px;" id="item_list">
                            <!-- 항목 리스트가 여기에 동적으로 로드됨 -->
                        </div>
                        <p class="text-muted x-small mb-0">
                            <i class="bi bi-info-circle me-1"></i> 리스트에서 선택하면 그 밑으로 항목이 생성됩니다.<br>
                            <i class="bi bi-info-circle me-1"></i> 체크하지 않으면 맨 마지막에 생성됩니다.
                        </p>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">촬영 항목명 (상세 공정 명칭)</label>
                        <input type="text" name="item_name" class="form-control" value="<?php echo $item ? h($item['item_name']) : ''; ?>" required placeholder="항목명 입력">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">촬영 사진 개수</label>
                        <div class="input-group" style="max-width: 150px;">
                            <input type="number" name="photo_count" class="form-control" value="<?php echo $item ? (int)$item['photo_count'] : 1; ?>" min="1" max="10" required>
                            <span class="input-group-text">장</span>
                        </div>
                        <p class="text-muted x-small mt-1 mb-0">
                            <i class="bi bi-info-circle me-1"></i> 해당 공정에서 촬영해야 할 사진의 총 개수입니다.
                        </p>
                    </div>

                    <input type="hidden" name="target_item_id" id="target_item_id" value="">
                    <input type="hidden" name="insert_pos" id="insert_pos" value="last">

                    <?php if ($mode == 'edit'): ?>
                    <div class="mb-4">
                        <label class="form-label d-block text-muted small mb-2">항목 삭제 가이드</label>
                        <p class="text-muted" style="font-size: 0.8rem;">
                            이미 촬영 기록이 있는 항목은 삭제 대신 '숨김' 처리를 권장합니다.
                            항목을 삭제하면 해당 항목과 연결된 모든 사진 로그가 데이터 무결성 오류를 일으킬 수 있습니다.
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2 border-top pt-4 mt-2">
                        <button type="submit" class="btn btn-primary py-2 fw-bold">
                            <?php echo $mode == 'add' ? '항목 등록 완료' : '정보 수정 저장'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
async function loadCategories() {
    const role = document.getElementById('role_type').value;
    const select = document.getElementById('category_select');
    const currentCategory = "<?php echo $item ? h($item['category_name']) : ''; ?>";
    
    // 초기화
    select.innerHTML = '<option value="">-- 카테고리 선택 --</option><option value="__new__">+ 직접 입력 (새 카테고리)</option>';
    
    try {
        const res = await fetch(`item_api.php?mode=get_categories&role=${role}`);
        const categories = await res.json();
        
        categories.forEach(cat => {
            const opt = document.createElement('option');
            opt.value = cat;
            opt.textContent = cat;
            if (cat === currentCategory) opt.selected = true;
            select.appendChild(opt);
        });

        // 수정 모드이거나 카테고리가 이미 있으면 리스트 로드
        if (select.value && select.value !== '__new__') {
            onCategorySelectChange();
        }
    } catch (e) { console.error(e); }
}

function onCategorySelectChange() {
    const select = document.getElementById('category_select');
    const input = document.getElementById('category_input');
    
    if (select.value === '__new__') {
        input.classList.remove('d-none');
        input.required = true;
        input.value = '';
        document.getElementById('existing_items_container').classList.add('d-none');
    } else {
        input.classList.add('d-none');
        input.required = false;
        input.value = select.value;
        if (select.value !== '') {
            loadItems(select.value);
        } else {
            document.getElementById('existing_items_container').classList.add('d-none');
        }
    }
}

async function loadItems(category) {
    const role = document.getElementById('role_type').value;
    const container = document.getElementById('existing_items_container');
    const list = document.getElementById('item_list');
    const currentItemId = "<?php echo $id; ?>";
    
    try {
        const res = await fetch(`item_api.php?mode=get_items&role=${role}&category=${encodeURIComponent(category)}`);
        const items = await res.json();
        
        list.innerHTML = '';
        
        // "가장 처음에 삽입" 옵션
        addItemToList('__first__', '--- 가장 처음에 삽입 ---', false);
        
        items.forEach(it => {
            if (it.item_id != currentItemId) {
                addItemToList(it.item_id, it.item_name, false);
            }
        });
        
        // "마지막에 삽입" 옵션 (기본값)
        addItemToList('__last__', '--- 가장 마지막에 삽입 ---', true);
        
        container.classList.remove('d-none');
    } catch (e) { console.error(e); }
}

function addItemToList(id, name, isDefault) {
    const list = document.getElementById('item_list');
    const item = document.createElement('button');
    item.type = 'button';
    item.className = 'list-group-item list-group-item-action py-2 small';
    if (id === '__first__' || id === '__last__') {
        item.classList.add('bg-light', 'text-center', 'fw-bold', 'text-primary');
    }
    
    item.innerHTML = `<i class="bi bi-dot me-1"></i> ${name}`;
    if (isDefault) {
        item.classList.add('active');
        document.getElementById('target_item_id').value = id;
        document.getElementById('insert_pos').value = (id === '__first__') ? 'first' : 'last';
    }
    
    item.onclick = () => {
        // 모든 항목 active 제거
        Array.from(list.children).forEach(child => child.classList.remove('active'));
        item.classList.add('active');
        
        if (id === '__first__' || id === '__last__') {
            document.getElementById('target_item_id').value = id;
            document.getElementById('insert_pos').value = (id === '__first__') ? 'first' : 'last';
        } else {
            document.getElementById('target_item_id').value = id;
            document.getElementById('insert_pos').value = 'after';
        }
    };
    
    list.appendChild(item);
}

// 초기 로드
window.onload = loadCategories;
</script>
