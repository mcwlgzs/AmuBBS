<?php
/**
 * 队列任务模型（queue_jobs 表）
 *
 * 这是「无 Redis」时的队列后端：抢占任务用一条带条件的 UPDATE（原子），
 * 再按抢占令牌取回那一行 —— 顺序与条件都不能拆开写，所以整对操作放在 reserve() 里。
 */

namespace App\Models;

use Core\Database;

class QueueJob extends Model
{
    protected static string $table = 'queue_jobs';

    /** 本表没有软删除列（消费完直接删行） */
    protected static ?string $softDeleteColumn = null;

    /**
     * 入队
     */
    public static function push(string $queue, string $payloadJson, int $availableAt, int $createdAt): int
    {
        Database::execute(
            "INSERT INTO queue_jobs (queue, payload, available_at, created_at) VALUES (?, ?, ?, ?)",
            [$queue, $payloadJson, $availableAt, $createdAt]
        );

        return Database::lastInsertId();
    }

    /**
     * 原子抢占一条到期任务（没有则返回 null）
     *
     * 返回行里带 reserve_token：调用方必须把它一路带到 done()/remove()/fail()，
     * 否则租约超时后旧 worker 会误删新 worker 正在处理的那一行。
     *
     * @return array{id: int, queue: string, payload: string, reserve_token: string, attempts: int, created_at: int}|null
     */
    public static function reserve(string $queue, int $now, string $token): ?array
    {
        $affected = Database::execute(
            "UPDATE queue_jobs SET reserved_at = ?, reserve_token = ?
             WHERE queue = ? AND available_at <= ? AND reserved_at IS NULL
             ORDER BY id ASC LIMIT 1",
            [$now, $token, $queue, $now]
        );

        if ($affected === 0) {
            return null;
        }

        $job = Database::fetchOne(
            "SELECT * FROM queue_jobs WHERE queue = ? AND reserve_token = ? AND reserved_at = ?",
            [$queue, $token, $now]
        );

        return $job ?: null;
    }

    /**
     * 完成任务（删行）
     *
     * @param string|null $token 传了就只删「自己抢占的那一行」（防止租约过期后误删他人任务）
     */
    public static function remove(int $id, ?string $token = null): int
    {
        if ($token !== null) {
            return Database::execute(
                "DELETE FROM queue_jobs WHERE id = ? AND reserve_token = ?",
                [$id, $token]
            );
        }

        return Database::execute("DELETE FROM queue_jobs WHERE id = ?", [$id]);
    }

    /** 未被抢占的任务数（队列长度） */
    public static function unreservedCount(string $queue): int
    {
        return (int)(Database::fetchOne(
            "SELECT COUNT(*) as c FROM queue_jobs WHERE queue = ? AND reserved_at IS NULL",
            [$queue]
        )['c'] ?? 0);
    }

    /** 释放一条被抢占的任务（失败重试用，清掉抢占标记好让它重新排队） */
    public static function release(int $id, ?string $token = null): int
    {
        if ($token !== null) {
            return Database::execute(
                "UPDATE queue_jobs SET reserved_at = NULL, reserve_token = NULL WHERE id = ? AND reserve_token = ?",
                [$id, $token]
            );
        }

        return Database::execute(
            "UPDATE queue_jobs SET reserved_at = NULL, reserve_token = NULL WHERE id = ?",
            [$id]
        );
    }

    /**
     * 释放超时未完成的任务（cron 调用）
     *
     * 必须连 reserve_token 一起清：只清 reserved_at 会让「已过期的旧 token」继续有效，
     * 旧 worker 随后的 remove() 就会删掉刚被新 worker 抢到的同一行。
     */
    public static function releaseStale(int $cutoff): int
    {
        return Database::execute(
            "UPDATE queue_jobs SET reserved_at = NULL, reserve_token = NULL
             WHERE reserved_at IS NOT NULL AND reserved_at < ?",
            [$cutoff]
        );
    }

    /**
     * 记录一次消费失败（attempts 持久化 + 租约处理）
     *
     * 达到 $maxAttempts 就删行（毒任务不再无限重试），否则释放租约等待下次调度。
     *
     * @return string 'deleted' | 'retried' | 'lost'（lost = 租约已不属于自己，什么都没做）
     */
    public static function fail(int $id, string $token, int $maxAttempts = 3): string
    {
        $affected = Database::execute(
            "UPDATE queue_jobs SET attempts = attempts + 1 WHERE id = ? AND reserve_token = ?",
            [$id, $token]
        );

        if ($affected === 0) {
            return 'lost';
        }

        $row = Database::fetchOne("SELECT attempts FROM queue_jobs WHERE id = ?", [$id]);
        if ((int)($row['attempts'] ?? 0) >= $maxAttempts) {
            self::remove($id, $token);
            return 'deleted';
        }

        self::release($id, $token);
        return 'retried';
    }
}
