<?php
/**
 * 中间件接口
 */

namespace App\Middlewares;

interface Middleware
{
    /**
     * 处理请求
     *
     * @param callable $next 下一个中间件或最终处理器
     */
    public function handle(callable $next): void;
}
