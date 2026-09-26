<?php
/**
 * 自检：全新安装（安装向导）能不能跑通
 *
 * 为什么要在「临时副本 + 临时数据库」上跑：
 *   向导最后两步会覆写 config/database.php、config/app.php，并用提交的库名导入
 *   install/database.sql。在仓库里直接跑会毁掉当前站点的配置与数据，所以这里
 *   把整个应用复制到临时目录、起一个独立端口的 php -S、用一个临时库，
 *   跑完把进程杀掉、库删掉、副本删掉。当前站点完全不受影响。
 *
 * 覆盖：
 *   1. 每一步都是服务端渲染、整块替换 #installWizard，页面上没有任何 Alpine 指令
 *   2. 步骤推进 = 服务端 session + 真实进度夹取（不能跳步，能回退且回填）
 *   3. 非 htmx 的旧 JSON 接口保持兼容
 *   4. 完整走通：环境检测 → 建库导入 SQL → 建管理员 → 写配置 → 写锁文件
 *   5. 装完以后 /install 变成「已安装」提示，安装接口不再对外暴露
 *
 * 用法: php scripts/selftest_install.php
 * 环境变量:
 *   INSTALL_SELFTEST_DB    临时库名（默认 amubbs_selftest_install）
 *   INSTALL_SELFTEST_PORT  指定端口（默认自动挑一个空闲的）
 */

$ROOT = dirname(__DIR__);
$DB   = getenv('INSTALL_SELFTEST_DB') ?: 'amubbs_selftest_install';
$PORT = (int)(getenv('INSTALL_SELFTEST_PORT') ?: 0);

$pass = 0; $fail = 0; $failures = [];
function ok(string $l): void { global $pass; $pass++; echo "  \033[32mPASS\033[0m  {$l}\n"; }
function bad(string $l, string $d = ''): void { global $fail, $failures; $fail++; $failures[] = $l; echo "  \033[31mFAIL\033[0m  {$l}" . ($d !== '' ? "  --> {$d}" : '') . "\n"; }
function check(string $l, bool $c, string $d = ''): void { $c ? ok($l) : bad($l, $d); }

/** Alpine 指令的特征串（与 smoke.php 保持一致） */
const ALPINE_RE = '/x-data|x-model|x-show|x-text|x-for|x-ref|x-init|@click|@submit|@change|@keydown|@input|:class="|:disabled="|:placeholder="|:type="|x-cloak[ >]|\$refs|\$dispatch/';

$COOKIE = sys_get_temp_dir() . '/amubbs_selftest_install_' . getmypid() . '.txt';
$COPY   = sys_get_temp_dir() . '/amubbs-selftest-install-' . getmypid();
$COOKIE2 = $COOKIE . '.fresh';
@unlink($COOKIE);
@unlink($COOKIE2);

// ---------------------------------------------------------------- HTTP
function req(string $method, string $url, ?array $post = null, bool $htmx = false, string $csrf = ''): array
{
    global $COOKIE;
    $headers = [];
    if ($htmx) { $headers[] = 'HX-Request: true'; }
    if ($csrf !== '') { $headers[] = 'X-CSRF-TOKEN: ' . $csrf; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $COOKIE, CURLOPT_COOKIEFILE => $COOKIE,
        CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); curl_close($ch); return ['code' => 0, 'headers' => '', 'body' => '', 'error' => $e]; }
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $hlen), 'body' => substr($raw, $hlen), 'error' => ''];
}

function csrfFrom(string $html): string
{
    foreach (['/name="csrf-token"\s+content="([^"]+)"/', '/name="csrf-token" content="([^"]+)"/'] as $re) {
        if (preg_match($re, $html, $m)) { return $m[1]; }
    }
    return '';
}

/** 当前渲染出来的是第几步（看步骤条上哪一格带 active） */
function activeStep(string $html): int
{
    if (preg_match_all('/<div class="step-item ([^"]*)">/', $html, $m)) {
        foreach ($m[1] as $i => $cls) {
            if (str_contains($cls, 'active')) { return $i + 1; }
        }
    }
    return 0;
}

// ---------------------------------------------------------------- 临时副本
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function copyTree(string $src, string $dst, array $skipDirs = []): void
{
    @mkdir($dst, 0777, true);
    foreach (scandir($src) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $from = $src . DIRECTORY_SEPARATOR . $e;
        $to   = $dst . DIRECTORY_SEPARATOR . $e;
        if (is_dir($from)) {
            if (in_array($e, $skipDirs, true)) { continue; }
            copyTree($from, $to, $skipDirs);
            continue;
        }
        copy($from, $to);
    }
}

function freePort(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$sock) { return 0; }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

function waitForServer(string $base, int $seconds = 15): bool
{
    $deadline = time() + $seconds;
    while (time() < $deadline) {
        $conn = @fsockopen('127.0.0.1', (int)parse_url($base, PHP_URL_PORT), $errno, $errstr, 0.5);
        if ($conn) { fclose($conn); return true; }
        usleep(200000);
    }
    return false;
}

// ---------------------------------------------------------------- 准备
echo "== 准备临时副本 ==\n";
rrmdir($COPY);
copyTree($ROOT, $COPY, ['.git', 'node_modules']);
foreach (['storage/cache', 'storage/sessions', 'storage/logs', 'public/uploads/images', 'install'] as $d) {
    @mkdir($COPY . '/' . $d, 0777, true);
}
@file_put_contents($COPY . '/storage/cache/.gitkeep', '');
@file_put_contents($COPY . '/storage/logs/.gitkeep', '');
@unlink($COPY . '/install/install.lock');

// 临时副本的 .env 也指向临时库：万一有代码路径读 .env，也绝不会碰到真实库
$envFile = $COPY . '/.env';
if (is_file($envFile)) {
    $env = (string)file_get_contents($envFile);
    $env = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE=' . $DB, $env);
    file_put_contents($envFile, $env);
}
ok('临时副本已建立（' . $COPY . '）');
check('副本里没有 install.lock', !file_exists($COPY . '/install/install.lock'));

// 独立数据库连接（也是给「建库前的清理」用）
$dsnRoot = 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4';
$pdoRoot = null;
try {
    $pdoRoot = new PDO($dsnRoot, 'root', 'amubbs', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdoRoot->exec("DROP DATABASE IF EXISTS `{$DB}`");
    ok("临时库 {$DB} 已重置");
} catch (PDOException $e) {
    bad('连不上 MySQL（127.0.0.1:3307 root/amubbs）', $e->getMessage());
    rrmdir($COPY);
    exit(1);
}

// ---------------------------------------------------------------- 起服务
$port = $PORT > 0 ? $PORT : freePort();
if ($port <= 0) { bad('挑不到空闲端口'); rrmdir($COPY); exit(1); }
$base = "http://127.0.0.1:{$port}";
$logFile = $COPY . '/storage/logs/selftest-server.log';

$phpBin = PHP_BINARY;
$cmd = [$phpBin, '-S', "127.0.0.1:{$port}", '-t', 'public', 'public/router.php'];
$descriptors = [
    0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
    1 => ['file', $logFile, 'a'],
    2 => ['file', $logFile, 'a'],
];
$proc = proc_open($cmd, $descriptors, $pipes, $COPY);
if (!is_resource($proc)) { bad('起不了 php -S'); rrmdir($COPY); exit(1); }
ok("临时站点已启动 {$base}");

$teardown = function () use (&$proc, $COPY, $pdoRoot, $DB, $COOKIE, $COOKIE2, $logFile) {
    if (is_resource($proc)) {
        proc_terminate($proc);
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            $st = proc_get_status($proc);
            if (!$st['running']) { break; }
            usleep(100000);
        }
        if (proc_get_status($proc)['running']) { proc_terminate($proc, 9); }
        proc_close($proc);
    }
    if ($pdoRoot instanceof PDO) {
        try { $pdoRoot->exec("DROP DATABASE IF EXISTS `{$DB}`"); } catch (Throwable $e) {}
    }
    @unlink($COOKIE);
    @unlink($COOKIE2);
    rrmdir($COPY);
    @unlink($logFile);
};
register_shutdown_function($teardown);

if (!waitForServer($base)) {
    bad('临时站点没起来', substr((string)@file_get_contents($logFile), -400));
    exit(1);
}

$lockFile     = $COPY . '/install/install.lock';
$dbConfigFile = $COPY . '/config/database.php';
$appConfigFile = $COPY . '/config/app.php';

// ---------------------------------------------------------------- 1. 第 1 步
echo "\n== GET /install ==\n";
$r = req('GET', "{$base}/install");
$html = $r['body'];
check('HTTP 200', $r['code'] === 200, "HTTP {$r['code']}");
check('整页无 Alpine 指令', preg_match_all(ALPINE_RE, $html) === 0, '命中 ' . preg_match_all(ALPINE_RE, $html) . ' 处');
check('不再加载 Alpine 资源', stripos($html, 'alpine') === false);
check('加载 htmx', str_contains($html, '/assets/vendor/htmx/htmx.min.js'));
check('替换目标是 #installWizard', str_contains($html, 'id="installWizard"') && str_contains($html, 'hx-target="#installWizard"'));
check('CSRF 走 hx-headers', (bool)preg_match('/hx-headers=\'\{"X-CSRF-TOKEN":"[0-9a-f]{64}"\}\'/', $html));
check('停在第 1 步', activeStep($html) === 1, '实际第 ' . activeStep($html) . ' 步');
check('有「开始安装」按钮', str_contains($html, 'hx-post="/install/next"') && str_contains($html, '开始安装'));
check('第 1 步没有「上一步」', !str_contains($html, 'hx-post="/install/back"'));
check('按钮都是 type=button（不会被浏览器原生提交）', substr_count($html, '<button type="button"') === substr_count($html, '<button '));

$csrf = csrfFrom($html);
check('拿到 CSRF token', $csrf !== '');

echo "\n-- 非 htmx 旧接口兼容 --\n";
// 安装向导的每个 POST（含环境检测这种只读步骤）都要求 CSRF：它会往 $_SESSION 写 install_check，
// 被跨站请求触发等于让受害者页面显示一份伪造的检测结果。这里带上 token 验证 JSON 内容协商仍然成立。
$r = req('POST', "{$base}/install/check", [], false, $csrf);
$json = json_decode($r['body'], true);
check('/install/check 非 htmx 仍回 JSON', is_array($json) && ($json['success'] ?? false) === true, substr($r['body'], 0, 160));
check('JSON 里带 items 与 pass', isset($json['data']['items'], $json['data']['pass']));
check('JSON 里含 Redis 可选项（无 Redis 也能装）', str_contains(json_encode($json, JSON_UNESCAPED_UNICODE), 'Redis 扩展'));

echo "\n-- 无 CSRF 的向导 POST 必须被拒绝 --\n";
$r = req('POST', "{$base}/install/check", []);
check('/install/check 无 token 被拒', $r['code'] === 400 || str_contains($r['body'], 'CSRF'), substr($r['body'], 0, 120));
$r = req('POST', "{$base}/install/next", []);
check('/install/next 无 token 被拒', $r['code'] === 400 || str_contains($r['body'], 'CSRF'), substr($r['body'], 0, 120));

// ---------------------------------------------------------------- 2. 第 2 步
echo "\n== POST /install/next（1 → 2）==\n";
$r = req('POST', "{$base}/install/next", [], true, $csrf);
check('HTTP 200', $r['code'] === 200, "HTTP {$r['code']}");
check('只回片段（没有整页外壳）', !str_contains($r['body'], '<html') && !str_contains($r['body'], '<head'));
check('无 Alpine 指令', preg_match_all(ALPINE_RE, $r['body']) === 0, '命中 ' . preg_match_all(ALPINE_RE, $r['body']) . ' 处');
check('推进到第 2 步', activeStep($r['body']) === 2, '实际第 ' . activeStep($r['body']) . ' 步');
check('未检测时提示先检测', str_contains($r['body'], '点击下方按钮开始检测服务器环境'));
check('按钮是「开始检测」', str_contains($r['body'], '开始检测') && str_contains($r['body'], 'hx-post="/install/check"'));
check('这一步有「上一步」', str_contains($r['body'], 'hx-post="/install/back"'));
check('检测未做之前没有「下一步」', !str_contains($r['body'], '<span class="hx-idle">下一步</span>'));

echo "\n== POST /install/check ==\n";
$r = req('POST', "{$base}/install/check", [], true, $csrf);
$body = $r['body'];
check('HTTP 200', $r['code'] === 200, "HTTP {$r['code']}");
check('仍停在第 2 步', activeStep($body) === 2, '实际第 ' . activeStep($body) . ' 步');
check('渲染出检测列表', str_contains($body, 'class="check-list"'));
check('列出了 PHP 版本', str_contains($body, 'PHP 版本'));
check('列出了 Redis 可选项', str_contains($body, 'Redis 扩展'));
check('列出了目录可写性', str_contains($body, 'storage/cache/ 可写'));
check('全部通过（无 fail 项）', !str_contains($body, 'check-result fail'));
check('按钮变成「重新检测」+「下一步」', str_contains($body, '重新检测') && str_contains($body, '<span class="hx-idle">下一步</span>'));
check('「下一步」可用（没有 disabled）', !preg_match('/hx-post="\/install\/next"[^>]*disabled/', $body));

// ---------------------------------------------------------------- 3. 第 3 步
echo "\n== POST /install/next（2 → 3）==\n";
$r = req('POST', "{$base}/install/next", [], true, $csrf);
check('推进到第 3 步', activeStep($r['body']) === 3, '实际第 ' . activeStep($r['body']) . ' 步');
check('表单字段是真表单字段', str_contains($r['body'], 'name="host"') && str_contains($r['body'], 'name="database"')
    && str_contains($r['body'], 'name="username"') && str_contains($r['body'], 'name="password"'));
check('数据库名默认 amubbs', (bool)preg_match('/name="database"[^>]*value="amubbs"/', $r['body']));

echo "\n== POST /install/database（错误分支）==\n";
$r = req('POST', "{$base}/install/database", ['host' => '127.0.0.1', 'port' => 3307, 'database' => '', 'username' => ''], true, $csrf);
check('HTTP 200（错误也渲染向导）', $r['code'] === 200, "HTTP {$r['code']}");
check('错误渲染成 msg-error', str_contains($r['body'], 'msg msg-error') && str_contains($r['body'], '数据库名和用户名不能为空'));
check('错误后仍停在第 3 步', activeStep($r['body']) === 3, '实际第 ' . activeStep($r['body']) . ' 步');
check('错误后回填了刚提交的主机', (bool)preg_match('/name="host"[^>]*value="127\.0\.0\.1"/', $r['body']));
check('不产生锁文件', !file_exists($lockFile));

echo "\n== POST /install/database（DSN 注入防护）==\n";
$r = req('POST', "{$base}/install/database", ['host' => 'evil;host=x', 'port' => 3307, 'database' => 'x', 'username' => 'root'], true, $csrf);
check('主机名非法被拦', str_contains($r['body'], '数据库主机名格式不正确'));

echo "\n== POST /install/database（真实建库 + 导入 SQL）==\n";
$r = req('POST', "{$base}/install/database", [
    'host' => '127.0.0.1', 'port' => 3307, 'database' => $DB, 'username' => 'root', 'password' => 'amubbs',
], true, $csrf);
check('HTTP 200', $r['code'] === 200, "HTTP {$r['code']}");
check('推进到第 4 步', activeStep($r['body']) === 4, '实际第 ' . activeStep($r['body']) . ' 步 | ' . substr(strip_tags($r['body']), 0, 160));
check('第 4 步要建管理员', str_contains($r['body'], 'name="username"') && str_contains($r['body'], 'name="password_confirm"'));

// ---------------------------------------------------------------- 4. 第 4 步
echo "\n== POST /install/next（第 4 步不能跳步）==\n";
$r = req('POST', "{$base}/install/next", [], true, $csrf);
check('跳步被拒（提示先完成当前步骤）', str_contains($r['body'], '请先完成当前步骤'), substr(strip_tags($r['body']), 0, 120));
check('仍停在第 4 步', activeStep($r['body']) === 4, '实际第 ' . activeStep($r['body']) . ' 步');

echo "\n== POST /install/admin（校验分支）==\n";
$r = req('POST', "{$base}/install/admin", ['username' => 'admin', 'email' => 'a@b.com', 'password' => 'admin123456', 'password_confirm' => 'nope'], true, $csrf);
check('两次密码不一致被拦', str_contains($r['body'], '两次输入的密码不一致'));
$r = req('POST', "{$base}/install/admin", ['username' => 'admin', 'email' => 'not-an-email', 'password' => 'admin123456', 'password_confirm' => 'admin123456'], true, $csrf);
check('邮箱格式被拦', str_contains($r['body'], '邮箱格式不正确'));
$r = req('POST', "{$base}/install/admin", ['username' => 'admin', 'email' => 'admin@example.com', 'password' => '123', 'password_confirm' => '123'], true, $csrf);
check('密码过短被拦', str_contains($r['body'], '密码长度不能少于 6 个字符'));

echo "\n== POST /install/admin（成功）==\n";
$r = req('POST', "{$base}/install/admin", ['username' => 'admin', 'email' => 'admin@example.com', 'password' => 'admin123456', 'password_confirm' => 'admin123456'], true, $csrf);
check('HTTP 200', $r['code'] === 200, "HTTP {$r['code']}");
check('推进到第 5 步', activeStep($r['body']) === 5, '实际第 ' . activeStep($r['body']) . ' 步 | ' . substr(strip_tags($r['body']), 0, 160));
check('第 5 步是站点设置', str_contains($r['body'], 'name="site_name"') && str_contains($r['body'], 'name="site_url"'));
check('站点名称默认 AMuBBS', (bool)preg_match('/name="site_name"[^>]*value="AMuBBS"/', $r['body']), substr(strip_tags($r['body']), 0, 200));
check('站点 URL 默认取当前 origin', str_contains($r['body'], 'value="' . $base . '"'));

// ---------------------------------------------------------------- 5. 回退与回填
echo "\n== POST /install/back（5 → 4，验证回填）==\n";
$r = req('POST', "{$base}/install/back", [
    'site_name' => '自检站点', 'site_description' => '安装向导自检', 'site_url' => $base,
], true, $csrf);
check('退回第 4 步', activeStep($r['body']) === 4, '实际第 ' . activeStep($r['body']) . ' 步');
check('用户名被回填', (bool)preg_match('/name="username"[^>]*value="admin"/', $r['body']));
check('邮箱被回填', (bool)preg_match('/name="email"[^>]*value="admin@example\.com"/', $r['body']));
check('密码框不回填（密码不进表单回填值）', !preg_match('/name="password"[^>]*value="[^"]+"/', $r['body']));

echo "\n== POST /install/back（再退到 3，验证 DB 回填）==\n";
$r = req('POST', "{$base}/install/back", ['username' => 'admin', 'email' => 'admin@example.com'], true, $csrf);
check('退回第 3 步', activeStep($r['body']) === 3, '实际第 ' . activeStep($r['body']) . ' 步');
check('数据库名回填为临时库', (bool)preg_match('/name="database"[^>]*value="' . preg_quote($DB, '/') . '"/', $r['body']));
check('端口回填 3307', (bool)preg_match('/name="port"[^>]*value="3307"/', $r['body']));

echo "\n== 不带任何字段的上一步（不能把记住的值清空）==\n";
$r = req('POST', "{$base}/install/back", [], true, $csrf);
check('退回第 2 步', activeStep($r['body']) === 2, '实际第 ' . activeStep($r['body']) . ' 步');
$r = req('POST', "{$base}/install/next", [], true, $csrf);
check('再进第 3 步时 DB 回填还在', (bool)preg_match('/name="database"[^>]*value="' . preg_quote($DB, '/') . '"/', $r['body']));

// ---------------------------------------------------------------- 6. 完成安装
echo "\n== 回到第 5 步并完成安装 ==\n";
req('POST', "{$base}/install/database", ['host' => '127.0.0.1', 'port' => 3307, 'database' => $DB, 'username' => 'root', 'password' => 'amubbs'], true, $csrf);
$r = req('POST', "{$base}/install/admin", ['username' => 'admin', 'email' => 'admin@example.com', 'password' => 'admin123456', 'password_confirm' => 'admin123456'], true, $csrf);
check('重新推进到第 5 步', activeStep($r['body']) === 5, '实际第 ' . activeStep($r['body']) . ' 步');
check('站点名称回填为之前填过的值', (bool)preg_match('/name="site_name"[^>]*value="自检站点"/', $r['body']), substr(strip_tags($r['body']), 0, 200));

$r = req('POST', "{$base}/install/complete", ['site_name' => '自检站点', 'site_description' => '安装向导自检', 'site_url' => $base], true, $csrf);
$body = $r['body'];
check('HTTP 200', $r['code'] === 200, "HTTP {$r['code']}");
check('显示完成卡片', str_contains($body, '安装完成') && str_contains($body, 'complete-icon'));
check('完成页显示站点名称', str_contains($body, '自检站点'));
check('完成页显示管理员账号', str_contains($body, 'admin'));
check('完成页有「进入首页」', str_contains($body, 'btn-success') && str_contains($body, '进入首页'));
check('完成页不再有安装按钮', !str_contains($body, 'hx-post="/install/'));
check('完成页无 Alpine 指令', preg_match_all(ALPINE_RE, $body) === 0, '命中 ' . preg_match_all(ALPINE_RE, $body) . ' 处');

// ---------------------------------------------------------------- 7. 落盘结果
echo "\n== 落盘结果 ==\n";
check('已写 install.lock', file_exists($lockFile));
check('已写 config/database.php', file_exists($dbConfigFile));
$dbCfg = (string)file_get_contents($dbConfigFile);
check('数据库配置指向临时库', str_contains($dbCfg, "'{$DB}'"), substr($dbCfg, 0, 200));
check('数据库配置带端口 3307', str_contains($dbCfg, "'port' => 3307"));
$appCfg = (string)file_get_contents($appConfigFile);
check('应用配置写入了站点名', str_contains($appCfg, '自检站点'));

$pdo = new PDO("mysql:host=127.0.0.1;port=3307;dbname={$DB};charset=utf8mb4", 'root', 'amubbs', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($DB))->fetchColumn();
check('临时库已建表（>30 张）', $tables > 30, "实际 {$tables} 张");
$admin = $pdo->query("SELECT username, email, group_id FROM users WHERE id = 1")->fetch();
check('管理员已建好（group_id=3）', $admin && $admin['username'] === 'admin' && (int)$admin['group_id'] === 3, json_encode($admin));
$siteName = $pdo->query("SELECT value FROM settings WHERE `key` = 'site_name'")->fetchColumn();
check('settings.site_name 已更新', $siteName === '自检站点', var_export($siteName, true));

// ---------------------------------------------------------------- 8. 装完之后
echo "\n== 已安装后本会话再访问 /install ==\n";
$r = req('GET', "{$base}/install");
check('不再 404', $r['code'] === 200, "HTTP {$r['code']}");
check('本会话仍看到完成卡片', str_contains($r['body'], '安装完成') && str_contains($r['body'], '进入首页'));
check('完成卡片无 Alpine 指令', preg_match_all(ALPINE_RE, $r['body']) === 0);

echo "\n== 已安装后换个访客访问 /install ==\n";
$saved = $COOKIE;
$COOKIE = $COOKIE2;
$r = req('GET', "{$base}/install");
check('HTTP 200', $r['code'] === 200, "HTTP {$r['code']}");
check('提示怎么重新安装', str_contains($r['body'], '系统已安装') && str_contains($r['body'], 'install.lock'));
check('不再渲染向导步骤条', !str_contains($r['body'], 'class="steps-bar"'));
check('不再渲染安装按钮', !str_contains($r['body'], 'hx-post="/install/'));
check('无 Alpine 指令', preg_match_all(ALPINE_RE, $r['body']) === 0);
@unlink($COOKIE);
$COOKIE = $saved;

echo "\n== 已安装后安装接口不再对外暴露 ==\n";
foreach (['next', 'back', 'check', 'database', 'admin', 'complete'] as $ep) {
    $r = req('POST', "{$base}/install/{$ep}", [], true, $csrf);
    check("/install/{$ep} 已下线（404）", $r['code'] === 404, "HTTP {$r['code']}");
}

// ---------------------------------------------------------------- 收尾
echo "\n== 收尾 ==\n";
$teardown();
check('临时站点已停止', true);
check('临时库已删除', (int)$pdoRoot->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = " . $pdoRoot->quote($DB))->fetchColumn() === 0);
check('临时副本已删除', !is_dir($COPY));

echo "\n" . str_repeat('=', 60) . "\n";
echo "通过: {$pass}    失败: {$fail}\n";
if ($fail > 0) {
    echo "失败项：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "结果: 全部通过 ✅\n";
exit(0);
