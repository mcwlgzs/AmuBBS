<?php
/**
 * 页面级缓存
 * 匿名用户直接返回缓存的完整 HTML，跳过所有 DB 查询
 * 登录用户不走页面缓存（因为有个性化内容）
 */

namespace Core;

class PageCache
{
    private static bool $enabled = false;
    private static string $cacheKey = '';
    private static int $ttl = 60;

    /**
     * 获取当前请求的页面缓存 key（供外部预热使用）
     * 非 GET 或已登录时返回空字符串
     */
    public static function getCacheKey(): string
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            return '';
        }
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            return '';
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            return '';
        }
        return self::buildKey();
    }

    /**
     * 尝试从缓存返回页面（在路由分发前调用）
     * 返回 true 表示已输出缓存，可以直接 exit
     */
    public static function tryServe(): bool
    {
        // 只缓存 GET 请求
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            return false;
        }

        // 登录用户不走页面缓存
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            return false;
        }

        // AJAX 请求不缓存
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            return false;
        }

        $key = self::buildKey();
        $html = Cache::get($key);

        if ($html !== null && is_string($html)) {
            // ETag 协商缓存：内容未变时返回 304，零传输
            $etag = '"' . md5($html) . '"';
            header('X-Page-Cache: HIT');
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
            header('Vary: Accept-Encoding, User-Agent');

            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                http_response_code(304);
                return true;
            }

            echo $html;
            return true;
        }

        return false;
    }

    /**
     * 开始捕获输出（在控制器渲染前调用）
     */
    public static function start(int $ttl = 60): void
    {
        // 登录用户不缓存
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            return;
        }

        self::$ttl = $ttl;
        self::$cacheKey = self::buildKey();
        self::$enabled = true;
        ob_start();
    }

    /**
     * 结束捕获并存入缓存
     */
    public static function end(): void
    {
        if (!self::$enabled) {
            return;
        }

        $html = ob_get_clean();
        if ($html !== false && strlen($html) > 0) {
            Cache::set(self::$cacheKey, $html, self::$ttl);
            $etag = '"' . md5($html) . '"';
            header('X-Page-Cache: MISS');
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            header('Vary: Accept-Encoding, User-Agent');
            echo $html;
        }

        self::$enabled = false;
    }

    /**
     * 清除指定路径的页面缓存
     */
    public static function invalidate(string $path = ''): void
    {
        if ($path === '') {
            Cache::deletePattern('page:*');
        } else {
            $key = 'page:' . md5($path);
            Cache::delete($key);
        }
    }

    /**
     * 构建缓存 key（区分移动端/桌面端）
     */
    private static function buildKey(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        // 只保留 path 和白名单查询参数，防止随机参数导致缓存膨胀
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = '';
        $queryStr = parse_url($uri, PHP_URL_QUERY);
        if ($queryStr) {
            parse_str($queryStr, $params);
            // 只保留分页和排序等有意义的参数
            $allowed = ['page', 'sort', 'order', 'tab', 'tag'];
            $filtered = array_intersect_key($params, array_flip($allowed));
            if (!empty($filtered)) {
                ksort($filtered);
                $query = '?' . http_build_query($filtered);
            }
        }
        $suffix = self::isMobile() ? ':m' : ':d';
        return 'page:' . md5($path . $query) . $suffix;
    }

    /**
     * 简单判断是否为移动端请求
     */
    private static function isMobile(): bool
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        // 注意：不包含 iPad，iPadOS 13+ 默认使用桌面版 UA，应返回桌面布局
        return (bool)preg_match('/Mobile|Android|iPhone|iPod|webOS|BlackBerry|Opera Mini|IEMobile/i', $ua);
    }
}
