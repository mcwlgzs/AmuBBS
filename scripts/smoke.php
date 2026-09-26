<?php
/**
 * AMuBBS 冒烟测试（永久脚本，非临时）
 *
 * 用途：每次改动后确认「无 Redis 环境下仍可运行」这一硬性要求，
 *       覆盖前台页面、后台全部页面、后台 API、以及页面缓存的 CSRF 隔离。
 *
 * 用法：
 *   php scripts/smoke.php
 *
 * 可用环境变量覆盖默认值：
 *   SMOKE_BASE_URL     默认 http://127.0.0.1:8000
 *   SMOKE_ADMIN_USER   默认 admin
 *   SMOKE_ADMIN_PASS   默认 admin123456
 *
 * 退出码：0 = 全部通过，1 = 有失败项。
 *
 * 说明：本脚本只用 curl 走真实 HTTP，不引入任何依赖。
 *       除「GET /notifications 会把当前页通知标记为已读」这一处服务端设计如此的行为外，
 *       不做任何数据库写入；破坏性的功能验证（发帖/回帖/收藏等）请另用一次性脚本。
 *
 * 前置条件：后台「验证码」必须处于关闭状态，否则登录过不了验证码，
 *           [3] 之后的检查会全部跳过。
 */

$BASE  = rtrim(getenv('SMOKE_BASE_URL') ?: 'http://127.0.0.1:8000', '/');
$ADMIN = getenv('SMOKE_ADMIN_USER') ?: 'admin';
$APASS = getenv('SMOKE_ADMIN_PASS') ?: 'admin123456';

/**
 * Alpine 指令的特征串（用于「这个视图/页面已经迁干净了」的断言）
 *
 * ⚠️ 不能把 :class / :disabled 写成不带 "=" 的裸串：页面里的 CSS
 *（例如 .tc-claim-all-btn:disabled）会被误判成指令。
 * x-cloak 也要求后面跟空格或 ">"，这样布局里 [x-cloak]{...} 那行兜底样式不算指令。
 */
define('ALPINE_RE', '/x-data|x-model|x-show|x-text|x-for|x-ref|x-init'
    . '|@click|@submit|@change|@keydown|@input'
    . '|:class="|:disabled="|:placeholder="|:type="|x-cloak[ >]|\$refs|\$dispatch/');

if (!extension_loaded('curl')) {
    fwrite(STDERR, "需要 curl 扩展\n");
    exit(1);
}

$COOKIE = sys_get_temp_dir() . '/amubbs_smoke_' . getmypid() . '.txt';
@unlink($COOKIE);
date_default_timezone_set('Asia/Shanghai');

$pass = 0;
$fail = 0;
$failures = [];

function ok(string $label): void
{
    global $pass;
    $pass++;
    echo "  \033[32mPASS\033[0m  {$label}\n";
}

function bad(string $label, string $detail = ''): void
{
    global $fail, $failures;
    $fail++;
    $failures[] = $label . ($detail !== '' ? " ({$detail})" : '');
    echo "  \033[31mFAIL\033[0m  {$label}" . ($detail !== '' ? "  --> {$detail}" : '') . "\n";
}

function check(string $label, bool $cond, string $detail = ''): void
{
    $cond ? ok($label) : bad($label, $detail);
}

function req(string $method, string $url, ?array $post = null, bool $follow = false, array $headers = []): array
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $e = curl_error($ch);
        curl_close($ch);
        return ['code' => 0, 'headers' => '', 'body' => '', 'error' => $e];
    }
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'headers' => substr($raw, 0, $hlen), 'body' => substr($raw, $hlen), 'error' => ''];
}

function csrfFrom(string $html): string
{
    foreach ([
        '/name="csrf-token"\s+content="([^"]+)"/',
        '/name="csrf-token" content="([^"]+)"/',
        '/name="_csrf_token" value="([^"]+)"/',
    ] as $re) {
        if (preg_match($re, $html, $m)) {
            return $m[1];
        }
    }
    return '';
}

/**
 * 取响应头的值（大小写不敏感）
 */
function headerValue(string $headers, string $name): string
{
    foreach (preg_split('/\r?\n/', $headers) as $line) {
        if (stripos($line, $name . ':') === 0) {
            return trim(substr($line, strlen($name) + 1));
        }
    }
    return '';
}

/**
 * 响应体是不是「异常页」
 *
 * DEBUG 模式下异常页返回的是 HTTP 200，只断言状态码会把 500 当成通过：
 * sitemap 就是因为 ORDER BY 了一个不存在的列（sort_order），
 * 冷缓存下整页抛异常，而检查只看了 200 才一直没被发现。
 */
function isErrorPage(string $body): bool
{
    foreach (['[Exception]', '[Error]', 'Fatal error', 'Uncaught ', 'SQLSTATE['] as $needle) {
        if (str_contains($body, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * 清空文件缓存（含频率限制计数）
 *
 * 为什么是「整个清空」而不是只删限流的 key：
 *   RateLimit 的缓存键是 ratelimit:{max}:{window}:{ip}，而文件驱动用 md5(键) 当文件名，
 *   "ratelimit:" 这个字符串根本不出现在文件内容里 —— 早期版本按内容搜索，
 *   实际一个文件都删不掉（静默失效）。
 *   本脚本要发 60+ 个请求，正好卡在默认的 rate_limit_global_max=60/60s 上，
 *   于是「连着跑两次」会偶发 429，看起来像业务坏了。
 * 清空后顺带让下面的页面缓存隔离检查从冷缓存开始，更干净。
 * 注意：只对文件驱动有效；本项目的目标部署环境正是无 Redis 的共享主机。
 */
function resetRateLimits(): void
{
    $dir = __DIR__ . '/../storage/cache';
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        // .gitkeep 要留着：该目录内容被 gitignore，但保留文件本身有用
        if ($f->isFile() && $f->getFilename() === '.gitkeep') {
            continue;
        }
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }

    // 自愈：跑完测试不该把仓库里的占位文件弄丢，缺了就补回来
    if (!is_file($dir . '/.gitkeep')) {
        @touch($dir . '/.gitkeep');
    }
}

echo "AMuBBS 冒烟测试 @ {$BASE}\n";
echo str_repeat('=', 56) . "\n";

resetRateLimits();

// ---------------------------------------------------------------- 前台
echo "\n[1] 前台页面\n";
foreach (['/', '/forum/1', '/login', '/register', '/forgot-password', '/search?q=test', '/navigation', '/user/levels', '/sitemap.xml'] as $p) {
    $r = req('GET', $BASE . $p);
    check("GET {$p}", $r['code'] === 200, 'http=' . $r['code']);
    check("GET {$p} 不是异常页", !isErrorPage($r['body']));
}

// sitemap 必须真的有内容：只查 200 会被「异常页也是 200」蒙过去
$sitemap = req('GET', $BASE . '/sitemap.xml');
check('sitemap 是合法 urlset', str_contains($sitemap['body'], '<urlset') && str_contains($sitemap['body'], '</urlset>'));
check('sitemap 收录了板块页', str_contains($sitemap['body'], '/forum/1'));
check('sitemap 收录了帖子页', str_contains($sitemap['body'], '/thread/1'));

// 未登录形态：登录弹窗只剩空壳，表单由 htmx 从 /login?modal=1 取回（与独立页面共用一份标记）
$guestHome = req('GET', $BASE . '/');
check('未登录首页渲染了登录弹窗空壳', str_contains($guestHome['body'], 'id="authModalBody"'));
check('未登录首页加载 auth.css（弹窗表单要用）', str_contains($guestHome['body'], '/assets/css/auth.css'));
check('登录入口用 data-auth-open', str_contains($guestHome['body'], 'data-auth-open="login"'));

// 验证码资源按站点开关加载（见 resources/views/layout/header.php）。
// 本脚本的前提就是「后台验证码关闭」，所以这里可以断言两个资源都不该出现：
// 关掉验证码的站点不该为每个页面多付 3.3KB CSS + 9.4KB 阻塞脚本。
// （反方向「开启验证码时必须加载」用一次性探针验证过：探针会临时打开设置再还原。）
check('验证码关闭时不加载 captcha.css', !str_contains($guestHome['body'], '/assets/css/captcha.css'));
check('验证码关闭时不加载 captcha.js（它是 head 里的阻塞脚本）',
    !str_contains($guestHome['body'], '/assets/js/captcha.js'));
check('首页核心资源仍在（main.css / htmx）',
    str_contains($guestHome['body'], '/assets/css/main.css')
    && str_contains($guestHome['body'], '/assets/vendor/htmx/htmx.min.js'));

$r = req('GET', $BASE . '/login?modal=1');
check('登录片段不带整页外壳',
    $r['code'] === 200 && !str_contains($r['body'], '<html') && str_contains($r['body'], 'hx-post="/login"'),
    'http=' . $r['code']);
$r = req('GET', $BASE . '/register?modal=1');
check('注册片段不带整页外壳',
    $r['code'] === 200 && !str_contains($r['body'], '<html') && str_contains($r['body'], 'hx-post="/register"'),
    'http=' . $r['code']);

// 首页：公告栏用 data-* + 原生 <details>，签到卡由服务端渲染 → 整页不该再有 Alpine
$homeAlpine = (int)preg_match_all(ALPINE_RE, $guestHome['body']);
check('首页整页无 Alpine 指令', $homeAlpine === 0, "命中 {$homeAlpine} 处");
check('公告容器用 data-ann-wrap', str_contains($guestHome['body'], 'data-ann-wrap'));
check('公告的防闪烁预隐藏脚本仍在', str_contains($guestHome['body'], "localStorage.getItem('ann_hidden'"));

// 动态页游客态：只有静态点赞数，没有发布表单
$r = req('GET', $BASE . '/moments');
check('GET /moments（游客）', $r['code'] === 200, 'http=' . $r['code']);
$momentsAlpine = (int)preg_match_all(ALPINE_RE, $r['body']);
check('动态页游客态无 Alpine 指令', $momentsAlpine === 0, "命中 {$momentsAlpine} 处");

// 板块页游客态：没有版主弹窗，也没有批量操作工具栏
$r = req('GET', $BASE . '/forum/1');
check('GET /forum/1（游客）', $r['code'] === 200, 'http=' . $r['code']);
$forumAlpine = (int)preg_match_all(ALPINE_RE, $r['body']);
check('板块页游客态无 Alpine 指令', $forumAlpine === 0, "命中 {$forumAlpine} 处");
check('游客看不到版主弹窗', !str_contains($r['body'], 'id="forumModPanel"'));
check('游客看不到批量工具栏', !str_contains($r['body'], 'data-check-toolbar'));

// 首页四格统计（主题/回帖/会员/今日）——在线功能移除后由「今日」补位
$home = req('GET', $BASE . '/');
$stats = preg_match_all('/site-info-stat (stat-[a-z]+)"/', $home['body'], $m);
check('首页统计格为 4 个', $stats === 4, "count={$stats}");
check('首页不再有已下线的 stat-online', !str_contains($home['body'], 'stat-online'));

// ---------------------------------------------------------------- 页面缓存 CSRF 隔离
echo "\n[2] 页面缓存：每个访客拿到自己的 CSRF token\n";
$jarA = sys_get_temp_dir() . '/amubbs_smoke_a.txt';
$jarB = sys_get_temp_dir() . '/amubbs_smoke_b.txt';
@unlink($jarA);
@unlink($jarB);
$fetchHome = static function (string $jar): string {
    $ch = curl_init($GLOBALS['BASE'] . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $pos = strpos($raw, "\r\n\r\n");
    return $pos === false ? $raw : substr($raw, $pos + 4);
};
$tA = csrfFrom($fetchHome($jarA));
$bodyB = $fetchHome($jarB);
$tB = csrfFrom($bodyB);
check('访客 A 能拿到 token', $tA !== '');
check('访客 B 能拿到 token', $tB !== '');
check('两个访客 token 不同（页面缓存未串号）', $tA !== $tB, 'A=' . substr($tA, 0, 10) . ' B=' . substr($tB, 0, 10));
@unlink($jarA);
@unlink($jarB);

// ---------------------------------------------------------------- 后台
echo "\n[3] 后台登录\n";
$r = req('GET', $BASE . '/login');
resetRateLimits();
req('POST', $BASE . '/login', [
    'username' => $ADMIN, 'password' => $APASS, '_csrf_token' => csrfFrom($r['body']),
], true);
$r = req('GET', $BASE . '/admin', [], true);
check('管理员可进入后台', $r['code'] === 200 && str_contains($r['body'], 'layuimini-menu-left'), 'http=' . $r['code']);

if (!str_contains($r['body'], 'layuimini-menu-left')) {
    echo "\n无法登录后台，后续后台检查跳过（请确认 SMOKE_ADMIN_USER/PASS 与站点已安装；\n";
    echo "若后台开启了登录验证码，请先关闭——自动化脚本无法过验证码）\n";
} else {
    $adminPages = [
        '/admin', '/admin/dashboard', '/admin/monitor', '/admin/cache', '/admin/system-info',
        '/admin/credit-logs', '/admin/logs', '/admin/ip-blacklist', '/admin/tag-categories',
        '/admin/messages', '/admin/friend-links', '/admin/sensitive-words', '/admin/attachments',
        '/admin/posts', '/admin/threads', '/admin/forums', '/admin/levels', '/admin/notifications',
        '/admin/user-groups', '/admin/announcements', '/admin/vip-settings', '/admin/navigation',
        '/admin/user-settings', '/admin/cluster', '/admin/settings', '/admin/users', '/admin/plugins',
    ];
    echo "\n[4] 后台页面（layuimini 外壳 + iframe 子页面）\n";
    resetRateLimits();   // 每段之间清一次：整轮请求数会超过默认的 60/60s 全局上限
    $badPages = [];
    foreach ($adminPages as $p) {
        $r = req('GET', $BASE . $p);

        if ($r['code'] !== 200) {
            $badPages[] = $p . '=' . $r['code'];
            continue;
        }

        // 两种页面形态的标记完全不同，必须分开断言：
        //   /admin      → 外壳（layuimini 的 layout.php）：菜单容器 + tab 容器
        //   其余        → iframe 里加载的完整子页面：layuimini-container + layui.js，
        //                 而且**不能**带外壳标记，否则说明又回到「外壳塞片段」了
        $isShell = ($p === '/admin');
        $ok = $isShell
            ? (str_contains($r['body'], 'layuimini-menu-left') && str_contains($r['body'], 'layuimini-tab'))
            : (str_contains($r['body'], '<!DOCTYPE')
               && str_contains($r['body'], 'layuimini-container')
               && str_contains($r['body'], 'vendor/layui/layui.js')
               && !str_contains($r['body'], 'layuimini-menu-left'));

        if (!$ok) {
            $badPages[] = $p . '=标记不符';
        } elseif (isErrorPage($r['body'])) {
            // 页面渲染出来了但内容是异常（页面里嵌了报错），也要算失败
            $badPages[] = $p . '=异常页';
        }
    }
    check('全部 ' . count($adminPages) . ' 个后台页面正常', empty($badPages), implode(',', $badPages));

    // 菜单接口：外壳启动时靠它渲染菜单，结构错了整个后台就没有导航
    $r = req('GET', $BASE . '/admin/menu.json');
    $menu = json_decode($r['body'], true);
    check('后台菜单接口 /admin/menu.json 结构正确',
        $r['code'] === 200 && is_array($menu)
        && !empty($menu['homeInfo']['href'])
        && !empty($menu['logoInfo']['title'])
        && is_array($menu['menuInfo']) && count($menu['menuInfo']) > 0
        && !empty($menu['menuInfo'][0]['child'][0]['href']),
        'http=' . $r['code']);

    // 插件管理页：只做只读断言。
    // smoke 可能在正式站上跑，启停/卸载插件会改掉站长真实的 plugins.json，所以不在这里点按钮。
    $r = req('GET', $BASE . '/admin/plugins');
    check('插件管理页列出自带示例插件',
        $r['code'] === 200 && str_contains($r['body'], '插件列表') && str_contains($r['body'], '示例插件'),
        'http=' . $r['code']);
    check('插件管理页的启停按钮走 layui 交互',
        str_contains($r['body'], 'admin-plugin-toggle')
        && str_contains($r['body'], '/admin/plugins/toggle')
        && !str_contains($r['body'], 'hx-post'));

    echo "\n[5] 后台 API（保留的 JSON 表格接口）\n";
    resetRateLimits();
    $apis = [
        '/admin/api/forums', '/admin/api/threads', '/admin/api/posts', '/admin/api/users',
        '/admin/api/attachments', '/admin/api/announcements', '/admin/api/sensitive-words',
        '/admin/api/tag-categories', '/admin/api/notifications', '/admin/api/messages',
        '/admin/api/ip-blacklist', '/admin/api/friend-links', '/admin/api/logs',
        '/admin/api/credit-logs', '/admin/api/levels', '/admin/api/user-groups',
    ];
    $badApi = [];
    foreach ($apis as $ep) {
        $r = req('GET', $BASE . $ep);
        $j = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !is_array($j) || !array_key_exists('code', $j) || !array_key_exists('count', $j)) {
            $badApi[] = $ep . '=' . $r['code'];
        }
    }
    check('全部 ' . count($apis) . ' 个后台 API 正常', empty($badApi), implode(',', $badApi));

    echo "\n[6] 后台子页面形态\n";
    resetRateLimits();
    // 后台改成 layuimini 的 iframe 多 tab 之后，子页面**始终**是完整文档，
    // 不再按 HX-Request 返回片段。这条断言就是防止有人把片段模式又加回来。
    $r = req('GET', $BASE . '/admin/users', [], false, ['HX-Request: true']);
    check('子页面始终是完整文档（不再按 HX-Request 返回片段）',
        $r['code'] === 200
        && str_contains($r['body'], '<!DOCTYPE')
        && str_contains($r['body'], 'layuimini-container')
        && !str_contains($r['body'], 'layuimini-menu-left'),
        'http=' . $r['code']);

    // 前台 htmx 迁移进度：已迁移的页面不再有 Alpine 指令，动作走 hx-post
    echo "\n[7] 前台 htmx（已迁移页面）\n";
    $r = req('GET', $BASE . '/notifications');
    check('GET /notifications', $r['code'] === 200, 'http=' . $r['code']);
    check('通知页加载 htmx', str_contains($r['body'], '/assets/vendor/htmx/htmx.min.js'));
    check('body 带 hx-headers（CSRF 走请求头）', (bool)preg_match('/<body[^>]*hx-headers=/', $r['body']));
    check('通知卡片容器存在', str_contains($r['body'], 'id="notification-card"'));

    // 发帖 / 编辑表单：迁移后是 htmx 表单（hx-post + hx-swap="none"）
    $r = req('GET', $BASE . '/thread/create?forum_id=1');
    check('GET /thread/create', $r['code'] === 200, 'http=' . $r['code']);
    check('发帖表单走 hx-post', str_contains($r['body'], 'hx-post="/thread/create"'));
    check('发帖表单 hx-swap="none"', str_contains($r['body'], 'hx-swap="none"'));

    $r = req('GET', $BASE . '/thread/edit/1');
    check('GET /thread/edit/1', $r['code'] === 200, 'http=' . $r['code']);
    check('编辑表单走 hx-post', str_contains($r['body'], 'hx-post="/thread/edit"'));

    // 迁移完成的视图不该再出现 Alpine 指令；加新页面时往这个列表里追加
    $alpineIn = static function (string $view): int {
        $file = __DIR__ . '/../resources/views/' . $view;
        if (!is_file($file)) {
            return -1;
        }
        return (int)preg_match_all(ALPINE_RE, (string)file_get_contents($file));
    };
    $migratedViews = [
        'notification.php', 'notification/_card.php', 'notification/_popup.php',
        'thread/create.php', 'thread/edit.php',
        'components/auth-modal.php', 'user/login.php', 'user/register.php',
        'user/forgot.php', 'user/_forgot_card.php',
        'components/notification-panel.php', 'components/user-dropdown.php',
        'layout/footer.php', 'layout/navbar.php',
        'user/profile.php', 'user/public_profile.php',
        'user/_profile_actions.php', 'user/_avatar_preview.php',
        'user/vip.php', 'user/_vip_quote.php', 'user/task_center.php',
        'message/conversation.php', 'message/_row.php',
        'index.php', 'index/_checkin.php',
        'moment/index.php', 'moment/_actions.php', 'moment/_comment.php',
        'components/uploaded-image.php',
        'thread/forum.php', 'thread/_mod_list.php',
        'thread/detail.php',
        'install.php', 'install/_wizard.php',
    ];
    $stillAlpine = [];
    foreach ($migratedViews as $view) {
        $n = $alpineIn($view);
        if ($n !== 0) { $stillAlpine[] = $view . '=' . ($n < 0 ? 'missing' : $n); }
    }
    check('已迁移的 ' . count($migratedViews) . ' 个视图均无 Alpine 指令', empty($stillAlpine), implode(',', $stillAlpine));

    // 全量兜底：固定列表能发现「文件被删/改名」，全量扫描能发现「新页面又写回了 Alpine」。
    // 到这里为止 Alpine 已经从整个前台退役（资源文件也删了），所以这应该是 0。
    $viewBase = realpath(__DIR__ . '/../resources/views');
    $viewAlpine = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($viewBase, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $vf) {
        if (!$vf->isFile() || $vf->getExtension() !== 'php') { continue; }
        $n = (int)preg_match_all(ALPINE_RE, (string)file_get_contents($vf->getPathname()));
        if ($n > 0) {
            $viewAlpine[] = str_replace('\\', '/', substr($vf->getPathname(), strlen($viewBase) + 1)) . '=' . $n;
        }
    }
    check('resources/views 下已无任何 Alpine 指令', empty($viewAlpine), implode(',', $viewAlpine));

    // 导航栏通知面板：点开时懒加载的片段
    $r = req('GET', $BASE . '/notifications/popup');
    check('GET /notifications/popup', $r['code'] === 200, 'http=' . $r['code']);
    check('面板片段带 hx-swap-oob 未读角标', str_contains($r['body'], 'hx-swap-oob="true"'));
    check('面板片段不含整页外壳', !str_contains($r['body'], '<html'));

    // 框架层不该再输出任何 Alpine 组件工厂
    $r = req('GET', $BASE . '/');
    check('框架层不再输出 Alpine 组件工厂',
        !str_contains($r['body'], 'notifPanel(') && !str_contains($r['body'], 'backToTop()')
        && !str_contains($r['body'], 'darkMode()'));

    // 个人中心 / 用户主页：整页不该再有 Alpine 指令
    //（只匹配指令本身；布局里那行 [x-cloak] 兜底 CSS 还没迁移的页面在用）
    $pageAlpine = static function (string $html): int {
        return (int)preg_match_all(ALPINE_RE, $html);
    };

    $r = req('GET', $BASE . '/profile');
    check('GET /profile', $r['code'] === 200, 'http=' . $r['code']);
    check('个人中心标签页走 data-tab',
        str_contains($r['body'], 'data-tabs="threads"') && str_contains($r['body'], 'data-tab-panel="settings"'));
    check('个人中心表单走 hx-post',
        str_contains($r['body'], 'hx-post="/user/profile"') && str_contains($r['body'], 'hx-post="/user/password"'));
    check('个人中心已无 Alpine 指令', $pageAlpine($r['body']) === 0, '命中 ' . $pageAlpine($r['body']) . ' 处');

    $r = req('GET', $BASE . '/user/1');
    check('GET /user/1（用户主页）', $r['code'] === 200, 'http=' . $r['code']);
    check('用户主页已无 Alpine 指令', $pageAlpine($r['body']) === 0, '命中 ' . $pageAlpine($r['body']) . ' 处');

    // VIP / 任务中心 / 私信
    $r = req('GET', $BASE . '/vip');
    check('GET /vip', $r['code'] === 200, 'http=' . $r['code']);
    check('VIP 页已无 Alpine 指令', $pageAlpine($r['body']) === 0, '命中 ' . $pageAlpine($r['body']) . ' 处');
    check('VIP 等级用原生 radio + 兄弟选择器高亮',
        str_contains($r['body'], 'vip-level-radio') && str_contains($r['body'], ':checked + .vip-card'));
    check('VIP 购买走 hx-post、报价走 hx-get',
        str_contains($r['body'], 'hx-post="/vip/purchase"') && str_contains($r['body'], 'hx-get="/vip/quote"'));

    // 报价由服务端算：白银 100 × 3 个月 = 300
    $r = req('GET', $BASE . '/vip/quote?level=1&months=3', [], false, ['HX-Request: true']);
    check('GET /vip/quote 返回片段', $r['code'] === 200 && str_contains($r['body'], 'id="vipQuote"') && !str_contains($r['body'], '<html'), 'http=' . $r['code']);
    check('报价算对（100×3=300）', str_contains($r['body'], '300'), substr($r['body'], 0, 120));

    $r = req('GET', $BASE . '/tasks');
    check('GET /tasks', $r['code'] === 200, 'http=' . $r['code']);
    check('任务中心已无 Alpine 指令', $pageAlpine($r['body']) === 0, '命中 ' . $pageAlpine($r['body']) . ' 处');

    $r = req('GET', $BASE . '/messages');
    check('GET /messages（私信列表）', $r['code'] === 200, 'http=' . $r['code']);
    check('私信列表已无 Alpine 指令', $pageAlpine($r['body']) === 0, '命中 ' . $pageAlpine($r['body']) . ' 处');

    // 首页登录形态：签到卡片由服务端渲染状态
    $r = req('GET', $BASE . '/');
    check('登录后首页有签到卡片', str_contains($r['body'], 'id="checkinCard"'));
    check('登录后首页也无 Alpine 指令', $pageAlpine($r['body']) === 0, '命中 ' . $pageAlpine($r['body']) . ' 处');

    // 动态页登录形态
    $r = req('GET', $BASE . '/moments');
    check('GET /moments（登录）', $r['code'] === 200, 'http=' . $r['code']);
    check('动态页已无 Alpine 指令', $pageAlpine($r['body']) === 0, '命中 ' . $pageAlpine($r['body']) . ' 处');
    check('动态发布表单走 hx-post', str_contains($r['body'], 'hx-post="/moments/create"'));
    check('动态点赞走 hx-post', str_contains($r['body'], 'hx-post="/moments/like"'));

    // 板块页登录形态（管理员）：版主弹窗 + 批量操作工具栏
    $r = req('GET', $BASE . '/forum/1');
    check('GET /forum/1（登录）', $r['code'] === 200, 'http=' . $r['code']);
    check('板块页已无 Alpine 指令', $pageAlpine($r['body']) === 0, '命中 ' . $pageAlpine($r['body']) . ' 处');
    check('有版主管理弹窗且默认隐藏',
        str_contains($r['body'], 'id="forumModPanel"') && str_contains($r['body'], 'data-overlay-close'));
    check('添加版主走 hx-post', str_contains($r['body'], 'hx-post="/forum/moderators"'));
    check('有批量操作工具栏', str_contains($r['body'], 'data-check-toolbar="[data-thread-check]"'));
    check('批量按钮走 hx-post 并收集选中项', str_contains($r['body'], 'hx-post="/mod/batch"')
        && str_contains($r['body'], 'hx-include="[data-thread-check]:checked"'));

    // 前台 CSS：括号必须配平（编辑 CSS 时最容易出的错），并且已经清掉的死规则不要被加回来
    foreach (['main', 'index', 'thread', 'auth', 'message', 'captcha'] as $cssName) {
        $cssFile = __DIR__ . '/../public/assets/css/' . $cssName . '.css';
        $css = (string)file_get_contents($cssFile);
        check("{$cssName}.css 括号配平", substr_count($css, '{') === substr_count($css, '}'),
            '{' . substr_count($css, '{') . ' }' . substr_count($css, '}'));
    }
    // 这些 class 当年要么是 Alpine 的过渡类，要么是被取代/从未落地的组件，已确认 0 引用后删除。
    // 只列「在前台 CSS 里已彻底消失」的那些（有些死类还留在组合选择器里，属于保守保留）。
    $deadCss = [
        'auth-enter', 'auth-leave', 'hamburger', 'show-mobile', 'show-mobile-only',
        'skeleton', 'skeleton-text', 'auth-pwd-toggle', 'auth-field-error', 'auth-msg',
        'auth-spinner', 'auth-modal-footer', 'flex-col', 'items-center', 'justify-between',
        'btn-lg', 'msg-success', 'animate-fadeIn', 'task-item', 'view-all-link',
        'profile-stats', 'msg-date-sep',
    ];
    $cssAll = '';
    foreach (['main', 'index', 'thread', 'auth', 'message', 'captcha'] as $cssName) {
        $cssAll .= (string)file_get_contents(__DIR__ . '/../public/assets/css/' . $cssName . '.css');
    }
    $reappeared = [];
    foreach ($deadCss as $cls) {
        if (preg_match('/\.' . preg_quote($cls, '/') . '(?![\w-])/', $cssAll)) { $reappeared[] = $cls; }
    }
    check('已清理的死 CSS 规则没有被加回来', empty($reappeared), implode(',', $reappeared));


    $r = req('GET', $BASE . '/thread/1');
    check('GET /thread/1（登录）', $r['code'] === 200, 'http=' . $r['code']);
    check('详情页已无 Alpine 指令', $pageAlpine($r['body']) === 0, '命中 ' . $pageAlpine($r['body']) . ' 处');
    check('楼层编辑表单默认隐藏且用 data-toggle 切换',
        (bool)preg_match('/id="post-\d+-edit" hidden/', $r['body'])
        && (bool)preg_match('/data-toggle-target="#post-\d+-edit"/', $r['body'])
        && (bool)preg_match('/data-toggle-alt="#post-\d+-view"/', $r['body']));
    check('楼层保存走 hx-post，删除带二次确认',
        str_contains($r['body'], 'hx-post="/post/edit"')
        && str_contains($r['body'], 'hx-post="/post/delete"')
        && str_contains($r['body'], 'hx-confirm="确定要删除这条评论吗？"'));
    check('回复框走 hx-post 且不换目标',
        (bool)preg_match('/id="threadReplyForm"[^>]*hx-post="\/thread\/reply"[^>]*hx-swap="none"/', $r['body']));
    check('回复框的引用/表情走通用 data-* 机制',
        str_contains($r['body'], 'data-reply-form="#threadReplyForm"')
        && str_contains($r['body'], 'data-reply-cancel')
        && str_contains($r['body'], 'data-emoji-picker='));
    check('旧的 Alpine 组件工厂全没了',
        !str_contains($r['body'], 'function replyForm()')
        && !str_contains($r['body'], 'function postActions(')
        && !str_contains($r['body'], 'function quoteReply('));

    // 详情页动作接口的失败分支：回 422 + HX-Reswap: none（页面停在原地、不写库）
    $hxPost = ['HX-Request: true', 'X-CSRF-TOKEN: ' . csrfFrom($r['body'])];
    $r = req('POST', $BASE . '/post/edit', ['post_id' => 9, 'content' => 'x'], false, $hxPost);
    check('/post/edit 内容过短回 422 且不换目标',
        $r['code'] === 422 && strtolower(headerValue($r['headers'], 'HX-Reswap')) === 'none',
        'http=' . $r['code'] . ' swap=' . headerValue($r['headers'], 'HX-Reswap'));

    $r = req('POST', $BASE . '/thread/reply', ['thread_id' => 999999, 'content' => '不存在的帖子'], false, $hxPost);
    check('/thread/reply 目标不存在回 422 且不换目标',
        $r['code'] === 422 && strtolower(headerValue($r['headers'], 'HX-Reswap')) === 'none',
        'http=' . $r['code'] . ' swap=' . headerValue($r['headers'], 'HX-Reswap'));

    // 安装向导：整站已经装好了，这里能验的是「已安装」分支 + 页面不再依赖 Alpine
    $r = req('GET', $BASE . '/install');
    check('GET /install（已安装）', $r['code'] === 200, 'http=' . $r['code']);
    check('/install 提示怎么重新安装', str_contains($r['body'], '系统已安装') && str_contains($r['body'], 'install.lock'));
    check('/install 不再渲染向导步骤条', !str_contains($r['body'], 'class="steps-bar"'));
    check('/install 已无 Alpine 指令', $pageAlpine($r['body']) === 0, '命中 ' . $pageAlpine($r['body']) . ' 处');
    check('/install 改用 htmx', str_contains($r['body'], '/assets/vendor/htmx/htmx.min.js'));
    foreach (['assets/js/alpine.min.js', 'assets/js/alpine-collapse.min.js'] as $gone) {
        check("Alpine 资产已删除 {$gone}", !is_file(__DIR__ . '/../public/' . $gone));
    }
    // 已被自研实现取代的死资源（emoji 面板现在由 app.js 的 initEmojiPickers 负责，
    // 代码高亮用 highlight.js，prism 从未被任何视图引用）
    foreach ([
        'assets/js/emoji-picker.js', 'assets/css/emoji-picker.css',
        'assets/prism/prism.js', 'assets/prism/prism.css',
    ] as $gone) {
        check("死资源已删除 {$gone}", !is_file(__DIR__ . '/../public/' . $gone));
    }

    // 已删除的死组件不要被无意中加回来
    foreach (['components/toast.php', 'components/confirm-modal.php', 'components/alert.php'] as $gone) {
        check("死组件 {$gone} 不存在", !is_file(__DIR__ . '/../resources/views/' . $gone));
    }

    // 技术栈边界：后台已经改用 layui（layuimini），但前台必须保持无 layui。
    // 这条断言守的是「layui 不要漏到前台」——后台页面不在这个检查范围内。
    $r = req('GET', $BASE . '/notifications');
    foreach (['layui', 'tinymce'] as $gone) {
        check("前台 HTML 不含 {$gone}", stripos($r['body'], $gone) === false);
    }
}

@unlink($COOKIE);

echo "\n" . str_repeat('=', 56) . "\n";
if ($fail === 0) {
    echo "全部通过：{$pass} 项\n";
    exit(0);
}
echo "通过 {$pass} 项，失败 {$fail} 项：\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
exit(1);
