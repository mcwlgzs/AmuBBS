<?php
/**
 * 数据库配置
 *
 * 优先读取 .env 中的 DB_* 变量；未设置时回落到下面的默认值。
 * 这样共享主机上只需要改 .env，不必改代码（此前 .env 里的 DB_* 是无效的）。
 */

return [
    // 默认连接
    'default' => Core\env('DB_CONNECTION', 'mysql'),

    // 数据库连接配置
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => Core\env('DB_HOST', '127.0.0.1'),
            'port' => (int) Core\env('DB_PORT', 3306),
            'database' => Core\env('DB_DATABASE', 'amubbs'),
            'username' => Core\env('DB_USERNAME', 'root'),
            'password' => Core\env('DB_PASSWORD', ''),
            'charset' => Core\env('DB_CHARSET', 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            // 读写分离：不配置 DB_READ_HOST 时读连接自动回退主库（单机部署无需关心）
            'read' => [
                'host' => Core\env('DB_READ_HOST', ''),
                'port' => (int) Core\env('DB_READ_PORT', 0),
            ],
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ],
    ],
];
