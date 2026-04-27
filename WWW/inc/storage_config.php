<?php
/**
 * KORAIL 사진 저장소 설정 파일 (Storage Configuration)
 * Cloudflare R2 또는 NAS FTP 중 선택하여 사용 가능
 */

return [
    // 활성화된 저장소 서비스 선택 ('R2' | 'NAS_FTP' | 'LOCAL')
    'ACTIVE_STORAGE' => 'LOCAL', 

    // Cloudflare R2 설정
    'R2' => [
        'ACCOUNT_ID'    => '',
        'ACCESS_KEY_ID' => '',
        'SECRET_ACCESS_KEY' => '',
        'BUCKET_NAME'   => 'korail-photos',
        'ENDPOINT_URL'  => '', // 예: https://<account_id>.r2.cloudflarestorage.com
        'PUBLIC_URL'    => '', // R2 도메인 연결 시
    ],

    // NAS FTP 설정
    'NAS_FTP' => [
        'HOST' => '192.168.0.2',
        'USER' => 'root',
        'PASS' => 'ZX2646zx!#',
        'PORT' => 21,
        'ROOT' => '/WWW/upload_photos/',
    ],

    // 로컬 서버 저장 설정 (기본값)
    'LOCAL' => [
        'UPLOAD_PATH' => $_SERVER['DOCUMENT_ROOT'] . '/upload_photos/',
        'URL_PATH'    => '/upload_photos/',
    ]
];
