<?php
/**
 * 等级服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class LevelSvc
{
    /**
     * 根据积分获取用户等级（从缓存的等级列表中查找，避免逐积分缓存）
     */
    public static function getLevelByCredits(int $credits): ?array
    {
        $levels = self::getAllLevels();
        $matched = null;
        foreach ($levels as $level) {
            if ((int)$level['min_credits'] <= $credits) {
                $matched = $level;
            } else {
                break;
            }
        }
        return $matched;
    }

    /**
     * 获取所有等级列表（带缓存）
     */
    public static function getAllLevels(): array
    {
        return Cache::getStale('levels:all', function () {
            return Database::fetchAll("
                SELECT * FROM user_levels
                ORDER BY min_credits ASC
            ");
        }, 3600) ?? [];
    }

    /**
     * 获取下一等级信息（从缓存列表查找，无需额外SQL）
     */
    public static function getNextLevel(int $currentLevel): ?array
    {
        $levels = self::getAllLevels();
        foreach ($levels as $level) {
            if ((int)$level['level'] > $currentLevel) {
                return $level;
            }
        }
        return null;
    }

    /**
     * 计算升级进度百分比
     */
    public static function getLevelProgress(int $credits): array
    {
        $currentLevel = self::getLevelByCredits($credits);
        if (!$currentLevel) {
            return [
                'current_level' => null,
                'next_level' => null,
                'progress' => 0,
                'credits_needed' => 0,
            ];
        }

        $nextLevel = self::getNextLevel((int)$currentLevel['level']);

        if (!$nextLevel) {
            // 已达到最高等级
            return [
                'current_level' => $currentLevel,
                'next_level' => null,
                'progress' => 100,
                'credits_needed' => 0,
            ];
        }

        $currentMin = (int)$currentLevel['min_credits'];
        $nextMin = (int)$nextLevel['min_credits'];
        $range = $nextMin - $currentMin;
        $earned = $credits - $currentMin;
        $progress = $range > 0 ? min(100, round(($earned / $range) * 100)) : 0;

        return [
            'current_level' => $currentLevel,
            'next_level' => $nextLevel,
            'progress' => $progress,
            'credits_needed' => max(0, $nextMin - $credits),
        ];
    }

    /**
     * 获取等级徽章HTML
     */
    public static function getLevelBadge(int $credits): string
    {
        $level = self::getLevelByCredits($credits);
        if (!$level) {
            return '';
        }

        $color = htmlspecialchars($level['color'] ?? '#999999');
        $name = htmlspecialchars($level['name']);
        $levelNum = (int)$level['level'];

        return sprintf(
            '<span class="user-level-badge" style="background:%s15;color:%s;border:1px solid %s;padding:2px 8px;border-radius:var(--radius);font-size:11px;font-weight:600;">Lv%d %s</span>',
            $color, $color, $color, $levelNum, $name
        );
    }

    /**
     * 清除等级缓存
     */
    public static function clearCache(): void
    {
        Cache::delete("levels:all");
    }

    /**
     * 根据发帖数/积分自动升级用户组
     * 检查 user_groups 表的 credits_from / credits_to 字段，自动调整 group_id
     */
    public static function autoUpgradeGroup(int $userId): void
    {
        try {
            $user = Database::fetchOne("SELECT id, credits, group_id FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
            if (!$user) return;

            $credits = (int)$user['credits'];
            $currentGroupId = (int)$user['group_id'];

            // 管理员(3)、版主(2)、待验证(4)、禁止(5) 不自动升级
            if (in_array($currentGroupId, [2, 3, 4, 5], true)) {
                return;
            }

            // 查找匹配积分区间的用户组（credits_from <= credits < credits_to）
            $newGroup = Database::fetchOneCached("
                SELECT id FROM user_groups
                WHERE credits_from IS NOT NULL AND credits_to IS NOT NULL
                  AND credits_from <= ? AND credits_to > ?
                  AND is_admin = 0
                ORDER BY credits_from DESC
                LIMIT 1
            ", [$credits, $credits], 600);

            if ($newGroup && (int)$newGroup['id'] !== $currentGroupId) {
                Database::execute("UPDATE users SET group_id = ? WHERE id = ?", [(int)$newGroup['id'], $userId]);
                Cache::delete("user:profile:{$userId}");
                Cache::delete("user:group:{$userId}");
                // 清除权限缓存中的 group_id
                Cache::delete("dbq1:" . md5("SELECT group_id FROM users WHERE id = ? AND deleted_at IS NULL" . serialize([$userId])));
            }
        } catch (\Throwable $e) {
            // credits_from/credits_to 字段不存在时静默忽略，但记录其他异常
            if (!str_contains($e->getMessage(), 'credits_from') && !str_contains($e->getMessage(), 'credits_to')) {
                error_log('[LevelSvc] autoUpgradeGroup failed: ' . $e->getMessage());
            }
        }
    }
}
