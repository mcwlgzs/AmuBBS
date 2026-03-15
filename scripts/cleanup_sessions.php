#!/usr/bin/env php
<?php
/**
 * 定时任务：清理过期会话
 * 建议通过 crontab 每小时执行一次：
 * 0 * * * * /usr/bin/php /path/to/amubbs/scripts/cleanup_sessions.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Services\SessionCleanupSvc;

echo "[" . date('Y-m-d H:i:s') . "] Starting session cleanup...\n";

// 清理 2 小时未活动的会话
$count = SessionCleanupSvc::cleanExpiredSessions(7200);
echo "Cleaned {$count} expired sessions.\n";

// 获取会话统计
$stats = SessionCleanupSvc::getSessionStats();
echo "Session stats: Total={$stats['total']}, Active={$stats['active']}, LoggedIn={$stats['logged_in']}, Guest={$stats['guest']}\n";

echo "[" . date('Y-m-d H:i:s') . "] Session cleanup completed.\n";
