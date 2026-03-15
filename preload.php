<?php
/**
 * OPcache Preloading 脚本
 * 将核心类预编译到共享内存，省掉每次请求的文件解析开销
 *
 * 使用方法：在 php.ini 中添加：
 *   opcache.preload=/path/to/amubbs/preload.php
 *   opcache.preload_user=www-data
 *
 * 注意：
 * - 需要 PHP 7.4+
 * - 修改此文件后需重启 PHP-FPM 才能生效
 * - 只预加载不会变动的核心类，避免开发时频繁重启
 */

$basePath = __DIR__ . '/';

// 核心框架类（每次请求必定加载）
$coreFiles = [
    'core/Bootstrap.php',
    'core/Router.php',
    'core/Database.php',
    'core/Cache.php',
    'core/PageCache.php',
    'core/Security.php',
    'core/Lang.php',
    'core/Url.php',
    'core/Helper.php',
    'core/Container.php',
    'core/EventDispatcher.php',
    'core/Event.php',
    'core/RememberToken.php',
    'core/RedisSessionHandler.php',
    'core/Markdown.php',
    'core/PluginManager.php',
    'core/PluginLoader.php',
];

// 高频控制器
$controllerFiles = [
    'app/Controllers/Base.php',
    'app/Controllers/Index.php',
    'app/Controllers/Thread.php',
    'app/Controllers/User.php',
    'app/Controllers/Search.php',
];

// 高频服务
$serviceFiles = [
    'app/Services/SettingSvc.php',
    'app/Services/UserSvc.php',
    'app/Services/ThreadSvc.php',
    'app/Services/ForumSvc.php',
    'app/Services/PermissionSvc.php',
    'app/Services/OnlineSvc.php',
    'app/Services/RuntimeSvc.php',
    'app/Services/LevelSvc.php',
    'app/Services/CreditSvc.php',
    'app/Services/IpBlacklistService.php',
];

// 高频 Repository
$repoFiles = [
    'app/Repositories/ThreadRepo.php',
    'app/Repositories/PostRepo.php',
    'app/Repositories/ForumRepo.php',
    'app/Repositories/UserRepo.php',
];

// 中间件
$middlewareFiles = [
    'app/Middlewares/Auth.php',
    'app/Middlewares/AdminAuth.php',
    'app/Middlewares/Csrf.php',
    'app/Middlewares/RateLimit.php',
    'app/Middlewares/RunLevel.php',
];

$allFiles = array_merge($coreFiles, $controllerFiles, $serviceFiles, $repoFiles, $middlewareFiles);

$loaded = 0;
foreach ($allFiles as $file) {
    $fullPath = $basePath . $file;
    if (file_exists($fullPath)) {
        opcache_compile_file($fullPath);
        $loaded++;
    }
}

// 记录预加载结果（仅在 CLI 下输出）
if (PHP_SAPI === 'cli') {
    echo "OPcache preloaded {$loaded}/" . count($allFiles) . " files\n";
}
