<?php
require_once __DIR__ . '/inc/db_config.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS item_memos (
        memo_id INT AUTO_INCREMENT PRIMARY KEY,
        const_id INT NOT NULL,
        site_id INT NOT NULL,
        platform_id INT NOT NULL,
        item_id INT NOT NULL,
        memo_text TEXT,
        memo_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        user_id VARCHAR(50),
        UNIQUE KEY idx_plat_item_date (platform_id, item_id, memo_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $pdo->exec($sql);
    echo "Table created successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
