<?php
/**
 * 搜索服务
 *
 * 站内搜索此前有两份几乎相同的实现（前台 Search 控制器与开放 API），
 * 都是「ASCII 走 FULLTEXT、中文走 LIKE、FULLTEXT 命中 0 条或不可用时回退 LIKE」。
 * 这类规则必须只有一处：两边分头改很容易只修一边（中文静默漏结果那个 bug 就是这么来的）。
 *
 * 分工：
 *   - 本服务负责「怎么搜」：关键词清洗、CJK 判定、BOOLEAN MODE 构造、FULLTEXT→LIKE 回退、分页上限
 *   - Thread / Post 模型负责「SQL 长什么样」
 */

namespace App\Services;

use App\Models\Post;
use App\Models\Thread;
use Core\Helper;

class SearchSvc
{
    /** LIKE 回退时的最大偏移量：深分页会退化成全表扫描 */
    public const MAX_LIKE_OFFSET = 1000;

    /** 计数上限：避免 LIKE 扫描出过大的数字 */
    public const COUNT_CAP = 10000;

    /** BOOLEAN MODE 最多取前 N 个词 */
    private const MAX_TERMS = 10;

    /**
     * 关键词 → 可用的 BOOLEAN MODE 查询串
     *
     * 规则：
     *   - 过滤 MySQL BOOLEAN MODE 特殊字符（用户可能构造出语法错误的查询）
     *   - 中文关键词直接返回 null：InnoDB FULLTEXT 对 CJK 分词很差，
     *     语句能跑通但命中 0 条，会静默漏结果，所以中文一律走 LIKE
     *   - 每个词加 + 前缀与 * 通配，提高多词查询的召回
     */
    public static function booleanQuery(string $keyword): ?string
    {
        $cleaned = trim(preg_replace('/\s+/', ' ', preg_replace('/[+\-><()~*"@]+/', ' ', $keyword) ?? ''));
        if ($cleaned === '') {
            return null;
        }
        if (Helper::containsCjk($cleaned)) {
            return null;
        }

        $words = preg_split('/\s+/', $cleaned, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice($words, 0, self::MAX_TERMS);
        if (empty($words)) {
            return null;
        }

        return implode(' ', array_map(static fn(string $w): string => '+' . $w . '*', $words));
    }

    /** LIKE 用的转义关键词（含 % _ \） */
    public static function likeKeyword(string $keyword): string
    {
        return addcslashes($keyword, '%_\\');
    }

    /**
     * 搜索主题
     *
     * @param bool $slim true = 只返回列表需要的列（开放 API），false = 整行（站内搜索结果页）
     * @return array{items: array, total: int}
     */
    public static function threads(string $keyword, int $page = 1, int $perPage = 20, bool $slim = false): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $boolean = self::booleanQuery($keyword);
        $escaped = self::likeKeyword($keyword);

        if ($boolean !== null) {
            try {
                $items = Thread::searchFulltext($boolean, $perPage, $offset, $slim);
                $total = Thread::countSearchFulltext($boolean);
                if (!empty($items)) {
                    return ['items' => $items, 'total' => $total];
                }
                // 命中 0 条不代表真的没有（分词问题），继续走 LIKE 再确认
            } catch (\Throwable $e) {
                // FULLTEXT 不可用（没建索引 / 老版本 MySQL），回退 LIKE
            }
        }

        if ($offset > self::MAX_LIKE_OFFSET) {
            return ['items' => [], 'total' => 0];
        }

        return [
            'items' => Thread::searchLike($escaped, $perPage, $offset, $slim),
            'total' => Thread::countSearchLike($escaped, self::COUNT_CAP),
        ];
    }

    /**
     * 搜索回复
     *
     * @return array{items: array, total: int}
     */
    public static function posts(string $keyword, int $page = 1, int $perPage = 20, bool $slim = false): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $boolean = self::booleanQuery($keyword);
        $escaped = self::likeKeyword($keyword);

        if ($boolean !== null) {
            try {
                $items = Post::searchFulltext($boolean, $perPage, $offset, $slim);
                $total = Post::countSearchFulltext($boolean);
                if (!empty($items)) {
                    return ['items' => $items, 'total' => $total];
                }
            } catch (\Throwable $e) {
                // 回退 LIKE
            }
        }

        if ($offset > self::MAX_LIKE_OFFSET) {
            return ['items' => [], 'total' => 0];
        }

        return [
            'items' => Post::searchLike($escaped, $perPage, $offset, $slim),
            'total' => Post::countSearchLike($escaped, self::COUNT_CAP),
        ];
    }

    /**
     * 按类型搜索（前台搜索页与开放 API 共用入口）
     *
     * @param string $type thread|post
     * @return array{items: array, total: int}
     */
    public static function byType(string $type, string $keyword, int $page = 1, int $perPage = 20, bool $slim = false): array
    {
        return $type === 'post'
            ? self::posts($keyword, $page, $perPage, $slim)
            : self::threads($keyword, $page, $perPage, $slim);
    }
}
