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

            // htmx 请求：带上 frontFlash，前台就能弹出真正的原因；
            // 否则前端只看到 429，只能给一句无意义的「请求失败」。
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                header('HX-Trigger: ' . json_encode([
                    'frontFlash' => ['type' => 'danger', 'message' => '请求过于频繁，请稍后再试'],
                ], JSON_UNESCAPED_SLASHES));
                header('HX-Reswap: none');
                header('Content-Type: text/html; charset=utf-8');
                return;
            }

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
