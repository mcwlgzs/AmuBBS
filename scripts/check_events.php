<?php
/**
 * 静态检查：事件常量是否真的会被派发 / 代码引用的事件常量是否真的定义了
 *
 * 为什么需要它：`Events` 里定义了 30 个事件常量，插件作者会照着常量名写监听器。
 * 如果某个常量只在 Events.php 里定义、代码里没有任何 `Event::dispatch(Events::X, ...)`，
 * 那它就是一个「死钩子」——文档说可用、插件挂上去永远不触发，而且不会有任何报错。
 * 本项目里 post.updated / post.deleted / mod.thread.* / mod.post.* 这 6 个就长期处于这种状态。
 *
 * 同时反向检查：代码里写了 `Events::XXX` 但常量不存在（拼错），
 * 这在运行到那一行时才会 fatal，静态检查能提前发现。
 *
 * 用法: php scripts/check_events.php
 *       php scripts/check_events.php <另一个 Events.php 路径>   # 验证检查器本身有效
 */

$root = dirname(__DIR__);
$eventsFile = $argv[1] ?? ($root . '/app/Events/Events.php');

if (!is_file($eventsFile)) {
    fwrite(STDERR, "找不到事件定义文件: {$eventsFile}\n");
    exit(1);
}

$eventsSrc = (string)file_get_contents($eventsFile);

// 1) 解析常量定义
$defined = [];   // NAME => value
if (preg_match_all('/const\s+([A-Z_][A-Z0-9_]*)\s*=\s*\'([^\']*)\'/', $eventsSrc, $m, PREG_SET_ORDER)) {
    foreach ($m as $row) {
        $defined[$row[1]] = $row[2];
    }
}
if (!$defined) {
    fwrite(STDERR, "没解析出任何事件常量，检查正则是否与实际写法一致\n");
    exit(1);
}

// 2) 扫描代码里对事件常量的引用
$dirs = ['app', 'core', 'plugins', 'resources'];
$refs = [];            // NAME => [文件...]（不含定义文件本身）
$unknown = [];         // 引用了但没定义的常量 => [文件...]
$files = 0;

foreach ($dirs as $dir) {
    $base = $root . '/' . $dir;
    if (!is_dir($base)) {
        continue;
    }

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }

        $path = $f->getPathname();
        if (realpath($path) === realpath($eventsFile)) {
            continue;   // 定义文件本身不算「被派发」
        }

        $files++;
        $src = (string)file_get_contents($path);
        // 去掉注释，避免文档/注释里的示例被当成真实引用
        $src = preg_replace('#/\*.*?\*/#s', '', $src);
        $src = preg_replace('#(^|\s)//[^\n]*#', '$1', (string)$src);
        $src = preg_replace('#(^|\s)\#[^\n]*#', '$1', (string)$src);

        if (!preg_match_all('/Events::([A-Z_][A-Z0-9_]*)/', (string)$src, $mm)) {
            continue;
        }

        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        foreach (array_unique($mm[1]) as $name) {
            if (isset($defined[$name])) {
                $refs[$name][] = $rel;
            } else {
                $unknown[$name][] = $rel;
            }
        }
    }
}

echo '事件常量 ' . count($defined) . " 个，扫描 {$files} 个 PHP 文件\n";

// 3) 死钩子：定义了但没有任何引用
$dead = [];
foreach ($defined as $name => $value) {
    if (empty($refs[$name])) {
        $dead[$name] = $value;
    }
}

// 4) 未定义常量：引用了但 Events.php 里没有
if ($unknown) {
    foreach ($unknown as $name => $where) {
        echo "  BAD  引用了未定义的事件常量 Events::{$name}（" . implode(', ', array_slice(array_unique($where), 0, 3)) . "）\n";
    }
}

if ($dead) {
    foreach ($dead as $name => $value) {
        echo "  BAD  死钩子 Events::{$name} = '{$value}'：定义了但代码里没有任何派发点\n";
    }
}

if ($dead || $unknown) {
    echo '共 ' . count($dead) . ' 个死钩子，' . count($unknown) . " 个未定义常量 ❌\n";
    exit(1);
}

echo "所有事件常量都有派发点，且代码里没有引用未定义的常量 ✅\n";
exit(0);
