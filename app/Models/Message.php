<?php
/**
 * 私信模型
 *
 * 私信的「删除」是按 from/to 两个标记位分别记的（deleted_by_from / deleted_by_to），
 * 所以本表没有 deleted_at：软删除列显式置 null，避免基类拼出不存在的条件。
 */

namespace App\Models;

use Core\Database;

class Message extends Model
{
    protected static string $table = 'messages';

    /** 本表用 from/to 两个标记位分别记录删除，没有统一的软删除列 */
    protected static ?string $softDeleteColumn = null;

    /**
     * 会话列表：每个对话只取最新一条
     *
     * LEAST/GREATEST 把 (A,B) 和 (B,A) 归一到同一组，MAX(id) 取该组最后一条。
     *
     * @return array 每行额外带 other_user_id（对方 id）
     */
    public static function conversations(int $userId, int $limit = 50): array
    {
        return Database::fetchAll(
            "SELECT m.*,
                    CASE WHEN m.from_user_id = ? THEN m.to_user_id ELSE m.from_user_id END as other_user_id
             FROM messages m
             INNER JOIN (
                 SELECT LEAST(from_user_id, to_user_id) as u1,
                        GREATEST(from_user_id, to_user_id) as u2,
                        MAX(id) as max_id
                 FROM messages
                 WHERE (from_user_id = ? AND deleted_by_from = 0)
                    OR (to_user_id = ? AND deleted_by_to = 0)
                 GROUP BY u1, u2
             ) latest ON m.id = latest.max_id
             ORDER BY m.created_at DESC
             LIMIT ?",
            [$userId, $userId, $userId, $limit]
        );
    }

    /**
     * 每个发信人发给我的未读条数（会话列表批量用，避免 N+1）
     *
     * @param int[] $senderIds
     * @return array<int, int> from_user_id => 未读条数
     */
    public static function unreadCountsBySender(int $userId, array $senderIds): array
    {
        $senderIds = array_values(array_unique(array_filter(array_map('intval', $senderIds))));
        if (empty($senderIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($senderIds), '?'));
        $rows = Database::fetchAll(
            "SELECT from_user_id, COUNT(*) as c
             FROM messages
             WHERE from_user_id IN ({$placeholders}) AND to_user_id = ? AND is_read = 0
             GROUP BY from_user_id",
            [...$senderIds, $userId]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['from_user_id']] = (int)$row['c'];
        }

        return $counts;
    }

    /**
     * 两人之间的消息（各自删掉的那些对删除方不可见）
     */
    public static function between(int $userId, int $otherUserId, int $limit = 100): array
    {
        return Database::fetchAll(
            "SELECT m.*, u.username, u.avatar
             FROM messages m
             LEFT JOIN users u ON m.from_user_id = u.id
             WHERE ((m.from_user_id = ? AND m.to_user_id = ? AND m.deleted_by_from = 0)
                 OR (m.from_user_id = ? AND m.to_user_id = ? AND m.deleted_by_to = 0))
             ORDER BY m.created_at ASC
             LIMIT ?",
            [$userId, $otherUserId, $otherUserId, $userId, $limit]
        );
    }

    /**
     * 把某人发给我的未读私信标记为已读
     */
    public static function markReadFrom(int $fromUserId, int $toUserId): int
    {
        return Database::execute(
            "UPDATE messages SET is_read = 1 WHERE from_user_id = ? AND to_user_id = ? AND is_read = 0",
            [$fromUserId, $toUserId]
        );
    }

    /**
     * 发一条私信，返回消息 id
     *
     * 用事务包住：lastInsertId() 必须和 INSERT 在同一条连接、同一个事务里取，
     * 否则拿到的可能是别的连接上的 0。
     */
    public static function create(int $fromUserId, int $toUserId, string $content): int
    {
        return (int)Database::transaction(static function () use ($fromUserId, $toUserId, $content): int {
            Database::execute(
                "INSERT INTO messages (from_user_id, to_user_id, content, is_read, created_at, deleted_by_from, deleted_by_to)
                 VALUES (?, ?, ?, 0, ?, 0, 0)",
                [$fromUserId, $toUserId, $content, time()]
            );

            return Database::lastInsertId();
        });
    }

    /**
     * 撤回前的「新鲜」读取（不能用任何缓存：撤回窗口只有 2 分钟）
     *
     * @return array{id: int, from_user_id: int, created_at: int, is_recalled: int}|null
     */
    public static function findForRecall(int $messageId): ?array
    {
        $row = Database::fetchOne(
            "SELECT id, from_user_id, created_at, is_recalled FROM messages WHERE id = ?",
            [$messageId]
        );

        return $row ?: null;
    }

    /**
     * 渲染单条私信片段要用的列
     */
    public static function findRow(int $messageId): ?array
    {
        $row = Database::fetchOne(
            "SELECT id, from_user_id, content, is_recalled, created_at FROM messages WHERE id = ?",
            [$messageId]
        );

        return $row ?: null;
    }

    public static function recall(int $messageId): int
    {
        return Database::execute("UPDATE messages SET is_recalled = 1 WHERE id = ?", [$messageId]);
    }

    // ------------------------------------------------------------------
    // 后台私信监控
    // ------------------------------------------------------------------

    /**
     * 后台私信列表（页面与 JSON API 共用），按收发双方用户名或内容搜索
     *
     * @return array{rows: array, total: int}
     */
    public static function adminList(string $search = '', int $page = 1, int $limit = 20): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        $search = trim($search);
        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $where .= ' AND (fu.username LIKE ? OR tu.username LIKE ? OR m.content LIKE ?)';
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
        }

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM messages m
             LEFT JOIN users fu ON m.from_user_id = fu.id
             LEFT JOIN users tu ON m.to_user_id = tu.id
             {$where}",
            $params
        )['cnt'] ?? 0);

        $rows = Database::fetchAll(
            "SELECT m.*, fu.username as from_username, fu.avatar as from_avatar, tu.username as to_username
             FROM messages m
             LEFT JOIN users fu ON m.from_user_id = fu.id
             LEFT JOIN users tu ON m.to_user_id = tu.id
             {$where} ORDER BY m.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, max(0, ($page - 1) * $limit)])
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * 后台删除单条私信（硬删除，管理员看不到也不该留）
     */
    public static function remove(int $messageId): int
    {
        return Database::execute("DELETE FROM messages WHERE id = ?", [$messageId]);
    }
}
