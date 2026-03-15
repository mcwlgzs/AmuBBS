<?php
/**
 * 验证迁移结果
 */

define('APP_PATH', dirname(__DIR__) . '/');
define('DEBUG', false);
require_once APP_PATH . 'core/Database.php';

echo "=== 配置迁移验证 ===\n\n";

$settings = \Core\Database::fetchAll(
    "SELECT `key`, `value` FROM settings WHERE 
    `key` LIKE 'auto_avatar_%' OR 
    `key` LIKE 'emoji_%' OR 
    `key` LIKE 'social_login_%' OR 
    `key` LIKE 'editor_%' OR 
    `key` LIKE 'tinymce_%' 
    ORDER BY `key`"
);

echo "已迁移的配置项（共 " . count($settings) . " 个）：\n";
foreach ($settings as $s) {
    echo "  - {$s['key']} = {$s['value']}\n";
}

echo "\n=== 资源迁移验证 ===\n\n";

$dirs = [
    'public/assets/avatars/' => '头像资源',
    'public/assets/tinymce/' => 'TinyMCE 资源',
    'public/assets/prism/' => 'Prism 资源',
];

foreach ($dirs as $dir => $label) {
    $path = APP_PATH . $dir;
    if (is_dir($path)) {
        $count = count(glob($path . '*'));
        echo "✓ {$label}: {$count} 个文件/目录\n";
    } else {
        echo "✗ {$label}: 目录不存在\n";
    }
}

echo "\n迁移验证完成！\n";
