<?php
/**
 * 静态检查：路由声明的控制器方法是否真的存在
 *
 * 为什么需要它：重构时把控制器里的一段整块替换掉，很容易顺手删掉某个路由入口方法
 * （本项目就发生过一次：NotifyController::notifications() 被整块替换时删掉，
 * 页面直接 500）。php -l 查不出，只看被改文件的测试也可能漏掉。
 *
 * 用法: php scripts/check_routes.php
 */

$root = dirname(__DIR__);
// 可选参数：指定另一个 Bootstrap 文件（用于验证检查器本身有效）
$bootstrapFile = $argv[1] ?? ($root . '/core/Bootstrap.php');
$bootstrap = (string)file_get_contents($bootstrapFile);

// 形如：$this->router->get('/x', ['App\Controllers\Y', 'method'], [...])
$pattern = '/\$this->router->(?:get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]/';
if (!preg_match_all($pattern, $bootstrap, $m, PREG_SET_ORDER)) {
    fwrite(STDERR, "没解析出任何路由，检查正则是否与实际写法一致\n");
    exit(1);
}

$missing = [];
$checked = 0;
$fileCache = [];

foreach ($m as $route) {
    [$all, $path, $class, $method] = $route;
    $checked++;

    // App\Controllers\Admin\X → app/Controllers/Admin/X.php
    $relative = str_replace('\\', '/', preg_replace('/^App\\\\/', '', $class));
    $file = $root . '/app/' . $relative . '.php';

    if (!is_file($file)) {
        $missing[] = "{$path} → 控制器文件不存在: app/{$relative}.php";
        continue;
    }

    if (!isset($fileCache[$file])) {
        $fileCache[$file] = (string)file_get_contents($file);
    }
    $src = $fileCache[$file];

    // 方法名要真的定义在这个类里（含 public/protected/private）
    if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src)) {
        $missing[] = "{$path} → {$class}::{$method}() 不存在";
    }
}

echo "检查了 {$checked} 条路由\n";
if ($missing) {
    foreach ($missing as $x) { echo "  BAD  {$x}\n"; }
    echo "共 " . count($missing) . " 条路由指向不存在的方法 ❌\n";
    exit(1);
}
echo "所有路由都能找到对应的控制器方法 ✅\n";
exit(0);
