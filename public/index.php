<?php
/**
 * AMuBBS - 轻量化论坛系统
 * 入口文件
 */

// 定义常量
define('APP_PATH', dirname(__DIR__) . '/');

// 记录开始时间（全局可访问，供页脚显示）
$startTime = microtime(true);
$_SERVER['REQUEST_START_TIME'] = $startTime;

// 加载环境变量
require APP_PATH . 'core/Env.php';
Core\Env::load(APP_PATH);

// 调试模式（从 .env 读取，默认 false）
define('DEBUG', (bool) Core\env('APP_DEBUG', false));

// 加载配置
$config = require APP_PATH . 'config/app.php';

// 加载核心框架
require APP_PATH . 'core/Bootstrap.php';

// 启动应用
$app = Core\Bootstrap::getInstance();
$app->run();

// 性能监控（开发模式）
if (DEBUG && !headers_sent()) {
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    $memory = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

    header("X-Response-Time: {$duration}ms");
    header("X-Memory-Usage: {$memory}MB");
}
