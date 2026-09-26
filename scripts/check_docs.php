<?php
/**
 * 静态检查：Markdown 文档的结构完整性
 *
 * 为什么需要它：文档是用脚本按行号「拼接」重写过的，一次没对齐就会留下
 * 孤立的代码片段或未闭合的 ``` 围栏 —— 渲染出来是一大片代码块，
 * 但没有任何测试会报错（本轮就真的漏了一处：docs/02-架构.md 里残留了
 * `    }` / `}` 和多余的围栏，导致后面整篇被当成代码）。链接失效同理。
 *
 * 检查两件事：
 *   1. 每个 Markdown 文件的 ``` 围栏数量必须成对；
 *   2. 文档内相对路径的 .md 链接必须指向真实存在的文件。
 *
 * 代码块里的内容会被剥掉再找链接，避免把示例里的假链接当成真链接。
 *
 * 用法: php scripts/check_docs.php
 *       php scripts/check_docs.php <另一个目录>   # 验证检查器本身有效
 */

$root = dirname(__DIR__);
$scanDir = $argv[1] ?? ($root . '/docs');

$files = [];
if (is_file($scanDir)) {
    $files[] = $scanDir;
} else {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'md') {
            $files[] = $f->getPathname();
        }
    }
    // 仓库根目录的 README 也一起检查
    foreach (['README.md', 'README.en.md'] as $r) {
        if (is_file($root . '/' . $r)) {
            $files[] = $root . '/' . $r;
        }
    }
}

if (!$files) {
    fwrite(STDERR, "没有找到任何 Markdown 文件: {$scanDir}\n");
    exit(1);
}

$problems = [];
$checked = 0;

foreach ($files as $path) {
    $checked++;
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $src = (string)file_get_contents($path);

    // 1) 围栏配对
    $fences = preg_match_all('/^```/m', $src);
    if ($fences % 2 !== 0) {
        $problems[] = "{$rel}：代码围栏 ``` 出现了 {$fences} 次（应为偶数，有未闭合的块）";
    }

    // 2) 相对链接是否存在
    $clean = (string)preg_replace('/```.*?```/s', '', $src);
    if (preg_match_all('/\]\(([^)\s#]+\.md)(?:#[^)]*)?\)/', $clean, $m)) {
        foreach (array_unique($m[1]) as $link) {
            if (preg_match('#^[a-z]+://#i', $link)) {
                continue;
            }
            $target = dirname($path) . '/' . $link;
            if (!is_file($target)) {
                $problems[] = "{$rel}：链接指向不存在的文件 {$link}";
            }
        }
    }
}

echo "检查了 {$checked} 个 Markdown 文件\n";

if ($problems) {
    foreach ($problems as $p) {
        echo "  BAD  {$p}\n";
    }
    echo '共 ' . count($problems) . " 处文档结构问题 ❌\n";
    exit(1);
}

echo "代码围栏成对、文档内链接均有效 ✅\n";
exit(0);
