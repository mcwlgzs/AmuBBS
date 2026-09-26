<?php
/**
 * 公告模型
 *
 * 公告表没有 deleted_at（用 is_enabled + start_at/end_at 控制上下线），
 * 所以软删除列显式置 null。
 */

namespace App\Models;

use Core\Cache;
use Core\Database;
use Core\PageCache;

class Announcement extends Model
{
    protected static string $table = 'announcements';

    /** 本表用 is_enabled 控制上下线，没有软删除列 */
    protected static ?string $softDeleteColumn = null;

    /** 生效列表的 TTL 上限（秒）：没有定时切换时按这个值缓存 */
    private const ACTIVE_TTL_MAX = 300;

    /** 生效列表的 TTL 下限（秒）：快到切换点时也别把缓存压成 0 */
    private const ACTIVE_TTL_MIN = 5;

    /**
     * 当前生效的公告（key = announcements:active:<limit>）
     *
     * 生效窗口：is_enabled = 1 且 start_at 未到点不算、end_at 过了不算（NULL 表示不限）。
     *
     * TTL 不再写死 300s：start_at/end_at 是精确时间窗，固定 300s 会让
     * 定时上下线最多迟到 5 分钟 —— 现在按「距下一次切换还有多久」来定 TTL。
     */
    public static function activeCached(int $limit = 10): array
    {
        $now = time();

        return self::staleAll(
            self::activeCacheKey($limit),
            "SELECT id, title, content, url, type
             FROM announcements
             WHERE is_enabled = 1
               AND (start_at IS NULL OR start_at <= ?)
               AND (end_at IS NULL OR end_at >= ?)
             ORDER BY `rank` DESC, id DESC
             LIMIT ?",
            [$now, $now, $limit],
            self::nextBoundaryTtl($now)
        );
    }

    /**
     * 生效列表的缓存 key
     *
     * limit 必须进 key：以前写死 'announcements:active'，
     * 换个 limit 调用就会吃到上一个 limit 的结果。
     *
     * public：Index 控制器的批量预热列表要用同一个 key，避免两处各写一份字符串而漂移。
     */
    public static function activeCacheKey(int $limit = 10): string
    {
        return 'announcements:active:' . max(1, $limit);
    }

    /**
     * 距下一次「公告生效 / 下线」还有多少秒（无定时公告则返回 TTL 上限）
     *
     * 只查一次 MIN，走 idx_announcements_time(is_enabled, start_at, end_at)。
     */
    private static function nextBoundaryTtl(int $now): int
    {
        $row = Database::fetchOne(
            "SELECT MIN(t) AS next_at FROM (
                 SELECT start_at AS t FROM announcements WHERE is_enabled = 1 AND start_at > ?
                 UNION ALL
                 SELECT end_at AS t FROM announcements WHERE is_enabled = 1 AND end_at > ?
             ) AS boundaries",
            [$now, $now]
        );

        $next = (int)($row['next_at'] ?? 0);
        if ($next <= $now) {
            return self::ACTIVE_TTL_MAX;
        }

        // +1s 保证跨过切换点那一刻，key 一定已经重建
        return max(self::ACTIVE_TTL_MIN, min(self::ACTIVE_TTL_MAX, $next - $now + 1));
    }

    // ------------------------------------------------------------------
    // 后台管理
    // ------------------------------------------------------------------

    /**
     * 后台公告列表（含未启用的）
     */
    public static function adminList(): array
    {
        return Database::fetchAll("SELECT * FROM announcements ORDER BY `rank` DESC, id DESC");
    }

    /**
     * 按 id 取（后台编辑表单，要求实时，不吃缓存）
     */
    public static function findFresh(int $id): ?array
    {
        $row = Database::fetchOne("SELECT * FROM announcements WHERE id = ?", [$id]);

        return $row ?: null;
    }

    /**
     * 是否启用（切换开关时读当前值用，要求实时）
     */
    public static function isEnabled(int $id): ?int
    {
        $row = Database::fetchOne("SELECT is_enabled FROM announcements WHERE id = ?", [$id]);

        return $row === null ? null : (int)$row['is_enabled'];
    }

    /**
     * 内部标题
     *
     * announcements.title 是 VARCHAR(200) NOT NULL，后台列表与 /admin/api/announcements
     * 都还在读它，所以不能直接不写；但界面（表单 + 前台）已经不再有「标题」这个概念，
     * 用户只填内容 —— 没给标题时取内容首行（最多 100 字符）作为内部标题。
     *
     * @param array{title?: string, content?: string} $fields
     */
    private static function resolveTitle(array $fields): string
    {
        $title = trim((string)($fields['title'] ?? ''));
        if ($title !== '') {
            return mb_substr($title, 0, 200);
        }

        $content = (string)($fields['content'] ?? '');
        $first = trim(explode("\n", $content, 2)[0]);
        if ($first === '') {
            $first = trim($content);
        }

        return $first === '' ? '公告' : mb_substr($first, 0, 100);
    }

    /**
     * @param array{title?: string, content: string, url: string, type: int, rank: int, start_at: ?int, end_at: ?int} $fields
     */
    public static function create(array $fields, int $adminId): int
    {
        $now = time();
        Database::execute(
            "INSERT INTO announcements (title, content, url, type, is_enabled, `rank`, start_at, end_at, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)",
            [
                self::resolveTitle($fields), $fields['content'], $fields['url'], $fields['type'], $fields['rank'],
                $fields['start_at'], $fields['end_at'], $adminId, $now, $now,
            ]
        );

        $id = Database::lastInsertId();
        self::forgetCaches();

        return $id;
    }

    /**
     * @param array{title?: string, content: string, url: string, type: int, rank: int, start_at: ?int, end_at: ?int} $fields
     */
    public static function update(int $id, array $fields): int
    {
        $affected = Database::execute(
            "UPDATE announcements SET title = ?, content = ?, url = ?, type = ?, `rank` = ?, start_at = ?, end_at = ?, updated_at = ?
             WHERE id = ?",
            [
                self::resolveTitle($fields), $fields['content'], $fields['url'], $fields['type'], $fields['rank'],
                $fields['start_at'], $fields['end_at'], time(), $id,
            ]
        );

        self::forgetCaches();

        return $affected;
    }

    public static function setEnabled(int $id, int $enabled): int
    {
        $affected = Database::execute(
            "UPDATE announcements SET is_enabled = ?, updated_at = ? WHERE id = ?",
            [$enabled, time(), $id]
        );

        self::forgetCaches();

        return $affected;
    }

    public static function delete(int $id): int
    {
        $affected = Database::execute("DELETE FROM announcements WHERE id = ?", [$id]);

        self::forgetCaches();

        return $affected;
    }

    /**
     * 公告前台缓存失效（生效列表是按 key 名读的，写入后必须清）
     *
     * 清两层，缺一不可：
     *   1. announcements:active* —— 生效列表数据缓存（key 带 limit，所以用通配）
     *   2. page:*                —— 匿名访客的整页 HTML 缓存（首页公告就在里面）
     * 只清第 1 层时，后台改完公告，游客最长 180s（PageCache::start(180)）还看到旧公告。
     */
    public static function forgetCaches(): void
    {
        Cache::deletePattern('announcements:active*');
        PageCache::invalidate();
    }
}
