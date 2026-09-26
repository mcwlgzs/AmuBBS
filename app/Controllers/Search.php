<?php
/**
 * 搜索控制器
 *
 * 「怎么搜」的逻辑（中文走 LIKE、ASCII 走 FULLTEXT、命中 0 条回退 LIKE）统一在
 * App\Services\SearchSvc：前台搜索页与开放 API 共用同一份实现。
 */

namespace App\Controllers;

use App\Services\SearchSvc;
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
     *
     * 优先使用 FULLTEXT MATCH...AGAINST，中文或不可用时回退到 LIKE（见 SearchSvc）
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

            // 搜索结果缓存 60 秒
            $cacheKey = 'search:' . md5("{$type}:{$keyword}:{$page}");
            $cached = Cache::get($cacheKey);
            if ($cached !== null && is_array($cached)) {
                $total = $cached['total'];
                $results = $cached['results'];
            } else {
                $found = SearchSvc::byType($type, $keyword, $page, $perPage);
                $total = $found['total'];
                $results = $found['items'];
                Cache::set($cacheKey, ['total' => $total, 'results' => $results], 60);
            }
        }

        $totalPages = max(1, (int)ceil($total / $perPage));

        $this->render('search', [
            'keyword' => $keyword,
            'type' => $type,
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }
}
