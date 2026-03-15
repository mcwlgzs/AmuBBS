<?php
/**
 * 操作日志服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class LogService
{
    /**
     * 记录操作日志
     */
    public static function log(string $action, string $targetType = '', int $targetId = 0, array $detail = [], int $userId = 0): void
    {
        if ($userId === 0) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
        }

        try {
            Database::execute(
                "INSERT INTO logs (user_id, action, target_type, target_id, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $action,
                    $targetType,
                    $targetId,
                    !empty($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    time(),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[LogService] log failed: ' . $e->getMessage());
        }
    }

    /**
     * 记录版主操作日志（使用 mod_ 前缀）
     */
    public static function logModAction(string $action, int $userId, string $targetType, int $targetId, array $detail = []): void
    {
        self::log('mod_' . $action, $targetType, $targetId, array_merge($detail, [
            'operator_id' => $userId,
        ]), $userId);
    }

    /**
     * 获取版主操作日志（支持筛选）
     */
    public static function getModLogs(int $page = 1, int $perPage = 30, array $filters = []): array
    {
        $where = "WHERE l.action LIKE 'mod_%'";
        $params = [];

        if (!empty($filters['action'])) {
            $where .= " AND l.action = ?";
            $params[] = $filters['action'];
        }
        if (!empty($filters['user_id'])) {
            $where .= " AND l.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['target_type'])) {
            $where .= " AND l.target_type = ?";
            $params[] = $filters['target_type'];
        }
        if (!empty($filters['date_from'])) {
            $ts = strtotime($filters['date_from']);
            if ($ts) { $where .= " AND l.created_at >= ?"; $params[] = $ts; }
        }
        if (!empty($filters['date_to'])) {
            $ts = strtotime($filters['date_to'] . ' 23:59:59');
            if ($ts) { $where .= " AND l.created_at <= ?"; $params[] = $ts; }
        }

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $params[] = $perPage;
        $params[] = $offset;

        return Database::fetchAll(
            "SELECT l.*, u.username as operator_name FROM logs l
             LEFT JOIN users u ON l.user_id = u.id
             {$where}
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /**
     * 获取版主操作日志总数（支持筛选）
     */
    public static function countModLogs(array $filters = []): int
    {
        $where = "WHERE action LIKE 'mod_%'";
        $params = [];

        if (!empty($filters['action'])) {
            $where .= " AND action = ?";
            $params[] = $filters['action'];
        }
        if (!empty($filters['user_id'])) {
            $where .= " AND user_id = ?";
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['target_type'])) {
            $where .= " AND target_type = ?";
            $params[] = $filters['target_type'];
        }
        if (!empty($filters['date_from'])) {
            $ts = strtotime($filters['date_from']);
            if ($ts) { $where .= " AND created_at >= ?"; $params[] = $ts; }
        }
        if (!empty($filters['date_to'])) {
            $ts = strtotime($filters['date_to'] . ' 23:59:59');
            if ($ts) { $where .= " AND created_at <= ?"; $params[] = $ts; }
        }

        $row = Database::fetchOne("SELECT COUNT(*) as c FROM logs {$where}", $params);
        return (int)($row['c'] ?? 0);
    }

    /**
     * 获取所有版主操作类型（用于筛选下拉）
     */
    public static function getModActionTypes(): array
    {
        return Cache::get('logs:mod_action_types', function () {
            return Database::fetchAll(
                "SELECT DISTINCT action FROM logs WHERE action LIKE 'mod_%' ORDER BY action"
            );
        }, 300);
    }

    /**
     * 清理过期日志（保留指定天数，分批删除防止锁表）
     */
    public static function cleanOldLogs(int $keepDays = 90): int
    {
        $threshold = time() - ($keepDays * 86400);
        $total = 0;
        do {
            $affected = Database::execute(
                "DELETE FROM logs WHERE created_at < ? LIMIT 5000",
                [$threshold]
            );
            $total += $affected;
        } while ($affected >= 5000);
        return $total;
    }
}
