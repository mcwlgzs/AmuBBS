<?php
/**
 * 回复模型
 *
 * 由原 App\Repositories\PostRepo 迁移而来，方法名与语义保持一致。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class Post extends Model
{
    protected static string $table = 'posts';

    /** 列表缓存时长（秒）：热门前几页用短缓存压住 4 表 JOIN 的开销 */
    private const LIST_CACHE_TTL = 30;

    public static function findById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT p.*, u.username, u.nickname, u.avatar, u.group_id, u.nickname_color
             FROM posts p
             LEFT JOIN users u ON p.user_id = u.id
             WHERE p.id = ? AND p.deleted_at IS NULL",
            [$id]
        );
    }

    public static function exists(int $id): bool
    {
        return self::existsWhere('id = ?', [$id]);
    }

    /**
     * 这条回复是否属于该主题（引用回复时校验，防止跨主题引用）
     */
    public static function existsInThread(int $id, int $threadId): bool
    {
        return Database::fetchOne(
            "SELECT id FROM posts WHERE id = ? AND thread_id = ? AND deleted_at IS NULL",
            [$id, $threadId]
        ) !== null;
    }

    /**
     * 回复作者 id（打赏这类场合按服务端的真实作者算）
     */
    public static function getAuthorId(int $id): int
    {
        $row = Database::fetchOne(
            "SELECT user_id FROM posts WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return (int)($row['user_id'] ?? 0);
    }

    /**
     * 回复作者 id，回复不存在返回 null（打赏要区分「评论没了」与「作者不匹配」）
     */
    public static function findAuthorId(int $id): ?int
    {
        $row = Database::fetchOne(
            "SELECT user_id FROM posts WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return $row === null ? null : (int)$row['user_id'];
    }

    // ------------------------------------------------------------------
    // 后台回帖管理
    // ------------------------------------------------------------------

    /** 后台列表允许的排序字段（白名单） */
    private const ADMIN_SORTS = [
        'id'         => 'p.id',
        'created_at' => 'p.created_at',
    ];

    /**
     * 后台筛选条件 → WHERE + 参数 + 排序
     *
     * @param array{search?:string, username?:string, thread_id?:int, ip?:string, sort?:string, dir?:string} $filters
     * @return array{where: string, params: array, sortCol: string, sortDir: string}
     */
    public static function adminQuery(array $filters): array
    {
        $where = 'WHERE p.deleted_at IS NULL';
        $params = [];

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where .= ' AND p.content LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $username = trim((string)($filters['username'] ?? ''));
        if ($username !== '') {
            $where .= ' AND p.username LIKE ?';
            $params[] = '%' . addcslashes($username, '%_\\') . '%';
        }

        $threadId = (int)($filters['thread_id'] ?? 0);
        if ($threadId > 0) {
            $where .= ' AND p.thread_id = ?';
            $params[] = $threadId;
        }

        $ip = trim((string)($filters['ip'] ?? ''));
        if ($ip !== '') {
            $where .= ' AND p.user_ip LIKE ?';
            $params[] = '%' . addcslashes($ip, '%_\\') . '%';
        }

        $sortCol = self::ADMIN_SORTS[(string)($filters['sort'] ?? 'id')] ?? 'p.id';
        $sortDir = strtolower((string)($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return ['where' => $where, 'params' => $params, 'sortCol' => $sortCol, 'sortDir' => $sortDir];
    }

    /**
     * 后台回帖列表 + 总数
     *
     * @return array{rows: array, total: int, page: int, pages: int, sortDir: string}
     */
    public static function adminList(array $filters, int $page = 1, int $limit = 20): array
    {
        $q = self::adminQuery($filters);
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM posts p {$q['where']}",
            $q['params']
        )['cnt'] ?? 0);

        $rows = Database::fetchAll(
            "SELECT p.*, t.title as thread_title
             FROM posts p
             LEFT JOIN threads t ON p.thread_id = t.id
             {$q['where']}
             ORDER BY {$q['sortCol']} {$q['sortDir']}
             LIMIT ? OFFSET ?",
            array_merge($q['params'], [$limit, $offset])
        );

        return [
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'pages'   => max(1, (int)ceil($total / $limit)),
            'sortDir' => $q['sortDir'],
        ];
    }

    /**
     * 待删除回帖的上下文（主题、作者、板块），批量删与单条删共用
     *
     * @param int[] $ids
     * @return array<int, array{id: int, thread_id: int, user_id: int, forum_id: int}>
     */
    public static function getDeleteContexts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if (empty($ids)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll(
            "SELECT p.id, p.thread_id, p.user_id, COALESCE(t.forum_id, 0) as forum_id
             FROM posts p
             LEFT JOIN threads t ON p.thread_id = t.id
             WHERE p.id IN ({$ph}) AND p.deleted_at IS NULL",
            $ids
        );

        return array_map(static fn(array $r): array => [
            'id'        => (int)$r['id'],
            'thread_id' => (int)$r['thread_id'],
            'user_id'   => (int)$r['user_id'],
            'forum_id'  => (int)$r['forum_id'],
        ], $rows);
    }

    /**
     * 批量软删除（只动确实活着的行）
     *
     * @param int[] $ids
     */
    public static function softDeleteBulk(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if (empty($ids)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $affected = Database::execute(
            "UPDATE posts SET deleted_at = ? WHERE id IN ({$ph}) AND deleted_at IS NULL",
            array_merge([time()], $ids)
        );

        foreach (self::threadIdsOf($ids) as $tid) {
            self::forgetCaches($tid);
        }

        return $affected;
    }

    /**
     * 这些回帖属于哪些主题
     *
     * @param int[] $ids
     * @return int[]
     */
    private static function threadIdsOf(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll("SELECT DISTINCT thread_id FROM posts WHERE id IN ({$ph})", $ids);

        return array_map('intval', array_column($rows, 'thread_id'));
    }

    /**
     * 清掉某个主题下与回复有关的缓存
     *
     * 回复增删改都要调用：列表片段（posts:thread:*）、回复数（posts:count:*）、
     * 详情页的 thread:{id} 以及 threads 行的 cachedOne 缓存。
     * 以前这套清理在 ThreadSvc 里叫 clearPostCache()，但后台删除回帖那条路完全没调它，
     * 于是删完回帖后列表和计数最长 30 秒还是旧的。收进模型，谁改谁负责。
     */
    public static function forgetCaches(int $threadId): void
    {
        if ($threadId <= 0) {
            return;
        }

        // 回复列表的 key 是**确定性**的（order × 前 3 页，见 getByThread()），
        // 所以这里直接点名删除。以前用 deletePattern("posts:thread:{$threadId}:*")：
        // 文件驱动下 deletePattern = 扫整个缓存目录 + 逐文件读 key + 正则匹配，
        // 而发帖/编辑/删除回复是站内最热的写路径，每次都要付这份全树扫描成本。
        $keys = [];
        foreach (['asc', 'desc'] as $order) {
            for ($page = 1; $page <= 3; $page++) {
                $keys[] = "posts:thread:{$threadId}:{$order}:p{$page}";
            }
        }

        Cache::deleteMulti($keys);
        Cache::delete("posts:count:{$threadId}");
        Cache::delete("thread:{$threadId}");
        Thread::forgetRowCachesFor([$threadId]);
    }

    /**
     * 主题内的回复列表
     *
     * @param int $authorOnly 只看某作者（0 表示不筛选）
     */
    public static function getByThread(int $threadId, int $limit, int $offset, string $order = 'asc', int $authorOnly = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        // 前 3 页默认排序缓存，减少 4 表 JOIN 开销
        $page = $offset > 0 ? (int)($offset / $limit) + 1 : 1;
        $useCache = $page <= 3 && $authorOnly === 0;

        $cacheKey = "posts:thread:{$threadId}:{$order}:p{$page}";
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $orderSql = $order === 'desc' ? 'p.created_at DESC' : 'p.created_at ASC';
        $authorWhere = $authorOnly > 0 ? ' AND p.user_id = ?' : '';

        $params = [$threadId];
        if ($authorOnly > 0) {
            $params[] = $authorOnly;
        }
        $params[] = $limit;
        $params[] = $offset;

        $result = Database::fetchAll(
            "SELECT
                p.*,
                u.username,
                u.nickname,
                u.avatar,
                u.group_id,
                u.nickname_color,
                u.post_count as user_post_count,
                qp.content as quote_content,
                qu.username as quote_username,
                qu.nickname as quote_nickname,
                qp.floor as quote_floor,
                qp.created_at as quote_created_at
            FROM posts p
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN posts qp ON p.quote_post_id = qp.id AND qp.deleted_at IS NULL
            LEFT JOIN users qu ON qp.user_id = qu.id
            WHERE p.thread_id = ? AND p.deleted_at IS NULL {$authorWhere}
            ORDER BY {$orderSql}
            LIMIT ? OFFSET ?",
            $params
        );

        if ($useCache) {
            Cache::set($cacheKey, $result, self::LIST_CACHE_TTL);
        }

        return $result;
    }

    public static function countByThread(int $threadId, int $authorOnly = 0): int
    {
        $cacheKey = "posts:count:{$threadId}";

        if ($authorOnly === 0) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return (int)$cached;
            }
        }

        $authorWhere = $authorOnly > 0 ? ' AND user_id = ?' : '';
        $params = [$threadId];
        if ($authorOnly > 0) {
            $params[] = $authorOnly;
        }

        $row = Database::fetchOne(
            "SELECT COUNT(*) as count FROM posts
             WHERE thread_id = ? AND deleted_at IS NULL{$authorWhere}",
            $params
        );
        $count = (int)($row['count'] ?? 0);

        if ($authorOnly === 0) {
            Cache::set($cacheKey, $count, self::LIST_CACHE_TTL);
        }

        return $count;
    }

    /**
     * 创建回复
     *
     * 必须在事务内调用：楼层号用 SELECT ... FOR UPDATE 计算，依赖事务上下文。
     */
    public static function create(int $threadId, int $userId, string $username, string $content, int $quotePostId = 0): int
    {
        $now = time();
        $userIp = $_SERVER['REMOTE_ADDR'] ?? '';

        // 原子计算楼层号，防止并发出现重复楼层
        $maxFloor = Database::fetchOne(
            "SELECT MAX(floor) as mf FROM posts WHERE thread_id = ? FOR UPDATE",
            [$threadId]
        );
        $floor = ((int)($maxFloor['mf'] ?? 0)) + 1;

        Database::execute(
            "INSERT INTO posts (thread_id, user_id, username, user_ip, content, content_fmt, quote_post_id, floor, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$threadId, $userId, $username, $userIp, $content, null, $quotePostId, $floor, $now, $now]
        );

        return Database::lastInsertId();
    }

    public static function update(int $id, string $content): void
    {
        Database::execute(
            "UPDATE posts SET content = ?, content_fmt = NULL, updated_at = ? WHERE id = ?",
            [$content, time(), $id]
        );
    }

    public static function softDelete(int $id): void
    {
        Database::execute(
            "UPDATE posts SET deleted_at = ? WHERE id = ?",
            [time(), $id]
        );
    }
    /**
     * 批量取「帖子 id => 所属主题 id」映射（积分流水的 related_id 要跳到主题用）
     *
     * @param int[] $postIds
     * @return array<int, int>
     */
    public static function threadIdMap(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));
        if (empty($postIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $rows = Database::fetchAll(
            "SELECT id, thread_id FROM posts WHERE id IN ({$placeholders})",
            $postIds
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = (int)$row['thread_id'];
        }

        return $map;
    }

    /**
     * 该用户在这个主题下回复过没有（「评论可见」判断，60 秒缓存）
     */
    public static function hasUserRepliedInThread(int $threadId, int $userId): bool
    {
        return self::cachedOne(
            "SELECT id FROM posts WHERE thread_id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1",
            [$threadId, $userId],
            60
        ) !== null;
    }

    /**
     * 事务内加锁取某主题下回复的作者 id（删主题时按作者回退 post_count）
     *
     * @return int[]
     */
    public static function lockAuthorIdsInThread(int $threadId): array
    {
        $rows = Database::fetchAll(
            "SELECT user_id FROM posts WHERE thread_id = ? AND deleted_at IS NULL FOR UPDATE",
            [$threadId]
        );

        return array_map('intval', array_column($rows, 'user_id'));
    }

    /**
     * 取一批主题下回复的作者 id（批量删除用，不加锁）
     *
     * @param int[] $threadIds
     * @return int[]
     */
    public static function authorIdsInThreads(array $threadIds): array
    {
        $threadIds = array_values(array_filter(array_map('intval', $threadIds)));
        if (empty($threadIds)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($threadIds), '?'));

        $rows = Database::fetchAll(
            "SELECT user_id FROM posts WHERE thread_id IN ({$ph}) AND deleted_at IS NULL",
            $threadIds
        );

        return array_map('intval', array_column($rows, 'user_id'));
    }

    /** 软删除某主题下的全部回复 */
    public static function softDeleteByThread(int $threadId, int $deletedAt): int
    {
        return Database::execute(
            "UPDATE posts SET deleted_at = ? WHERE thread_id = ? AND deleted_at IS NULL",
            [$deletedAt, $threadId]
        );
    }

    /**
     * 软删除一批主题下的全部回复
     *
     * @param int[] $threadIds
     */
    public static function softDeleteByThreadIds(array $threadIds, int $deletedAt): int
    {
        $threadIds = array_values(array_filter(array_map('intval', $threadIds)));
        if (empty($threadIds)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($threadIds), '?'));

        return Database::execute(
            "UPDATE posts SET deleted_at = ? WHERE thread_id IN ({$ph}) AND deleted_at IS NULL",
            array_merge([$deletedAt], $threadIds)
        );
    }

    /**
     * 一批主题下未删除回复的总数（批量移动时同步板块 post_count）
     *
     * @param int[] $threadIds
     */
    public static function countActiveByThreadIds(array $threadIds): int
    {
        $threadIds = array_values(array_filter(array_map('intval', $threadIds)));
        if (empty($threadIds)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($threadIds), '?'));

        return (int)(Database::fetchOne(
            "SELECT COUNT(*) as c FROM posts WHERE thread_id IN ({$ph}) AND deleted_at IS NULL",
            $threadIds
        )['c'] ?? 0);
    }

    // ------------------------------------------------------------------
    // 定时任务（统计重建）
    // ------------------------------------------------------------------

    /**
     * 每个板块的未删除回复数（一次 GROUP BY，替代逐板块 N+1）
     *
     * @return array<int, int> forum_id => 回复数
     */
    public static function forumPostCounts(): array
    {
        $counts = [];
        foreach (Database::fetchAll(
            "SELECT t.forum_id, COUNT(*) as c
             FROM posts p
             INNER JOIN threads t ON p.thread_id = t.id
             WHERE t.deleted_at IS NULL AND p.deleted_at IS NULL
             GROUP BY t.forum_id"
        ) as $row) {
            $counts[(int)$row['forum_id']] = (int)$row['c'];
        }

        return $counts;
    }

    // ------------------------------------------------------------------
    // 用户级联清理（删用户时用）
    // ------------------------------------------------------------------

    /**
     * 某用户的回复列表（带所属主题标题）
     */
    public static function listByUserWithThread(int $userId, int $limit = 10): array
    {
        return Database::fetchAll(
            "SELECT p.*, t.title as thread_title
             FROM posts p
             LEFT JOIN threads t ON p.thread_id = t.id
             WHERE p.user_id = ? AND p.deleted_at IS NULL
             ORDER BY p.created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * 待级联删除的回复行（id / thread_id / 所属板块）
     */
    public static function rowsForUserDeletion(int $userId): array
    {
        return Database::fetchAll(
            "SELECT p.id, p.thread_id, t.forum_id
             FROM posts p
             LEFT JOIN threads t ON p.thread_id = t.id
             WHERE p.user_id = ? AND p.deleted_at IS NULL",
            [$userId]
        );
    }

    /**
     * 按 id 批量软删除
     *
     * @param int[] $postIds
     */
    public static function softDeleteByIds(array $postIds, int $deletedAt): int
    {
        $postIds = array_values(array_filter(array_map('intval', $postIds)));
        if (empty($postIds)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($postIds), '?'));

        return Database::execute(
            "UPDATE posts SET deleted_at = ? WHERE id IN ({$ph})",
            array_merge([$deletedAt], $postIds)
        );
    }

    // ------------------------------------------------------------------
    // 板块级联清理（删板块时用）
    // ------------------------------------------------------------------

    /**
     * 某板块下按作者汇总的回复数（删板块时逐个回退 users.post_count）
     *
     * @return array<int, array{user_id: int, cnt: int}>
     */
    public static function authorCountsInForum(int $forumId): array
    {
        return Database::fetchAll(
            "SELECT user_id, COUNT(*) as cnt
             FROM posts
             WHERE thread_id IN (SELECT id FROM threads WHERE forum_id = ? AND deleted_at IS NULL)
               AND deleted_at IS NULL
             GROUP BY user_id",
            [$forumId]
        );
    }

    /** 软删除某板块下全部主题的回复 */
    public static function softDeleteByForum(int $forumId, int $deletedAt): int
    {
        return Database::execute(
            "UPDATE posts SET deleted_at = ?
             WHERE thread_id IN (SELECT id FROM threads WHERE forum_id = ? AND deleted_at IS NULL)
               AND deleted_at IS NULL",
            [$deletedAt, $forumId]
        );
    }

    // ------------------------------------------------------------------
    // 搜索（策略在 SearchSvc）
    // ------------------------------------------------------------------

    private const SEARCH_COLUMNS =
        "p.*, t.title as thread_title, t.forum_id, u.username, u.nickname, u.nickname_color";

    private const SEARCH_COLUMNS_SLIM =
        "p.id, p.thread_id, p.username, p.content, p.created_at, t.title as thread_title";

    public static function searchFulltext(string $booleanQuery, int $limit, int $offset, bool $slim = false): array
    {
        $cols = $slim ? self::SEARCH_COLUMNS_SLIM : self::SEARCH_COLUMNS;
        $relevance = $slim ? '' : ', MATCH(p.content) AGAINST(? IN BOOLEAN MODE) as relevance';
        $order = $slim ? 'p.created_at DESC' : 'relevance DESC, p.created_at DESC';

        $params = [$booleanQuery];
        if (!$slim) {
            $params[] = $booleanQuery;
        }
        $params[] = $limit;
        $params[] = $offset;

        return Database::fetchAll(
            "SELECT {$cols}{$relevance}
             FROM posts p
             LEFT JOIN threads t ON p.thread_id = t.id
             LEFT JOIN users u ON p.user_id = u.id
             WHERE p.deleted_at IS NULL AND MATCH(p.content) AGAINST(? IN BOOLEAN MODE)
             ORDER BY {$order}
             LIMIT ? OFFSET ?",
            $params
        );
    }

    public static function searchLike(string $escapedKeyword, int $limit, int $offset, bool $slim = false): array
    {
        $cols = $slim ? self::SEARCH_COLUMNS_SLIM : self::SEARCH_COLUMNS;

        return Database::fetchAll(
            "SELECT {$cols}
             FROM posts p
             LEFT JOIN threads t ON p.thread_id = t.id
             LEFT JOIN users u ON p.user_id = u.id
             WHERE p.deleted_at IS NULL AND p.content LIKE ?
             ORDER BY p.created_at DESC
             LIMIT ? OFFSET ?",
            ['%' . $escapedKeyword . '%', $limit, $offset]
        );
    }

    public static function countSearchFulltext(string $booleanQuery): int
    {
        return (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM posts WHERE deleted_at IS NULL AND MATCH(content) AGAINST(? IN BOOLEAN MODE)",
            [$booleanQuery]
        )['cnt'] ?? 0);
    }

    public static function countSearchLike(string $escapedKeyword, int $cap = 10000): int
    {
        return min($cap, (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM (
                 SELECT 1 FROM posts WHERE deleted_at IS NULL AND content LIKE ? LIMIT ?
             ) t",
            ['%' . $escapedKeyword . '%', $cap]
        )['cnt'] ?? 0));
    }
}