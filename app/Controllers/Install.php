<?php
/**
 * 安装引导控制器
 */

namespace App\Controllers;

class Install extends Base
{
    /**
     * 向导步骤标题（下标 + 1 就是步骤号）
     */
    private const STEP_LABELS = ['安装说明', '环境检测', '数据库', '管理员', '站点设置', '完成'];

    /**
     * 验证安装流程的 CSRF Token（通过 X-CSRF-TOKEN header）
     *
     * 只判断不输出，怎么报错交给调用方（向导要把错误渲染进页面，JSON 接口要回 400）
     */
    private function verifyCsrf(): bool
    {
        $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($token === '') {
            return false;
        }
        // 匿名访客（没有 session cookie）的 token 走双提交 Cookie，不只在会话里
        return \App\Middlewares\Csrf::matchesAny(\App\Middlewares\Csrf::currentTokens(), $token);
    }

    /**
     * 已安装则拒绝
     *
     * 正常流程下 Bootstrap 在未安装时才把请求交给这里，所以这条只挡并发安装
     * （两个标签页同时装）：一个装完了，另一个的后续步骤应当立刻停手。
     */
    private function rejectIfInstalled(): bool
    {
        if (!file_exists(APP_PATH . 'install/install.lock')) {
            return false;
        }
        if ($this->isHtmx()) {
            $this->renderWizard();
        } else {
            // 用 404 而不是 400/403：装好的站点不该对外泄露「这里有个安装向导」
            $this->error('系统已安装，禁止重复安装', 404);
        }
        return true;
    }

    /**
     * 当前步骤
     *
     * 以 session 记录为准，同时用「真实进度」两头夹住，保证跳不了步：
     *   - 没配好数据库就不可能在「创建管理员」及之后
     *   - 没创建管理员就不可能在「站点设置」
     * 锁文件存在则一律算最后一步。
     */
    private function currentStep(): int
    {
        if (file_exists(APP_PATH . 'install/install.lock')) {
            return 6;
        }

        $step = (int)($_SESSION['install_step'] ?? 1);
        if ($step < 1 || $step > 6) {
            $step = 1;
        }
        if ($step >= 5 && empty($_SESSION['install_admin'])) {
            $step = 4;
        }
        if ($step >= 4 && empty($_SESSION['install_db'])) {
            $step = 3;
        }

        return $step;
    }

    private function gotoStep(int $step): void
    {
        $_SESSION['install_step'] = max(1, min(6, $step));
    }

    /**
     * 渲染安装向导
     *
     * htmx 请求只回 `#installWizard` 的内容（与全站「HX-Request 只回片段」的约定一致），
     * 普通请求回整页外壳。
     *
     * 注意这里刻意让失败也返回 200：htmx 默认不替换 4xx 响应，而向导的错误提示
     * 本身就是「重渲染后的向导」的一部分（错误条 + 保留已填内容），所以走的是
     * 「服务端渲染整块向导」这条路，而不是别处的 422 + HX-Reswap: none。
     */
    private function renderWizard(string $error = ''): void
    {
        $data = [
            'step'       => $this->currentStep(),
            'stepLabels' => self::STEP_LABELS,
            'installed'  => file_exists(APP_PATH . 'install/install.lock'),
            'done'       => $_SESSION['install_done'] ?? null,
            'error'      => $error,
            'checkResult' => $_SESSION['install_check'] ?? null,
            'form'       => $this->formValues(),
            'csrfToken'  => \App\Middlewares\Csrf::generateToken(),
        ];

        if ($this->isHtmx()) {
            $this->render('install/_wizard', $data);
            return;
        }

        $this->render('install', $data);
    }

    /**
     * 出错：htmx 渲染带错误条的向导，普通请求保持原来的 JSON 错误
     */
    private function fail(string $message): void
    {
        if ($this->isHtmx()) {
            $this->renderWizard($message);
            return;
        }
        $this->error($message);
    }

    /**
     * 表单回填值
     *
     * 步骤改成服务端渲染后，页面上不再有 Alpine 的本地状态，所以「上一步」再回来
     * 要能看见之前填的内容，得靠 session 记住用户提交过的值。
     */
    private function formValues(): array
    {
        $origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return [
            'db' => array_merge([
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'amubbs',
                'username' => 'root',
                'password' => '',
            ], $_SESSION['install_db_form'] ?? [], $_SESSION['install_db'] ?? []),
            'admin' => array_merge([
                'username' => '',
                'email' => '',
            ], $_SESSION['install_admin_form'] ?? []),
            'site' => array_merge([
                'name' => 'AMuBBS',
                'description' => '基于 PHP 的轻量化论坛系统',
                'url' => $origin,
            ], $_SESSION['install_site_form'] ?? []),
        ];
    }

    /**
     * 记住某一页表单填了什么（不校验，纯粹为了回填）
     *
     * 只覆盖这次真的提交上来的字段：按钮在 <form> 里，htmx 会把整份字段一起带上，
     * 但万一某个动作没带字段（例如直接请求 /install/back），也不能把已经记住的值清空。
     */
    private function captureInput(int $step): void
    {
        $in = $this->input();
        if (!$in) {
            return;
        }

        $fields = [
            3 => ['install_db_form', ['host', 'port', 'database', 'username', 'password']],
            4 => ['install_admin_form', ['username', 'email']],
            5 => ['install_site_form', ['name' => 'site_name', 'description' => 'site_description', 'url' => 'site_url']],
        ][$step] ?? null;

        if ($fields === null) {
            return;
        }

        [$key, $map] = $fields;
        $stored = $_SESSION[$key] ?? [];

        foreach ($map as $store => $field) {
            // ['host','port',...] 这种写法里 store 是下标、field 是字段名
            if (is_int($store)) {
                $store = $field;
            }
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $value = trim((string) $in[$field]);
            $stored[$store] = ($field === 'port') ? (int) $value : $value;
        }

        $_SESSION[$key] = $stored;
    }

    /**
     * 环境检测（`/install/check` 与「下一步」共用，保证推进前是刚查过的结果）
     */
    private function runEnvironmentCheck(): array
    {
        $results = [];

        // PHP 版本
        $results[] = [
            'name' => 'PHP 版本 >= 8.0',
            'value' => PHP_VERSION,
            'pass' => version_compare(PHP_VERSION, '8.0.0', '>='),
        ];

        // PDO 扩展
        $results[] = [
            'name' => 'PDO 扩展',
            'value' => extension_loaded('pdo') ? '已安装' : '未安装',
            'pass' => extension_loaded('pdo'),
        ];

        // PDO MySQL 驱动
        $results[] = [
            'name' => 'PDO MySQL 驱动',
            'value' => extension_loaded('pdo_mysql') ? '已安装' : '未安装',
            'pass' => extension_loaded('pdo_mysql'),
        ];

        // mbstring
        $results[] = [
            'name' => 'mbstring 扩展',
            'value' => extension_loaded('mbstring') ? '已安装' : '未安装',
            'pass' => extension_loaded('mbstring'),
        ];

        // JSON
        $results[] = [
            'name' => 'JSON 扩展',
            'value' => extension_loaded('json') ? '已安装' : '未安装',
            'pass' => extension_loaded('json'),
        ];

        // Redis 扩展（可选）：没有时缓存自动使用文件缓存，功能不降级
        $results[] = [
            'name' => 'Redis 扩展',
            'value' => extension_loaded('redis') ? '已安装' : '未安装（可选，将自动使用文件缓存）',
            'pass' => true,
            'optional' => true,
        ];

        // OPcache 扩展（性能优化，非必须）
        $opcacheLoaded = extension_loaded('Zend OPcache');
        $results[] = [
            'name' => 'OPcache 扩展',
            'value' => $opcacheLoaded ? '已安装' : '未安装（可选，建议开启以提升性能）',
            'pass' => true,
            'optional' => true,
        ];

        // 目录可写性检测
        $writableDirs = [
            'config/' => APP_PATH . 'config/',
            'storage/logs/' => APP_PATH . 'storage/logs/',
            'storage/cache/' => APP_PATH . 'storage/cache/',
            'storage/sessions/' => APP_PATH . 'storage/sessions/',
            'install/' => APP_PATH . 'install/',
        ];

        foreach ($writableDirs as $label => $dir) {
            $writable = is_dir($dir) && is_writable($dir);
            $results[] = [
                'name' => "{$label} 可写",
                'value' => $writable ? '可写' : '不可写',
                'pass' => $writable,
            ];
        }

        return [
            'items' => $results,
            'pass' => !in_array(false, array_column($results, 'pass'), true),
        ];
    }

    /**
     * 安装页面
     */
    public function index(): void
    {
        // 已安装时 renderWizard() 会走「已安装提示」分支（提示删除 install/install.lock 才能重装），
        // 这是刻意保留的给站长的提示页，不是安装向导外壳 —— scripts/smoke.php 有对应断言。
        $this->renderWizard();
    }

    /**
     * 向导「下一步」：只负责导航性的推进
     *
     * 第 3/4/5 步必须各自走自己的提交接口（要真的连库、建管理员、写配置），
     * 这里不接受跳步，避免用户绕过实际操作直接进到「站点设置」。
     */
    public function next(): void
    {
        if ($this->rejectIfInstalled()) {
            return;
        }

        // 向导的每一步都写 $_SESSION（captureInput/gotoStep），统一要求 CSRF，
        // 不能只保护真正写库的那几个接口 —— 否则跨站请求可以打乱安装流程/污染回填数据。
        if (!$this->verifyCsrf()) {
            $this->fail('CSRF 验证失败，请刷新页面重试');
            return;
        }

        $step = $this->currentStep();
        $this->captureInput($step);

        if ($step === 1) {
            $this->gotoStep(2);
        } elseif ($step === 2) {
            // 不信任上一次的检测结果：推进前重跑一遍，环境可能已经变了
            $result = $this->runEnvironmentCheck();
            $_SESSION['install_check'] = $result;
            if (!$result['pass']) {
                $this->fail('环境检测未通过，请解决上述问题后重新检测。');
                return;
            }
            $this->gotoStep(3);
        } else {
            $this->fail('请先完成当前步骤');
            return;
        }

        $this->renderWizard();
    }

    /**
     * 向导「上一步」
     */
    public function back(): void
    {
        if ($this->rejectIfInstalled()) {
            return;
        }

        if (!$this->verifyCsrf()) {
            $this->fail('CSRF 验证失败，请刷新页面重试');
            return;
        }

        $step = $this->currentStep();
        $this->captureInput($step);
        $this->gotoStep($step - 1);
        $this->renderWizard();
    }

    /**
     * 环境检测
     */
    public function check(): void
    {
        // 已安装则禁止访问
        if ($this->rejectIfInstalled()) {
            return;
        }

        // 只读检测也走 CSRF：它会往 $_SESSION 里写 install_check，
        // 被跨站触发等于让受害者页面显示一份伪造的检测结果。
        if (!$this->verifyCsrf()) {
            $this->fail('CSRF 验证失败，请刷新页面重试');
            return;
        }

        $result = $this->runEnvironmentCheck();

        if ($this->isHtmx()) {
            $_SESSION['install_check'] = $result;
            $this->renderWizard();
            return;
        }

        $this->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * 数据库配置与初始化
     */
    public function database(): void
    {
        // 二次检查安装锁
        if ($this->rejectIfInstalled()) {
            return;
        }

        $input = $this->input();
        $this->captureInput(3);

        if (!$this->verifyCsrf()) {
            $this->fail('CSRF 验证失败，请刷新页面重试');
            return;
        }

        $host = trim((string) ($input['host'] ?? '127.0.0.1'));
        $port = (int) ($input['port'] ?? 3306);
        $database = trim((string) ($input['database'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if (empty($database) || empty($username)) {
            $this->fail('数据库名和用户名不能为空');
            return;
        }

        // 验证 host 格式，防止 DSN 注入
        if (!preg_match('/^[\w.\-]+$/', $host)) {
            $this->fail('数据库主机名格式不正确');
            return;
        }
        if ($port < 1 || $port > 65535) {
            $this->fail('端口号不正确');
            return;
        }

        try {
            // 先连接 MySQL（不指定数据库）
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);

            // 创建数据库（如果不存在）
            $dbSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $database);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbSafe}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbSafe}`");

            // 导入 SQL
            $sqlFile = APP_PATH . 'install/database.sql';
            if (!file_exists($sqlFile)) {
                $this->fail('数据库初始化脚本不存在');
                return;
            }

            $sql = file_get_contents($sqlFile);
            $pdo->exec($sql);

            // 可选步骤：尝试补充全文索引。
            // 主库脚本刻意不含 FULLTEXT（老版本 MySQL 会导致建表失败），这里在支持的环境上补上；
            // 失败也不影响安装，搜索会自动回退 LIKE。
            $fulltextFile = APP_PATH . 'install/optional_fulltext.sql';
            if (file_exists($fulltextFile)) {
                $fulltextSql = '';
                foreach (file($fulltextFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_starts_with(trim($line), '--')) {
                        continue;
                    }
                    $fulltextSql .= $line . "\n";
                }

                foreach (array_filter(array_map('trim', explode(';', $fulltextSql))) as $stmt) {
                    try {
                        $pdo->exec($stmt);
                    } catch (\PDOException $e) {
                        error_log('[Install] 全文索引创建失败（已忽略，搜索将回退 LIKE）: ' . $e->getMessage());
                    }
                }
            }

            // 保存数据库配置到 session 供后续步骤使用
            $_SESSION['install_db'] = [
                'host' => $host,
                'port' => $port,
                'database' => $dbSafe,
                'username' => $username,
                'password' => $password,
            ];

            if ($this->isHtmx()) {
                $this->gotoStep(4);
                $this->renderWizard();
                return;
            }

            $this->success('数据库初始化成功');
        } catch (\PDOException $e) {
            error_log('[Install] 数据库连接失败: ' . $e->getMessage());
            $this->fail('数据库连接失败，请检查配置信息');
        }
    }

    /**
     * 创建管理员账号
     */
    public function admin(): void
    {
        if ($this->rejectIfInstalled()) {
            return;
        }

        $input = $this->input();
        $this->captureInput(4);

        if (!$this->verifyCsrf()) {
            $this->fail('CSRF 验证失败，请刷新页面重试');
            return;
        }

        $username = trim((string) ($input['username'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if (empty($username) || empty($email) || empty($password)) {
            $this->fail('所有字段都不能为空');
            return;
        }

        if (mb_strlen($username) < 2 || mb_strlen($username) > 32) {
            $this->fail('用户名长度应在 2-32 个字符之间');
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('邮箱格式不正确');
            return;
        }

        if (strlen($password) < 6) {
            $this->fail('密码长度不能少于 6 个字符');
            return;
        }

        // 确认密码：只有调用方传了才校验，保持旧 JSON 接口的兼容性
        if (isset($input['password_confirm']) && $password !== (string) $input['password_confirm']) {
            $this->fail('两次输入的密码不一致');
            return;
        }

        $dbConfig = $_SESSION['install_db'] ?? null;
        if (!$dbConfig) {
            $this->fail('请先完成数据库配置');
            return;
        }

        try {
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4";
            $pdo = new \PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $now = time();
            $hash = password_hash($password, PASSWORD_BCRYPT);

            // 检查是否已有管理员（id=1），有则更新，无则插入
            $existing = $pdo->query("SELECT id FROM `users` WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                $stmt = $pdo->prepare(
                    "UPDATE `users` SET `username` = :username, `email` = :email, `password` = :password,
                     `group_id` = 3, `updated_at` = :updated_at WHERE id = 1"
                );
                $stmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':password' => $hash,
                    ':updated_at' => $now,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO `users` (`id`, `username`, `email`, `password`, `group_id`, `credits`, `created_at`, `updated_at`)
                     VALUES (1, :username, :email, :password, 3, 0, :created_at, :updated_at)"
                );
                $stmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':password' => $hash,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }

            $_SESSION['install_admin'] = $username;

            if ($this->isHtmx()) {
                $this->gotoStep(5);
                $this->renderWizard();
                return;
            }

            $this->success('管理员账号创建成功');
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $this->fail('用户名或邮箱已存在');
                return;
            }
            error_log('[Install] 创建管理员失败: ' . $e->getMessage());
            $this->fail('创建管理员失败，请检查数据库配置');
        }
    }

    /**
     * 完成安装：写入配置文件和锁文件
     */
    public function complete(): void
    {
        if ($this->rejectIfInstalled()) {
            return;
        }

        $input = $this->input();
        $this->captureInput(5);

        if (!$this->verifyCsrf()) {
            $this->fail('CSRF 验证失败，请刷新页面重试');
            return;
        }

        // 站点地址默认取当前访问的 origin，与迁移前 window.location.origin 的行为一致
        $defaultUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $siteName = trim((string) ($input['site_name'] ?? 'AMuBBS'));
        $siteUrl = rtrim(trim((string) ($input['site_url'] ?? $defaultUrl)), '/');
        $siteDescription = trim((string) ($input['site_description'] ?? ''));

        $dbConfig = $_SESSION['install_db'] ?? null;
        if (!$dbConfig) {
            $this->fail('请先完成数据库配置');
            return;
        }

        // 写入数据库配置（使用 var_export 安全转义）
        $dbHost = var_export($dbConfig['host'], true);
        $dbPort = (int)$dbConfig['port'];
        $dbName = var_export($dbConfig['database'], true);
        $dbUser = var_export($dbConfig['username'], true);
        $dbPass = var_export($dbConfig['password'], true);

        $dbConfigContent = <<<PHP
<?php
/**
 * 数据库配置
 */

return [
    // 默认连接
    'default' => 'mysql',

    // 数据库连接配置
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => {$dbHost},
            'port' => {$dbPort},
            'database' => {$dbName},
            'username' => {$dbUser},
            'password' => {$dbPass},
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ],
    ],
];
PHP;

        if (!file_put_contents(APP_PATH . 'config/database.php', $dbConfigContent)) {
            $this->fail('写入数据库配置文件失败');
            return;
        }

        // 写入应用配置
        $siteNameExport = var_export($siteName, true);
        $siteUrlExport = var_export($siteUrl, true);

        $appConfigContent = <<<PHP
<?php
/**
 * 应用配置
 */

use function Core\\env;

return [
    // 应用名称
    'name' => {$siteNameExport},

    // 应用版本
    'version' => '1.0.0',

    // 时区
    'timezone' => 'Asia/Shanghai',

    // 字符集
    'charset' => 'UTF-8',

    // 调试模式
    'debug' => (bool) env('APP_DEBUG', false),

    // URL 配置
    'url' => env('APP_URL', {$siteUrlExport}),

    // 路径配置
    'paths' => [
        'log' => APP_PATH . 'storage/logs/',
        'cache' => APP_PATH . 'storage/cache/',
        'upload' => APP_PATH . 'public/uploads/',
    ],
];
PHP;

        if (!file_put_contents(APP_PATH . 'config/app.php', $appConfigContent)) {
            $this->fail('写入应用配置文件失败');
            return;
        }

        // 更新 settings 表
        try {
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4";
            $pdo = new \PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $now = time();
            $stmt = $pdo->prepare("UPDATE `settings` SET `value` = :val, `updated_at` = :time WHERE `key` = :key");
            $stmt->execute([':val' => $siteName, ':time' => $now, ':key' => 'site_name']);
            $stmt->execute([':val' => $siteUrl, ':time' => $now, ':key' => 'site_url']);
            $stmt->execute([':val' => $siteDescription, ':time' => $now, ':key' => 'site_description']);
        } catch (\PDOException $e) {
            // 非致命错误，配置文件已写入
        }

        // 写入锁文件
        $lockContent = json_encode([
            'installed_at' => date('Y-m-d H:i:s'),
            'version' => '1.0.0',
        ], JSON_UNESCAPED_UNICODE);

        if (!file_put_contents(APP_PATH . 'install/install.lock', $lockContent)) {
            $this->fail('写入锁文件失败');
            return;
        }

        // 完成页要显示站点信息，所以先把它们挪到 install_done，
        // 再清掉安装 session 数据（数据库密码不该继续留在 session 里）
        $_SESSION['install_done'] = [
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'admin' => $_SESSION['install_admin'] ?? '',
        ];
        unset($_SESSION['install_db'], $_SESSION['install_admin'], $_SESSION['install_step']);

        if ($this->isHtmx()) {
            $this->renderWizard();
            return;
        }

        $this->success('安装完成');
    }
}
