<?php
namespace App\Services;

use Core\Database;
use Core\Cache;

/**
 * VIP 会员服务
 */
class VipSvc
{
    // 默认等级配置（settings 中无数据时回退）
    private static array $defaultLevels = [
        ['level' => 1, 'name' => '白银会员', 'color' => '#94a3b8', 'icon' => '🥈', 'price' => 100, 'benefits' => ['专属标识', '每日签到双倍积分', '免费查看评论可见内容']],
        ['level' => 2, 'name' => '黄金会员', 'color' => '#f59e0b', 'icon' => '🥇', 'price' => 300, 'benefits' => ['白银全部权益', '每日签到三倍积分', '免费查看等级限制内容', '发帖无需审核']],
        ['level' => 3, 'name' => '铂金会员', 'color' => '#06b6d4', 'icon' => '💎', 'price' => 600, 'benefits' => ['黄金全部权益', '每日签到四倍积分', '免费查看所有隐藏内容', '专属头像框']],
        ['level' => 4, 'name' => '钻石会员', 'color' => '#8b5cf6', 'icon' => '👑', 'price' => 1200, 'benefits' => ['铂金全部权益', '每日签到五倍积分', '全站免费', '专属昵称颜色', '优先客服支持']],
    ];

    /**
     * VIP 功能是否启用
     */
    public static function isEnabled(): bool
    {
        return SettingSvc::getBool('vip_enabled', true);
    }

    /** 请求级缓存 */
    private static ?array $configCache = null;

    /**
     * 获取后台配置的等级数据（按 level 索引）
     */
    public static function getConfig(): array
    {
        if (self::$configCache !== null) {
            return self::$configCache;
        }
        $json = SettingSvc::get('vip_levels', '');
        $levels = $json !== '' ? json_decode($json, true) : null;
        if (!is_array($levels) || empty($levels)) {
            $levels = self::$defaultLevels;
        }
        $map = [0 => ['name' => '普通用户', 'color' => '#999999', 'icon' => '', 'price' => 0, 'benefits' => []]];
        foreach ($levels as $lv) {
            $map[(int)$lv['level']] = $lv;
        }
        self::$configCache = $map;
        return $map;
    }

    /**
     * 获取 VIP 等级列表
     */
    public static function getLevels(): array
    {
        $config = self::getConfig();
        $result = [];
        foreach ($config as $level => $info) {
            $result[] = array_merge($info, ['level' => $level]);
        }
        return $result;
    }

    /**
     * 获取用户 VIP 信息（带缓存）
     */
    public static function getUserVip(int $userId): array
    {
        return Cache::get("vip:user:{$userId}", function() use ($userId) {
            $config = self::getConfig();
            try {
                $row = Database::fetchOne(
                    "SELECT * FROM user_vip WHERE user_id = ? AND expire_at > ? ORDER BY vip_level DESC LIMIT 1",
                    [$userId, time()]
                );
            } catch (\Throwable $e) {
                $row = null;
            }

            if (!$row) {
                $default = $config[0];
                return ['level' => 0, 'name' => $default['name'], 'color' => $default['color'], 'icon' => $default['icon'], 'expire_at' => 0, 'benefits' => []];
            }

            $level = (int)$row['vip_level'];
            $info = $config[$level] ?? $config[0];

            return [
                'level' => $level,
                'name' => $info['name'],
                'color' => $info['color'],
                'icon' => $info['icon'],
                'expire_at' => (int)$row['expire_at'],
                'benefits' => $info['benefits'] ?? [],
            ];
        }, 300);
    }

    /**
     * 购买/续费 VIP（使用积分）
     */
    public static function purchase(int $userId, int $level, int $months = 1): array
    {
        if (!self::isEnabled()) {
            throw new \RuntimeException('VIP 功能暂未开放');
        }

        $config = self::getConfig();
        if (!isset($config[$level]) || $level < 1) {
            throw new \RuntimeException('无效的 VIP 等级');
        }
        if ($months < 1 || $months > 12) {
            throw new \RuntimeException('购买时长 1-12 个月');
        }

        $info = $config[$level];
        $totalPrice = $info['price'] * $months;

        // 积分扣除与 VIP 写入在同一事务中，避免跨事务数据不一致
        Database::beginTransaction();
        try {
            // 原子扣减积分
            $affected = Database::execute(
                "UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?",
                [$totalPrice, $userId, $totalPrice]
            );
            if ($affected === 0) {
                throw new \RuntimeException('积分不足，需要 ' . $totalPrice . ' 积分');
            }
            $balance = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$userId])['credits'] ?? 0;
            Database::execute(
                "INSERT INTO credit_logs (user_id, amount, balance, type, description, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, -$totalPrice, $balance, 'vip', "购买{$info['name']} {$months}个月", time()]
            );

            // 查询现有 VIP（加锁防止并发购买）
            $existing = Database::fetchOne(
                "SELECT * FROM user_vip WHERE user_id = ? AND vip_level = ? AND expire_at > ? FOR UPDATE",
                [$userId, $level, time()]
            );

            $duration = $months * 30 * 86400;
            $newExpire = 0;

            if ($existing) {
                $newExpire = (int)$existing['expire_at'] + $duration;
                Database::execute(
                    "UPDATE user_vip SET expire_at = ?, updated_at = ? WHERE id = ?",
                    [$newExpire, time(), $existing['id']]
                );
            } else {
                $newExpire = time() + $duration;
                Database::execute(
                    "INSERT INTO user_vip (user_id, vip_level, expire_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
                    [$userId, $level, $newExpire, time(), time()]
                );
            }
            Database::commit();
        } catch (\RuntimeException $e) {
            Database::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log('[VipSvc] purchase failed: ' . $e->getMessage());
            throw new \RuntimeException('购买失败，请重试');
        }

        Cache::delete("vip:user:{$userId}");
        Cache::delete("user:profile:{$userId}");

        return [
            'level' => $level,
            'name' => $info['name'],
            'expire_at' => $newExpire,
            'cost' => $totalPrice,
        ];
    }

    /**
     * 获取 VIP 徽章 HTML
     */
    public static function getVipBadge(int $userId): string
    {
        if (!self::isEnabled()) return '';
        $vip = self::getUserVip($userId);
        if ($vip['level'] < 1) return '';
        return '<span class="vip-badge vip-' . $vip['level'] . '" title="' . htmlspecialchars($vip['name']) . '">' . htmlspecialchars($vip['icon']) . '</span>';
    }

    /**
     * 获取签到积分倍率
     */
    public static function getCheckinMultiplier(int $userId): int
    {
        $vip = self::getUserVip($userId);
        return max(1, $vip['level'] + 1);
    }
}
