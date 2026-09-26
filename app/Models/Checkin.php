<?php
/**
 * 签到模型
 *
 * 只负责「取某天的签到状态」这一读路径：缓存 key 由 Checkin 控制器写入和删除，
 * 所以这里必须用同一个 key（checkin:{userId}:{date}），不能换成 SQL 推导的 key。
 */

namespace App\Models;

use Core\Cache;
use Core\Database;

class Checkin extends Model
{
    protected static string $table = 'user_checkins';

    /** 本表没有软删除列 */
    protected static ?string $softDeleteColumn = null;

    /**
     * 某用户某天的签到记录
     *
     * 返回类型是 mixed：写入方（Checkin 控制器）往同一个 key 里存过
     * 完整行，也存过 ['id' => 1, 'credits' => .., 'consecutive_days' => ..] 这种精简数组，
     * 这里原样返回，由调用方按自己的分支处理。
     *
     * @return mixed
     */
    public static function todayCached(int $userId, string $date): mixed
    {
        return self::keyedOne(
            self::cacheKey($userId, $date),
            "SELECT id, credits, consecutive_days FROM user_checkins WHERE user_id = ? AND checkin_date = ? LIMIT 1",
            [$userId, $date],
            300
        );
    }

    /**
     * 事务内加锁读当天记录（并发重复签到的最后一道闸）
     *
     * 必须在事务里调用：FOR UPDATE 的行锁只在事务期间有效。
     *
     * @return array{id: int, credits: int, consecutive_days: int}|null
     */
    public static function lockedToday(int $userId, string $date): ?array
    {
        $row = Database::fetchOne(
            "SELECT id, credits, consecutive_days FROM user_checkins
             WHERE user_id = ? AND checkin_date = ? FOR UPDATE",
            [$userId, $date]
        );

        return $row ?: null;
    }

    /**
     * 最近一次签到（判断连续天数用）
     *
     * @return array{checkin_date: string, consecutive_days: int}|null
     */
    public static function lastCheckin(int $userId): ?array
    {
        $row = Database::fetchOne(
            "SELECT checkin_date, consecutive_days FROM user_checkins
             WHERE user_id = ? ORDER BY checkin_date DESC LIMIT 1",
            [$userId]
        );

        return $row ?: null;
    }

    /**
     * 写入当天签到记录
     */
    public static function create(int $userId, string $date, int $consecutiveDays, int $credits): int
    {
        Database::execute(
            "INSERT INTO user_checkins (user_id, checkin_date, consecutive_days, credits, created_at) VALUES (?, ?, ?, ?, ?)",
            [$userId, $date, $consecutiveDays, $credits, time()]
        );

        return Database::lastInsertId();
    }

    /**
     * 把当天状态写进缓存（签到成功后调用，别的请求就不用再查库）
     *
     * 存精简数组而不是整行：首页/卡片只需要这三个字段，
     * 与 todayCached() 读取时的分支保持一致。
     */
    public static function rememberToday(int $userId, string $date, int $credits, int $consecutiveDays): void
    {
        Cache::set(self::cacheKey($userId, $date), [
            'id'               => 1,
            'credits'          => $credits,
            'consecutive_days' => $consecutiveDays,
        ], 86400);
    }

    /**
     * 缓存里已有的当天记录（签到前先看它，避免无谓查库）
     *
     * @return mixed
     */
    public static function cachedToday(int $userId, string $date): mixed
    {
        return Cache::get(self::cacheKey($userId, $date));
    }

    /**
     * 缓存某条已存在的记录（事务里查到「今天已签过」时补缓存）
     */
    public static function rememberRow(int $userId, string $date, array $row): void
    {
        Cache::set(self::cacheKey($userId, $date), $row, 86400);
    }

    /** 签到状态缓存键（读写集中在一处，避免各处手写） */
    public static function cacheKey(int $userId, string $date): string
    {
        return "checkin:{$userId}:{$date}";
    }

    /**
     * 删除某用户的全部签到记录（删用户级联用）
     *
     * 顺手清该用户的状态缓存：键里带日期，没法逐个推，按 checkin:{userId}:* 模式删。
     */
    public static function deleteByUser(int $userId): int
    {
        $affected = Database::execute("DELETE FROM user_checkins WHERE user_id = ?", [$userId]);
        Cache::deletePattern("checkin:{$userId}:*");

        return $affected;
    }
}
