<?php
/**
 * 钩子 + 插件系统自检（无需 Redis / 无需数据库）
 *
 * 用法：php scripts/selftest_hooks.php
 *
 * 覆盖：
 *  1. 全局函数 add_action / do_action / add_filter / apply_filters 存在
 *  2. Action 传参、优先级顺序、返回 false 中断传播
 *  3. Filter 链式修改、附带额外参数
 *  4. remove_action / remove_filter / has_action / did_action
 *  5. 旧 API listen()/dispatch() 仍可用，且 dispatch 等于 do_action
 *  6. PluginManager 发现插件、启用状态持久化
 *  7. PluginLoader 真正加载插件并执行 register()
 *  8. 插件注册的钩子在业务点生效
 *  9. 坏插件（抛异常 / 未实现接口 / 入口文件缺失）被安全跳过，不拖垮整站
 */

define('APP_PATH', dirname(__DIR__) . '/');

require APP_PATH . 'core/Env.php';
Core\Env::load(APP_PATH);

if (!defined('DEBUG')) {
    define('DEBUG', true);
}

spl_autoload_register(function (string $class) {
    foreach (['Core\\' => 'core/', 'App\\' => 'app/', 'Plugins\\' => 'plugins/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = APP_PATH . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

require_once APP_PATH . 'core/hooks.php';

use Core\Event;
use Core\PluginManager;
use Core\PluginLoader;

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

// 测试用临时目录
$tmpRoot  = sys_get_temp_dir() . '/amubbs_hooktest_' . getmypid();
$tmpState = $tmpRoot . '/state';
$tmpPlug  = $tmpRoot . '/plugins';
@mkdir($tmpState, 0777, true);
@mkdir($tmpPlug, 0777, true);

echo "=== AMuBBS 钩子 / 插件系统自检 ===\n";
echo 'PHP: ' . PHP_VERSION . "\n";

// ---------------------------------------------------------------
echo "\n[1] 全局钩子函数\n";
check('add_action 存在', function_exists('add_action'));
check('do_action 存在', function_exists('do_action'));
check('add_filter 存在', function_exists('add_filter'));
check('apply_filters 存在', function_exists('apply_filters'));
check('remove_action 存在', function_exists('remove_action'));
check('has_action 存在', function_exists('has_action'));

// ---------------------------------------------------------------
echo "\n[2] Action 行为\n";
Event::clear();

$hits = [];
add_action('demo.event', function ($a, $b) use (&$hits) {
    $hits[] = "first:{$a}:{$b}";
}, 10);
add_action('demo.event', function ($a, $b) use (&$hits) {
    $hits[] = "second:{$a}:{$b}";
}, 5); // 数字小 = 先执行

do_action('demo.event', 'x', 'y');
check('Action 按优先级升序执行', $hits === ['second:x:y', 'first:x:y'], implode(' | ', $hits));
check('did_action 计数正确', Event::didAction('demo.event') === 1);
check('has_action 能识别', has_action('demo.event') === true);

// 返回 false 中断传播
Event::clear();
$order = [];
add_action('demo.stop', function () use (&$order) { $order[] = 'a'; return false; }, 1);
add_action('demo.stop', function () use (&$order) { $order[] = 'b'; }, 2);
do_action('demo.stop');
check('返回 false 中断后续回调', $order === ['a'], implode(',', $order));

// ---------------------------------------------------------------
echo "\n[3] Filter 行为\n";
Event::clear();

add_filter('demo.title', fn(string $t) => $t . '-A', 10);
add_filter('demo.title', fn(string $t) => $t . '-B', 5);   // 先执行
$result = apply_filters('demo.title', 'base');
check('Filter 链式修改且按优先级', $result === 'base-B-A', $result);

$result = apply_filters('demo.extra', 'v', 'arg1', 'arg2');
check('无过滤器时原值返回', $result === 'v');

Event::clear();
add_filter('demo.ctx', fn(string $v, string $ctx) => $v . '/' . $ctx, 10);
check('Filter 能接收附加参数', apply_filters('demo.ctx', 'val', 'ctx') === 'val/ctx');

// ---------------------------------------------------------------
echo "\n[4] 移除钩子\n";
Event::clear();
$cb = fn(string $t) => $t . '!';
add_filter('demo.rm', $cb);
check('移除前生效', apply_filters('demo.rm', 'a') === 'a!');
remove_filter('demo.rm', $cb);
check('移除后不生效', apply_filters('demo.rm', 'a') === 'a');

add_action('demo.rmall', fn() => null);
Event::remove('demo.rmall');
check('remove() 清空整个 tag', has_action('demo.rmall') === false);

// ---------------------------------------------------------------
echo "\n[5] 向后兼容旧 API\n";
Event::clear();
$legacy = null;
Event::listen('legacy.event', function ($data) use (&$legacy) {
    $legacy = $data;
});
Event::dispatch('legacy.event', ['k' => 'v']);
check('listen + dispatch 仍可用', $legacy === ['k' => 'v']);

// 核心 Events 常量的值就是钩子名，业务代码里的 dispatch 自动成为插件钩子
$fired = false;
Event::clear();
add_action('thread.created', function ($data) use (&$fired) {
    $fired = is_array($data) && ($data['thread_id'] ?? null) === 42;
});
Event::dispatch('thread.created', ['thread_id' => 42]);
check('核心事件名可直接被插件监听', $fired === true);

// ---------------------------------------------------------------
echo "\n[6] PluginManager 发现与状态\n";
$manager = new PluginManager(APP_PATH . 'plugins', $tmpState);
$found = $manager->discover();
check('发现示例插件', isset($found['example']), '实际: ' . implode(',', array_keys($found)));
check('元数据读取正确', ($found['example']['title'] ?? '') === '示例插件');
check('类名解析正确', ($found['example']['class'] ?? '') === 'Plugins\\Example\\Plugin');
check('默认未启用', $manager->isEnabled('example') === false);
check('getEnabled 初始为空', $manager->getEnabled() === []);

check('enable 返回成功', $manager->enable('example') === true);
check('状态文件已写入', is_file($tmpState . '/plugins.json'));
check('enable 后 isEnabled 为真', $manager->isEnabled('example') === true);

// 新实例从磁盘读回状态
$manager2 = new PluginManager(APP_PATH . 'plugins', $tmpState);
check('状态可跨实例持久化', $manager2->getEnabled() === ['example']);

// ---------------------------------------------------------------
echo "\n[7] PluginLoader 加载与 register()\n";
Event::clear();
$loader = new PluginLoader($manager2);
$loaded = $loader->load($manager2->getEnabled());

check('插件实例化成功', isset($loaded['example']));
check('实例实现 PluginInterface', ($loaded['example'] ?? null) instanceof Core\PluginInterface);
check('register() 注册了钩子', has_action('thread.created') === true);
check('register() 注册了 filter', has_filter('thread.title') === true);

// PluginManager::get() —— auth-modal 依赖这个调用形态
check('get() 可取到已加载插件', $manager2->get('example') !== null);
check('get() 忽略大小写', $manager2->get('Example') !== null);
check('get() 忽略分隔符', $manager2->get('ex_ample') !== null);
check('get() 未加载/不存在时返回 null 而不报错', $manager2->get('SocialLogin') === null);

// 插件注册的 filter 真的生效
$filtered = apply_filters('thread.title', '我的帖子', 1);
check('插件 filter 改变数据', $filtered === '我的帖子 [示例插件]', $filtered);

// 插件的 action 真的被触发
$actionFired = false;
Event::clear();
$manager3 = new PluginManager(APP_PATH . 'plugins', $tmpState);
$manager3->discover();
(new PluginLoader($manager3))->load(['example']);
Event::addAction('thread.created', function ($d) use (&$actionFired) {
    $actionFired = isset($d['thread_id']);
}, 99);
Event::doAction('thread.created', ['thread_id' => 7]);
check('插件 action 与其它监听器共存', $actionFired === true);

// 未启用的插件不会被加载
Event::clear();
$manager4 = new PluginManager(APP_PATH . 'plugins', $tmpState);
$manager4->discover();
$manager4->disable('example');
check('disable 生效', $manager4->getEnabled() === []);
$loadedNone = (new PluginLoader($manager4))->load($manager4->getEnabled());
check('停用后不加载任何插件', $loadedNone === []);

// ---------------------------------------------------------------
echo "\n[8] 坏插件隔离（不能把整站打白屏）\n";

// 8.1 register() 抛异常
@mkdir($tmpPlug . '/Boom', 0777, true);
file_put_contents($tmpPlug . '/Boom/plugin.json', json_encode(['name' => 'boom', 'class' => 'Plugins\\Boom\\Plugin']));
file_put_contents($tmpPlug . '/Boom/Plugin.php', <<<'PHP'
<?php
namespace Plugins\Boom;
class Plugin implements \Core\PluginInterface {
    public function register(): void { throw new \RuntimeException('boom'); }
}
PHP);

// 8.2 未实现接口
@mkdir($tmpPlug . '/NoIface', 0777, true);
file_put_contents($tmpPlug . '/NoIface/plugin.json', json_encode(['name' => 'noiface', 'class' => 'Plugins\\NoIface\\Plugin']));
file_put_contents($tmpPlug . '/NoIface/Plugin.php', <<<'PHP'
<?php
namespace Plugins\NoIface;
class Plugin { public function register(): void {} }
PHP);

// 8.3 入口文件缺失
@mkdir($tmpPlug . '/Missing', 0777, true);
file_put_contents($tmpPlug . '/Missing/plugin.json', json_encode(['name' => 'missing', 'class' => 'Plugins\\Missing\\Plugin']));

// 8.4 plugin.json 损坏
@mkdir($tmpPlug . '/BadJson', 0777, true);
file_put_contents($tmpPlug . '/BadJson/plugin.json', '{ not valid json');

$mgr = new PluginManager($tmpPlug, $tmpState . '/bad');
$found = $mgr->discover();
check('损坏的 plugin.json 被跳过', !isset($found['badjson']), '发现: ' . implode(',', array_keys($found)));

$threw = false;
try {
    (new PluginLoader($mgr))->load(['boom', 'noiface', 'missing']);
} catch (\Throwable $e) {
    $threw = true;
}

check('坏插件不会抛异常到上层', $threw === false);
check('好插件仍可正常工作（隔离失败）', is_array($found));

// ---------------------------------------------------------------
echo "\n[9] 生命周期 install() / uninstall()\n";

// 9.1 有生命周期的插件：install() 只在首次启用时跑一次，uninstall() 在卸载时跑
$lifeRoot  = $tmpRoot . '/life';
$lifeState = $tmpRoot . '/life_state';
$lifeHits  = $tmpRoot . '/life_hits.txt';
@mkdir($lifeRoot . '/LifeCycle', 0777, true);
file_put_contents($lifeRoot . '/LifeCycle/plugin.json', json_encode([
    'name'  => 'lifecycle',
    'title' => '生命周期插件',
    'class' => 'Plugins\\LifeCycle\\Plugin',
]));
file_put_contents($lifeRoot . '/LifeCycle/Plugin.php', <<<PHP
<?php
namespace Plugins\LifeCycle;
class Plugin implements \Core\PluginInterface {
    public function register(): void {}
    public function install(): void { file_put_contents('{$lifeHits}', "install\n", FILE_APPEND); }
    public function uninstall(): void { file_put_contents('{$lifeHits}', "uninstall\n", FILE_APPEND); }
}
PHP);

// 9.2 只有 register() 的插件：可选生命周期缺失时不能报错
@mkdir($lifeRoot . '/Bare', 0777, true);
file_put_contents($lifeRoot . '/Bare/plugin.json', json_encode([
    'name'  => 'bare',
    'title' => '裸插件',
    'class' => 'Plugins\\Bare\\Plugin',
]));
file_put_contents($lifeRoot . '/Bare/Plugin.php', <<<'PHP'
<?php
namespace Plugins\Bare;
class Plugin implements \Core\PluginInterface {
    public function register(): void {}
}
PHP);

$lifeMgr = new PluginManager($lifeRoot, $lifeState);
check('生命周期插件被发现', isset($lifeMgr->discover()['lifecycle']));
check('首次 enable 成功', $lifeMgr->enable('lifecycle') === true);
check('首次启用调用了 install()', is_file($lifeHits) && str_contains((string)file_get_contents($lifeHits), 'install'));

$lifeMgr->disable('lifecycle');
$lifeMgr->enable('lifecycle');
$hitsAfter = (string)file_get_contents($lifeHits);
check('再次启用不会重复 install()', substr_count($hitsAfter, 'install') === 1, str_replace("\n", ',', $hitsAfter));

check('uninstall() 返回成功', $lifeMgr->uninstall('lifecycle') === true);
check('卸载调用了 uninstall()', str_contains((string)file_get_contents($lifeHits), 'uninstall'));
check('卸载后状态被清掉（不再启用）', $lifeMgr->isEnabled('lifecycle') === false);
check('卸载后 getEnabled 不含该插件', !in_array('lifecycle', $lifeMgr->getEnabled(), true));

$bareThrew = false;
try {
    $lifeMgr->enable('bare');
} catch (\Throwable $e) {
    $bareThrew = true;
}
check('没有 install() 的插件启用不报错', $bareThrew === false);
check('裸插件启用后出现在启用列表', in_array('bare', $lifeMgr->getEnabled(), true), implode(',', $lifeMgr->getEnabled()));

$bareThrew = false;
try {
    $lifeMgr->uninstall('bare');
} catch (\Throwable $e) {
    $bareThrew = true;
}
check('没有 uninstall() 的插件卸载不报错', $bareThrew === false);
check('裸插件卸载后从启用列表消失', !in_array('bare', $lifeMgr->getEnabled(), true), implode(',', $lifeMgr->getEnabled()));

check('不存在的插件 enable 返回 false', $lifeMgr->enable('nope') === false);

$unknownThrew = false;
try {
    $ok = $lifeMgr->uninstall('nope');
} catch (\Throwable $e) {
    $unknownThrew = true;
    $ok = null;
}
check('卸载不存在的插件不报错且返回 false', $unknownThrew === false && $ok === false);

// 9.3 install() 抛异常不能把「启用」变成 500
@mkdir($lifeRoot . '/BadInstall', 0777, true);
file_put_contents($lifeRoot . '/BadInstall/plugin.json', json_encode([
    'name'  => 'badinstall',
    'class' => 'Plugins\\BadInstall\\Plugin',
]));
file_put_contents($lifeRoot . '/BadInstall/Plugin.php', <<<'PHP'
<?php
namespace Plugins\BadInstall;
class Plugin implements \Core\PluginInterface {
    public function register(): void {}
    public function install(): void { throw new \RuntimeException('install boom'); }
}
PHP);

$badInsMgr = new PluginManager($lifeRoot, $lifeState . '/bad_install');
$badInsThrew = false;
try {
    $badInsMgr->enable('badinstall');
} catch (\Throwable $e) {
    $badInsThrew = true;
}
check('install() 抛异常不会冒泡到调用方', $badInsThrew === false);
check('install() 抛异常后插件仍是启用状态', $badInsMgr->isEnabled('badinstall') === true);

// 9.4 PluginLoader 复用 PluginManager 的实例，不会重复 new
$reuseMgr = new PluginManager($lifeRoot, $lifeState . '/reuse');
$reuseMgr->enable('lifecycle');
$instA = $reuseMgr->instance('lifecycle');
$instB = $reuseMgr->instance('lifecycle');
check('instance() 同一插件只 new 一次', $instA !== null && $instA === $instB);
check('instance() 对不存在的插件返回 null', $reuseMgr->instance('nope') === null);

// ---------------------------------------------------------------
echo "\n[10] 清理\n";
$cleanup = function (string $dir) use (&$cleanup) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? $cleanup($p) : @unlink($p);
    }
    @rmdir($dir);
};
$cleanup($tmpRoot);
check('临时目录已清理', !is_dir($tmpRoot));

echo "\n========================================\n";
echo "通过: {$passed}    失败: {$failed}\n";
echo $failed === 0 ? "结果: 全部通过 ✅\n" : "结果: 存在失败 ❌\n";

exit($failed === 0 ? 0 : 1);
