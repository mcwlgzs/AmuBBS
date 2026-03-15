<?php
/**
 * PHP 内置服务器路由文件
 * 用于将所有请求转发到 index.php
 */

// 如果请求的是静态文件且文件存在，直接返回
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $path;

    // 如果是静态文件且存在，返回 false 让服务器处理
    if (is_file($file)) {
        return false;
    }
}

// 否则加载 index.php
require __DIR__ . '/index.php';
