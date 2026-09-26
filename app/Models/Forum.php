<?php
/**
 * 板块模型
 *
 * 由原 App\Repositories\ForumRepo 迁移而来，方法名与语义保持一致。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class Forum extends Model
{
    protected static string $table = 'forums';

    private const FIND_BY_ID_SQL =
        "SELECT * FROM forums WHERE id = ? AND deleted_at IS NULL";

    private const TOP_FORUMS_SQL =
        "SELECT * FROM forums WHERE parent_id = 0 AND deleted_at IS NULL ORDER BY `rank` DESC";

    /** 全部板块（按层级 + 权重排序），发帖时选板块用 */
    private const ALL_ORDERED_SQL =
        "SELECT * FROM forums WHERE deleted_at IS NULL ORDER BY parent_id ASC, `rank` DESC";

    /** 扁平板块列表（选择器用） */
    private const PICKER_SQL =
        "SELECT id, parent_id, name FROM forums WHERE deleted_at IS NULL ORDER BY parent_id ASC, `rank` DESC";

    private const CHILDREN_SQL =
        "SELECT * FROM forums WHERE parent_id = ? AND deleted_at IS NULL ORDER BY `rank` DESC";

    public static function findById(int $id): ?array
    {
        return self::cachedOne(self::FIND_BY_ID_SQL, [$id], 600);
    }

    public static function exists(int $id): bool
    {
        return self::existsWhere('id = ?', [$id]);
    }

    public static function getTopForums(): array
    {
        return self::cachedAll(self::TOP_FORUMS_SQL, [], 600);
    }

    public static function getChildren(int $parentId): array
    {
        return self::cachedAll(self::CHILDREN_SQL, [$parentId], 600);
    }

    /**
     * 全部板块（按层级 + 权重排序），发帖时选板块用
     */
    public static function getAllOrdered(): array
    {
        return self::cachedAll(self::ALL_ORDERED_SQL, [], 3600);
    }

    /**
     * 首页/板块页用的顶级板块（key = forums:list）
     *
     * 与 ForumSvc、后台的 Cache::delete('forums:list') 成对：key 名不能改。
     */
    public static function topLevelCached(): array
    {
        return self::staleAll(
            'forums:list',
            "SELECT * FROM forums WHERE parent_id = 0 AND deleted_at IS NULL ORDER BY `rank` DESC",
            [],
            3600
        );
    }

    /**
     * 全部子板块的原始查询（parent_id > 0）
     *
     * 与 childrenCached() 分开：ForumSvc 在**自己的** forums:list 缓存闭包里也要拿这份数据，
     * 如果那里调用 childrenCached()，就会在 SWR 重建过程中嵌套同一个锁，拿到空数组。
     */
    public static function childrenRows(): array
    {
        return Database::fetchAll(
            "SELECT * FROM forums WHERE parent_id > 0 AND deleted_at IS NULL ORDER BY `rank` DESC"
        );
    }

    /**
     * 扁平板块列表（id / parent_id / name，选择器用，带缓存）
     */
    public static function pickerCached(): array
    {
        return self::cachedAll(self::PICKER_SQL, [], 600);
    }

    /** 该板块下还有没有未删除的子板块 */
    public static function hasChildren(int $forumId): bool
    {
        return Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM forums WHERE parent_id = ? AND deleted_at IS NULL",
            [$forumId]
        )['cnt'] > 0;
    }

    /**
     * 全部子板块一次取回（key = forums:children:all），调用方自己按 parent_id 分组，避免 N+1
     */
    public static function childrenCached(): array
    {
        return self::staleFetch('forums:children:all', static fn(): array => self::childrenRows(), 3600) ?? [];
    }

    /**
     * 子板块按 parent_id 分组
     *
     * 「顶级板块 + 挂上各自子板块」这段分组以前在首页、板块页、ForumSvc 各写过一遍，
     * 统一到这里：以后加字段/改分组规则只改一处，不会三处走样。
     */
    public static function groupByParent(array $children): array
    {
        $map = [];
        foreach ($children as $child) {
            $map[(int)($child['parent_id'] ?? 0)][] = $child;
        }

        return $map;
    }

    /**
     * 顶级板块 + children（首页 / 板块页用，两级都走各自缓存）
     *
     * 子板块取失败时保持「只有顶级板块」的降级形态并记日志：
     * 首页不该因为子板块查询出问题就整页 500。
     */
    public static function topWithChildrenCached(): array
    {
        $forums = self::topLevelCached();

        $children = [];
        try {
            $children = self::childrenCached();
        } catch (\Throwable $e) {
            error_log('[Forum] childrenCached failed: ' . $e->getMessage());
        }

        $map = self::groupByParent($children);
        foreach ($forums as &$forum) {
            // 兼容两种主键写法（历史上有调用方用 forum_id 别名）
            $fid = (int)($forum['id'] ?? $forum['forum_id'] ?? 0);
            $forum['children'] = $map[$fid] ?? [];
        }
        unset($forum);

        return $forums;
    }

    /**
     * 「每板块最后一条主题」的缓存 key
     *
     * 这个 key 必须带参数指纹：原来用固定 key 'forums:latest_threads'，
     * 而 SQL 是按 $forumIds 拼 IN 的，不同板块集合会共用同一份缓存 → 直接返回错数据。
     */
    public static function latestThreadsCacheKey(array $forumIds): string
    {
        $forumIds = array_values(array_filter(array_map('intval', $forumIds)));

        return 'forums:latest_threads:' . md5(implode(',', $forumIds));
    }

    /**
     * 每个板块的最后一条主题（首页板块列表右侧显示用）
     *
     * @param int[] $forumIds
     */
    public static function latestThreadsCached(array $forumIds): array
    {
        $forumIds = array_values(array_filter(array_map('intval', $forumIds)));
        if (empty($forumIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($forumIds), '?'));

        return self::staleAll(
            self::latestThreadsCacheKey($forumIds),
            "SELECT t.id, t.title, t.username, t.created_at, t.forum_id
             FROM threads t
             INNER JOIN (
                 SELECT forum_id, MAX(id) as max_id
                 FROM threads
                 WHERE forum_id IN ({$placeholders}) AND deleted_at IS NULL
                 GROUP BY forum_id
             ) latest ON t.id = latest.max_id",
            $forumIds,
            300
        );
    }

    /**
     * sitemap 用的板块列表（只要 id 与更新时间）
     *
     * 注意排序用的是真实存在的 `rank` 列：这里原来写的是 `sort_order`，
     * 而 forums 表根本没有这个列，导致冷缓存下 /sitemap.xml 直接抛
     * 「Unknown column 'sort_order'」，页面上却因为异常页也是 200 而被冒烟测漏掉。
     */
    public static function allForSitemap(int $limit = 200): array
    {
        return Database::fetchAll(
            "SELECT id, updated_at FROM forums WHERE deleted_at IS NULL ORDER BY `rank` DESC, id ASC LIMIT ?",
            [$limit]
        );
    }

    /**
     * 开放 API 的板块列表（精简列，不含敏感/无用字段）
     */
    public static function apiList(): array
    {
        return Database::fetchAll(
            "SELECT id, parent_id, name, description, icon, thread_count, post_count
             FROM forums WHERE deleted_at IS NULL
             ORDER BY parent_id ASC, `rank` DESC"
        );
    }

    /**
     * 开放 API 的板块详情
     */
    public static function apiDetail(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT id, parent_id, name, description, icon, thread_count, post_count
             FROM forums WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    }

    /**
     * 后台筛选下拉用的精简列表（只要 id + 名字）
     */
    public static function getOptions(): array
    {
        return Database::fetchAll(
            "SELECT id, name FROM forums WHERE deleted_at IS NULL ORDER BY `rank` DESC, id ASC"
        );
    }

    /**
     * 调整板块的帖子数（正数加、负数减且不为负）
     */
    public static function adjustThreadCount(int $forumId, int $delta): int
    {
        if ($delta === 0) {
            return 0;
        }

        return $delta > 0
            ? Database::execute("UPDATE forums SET thread_count = thread_count + ? WHERE id = ?", [$delta, $forumId])
            : Database::execute(
                "UPDATE forums SET thread_count = CASE WHEN thread_count >= ? THEN thread_count - ? ELSE 0 END WHERE id = ?",
                [-$delta, -$delta, $forumId]
            );
    }

    /**
     * 一次 GROUP BY 重建所有板块的统计（thread_count / post_count）
     *
     * 由 CronSvc 的「重建板块统计」任务调用：真实计数分别来自 Thread / Post 模型，
     * 这里只负责把结果一次写回（一条 CASE WHEN，避免逐板块 N+1）。
     *
     * @return int 处理的板块数
     */
    public static function rebuildStats(): int
    {
        $threadMap = Thread::forumThreadCounts();
        $postMap = Post::forumPostCounts();

        $ids = self::allIds();
        if (empty($ids)) {
            return 0;
        }

        $threadCases = [];
        $postCases = [];
        $threadParams = [];
        $postParams = [];
        foreach ($ids as $fid) {
            $threadCases[] = 'WHEN id = ? THEN ?';
            array_push($threadParams, $fid, $threadMap[$fid] ?? 0);
            $postCases[] = 'WHEN id = ? THEN ?';
            array_push($postParams, $fid, $postMap[$fid] ?? 0);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // 参数顺序必须与 SQL 一致：先 thread CASE，再 post CASE，最后 WHERE IN
        $params = array_merge($threadParams, $postParams, $ids);

        Database::execute(
            'UPDATE forums SET thread_count = CASE ' . implode(' ', $threadCases) . ' ELSE thread_count END,
                    post_count = CASE ' . implode(' ', $postCases) . " ELSE post_count END
             WHERE id IN ({$placeholders})",
            $params
        );

        Cache::delete('forums:list');

        return count($ids);
    }

    /**
     * 调整板块的回复数（正数加、负数减且不为负）
     */
    public static function adjustPostCount(int $forumId, int $delta): int
    {
        if ($delta === 0) {
            return 0;
        }

        return $delta > 0
            ? Database::execute("UPDATE forums SET post_count = post_count + ? WHERE id = ?", [$delta, $forumId])
            : Database::execute(
                "UPDATE forums SET post_count = CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END WHERE id = ?",
                [-$delta, -$delta, $forumId]
            );
    }

    /**
     * 全部未删除板块的 id（统计重建时按批更新）
     *
     * @return int[]
     */
    public static function allIds(): array
    {
        return array_map('intval', array_column(
            Database::fetchAll("SELECT id FROM forums WHERE deleted_at IS NULL"),
            'id'
        ));
    }

    /**
     * 把所有板块的「今日」计数清零（每日 0 点由定时任务调用）
     */
    public static function resetTodayCounts(): int
    {
        return Database::execute(
            "UPDATE forums SET today_threads = 0, today_posts = 0 WHERE deleted_at IS NULL"
        );
    }

    /**
     * 批量回退多个板块的主题数 / 回复数（各一条 CASE WHEN 语句）
     *
     * 删用户时要把他在各板块的帖子数一次性扣掉；两项都传时合并成一条 UPDATE，
     * 只传其中一项就只生成对应的 CASE 分支。
     *
     * @param array<int, int> $threadDecById forum_id => 主题数扣减
     * @param array<int, int> $postDecById   forum_id => 回复数扣减
     */
    public static function decrementCountsBulk(array $threadDecById, array $postDecById): int
    {
        $threadDecById = array_filter($threadDecById, static fn(int $n): bool => $n > 0);
        $postDecById = array_filter($postDecById, static fn(int $n): bool => $n > 0);
        if (empty($threadDecById) && empty($postDecById)) {
            return 0;
        }

        $ids = array_values(array_unique(array_merge(array_keys($threadDecById), array_keys($postDecById))));
        $sets = [];
        $params = [];

        if (!empty($threadDecById)) {
            $cases = [];
            foreach ($ids as $fid) {
                $dec = (int)($threadDecById[$fid] ?? 0);
                $cases[] = 'WHEN id = ? THEN CASE WHEN thread_count >= ? THEN thread_count - ? ELSE 0 END';
                array_push($params, $fid, $dec, $dec);
            }
            $sets[] = 'thread_count = CASE ' . implode(' ', $cases) . ' ELSE thread_count END';
        }

        if (!empty($postDecById)) {
            $cases = [];
            foreach ($ids as $fid) {
                $dec = (int)($postDecById[$fid] ?? 0);
                $cases[] = 'WHEN id = ? THEN CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END';
                array_push($params, $fid, $dec, $dec);
            }
            $sets[] = 'post_count = CASE ' . implode(' ', $cases) . ' ELSE post_count END';
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($params, $ids);

        return Database::execute(
            'UPDATE forums SET ' . implode(', ', $sets) . " WHERE id IN ({$ph})",
            $params
        );
    }

    /**
     * 版主 id 列表（forums.moderators 存的是逗号分隔的 id）
     *
     * @return int[]
     */
    public static function getModerators(int $forumId): array
    {
        $row = Database::fetchOne(
            "SELECT moderators FROM forums WHERE id = ? AND deleted_at IS NULL",
            [$forumId]
        );

        if (!$row) {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', (string)($row['moderators'] ?? '')))));
    }

    /**
     * 覆盖式写入版主列表
     *
     * @param int[] $userIds
     */
    public static function setModerators(int $forumId, array $userIds): int
    {
        $affected = Database::execute(
            "UPDATE forums SET moderators = ?, updated_at = ? WHERE id = ?",
            [implode(',', array_map('intval', $userIds)), time(), $forumId]
        );

        self::forgetCaches($forumId);

        return $affected;
    }

    /**
     * 后台板块管理列表（页面与 JSON API 共用同一份查询）
     */
    public static function adminList(): array
    {
        return Database::fetchAll(
            "SELECT id, parent_id, name, description, `rank`, moderators, announcement,
                    seo_title, seo_keywords, thread_count, post_count, created_at
             FROM forums WHERE deleted_at IS NULL
             ORDER BY parent_id ASC, `rank` DESC, id ASC"
        );
    }

    /**
     * 后台新建板块，返回新 id
     */
    public static function adminCreate(
        int $parentId,
        string $name,
        string $description,
        int $rank,
        string $moderators,
        string $announcement,
        string $seoTitle,
        string $seoKeywords
    ): int {
        Database::execute(
            "INSERT INTO forums (parent_id, name, description, `rank`, moderators, announcement, seo_title, seo_keywords, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$parentId, $name, $description, $rank, $moderators, $announcement, $seoTitle, $seoKeywords, time()]
        );

        $forumId = Database::lastInsertId();
        self::forgetCaches($forumId);

        return $forumId;
    }

    /**
     * 后台编辑板块
     */
    public static function adminUpdate(
        int $forumId,
        int $parentId,
        string $name,
        string $description,
        int $rank,
        string $moderators,
        string $announcement,
        string $seoTitle,
        string $seoKeywords
    ): int {
        $affected = Database::execute(
            "UPDATE forums SET parent_id = ?, name = ?, description = ?, `rank` = ?, moderators = ?,
                    announcement = ?, seo_title = ?, seo_keywords = ?, updated_at = ?
             WHERE id = ?",
            [$parentId, $name, $description, $rank, $moderators, $announcement, $seoTitle, $seoKeywords, time(), $forumId]
        );

        self::forgetCaches($forumId);

        return $affected;
    }

    /**
     * 板块相关缓存统一在这里失效，调用方不用记有哪些键
     *
     * findById() 走 cachedOne（TTL 600s），它的缓存键里含 SQL 常量，
     * 所以除了语义化的旧键，还要按行失效一次，否则改完版主最长 10 分钟仍读到旧行。
     */
    public static function forgetCaches(int $forumId = 0): void
    {
        Cache::delete('forums:list');
        Cache::delete('forums:children:all');
        // 注意：不要再删 'forums:all'——全项目从来没有人读这个键（死键），
        // 真正在用的下面这些按 SQL 推导出来的键，原来一个都没删，导致
        // 「新建/改名板块后发帖下拉最长 1 小时还是旧数据」。
        self::forgetAll(self::TOP_FORUMS_SQL);
        self::forgetAll(self::ALL_ORDERED_SQL);
        self::forgetAll(self::PICKER_SQL);
        if ($forumId > 0) {
            self::forgetAll(self::CHILDREN_SQL, [$forumId]);
            Cache::delete("forum:{$forumId}");
            self::forgetOne(self::FIND_BY_ID_SQL, [$forumId]);
        }
        // 首页「每板块最新主题」的 key 带板块集合指纹，只能按前缀清
        Cache::deletePattern('forums:latest_threads:*');
    }

    public static function incrementThreadCount(int $forumId, int $threadId): int
    {
        return Database::execute(
            "UPDATE forums
             SET thread_count = thread_count + 1,
                 today_threads = today_threads + 1,
                 last_thread_id = ?,
                 last_post_time = ?
             WHERE id = ?",
            [$threadId, time(), $forumId]
        );
    }

    public static function incrementPostCount(int $forumId): int
    {
        return Database::execute(
            "UPDATE forums
             SET post_count = post_count + 1,
                 today_posts = today_posts + 1,
                 last_post_time = ?
             WHERE id = ?",
            [time(), $forumId]
        );
    }

    public static function decrementThreadCount(int $forumId): int
    {
        return Database::execute(
            "UPDATE forums
             SET thread_count = CASE WHEN thread_count > 0 THEN thread_count - 1 ELSE 0 END,
                 today_threads = CASE WHEN today_threads > 0 THEN today_threads - 1 ELSE 0 END
             WHERE id = ?",
            [$forumId]
        );
    }

    public static function decrementPostCount(int $forumId): int
    {
        return Database::execute(
            "UPDATE forums
             SET post_count = CASE WHEN post_count > 0 THEN post_count - 1 ELSE 0 END,
                 today_posts = CASE WHEN today_posts > 0 THEN today_posts - 1 ELSE 0 END
             WHERE id = ?",
            [$forumId]
        );
    }

    /**
     * 软删除板块，并级联软删除所有子板块（含多级嵌套）
     */
    public static function softDelete(int $forumId): int
    {
        $now = time();
        Database::beginTransaction();
        try {
            self::softDeleteChildren($forumId, $now);
            $result = Database::execute(
                "UPDATE forums SET deleted_at = ? WHERE id = ?",
                [$now, $forumId]
            );
            Database::commit();

            return $result;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * 递归软删除子板块
     */
    private static function softDeleteChildren(int $parentId, int $now): void
    {
        $children = Database::fetchAll(
            "SELECT id FROM forums WHERE parent_id = ? AND deleted_at IS NULL",
            [$parentId]
        );

        foreach ($children as $child) {
            self::softDeleteChildren((int)$child['id'], $now);
        }

        Database::execute(
            "UPDATE forums SET deleted_at = ? WHERE parent_id = ? AND deleted_at IS NULL",
            [$now, $parentId]
        );
    }
}
