<?php
// CRONTAB: Add this line to run daily at 2AM:
// 0 2 * * * php /public_html/heart/cron/expire_stories.php

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/helpers/Database.php';

$db = getDB();

$stmt = $db->prepare("SELECT id, media_url FROM stories WHERE expires_at < NOW()");
$stmt->execute();
$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

$deletedCount = 0;

foreach ($expired as $story) {
    $filePath = dirname(__DIR__, 2) . $story['media_url'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    $del = $db->prepare("DELETE FROM stories WHERE id = ?");
    $del->execute([$story['id']]);
    $deletedCount++;
}

if ($deletedCount > 0) {
    echo "Deleted $deletedCount expired stories at " . date('Y-m-d H:i:s') . "\n";
} else {
    echo "No expired stories found at " . date('Y-m-d H:i:s') . "\n";
}
