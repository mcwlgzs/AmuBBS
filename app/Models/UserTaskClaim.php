<?php
/**
 * 任务领取记录模型（user_task_claims 表）
 *
 * (user_id, task_key, claim_date) 上有唯一索引，重复领取会抛 1062 ——
 * 领取流程正是靠这个唯一键兜住并发，所以调用方要能区分「唯一键冲突」。
 */

namespace App\Models;

use Core\Database;

class UserTaskClaim extends Model
{
    protected static string $table = 'user_task_claims';

    /** 本表没有软删除列 */
    protected static ?string $softDeleteColumn = null;

    /**
     * 某用户某天已领取的任务键
     *
     * @return array<string, bool>
     */
    public static function claimedKeys(int $userId, string $date): array
    {
        $rows = Database::fetchAll(
            "SELECT task_key FROM user_task_claims WHERE user_id = ? AND claim_date = ?",
            [$userId, $date]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['task_key']] = true;
        }

        return $map;
    }

    /**
     * 写一条领取记录（并发重复领取时会抛唯一键冲突，由调用方处理）
     */
    public static function insert(int $userId, string $taskKey, int $credits, string $date, int $createdAt): int
    {
        Database::execute(
            "INSERT INTO user_task_claims (user_id, task_key, credits, claim_date, created_at) VALUES (?, ?, ?, ?, ?)",
            [$userId, $taskKey, $credits, $date, $createdAt]
        );

        return Database::lastInsertId();
    }
}
