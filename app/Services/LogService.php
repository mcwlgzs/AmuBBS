<?php
/**
 * 操作日志服务
 */

namespace App\Services;

use App\Models\Log;
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
            Log::write(
                $userId,
                $action,
                $targetType,
                $targetId,
                !empty($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['REMOTE_ADDR'] ?? '',
                time()
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
        self::log(Log::MOD_PREFIX . $action, $targetType, $targetId, array_merge($detail, [
            'operator_id' => $userId,
        ]), $userId);
    }

    /**
     * 获取版主操作日志（支持筛选）
     */
    public static function getModLogs(int $page = 1, int $perPage = 30, array $filters = []): array
    {
        return Log::modPage($filters, $page, $perPage);
    }

    /**
     * 获取版主操作日志总数（支持筛选）
     */
    public static function countModLogs(array $filters = []): int
    {
        return Log::countMod($filters);
    }

    /**
     * 获取所有版主操作类型（用于筛选下拉）
     */
    public static function getModActionTypes(): array
    {
        return Cache::get('logs:mod_action_types', function () {
            return Log::modActionTypes();
        }, 300);
    }

    /**
     * 清理过期日志（保留指定天数，分批删除防止锁表）
     */
    public static function cleanOldLogs(int $keepDays = 90): int
    {
        return Log::pruneBefore(time() - ($keepDays * 86400));
    }
}
