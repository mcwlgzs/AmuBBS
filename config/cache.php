<?php
/**
 * 缓存配置
 */

return [
    // 默认缓存驱动
    'default' => Core\env('CACHE_DRIVER', 'redis'),

    // Redis 配置
    'redis' => [
        'host' => Core\env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) Core\env('REDIS_PORT', 6379),
        'password' => Core\env('REDIS_PASSWORD', ''),
        'database' => (int) Core\env('REDIS_DATABASE', 0),
        'timeout' => 5,
    ],

    // 文件缓存配置
    'file' => [
        'path' => APP_PATH . 'storage/cache/',
    ],
];
