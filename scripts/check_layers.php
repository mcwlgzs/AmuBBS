<?php
/**
 * 静态检查：Controllers / Services 里不许再出现数据访问调用
 *
 * 用法: php scripts/check_layers.php
 *
 * 这是 ②「Controller + Model」重构的验收线：SQL 只允许出现在 app/Models 里。
 * 控制器/服务允许保留的只有事务与连接控制（beginTransaction / commit / rollBack /
 * transaction / useMaster / restoreReadWrite）以及极少数框架能力（serverVersion）。
 *
 * 为什么值得固化成脚本：这轮重构是一次性的体力活，但「以后随手在服务里写一句 SQL」
 * 是持续会发生的；没有这道闸，架构几轮之后就会重新长回去。
 */

$root = dirname(__DIR__);

// 允许出现在 Controller / Service 的 Database:: 调用（非 SQL）
$allowed = [
    'beginTransaction', 'commit', 'rollBack', 'transaction',
    'useMaster', 'restoreReadWrite', 'serverVersion',
];

// 这些是数据访问（SQL 或缓存化 SQL），只允许在 Model 里出现
$forbidden = [
    'execute', 'fetchAll', 'fetchOne', 'fetchAllCached', 'fetchOneCached',
    'insert', 'update', 'delete', 'lastInsertId', 'query', 'prepare',
];

// 允许自建连接的例外：必须写清理由，避免例外悄悄扩散
$connectionExceptions = [
    'app/Controllers/Install.php' =>
        '安装器：此时 config/database.php 还没生成，只能自己连',
    'app/Controllers/Admin/SystemController.php' =>
        '集群节点连通性探测：连的是远端主机，框架连接绑定的是本机配置',
];

$layers = [
    'app/Controllers' => 'Controller',
    'app/Services'    => 'Service',
];

$bad = [];
$files = 0;
$allowedHits = 0;

foreach ($layers as $rel => $label) {
    $base = $root . '/' . $rel;
    if (!is_dir($base)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $files++;
        $src = (string)file_get_contents($f->getPathname());
        // 去注释：注释里经常写「原来这里是 DELETE FROM ...」，不算真实调用
        $src = preg_replace('#/\*.*?\*/#s', '', $src);
        $src = preg_replace('#(^|\s)//[^\n]*#', '$1', (string)$src);
        $src = preg_replace('#(^|\s)\#[^\n]*#', '$1', (string)$src);

        $file = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));

        foreach ($forbidden as $method) {
            if (preg_match('/Database::' . $method . '\s*\(/', (string)$src)) {
                $bad[] = "{$file} 里调用了 Database::{$method}（{$label} 层不该做数据访问）";
            }
        }
        foreach ($allowed as $method) {
            $allowedHits += (int)preg_match_all('/Database::' . $method . '\s*\(/', (string)$src);
        }

        // 直接拼 SQL 交给 Database 之外的入口（例如通过 new PDO）也拦一下
        if (preg_match('/new\s+\\\\?PDO\s*\(/', (string)$src)) {
            if (!isset($connectionExceptions[$file])) {
                $bad[] = "{$file} 里自己 new PDO（{$label} 层不该自建连接）";
            } else {
                $exceptions[] = "{$file}：{$connectionExceptions[$file]}";
            }
        }
    }
}

echo '扫描 ' . $files . " 个 Controller/Service 文件\n";
echo '允许的事务/连接调用 ' . $allowedHits . " 处\n";
if (!empty($exceptions)) {
    foreach (array_unique($exceptions) as $e) { echo "  例外  {$e}\n"; }
}
if ($bad) {
    foreach ($bad as $b) { echo "  BAD  {$b}\n"; }
    echo '共 ' . count($bad) . " 处越层数据访问 ❌\n";
    exit(1);
}
echo "Controllers / Services 层已无数据访问调用 ✅\n";
exit(0);
