<?php
/**
 * 频率限制中间件
 */

namespace App\Middlewares;

use Core\Cache;

class RateLimit implements Middleware
{
    private int $maxRequests;
    private int $windowSeconds;

    /**
     * @param int $maxRequests  窗口期内最大请求数
     * @param int $windowSeconds 窗口期（秒）
     */
    public function __construct(int $maxRequests = 60, int $windowSeconds = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    public function handle(callable $next): void
    {
        // maxRequests 为 0 表示不限制
        if ($this->maxRequests <= 0) {
            $next();
            return;
        }

        $ip = \Core\Helper::clientIp();
        $key = "ratelimit:{$this->maxRequests}:{$this->windowSeconds}:{$ip}";

        // 原子递增计数，避免 TOCTOU 竞态
        $result = Cache::incrementWithLimit($key, $this->maxRequests, $this->windowSeconds);

        if ($result === -1) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => '请求过于频繁，请稍后再试',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $next();
    }
}
