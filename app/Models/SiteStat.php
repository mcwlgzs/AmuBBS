<?php
/**
 * 全站统计（跨表聚合）
 *
 * 刻意不做成「行模型」：仪表盘这几个 COUNT 必须一条 SQL 取回，
 * 拆成 User::countAll() + Thread::countAll() + ... 就是 4 次往返。
 *
 * $table 只是占位（聚合查询自带了全部表名），本类不使用基类的行级方法
 * （existsWhere / softDeleteById / dailyCounts）。
 */

namespace App\Models;

use Core\Database;

final class SiteStat extends Model
{
    protected static string $table = 'users';

    protected static ?string $softDeleteColumn = null;

    /**
     * 全站总量
     *
     * @return array{users: int, threads: int, posts: int, forums: int}
     */
    public static function totals(): array
    {
        $row = Database::fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) as users,
                (SELECT COUNT(*) FROM threads WHERE deleted_at IS NULL) as threads,
                (SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL) as posts,
                (SELECT COUNT(*) FROM forums WHERE deleted_at IS NULL) as forums"
        );

        return [
            'users'   => (int)($row['users'] ?? 0),
            'threads' => (int)($row['threads'] ?? 0),
            'posts'   => (int)($row['posts'] ?? 0),
            'forums'  => (int)($row['forums'] ?? 0),
        ];
    }

    /**
     * 今日新增
     *
     * @return array{threads: int, posts: int, users: int}
     */
    public static function today(int $todayStart): array
    {
        $row = Database::fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM threads WHERE created_at >= ? AND deleted_at IS NULL) as threads,
                (SELECT COUNT(*) FROM posts WHERE created_at >= ? AND deleted_at IS NULL) as posts,
                (SELECT COUNT(*) FROM users WHERE created_at >= ? AND deleted_at IS NULL) as users",
            [$todayStart, $todayStart, $todayStart]
        );

        return [
            'threads' => (int)($row['threads'] ?? 0),
            'posts'   => (int)($row['posts'] ?? 0),
            'users'   => (int)($row['users'] ?? 0),
        ];
    }

    /**
     * 某用户今日的任务进度（7 张表各一个子查询，必须一条 SQL 取回）
     *
     * 任务中心的 7 个任务分别落在不同表上（签到/主题/回复/点赞/动态/关注/浏览），
     * 拆成 7 次查询就是 7 次往返，所以按跨表聚合处理。
     *
     * @return array{checkin: int, thread: int, reply: int, like: int, moment: int, follow: int, share: int}
     */
    public static function userDailyActivity(int $userId, string $date, int $dayStart, int $dayEnd): array
    {
        $row = Database::fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM user_checkins WHERE user_id = ? AND checkin_date = ?) as checkin,
                (SELECT COUNT(*) FROM threads WHERE user_id = ? AND created_at >= ? AND created_at < ? AND deleted_at IS NULL) as thread,
                (SELECT COUNT(*) FROM posts WHERE user_id = ? AND created_at >= ? AND created_at < ? AND deleted_at IS NULL) as reply,
                (SELECT COUNT(*) FROM post_likes WHERE user_id = ? AND created_at >= ? AND created_at < ?) as `like`,
                (SELECT COUNT(*) FROM moments WHERE user_id = ? AND created_at >= ? AND created_at < ? AND deleted_at IS NULL) as moment,
                (SELECT COUNT(*) FROM user_follows WHERE user_id = ? AND created_at >= ? AND created_at < ?) as follow,
                (SELECT COUNT(*) FROM browse_history WHERE user_id = ? AND created_at >= ? AND created_at < ?) as share",
            [
                $userId, $date,
                $userId, $dayStart, $dayEnd,
                $userId, $dayStart, $dayEnd,
                $userId, $dayStart, $dayEnd,
                $userId, $dayStart, $dayEnd,
                $userId, $dayStart, $dayEnd,
                $userId, $dayStart, $dayEnd,
            ]
        );

        return [
            'checkin' => min(1, (int)($row['checkin'] ?? 0)),
            'thread'  => (int)($row['thread'] ?? 0),
            'reply'   => (int)($row['reply'] ?? 0),
            'like'    => (int)($row['like'] ?? 0),
            'moment'  => (int)($row['moment'] ?? 0),
            'follow'  => (int)($row['follow'] ?? 0),
            'share'   => (int)($row['share'] ?? 0),
        ];
    }
}
