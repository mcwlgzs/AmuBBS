<?php
/**
 * 静态检查：.env.example 里声明的配置项，代码是否真的会读
 *
 * 为什么需要它：这个项目给共享虚拟主机用，`.env.example` 就是站长唯一的配置说明书。
 * 里面写着一个开关、站长为它折腾半天，而代码根本不读 —— 这类「死配置」
 * 比缺文档更糟（本项目已经踩过：DB_* / DB_READ_HOST / SESSION_* / CACHE_DRIVER
 * 都曾经是写了不读的假配置）。
 *
 * 判定：
 *   - 正向（失败级）：.env.example 里出现的每个 KEY，必须在 core/ app/ config/ public/ install/
 *     里被 env('KEY') / Core\env('KEY') / getenv('KEY') / $_ENV['KEY'] 读取。
 *   - 反向（失败级）：代码里读取的每个 KEY，必须在 .env.example 里出现
 *     （注释形式 `# KEY=...` 也算「有说明」，可选配置这样写就够了）。
 *
 * 用法: php scripts/check_env.php
 *       php scripts/check_env.php <另一个 .env.example 路径>   # 验证检查器本身有效
 */

$root = dirname(__DIR__);
$exampleFile = $argv[1] ?? ($root . '/.env.example');

if (!is_file($exampleFile)) {
    fwrite(STDERR, "找不到配置文件: {$exampleFile}\n");
    exit(1);
}

// 1) .env.example 里声明（含注释形式）的键
$declared = [];
foreach (preg_split('/\r?\n/', (string)file_get_contents($exampleFile)) as $line) {
    if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $m)) {
        $declared[$m[1]] = true;
    }
}

if (!$declared) {
    fwrite(STDERR, "没有从 .env.example 解析到任何配置项，检查正则是否与实际写法一致\n");
    exit(1);
}

// 2) 代码里实际读取的键
$used = [];
$files = 0;
foreach (['core', 'app', 'config', 'public', 'install'] as $dir) {
    $base = $root . '/' . $dir;
    if (!is_dir($base)) {
        continue;
    }

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $files++;
        $src = (string)file_get_contents($f->getPathname());
        // 去注释，避免注释里的示例被当成真实读取
        $src = preg_replace('#/\*.*?\*/#s', '', $src);
        $src = preg_replace('#(^|\s)//[^\n]*#', '$1', (string)$src);
        $src = preg_replace('#(^|\s)\#[^\n]*#', '$1', (string)$src);

        $patterns = [
            "/(?:Core\\\\)?env\(\s*'([A-Z][A-Z0-9_]*)'/",
            "/getenv\(\s*'([A-Z][A-Z0-9_]*)'/",
            "/\\\$_ENV\[\s*'([A-Z][A-Z0-9_]*)'/",
        ];
        foreach ($patterns as $re) {
            if (preg_match_all($re, (string)$src, $mm)) {
                foreach ($mm[1] as $k) {
                    $used[$k] = true;
                }
            }
        }
    }
}

echo '.env.example 声明 ' . count($declared) . " 项，扫描 {$files} 个 PHP 文件，代码读取 " . count($used) . " 项\n";

$dead = array_diff_key($declared, $used);          // 声明了但没人读
$undoc = array_diff_key($used, $declared);         // 读了但没写进 .env.example

foreach (array_keys($dead) as $k) {
    echo "  BAD  {$k}：.env.example 里声明了，但没有任何代码读取它（死配置）\n";
}
foreach (array_keys($undoc) as $k) {
    echo "  BAD  {$k}：代码会读，但 .env.example 里没有说明\n";
}

if ($dead || $undoc) {
    echo '共 ' . count($dead) . ' 个死配置，' . count($undoc) . " 个未说明配置 ❌\n";
    exit(1);
}

echo ".env.example 与代码读取的配置项完全一致 ✅\n";
exit(0);
