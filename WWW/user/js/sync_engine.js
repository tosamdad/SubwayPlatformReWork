/**
 * Korail Sync Engine v1.2 (Deadlock-Free)
 */

const DB_NAME = 'KorailPhotoDB';
const DB_VERSION = 1;
const STORE_NAME = 'photo_queue';

let isQueueProcessing = false;

// 1. IndexedDB 초기화
async function initDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                const store = db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
                store.createIndex('status', 'status', { unique: false });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

// 2. 이미지 최적화 (기존 동일)
async function optimizeImage(file, maxWidth = 1024, quality = 0.8) {
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = (e) => {
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                let width = img.width;
                let height = img.height;
                if (width > maxWidth) {
                    height = (height / width) * maxWidth;
                    width = maxWidth;
                }
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);
                canvas.toBlob((blob) => resolve(blob), 'image/jpeg', quality);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
}

// 3. 대기열 추가
async function addToQueue(file, metadata) {
    const db = await initDB();
    const blob = await optimizeImage(file);
    return new Promise((resolve, reject) => {
        const tx = db.transaction([STORE_NAME], 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        const entry = { ...metadata, blob, status: 'pending', timestamp: Date.now() };
        const req = store.add(entry);
        req.onsuccess = () => {
            processQueue();
            resolve(req.result);
        };
        req.onerror = () => reject(req.error);
    });
}

// 4. [개편] 루프 기반 전송 엔진
async function processQueue() {
    if (isQueueProcessing) return;
    isQueueProcessing = true;

    try {
        const db = await initDB();
        
        // 4-1. 멈춘 상태 복구
        await resetStuckUploads(db);

        // 4-2. 무한 루프를 통해 하나씩 처리
        while (true) {
            const item = await getNextItem(db);
            if (!item) break;

            console.log(`전송 시도 중: ${item.id}`);
            const success = await uploadToServer(item);

            if (success) {
                await deleteItem(db, item.id);
                console.log(`전송 및 삭제 완료: ${item.id}`);
            } else {
                console.error(`전송 실패, 다음 주기에 재시도: ${item.id}`);
                await setStatus(db, item.id, 'pending');
                break; // 실패 시 루프 중단하고 나중에 다시 시작
            }
        }
    } catch (err) {
        console.error('Queue Processor Error:', err);
    } finally {
        isQueueProcessing = false;
    }
}

// 헬퍼 함수들
async function resetStuckUploads(db) {
    return new Promise((resolve) => {
        const tx = db.transaction([STORE_NAME], 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        const index = store.index('status');
        const req = index.openCursor(IDBKeyRange.only('uploading'));
        req.onsuccess = (e) => {
            const cursor = e.target.result;
            if (cursor) {
                const item = cursor.value;
                item.status = 'pending';
                cursor.update(item);
                cursor.continue();
            } else { resolve(); }
        };
    });
}

async function getNextItem(db) {
    return new Promise((resolve) => {
        const tx = db.transaction([STORE_NAME], 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        const index = store.index('status');
        const req = index.get(IDBKeyRange.only('pending'));
        req.onsuccess = () => {
            if (req.result) {
                const item = req.result;
                item.status = 'uploading';
                store.put(item);
                tx.oncomplete = () => resolve(item);
            } else { resolve(null); }
        };
    });
}

async function deleteItem(db, id) {
    return new Promise((resolve) => {
        const tx = db.transaction([STORE_NAME], 'readwrite');
        tx.objectStore(STORE_NAME).delete(id);
        tx.oncomplete = () => resolve();
    });
}

async function setStatus(db, id, status) {
    return new Promise((resolve) => {
        const tx = db.transaction([STORE_NAME], 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        const req = store.get(id);
        req.onsuccess = () => {
            const item = req.result;
            if (item) {
                item.status = status;
                store.put(item);
            }
            tx.oncomplete = () => resolve();
        };
    });
}

/**
 * [추가] 대기열 전체 삭제
 */
async function clearQueue() {
    if (!confirm('전송 대기 중인 모든 사진을 삭제하시겠습니까?\n서버에 업로드되지 않은 사진은 복구할 수 없습니다.')) return;
    
    const db = await initDB();
    return new Promise((resolve) => {
        const tx = db.transaction([STORE_NAME], 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        const req = store.clear();
        req.onsuccess = () => {
            console.log('대기열 삭제 완료');
            location.reload();
            resolve();
        };
    });
}

async function uploadToServer(item) {
    const formData = new FormData();
    formData.append('photo', item.blob, `upload_${item.id}.jpg`);
    formData.append('item_id', item.item_id);
    formData.append('platform_id', item.platform_id);
    
    try {
        const response = await fetch('api_upload_router.php', { method: 'POST', body: formData });
        
        // 서버 응답 자체가 실패(404, 500 등)인 경우에만 false 반환하여 재시도
        if (!response.ok) return false;
        
        const result = await response.json();
        
        // [중요] DB 기록 실패(success: false)이더라도 파일은 이미 서버에 저장된 상태라면
        // 대기열에서 삭제하여 무한 반복을 막음 (작업자 편의 우선)
        if (!result.success && result.message.includes('DB')) {
            console.warn('DB 기록 실패했으나 파일은 저장됨:', result.message);
            return true; 
        }
        
        return result.success;
    } catch (e) { 
        console.error('Network/JSON Error:', e);
        return false; 
    }
}
