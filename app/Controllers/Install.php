<?php
/**
 * 安装引导控制器
 */

namespace App\Controllers;

class Install extends Base
{
    /**
     * 验证安装流程的 CSRF Token（通过 X-CSRF-TOKEN header）
     */
    private function verifyCsrf(): bool
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
            $this->error('CSRF 验证失败，请刷新页面重试');
            return false;
        }
        return true;
    }

    /**
     * 安装页面
     */
    public function index(): void
    {
        // 已安装则提示
        if (file_exists(APP_PATH . 'install/install.lock')) {
            echo '系统已安装。如需重新安装，请删除 install/install.lock 文件。';
            return;
        }

        $this->render('install');
    }

    /**
     * 环境检测
     */
    public function check(): void
    {
        // 已安装则禁止访问
        if (file_exists(APP_PATH . 'install/install.lock')) {
            $this->error('系统已安装');
            return;
        }

        $results = [];

        // PHP 版本
        $results[] = [
            'name' => 'PHP 版本 >= 8.1',
            'value' => PHP_VERSION,
            'pass' => version_compare(PHP_VERSION, '8.1.0', '>='),
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

        // mbstring 扩展
        $results[] = [
            'name' => 'mbstring 扩展',
            'value' => extension_loaded('mbstring') ? '已安装' : '未安装',
            'pass' => extension_loaded('mbstring'),
        ];

        // JSON 扩展
        $results[] = [
            'name' => 'JSON 扩展',
            'value' => extension_loaded('json') ? '已安装' : '未安装',
            'pass' => extension_loaded('json'),
        ];

        // Redis 扩展（缓存系统依赖，非必须）
        $results[] = [
            'name' => 'Redis 扩展',
            'value' => extension_loaded('redis') ? '已安装' : '未安装（可选，缓存将降级为本地缓存）',
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

        $allPass = !in_array(false, array_column($results, 'pass'), true);

        $this->json([
            'success' => true,
            'data' => [
                'items' => $results,
                'pass' => $allPass,
            ],
        ]);
    }

    /**
     * 数据库配置与初始化
     */
    public function database(): void
    {
        // 二次检查安装锁
        if (file_exists(APP_PATH . 'install/install.lock')) {
            $this->error('系统已安装，禁止重复安装');
            return;
        }

        if (!$this->verifyCsrf()) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        $host = trim($input['host'] ?? '127.0.0.1');
        $port = (int) ($input['port'] ?? 3306);
        $database = trim($input['database'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($database) || empty($username)) {
            $this->error('数据库名和用户名不能为空');
            return;
        }

        // 验证 host 格式，防止 DSN 注入
        if (!preg_match('/^[\w.\-]+$/', $host)) {
            $this->error('数据库主机名格式不正确');
            return;
        }
        if ($port < 1 || $port > 65535) {
            $this->error('端口号不正确');
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
                $this->error('数据库初始化脚本不存在');
                return;
            }

            $sql = file_get_contents($sqlFile);
            $pdo->exec($sql);

            // 保存数据库配置到 session 供后续步骤使用
            $_SESSION['install_db'] = [
                'host' => $host,
                'port' => $port,
                'database' => $dbSafe,
                'username' => $username,
                'password' => $password,
            ];

            $this->success('数据库初始化成功');
        } catch (\PDOException $e) {
            error_log('[Install] 数据库连接失败: ' . $e->getMessage());
            $this->error('数据库连接失败，请检查配置信息');
            return;
        }
    }

    /**
     * 创建管理员账号
     */
    public function admin(): void
    {
        if (file_exists(APP_PATH . 'install/install.lock')) {
            $this->error('系统已安装，禁止重复安装');
            return;
        }

        if (!$this->verifyCsrf()) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            $this->error('所有字段都不能为空');
            return;
        }

        if (mb_strlen($username) < 2 || mb_strlen($username) > 32) {
            $this->error('用户名长度应在 2-32 个字符之间');
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('邮箱格式不正确');
            return;
        }

        if (strlen($password) < 6) {
            $this->error('密码长度不能少于 6 个字符');
            return;
        }

        $dbConfig = $_SESSION['install_db'] ?? null;
        if (!$dbConfig) {
            $this->error('请先完成数据库配置');
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

            $this->success('管理员账号创建成功');
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $this->error('用户名或邮箱已存在');
                return;
            }
            error_log('[Install] 创建管理员失败: ' . $e->getMessage());
            $this->error('创建管理员失败，请检查数据库配置');
            return;
        }
    }

    /**
     * 完成安装：写入配置文件和锁文件
     */
    public function complete(): void
    {
        if (file_exists(APP_PATH . 'install/install.lock')) {
            $this->error('系统已安装，禁止重复安装');
            return;
        }

        if (!$this->verifyCsrf()) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        $siteName = trim($input['site_name'] ?? 'AMuBBS');
        $siteUrl = rtrim(trim($input['site_url'] ?? 'http://localhost'), '/');
        $siteDescription = trim($input['site_description'] ?? '');

        $dbConfig = $_SESSION['install_db'] ?? null;
        if (!$dbConfig) {
            $this->error('请先完成数据库配置');
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
            $this->error('写入数据库配置文件失败');
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
            $this->error('写入应用配置文件失败');
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
            $this->error('写入锁文件失败');
            return;
        }

        // 清除安装 session 数据
        unset($_SESSION['install_db'], $_SESSION['install_admin']);

        $this->success('安装完成');
    }
}
