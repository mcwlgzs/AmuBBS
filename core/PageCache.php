<?php
/**
 * 页面级缓存
 * 匿名用户直接返回缓存的完整 HTML，跳过所有 DB 查询
 * 登录用户不走页面缓存（因为有个性化内容）
 *
 * ⚠️ CSRF token 的处理（重要）：
 *   页面模板里会输出一枚 CSRF token（header.php 的 meta[name=csrf-token]），
 *   而 token 是**每个会话各不相同**的。如果把它连同 HTML 一起缓存，
 *   之后所有匿名访客都会拿到「第一个访客的 token」，提交时必然校验失败
 *   —— 表现为匿名访客在命中缓存的首页上无法登录（一直提示 CSRF 失败）。
 *   因此：入缓存前先把 token 抽成占位符，出缓存时再用「当前访客自己」的 token 替换回去。
 */

namespace Core;

class PageCache
{
    /** 缓存 HTML 里 token 的占位符（与原 token 等长无关，替换是精确字符串匹配） */
    private const CSRF_PLACEHOLDER = '{{AMUBBS_PAGE_CSRF}}';

    private static bool $enabled = false;
    private static string $cacheKey = '';
    private static int $ttl = 60;

    /** 本次请求抢到的单飞锁 key（end() 里释放） */
    private static string $lockKey = '';

    /**
     * 把「当前会话/当前访客的 token」换成占位符（用于写入缓存）
     */
    private static function stripToken(string $html): string
    {
        $tokens = [];
        if (!empty($_SESSION['csrf_token'])) {
            $tokens[] = (string)$_SESSION['csrf_token'];
        }
        // 匿名访客的 token 来自双提交 Cookie，不在会话里，必须一并剥离，
        // 否则那枚 token 会被写进所有访客共用的缓存文件。
        $issued = \App\Middlewares\Csrf::currentToken();
        if ($issued !== '') {
            $tokens[] = $issued;
        }

        foreach (array_unique($tokens) as $token) {
            if ($token !== '') {
                $html = str_replace($token, self::CSRF_PLACEHOLDER, $html);
            }
        }

        return $html;
    }

    /**
     * 把占位符换成给定 token（用于输出）
     */
    private static function injectToken(string $html, string $token): string
    {
        if ($token !== '') {
            return str_replace(self::CSRF_PLACEHOLDER, $token, $html);
        }

        // 拿不到 token 时绝不能把占位符原样吐给浏览器（表单一提交必然 403）
        error_log('[PageCache] 未能取得 CSRF token，已清空占位符');
        return str_replace(self::CSRF_PLACEHOLDER, '', $html);
    }

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
        $cached = Cache::get($key);

        if ($cached === null || !is_string($cached)) {
            // 缓存未命中：抢一把单飞锁，避免首页 180s 过期的那一瞬间
            // N 个并发请求全部各自冷渲染（各自跑全套 SQL）。
            $lockKey = $key . ':lock';
            if (Cache::add($lockKey, 1, 10)) {
                self::$lockKey = $lockKey;
                // 渲染中途 fatal/exit 也要把锁放掉，否则后来者要白等 10s
                register_shutdown_function(static function (): void {
                    self::releaseLock();
                });
            } else {
                // 没抢到锁：等一小会儿再看一次，仍然没有就自己渲染（宁可慢，不可空）
                usleep(30000);
                $cached = Cache::get($key);
                if ($cached === null || !is_string($cached)) {
                    return false;
                }
            }
        }

        if ($cached !== null && is_string($cached)) {
            if (self::sendCacheHeaders($cached, 'HIT')) {
                return true; // 304：不输出正文
            }

            // 用当前访客自己的 token 填回占位符；匿名访客走双提交 Cookie，不会因此创建 session 文件
            echo self::injectToken($cached, \App\Middlewares\Csrf::generateToken());
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
            // 先抽掉本访客的 token 再入缓存，避免把别人的 token 发给其他访客
            $cacheHtml = self::stripToken($html);

            Cache::set(self::$cacheKey, $cacheHtml, self::$ttl);

            if (!self::sendCacheHeaders($cacheHtml, 'MISS')) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
                // 本次响应仍然要给当前访客他自己的 token
                echo self::injectToken($cacheHtml, \App\Middlewares\Csrf::generateToken());
            }
        }

        self::releaseLock();
        self::$enabled = false;
    }

    /**
     * 释放单飞锁（缓存已写入，后来者可以直接读缓存）
     */
    private static function releaseLock(): void
    {
        if (self::$lockKey !== '') {
            Cache::delete(self::$lockKey);
            self::$lockKey = '';
        }
    }

    /**
     * 输出页面缓存的响应头
     *
     * 关键：必须是 private —— 响应体里内嵌着「当前访客自己」的 CSRF token，
     * 之前写 public, max-age=30 意味着共享缓存/CDN 可能在 30s 内把 A 的页面（含 A 的 token）
     * 发给同 UA 的 B。同时去掉 Vary: User-Agent（它分不清会话，只会让变体爆炸）。
     */
    private static function sendCacheHeaders(string $html, string $state): bool
    {
        $etag = '"' . md5($html) . '"';
        header('X-Page-Cache: ' . $state);
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Vary: Accept-Encoding');

        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            return true;
        }

        return false;
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
     *
     * 只保留白名单查询参数 —— 好处是随机参数（?foo=1、?utm_source=x、爬虫乱拼的串）
     * 不会把缓存目录撑爆；代价是新增「页面会读的参数」时必须同步加进白名单。
     * 参数值要归一化：?page=abc / ?page=-3 / ?page=99999 与 ?page=1..500 必须落到同一个 key，
     * 否则任何人都能用一条 URL 无限制造缓存条目（首次访问还要跑全套 SQL）。
     */
    private static function buildKey(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = '';
        $queryStr = parse_url($uri, PHP_URL_QUERY);
        if ($queryStr) {
            parse_str($queryStr, $params);

            // 数组参数一律丢掉（?tag[]=1 这种只会制造无意义变体）
            $params = array_filter($params, static fn($v) => !is_array($v));

            $allowed = ['page', 'sort', 'order', 'tab', 'tag'];
            $filtered = array_intersect_key($params, array_flip($allowed));

            foreach ($filtered as $k => $v) {
                $v = trim((string)$v);
                if ($k === 'page') {
                    $filtered[$k] = (string)max(1, min(500, (int)$v));
                } else {
                    // sort/order/tab/tag 只可能是短标识符，超长直接按空处理
                    $filtered[$k] = preg_match('/^[\w\-]{1,32}$/', $v) ? $v : '';
                }
            }

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
