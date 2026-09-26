<?php
/**
 * 帖子（主题）模型
 *
 * 由原 App\Repositories\ThreadRepo 迁移而来，方法名与语义保持一致。
 */

namespace App\Models;

use Core\Database;

class Thread extends Model
{
    protected static string $table = 'threads';

    private const FIND_BY_ID_SQL =
        "SELECT * FROM threads WHERE id = ? AND deleted_at IS NULL";

    private const DETAIL_SQL =
        "SELECT
                t.*,
                u.username,
                u.nickname,
                u.avatar,
                u.group_id,
                u.nickname_color,
                u.credits,
                g.name as group_name,
                f.name as forum_name
            FROM threads t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN user_groups g ON u.group_id = g.id
            LEFT JOIN forums f ON t.forum_id = f.id
            WHERE t.id = ? AND t.deleted_at IS NULL";

    public static function findById(int $id): ?array
    {
        return self::cachedOne(self::FIND_BY_ID_SQL, [$id], 120);
    }

    public static function exists(int $id): bool
    {
        return self::existsWhere('id = ?', [$id]);
    }

    /**
     * 写操作前的「新鲜」读取：锁定/权限判断不能吃 120 秒的行缓存
     *
     * @return array{forum_id: int, is_locked: int, user_id: int}|null
     */
    public static function getLockState(int $id): ?array
    {
        $row = Database::fetchOne(
            "SELECT forum_id, is_locked, user_id FROM threads WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return $row ?: null;
    }

    /**
     * 主题作者 id（打赏这类场合要按服务端的真实作者算，不信客户端传的 to_user_id）
     */
    public static function getAuthorId(int $id): int
    {
        $row = Database::fetchOne(
            "SELECT user_id FROM threads WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return (int)($row['user_id'] ?? 0);
    }

    /**
     * 主题作者 id，主题不存在返回 null
     *
     * 与 getAuthorId() 的区别：那个把「不存在」压成 0，调用方分不清
     * 「主题没了」和「作者 id 是 0」；打赏要给出不同的错误提示，所以需要 null。
     */
    public static function findAuthorId(int $id): ?int
    {
        $row = Database::fetchOne(
            "SELECT user_id FROM threads WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return $row === null ? null : (int)$row['user_id'];
    }

    /**
     * 主题作者 id（带 120 秒缓存，内容解析时每个隐藏标签都要用）
     *
     * 与 findAuthorId() 的区别：那个是新鲜读，用于权限/打赏这类不能吃缓存的判断。
     */
    public static function authorIdCached(int $id): ?int
    {
        $row = self::cachedOne(
            "SELECT user_id FROM threads WHERE id = ? AND deleted_at IS NULL",
            [$id],
            120
        );

        return $row === null ? null : (int)$row['user_id'];
    }

    /**
     * 主题所属板块 id（附件下载要按板块判权限）
     */
    public static function getForumId(int $id): ?int
    {
        $row = Database::fetchOne(
            "SELECT forum_id FROM threads WHERE id = ?",
            [$id]
        );

        return $row === null ? null : (int)$row['forum_id'];
    }

    /**
     * 主题正文（提取隐藏内容的积分价格用）
     */
    public static function getContent(int $id): ?string
    {
        $row = Database::fetchOne(
            "SELECT content FROM threads WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return $row === null ? null : (string)$row['content'];
    }

    /**
     * 精华级别（0 表示不是精华）
     */
    public static function getHighlightLevel(int $id): int
    {
        $row = Database::fetchOne(
            "SELECT is_highlight FROM threads WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return (int)($row['is_highlight'] ?? 0);
    }

    /**
     * 某个标签下的主题（列表页），带作者与板块名
     */
    public static function getByTag(int $tagId, int $limit = 20, int $offset = 0): array
    {
        return Database::fetchAll(
            "SELECT t.*, u.username, u.nickname, u.avatar, u.nickname_color, f.name as forum_name
             FROM thread_tags tt
             INNER JOIN threads t ON tt.thread_id = t.id
             LEFT JOIN users u ON t.user_id = u.id
             LEFT JOIN forums f ON t.forum_id = f.id
             WHERE tt.tag_id = ? AND t.deleted_at IS NULL
             ORDER BY t.created_at DESC
             LIMIT ? OFFSET ?",
            [$tagId, $limit, $offset]
        );
    }

    /**
     * 某个标签下的主题总数
     */
    public static function countByTag(int $tagId): int
    {
        $row = Database::fetchOne(
            "SELECT COUNT(*) as c FROM thread_tags tt
             INNER JOIN threads t ON tt.thread_id = t.id
             WHERE tt.tag_id = ? AND t.deleted_at IS NULL",
            [$tagId]
        );

        return (int)($row['c'] ?? 0);
    }

    // ------------------------------------------------------------------
    // 后台帖子管理：筛选、列表、批量改状态
    // ------------------------------------------------------------------

    /** 后台列表允许的排序字段（白名单，防止 ORDER BY 注入） */
    private const ADMIN_SORTS = [
        'id'          => 't.id',
        'views'       => 't.views',
        'reply_count' => 't.reply_count',
        'created_at'  => 't.created_at',
    ];

    /**
     * 后台筛选条件 → WHERE 片段 + 参数 + 排序
     *
     * 筛选与排序的白名单以前散在控制器里，跟着 SQL 一起搬过来，
     * 这样「怎么筛」只有一处定义。
     *
     * @param array{search?:string, forum_id?:int, username?:string, ip?:string,
     *              date_from?:string, date_to?:string, status?:string, sort?:string, dir?:string} $filters
     * @return array{where: string, params: array, sortCol: string, sortDir: string}
     */
    public static function adminQuery(array $filters): array
    {
        $where = 'WHERE t.deleted_at IS NULL';
        $params = [];

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where .= ' AND t.title LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $forumId = (int)($filters['forum_id'] ?? 0);
        if ($forumId > 0) {
            $where .= ' AND t.forum_id = ?';
            $params[] = $forumId;
        }

        $username = trim((string)($filters['username'] ?? ''));
        if ($username !== '') {
            $where .= ' AND t.username LIKE ?';
            $params[] = '%' . addcslashes($username, '%_\\') . '%';
        }

        $ip = trim((string)($filters['ip'] ?? ''));
        if ($ip !== '') {
            $where .= ' AND t.user_ip LIKE ?';
            $params[] = '%' . addcslashes($ip, '%_\\') . '%';
        }

        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $ts = strtotime($dateFrom);
            if ($ts) { $where .= ' AND t.created_at >= ?'; $params[] = $ts; }
        }

        $dateTo = trim((string)($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $ts = strtotime($dateTo . ' 23:59:59');
            if ($ts) { $where .= ' AND t.created_at <= ?'; $params[] = $ts; }
        }

        switch (trim((string)($filters['status'] ?? ''))) {
            case 'top':
                $where .= ' AND t.is_top > 0';
                break;
            case 'highlight':
                $where .= ' AND t.is_highlight = 1';
                break;
            case 'locked':
                $where .= ' AND t.is_locked = 1';
                break;
        }

        $sortCol = self::ADMIN_SORTS[(string)($filters['sort'] ?? 'id')] ?? 't.id';
        $sortDir = strtolower((string)($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return ['where' => $where, 'params' => $params, 'sortCol' => $sortCol, 'sortDir' => $sortDir];
    }

    /**
     * 后台帖子列表 + 总数（一次调用把列表页要的都算好）
     *
     * @return array{rows: array, total: int, page: int, pages: int, sortDir: string}
     */
    public static function adminList(array $filters, int $page = 1, int $limit = 20): array
    {
        $q = self::adminQuery($filters);
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM threads t {$q['where']}",
            $q['params']
        )['cnt'] ?? 0);

        $rows = Database::fetchAll(
            "SELECT t.*, f.name as forum_name
             FROM threads t
             LEFT JOIN forums f ON t.forum_id = f.id
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
     * 批量设置锁定状态
     *
     * @param int[] $threadIds
     */
    public static function setLockedBulk(array $threadIds, bool $locked): int
    {
        return self::updateBulkFlag($threadIds, 'is_locked', $locked ? 1 : 0);
    }

    /**
     * 批量设置置顶级别
     *
     * @param int[] $threadIds
     */
    public static function setTopBulk(array $threadIds, int $level): int
    {
        return self::updateBulkFlag($threadIds, 'is_top', max(0, $level));
    }

    /**
     * 批量设置精华级别
     *
     * @param int[] $threadIds
     */
    public static function setHighlightBulk(array $threadIds, int $level): int
    {
        return self::updateBulkFlag($threadIds, 'is_highlight', max(0, $level));
    }

    /**
     * 批量改挂到另一个板块（只改归属，板块计数由调用方按来源分组调整）
     *
     * @param int[] $threadIds
     */
    public static function setForumBulk(array $threadIds, int $forumId): int
    {
        $ids = array_values(array_filter(array_map('intval', $threadIds), static fn(int $id): bool => $id > 0));
        if (empty($ids) || $forumId <= 0) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $affected = Database::execute(
            "UPDATE threads SET forum_id = ?, updated_at = ? WHERE id IN ({$placeholders}) AND deleted_at IS NULL",
            array_merge([$forumId, time()], $ids)
        );

        self::forgetRowCachesFor($ids);

        return $affected;
    }

    /**
     * 调整主题的回复数（负数表示减少，且不会减到 0 以下）
     */
    public static function adjustReplyCount(int $threadId, int $delta): int
    {
        if ($delta === 0) {
            return 0;
        }

        return $delta > 0
            ? Database::execute("UPDATE threads SET reply_count = reply_count + ? WHERE id = ?", [$delta, $threadId])
            : Database::execute(
                "UPDATE threads SET reply_count = CASE WHEN reply_count >= ? THEN reply_count - ? ELSE 0 END WHERE id = ?",
                [-$delta, -$delta, $threadId]
            );
    }

    /**
     * 重算主题的「最后回复」信息
     *
     * 删掉回复之后必须重算，否则列表页还会显示被删掉那条回复的作者和时间。
     * 没有回复时回退到主题自身的作者与创建时间。
     */
    public static function refreshLastPost(int $threadId): void
    {
        $last = Database::fetchOne(
            "SELECT user_id, created_at FROM posts
             WHERE thread_id = ? AND deleted_at IS NULL
             ORDER BY created_at DESC, id DESC LIMIT 1",
            [$threadId]
        );

        if ($last) {
            Database::execute(
                "UPDATE threads SET last_post_user_id = ?, last_post_time = ? WHERE id = ?",
                [(int)$last['user_id'], (int)$last['created_at'], $threadId]
            );
        } else {
            $thread = Database::fetchOne("SELECT user_id, created_at FROM threads WHERE id = ?", [$threadId]);
            Database::execute(
                "UPDATE threads SET last_post_user_id = ?, last_post_time = ? WHERE id = ?",
                [(int)($thread['user_id'] ?? 0), (int)($thread['created_at'] ?? 0), $threadId]
            );
        }

        self::forgetRowCaches($threadId);
    }

    /**
     * 批量改一个枚举字段（列名来自本类内部常量，不接受外部传入）
     *
     * 返回「选中且确实存在的行数」，而不是数据库报告的受影响行数：
     * 后者在「值本来就是这个」时是 0（MySQL 只统计真正变化的行），会让
     * 「加精两篇已经加精的帖子」显示成「已加精 0 篇」，也会让日志莫名其妙地不写。
     * 同时它又把「提交了不存在的 id」排除掉了 —— 只按提交数量报成功才是真错。
     *
     * @param int[] $threadIds
     */
    private static function updateBulkFlag(array $threadIds, string $column, int $value): int
    {
        $ids = array_values(array_filter(array_map('intval', $threadIds), static fn(int $id): bool => $id > 0));
        if (empty($ids)) {
            return 0;
        }

        $allowed = ['is_locked', 'is_top', 'is_highlight'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("不允许批量修改字段 {$column}");
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $existing = Database::fetchAll(
            "SELECT id FROM threads WHERE id IN ({$placeholders}) AND deleted_at IS NULL",
            $ids
        );
        $existingIds = array_map('intval', array_column($existing, 'id'));
        if (empty($existingIds)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($existingIds), '?'));
        Database::execute(
            "UPDATE threads SET `{$column}` = ? WHERE id IN ({$ph})",
            array_merge([$value], $existingIds)
        );

        self::forgetRowCachesFor($existingIds);

        return count($existingIds);
    }

    public static function getDetail(int $threadId): ?array
    {
        return self::cachedOne(self::DETAIL_SQL, [$threadId], 120);
    }

    /**
     * 板块内主题列表
     *
     * @param string $orderBy lastpost|tid|replies|views
     * @param string $filter  ''|highlight|top
     */
    public static function getByForum(
        int $forumId,
        int $limit,
        int $offset,
        string $orderBy = 'lastpost',
        bool $asc = false,
        string $filter = ''
    ): array {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $orderCol = match ($orderBy) {
            'tid' => 't.created_at',
            'replies' => 't.reply_count',
            'views' => 't.views',
            default => 't.last_post_time',
        };
        $dir = $asc ? 'ASC' : 'DESC';

        // 置顶帖始终排在前面（反向查询时不显示置顶）
        $topOrder = $asc ? '' : 't.is_top DESC,';

        $filterSql = '';
        if ($filter === 'highlight') {
            $filterSql = ' AND t.is_highlight > 0';
        } elseif ($filter === 'top') {
            $filterSql = ' AND t.is_top > 0';
        }

        return Database::fetchAll(
            "SELECT
                t.*,
                u.username,
                u.nickname,
                u.avatar,
                u.credits,
                u.nickname_color,
                t.reply_count as reply_count_calc,
                lpu.username as last_post_username,
                lpu.nickname as last_post_nickname
            FROM threads t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN users lpu ON t.last_post_user_id = lpu.id
            WHERE t.forum_id = ? AND t.deleted_at IS NULL{$filterSql}
            ORDER BY {$topOrder} {$orderCol} {$dir}
            LIMIT ? OFFSET ?",
            [$forumId, $limit, $offset]
        );
    }

    /**
     * 全局置顶帖（is_top = 2）
     */
    public static function getGlobalTopThreads(): array
    {
        return self::cachedAll(
            "SELECT
                t.*,
                u.username,
                u.nickname,
                u.avatar,
                u.credits,
                u.nickname_color,
                t.reply_count as reply_count_calc,
                lpu.username as last_post_username,
                lpu.nickname as last_post_nickname
            FROM threads t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN users lpu ON t.last_post_user_id = lpu.id
            WHERE t.is_top = 2 AND t.deleted_at IS NULL
            ORDER BY t.updated_at DESC
            LIMIT 20",
            [],
            300
        );
    }

    // ------------------------------------------------------------------
    // 首页 / 全部帖子页的热 key
    //
    // key 名与后台、CronSvc 的删缓存调用成对（如 threads:latest:p1），不能改；
    // 列表统一用同一份列清单，避免三处 SELECT 各写一遍。
    // ------------------------------------------------------------------

    /** 列表页统一列（作者信息 + 板块名） */
    private const LIST_COLUMNS =
        "t.*, u.username, u.nickname, u.avatar, u.credits, u.nickname_color, f.name as forum_name";

    private const LIST_JOINS =
        "LEFT JOIN users u ON t.user_id = u.id
         LEFT JOIN forums f ON t.forum_id = f.id";

    /** 最新主题（key = threads:latest:pN） */
    public static function latestPageCached(int $page, int $perPage, int $offset): array
    {
        return self::staleAll(
            "threads:latest:p{$page}",
            "SELECT " . self::LIST_COLUMNS . "
             FROM threads t " . self::LIST_JOINS . "
             WHERE t.deleted_at IS NULL
             ORDER BY t.is_top DESC, t.created_at DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset],
            60
        );
    }

    /** 热门主题（按回复数，key = threads:hot:pN） */
    public static function hotPageCached(int $page, int $perPage, int $offset): array
    {
        return self::staleAll(
            "threads:hot:p{$page}",
            "SELECT " . self::LIST_COLUMNS . "
             FROM threads t " . self::LIST_JOINS . "
             WHERE t.deleted_at IS NULL
             ORDER BY t.is_top DESC, t.reply_count DESC, t.views DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset],
            300
        );
    }

    /** 精华主题（is_highlight >= 1，key = threads:featured:pN） */
    public static function featuredPageCached(int $page, int $perPage, int $offset): array
    {
        return self::staleAll(
            "threads:featured:p{$page}",
            "SELECT " . self::LIST_COLUMNS . "
             FROM threads t " . self::LIST_JOINS . "
             WHERE t.deleted_at IS NULL AND t.is_highlight >= 1
             ORDER BY t.is_highlight DESC, t.created_at DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset],
            600
        );
    }

    /** 未删除主题总数（key = threads:total_count） */
    public static function activeCountCached(): int
    {
        return self::staleCount(
            'threads:total_count',
            "SELECT COUNT(*) as c FROM threads WHERE deleted_at IS NULL",
            [],
            60
        );
    }

    /** 精华主题总数（key = threads:featured:count） */
    public static function featuredCountCached(): int
    {
        return self::staleCount(
            'threads:featured:count',
            "SELECT COUNT(*) as c FROM threads WHERE deleted_at IS NULL AND is_highlight >= 1",
            [],
            600
        );
    }

    /**
     * 全局置顶帖（key = threads:global_tops），全部帖子页第一页合并用
     *
     * 与 getGlobalTopThreads() 的区别：这里不限条数、不带最后回复人，
     * 且 key 与 ForumSvc 用的 threads:global_tops_forum 是两个用途。
     */
    public static function globalTopsCached(): array
    {
        return self::staleAll(
            'threads:global_tops',
            "SELECT " . self::LIST_COLUMNS . "
             FROM threads t " . self::LIST_JOINS . "
             WHERE t.is_top = 2 AND t.deleted_at IS NULL
             ORDER BY t.updated_at DESC",
            [],
            120
        );
    }

    /**
     * 全部帖子页列表（key = allthreads:{sort}:pN）
     *
     * sort 在这里再白名单一次：控制器已经过滤过，但 SQL 片段不能依赖调用方自觉。
     */
    public static function allThreadsCached(string $sort, int $page, int $perPage, int $offset): array
    {
        $order = match ($sort) {
            'hot'       => 't.reply_count DESC, t.views DESC',
            'highlight' => 't.is_highlight DESC, t.created_at DESC',
            default     => 't.created_at DESC',
        };
        $where = $sort === 'highlight' ? ' AND t.is_highlight = 1' : '';

        return self::staleAll(
            "allthreads:{$sort}:p{$page}",
            "SELECT " . self::LIST_COLUMNS . "
             FROM threads t " . self::LIST_JOINS . "
             WHERE t.deleted_at IS NULL{$where}
             ORDER BY t.is_top DESC, {$order}
             LIMIT ? OFFSET ?",
            [$perPage, $offset],
            60
        );
    }

    /** 全部帖子页总数（key = allthreads:count:{sort}） */
    public static function allThreadsCountCached(string $sort): int
    {
        $where = $sort === 'highlight' ? ' AND is_highlight = 1' : '';

        return self::staleCount(
            "allthreads:count:{$sort}",
            "SELECT COUNT(*) as c FROM threads WHERE deleted_at IS NULL{$where}",
            [],
            60
        );
    }

    public static function countByForum(int $forumId, string $filter = ''): int
    {
        $filterSql = '';
        if ($filter === 'highlight') {
            $filterSql = ' AND is_highlight > 0';
        } elseif ($filter === 'top') {
            $filterSql = ' AND is_top > 0';
        }

        $row = Database::fetchOne(
            "SELECT COUNT(*) as count FROM threads
             WHERE forum_id = ? AND deleted_at IS NULL{$filterSql}",
            [$forumId]
        );

        return (int)($row['count'] ?? 0);
    }

    public static function getLatest(int $limit = 10): array
    {
        return Database::fetchAll(
            "SELECT t.*, u.username, u.nickname, u.avatar, u.nickname_color
             FROM threads t
             LEFT JOIN users u ON t.user_id = u.id
             WHERE t.deleted_at IS NULL
             ORDER BY t.is_top DESC, t.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    public static function getByUser(int $userId, int $limit = 10, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        return Database::fetchAll(
            "SELECT * FROM threads
             WHERE user_id = ? AND deleted_at IS NULL
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    public static function create(int $forumId, int $userId, string $username, string $title, string $content): int
    {
        $now = time();
        // content_fmt 置 null：正文按 Markdown 源码存储，显示时由 Markdown::render() 解析
        // （与回复帖 Post::create 的处理保持一致）
        $contentFmt = null;
        $userIp = $_SERVER['REMOTE_ADDR'] ?? '';

        Database::execute(
            "INSERT INTO threads (
                forum_id, user_id, username, user_ip, title, content, content_fmt,
                created_at, updated_at, last_post_time, last_post_user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$forumId, $userId, $username, $userIp, $title, $content, $contentFmt, $now, $now, $now, $userId]
        );

        return Database::lastInsertId();
    }

    public static function incrementViews(int $threadId, int $count = 1): int
    {
        return Database::execute(
            "UPDATE threads SET views = views + ? WHERE id = ?",
            [$count, $threadId]
        );
    }

    public static function updateLastPost(int $threadId, int $userId): int
    {
        return Database::execute(
            "UPDATE threads
             SET reply_count = reply_count + 1,
                 last_post_time = ?,
                 last_post_user_id = ?
             WHERE id = ?",
            [time(), $userId, $threadId]
        );
    }

    public static function update(int $threadId, string $title, string $content): int
    {
        // 同 create：Markdown 源码存 content，content_fmt 留空走解析路径
        $affected = Database::execute(
            "UPDATE threads
             SET title = ?, content = ?, content_fmt = NULL, updated_at = ?
             WHERE id = ?",
            [$title, $content, time(), $threadId]
        );

        self::forgetRowCaches($threadId);

        return $affected;
    }

    public static function softDelete(int $threadId): int
    {
        $affected = Database::execute(
            "UPDATE threads SET deleted_at = ? WHERE id = ?",
            [time(), $threadId]
        );

        self::forgetRowCaches($threadId);

        return $affected;
    }

    public static function setTopLevel(int $threadId, int $level): int
    {
        $affected = Database::execute(
            "UPDATE threads SET is_top = ? WHERE id = ?",
            [$level, $threadId]
        );

        self::forgetRowCaches($threadId);

        return $affected;
    }

    public static function setDigest(int $threadId, int $level): int
    {
        $affected = Database::execute(
            "UPDATE threads SET is_highlight = ? WHERE id = ?",
            [max(0, min(3, $level)), $threadId]
        );

        self::forgetRowCaches($threadId);

        return $affected;
    }

    /**
     * 失效某条主题的行级缓存
     *
     * findById() / getDetail() 都走 cachedOne（TTL 120s），改库之后必须一并失效，
     * 否则编辑 / 删除 / 置顶 / 加精后，详情页与编辑页最长 120 秒仍读旧行。
     * 这两条 SQL 常量是缓存键的一部分，所以失效逻辑收在这里，调用方不用关心。
     */
    private static function forgetRowCaches(int $threadId): void
    {
        self::forgetOne(self::FIND_BY_ID_SQL, [$threadId]);
        self::forgetOne(self::DETAIL_SQL, [$threadId]);
    }

    /**
     * 批量失效若干主题的行级缓存
     *
     * 服务层里有些路径（批量置顶/锁定/删除/移动、点赞计数更新等）是直接写 SQL 的，
     * 它们只清了 getThreadDetail 用的 thread:{id} 缓存，没清 cachedOne 的行缓存 ——
     * 于是紧接着的读会拿到旧行，表现为「操作看起来没生效」。
     * 把行缓存失效也补上（调用方只需给一批 id）。
     *
     * @param int[] $threadIds
     */
    public static function forgetRowCachesFor(array $threadIds): void
    {
        foreach (array_unique(array_map('intval', $threadIds)) as $id) {
            if ($id > 0) {
                self::forgetRowCaches($id);
            }
        }
    }

    /**
     * 锁定 / 解锁
     *
     * 与 update/softDelete/setTopLevel/setDigest 同一约定：写完立刻失效行缓存。
     * （原来这段写在 ThreadSvc 里直接执行 SQL，没失效缓存，
     *   导致 120 秒内再次点击「解锁」会读到旧的 is_locked=0、又给锁上。）
     */
    public static function setLocked(int $threadId, bool $locked): int
    {
        $affected = Database::execute(
            "UPDATE threads SET is_locked = ?, updated_at = ? WHERE id = ?",
            [(int)$locked, time(), $threadId]
        );

        self::forgetRowCaches($threadId);

        return $affected;
    }
    /**
     * 未删除主题总数（开放 API 分页用）
     */
    public static function countActive(): int
    {
        return (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM threads WHERE deleted_at IS NULL"
        )['cnt'] ?? 0);
    }

    /**
     * 开放 API 的全站主题列表（精简列，置顶优先）
     */
    public static function apiListAll(int $limit, int $offset): array
    {
        return Database::fetchAll(
            "SELECT t.id, t.forum_id, t.user_id, t.username, t.title, t.views, t.reply_count,
                    t.is_top, t.is_highlight, t.created_at, t.last_post_time,
                    f.name as forum_name
             FROM threads t
             LEFT JOIN forums f ON t.forum_id = f.id
             WHERE t.deleted_at IS NULL
             ORDER BY t.is_top DESC, t.last_post_time DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    // ------------------------------------------------------------------
    // 定时任务（统计重建 / 浏览量落库）
    // ------------------------------------------------------------------

    /**
     * 每个板块的未删除主题数（一次 GROUP BY，替代逐板块 N+1）
     *
     * @return array<int, int> forum_id => 主题数
     */
    public static function forumThreadCounts(): array
    {
        $counts = [];
        foreach (Database::fetchAll(
            "SELECT forum_id, COUNT(*) as c FROM threads WHERE deleted_at IS NULL GROUP BY forum_id"
        ) as $row) {
            $counts[(int)$row['forum_id']] = (int)$row['c'];
        }

        return $counts;
    }

    /**
     * 最近有活动的主题 id（刷浏览量用）
     *
     * @return int[]
     */
    public static function idsUpdatedSince(int $since, int $limit = 1000): array
    {
        $rows = Database::fetchAll(
            "SELECT id FROM threads WHERE deleted_at IS NULL AND updated_at > ? ORDER BY id DESC LIMIT ?",
            [$since, $limit]
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * 批量累加浏览量（一条 CASE WHEN 写完一批）
     *
     * @param array<int, int> $viewsById thread_id => 本次新增浏览量
     */
    public static function addViewsBulk(array $viewsById): int
    {
        $viewsById = array_filter($viewsById, static fn(int $n): bool => $n > 0);
        if (empty($viewsById)) {
            return 0;
        }

        $ids = array_keys($viewsById);
        $cases = [];
        $params = [];
        foreach ($viewsById as $tid => $views) {
            $cases[] = 'WHEN id = ? THEN views + ?';
            array_push($params, $tid, $views);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($params, $ids);

        return Database::execute(
            'UPDATE threads SET views = CASE ' . implode(' ', $cases) . " ELSE views END WHERE id IN ({$placeholders})",
            $params
        );
    }

    /** 主题的点赞数（点赞接口回给前端的最新值） */
    public static function getLikes(int $id): int
    {
        $row = Database::fetchOne("SELECT likes FROM threads WHERE id = ?", [$id]);

        return (int)($row['likes'] ?? 0);
    }

    /**
     * 事务内加锁确认主题还在（删主题前用，避免并发删除）
     */
    public static function lockForDelete(int $threadId): bool
    {
        return Database::fetchOne(
            "SELECT id FROM threads WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
            [$threadId]
        ) !== null;
    }

    /**
     * 批量删除前取主题行（id / forum_id / user_id / reply_count）
     *
     * @param int[] $threadIds
     */
    public static function rowsForBulkDelete(array $threadIds): array
    {
        $threadIds = array_values(array_filter(array_map('intval', $threadIds)));
        if (empty($threadIds)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($threadIds), '?'));

        return Database::fetchAll(
            "SELECT id, forum_id, user_id, reply_count FROM threads WHERE id IN ({$ph}) AND deleted_at IS NULL",
            $threadIds
        );
    }

    /**
     * 批量移动前取主题行（排除已在目标板块的，并按源板块分组用）
     *
     * @param int[] $threadIds
     */
    public static function rowsForBulkMove(array $threadIds, int $targetForumId): array
    {
        $threadIds = array_values(array_filter(array_map('intval', $threadIds)));
        if (empty($threadIds)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($threadIds), '?'));

        return Database::fetchAll(
            "SELECT id, forum_id FROM threads WHERE id IN ({$ph}) AND deleted_at IS NULL AND forum_id != ?",
            array_merge($threadIds, [$targetForumId])
        );
    }

    // ------------------------------------------------------------------
    // 用户级联清理（删用户时用）
    // ------------------------------------------------------------------

    /** 某用户未删除主题数 */
    public static function countByUser(int $userId): int
    {
        return (int)(Database::fetchOne(
            "SELECT COUNT(*) as c FROM threads WHERE user_id = ? AND deleted_at IS NULL",
            [$userId]
        )['c'] ?? 0);
    }

    /**
     * 待级联删除的主题行（id / forum_id / reply_count，用于回退板块统计）
     */
    public static function rowsForUserDeletion(int $userId): array
    {
        return Database::fetchAll(
            "SELECT id, forum_id, reply_count FROM threads WHERE user_id = ? AND deleted_at IS NULL",
            [$userId]
        );
    }

    /**
     * 按 id 批量软删除
     *
     * @param int[] $threadIds
     */
    public static function softDeleteByIds(array $threadIds, int $deletedAt): int
    {
        $threadIds = array_values(array_filter(array_map('intval', $threadIds)));
        if (empty($threadIds)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($threadIds), '?'));

        return Database::execute(
            "UPDATE threads SET deleted_at = ? WHERE id IN ({$ph})",
            array_merge([$deletedAt], $threadIds)
        );
    }

    /**
     * 批量回退主题的回复数（一条 CASE WHEN 语句搞定）
     *
     * @param array<int, int> $decById thread_id => 需要扣减的数量
     */
    public static function decrementReplyCountBulk(array $decById): int
    {
        $ids = array_keys(array_filter($decById, static fn(int $n): bool => $n > 0));
        if (empty($ids)) {
            return 0;
        }

        $cases = [];
        $params = [];
        foreach ($ids as $id) {
            $dec = (int)$decById[$id];
            $cases[] = 'WHEN id = ? THEN CASE WHEN reply_count >= ? THEN reply_count - ? ELSE 0 END';
            array_push($params, $id, $dec, $dec);
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($params, $ids);

        return Database::execute(
            'UPDATE threads SET reply_count = CASE ' . implode(' ', $cases) . " ELSE reply_count END WHERE id IN ({$ph})",
            $params
        );
    }

    // ------------------------------------------------------------------
    // 板块级联清理（删板块时用）
    // ------------------------------------------------------------------

    /**
     * 某板块下未删除主题的条数与回复数合计
     *
     * 直接查库而不是读 forums.thread_count / post_count：删除板块要按真实数据回滚统计，
     * 缓存里的旧值不够准。
     *
     * @return array{thread_count: int, post_count: int}
     */
    public static function statsForForum(int $forumId): array
    {
        $row = Database::fetchOne(
            "SELECT COUNT(*) as thread_count, COALESCE(SUM(reply_count), 0) as post_count
             FROM threads WHERE forum_id = ? AND deleted_at IS NULL",
            [$forumId]
        );

        return [
            'thread_count' => (int)($row['thread_count'] ?? 0),
            'post_count'   => (int)($row['post_count'] ?? 0),
        ];
    }

    /**
     * 某板块下按作者汇总的主题数（删板块时逐个回退 users.thread_count）
     *
     * @return array<int, array{user_id: int, cnt: int}>
     */
    public static function authorCountsInForum(int $forumId): array
    {
        return Database::fetchAll(
            "SELECT user_id, COUNT(*) as cnt
             FROM threads WHERE forum_id = ? AND deleted_at IS NULL
             GROUP BY user_id",
            [$forumId]
        );
    }

    /** 软删除某板块下的全部主题 */
    public static function softDeleteByForum(int $forumId, int $deletedAt): int
    {
        return Database::execute(
            "UPDATE threads SET deleted_at = ? WHERE forum_id = ? AND deleted_at IS NULL",
            [$deletedAt, $forumId]
        );
    }

    // ------------------------------------------------------------------
    // 后台概览 / 监控 / sitemap
    // ------------------------------------------------------------------

    /** sitemap 用的主题列表（只要 id 与时间戳） */
    public static function allForSitemap(int $limit = 2000): array
    {
        return Database::fetchAll(
            "SELECT id, updated_at, created_at FROM threads WHERE deleted_at IS NULL
             ORDER BY updated_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /** 最新主题（后台仪表盘） */
    public static function recentlyCreated(int $limit = 5): array
    {
        return Database::fetchAll(
            "SELECT id, title, username, created_at
             FROM threads WHERE deleted_at IS NULL
             ORDER BY created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /** 某段时间内最热主题（后台监控页，按回复数再看浏览数） */
    public static function hotSince(int $since, int $limit = 10): array
    {
        return Database::fetchAll(
            "SELECT id, title, username, views, reply_count
             FROM threads
             WHERE created_at >= ? AND deleted_at IS NULL
             ORDER BY reply_count DESC, views DESC
             LIMIT ?",
            [$since, $limit]
        );
    }

    // ------------------------------------------------------------------
    // 搜索（策略在 SearchSvc：什么时候用 FULLTEXT、什么时候回退 LIKE）
    // ------------------------------------------------------------------

    /** 站内搜索的完整列（结果页要展示作者/板块/摘要） */
    private const SEARCH_COLUMNS =
        "t.*, f.name as forum_name, u.username, u.nickname, u.nickname_color";

    /** 开放 API 的精简列 */
    private const SEARCH_COLUMNS_SLIM =
        "t.id, t.title, t.username, t.views, t.reply_count, t.created_at, f.name as forum_name";

    /**
     * FULLTEXT 搜索（title + content 联合索引）
     */
    public static function searchFulltext(string $booleanQuery, int $limit, int $offset, bool $slim = false): array
    {
        $cols = $slim ? self::SEARCH_COLUMNS_SLIM : self::SEARCH_COLUMNS;
        $relevance = $slim ? '' : ', MATCH(t.title, t.content) AGAINST(? IN BOOLEAN MODE) as relevance';
        $order = $slim ? 't.created_at DESC' : 'relevance DESC, t.created_at DESC';

        $params = [$booleanQuery];
        if (!$slim) {
            $params[] = $booleanQuery;
        }
        $params[] = $limit;
        $params[] = $offset;

        return Database::fetchAll(
            "SELECT {$cols}{$relevance}
             FROM threads t
             LEFT JOIN forums f ON t.forum_id = f.id
             LEFT JOIN users u ON t.user_id = u.id
             WHERE t.deleted_at IS NULL AND MATCH(t.title, t.content) AGAINST(? IN BOOLEAN MODE)
             ORDER BY {$order}
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /**
     * LIKE 搜索（中文与 FULLTEXT 不可用时的回退）
     */
    public static function searchLike(string $escapedKeyword, int $limit, int $offset, bool $slim = false): array
    {
        $cols = $slim ? self::SEARCH_COLUMNS_SLIM : self::SEARCH_COLUMNS;
        $like = '%' . $escapedKeyword . '%';

        return Database::fetchAll(
            "SELECT {$cols}
             FROM threads t
             LEFT JOIN forums f ON t.forum_id = f.id
             LEFT JOIN users u ON t.user_id = u.id
             WHERE t.deleted_at IS NULL AND (t.title LIKE ? OR t.content LIKE ?)
             ORDER BY t.created_at DESC
             LIMIT ? OFFSET ?",
            [$like, $like, $limit, $offset]
        );
    }

    public static function countSearchFulltext(string $booleanQuery): int
    {
        return (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM threads WHERE deleted_at IS NULL AND MATCH(title, content) AGAINST(? IN BOOLEAN MODE)",
            [$booleanQuery]
        )['cnt'] ?? 0);
    }

    /**
     * LIKE 计数：套一层子查询限行，避免全表扫描算出天文数字
     */
    public static function countSearchLike(string $escapedKeyword, int $cap = 10000): int
    {
        $like = '%' . $escapedKeyword . '%';

        return min($cap, (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM (
                 SELECT 1 FROM threads WHERE deleted_at IS NULL AND (title LIKE ? OR content LIKE ?) LIMIT ?
             ) t",
            [$like, $like, $cap]
        )['cnt'] ?? 0));
    }
}