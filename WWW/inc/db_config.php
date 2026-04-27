<?php
/**
 * KORAIL 승강장 공정 관리 시스템 DB 연결 설정
 * PDO를 이용한 MariaDB(MySQL) 연결
 */

$host = '192.168.0.2';
$db   = 'KORAIL';
$user = 'root';
$pass = 'ZX2646zx!#';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // 실제 운영 환경에서는 에러 메시지를 상세히 노출하지 않도록 주의합니다.
    die("데이터베이스 연결 실패: " . $e->getMessage());
}

/**
 * 보안을 위한 HTML 특수문자 이스케이프 함수
 */
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
