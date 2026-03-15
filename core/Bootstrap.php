<?php
/**
 * 核心启动类
 */

namespace Core;

class Bootstrap
{
    private static ?self $instance = null;
    private array $config = [];
    private Router $router;
    private Container $container;
    private EventDispatcher $eventDispatcher;
    private ?PluginManager $pluginManager = null;

    private function __construct()
    {
        // 注册自动加载
        $this->registerAutoloader();

        // 设置错误报告
        error_reporting(DEBUG ? E_ALL : 0);
        ini_set('display_errors', DEBUG ? '1' : '0');

        // 设置时区
        date_default_timezone_set('Asia/Shanghai');

        // 加载配置
        $this->loadConfig();

        // 初始化容器和事件系统
        $this->container = new Container();
        $this->eventDispatcher = new EventDispatcher();

        // 初始化核心组件（静态类，无需实例化）
        $this->initSecurity();
        $this->initLang();
        $this->initRouter();

        // 初始化插件系统
        $this->initPlugins();

        // 注册错误处理
        $this->registerErrorHandler();
    }

    /**
     * 注册自动加载器
     */
    private function registerAutoloader(): void
    {
        spl_autoload_register(function (string $class) {
            // 命名空间映射：Core\ -> core/, App\ -> app/
            $map = [
                'Core\\' => APP_PATH . 'core/',
                'App\\Controllers\\' => APP_PATH . 'app/Controllers/',
                'App\\Services\\' => APP_PATH . 'app/Services/',
                'App\\Repositories\\' => APP_PATH . 'app/Repositories/',
                'App\\Middlewares\\' => APP_PATH . 'app/Middlewares/',
                'App\\Events\\' => APP_PATH . 'app/Events/',
                'App\\Listeners\\' => APP_PATH . 'app/Listeners/',
                'App\\Models\\' => APP_PATH . 'app/Models/',
                'Plugins\\' => APP_PATH . 'plugins/',
            ];

            foreach ($map as $prefix => $dir) {
                if (str_starts_with($class, $prefix)) {
                    $relativeClass = substr($class, strlen($prefix));
                    $file = $dir . str_replace('\\', '/', $relativeClass) . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                        return;
                    }
                }
            }
        });
    }

    /**
     * 获取单例实例
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 运行应用
     */
    public function run(): void
    {
        // 启动 Session
        $this->startSession();

        // Remember Me：自动恢复 session
        $this->restoreRememberMe();

        // 安装检测：用 OPcache 缓存结果，避免每请求磁盘 I/O
        if (!self::isInstalled()) {
            $this->handleInstall();
            return;
        }

        // IP 黑名单检查
        try {
            // 批量预热：一次 MGET 拉取 IP 黑名单 + 页面缓存 + 设置，减少 Redis 往返
            $pageCacheKey = PageCache::getCacheKey();
            $preloadKeys = ['ip_blacklist:map', 'settings:all'];
            if ($pageCacheKey !== '') {
                $preloadKeys[] = $pageCacheKey;
            }
            Cache::preload($preloadKeys);

            if (\App\Services\IpBlacklistService::isBlocked($_SERVER['REMOTE_ADDR'] ?? '')) {
                http_response_code(403);
                echo '您的 IP 已被封禁';
                return;
            }
        } catch (\Throwable $e) {
            // 表不存在时跳过检查，不影响正常访问
            error_log('[IpBlacklist] ' . $e->getMessage());
        }

        // 页面级缓存：匿名用户直接返回缓存 HTML
        if (PageCache::tryServe()) {
            return;
        }

        // 已安装，正常路由分发
        $this->deferSessionUpdate();
        $this->router->dispatch();
    }

    /**
     * 处理安装流程
     */
    private function handleInstall(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // 允许访问静态资源
        if (str_starts_with($path, '/assets/')) {
            return;
        }

        // 安装路由
        if ($path === '/install') {
            $controller = new \App\Controllers\Install();
            $controller->index();
            return;
        }

        $installApiRoutes = [
            '/install/check' => 'check',
            '/install/database' => 'database',
            '/install/admin' => 'admin',
            '/install/complete' => 'complete',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($installApiRoutes[$path])) {
            $controller = new \App\Controllers\Install();
            $method = $installApiRoutes[$path];
            $controller->$method();
            return;
        }

        // 其他请求一律重定向到安装页面
        header('Location: /install');
        exit;
    }

    /**
     * 按需加载配置文件（懒加载，避免启动时读取所有配置）
     */
    private function loadConfig(): void
    {
        // 只预加载启动必需的配置（app=路由/session, cache=Redis session驱动）
        $essential = ['app', 'cache'];
        foreach ($essential as $key) {
            $file = APP_PATH . 'config/' . $key . '.php';
            if (file_exists($file)) {
                $this->config[$key] = require $file;
            }
        }
    }

    /**
     * 获取配置（按需加载）
     */
    public function getConfig(string $key = null)
    {
        if ($key === null) {
            // 全量请求时加载所有
            $configFiles = glob(APP_PATH . 'config/*.php');
            foreach ($configFiles as $file) {
                $k = basename($file, '.php');
                if (!isset($this->config[$k])) {
                    $this->config[$k] = require $file;
                }
            }
            return $this->config;
        }

        $keys = explode('.', $key);
        $topKey = $keys[0];

        // 按需加载顶层配置文件
        if (!isset($this->config[$topKey])) {
            $file = APP_PATH . 'config/' . $topKey . '.php';
            if (file_exists($file)) {
                $this->config[$topKey] = require $file;
            } else {
                return null;
            }
        }

        $value = $this->config;
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return null;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * 初始化安全组件
     */
    private function initSecurity(): void
    {
        Security::setSecurityHeaders();
    }

    /**
     * 初始化多语言系统
     */
    private function initLang(): void
    {
        $locale = $this->config['app']['lang'] ?? 'zh-cn';
        Lang::init($locale);

        // 注册全局 lang() 辅助函数
        if (!function_exists('lang')) {
            function lang(string $key, array $params = [], ?string $default = null): string
            {
                return Lang::get($key, $params, $default);
            }
        }

        // 初始化 URL 生成器
        $siteUrl = $this->config['app']['url'] ?? '';
        Url::init($siteUrl);

        // 注册全局 url() 辅助函数
        if (!function_exists('url')) {
            function url(string $name, array $params = [], array $query = []): string
            {
                return Url::to($name, $params, $query);
            }
        }

        // 注册全局 Helper 辅助函数
        if (!function_exists('human_date')) {
            function human_date(int $timestamp): string
            {
                return Helper::humanDate($timestamp);
            }
        }
        if (!function_exists('brief')) {
            function brief(string $content, int $length = 200): string
            {
                return Helper::brief($content, $length);
            }
        }
        if (!function_exists('client_ip')) {
            function client_ip(): string
            {
                return Helper::clientIp();
            }
        }
        if (!function_exists('human_number')) {
            function human_number(int $num): string
            {
                return Helper::humanNumber($num);
            }
        }
        if (!function_exists('file_size_fmt')) {
            function file_size_fmt(int $bytes): string
            {
                return Helper::fileSize($bytes);
            }
        }
    }

    /**
     * 初始化路由
     */
    private function initRouter(): void
    {
        $this->router = new Router();

        // 初始化事件系统
        $this->initEvents();

        // 加载路由配置
        $this->loadRoutes();
    }

    /**
     * 初始化事件系统
     */
    private function initEvents(): void
    {
        \App\Listeners\LogListener::register();
        
        // 注册自动头像服务事件监听器
        if (\App\Services\AutoAvatarService::isEnabled()) {
            Event::listen(\App\Events\Events::USER_REGISTERED, function ($payload) {
                \App\Services\AutoAvatarService::assignRandomAvatar($payload['user_id']);
            });
        }
        
        // 注册 Emoji 服务过滤器
        if (\App\Services\EmojiService::isEnabled()) {
            \Core\Markdown::addFilter(function (string $html): string {
                return \App\Services\EmojiService::replaceEmoji($html);
            });
        }
    }

    /**
     * 加载路由
     */
    private function loadRoutes(): void
    {
        // 中间件实例
        $auth = new \App\Middlewares\Auth();
        $adminAuth = new \App\Middlewares\AdminAuth();
        $csrf = new \App\Middlewares\Csrf();
        $rateLimit = new \App\Middlewares\RateLimit(
            \App\Services\SettingSvc::getInt('rate_limit_global_max', 60),
            \App\Services\SettingSvc::getInt('rate_limit_global_window', 60)
        );
        $strictRate = new \App\Middlewares\RateLimit(
            \App\Services\SettingSvc::getInt('rate_limit_strict_max', 5),
            \App\Services\SettingSvc::getInt('rate_limit_strict_window', 300)
        );
        $runLevel = new \App\Middlewares\RunLevel();
        $searchRate = new \App\Middlewares\RateLimit(
            \App\Services\SettingSvc::getInt('rate_limit_search_max', 10),
            \App\Services\SettingSvc::getInt('rate_limit_search_window', 60)
        );

        // 全局中间件：频率限制 + CSRF + 运行级别
        $this->router->use($rateLimit);
        $this->router->use($csrf);
        $this->router->use($runLevel);

        // 首页
        $this->router->get('/', ['App\Controllers\Index', 'index']);

        // Sitemap
        $this->router->get('/sitemap.xml', ['App\Controllers\Sitemap', 'index']);

        // 搜索（频率限制 10次/分钟）
        $this->router->get('/search', ['App\Controllers\Search', 'index'], [$searchRate]);

        // 登录/注册（更严格的频率限制）
        $this->router->get('/register', ['App\Controllers\User', 'registerPage']);
        $this->router->post('/register', ['App\Controllers\User', 'register'], [$strictRate]);
        $this->router->post('/register/send-code', ['App\Controllers\User', 'registerSendCode'], [$strictRate]);
        $this->router->get('/login', ['App\Controllers\User', 'loginPage']);
        $this->router->post('/login', ['App\Controllers\User', 'login'], [$strictRate]);
        $this->router->post('/logout', ['App\Controllers\User', 'logout']);

        // 社交登录 OAuth 回调
        $this->router->get('/auth/callback/{provider}', function($provider) {
            // OAuth 回调需要读取 session 中的 state
            Bootstrap::ensureSession();
            // provider 白名单校验
            if (!preg_match('/^[a-z][a-z0-9_]{0,19}$/', $provider)) {
                header('Location: /?error=invalid_provider');
                return;
            }
            $code = $_GET['code'] ?? '';
            $state = $_GET['state'] ?? '';
            if (empty($code) || empty($state)) {
                header('Location: /?error=auth_failed');
                return;
            }
            
            // 使用 SocialLoginService 处理回调
            $result = \App\Services\SocialLoginService::handleCallback($provider, $code, $state);
            
            if ($result['success']) {
                $redirect = $_SESSION['social_login_redirect'] ?? '/';
                unset($_SESSION['social_login_redirect']);
                // 防止 Open Redirect：只允许站内路径
                if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
                    $redirect = '/';
                }
                header('Location: ' . str_replace(["\r", "\n"], '', $redirect));
            } else {
                header('Location: /?error=' . urlencode($result['error'] ?? 'auth_failed'));
            }
        });

        // 社交登录跳转（生成 state 并重定向到提供者）
        $this->router->get('/auth/redirect/{provider}', function($provider) {
            // 社交登录需要 session 存储 state，确保已启动
            Bootstrap::ensureSession();
            // provider 白名单校验
            if (!preg_match('/^[a-z][a-z0-9_]{0,19}$/', $provider)) {
                header('Location: /?error=invalid_provider');
                return;
            }
            
            // 检查提供者是否已配置
            $config = \App\Services\SocialLoginService::getProviderConfig($provider);
            if (!$config['enabled'] || empty($config['client_id'])) {
                header('Location: /?error=provider_not_configured');
                return;
            }
            
            // 保存重定向 URL
            $_SESSION['social_login_redirect'] = (str_starts_with($_GET['redirect'] ?? '/', '/') && !str_starts_with($_GET['redirect'] ?? '', '//')) ? ($_GET['redirect'] ?? '/') : '/';
            
            // 生成 state 令牌
            $state = \App\Services\SocialLoginService::generateState($provider);
            
            // 构建授权 URL
            $authUrl = $this->buildOAuthUrl($provider, $config, $state);
            
            // 防止 CRLF 注入
            $authUrl = str_replace(["\r", "\n"], '', $authUrl);
            header('Location: ' . $authUrl);
        });

        // 验证码
        $this->router->get('/captcha/generate', function() {
            $type = trim($_GET['type'] ?? '');
            if ($type === '') $type = \App\Services\CaptchaSvc::getType();
            $data = \App\Services\CaptchaSvc::generate($type);
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'data' => $data]);
        });
        $this->router->post('/captcha/verify', function() {
            $input = json_decode(file_get_contents('php://input'), true);
            $id = trim($input['captcha_id'] ?? '');
            $answer = trim($input['captcha_answer'] ?? '');
            $ok = \App\Services\CaptchaSvc::verify($id, $answer);
            header('Content-Type: application/json');
            if ($ok) {
                // 生成轻量 token 供表单提交（不再生成完整图片）
                $tokenId = bin2hex(random_bytes(16));
                $token = bin2hex(random_bytes(8));
                $_SESSION['captcha_' . $tokenId] = [
                    'type' => 'token',
                    'answer' => $token,
                    'expires' => time() + 300,
                ];
                echo json_encode(['success' => true, 'message' => '验证通过', 'captcha_id' => $tokenId, 'captcha_answer' => $token]);
            } else {
                echo json_encode(['success' => false, 'message' => '验证失败']);
            }
        });

        // 忘记密码
        $this->router->post('/forgot-password/send-code', ['App\Controllers\User', 'forgotSendCode'], [$strictRate]);
        $this->router->post('/forgot-password/reset', ['App\Controllers\User', 'forgotReset'], [$strictRate]);

        // 需要登录的用户路由
        $this->router->get('/profile', ['App\Controllers\User', 'profile'], [$auth]);
        $this->router->post('/user/avatar', ['App\Controllers\User', 'uploadAvatar'], [$auth]);
        $this->router->post('/user/profile', ['App\Controllers\User', 'updateProfile'], [$auth]);
        $this->router->post('/user/password', ['App\Controllers\User', 'changePassword'], [$auth]);

        // 帖子相关（注意：/thread/create 必须在 /thread/{id:\d+} 之前注册）
        $this->router->get('/forums', ['App\Controllers\Index', 'forums']);
        $this->router->get('/forum/all', ['App\Controllers\Index', 'allThreads']);
        $this->router->get('/forum/{id:\d+}', ['App\Controllers\Thread', 'forum']);
        $this->router->get('/thread/create', ['App\Controllers\Thread', 'createPage'], [$auth]);
        $this->router->post('/thread/create', ['App\Controllers\Thread', 'create'], [$auth]);
        $this->router->get('/thread/{id:\d+}', ['App\Controllers\Thread', 'detail']);
        $this->router->get('/thread/edit/{id:\d+}', ['App\Controllers\Thread', 'editPage'], [$auth]);
        $this->router->post('/thread/reply', ['App\Controllers\Thread', 'reply'], [$auth]);
        $this->router->post('/thread/edit', ['App\Controllers\Thread', 'edit'], [$auth]);
        $this->router->post('/thread/delete', ['App\Controllers\Thread', 'delete'], [$auth]);
        $this->router->post('/thread/toggle-top', ['App\Controllers\Thread', 'toggleTop'], [$auth]);
        $this->router->post('/thread/toggle-highlight', ['App\Controllers\Thread', 'toggleHighlight'], [$auth]);
        $this->router->post('/thread/like', ['App\Controllers\Thread', 'like'], [$auth]);
        $this->router->post('/thread/upload-image', ['App\Controllers\Thread', 'uploadImage'], [$auth]);

        // 用户相关路由（具体路由必须在动态路由前面）
        $this->router->get('/user/credit-logs', ['App\Controllers\User', 'creditLogs'], [$auth]);
        $this->router->get('/user/credit-ranking', ['App\Controllers\User', 'creditRanking']);
        $this->router->get('/user/levels', ['App\Controllers\User', 'levels']);
        $this->router->post('/user/follow', ['App\Controllers\User', 'follow'], [$auth]);
        $this->router->post('/user/blacklist', ['App\Controllers\User', 'blacklist'], [$auth]);
        $this->router->get('/user/blacklist', ['App\Controllers\User', 'blacklistPage'], [$auth]);
        $this->router->post('/user/clear-history', ['App\Controllers\User', 'clearHistory'], [$auth]);

        // 用户公开主页（动态路由放后面）
        $this->router->get('/user/{id:\d+}', ['App\Controllers\User', 'publicProfile']);
        $this->router->get('/user/{id:\d+}/followers', ['App\Controllers\User', 'followers']);
        $this->router->get('/user/{id:\d+}/following', ['App\Controllers\User', 'following']);

        // 通知（需要登录）
        $this->router->get('/notifications', ['App\Controllers\Notification', 'index'], [$auth]);
        $this->router->get('/notifications/unread-count', ['App\Controllers\Notification', 'unreadCount'], [$auth]);
        $this->router->post('/notifications/read-all', ['App\Controllers\Notification', 'readAll'], [$auth]);
        $this->router->post('/notifications/read', ['App\Controllers\Notification', 'readOne'], [$auth]);
        $this->router->post('/notifications/delete', ['App\Controllers\Notification', 'deleteOne'], [$auth]);
        $this->router->post('/notifications/delete-read', ['App\Controllers\Notification', 'deleteRead'], [$auth]);

        // 私信（需要登录）
        $this->router->get('/messages', ['App\Controllers\Message', 'index'], [$auth]);
        $this->router->get('/messages/{id:\d+}', ['App\Controllers\Message', 'conversation'], [$auth]);
        $this->router->post('/messages/send', ['App\Controllers\Message', 'send'], [$auth]);
        $this->router->post('/messages/recall', ['App\Controllers\Message', 'recall'], [$auth]);

        // 标签
        $this->router->get('/tag/{name}', ['App\Controllers\Thread', 'tagThreads']);

        // 收藏
        $this->router->post('/thread/favorite', ['App\Controllers\Thread', 'favorite'], [$auth]);
        $this->router->get('/favorites', ['App\Controllers\User', 'favorites'], [$auth]);

        // 签到
        $this->router->post('/checkin', ['App\Controllers\Checkin', 'checkin'], [$auth]);

        // 动态/说说
        $this->router->get('/moments', ['App\Controllers\Moment', 'index']);
        $this->router->post('/moments/create', ['App\Controllers\Moment', 'create'], [$auth]);
        $this->router->post('/moments/like', ['App\Controllers\Moment', 'like'], [$auth]);
        $this->router->post('/moments/comment', ['App\Controllers\Moment', 'comment'], [$auth]);
        $this->router->post('/moments/delete', ['App\Controllers\Moment', 'delete'], [$auth]);

        // 打赏
        $this->router->post('/thread/reward', ['App\Controllers\Thread', 'reward'], [$auth]);

        // 购买隐藏内容
        $this->router->post('/thread/buy-content', ['App\Controllers\Thread', 'buyContent'], [$auth]);

        // 批量版主操作
        $this->router->post('/mod/batch', ['App\Controllers\Thread', 'batchMod'], [$auth]);

        // 前台版主管理
        $this->router->post('/forum/moderators', ['App\Controllers\Thread', 'updateModerators'], [$auth]);

        // 帖子锁定/解锁
        $this->router->post('/thread/toggle-lock', ['App\Controllers\Thread', 'toggleLock'], [$auth]);

        // 帖子移动
        $this->router->post('/thread/move', ['App\Controllers\Thread', 'move'], [$auth]);

        // 回复编辑/删除
        $this->router->post('/post/edit', ['App\Controllers\Thread', 'editPost'], [$auth]);
        $this->router->post('/post/delete', ['App\Controllers\Thread', 'deletePost'], [$auth]);

        // 编辑历史
        $this->router->get('/thread/{id:\d+}/edit-log', ['App\Controllers\Thread', 'editLog']);

        // 附件上传/下载
        $this->router->post('/attachment/upload', ['App\Controllers\Thread', 'uploadAttachment'], [$auth]);
        $this->router->get('/attachment/{id:\d+}', ['App\Controllers\Thread', 'downloadAttachment']);

        // 定时任务
        $this->router->get('/cron/run', ['App\Controllers\Cron', 'run']);

        // 任务中心
        $this->router->get('/tasks', ['App\Controllers\TaskCenter', 'index'], [$auth]);
        $this->router->post('/tasks/claim', ['App\Controllers\TaskCenter', 'claim'], [$auth]);
        $this->router->post('/tasks/claim-all', ['App\Controllers\TaskCenter', 'claimAll'], [$auth]);

        // VIP 会员
        $this->router->get('/vip', ['App\Controllers\Vip', 'index']);
        $this->router->post('/vip/purchase', ['App\Controllers\Vip', 'purchase'], [$auth]);

        // 网址导航
        $this->router->get('/navigation', ['App\Controllers\NavLink', 'index']);
        $this->router->get('/navigation/go', ['App\Controllers\NavLink', 'go']);

        // 插件静态资源（支持多级路径，如 tinymce/plugins/codesample/plugin.min.js）
        $this->router->get('/plugin-assets/{plugin}/{file:.+}', function($plugin, $file) {
            // 安全校验：只允许字母数字和连字符的插件名，防止目录穿越
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $plugin) || preg_match('/\.\./', $file)) {
                http_response_code(404);
                return;
            }
            // 扩展名白名单：只允许静态资源类型，防止 PHP 源码泄露
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $allowedExts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'css', 'js', 'woff', 'woff2', 'ttf', 'eot', 'map', 'json', 'ico'];
            if (!in_array($ext, $allowedExts, true)) {
                http_response_code(403);
                return;
            }
            // 先尝试 assets/ 子目录，再尝试插件根目录
            $path = APP_PATH . 'plugins/' . $plugin . '/assets/' . $file;
            if (!file_exists($path) || !is_file($path)) {
                $path = APP_PATH . 'plugins/' . $plugin . '/' . $file;
            }
            if (!file_exists($path) || !is_file($path)) {
                http_response_code(404);
                return;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'css' => 'text/css', 'js' => 'application/javascript', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject'];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
            // 用 filemtime+filesize 生成 ETag，避免 md5_file 每次读整个文件
            $etag = '"' . filemtime($path) . '-' . filesize($path) . '"';
            header('Cache-Control: public, max-age=2592000, immutable');
            header('ETag: ' . $etag);

            // ETag 协商：文件未变直接返回 304，不读文件
            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                http_response_code(304);
                return;
            }

            header('Content-Type: ' . $mime);
            readfile($path);
        });

        // 健康检查（仅返回基本状态，不暴露内部信息）
        $this->router->get('/health', function() {
            header('Content-Type: application/json');

            $status = 'ok';

            // 检查数据库
            try {
                Database::fetchOne('SELECT 1');
            } catch (\Throwable $e) {
                $status = 'error';
            }

            http_response_code($status === 'ok' ? 200 : 500);
            echo json_encode(['status' => $status, 'timestamp' => time()]);
        });

        // ==================== 后台管理（AdminAuth 中间件） ====================

        // 概览（shell 框架页 + 子页面）
        $this->router->get('/admin', ['App\Controllers\Admin\DashboardController', 'shell'], [$adminAuth]);
        $this->router->get('/admin/dashboard', ['App\Controllers\Admin\DashboardController', 'dashboard'], [$adminAuth]);
        $this->router->get('/admin/monitor', ['App\Controllers\Admin\DashboardController', 'monitor'], [$adminAuth]);

        // 板块管理
        $this->router->get('/admin/forums', ['App\Controllers\Admin\ForumController', 'forums'], [$adminAuth]);
        $this->router->post('/admin/forums/create', ['App\Controllers\Admin\ForumController', 'forumCreate'], [$adminAuth]);
        $this->router->post('/admin/forums/update', ['App\Controllers\Admin\ForumController', 'forumUpdate'], [$adminAuth]);
        $this->router->post('/admin/forums/delete', ['App\Controllers\Admin\ForumController', 'forumDelete'], [$adminAuth]);
        $this->router->post('/admin/forums/access', ['App\Controllers\Admin\ForumController', 'forumAccess'], [$adminAuth]);
        $this->router->post('/admin/forums/access-save', ['App\Controllers\Admin\ForumController', 'forumAccessSave'], [$adminAuth]);

        // 帖子管理
        $this->router->get('/admin/threads', ['App\Controllers\Admin\ThreadController', 'threads'], [$adminAuth]);
        $this->router->post('/admin/threads/delete', ['App\Controllers\Admin\ThreadController', 'threadDelete'], [$adminAuth]);
        $this->router->post('/admin/threads/batch', ['App\Controllers\Admin\ThreadController', 'threadBatch'], [$adminAuth]);
        $this->router->post('/admin/threads/toggle-top', ['App\Controllers\Admin\ThreadController', 'threadToggleTop'], [$adminAuth]);
        $this->router->post('/admin/threads/toggle-highlight', ['App\Controllers\Admin\ThreadController', 'threadToggleHighlight'], [$adminAuth]);

        // 回帖管理
        $this->router->get('/admin/posts', ['App\Controllers\Admin\PostController', 'posts'], [$adminAuth]);
        $this->router->post('/admin/posts/delete', ['App\Controllers\Admin\PostController', 'postDelete'], [$adminAuth]);
        $this->router->post('/admin/posts/batch', ['App\Controllers\Admin\PostController', 'postBatch'], [$adminAuth]);

        // 附件管理
        $this->router->get('/admin/attachments', ['App\Controllers\Admin\AttachController', 'attachments'], [$adminAuth]);
        $this->router->post('/admin/attachments/delete', ['App\Controllers\Admin\AttachController', 'attachmentDelete'], [$adminAuth]);

        // 敏感词管理
        $this->router->get('/admin/sensitive-words', ['App\Controllers\Admin\FilterController', 'sensitiveWords'], [$adminAuth]);
        $this->router->post('/admin/sensitive-words/create', ['App\Controllers\Admin\FilterController', 'sensitiveWordCreate'], [$adminAuth]);
        $this->router->post('/admin/sensitive-words/delete', ['App\Controllers\Admin\FilterController', 'sensitiveWordDelete'], [$adminAuth]);

        // 标签分类管理
        $this->router->get('/admin/tag-categories', ['App\Controllers\Admin\TagController', 'tagCategories'], [$adminAuth]);
        $this->router->post('/admin/tag-categories/create', ['App\Controllers\Admin\TagController', 'tagCategoryCreate'], [$adminAuth]);
        $this->router->post('/admin/tag-categories/delete', ['App\Controllers\Admin\TagController', 'tagCategoryDelete'], [$adminAuth]);

        // 公告管理
        $this->router->get('/admin/announcements', ['App\Controllers\Admin\AnnounceController', 'announcements'], [$adminAuth]);
        $this->router->post('/admin/announcements/create', ['App\Controllers\Admin\AnnounceController', 'announcementCreate'], [$adminAuth]);
        $this->router->post('/admin/announcements/update', ['App\Controllers\Admin\AnnounceController', 'announcementUpdate'], [$adminAuth]);
        $this->router->post('/admin/announcements/toggle', ['App\Controllers\Admin\AnnounceController', 'announcementToggle'], [$adminAuth]);
        $this->router->post('/admin/announcements/delete', ['App\Controllers\Admin\AnnounceController', 'announcementDelete'], [$adminAuth]);

        // 用户管理
        $this->router->get('/admin/users', ['App\Controllers\Admin\UserController', 'users'], [$adminAuth]);
        $this->router->post('/admin/users/update', ['App\Controllers\Admin\UserController', 'userUpdate'], [$adminAuth]);
        $this->router->post('/admin/users/batch', ['App\Controllers\Admin\UserController', 'userBatchAction'], [$adminAuth]);
        $this->router->get('/admin/user-settings', ['App\Controllers\Admin\UserController', 'userSettings'], [$adminAuth]);
        $this->router->post('/admin/user-settings', ['App\Controllers\Admin\UserController', 'userSettingsSave'], [$adminAuth]);
        $this->router->get('/admin/vip-settings', ['App\Controllers\Admin\UserController', 'vipSettings'], [$adminAuth]);
        $this->router->post('/admin/vip-settings', ['App\Controllers\Admin\UserController', 'vipSettingsSave'], [$adminAuth]);
        $this->router->get('/admin/online-users', ['App\Controllers\Admin\UserController', 'onlineUsers'], [$adminAuth]);
        $this->router->get('/admin/credit-logs', ['App\Controllers\Admin\UserController', 'creditLogs'], [$adminAuth]);

        // 用户组管理
        $this->router->get('/admin/user-groups', ['App\Controllers\Admin\UserGroupController', 'userGroups'], [$adminAuth]);
        $this->router->post('/admin/user-groups/save', ['App\Controllers\Admin\UserGroupController', 'userGroupSave'], [$adminAuth]);
        $this->router->post('/admin/user-groups/delete', ['App\Controllers\Admin\UserGroupController', 'userGroupDelete'], [$adminAuth]);

        // 等级管理
        $this->router->get('/admin/levels', ['App\Controllers\Admin\LevelController', 'levels'], [$adminAuth]);
        $this->router->post('/admin/levels/save', ['App\Controllers\Admin\LevelController', 'levelSave'], [$adminAuth]);
        $this->router->post('/admin/levels/delete', ['App\Controllers\Admin\LevelController', 'levelDelete'], [$adminAuth]);

        // 通知管理
        $this->router->get('/admin/notifications', ['App\Controllers\Admin\NotifyController', 'notifications'], [$adminAuth]);
        $this->router->post('/admin/notifications/send', ['App\Controllers\Admin\NotifyController', 'notificationSend'], [$adminAuth]);
        $this->router->post('/admin/notifications/delete', ['App\Controllers\Admin\NotifyController', 'notificationDelete'], [$adminAuth]);

        // 私信监控
        $this->router->get('/admin/messages', ['App\Controllers\Admin\MessageController', 'messages'], [$adminAuth]);
        $this->router->post('/admin/messages/delete', ['App\Controllers\Admin\MessageController', 'messageDelete'], [$adminAuth]);

        // 系统管理
        $this->router->get('/admin/settings', ['App\Controllers\Admin\SystemController', 'settings'], [$adminAuth]);
        $this->router->post('/admin/settings', ['App\Controllers\Admin\SystemController', 'settingsSave'], [$adminAuth]);
        $this->router->post('/admin/settings/test-email', ['App\Controllers\Admin\SystemController', 'testEmail'], [$adminAuth]);
        $this->router->get('/admin/system-info', ['App\Controllers\Admin\SystemController', 'systemInfo'], [$adminAuth]);
        $this->router->get('/admin/cache', ['App\Controllers\Admin\SystemController', 'cacheManage'], [$adminAuth]);
        $this->router->post('/admin/cache/clear', ['App\Controllers\Admin\SystemController', 'cacheClear'], [$adminAuth]);
        $this->router->get('/admin/cluster', ['App\Controllers\Admin\SystemController', 'cluster'], [$adminAuth]);
        $this->router->post('/admin/cluster/create', ['App\Controllers\Admin\SystemController', 'clusterCreate'], [$adminAuth]);
        $this->router->post('/admin/cluster/toggle', ['App\Controllers\Admin\SystemController', 'clusterToggle'], [$adminAuth]);
        $this->router->post('/admin/cluster/delete', ['App\Controllers\Admin\SystemController', 'clusterDelete'], [$adminAuth]);
        $this->router->post('/admin/cluster/update', ['App\Controllers\Admin\SystemController', 'clusterUpdate'], [$adminAuth]);
        $this->router->post('/admin/cluster/test', ['App\Controllers\Admin\SystemController', 'clusterTest'], [$adminAuth]);
        $this->router->get('/admin/ip-blacklist', ['App\Controllers\Admin\SystemController', 'ipBlacklist'], [$adminAuth]);
        $this->router->post('/admin/ip-blacklist/create', ['App\Controllers\Admin\SystemController', 'ipBlacklistCreate'], [$adminAuth]);
        $this->router->post('/admin/ip-blacklist/delete', ['App\Controllers\Admin\SystemController', 'ipBlacklistDelete'], [$adminAuth]);

        // 导航管理
        $this->router->get('/admin/navigation', ['App\Controllers\Admin\SystemController', 'navigation'], [$adminAuth]);
        $this->router->post('/admin/nav-categories/create', ['App\Controllers\Admin\SystemController', 'navCategoryCreate'], [$adminAuth]);
        $this->router->post('/admin/nav-categories/update', ['App\Controllers\Admin\SystemController', 'navCategoryUpdate'], [$adminAuth]);
        $this->router->post('/admin/nav-categories/delete', ['App\Controllers\Admin\SystemController', 'navCategoryDelete'], [$adminAuth]);
        $this->router->post('/admin/nav-links/create', ['App\Controllers\Admin\SystemController', 'navLinkCreate'], [$adminAuth]);
        $this->router->post('/admin/nav-links/update', ['App\Controllers\Admin\SystemController', 'navLinkUpdate'], [$adminAuth]);
        $this->router->post('/admin/nav-links/delete', ['App\Controllers\Admin\SystemController', 'navLinkDelete'], [$adminAuth]);

        // 友情链接管理
        $this->router->get('/admin/friend-links', ['App\Controllers\Admin\SystemController', 'friendLinks'], [$adminAuth]);
        $this->router->post('/admin/friend-links/create', ['App\Controllers\Admin\SystemController', 'friendLinkCreate'], [$adminAuth]);
        $this->router->post('/admin/friend-links/delete', ['App\Controllers\Admin\SystemController', 'friendLinkDelete'], [$adminAuth]);
        $this->router->get('/admin/logs', ['App\Controllers\Admin\SystemController', 'logs'], [$adminAuth]);

        // 后台 API 端点（layui table 数据源）
        $this->router->get('/admin/api/forums', ['App\Controllers\Admin\ForumController', 'forumsApi'], [$adminAuth]);
        $this->router->get('/admin/api/threads', ['App\Controllers\Admin\ThreadController', 'threadsApi'], [$adminAuth]);
        $this->router->get('/admin/api/posts', ['App\Controllers\Admin\PostController', 'postsApi'], [$adminAuth]);
        $this->router->get('/admin/api/users', ['App\Controllers\Admin\UserController', 'usersApi'], [$adminAuth]);
        $this->router->get('/admin/api/attachments', ['App\Controllers\Admin\AttachController', 'attachmentsApi'], [$adminAuth]);
        $this->router->get('/admin/api/announcements', ['App\Controllers\Admin\AnnounceController', 'announcementsApi'], [$adminAuth]);
        $this->router->get('/admin/api/sensitive-words', ['App\Controllers\Admin\FilterController', 'sensitiveWordsApi'], [$adminAuth]);
        $this->router->get('/admin/api/tag-categories', ['App\Controllers\Admin\TagController', 'tagCategoriesApi'], [$adminAuth]);
        $this->router->get('/admin/api/notifications', ['App\Controllers\Admin\NotifyController', 'notificationsApi'], [$adminAuth]);
        $this->router->get('/admin/api/messages', ['App\Controllers\Admin\MessageController', 'messagesApi'], [$adminAuth]);
        $this->router->get('/admin/api/ip-blacklist', ['App\Controllers\Admin\SystemController', 'ipBlacklistApi'], [$adminAuth]);
        $this->router->get('/admin/api/friend-links', ['App\Controllers\Admin\SystemController', 'friendLinksApi'], [$adminAuth]);
        $this->router->get('/admin/api/logs', ['App\Controllers\Admin\SystemController', 'logsApi'], [$adminAuth]);
        $this->router->get('/admin/api/credit-logs', ['App\Controllers\Admin\UserController', 'creditLogsApi'], [$adminAuth]);
        $this->router->get('/admin/api/online-users', ['App\Controllers\Admin\UserController', 'onlineUsersApi'], [$adminAuth]);
        $this->router->get('/admin/api/levels', ['App\Controllers\Admin\LevelController', 'levelsApi'], [$adminAuth]);
        $this->router->get('/admin/api/user-groups', ['App\Controllers\Admin\UserGroupController', 'userGroupsApi'], [$adminAuth]);

        // RESTful API（API 使用 Token 认证，不需要 Session Auth 中间件）
        $this->router->post('/api/auth/login', ['App\Controllers\Api', 'authLogin'], [$strictRate]);
        $this->router->post('/api/auth/register', ['App\Controllers\Api', 'authRegister'], [$strictRate]);
        $this->router->post('/api/auth/logout', ['App\Controllers\Api', 'authLogout']);
        $this->router->get('/api/users/me', ['App\Controllers\Api', 'userMe']);
        $this->router->get('/api/users/{id:\d+}', ['App\Controllers\Api', 'userShow']);
        $this->router->get('/api/forums', ['App\Controllers\Api', 'forumList']);
        $this->router->get('/api/forums/{id:\d+}', ['App\Controllers\Api', 'forumShow']);
        $this->router->get('/api/threads', ['App\Controllers\Api', 'threadList']);
        $this->router->get('/api/threads/{id:\d+}', ['App\Controllers\Api', 'threadShow']);
        $this->router->post('/api/threads', ['App\Controllers\Api', 'threadCreate']);
        $this->router->get('/api/threads/{id:\d+}/posts', ['App\Controllers\Api', 'postList']);
        $this->router->post('/api/threads/{id:\d+}/posts', ['App\Controllers\Api', 'postCreate']);
        $this->router->get('/api/search', ['App\Controllers\Api', 'search'], [$searchRate]);
        $this->router->get('/api/notifications', ['App\Controllers\Api', 'notificationList']);
        $this->router->post('/api/notifications/read', ['App\Controllers\Api', 'notificationRead']);
    }

    /**
     * 启动 Session（仅在有 session cookie 或 remember_me cookie 时才启动）
     */
    private function startSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // 匿名 GET 请求无 cookie 时跳过 session_start，节省文件/Redis I/O
        // POST 请求需要 session（CSRF 验证），所以不跳过
        $sessionName = session_name(); // 默认 PHPSESSID
        $hasSessionCookie = isset($_COOKIE[$sessionName]);
        $hasRememberCookie = isset($_COOKIE['amubbs_remember']);
        $isGet = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
        if (!$hasSessionCookie && !$hasRememberCookie && $isGet) {
            return;
        }

        self::doStartSession($this->config);
    }

    /**
     * 确保 session 已启动（供需要 session 的组件调用，如 CSRF、验证码、社交登录）
     * 包含完整的 session 配置（Redis handler、cookie 安全参数），避免裸 session_start()
     */
    public static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        $instance = self::$instance;
        $config = $instance ? $instance->config : [];
        self::doStartSession($config);
    }

    /**
     * 执行 session 启动（含 Redis handler 和 cookie 安全配置）
     */
    private static function doStartSession(array $config): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $appConfig = $config['app'] ?? [];
        $sessionConf = $appConfig['session'] ?? [];
        $driver = $sessionConf['driver'] ?? 'file';

        if ($driver === 'redis' && extension_loaded('redis')) {
            try {
                $cacheConf = $config['cache']['redis'] ?? [];
                $handler = new RedisSessionHandler([
                    'host' => $cacheConf['host'] ?? '127.0.0.1',
                    'port' => $cacheConf['port'] ?? 6379,
                    'password' => $cacheConf['password'] ?? '',
                    'database' => $sessionConf['redis_db'] ?? 1,
                    'timeout' => $cacheConf['timeout'] ?? 5,
                ], $sessionConf['lifetime'] ?? 7200);

                session_set_save_handler($handler, true);
            } catch (\Throwable $e) {
                // Redis 不可用时回退到文件 Session
                error_log('[Session] Redis 不可用，回退到文件驱动: ' . $e->getMessage());
            }
        }

        // Session 安全选项
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => $sessionConf['lifetime'] ?? 7200,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /**
     * Remember Me：检查 token cookie 自动恢复 session
     */
    private function restoreRememberMe(): void
    {
        // Session 未启动时跳过（匿名 GET 无 cookie）
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        // 已登录则跳过
        if (!empty($_SESSION['user_id'])) {
            return;
        }

        try {
            $user = RememberToken::verify();
            if ($user) {
                // 防止会话固定攻击：恢复登录态时重新生成 session ID
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nickname'] = $user['nickname'] ?? null;
                $_SESSION['group_id'] = $user['group_id'];
                $_SESSION['avatar'] = $user['avatar'] ?? '';
            }
        } catch (\Throwable $e) {
            // token 验证失败不影响正常访问
            error_log('[RememberMe] ' . $e->getMessage());
        }
    }

    /**
     * 延迟更新 session 上下文到响应发送后
     * 避免 DB 写操作阻塞首页响应
     */
    private function deferSessionUpdate(): void
    {
        register_shutdown_function(function () {
            // 先刷出响应给客户端
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $this->updateSessionContext();
            } else {
                // 匿名游客：用 IP+UA 哈希生成伪 session ID 写入 sessions 表
                $this->trackGuestVisit();
            }
        });
    }

    /**
     * 追踪匿名游客访问（无 PHP session 时）
     */
    private function trackGuestVisit(): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $guestId = 'guest_' . substr(md5($ip . '|' . $ua), 0, 24);

            // 30秒内同一游客不重复写DB，大幅减少匿名请求的DB开销
            $throttleKey = "guest:throttle:{$guestId}";
            if (\Core\Cache::add($throttleKey, 1, 30)) {
                $url = mb_substr($_SERVER['REQUEST_URI'] ?? '', 0, 255);
                $now = time();

                $forumId = 0;
                if (preg_match('#^/forum/(\d+)#', $url, $m)) {
                    $forumId = (int)$m[1];
                }

                \Core\Database::execute("
                    INSERT INTO sessions (id, user_id, ip, user_agent, last_activity, current_forum_id, current_url)
                    VALUES (?, 0, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        last_activity = VALUES(last_activity),
                        current_forum_id = VALUES(current_forum_id),
                        current_url = VALUES(current_url)
                ", [$guestId, $ip, mb_substr($ua, 0, 500), $now, $forumId, $url]);
            }
        } catch (\Throwable $e) {
            // 非关键操作
        }
    }

    /**
     * 更新 session 浏览上下文（当前 URL、板块 ID）
     */
    private function updateSessionContext(): void
    {
        try {
            $url = $_SERVER['REQUEST_URI'] ?? '';
            $forumId = 0;

            // 从 URL 解析当前板块 ID
            if (preg_match('#^/forum/(\d+)#', $url, $m)) {
                $forumId = (int)$m[1];
            } elseif (preg_match('#^/thread/(\d+)#', $url, $m)) {
                // 帖子详情页：从缓存或 session 获取板块 ID
                $forumId = (int)($_SESSION['current_forum_id'] ?? 0);
            }

            \App\Services\OnlineSvc::updateContext($forumId, $url);
        } catch (\Throwable $e) {
            // 非关键操作，不中断请求，但记录日志便于排查
            error_log('[Bootstrap] updateSessionContext failed: ' . $e->getMessage());
        }
    }

    /**
     * 注册错误处理
     */
    private function registerErrorHandler(): void
    {
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting() & $errno)) {
                return false;
            }

            $error = sprintf(
                "[%s] %s in %s on line %d",
                $this->getErrorType($errno),
                str_replace(["\r", "\n"], ' ', $errstr),
                $errfile,
                $errline
            );

            error_log($error);

            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => DEBUG ? $error : '服务器错误']);
            } elseif (DEBUG) {
                echo "<pre>" . htmlspecialchars($error) . "</pre>";
            }

            return true;
        });

        set_exception_handler(function($exception) {
            $error = sprintf(
                "[Exception] %s in %s on line %d",
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            );

            error_log($error);

            if ($this->isAjaxRequest()) {
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                    http_response_code(500);
                }
                echo json_encode(['success' => false, 'message' => DEBUG ? $exception->getMessage() : '服务器错误']);
            } elseif (DEBUG) {
                echo "<pre>" . htmlspecialchars($error . "\n" . $exception->getTraceAsString()) . "</pre>";
            } else {
                if (!headers_sent()) {
                    http_response_code(500);
                }
                echo 'Internal Server Error';
            }
        });

        // 捕获致命错误（E_ERROR, E_PARSE 等 set_error_handler 无法捕获的）
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $msg = sprintf("[Fatal] %s in %s on line %d", $error['message'], $error['file'], $error['line']);
                error_log($msg);
                if (!headers_sent()) {
                    http_response_code(500);
                    if ($this->isAjaxRequest()) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => DEBUG ? $error['message'] : '服务器错误']);
                    } else {
                        echo DEBUG ? "<pre>" . htmlspecialchars($msg) . "</pre>" : 'Internal Server Error';
                    }
                }
            }
        });
    }

    /**
     * 判断是否为 AJAX 请求
     */
    private function isAjaxRequest(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
            || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
            || (!empty($_SERVER['HTTP_X_CSRF_TOKEN']));
    }

    private function getErrorType(int $type): string
    {
        $types = [
            E_ERROR => 'Error',
            E_WARNING => 'Warning',
            E_NOTICE => 'Notice',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
        ];

        return $types[$type] ?? 'Unknown Error';
    }

    /**
     * 初始化插件系统（基于 plugins.json 注册表，跳过 glob 目录扫描）
     */
    private function initPlugins(): void
    {
        $pluginPath = APP_PATH . 'plugins';
        if (!is_dir($pluginPath)) {
            return;
        }

        $this->pluginManager = new PluginManager($this->container, $this->eventDispatcher);
        $loader = new PluginLoader($this->pluginManager, $pluginPath);
        $loader->loadEnabled($this->pluginManager->getEnabled());
        $this->pluginManager->boot();
        $this->pluginManager->syncAllAssets();
    }

    /**
     * 安装检测（静态缓存，避免每请求 file_exists 磁盘 I/O）
     */
    private static function isInstalled(): bool
    {
        static $installed = null;
        if ($installed === null) {
            $installed = file_exists(APP_PATH . 'install/install.lock');
        }
        return $installed;
    }

    /**
     * 获取容器
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * 获取事件分发器
     */
    public function getEventDispatcher(): EventDispatcher
    {
        return $this->eventDispatcher;
    }

    /**
     * 获取插件管理器
     */
    public function getPluginManager(): ?PluginManager
    {
        return $this->pluginManager;
    }

    /**
     * 构建 OAuth 授权 URL
     */
    private function buildOAuthUrl(string $provider, array $config, string $state): string
    {
        $baseUrl = \App\Services\SettingSvc::get('site_url', '');
        if (empty($baseUrl)) {
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                     . '://' . $_SERVER['HTTP_HOST'];
        }
        $redirectUri = rtrim($baseUrl, '/') . "/auth/callback/{$provider}";
        
        $endpoints = [
            'github' => [
                'url' => 'https://github.com/login/oauth/authorize',
                'params' => [
                    'client_id' => $config['client_id'],
                    'redirect_uri' => $redirectUri,
                    'state' => $state,
                    'scope' => 'user:email',
                ],
            ],
            'google' => [
                'url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'params' => [
                    'client_id' => $config['client_id'],
                    'redirect_uri' => $redirectUri,
                    'response_type' => 'code',
                    'scope' => 'openid email profile',
                    'state' => $state,
                ],
            ],
            'wechat' => [
                'url' => 'https://open.weixin.qq.com/connect/qrconnect',
                'params' => [
                    'appid' => $config['client_id'],
                    'redirect_uri' => $redirectUri,
                    'response_type' => 'code',
                    'scope' => 'snsapi_login',
                    'state' => $state,
                ],
            ],
            'qq' => [
                'url' => 'https://graph.qq.com/oauth2.0/authorize',
                'params' => [
                    'client_id' => $config['client_id'],
                    'redirect_uri' => $redirectUri,
                    'response_type' => 'code',
                    'state' => $state,
                ],
            ],
        ];
        
        if (!isset($endpoints[$provider])) {
            return '/';
        }
        
        $endpoint = $endpoints[$provider];
        return $endpoint['url'] . '?' . http_build_query($endpoint['params']);
    }

}
