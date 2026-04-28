<?php
// 1. 보안 토큰 설정
$access_token = "018ad578c4f708e4_SubwayPlatformReWork";
if ($_GET['token'] !== $access_token) {
    header('HTTP/1.0 403 Forbidden');
    die("Access Denied.");
}

// 2. db.php 로드 및 접속 정보 추출
require_once "../inc/db.php";

// getenv를 통해 환경변수에서 정보를 직접 가져옵니다. (db.php 내부 로직과 동일)
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'korailpform';
$pass = getenv('DB_PASS') ?: 'zx2646zx!';
$name = getenv('DB_NAME') ?: 'korailpform';

// 3. 백업 경로 설정 (상대 경로 대신 절대 경로 권장)
$root_path = $_SERVER['DOCUMENT_ROOT'];
$backup_dir = $root_path . "/AutoBack/"; // www/AutoBack 폴더로 고정

// AutoBack 폴더가 없으면 생성
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0707, true);
}

$date = date("Ymd_His");
$sql_file = $backup_dir . "db_backup_" . $date . ".sql";
$zip_file = $backup_dir . "db_backup_" . $date . ".zip";

// 4. 오래된 파일 삭제 (10일 경과)
$files = glob($backup_dir . "*.zip");
foreach ($files as $file) {
    if (is_file($file) && (time() - filemtime($file) > (10 * 86400))) {
        unlink($file);
    }
}

// 5. DB 덤프 실행 (비밀번호를 따옴표로 감싸서 공백/특수문자 에러 방지)
// 2>&1을 추가하여 에러 발생 시 상세 내용을 볼 수 있게 합니다.
$command = "mysqldump -h $host -u $user -p'$pass' $name > $sql_file 2>&1";
exec($command, $output, $dump_output);

if ($dump_output === 0 && file_exists($sql_file)) {
    // 6. ZIP 압축 및 비밀번호 설정
    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE) === TRUE) {
        $zip->addFile($sql_file, basename($sql_file));

        // PHP 7.2 이상 암호화 지원 확인
        if (method_exists($zip, 'setEncryptionName')) {
            $zip->setEncryptionName(basename($sql_file), ZipArchive::EM_AES_256, $access_token);
        }

        $zip->close();
        unlink($sql_file); // 원본 SQL 삭제
        echo basename($zip_file);
    } else {
        echo "Zip Failed";
    }
} else {
    // 실패 시 상세 이유 출력
    echo "Dump Failed. Error Code: " . $dump_output . "\n";
    echo "Reason: " . implode("\n", $output);
}
?>