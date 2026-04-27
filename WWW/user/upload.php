<?php
require_once '../inc/db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$item_id = $_GET['item_id'] ?? '';
$platform_id = $_GET['platform_id'] ?? '';

if (!$item_id || !$platform_id) {
    echo "<script>alert('잘못된 접근입니다.'); history.back();</script>";
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT i.*, p.platform_name, s.site_name 
                           FROM items i 
                           CROSS JOIN platforms p 
                           JOIN sites s ON p.site_id = s.site_id
                           WHERE i.item_id = ? AND p.platform_id = ?");
    $stmt->execute([$item_id, $platform_id]);
    $data = $stmt->fetch();
} catch (Exception $e) { $data = null; }

if (!$data) {
    echo "<script>alert('정보를 찾을 수 없습니다.'); history.back();</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <title>KORAIL - 사진 업로드</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --korail-blue: #00529b; }
        body { background-color: #0f172a; color: white; height: 100vh; display: flex; flex-direction: column; overflow: hidden; font-family: 'Pretendard', sans-serif; }
        
        /* 헤더 */
        .upload-header { padding: 1.25rem 1rem; background: rgba(15, 23, 42, 0.9); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(255,255,255,0.1); }
        .item-info-top { font-size: 0.8rem; color: rgba(255,255,255,0.6); margin-bottom: 2px; }
        .item-name-top { font-size: 1.15rem; font-weight: 800; color: white; }

        /* 프리뷰 영역 */
        .preview-container { flex-grow: 1; display: flex; align-items: center; justify-content: center; position: relative; background: #020617; }
        #photoPreview { max-width: 100%; max-height: 100%; object-fit: contain; display: none; }
        .preview-placeholder { text-align: center; color: rgba(255,255,255,0.2); }
        .preview-placeholder i { font-size: 5rem; display: block; margin-bottom: 1rem; }

        /* 컨트롤 영역 */
        .controls-section { padding: 2rem 1.5rem; background: linear-gradient(to top, #0f172a 0%, rgba(15, 23, 42, 0.8) 100%); }
        .btn-capture { background: #2563eb; color: white; border: none; padding: 1.25rem; border-radius: 1.5rem; width: 100%; font-weight: 800; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 10px 25px rgba(37,99,235,0.3); margin-bottom: 1rem; }
        .btn-gallery { background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(255,255,255,0.2); padding: 1rem; border-radius: 1.25rem; width: 100%; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px; }
        
        /* 업로드 오버레이 */
        #uploadOverlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.85); display: none; flex-direction: column; align-items: center; justify-content: center; z-index: 2000; backdrop-filter: blur(5px); }
        .loader { width: 48px; height: 48px; border: 5px solid #FFF; border-bottom-color: #2563eb; border-radius: 50%; display: inline-block; box-sizing: border-box; animation: rotation 1s linear infinite; margin-bottom: 20px; }
        @keyframes rotation { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .upload-text { font-weight: 700; font-size: 1.1rem; color: white; text-align: center; }
        .upload-subtext { font-size: 0.85rem; color: rgba(255,255,255,0.5); margin-top: 8px; }
    </style>
</head>
<body>

<div class="upload-header d-flex align-items-center">
    <a href="work_list.php?platform_id=<?php echo $platform_id; ?>" class="text-white me-3"><i class="bi bi-x-lg fs-4"></i></a>
    <div>
        <div class="item-info-top"><?php echo h($data['site_name']); ?> > <?php echo h($data['platform_name']); ?></div>
        <div class="item-name-top"><?php echo h($data['item_name']); ?></div>
    </div>
</div>

<div class="preview-container">
    <div id="placeholder" class="preview-placeholder">
        <i class="bi bi-camera"></i>
        <span>현장 사진을 촬영해주세요</span>
    </div>
    <img id="photoPreview" alt="미리보기">
</div>

<div class="controls-section">
    <!-- 실제 파일 입력 (숨김) -->
    <input type="file" id="cameraInput" accept="image/*" capture="environment" style="display: none;">
    <input type="file" id="galleryInput" accept="image/*" style="display: none;">

    <button type="button" class="btn-capture" onclick="document.getElementById('cameraInput').click()">
        <i class="bi bi-camera-fill"></i> 현장 사진 촬영
    </button>
    <button type="button" class="btn-gallery" onclick="document.getElementById('galleryInput').click()">
        <i class="bi bi-images"></i> 갤러리에서 선택
    </button>
</div>

<!-- 업로드 중 오버레이 -->
<div id="uploadOverlay">
    <span class="loader"></span>
    <div class="upload-text">사진 전송 중...</div>
    <div class="upload-subtext">현장의 안전한 기록을 저장하고 있습니다.</div>
</div>

<script src="js/sync_engine.js"></script>
<script>
const cameraInput = document.getElementById('cameraInput');
const galleryInput = document.getElementById('galleryInput');
const photoPreview = document.getElementById('photoPreview');
const placeholder = document.getElementById('placeholder');
const uploadOverlay = document.getElementById('uploadOverlay');
const uploadText = document.querySelector('.upload-text');

async function handleFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;

    // 1. UI 업데이트 (미리보기)
    const reader = new FileReader();
    reader.onload = async function(e) {
        photoPreview.src = e.target.result;
        photoPreview.style.display = 'block';
        placeholder.style.display = 'none';
        
        // 2. 다이렉트 업로드 시작
        uploadOverlay.style.display = 'flex';
        uploadText.innerText = '사진 최적화 및 전송 중...';
        
        try {
            // [리사이징] sync_engine.js의 optimizeImage 함수 활용
            const optimizedBlob = await optimizeImage(file);
            
            const formData = new FormData();
            formData.append('photo', optimizedBlob, 'photo.jpg');
            formData.append('item_id', '<?php echo $item_id; ?>');
            formData.append('platform_id', '<?php echo $platform_id; ?>');

            // [다이렉트 전송]
            const response = await fetch('upload_proc.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                uploadText.innerText = '전송 완료!';
                setTimeout(() => {
                    location.href = 'work_list.php?platform_id=<?php echo $platform_id; ?>';
                }, 500);
            } else {
                throw new Error(result.message);
            }
            
        } catch (error) {
            alert('업로드 오류: ' + error.message);
            uploadOverlay.style.display = 'none';
        }
    };
    reader.readAsDataURL(file);
}

cameraInput.onchange = handleFileSelect;
galleryInput.onchange = handleFileSelect;
</script>

</body>
</html>
