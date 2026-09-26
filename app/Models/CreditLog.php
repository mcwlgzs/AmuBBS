<?php
/**
 * 积分流水模型
 *
 * 由原 App\Services\CreditSvc 的读查询迁移而来。
 *
 * 分层说明：积分的「记账」（users.credits 与 credit_logs 必须同事务增减）
 * 仍然留在 App\Services\CreditSvc —— 那是领域记账操作，不是单纯取数；
 * 这里只负责流水表自身的取数与写入。
 */

namespace App\Models;

use Core\Database;

class CreditLog extends Model
{
    protected static string $table = 'credit_logs';

    /** 隐藏内容购买凭证的查询（缓存键由这份 SQL 常量推导，三处必须一致） */
    private const PURCHASE_SQL =
        "SELECT id FROM credit_logs WHERE user_id = ? AND type = 'content_purchase' AND related_type = 'thread' AND related_id = ?";

    /**
     * 写一条积分流水
     *
     * balance 是「变动后余额」，必须由调用方在同一个事务里、紧跟 UPDATE 之后读出来再传进来，
     * 否则并发下会记错。
     */
    public static function write(
        int $userId,
        int $amount,
        int $balance,
        string $type,
        string $description,
        string $relatedType,
        int $relatedId,
        int $createdAt
    ): int {
        Database::execute(
            "INSERT INTO credit_logs (user_id, amount, balance, type, description, related_type, related_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$userId, $amount, $balance, $type, $description, $relatedType, $relatedId, $createdAt]
        );

        return Database::lastInsertId();
    }

    /**
     * 是否已购买过某主题的隐藏内容（120 秒缓存）
     */
    public static function hasContentPurchase(int $userId, int $threadId): bool
    {
        return self::cachedOne(self::PURCHASE_SQL, [$userId, $threadId], 120) !== null;
    }

    /**
     * 事务内加锁再查一次购买凭证（FOR UPDATE，防并发重复购买）
     *
     * 必须在事务里调用：行锁只在事务期间有效。
     */
    public static function lockContentPurchase(int $userId, int $threadId): bool
    {
        return Database::fetchOne(self::PURCHASE_SQL . ' FOR UPDATE', [$userId, $threadId]) !== null;
    }

    /**
     * 清掉购买凭证的缓存（购买成功后要立刻可见）
     *
     * 缓存键由同一份 SQL 常量推导，不用调用方手写 md5 拼 dbq 键。
     */
    public static function forgetContentPurchaseCache(int $userId, int $threadId): void
    {
        self::forgetOne(self::PURCHASE_SQL, [$userId, $threadId]);
    }

    /**
     * 某用户的积分流水（分页）
     *
     * @return array{logs: array, total: int, page: int, pageSize: int, totalPages: int}
     */
    public static function getUserCreditLogs(int $userId, int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $logs = Database::fetchAll(
            "SELECT * FROM credit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$userId, $pageSize, $offset]
        );

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) as count FROM credit_logs WHERE user_id = ?",
            [$userId]
        )['count'] ?? 0);

        return [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => max(1, (int)ceil($total / $pageSize)),
        ];
    }
    /**
     * 后台积分记录列表（可按用户名搜索）
     *
     * @return array{rows: array, total: int}
     */
    public static function adminList(string $search = '', int $page = 1, int $limit = 20): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE u.username LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM credit_logs cl
             LEFT JOIN users u ON cl.user_id = u.id
             {$where}",
            $params
        )['cnt'] ?? 0);

        $rows = Database::fetchAll(
            "SELECT cl.*, u.username, u.avatar FROM credit_logs cl
             LEFT JOIN users u ON cl.user_id = u.id
             {$where}
             ORDER BY cl.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, max(0, ($page - 1) * $limit)])
        );

        return ['rows' => $rows, 'total' => $total];
    }
}