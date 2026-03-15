<?php
/**
 * 搜索控制器
 */

namespace App\Controllers;

use Core\Database;
use Core\Cache;

class Search extends Base
{
    /**
     * 高亮关键词
     */
    public static function highlight(string $text, string $keyword): string
    {
        if ($keyword === '') return htmlspecialchars($text);
        $escaped = htmlspecialchars($text);
        $pattern = '/(' . preg_quote(htmlspecialchars($keyword), '/') . ')/iu';
        return preg_replace($pattern, '<mark style="background:var(--warning);color:#000;padding:0 2px;border-radius:2px;">$1</mark>', $escaped);
    }

    /**
     * 搜索页面
     * 优先使用 FULLTEXT MATCH...AGAINST，回退到 LIKE
     */
    public function index(): void
    {
        $keyword = trim($_GET['q'] ?? '');
        $type = $_GET['type'] ?? 'thread';
        if (!in_array($type, ['thread', 'post'], true)) $type = 'thread';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;

        if (mb_strlen($keyword) > 100) {
            $keyword = mb_substr($keyword, 0, 100);
        }

        $results = [];
        $total = 0;

        if ($keyword !== '') {
            // 搜索频率限制：同一 IP 每分钟最多 20 次
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $rateCacheKey = "search_rate:{$ip}";
            $rateCount = (int)Cache::get($rateCacheKey);
            if ($rateCount >= 20) {
                $this->error('搜索过于频繁，请稍后再试');
                return;
            }
            Cache::set($rateCacheKey, $rateCount + 1, 60);

            $offset = ($page - 1) * $perPage;
            $escapedKeyword = addcslashes($keyword, '%_\\');
            // 过滤 MySQL BOOLEAN MODE 特殊操作符，防止用户构造恶意查询
            $ftKeyword = preg_replace('/[+\-><()~*"@]/', ' ', $keyword);
            $ftKeyword = trim(preg_replace('/\s+/', ' ', $ftKeyword));

            // 过滤后关键词为空（全是特殊字符），跳过搜索
            if ($ftKeyword === '') {
                $ftKeyword = null;
            }

            // 搜索结果缓存 60 秒
            $cacheKey = 'search:' . md5("{$type}:{$keyword}:{$page}");
            $cached = Cache::get($cacheKey);
            if ($cached !== null && is_array($cached)) {
                $total = $cached['total'];
                $results = $cached['results'];
            } else {
                if ($type === 'post') {
                    $total = $this->countPostResults($ftKeyword, $escapedKeyword);
                    $results = $this->searchPosts($ftKeyword, $escapedKeyword, $perPage, $offset);
                } else {
                    $total = $this->countThreadResults($ftKeyword, $escapedKeyword);
                    $results = $this->searchThreads($ftKeyword, $escapedKeyword, $perPage, $offset);
                }
                Cache::set($cacheKey, ['total' => $total, 'results' => $results], 60);
            }
        }

        $totalPages = max(1, ceil($total / $perPage));

        $this->render('search', [
            'keyword' => $keyword,
            'type' => $type,
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * 搜索帖子（优先 FULLTEXT）
     */
    private function searchThreads(?string $keyword, string $escapedKeyword, int $limit, int $offset): array
    {
        // 优先使用 title+content 联合 FULLTEXT 索引
        if ($keyword !== null) {
            try {
                return Database::fetchAll("
                    SELECT t.*, f.name as forum_name, u.username, u.nickname, u.nickname_color,
                           MATCH(t.title, t.content) AGAINST(? IN BOOLEAN MODE) as relevance
                    FROM threads t
                    LEFT JOIN forums f ON t.forum_id = f.id
                    LEFT JOIN users u ON t.user_id = u.id
                    WHERE t.deleted_at IS NULL AND MATCH(t.title, t.content) AGAINST(? IN BOOLEAN MODE)
                    ORDER BY relevance DESC, t.created_at DESC
                    LIMIT ? OFFSET ?
                ", [$keyword, $keyword, $limit, $offset]);
            } catch (\Throwable $e) {
                // FULLTEXT 不可用时回退到 LIKE
            }
        }
        // LIKE 回退：限制最大偏移量，防止深分页全表扫描
        $maxOffset = 1000;
        if ($offset > $maxOffset) {
            return [];
        }
        return Database::fetchAll("
            SELECT t.*, f.name as forum_name, u.username, u.nickname, u.nickname_color
            FROM threads t
            LEFT JOIN forums f ON t.forum_id = f.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.deleted_at IS NULL AND (t.title LIKE ? OR t.content LIKE ?)
            ORDER BY t.created_at DESC
            LIMIT ? OFFSET ?
        ", ["%{$escapedKeyword}%", "%{$escapedKeyword}%", $limit, $offset]);
    }

    private function countThreadResults(?string $keyword, string $escapedKeyword): int
    {
        if ($keyword !== null) {
            try {
                return (int)(Database::fetchOne(
                    "SELECT COUNT(*) as cnt FROM threads WHERE deleted_at IS NULL AND MATCH(title, content) AGAINST(? IN BOOLEAN MODE)",
                    [$keyword]
                )['cnt'] ?? 0);
            } catch (\Throwable $e) {
                // 回退到 LIKE
            }
        }
        // LIKE 回退加上限保护，避免全表扫描返回过大结果
        return min(10000, (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM (SELECT 1 FROM threads WHERE deleted_at IS NULL AND (title LIKE ? OR content LIKE ?) LIMIT 10000) t",
            ["%{$escapedKeyword}%", "%{$escapedKeyword}%"]
        )['cnt'] ?? 0));
    }

    /**
     * 搜索回复
     */
    private function searchPosts(?string $keyword, string $escapedKeyword, int $limit, int $offset): array
    {
        if ($keyword !== null) {
            try {
                return Database::fetchAll("
                    SELECT p.*, t.title as thread_title, t.forum_id, u.username, u.nickname, u.nickname_color,
                           MATCH(p.content) AGAINST(? IN BOOLEAN MODE) as relevance
                    FROM posts p
                    LEFT JOIN threads t ON p.thread_id = t.id
                    LEFT JOIN users u ON p.user_id = u.id
                    WHERE p.deleted_at IS NULL AND MATCH(p.content) AGAINST(? IN BOOLEAN MODE)
                    ORDER BY relevance DESC, p.created_at DESC
                    LIMIT ? OFFSET ?
                ", [$keyword, $keyword, $limit, $offset]);
            } catch (\Throwable $e) {
                // 回退到 LIKE
            }
        }
        // LIKE 回退：限制最大偏移量，防止深分页全表扫描
        $maxOffset = 1000;
        if ($offset > $maxOffset) {
            return [];
        }
        return Database::fetchAll("
            SELECT p.*, t.title as thread_title, t.forum_id, u.username, u.nickname, u.nickname_color
            FROM posts p
            LEFT JOIN threads t ON p.thread_id = t.id
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.deleted_at IS NULL AND p.content LIKE ?
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ", ["%{$escapedKeyword}%", $limit, $offset]);
    }

    private function countPostResults(?string $keyword, string $escapedKeyword): int
    {
        if ($keyword !== null) {
            try {
                return (int)(Database::fetchOne(
                    "SELECT COUNT(*) as cnt FROM posts WHERE deleted_at IS NULL AND MATCH(content) AGAINST(? IN BOOLEAN MODE)",
                    [$keyword]
                )['cnt'] ?? 0);
            } catch (\Throwable $e) {
                // 回退到 LIKE
            }
        }
        return min(10000, (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM (SELECT 1 FROM posts WHERE deleted_at IS NULL AND content LIKE ? LIMIT 10000) t",
            ["%{$escapedKeyword}%"]
        )['cnt'] ?? 0));
    }
}
