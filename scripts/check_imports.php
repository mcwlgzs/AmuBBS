<?php
/**
 * 静态检查：类名引用了模型但当前文件没有 use、也不在同一命名空间；以及纯属多余的 use
 *
 * 用法: php scripts/check_imports.php
 *
 * 这是这次重构最容易犯的错：在 App\Services\Xxx 里写 `Forum::adjustThreadCount(...)`，
 * 却忘了 `use App\Models\Forum;`，PHP 会当成 App\Services\Forum 去找，
 * 直到运行时（而且是走到那行时）才炸。语法检查与页面冒烟都发现不了。
 *
 * ⚠️ 模型清单是**扫目录得到**的，不是手写常量：早先版本写死了 18 个模型名，
 *    新增的 Message / Announcement / ForumAccess / Checkin 全在盲区里，
 *    「漏 use」照样漏检（AnnounceController 就这么漏过一次）。
 */
$root = dirname(__DIR__);

// 需要关注的短类名 → 期望的完整类名（扫 app/Models/*.php 自动生成）
$known = [];
$modelsDir = $root . '/app/Models';
foreach (glob($modelsDir . '/*.php') ?: [] as $file) {
    $short = basename($file, '.php');
    if ($short === 'Model') { continue; }   // 抽象基类不需要被 use
    $known[$short] = 'App\\Models\\' . $short;
}
if (!$known) {
    echo "没有扫到任何模型，脚本失效 ❌\n";
    exit(1);
}

$bad = [];
$files = 0;
foreach ([$root . '/app', $root . '/core', $root . '/plugins'] as $base) {
    if (!is_dir($base)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $files++;
        $raw = (string)file_get_contents($f->getPathname());
        // 去掉注释再扫，避免把注释里提到的类名当成真引用
        $src = preg_replace('#/\*.*?\*/#s', '', $raw);
        $src = preg_replace('#(^|\s)//[^\n]*#', '$1', $src);
        $src = preg_replace('#(^|\s)\#[^\n]*#', '$1', $src);
        if (!preg_match('/^namespace\s+([^;]+);/m', $src, $nm)) { continue; }
        $ns = trim($nm[1]);
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));

        // 收集 use 别名
        $aliases = [];
        if (preg_match_all('/^use\s+([^;]+);/m', $src, $um)) {
            foreach ($um[1] as $use) {
                $use = trim($use);
                if (str_contains($use, ' as ')) {
                    [$cls, $alias] = array_map('trim', explode(' as ', $use));
                    $aliases[$alias] = $cls;
                } else {
                    $aliases[substr($use, strrpos($use, '\\') === false ? 0 : strrpos($use, '\\') + 1)] = $use;
                }
            }
        }

        foreach ($known as $short => $fqcn) {
            // 只看静态调用 XX:: 和 new XX(
            $used = preg_match('/(?<![\w$\\\\])' . preg_quote($short, '/') . '\s*::/', $src)
                 || preg_match('/new\s+' . preg_quote($short, '/') . '\s*\(/', $src);
            if (!$used) { continue; }

            if (isset($aliases[$short])) { continue; }                       // 有 use
            if ($ns === 'App\\Models') { continue; }                          // 同命名空间（模型内部互相引用）

            // 注意：这里**不能**再豁免「当前类名与短名相同」的情况。
            // app/Controllers/User.php 的类名就叫 User，里面写 `User::findByEmail()`
            // 解析到的是控制器自己（Call to undefined method），而不是 App\Models\User；
            // 早先版本把这种写法当成合法自引用，正好放过了一处真实错误。
            // 控制器要用同名模型必须起别名：use App\Models\User as UserModel;

            $bad[] = "{$rel}（namespace {$ns}）里的 {$short}:: 没有 use {$fqcn}";
        }

        // 多余的 use：短名/别名在整份文件里（含注释）除了 use 行再没出现过
        foreach ($aliases as $alias => $fqcn) {
            if ($fqcn === '' || !str_contains($fqcn, '\\')) { continue; }
            if (!str_starts_with($fqcn, 'App\\') && !str_starts_with($fqcn, 'Core\\')) { continue; }
            $withoutUses = preg_replace('/^use\s+[^;]+;/m', '', $raw);
            if (!preg_match('/(?<![\w$\\\\])' . preg_quote($alias, '/') . '(?![\w])/', (string)$withoutUses)) {
                $bad[] = "{$rel} 里的 use {$fqcn} 是多余的（全文再无 {$alias}）";
            }
        }
    }
}

echo '扫描 ' . $files . ' 个 PHP 文件（模型清单 ' . count($known) . ' 个）' . "\n";
if ($bad) {
    foreach ($bad as $b) { echo "  BAD  {$b}\n"; }
    echo '共 ' . count($bad) . " 处问题 ❌\n";
    exit(1);
}
echo "没有漏 use / 多余 use 的模型引用 ✅\n";
exit(0);

