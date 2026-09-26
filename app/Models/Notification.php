<?php
/**
 * 通知模型
 *
 * 由原 App\Services\NotificationSvc 迁移而来（5 个方法全部围绕 notifications 表，
 * 以及与之配套的 users.unread_notifications 冗余计数，没有额外编排）。
 *
 * 为什么模型里会动 users 表：
 *   unread_notifications 是为「避免每次 COUNT」而存在的冗余计数，
 *   它和 notifications 的读写必须成对更新且在同一事务里，
 *   否则未读数会永久跑偏。这属于通知子系统自己的数据不变量，跟着模型走。
 *
 * ⚠️ 命名注意：app/Controllers/Notification.php 这个控制器自身就叫 Notification，
 *    在它内部引用本模型必须用别名导入（use App\Models\Notification as NotificationModel;）——
 *    不能写不带别名的 use，那会与同文件里的类名冲突。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class Notification extends Model
{
    protected static string $table = 'notifications';

    /**
     * 发送通知
     *
     * 通知失败不应该影响主流程（发帖/回复/关注等），因此这里吞掉异常只记日志。
     */
    public static function notify(
        int $userId,
        int $fromUserId,
        string $type,
        string $title,
        string $content,
        string $targetType,
        int $targetId
    ): void {
        try {
            Database::beginTransaction();
            self::insertForUser($userId, $fromUserId, $type, $title, $content, $targetType, $targetId);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log('[Notification] notify failed: ' . $e->getMessage());
        }
    }

    /**
     * 未读通知数
     *
     * 优先读 users.unread_notifications 冗余字段（避免 COUNT），
     * 该字段不存在时回退到 COUNT 查询。
     */
    public static function getUnreadCount(int $userId): int
    {
        return (int)Cache::get(self::unreadCountKey($userId), function () use ($userId) {
            try {
                $row = Database::fetchOne(
                    "SELECT unread_notifications FROM users WHERE id = ?",
                    [$userId]
                );
                if ($row && isset($row['unread_notifications'])) {
                    return (int)$row['unread_notifications'];
                }
            } catch (\Throwable $e) {
                // 字段不存在时回退到下面的 COUNT
            }

            $row = Database::fetchOne(
                "SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0",
                [$userId]
            );

            return (int)($row['c'] ?? 0);
        }, 15);
    }

    /**
     * 全部标记为已读，并清零冗余计数（事务保证一致）
     */
    public static function markAllRead(int $userId): void
    {
        try {
            Database::beginTransaction();
            Database::execute(
                "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
                [$userId]
            );
            Database::execute(
                "UPDATE users SET unread_notifications = 0 WHERE id = ?",
                [$userId]
            );
            Database::commit();
            Cache::delete(self::unreadCountKey($userId));
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log("[Notification] markAllRead failed for user {$userId}: " . $e->getMessage());
            throw new \RuntimeException('操作失败，请重试');
        }
    }

    /**
     * 单条标记为已读（事务保证计数一致）
     */
    public static function markOneRead(int $notificationId, int $userId): void
    {
        try {
            Database::beginTransaction();
            // 加锁查询，防止并发重复递减计数
            $notif = Database::fetchOne(
                "SELECT is_read FROM notifications WHERE id = ? AND user_id = ? FOR UPDATE",
                [$notificationId, $userId]
            );
            if ($notif && !(int)$notif['is_read']) {
                Database::execute(
                    "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?",
                    [$notificationId, $userId]
                );
                Database::execute(
                    "UPDATE users SET unread_notifications = CASE WHEN unread_notifications > 0 THEN unread_notifications - 1 ELSE 0 END WHERE id = ?",
                    [$userId]
                );
            }
            Database::commit();
            Cache::delete(self::unreadCountKey($userId));
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log("[Notification] markOneRead failed for notification {$notificationId}: " . $e->getMessage());
            throw new \RuntimeException('操作失败，请重试');
        }
    }

    /**
     * 删除单条通知（未读则同步递减计数）
     */
    public static function delete(int $notificationId, int $userId): void
    {
        try {
            Database::beginTransaction();
            $notif = Database::fetchOne(
                "SELECT is_read FROM notifications WHERE id = ? AND user_id = ? FOR UPDATE",
                [$notificationId, $userId]
            );
            if (!$notif) {
                Database::commit();
                return;
            }

            if (!(int)$notif['is_read']) {
                Database::execute(
                    "UPDATE users SET unread_notifications = CASE WHEN unread_notifications > 0 THEN unread_notifications - 1 ELSE 0 END WHERE id = ?",
                    [$userId]
                );
            }
            Database::execute(
                "DELETE FROM notifications WHERE id = ? AND user_id = ?",
                [$notificationId, $userId]
            );
            Database::commit();
            Cache::delete(self::unreadCountKey($userId));
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log("[Notification] delete failed for notification {$notificationId}: " . $e->getMessage());
        }
    }

    /** 未读数的缓存键（读写集中在这里，避免各处手写字面量） */
    public static function unreadCountKey(int $userId): string
    {
        return "unread_notif:{$userId}";
    }
    // ------------------------------------------------------------------
    // 后台通知管理
    // ------------------------------------------------------------------

    /** 管理端群发的 target_type 取值（列表里按批次合并的就是这些） */
    public const SCOPE_USER  = 'user';
    public const SCOPE_GROUP = 'group';
    public const SCOPE_ALL   = 'all';
    public const ADMIN_SCOPES = [self::SCOPE_ALL, self::SCOPE_USER, self::SCOPE_GROUP];

    /**
     * 后台列表的筛选条件 → UNION 两半各自的 WHERE
     *
     * 列表 = 广播批次（管理端发送，按批次指纹合并成一行）+ 逐条通知（回复/提及/私信/关注…）。
     * 两边 WHERE 不一样：广播没有单一收件人，用户名搜索得换成「命中任一收件人」，
     * 所以分开返回给 UNION 的两半用。
     *
     * @param array{search?:string, type?:string} $filters
     * @return array{bWhere: string, bParams: array, sWhere: string, sParams: array}
     */
    public static function adminQuery(array $filters): array
    {
        $search = trim((string)($filters['search'] ?? ''));
        $type = trim((string)($filters['type'] ?? ''));
        $like = $search !== '' ? '%' . addcslashes($search, '%_\\') . '%' : '';
        $scopes = "'" . implode("','", self::ADMIN_SCOPES) . "'";

        // 广播：type = system 且带管理端发送标记
        $bWhere = "WHERE n.type = 'system' AND n.target_type IN ({$scopes})";
        $bParams = [];
        if ($type !== '' && $type !== 'system') {
            $bWhere .= ' AND 1 = 0';   // 按「回复/提及」筛选时不显示广播
        }
        if ($like !== '') {
            $bWhere .= ' AND (n.title LIKE ? OR EXISTS (SELECT 1 FROM users su WHERE su.id = n.user_id AND su.username LIKE ?))';
            $bParams[] = $like;
            $bParams[] = $like;
        }

        // 逐条：广播之外的全部通知（含改造前的历史记录）
        $sWhere = "WHERE NOT (n.type = 'system' AND COALESCE(n.target_type, '') IN ({$scopes}))";
        $sParams = [];
        if ($type !== '') {
            $sWhere .= ' AND n.type = ?';
            $sParams[] = $type;
        }
        if ($like !== '') {
            $sWhere .= ' AND (n.title LIKE ? OR u.username LIKE ?)';
            $sParams[] = $like;
            $sParams[] = $like;
        }

        return ['bWhere' => $bWhere, 'bParams' => $bParams, 'sWhere' => $sWhere, 'sParams' => $sParams];
    }

    /**
     * 后台通知列表 + 总数
     *
     * @return array{rows: array, total: int}
     */
    public static function adminList(array $filters, int $page = 1, int $limit = 20): array
    {
        $q = self::adminQuery($filters);
        $offset = max(0, ($page - 1) * $limit);

        $broadcastSql = "
            SELECT MAX(n.id) AS id,
                   n.from_user_id, n.type, n.title, n.content, n.created_at, n.target_type, n.target_id,
                   COUNT(*)        AS recipients,
                   SUM(n.is_read)  AS read_count,
                   MIN(u.username) AS recipient_username,
                   fu.username     AS from_username,
                   1               AS is_batch
              FROM notifications n
              LEFT JOIN users u  ON n.user_id = u.id
              LEFT JOIN users fu ON n.from_user_id = fu.id
              {$q['bWhere']}
             GROUP BY n.from_user_id, n.type, n.title, n.content, n.created_at, n.target_type, n.target_id, fu.username";

        $singleSql = "
            SELECT n.id, n.from_user_id, n.type, n.title, n.content, n.created_at, n.target_type, n.target_id,
                   1               AS recipients,
                   n.is_read       AS read_count,
                   u.username      AS recipient_username,
                   fu.username     AS from_username,
                   0               AS is_batch
              FROM notifications n
              LEFT JOIN users u  ON n.user_id = u.id
              LEFT JOIN users fu ON n.from_user_id = fu.id
              {$q['sWhere']}";

        $rows = Database::fetchAll(
            "SELECT * FROM ({$broadcastSql} UNION ALL {$singleSql}) t ORDER BY t.id DESC LIMIT ? OFFSET ?",
            array_merge($q['bParams'], $q['sParams'], [$limit, $offset])
        );

        $broadcast = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM (
                 SELECT 1 FROM notifications n {$q['bWhere']}
                 GROUP BY n.from_user_id, n.type, n.title, n.content, n.created_at, n.target_type, n.target_id
             ) g",
            $q['bParams']
        )['cnt'] ?? 0);

        $single = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM notifications n LEFT JOIN users u ON n.user_id = u.id {$q['sWhere']}",
            $q['sParams']
        )['cnt'] ?? 0);

        return ['rows' => $rows, 'total' => $broadcast + $single];
    }

    /**
     * 给一个用户插一条通知并递增未读数
     *
     * 不自己开事务：调用方（notify() 或后台群发的分批事务）负责事务边界。
     * 计数变了必须清缓存，否则角标要等缓存 TTL 才更新。
     */
    public static function insertForUser(
        int $userId,
        int $fromUserId,
        string $type,
        string $title,
        string $content,
        string $targetType,
        int $targetId,
        int $createdAt = 0
    ): void {
        Database::execute(
            "INSERT INTO notifications (user_id, from_user_id, type, title, content, target_type, target_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$userId, $fromUserId, $type, $title, $content, $targetType, $targetId, $createdAt > 0 ? $createdAt : time()]
        );
        Database::execute(
            "UPDATE users SET unread_notifications = unread_notifications + 1 WHERE id = ?",
            [$userId]
        );
        Cache::delete(self::unreadCountKey($userId));
    }

    /**
     * 群发通知（后台「发送系统通知」用）
     *
     * 分批处理：一次只取一批收件人，每批一条多值 INSERT + 一次冗余计数更新，各自一个事务。
     * 批大小、事务边界、计数一致性都是这张表自己的不变量，所以循环与事务都收在模型里，
     * 控制器只负责解析发送范围与提示文案。
     *
     * @param string $target 'all'（全体）或 'group'（某个用户组）
     * @param string $targetType 落库到 notifications.target_type 的值（SCOPE_* 常量，本身是字符串）
     * @return int 实际发出的条数
     */
    public static function broadcastInBatches(
        string $target,
        int $scopeId,
        int $fromUserId,
        string $title,
        string $content,
        string $targetType,
        int $now,
        int $batchSize = 500
    ): int {
        $count = 0;
        $lastId = 0;

        while (true) {
            $userIds = $target === 'group'
                ? User::idsByGroupBatch($scopeId, $lastId, $batchSize)
                : User::idsBatch($lastId, $batchSize);

            if (empty($userIds)) {
                break;
            }

            Database::beginTransaction();
            try {
                self::insertManyForUsers($userIds, $fromUserId, $title, $content, $targetType, $scopeId, $now);
                $lastId = (int)end($userIds);
                Database::commit();
                $count += count($userIds);
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }
        }

        return $count;
    }

    /**
     * 批量给一批用户插同样的通知（后台群发按批调用）
     *
     * 用一条多值 INSERT + 一条 IN 更新，避免 500 个用户来回 1000 次；
     * created_at 由调用方统一传入，作为「同一批次」的指纹。
     *
     * @param int[] $userIds
     * @return int 实际写入条数
     */
    public static function insertManyForUsers(
        array $userIds,
        int $fromUserId,
        string $title,
        string $content,
        string $targetType,
        int $targetId,
        int $createdAt = 0
    ): int {
        $ids = array_values(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0));
        if (empty($ids)) {
            return 0;
        }
        $createdAt = $createdAt > 0 ? $createdAt : time();

        $values = [];
        $params = [];
        foreach ($ids as $id) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($params, $id, $fromUserId, 'system', $title, $content, $targetType, $targetId, $createdAt);
        }

        Database::execute(
            "INSERT INTO notifications (user_id, from_user_id, type, title, content, target_type, target_id, created_at)
             VALUES " . implode(', ', $values),
            $params
        );

        $ph = implode(',', array_fill(0, count($ids), '?'));
        Database::execute(
            "UPDATE users SET unread_notifications = unread_notifications + 1 WHERE id IN ({$ph})",
            $ids
        );

        foreach ($ids as $id) {
            Cache::delete(self::unreadCountKey($id));
        }

        return count($ids);
    }

    public static function findById(int $id): ?array
    {
        $row = Database::fetchOne("SELECT * FROM notifications WHERE id = ?", [$id]);

        return $row ?: null;
    }

    /**
     * 删除单条通知（未读的要把收件人未读数减回来）
     */
    public static function deleteById(int $id): int
    {
        $notif = Database::fetchOne("SELECT user_id, is_read FROM notifications WHERE id = ?", [$id]);
        if ($notif && !(int)$notif['is_read']) {
            Database::execute(
                "UPDATE users SET unread_notifications = CASE WHEN unread_notifications > 0 THEN unread_notifications - 1 ELSE 0 END WHERE id = ?",
                [(int)$notif['user_id']]
            );
            Cache::delete(self::unreadCountKey((int)$notif['user_id']));
        }

        return Database::execute("DELETE FROM notifications WHERE id = ?", [$id]);
    }

    /**
     * 取一批广播的「批次指纹」（浏览器只回传批次里任意一条 id）
     */
    public static function broadcastHead(int $id): ?array
    {
        $row = Database::fetchOne(
            "SELECT from_user_id, type, title, content, target_type, target_id, created_at
               FROM notifications WHERE id = ?",
            [$id]
        );

        return $row ?: null;
    }

    /**
     * 同一批次的全部收件人（含已读状态）
     *
     * @param array $head broadcastHead() 的返回值
     * @return array<int, array{user_id: int, is_read: int}>
     */
    public static function broadcastRecipients(array $head): array
    {
        [$cond, $params] = self::fingerprintWhere($head);
        $rows = Database::fetchAll("SELECT user_id, is_read FROM notifications WHERE {$cond}", $params);

        return array_map(static fn(array $r): array => [
            'user_id' => (int)$r['user_id'],
            'is_read' => (int)$r['is_read'],
        ], $rows);
    }

    /**
     * 批量标记已读（只处理属于该用户的行），并同步递减冗余未读计数
     *
     * 计数与标记在同一事务里完成，避免并发下重复扣减（与 markAllRead 同一套做法）。
     *
     * @return int 实际被标记的未读条数
     */
    public static function markReadIds(int $userId, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if (empty($ids) || $userId <= 0) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$userId]);

        try {
            Database::beginTransaction();

            $unread = (int)(Database::fetchOne(
                "SELECT COUNT(*) as c FROM notifications
                 WHERE id IN ({$placeholders}) AND user_id = ? AND is_read = 0",
                $params
            )['c'] ?? 0);

            Database::execute(
                "UPDATE notifications SET is_read = 1 WHERE id IN ({$placeholders}) AND user_id = ?",
                $params
            );

            if ($unread > 0) {
                Database::execute(
                    "UPDATE users SET unread_notifications = CASE WHEN unread_notifications >= ? THEN unread_notifications - ? ELSE 0 END WHERE id = ?",
                    [$unread, $unread, $userId]
                );
            }

            Database::commit();
            Cache::delete(self::unreadCountKey($userId));
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log("[Notification] markReadIds failed for user {$userId}: " . $e->getMessage());
            throw new \RuntimeException('操作失败，请重试');
        }

        return $unread;
    }

    /**
     * 站内通知列表（通知页 + htmx 卡片片段共用），带来源用户名
     *
     * @param bool $withAvatar 开放 API 要头像列；站内页面不用，少查一列
     * @return array{items: array, total: int, unread: int}
     */
    public static function listForUser(int $userId, int $limit, int $offset, bool $withAvatar = false): array
    {
        $cols = $withAvatar
            ? "n.*, u.username as from_username, u.avatar as from_avatar"
            : "n.*, u.username as from_username";

        $items = Database::fetchAll(
            "SELECT {$cols}
             FROM notifications n
             LEFT JOIN users u ON n.from_user_id = u.id
             WHERE n.user_id = ?
             ORDER BY n.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );

        $counts = self::countForUser($userId);

        return ['items' => $items, 'total' => $counts['total'], 'unread' => $counts['unread']];
    }

    /**
     * 总数与未读数一条 SQL 取回（原先是两条 COUNT）
     *
     * @return array{total: int, unread: int}
     */
    public static function countForUser(int $userId): array
    {
        $row = Database::fetchOne(
            "SELECT COUNT(*) as total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread
             FROM notifications WHERE user_id = ?",
            [$userId]
        );

        return [
            'total'  => (int)($row['total'] ?? 0),
            'unread' => (int)($row['unread'] ?? 0),
        ];
    }

    /**
     * 最近未读通知（导航栏下拉面板用，面板不显示发信人）
     */
    public static function recentUnread(int $userId, int $limit = 5): array
    {
        return Database::fetchAll(
            "SELECT id, type, title, content, target_type, target_id, created_at
             FROM notifications
             WHERE user_id = ? AND is_read = 0
             ORDER BY created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * 最近未读通知（前端轮询 ?detail=1 用，要带发信人信息）
     */
    public static function recentUnreadWithSender(int $userId, int $limit = 5): array
    {
        return Database::fetchAll(
            "SELECT n.id, n.type, n.title, n.content, n.target_type, n.target_id, n.created_at,
                    u.username as from_username, u.avatar as from_avatar
             FROM notifications n
             LEFT JOIN users u ON n.from_user_id = u.id
             WHERE n.user_id = ? AND n.is_read = 0
             ORDER BY n.created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * 删除某人的全部已读通知
     *
     * 只删已读：未读计数不受影响，所以不需要动 unread_notifications / 缓存。
     */
    public static function deleteRead(int $userId): int
    {
        return Database::execute(
            "DELETE FROM notifications WHERE user_id = ? AND is_read = 1",
            [$userId]
        );
    }

    /**
     * 开放 API 的通知列表（带来源用户信息）
     *
     * @return array{items: array, total: int}
     */
    public static function apiList(int $userId, int $limit, int $offset): array
    {
        $list = self::listForUser($userId, $limit, $offset, true);

        return ['items' => $list['items'], 'total' => $list['total']];
    }

    /**
     * 删除与某用户相关的全部通知（含他发出的，删用户级联用）
     */
    public static function deleteByUser(int $userId): int
    {
        $affected = Database::execute(
            "DELETE FROM notifications WHERE user_id = ? OR from_user_id = ?",
            [$userId, $userId]
        );
        Cache::delete(self::unreadCountKey($userId));

        return $affected;
    }

    /**
     * 删除整批广播：未读的先扣未读数，再整批删除（一个事务）
     *
     * @param array $head broadcastHead() 的返回值
     * @return int 删除条数
     */
    public static function deleteBroadcast(array $head): int
    {
        [$cond, $params] = self::fingerprintWhere($head);

        Database::useMaster();
        try {
            Database::beginTransaction();

            $rows = Database::fetchAll("SELECT user_id, is_read FROM notifications WHERE {$cond}", $params);
            foreach ($rows as $row) {
                if (!(int)$row['is_read']) {
                    Database::execute(
                        "UPDATE users SET unread_notifications = CASE WHEN unread_notifications > 0 THEN unread_notifications - 1 ELSE 0 END WHERE id = ?",
                        [(int)$row['user_id']]
                    );
                    Cache::delete(self::unreadCountKey((int)$row['user_id']));
                }
            }

            $deleted = Database::execute("DELETE FROM notifications WHERE {$cond}", $params);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        } finally {
            Database::restoreReadWrite();
        }

        return $deleted;
    }

    /**
     * 批次指纹 → WHERE 片段与参数（列全用 <=> 比较，兼容 content 为 NULL 的历史行）
     *
     * @param array $head
     * @return array{0: string, 1: array}
     */
    private static function fingerprintWhere(array $head): array
    {
        $cond = "type = ? AND from_user_id = ? AND created_at = ? AND target_type = ? AND target_id = ?
                 AND title = ? AND content <=> ?";

        return [$cond, [
            $head['type'], $head['from_user_id'], $head['created_at'],
            $head['target_type'], $head['target_id'], $head['title'], $head['content'],
        ]];
    }
}