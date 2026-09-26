<?php
/**
 * 静态检查：代码里引用的表，安装脚本里是否真的建了
 *
 * 用法: php scripts/check_schema.php
 *
 * 为什么需要它：SocialLoginService 一直对 `social_logins` 增删改查，
 * 而 install/database.sql 从未建这张表 —— 装了站也永远跑不通社交登录，
 * 只会在用户点「用 GitHub 登录」时抛 1146。语法检查、路由检查、冒烟都发现不了，
 * 因为没人会在冒烟里真的去走一遍 OAuth。
 *
 * 判定方式：
 *   1) 从 install/database.sql 收集 CREATE TABLE 的表名（安装脚本知道的表）
 *   2) 从运行库 information_schema 收集实际存在的表（当前部署）
 *   3) 扫 app/ core/ resources/ 里的 FROM / JOIN / INTO / UPDATE / DELETE FROM 后的标识符
 *   两处都不认识的表名 → 报错。
 */

$root = dirname(__DIR__);

// 1) 安装脚本里的表
$schemaFile = $root . '/install/database.sql';
$installerTables = [];
if (is_file($schemaFile)) {
    $sql = (string)file_get_contents($schemaFile);
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $sql, $m)) {
        foreach ($m[1] as $t) { $installerTables[strtolower($t)] = true; }
    }
}
if (!$installerTables) {
    echo "没有从 install/database.sql 解析到任何表，脚本失效 ❌\n";
    exit(1);
}

// 2) 运行库里实际存在的表（连不上就只按安装脚本判定）
$dbTables = [];
try {
    define('APP_PATH', $root . '/');
    if (!defined('DEBUG')) { define('DEBUG', false); }
    require APP_PATH . 'core/Env.php';
    Core\Env::load(APP_PATH);
    require APP_PATH . 'core/Database.php';
    foreach (Core\Database::fetchAll('SHOW TABLES') as $row) {
        $dbTables[strtolower((string)array_values($row)[0])] = true;
    }
} catch (\Throwable $e) {
    echo "提示：连不上数据库，只按安装脚本判定（" . $e->getMessage() . "）\n";
}

$known = $installerTables + $dbTables;

// 3) 扫代码里引用的表
$patterns = [
    '/\bFROM\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
    '/\bJOIN\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
    '/\bINSERT\s+INTO\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
    '/\bUPDATE\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+SET\b/i',
    '/\bDELETE\s+FROM\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i',
];
// 这些不是表名：SQL 关键字、子查询占位、派生表
$ignore = [
    'select' => true, 'dual' => true, 'information_schema' => true,
    'unix_timestamp' => true, 'from_unixtime' => true, 'case' => true,
];

$bad = [];
$files = 0;
foreach ([$root . '/app', $root . '/core', $root . '/resources', $root . '/plugins'] as $base) {
    if (!is_dir($base)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $files++;
        $src = (string)file_get_contents($f->getPathname());
        // 去掉注释：注释里常出现「原本这里 DELETE FROM sessions」这类历史说明，不该算引用
        $src = preg_replace('#/\*.*?\*/#s', '', $src);
        $src = preg_replace('#(^|\s)//[^\n]*#', '$1', (string)$src);
        $src = preg_replace('#(^|\s)\#[^\n]*#', '$1', (string)$src);
        foreach ($patterns as $re) {
            if (!preg_match_all($re, $src, $m)) { continue; }
            foreach ($m[1] as $table) {
                $t = strtolower($table);
                if (isset($known[$t]) || isset($ignore[$t])) { continue; }
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
                $bad[$t][] = $rel;
            }
        }
    }
}

echo '扫描 ' . $files . " 个 PHP 文件\n";
echo '安装脚本 ' . count($installerTables) . ' 张表，运行库 ' . count($dbTables) . " 张表\n";
if ($bad) {
    foreach ($bad as $table => $where) {
        $where = array_values(array_unique($where));
        echo "  BAD  代码引用了未知表 `{$table}`（" . implode(', ', array_slice($where, 0, 3)) . "）\n";
    }
    echo '共 ' . count($bad) . " 张表无出处 ❌\n";
    exit(1);
}
echo "代码引用的表都能在安装脚本/运行库里找到 ✅\n";
exit(0);
