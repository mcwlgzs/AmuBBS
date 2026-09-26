<?php
/**
 * 缓存层自检脚本（无需 Redis / 无需数据库）
 *
 * 用法：php scripts/selftest_cache.php
 *
 * 覆盖 P0 的验收点：
 *  1. 无 redis 扩展时驱动自动落到 file
 *  2. set/get/delete 基本读写与 TTL 过期
 *  3. 文件缓存跨进程持久化（真的落盘）
 *  4. add() 原子 SETNX 语义
 *  5. increment() 与 incrementWithLimit() 上限
 *  6. getAndDelete() 一次性读取
 *  7. deleteMulti() 的 key 版本化修复
 *  8. deletePattern() 的 key 版本化修复
 *  9. getStale() 与普通 set() 共用命名空间而不冲突
 */

define('APP_PATH', dirname(__DIR__) . '/');

require APP_PATH . 'core/Env.php';
Core\Env::load(APP_PATH);

if (!defined('DEBUG')) {
    define('DEBUG', true);
}

// 与 Bootstrap 一致的最小自动加载器（仅 Core\ 命名空间）
spl_autoload_register(function (string $class) {
    $prefix = 'Core\\';
    if (str_starts_with($class, $prefix)) {
        $file = APP_PATH . 'core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use Core\Cache;

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
    }
}

echo "=== AMuBBS 缓存层自检 ===\n";
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'redis 扩展: ' . (extension_loaded('redis') ? '已安装' : '未安装') . "\n";

// ---------------------------------------------------------------
echo "\n[1] 驱动选择\n";
$driver = Cache::driver();
check('无 redis 扩展时驱动为 file', $driver === 'file', "实际: {$driver}");
check('getRedis() 在文件驱动下返回 null', Cache::getRedis() === null);

// ---------------------------------------------------------------
echo "\n[2] 基本读写\n";
Cache::flush();
check('miss 返回 null', Cache::get('selftest:absent') === null);
check('set 成功', Cache::set('selftest:a', ['x' => 1, 'name' => '阿木'], 60) === true);
check('命中且结构一致', Cache::get('selftest:a') === ['x' => 1, 'name' => '阿木']);
check('存储字符串', Cache::set('selftest:s', 'hello', 60) && Cache::get('selftest:s') === 'hello');
check('存储 false 不丢失', Cache::set('selftest:f', false, 60) && Cache::get('selftest:f') === false);

// ---------------------------------------------------------------
echo "\n[3] 文件落盘与持久化\n";
$cacheDir = APP_PATH . 'storage/cache';
$files = glob($cacheDir . '/*/*/*.cache') ?: [];
check('缓存目录存在可写', is_dir($cacheDir) && is_writable($cacheDir));
check('已生成缓存文件', count($files) > 0, '文件数: ' . count($files));

// 新进程读取，验证真的跨请求持久化
$probeTpl = <<<'PHP'
<?php
define('APP_PATH', __APP_PATH__);
require APP_PATH . 'core/Env.php';
Core\Env::load(APP_PATH);
if (!defined('DEBUG')) define('DEBUG', true);
spl_autoload_register(function (string $c) {
    if (str_starts_with($c, 'Core\\')) {
        $f = APP_PATH . 'core/' . str_replace('\\', '/', substr($c, 5)) . '.php';
        if (file_exists($f)) require_once $f;
    }
});
echo json_encode(Core\Cache::get('selftest:a'));
PHP;
$probe = str_replace('__APP_PATH__', var_export(APP_PATH, true), $probeTpl);
$probeFile = APP_PATH . 'storage/probe_cache.php';
file_put_contents($probeFile, $probe);
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' 2>&1');
@unlink($probeFile);
check('新进程仍能读到缓存', trim((string)$out) === '{"x":1,"name":"\u963f\u6728"}', '实际: ' . trim((string)$out));

// ---------------------------------------------------------------
echo "\n[4] TTL 过期\n";
// 和下面「带 TTL 的递增」同样的道理：TTL=1 的 key 紧接着读，机器一忙就可能已经过期。
// 所以「还没过期能读到」用 10 秒的 key，「等它过期」单独用一个 1 秒的 key。
Cache::delete('selftest:ttl');
Cache::set('selftest:ttl', 'v', 10);
check('未过期时可读', Cache::get('selftest:ttl') === 'v');
Cache::delete('selftest:ttl');

Cache::delete('selftest:ttl_exp');
Cache::set('selftest:ttl_exp', 'v', 1);
sleep(2);
check('过期后返回 null', Cache::get('selftest:ttl_exp') === null);
Cache::delete('selftest:ttl_exp');

// ---------------------------------------------------------------
echo "\n[5] add() 原子 SETNX\n";
Cache::delete('selftest:lock');
check('首次 add 成功', Cache::add('selftest:lock', 'owner1', 30) === true);
check('重复 add 失败', Cache::add('selftest:lock', 'owner2', 30) === false);
check('锁值未被覆盖', Cache::get('selftest:lock') === 'owner1');
Cache::delete('selftest:lock');
check('删除后可再次 add', Cache::add('selftest:lock', 'owner3', 30) === true);
Cache::delete('selftest:lock');

// ---------------------------------------------------------------
echo "\n[6] 递增与频率限制\n";
Cache::delete('selftest:cnt');
check('首次递增为 1', Cache::increment('selftest:cnt') === 1);
check('第二次为 3', Cache::increment('selftest:cnt', 2) === 3);

Cache::delete('selftest:rate');
$results = [];
for ($i = 0; $i < 4; $i++) {
    $results[] = Cache::incrementWithLimit('selftest:rate', 3, 60);
}
check('前 3 次放行', $results === [1, 2, 3, -1], '实际: ' . implode(',', $results));
Cache::delete('selftest:rate');

// 带 TTL 的递增会过期
// 「立即读还在」和「等它过期」分成两个 key：
// 原来共用一个 TTL=1 的 key，机器一忙（紧接着跑过压测/清缓存）就可能在读之前就过期，
// 断言会偶发失败——那是测试写法的问题，不是缓存的问题。
Cache::delete('selftest:incttl');
Cache::increment('selftest:incttl', 5, 10);
check('带 TTL 递增生效', Cache::get('selftest:incttl') === 5);
Cache::delete('selftest:incttl');

Cache::delete('selftest:incttl_exp');
Cache::increment('selftest:incttl_exp', 5, 1);
sleep(2);
check('带 TTL 递增已过期', Cache::get('selftest:incttl_exp') === null);
Cache::delete('selftest:incttl_exp');

// 固定窗口回归：TTL 只在 key 首次创建时生效。
// 关键是在「窗口内」持续递增才能测出来：如果每次递增都重写 time()+ttl，
// 过期时间会随每个请求往后推，只要用户一直在点（窗口内总有请求）
// 计数就永远不过期 —— 线上表现是限流一旦触发再也解不开，一直弹「请求过于频繁」。
// 上面那条「不再递增时会过期」的断言覆盖不到这种情况（那次睡眠已经真的过期了）。
Cache::delete('selftest:fixedwin');
$fixedWin = [];
for ($i = 0; $i < 4; $i++) {
    $fixedWin[] = Cache::increment('selftest:fixedwin', 1, 2);  // ttl=2s，每秒递增一次
    if ($i < 3) {
        sleep(1);
    }
}
// t=0/1/2 落在同一个 2s 窗口内 -> 1,2,3；t=3 时窗口已到期 -> 重新从 1 开始
check(
    'TTL 递增是固定窗口（窗口内递增不把过期时间推后）',
    $fixedWin === [1, 2, 3, 1],
    '实际: ' . implode(',', $fixedWin) . '（会后移的写法得到 1,2,3,4）'
);
Cache::delete('selftest:fixedwin');
Cache::delete('selftest:fixedwin');

// ---------------------------------------------------------------
echo "\n[7] getAndDelete 与批量递增\n";
Cache::set('selftest:once', 'token', 60);
check('读取并删除返回原值', Cache::getAndDelete('selftest:once') === 'token');
check('第二次返回 null', Cache::getAndDelete('selftest:once') === null);

Cache::delete('selftest:bi1');
Cache::delete('selftest:bi2');
check('batchIncrement 成功', Cache::batchIncrement(['selftest:bi1' => 5, 'selftest:bi2' => 3]) === true);
check('bi1 = 5', Cache::get('selftest:bi1') == 5);
check('bi2 = 3', Cache::get('selftest:bi2') == 3);

// ---------------------------------------------------------------
echo "\n[8] key 版本化修复（原 bug：删不掉 v1: 前缀缓存）\n";
Cache::set('forums:list', 'F', 300);
Cache::set('forums:children:all', 'C', 300);
Cache::set('threads:latest:p1', 'T', 300);

Cache::deleteMulti(['threads:latest:p1']);
check('deleteMulti 删除了带版本前缀的 key', Cache::get('threads:latest:p1') === null);
check('未涉及的 key 仍在', Cache::get('forums:list') === 'F');

$deleted = Cache::deletePattern('forums:*');
// 只断言「至少删掉了我们刚放进去的 2 个」：缓存是共享的，别的进程完全可能
// 同时存在 forums:* 的 key，用 === 2 会把偶发的外部写入判成失败。
// 真正的契约由下面两条「已清除」断言保证。
check('deletePattern 至少匹配到 2 个 key', $deleted >= 2, "实际: {$deleted}");
check('forums:list 已清除', Cache::get('forums:list') === null);
check('forums:children:all 已清除', Cache::get('forums:children:all') === null);

// ---------------------------------------------------------------
echo "\n[9] getStale 与 set 共用命名空间\n";
Cache::set('selftest:mix', 'plain', 300);
$called = false;
$value = Cache::getStale('selftest:mix', function () use (&$called) {
    $called = true;
    return 'from-callback';
}, 300);
check('set 写入的裸值被 getStale 直接返回', $value === 'plain', "实际: {$value}");
check('未触发回调重建', $called === false);

Cache::delete('selftest:mix');
$value = Cache::getStale('selftest:mix', fn() => 'built', 300);
check('miss 时执行回调', $value === 'built');
check('回调结果被缓存', Cache::getStale('selftest:mix', fn() => 'other', 300) === 'built');
check('普通 get 也能读到 stale 记录的值', Cache::get('selftest:mix') === 'built');

// ---------------------------------------------------------------
echo "\n[10] preload 与清理\n";
Cache::flush();
Cache::set('selftest:p1', 'v1', 300);
Cache::set('selftest:p2', 'v2', 300);
Cache::preload(['selftest:p1', 'selftest:p2']);
check('preload 后 L1 可读', Cache::get('selftest:p1') === 'v1' && Cache::get('selftest:p2') === 'v2');

$before = count(glob($cacheDir . '/*/*/*.cache') ?: []);
$cleared = Cache::flush();
check('flush 清空缓存', Cache::get('selftest:p1') === null);
check('flush 返回清理数量', $cleared > 0, "清理: {$cleared}, flush 前文件: {$before}");

// ---------------------------------------------------------------
echo "\n========================================\n";
echo "通过: {$passed}    失败: {$failed}\n";
echo $failed === 0 ? "结果: 全部通过 ✅\n" : "结果: 存在失败 ❌\n";

exit($failed === 0 ? 0 : 1);
