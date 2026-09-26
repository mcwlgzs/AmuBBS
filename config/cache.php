<?php
/**
 * 缓存配置
 *
 * CACHE_DRIVER 取值：
 *   auto  - 有 Redis 且连得上就用 Redis，否则自动落到文件缓存（默认，推荐）
 *   file  - 强制文件缓存（共享虚拟主机 / 无 redis 扩展时使用）
 *   redis - 强制 Redis；连不上会自动降级为文件缓存，不会白屏
 */

return [
    // 默认缓存驱动
    'default' => Core\env('CACHE_DRIVER', 'auto'),

    // Redis 配置
    'redis' => [
        'host' => Core\env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) Core\env('REDIS_PORT', 6379),
        'password' => Core\env('REDIS_PASSWORD', ''),
        'database' => (int) Core\env('REDIS_DATABASE', 0),
        'timeout' => 5,
    ],

    // 文件缓存配置（共享主机默认走这里，无需任何扩展）
    'file' => [
        'path' => Core\env('CACHE_FILE_PATH', APP_PATH . 'storage/cache/'),
        // 过期清理概率分母：平均每 N 次写入触发一次 GC
        'gc_divisor' => (int) Core\env('CACHE_FILE_GC', 200),
    ],
];
