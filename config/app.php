<?php
/**
 * 应用配置
 */

use function Core\env;

return [
    // 应用名称
    'name' => 'AMU论坛',

    // 应用版本
    'version' => '1.0.0',

    // 缓存版本（用于缓存键前缀，升级时修改此值可清空所有缓存）
    'cache_version' => 'v1',

    // 时区
    'timezone' => 'Asia/Shanghai',

    // 字符集
    'charset' => 'UTF-8',

    // 调试模式
    'debug' => (bool) env('APP_DEBUG', false),

    // URL 配置
    'url' => env('APP_URL', 'http://localhost:8000'),

    // Session 配置
    'session' => [
        // file | redis（redis 不可用时自动回退到 file）
        'driver' => env('SESSION_DRIVER', 'file'),
        'lifetime' => (int) env('SESSION_LIFETIME', 7200),
        'redis_db' => (int) env('SESSION_REDIS_DB', 1),
        // 文件 Session 存放目录：放应用自己目录里，比系统临时目录可靠
        'path' => env('SESSION_FILE_PATH', APP_PATH . 'storage/sessions/'),
    ],

    // 路径配置
    'paths' => [
        'log' => APP_PATH . 'storage/logs/',
        'cache' => APP_PATH . 'storage/cache/',
        'upload' => APP_PATH . 'public/uploads/',
    ],
];