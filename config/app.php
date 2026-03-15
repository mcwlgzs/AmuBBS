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

    // 路径配置
    'paths' => [
        'log' => APP_PATH . 'storage/logs/',
        'cache' => APP_PATH . 'storage/cache/',
        'upload' => APP_PATH . 'public/uploads/',
    ],
];