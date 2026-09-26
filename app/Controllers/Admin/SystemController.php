<?php
/**
 * 后台 - 系统管理（设置、系统信息、缓存、集群、IP黑名单、日志）
 */

namespace App\Controllers\Admin;

use Core\Database;
use Core\Cache;
use Core\Event;
use App\Events\Events;

class SystemController extends AdminBase
{
    // ==================== 系统设置 ====================

    /**
     * 系统设置：布尔开关型字段
     *
     * 表单里每个都配了 hidden=0 + checkbox=1，未勾选也会提交 "0"。
     * 旧实现依赖前端 JS 补 false，服务端拿不到就跳过——直接 POST 时开关关不掉。
     */
    private const SETTINGS_BOOL_KEYS = [
        'url_html_suffix', 'captcha_enabled', 'watermark_enabled',
        'auto_avatar_enabled', 'auto_avatar_overwrite', 'emoji_enabled',
        'social_login_github_enabled', 'social_login_google_enabled',
        'social_login_wechat_enabled', 'social_login_qq_enabled',
    ];

    /** 系统设置：文本/数字/下拉型字段 */
    private const SETTINGS_TEXT_KEYS = [
        'site_name', 'site_url', 'site_description', 'site_keywords', 'icp_number',
        'cdn_url', 'site_runlevel', 'site_maintenance_msg', 'admin_bind_ip',
        'watermark_text', 'watermark_position', 'watermark_opacity',
        'image_max_width', 'image_thumb_width', 'cron_key',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'smtp_from_name', 'smtp_encryption',
        'ip_limit_thread', 'ip_limit_post', 'ip_limit_register', 'ip_limit_upload',
        'announcement_hide_duration', 'captcha_type', 'threads_per_page',
        'rate_limit_global_max', 'rate_limit_global_window',
        'rate_limit_strict_max', 'rate_limit_strict_window',
        'rate_limit_search_max', 'rate_limit_search_window',
        'social_login_github_client_id', 'social_login_github_client_secret',
        'social_login_google_client_id', 'social_login_google_client_secret',
        'social_login_wechat_app_id', 'social_login_wechat_app_secret',
        'social_login_qq_app_id', 'social_login_qq_app_key',
    ];

    /** 验证码可启用场景（表单里是 4 个独立 checkbox，存成一个逗号串） */
    private const CAPTCHA_SCENES = ['register', 'login', 'thread', 'reply'];

    public function settings(): void
    {
        $this->requireAdmin();
        $this->renderSettingsPage();
    }

    /**
     * 读取全部设置（键 => 值）
     */
    private function fetchAllSettings(): array
    {
        // 走 SettingSvc：它自带 settings:all 缓存，设置页就不必每次都全表读
        return \App\Services\SettingSvc::all();
    }

    /**
     * 渲染系统设置页面片段（GET 与保存后的刷新共用同一渲染路径）
     */
    private function renderSettingsPage(): void
    {
        $this->renderAdmin('admin/settings', [
            'pageTitle'  => '系统设置',
            'settings'   => $this->fetchAllSettings(),
            'currentIp'  => \Core\Helper::clientIp(),
            'captchaScenes' => self::CAPTCHA_SCENES,
        ], 'settings');
    }

    public function settingsSave(): void
    {
        $this->requireAdmin();

        $input = $this->input();

        // 运行级别值域校验（0-5）
        if (isset($input['site_runlevel']) && $input['site_runlevel'] !== '') {
            $rl = (int)$input['site_runlevel'];
            if ($rl < 0 || $rl > 5) {
                $this->respondMutation(false, '运行级别无效，请选择 0-5 之间的值', fn() => $this->renderSettingsPage());
                return;
            }
        }

        $values = [];

        foreach (self::SETTINGS_BOOL_KEYS as $key) {
            $values[$key] = !empty($input[$key]) ? '1' : '0';
        }

        foreach (self::SETTINGS_TEXT_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = trim((string)$input[$key]);
            }
        }

        // 4 个场景 checkbox 合成 captcha_scenes（旧实现靠前端 JS 拼，服务端不认单场景键）
        $scenes = [];
        foreach (self::CAPTCHA_SCENES as $scene) {
            if (!empty($input['captcha_scene_' . $scene])) {
                $scenes[] = $scene;
            }
        }
        $values['captcha_scenes'] = implode(',', $scenes);

        // 落库 + 失效缓存都由 SettingSvc 负责（它再往下调 Setting 模型）
        \App\Services\SettingSvc::setMany($values);
        Event::dispatch(Events::ADMIN_SETTINGS_SAVED, [
            'action' => '修改站点设置',
            'admin_id' => $_SESSION['user_id'],
            'detail' => '修改了站点设置',
            'target_type' => 'settings',
        ]);
        $this->respondMutation(true, '设置已保存', fn() => $this->renderSettingsPage());
    }

    /**
     * 发送测试邮件
     *
     * 只探测、不改数据，因此只回一条提示、不重绘页面
     * （前端按钮由 AdminUi 发 AJAX，自己弹 layer.msg）。
     */
    public function testEmail(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $config = [
            'smtp_host' => $input['smtp_host'] ?? '',
            'smtp_port' => $input['smtp_port'] ?? '465',
            'smtp_user' => $input['smtp_user'] ?? '',
            'smtp_pass' => $input['smtp_pass'] ?? '',
            'smtp_from' => $input['smtp_from'] ?? '',
            'smtp_from_name' => $input['smtp_from_name'] ?? 'AMuBBS',
            'smtp_encryption' => $input['smtp_encryption'] ?? 'ssl',
        ];

        if (empty($config['smtp_host']) || empty($config['smtp_user']) || empty($config['smtp_pass'])) {
            $this->respondSettingsProbe(false, '请先填写 SMTP 服务器、用户名和密码');
            return;
        }

        $to = $config['smtp_from'] ?: $config['smtp_user'];

        try {
            $mailer = new \Core\Mailer($config);
            $mailer->send($to, 'AMuBBS 邮件测试', '<h3>邮件配置成功</h3><p>如果你收到这封邮件，说明 SMTP 配置正确。</p>');
        } catch (\Throwable $e) {
            error_log('[Admin:System] test mail failed: ' . $e->getMessage());
            $this->respondSettingsProbe(false, '邮件发送失败，请检查 SMTP 配置是否正确（详细错误已记录到日志）');
            return;
        }

        $this->respondSettingsProbe(true, "测试邮件已发送到 {$to}");
    }

    /**
     * 试连/试发结果响应：后台请求走下面的 JSON 分支（AdminUi 自己弹 layer.msg）；
     * isHtmx() 分支是 layuimini 之前那版架构的遗留。
     */
    private function respondSettingsProbe(bool $ok, string $message): void
    {
        if ($this->isHtmx()) {
            $this->htmxFlash($message, $ok ? 'success' : 'danger');
            return;
        }

        if ($ok) {
            $this->success($message);
        } else {
            $this->error($message);
        }
    }

    // ==================== 系统信息 ====================

    public function systemInfo(): void
    {
        $this->requireAdmin();

        $mysqlVersion = Database::serverVersion() ?: '-';

        $redisVersion = '-';
        try {
            if (class_exists('Redis')) {
                $config = require APP_PATH . 'config/cache.php';
                $redis = new \Redis();
                $redis->connect($config['redis']['host'], $config['redis']['port'], 3);
                if (!empty($config['redis']['password'])) {
                    $redis->auth($config['redis']['password']);
                }
                if (!empty($config['redis']['database'])) {
                    $redis->select($config['redis']['database']);
                }
                $info = $redis->info();
                $redisVersion = $info['redis_version'] ?? '-';
            }
        } catch (\Throwable $e) {
            error_log('[Admin:System] redis version: ' . $e->getMessage());
        }

        $diskFree = @disk_free_space(APP_PATH);
        $diskTotal = @disk_total_space(APP_PATH);

        $info = [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'mysql_version' => $mysqlVersion,
            'redis_version' => $redisVersion,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '-',
            'os' => PHP_OS,
            'opcache' => extension_loaded('Zend OPcache') ? '已启用' : '未启用',
            'redis_ext' => extension_loaded('redis') ? '已安装' : '未安装',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time') . 's',
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'disk_free' => $diskFree ? round($diskFree / 1073741824, 2) . ' GB' : '-',
            'disk_total' => $diskTotal ? round($diskTotal / 1073741824, 2) . ' GB' : '-',
        ];

        $this->renderAdmin('admin/system_info', [
            'pageTitle' => '系统信息',
            'info' => $info,
        ], 'system-info');
    }

    // ==================== 缓存管理 ====================

    public function cacheManage(): void
    {
        $this->requireAdmin();
        $this->renderCachePage();
    }

    /**
     * 读取 Redis 运行信息；未安装扩展或连不上时返回 null
     */
    private function fetchRedisInfo(): ?array
    {
        try {
            if (!class_exists('Redis')) {
                return null;
            }

            $config = require APP_PATH . 'config/cache.php';
            $redis = new \Redis();
            $redis->connect($config['redis']['host'], $config['redis']['port'], 3);
            if (!empty($config['redis']['password'])) {
                $redis->auth($config['redis']['password']);
            }
            if (!empty($config['redis']['database'])) {
                $redis->select($config['redis']['database']);
            }
            $info = $redis->info();

            return [
                'version' => $info['redis_version'] ?? '-',
                'used_memory_human' => $info['used_memory_human'] ?? '-',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'total_keys' => $redis->dbSize(),
                'uptime_days' => round(($info['uptime_in_seconds'] ?? 0) / 86400, 1),
                'hit_rate' => ($info['keyspace_hits'] ?? 0) + ($info['keyspace_misses'] ?? 0) > 0
                    ? round(($info['keyspace_hits'] ?? 0) / (($info['keyspace_hits'] ?? 0) + ($info['keyspace_misses'] ?? 0)) * 100, 1)
                    : 0,
            ];
        } catch (\Throwable $e) {
            error_log('[Admin:System] redis info: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 渲染缓存管理页面片段（GET 与清除后的刷新共用同一个渲染路径）
     */
    private function renderCachePage(): void
    {
        $this->renderAdmin('admin/cache', [
            'pageTitle'   => '缓存管理',
            'redisInfo'   => $this->fetchRedisInfo(),
            'cacheDriver' => Cache::driver(),
        ], 'cache');
    }

    public function cacheClear(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $type = $input['type'] ?? 'all';
        $validTypes = ['forums', 'threads', 'users', 'all'];
        if (!in_array($type, $validTypes, true)) {
            $this->respondMutation(false, '无效的缓存类型', fn() => $this->renderCachePage());
            return;
        }

        $labels = ['forums' => '板块', 'threads' => '帖子', 'users' => '用户', 'all' => '全部'];

        try {
            switch ($type) {
                case 'forums':
                    Cache::deletePattern('forums:*');
                    Cache::deletePattern('forum:*');
                    break;
                case 'threads':
                    Cache::deletePattern('threads:*');
                    Cache::deletePattern('thread:*');
                    break;
                case 'users':
                    Cache::deletePattern('user:*');
                    break;
                case 'all':
                default:
                    // 先递增版本号（O(1) 让所有旧 key 立即逻辑失效、跨进程也生效），
                    // 再物理清盘回收空间。原来的 deletePattern('*') 在文件驱动下要
                    // 递归遍历整个缓存目录并逐个文件读 key 做正则匹配。
                    Cache::bumpVersion();
                    Cache::flush();
                    break;
            }
            Event::dispatch(Events::ADMIN_CACHE_CLEARED, [
                'action' => '清除缓存',
                'admin_id' => $_SESSION['user_id'],
                'detail' => "清除缓存类型:{$type}",
                'target_type' => 'cache',
            ]);

            $this->respondMutation(
                true,
                ($labels[$type] ?? '') . '缓存已清除',
                fn() => $this->renderCachePage()
            );
        } catch (\Throwable $e) {
            error_log('[Admin:System] cache clear failed: ' . $e->getMessage());
            $this->respondMutation(
                false,
                '缓存清除失败（详细错误已记录到日志）',
                fn() => $this->renderCachePage()
            );
        }
    }

    // ==================== 集群管理 ====================

    public function cluster(): void
    {
        $this->requireAdmin();
        $this->renderClusterPage();
    }

    /**
     * 集群节点（带在线状态与已解析的 config）
     */
    private function fetchClusterNodes(): array
    {
        $nodes = [];
        try {
            $nodes = \App\Models\ClusterNode::allOrdered();
            foreach ($nodes as &$node) {
                $node['status_info'] = $this->checkNodeStatus($node);
                $node['config_data'] = json_decode($node['config'] ?? '{}', true) ?: [];
            }
            unset($node);
        } catch (\Throwable $e) {
            error_log('[Admin:System] cluster nodes: ' . $e->getMessage());
        }

        return $nodes;
    }

    /**
     * 渲染集群管理页面片段（GET 与增删改后的刷新共用同一渲染路径）
     */
    private function renderClusterPage(): void
    {
        $this->renderAdmin('admin/cluster', [
            'pageTitle' => '集群管理',
            'nodes'     => $this->fetchClusterNodes(),
        ], 'cluster');
    }

    /**
     * 节点表单片段（layer iframe 弹层用）
     *
     * ?id=N 编辑；?id=0 或省略为新增
     */
    public function clusterForm(): void
    {
        $this->requireAdmin();

        $id = max(0, (int)($_GET['id'] ?? 0));
        $node = null;

        if ($id > 0) {
            $node = \App\Models\ClusterNode::findById($id);
            if (!$node) {
                http_response_code(404);
                echo '<blockquote class="layui-elem-quote layui-quote-nm">节点不存在</blockquote>';
                return;
            }
            $node['config_data'] = json_decode($node['config'] ?? '{}', true) ?: [];
        }

        $this->renderAdmin('admin/partials/cluster_form', [
            'pageTitle' => $node !== null ? '编辑节点' : '添加节点',
            'node'   => $node,
            'isEdit' => $node !== null,
        ], 'cluster');
    }

    /**
     * 校验节点类型
     */
    private function isValidNodeType(string $type): bool
    {
        return in_array($type, ['web', 'mysql', 'redis'], true);
    }

    /**
     * 校验主机与端口，并拒绝内网地址（防 SSRF）
     *
     * @return array{0: bool, 1: string, 2: string}  [是否通过, 错误信息, 解析出的 IP]
     */
    private function resolveNodeHost(string $host, int $port): array
    {
        if ($host === '' || $port <= 0 || $port > 65535) {
            return [false, '请填写完整信息', ''];
        }

        // 解析主机名并过滤内网地址，防止 SSRF
        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return [false, '无法解析主机名', ''];
        }
        if ($this->isPrivateIp($ip)) {
            return [false, '不允许使用内网地址', ''];
        }

        return [true, '', $ip];
    }

    /**
     * 归一化节点认证配置
     *
     * 兼容两种来源：
     *   - 旧 JSON 调用方：config => ['username' => ..., 'password' => ...]
     *   - 新表单：扁平的 config_username / config_password / config_redis_password
     *
     * @param bool $keepPassword 编辑时密码留空表示不修改（沿用已有值）
     */
    private function normalizeNodeConfig(array $input, string $type, array $existing = []): array
    {
        $nested = is_array($input['config'] ?? null) ? $input['config'] : [];

        if ($type === 'mysql') {
            $username = trim((string)($input['config_username'] ?? ($nested['username'] ?? ($existing['username'] ?? ''))));
            $password = (string)($input['config_password'] ?? ($nested['password'] ?? ''));

            $config = [];
            if ($username !== '') {
                $config['username'] = $username;
            }
            if ($password !== '') {
                $config['password'] = $password;
            } elseif (!empty($existing['password'])) {
                // 留空 = 不修改，保留原密码（旧实现会把密码清空）
                $config['password'] = $existing['password'];
            }

            return $config;
        }

        if ($type === 'redis') {
            $password = (string)($input['config_redis_password'] ?? ($nested['password'] ?? ''));

            if ($password !== '') {
                return ['password' => $password];
            }
            if (!empty($existing['password'])) {
                return ['password' => $existing['password']];
            }

            return [];
        }

        return [];
    }

    public function clusterCreate(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $type = (string)($input['type'] ?? '');
        $name = trim((string)($input['name'] ?? ''));
        $host = trim((string)($input['host'] ?? ''));
        $port = (int)($input['port'] ?? 0);
        $weight = max(1, min(10, (int)($input['weight'] ?? 1)));

        if (!$this->isValidNodeType($type)) {
            $this->respondMutation(false, '无效的节点类型', fn() => $this->renderClusterPage());
            return;
        }
        if ($name === '') {
            $this->respondMutation(false, '请填写节点名称', fn() => $this->renderClusterPage());
            return;
        }

        [$ok, $err] = $this->resolveNodeHost($host, $port);
        if (!$ok) {
            $this->respondMutation(false, $err, fn() => $this->renderClusterPage());
            return;
        }

        $config = json_encode($this->normalizeNodeConfig($input, $type), JSON_UNESCAPED_UNICODE);

        \App\Models\ClusterNode::create($type, $name, $host, $port, $weight, $config);

        Event::dispatch(Events::ADMIN_CLUSTER_NODE_CREATED, [
            'action' => '添加集群节点',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "添加{$type}节点:{$name}({$host}:{$port})",
            'target_type' => 'cluster_node',
        ]);
        $this->respondMutation(true, '节点添加成功', fn() => $this->renderClusterPage());
    }

    public function clusterToggle(): void
    {
        $this->requireAdmin();

        $id = (int)($this->input()['id'] ?? 0);

        $node = $id > 0 ? \App\Models\ClusterNode::findById($id) : null;
        if (!$node) {
            $this->respondMutation(false, '节点不存在', fn() => $this->renderClusterPage());
            return;
        }

        $newStatus = (int)$node['status'] === 1 ? 0 : 1;
        \App\Models\ClusterNode::setStatus($id, $newStatus);
        Cache::delete(\App\Models\ClusterNode::statusCacheKey($id));

        $statusText = $newStatus === 1 ? '启用' : '禁用';
        Event::dispatch(Events::ADMIN_CLUSTER_NODE_TOGGLED, [
            'action' => "{$statusText}集群节点",
            'admin_id' => $_SESSION['user_id'],
            'detail' => "{$statusText}节点:{$node['name']}({$node['host']}:{$node['port']})",
            'target_type' => 'cluster_node',
            'target_id' => $id,
        ]);
        $this->respondMutation(true, "节点已{$statusText}", fn() => $this->renderClusterPage());
    }

    public function clusterUpdate(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $host = trim((string)($input['host'] ?? ''));
        $port = (int)($input['port'] ?? 0);
        $weight = max(1, min(10, (int)($input['weight'] ?? 1)));

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderClusterPage());
            return;
        }

        $node = \App\Models\ClusterNode::findById($id);
        if (!$node) {
            $this->respondMutation(false, '节点不存在', fn() => $this->renderClusterPage());
            return;
        }

        if ($name === '') {
            $this->respondMutation(false, '请填写节点名称', fn() => $this->renderClusterPage());
            return;
        }

        [$ok, $err] = $this->resolveNodeHost($host, $port);
        if (!$ok) {
            $this->respondMutation(false, $err, fn() => $this->renderClusterPage());
            return;
        }

        // 类型不可改（与旧版一致：编辑弹窗里类型是只读的）
        $type = (string)$node['type'];
        $existing = json_decode($node['config'] ?? '{}', true) ?: [];
        $config = json_encode($this->normalizeNodeConfig($input, $type, $existing), JSON_UNESCAPED_UNICODE);

        \App\Models\ClusterNode::update($id, $name, $host, $port, $weight, $config);

        Cache::delete(\App\Models\ClusterNode::statusCacheKey($id));
        Event::dispatch(Events::ADMIN_CLUSTER_NODE_TOGGLED, [
            'action' => '编辑集群节点',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "编辑节点:{$name}({$host}:{$port})",
            'target_type' => 'cluster_node',
            'target_id' => $id,
        ]);
        $this->respondMutation(true, '节点已更新', fn() => $this->renderClusterPage());
    }

    public function clusterDelete(): void
    {
        $this->requireAdmin();

        $id = (int)($this->input()['id'] ?? 0);
        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderClusterPage());
            return;
        }

        $node = \App\Models\ClusterNode::findById($id);
        \App\Models\ClusterNode::deleteById($id);
        Cache::delete(\App\Models\ClusterNode::statusCacheKey($id));

        Event::dispatch(Events::ADMIN_CLUSTER_NODE_DELETED, [
            'action' => '删除集群节点',
            'admin_id' => $_SESSION['user_id'],
            'detail' => $node ? "删除节点:{$node['name']}({$node['host']}:{$node['port']})" : "删除节点ID:{$id}",
            'target_type' => 'cluster_node',
            'target_id' => $id,
        ]);
        $this->respondMutation(true, '节点已删除', fn() => $this->renderClusterPage());
    }

    /**
     * 测试节点连接（不走缓存）
     *
     * 支持两种调用：
     *   - 传 id：直接用库里已存的类型/主机/端口/认证信息（前端不再把密码写进 HTML）
     *   - 传 type/host/port/config：用于表单里保存前试连
     *
     * 这是「只探测、不改数据」的操作，因此只回一条提示、不重绘页面
     * （前端按钮由 AdminUi 发 AJAX，自己弹 layer.msg）。
     */
    public function clusterTest(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);

        if ($id > 0) {
            $node = \App\Models\ClusterNode::findById($id);
            if (!$node) {
                $this->respondClusterTest(false, '节点不存在');
                return;
            }
            $type   = (string)$node['type'];
            $host   = (string)$node['host'];
            $port   = (int)$node['port'];
            $config = json_decode($node['config'] ?? '{}', true) ?: [];
        } else {
            $type   = (string)($input['type'] ?? '');
            $host   = trim((string)($input['host'] ?? ''));
            $port   = (int)($input['port'] ?? 0);
            $config = $this->normalizeNodeConfig($input, $type);
        }

        if (!$this->isValidNodeType($type)) {
            $this->respondClusterTest(false, '无效的节点类型');
            return;
        }
        if ($host === '' || $port <= 0 || $port > 65535) {
            $this->respondClusterTest(false, '请填写主机和端口');
            return;
        }

        // 解析主机名并过滤内网地址，防止 SSRF
        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            $this->respondClusterTest(false, '无法解析主机名');
            return;
        }
        if ($this->isPrivateIp($ip)) {
            $this->respondClusterTest(false, '不允许使用内网地址');
            return;
        }

        $start = microtime(true);
        try {
            switch ($type) {
                case 'web':
                    $fp = @fsockopen($ip, $port, $errno, $errstr, 3);
                    if (!$fp) {
                        $this->respondClusterTest(false, '连接失败: ' . $errstr);
                        return;
                    }
                    fclose($fp);
                    break;
                case 'mysql':
                    $dsn = 'mysql:host=' . $ip . ';port=' . $port;
                    $pdo = new \PDO(
                        $dsn,
                        $config['username'] ?? 'root',
                        $config['password'] ?? '',
                        [\PDO::ATTR_TIMEOUT => 3]
                    );
                    $pdo->query('SELECT 1');
                    break;
                case 'redis':
                    if (!class_exists('Redis')) {
                        $this->respondClusterTest(false, '服务器未安装 Redis 扩展');
                        return;
                    }
                    $redis = new \Redis();
                    $redis->connect($ip, $port, 3);
                    if (!empty($config['password'])) {
                        $redis->auth($config['password']);
                    }
                    $redis->ping();
                    break;
            }
        } catch (\Throwable $e) {
            error_log('[Admin:Cluster] test failed: ' . $e->getMessage());
            $this->respondClusterTest(false, '连接失败，请检查主机和端口配置');
            return;
        }

        $ms = round((microtime(true) - $start) * 1000, 2);
        $this->respondClusterTest(true, "连接成功，响应时间 {$ms}ms");
    }

    /**
     * 试连结果响应：后台请求走下面的 JSON 分支（AdminUi 自己弹 layer.msg）；
     * isHtmx() 分支是 layuimini 之前那版架构的遗留。
     */
    private function respondClusterTest(bool $ok, string $message): void
    {
        if ($this->isHtmx()) {
            $this->htmxFlash($message, $ok ? 'success' : 'danger');
            return;
        }

        if ($ok) {
            $this->success($message);
        } else {
            $this->error($message, 200);
        }
    }

    private function checkNodeStatus(array $node): array
    {
        $cacheKey = \App\Models\ClusterNode::statusCacheKey((int)$node['id']);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) return $cached;

        $status = ['online' => false, 'response_time' => 0, 'last_check' => time()];
        $start = microtime(true);

        $ip = gethostbyname($node['host']);

        try {
            $config = json_decode($node['config'] ?? '{}', true) ?: [];
            switch ($node['type']) {
                case 'web':
                    $fp = @fsockopen($ip, (int)$node['port'], $errno, $errstr, 3);
                    if ($fp) { fclose($fp); $status['online'] = true; }
                    break;
                case 'mysql':
                    // 使用解析后的 IP 避免 DSN 注入
                    $dsn = 'mysql:host=' . $ip . ';port=' . (int)$node['port'];
                    $pdo = new \PDO(
                        $dsn,
                        $config['username'] ?? 'root',
                        $config['password'] ?? '',
                        [\PDO::ATTR_TIMEOUT => 3]
                    );
                    $pdo->query('SELECT 1');
                    $status['online'] = true;
                    break;
                case 'redis':
                    if (class_exists('Redis')) {
                        $redis = new \Redis();
                        $redis->connect($ip, (int)$node['port'], 3);
                        if (!empty($config['password'])) {
                            $redis->auth($config['password']);
                        }
                        $redis->ping();
                        $status['online'] = true;
                    }
                    break;
            }
        } catch (\Throwable $e) {
            error_log('[Admin:System] node check failed: ' . $e->getMessage());
            $status['error'] = $e->getMessage();
        }

        $status['response_time'] = round((microtime(true) - $start) * 1000, 2);
        Cache::set($cacheKey, $status, 60);
        return $status;
    }

    // ==================== IP 黑名单 ====================

    /**
     * 检查是否为内网/保留 IP，防止 SSRF
     */
    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    public function ipBlacklist(): void
    {
        $this->requireAdmin();
        $this->renderIpBlacklistPage();
    }

    /**
     * IP 黑名单数据（页面与 JSON API 共用同一份查询逻辑）
     *
     * @return array{rows: array, total: int}
     */
    private function fetchIpBlacklist(int $page, int $limit, string $search): array
    {
        return \App\Models\IpBlacklist::adminList($search, $page, $limit);
    }

    /**
     * 渲染 IP 黑名单页面片段（GET 与增删后的刷新共用同一个渲染路径）
     */
    private function renderIpBlacklistPage(): void
    {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $search = trim($_GET['search'] ?? '');

        $result = $this->fetchIpBlacklist($page, $limit, $search);

        $this->renderAdmin('admin/ip_blacklist', [
            'pageTitle' => 'IP 黑名单',
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'pages'     => max(1, (int)ceil($result['total'] / $limit)),
            'search'    => $search,
        ], 'ip-blacklist');
    }

    /**
     * IP 黑名单列表 API（保留，供外部 AJAX 调用）
     */
    public function ipBlacklistApi(): void
    {
        $this->requireAdmin();

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $search = trim($_GET['search'] ?? '');

        $result = $this->fetchIpBlacklist($page, $limit, $search);
        $this->jsonTable($result['rows'], $result['total']);
    }

    public function ipBlacklistCreate(): void
    {
        $this->requireAdmin();

        $input    = $this->input();
        $ip       = trim($input['ip'] ?? '');
        $reason   = trim($input['reason'] ?? '');
        $duration = (int)($input['duration'] ?? 0);

        if ($ip === '') {
            $this->respondMutation(false, 'IP 地址不能为空', fn() => $this->renderIpBlacklistPage());
            return;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->respondMutation(false, 'IP 地址格式不正确', fn() => $this->renderIpBlacklistPage());
            return;
        }

        // expire_at = 0 表示永久；走服务层是为了复用它的 upsert（重复 IP 不会撞唯一键）
        $expireAt = $duration > 0 ? time() + $duration : 0;

        \App\Services\IpBlacklistService::add($ip, $reason, $expireAt);
        Event::dispatch(Events::ADMIN_IP_BLOCKED, [
            'action' => '封禁IP',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "封禁 IP:{$ip} 原因:{$reason}",
            'target_type' => 'ip_blacklist',
        ]);

        $this->respondMutation(true, "已封禁 {$ip}", fn() => $this->renderIpBlacklistPage());
    }

    public function ipBlacklistDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderIpBlacklistPage());
            return;
        }

        // 先取出 IP：审计日志里带上具体地址比只记 ID 有用，移除也要按 IP 走服务
        $entry = \App\Models\IpBlacklist::findById($id);
        if (!$entry) {
            $this->respondMutation(false, '记录不存在', fn() => $this->renderIpBlacklistPage());
            return;
        }
        \App\Services\IpBlacklistService::remove((string)$entry['ip']);
        Event::dispatch(Events::ADMIN_IP_UNBLOCKED, [
            'action' => '解封IP',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "删除 IP 黑名单 ID:{$id}（IP:{$entry['ip']}）",
            'target_type' => 'ip_blacklist',
            'target_id' => $id,
        ]);

        $this->respondMutation(true, '已解除封禁', fn() => $this->renderIpBlacklistPage());
    }

    // ==================== 友情链接 ====================

    public function friendLinks(): void
    {
        $this->requireAdmin();
        $this->renderFriendLinksPage();
    }

    /**
     * 友情链接列表（页面与 JSON API 共用同一份查询逻辑）
     */
    private function fetchFriendLinks(): array
    {
        try {
            return \App\Models\FriendLink::allOrdered();
        } catch (\Throwable $e) {
            error_log('[Admin:System] fetchFriendLinks: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 渲染友情链接页面片段（GET 与增删后的刷新共用同一个渲染路径）
     */
    private function renderFriendLinksPage(): void
    {
        $this->renderAdmin('admin/friend_links', [
            'pageTitle' => '友情链接',
            'rows'      => $this->fetchFriendLinks(),
        ], 'friend-links');
    }

    /**
     * 友情链接列表 API（保留，供外部 AJAX 调用）
     */
    public function friendLinksApi(): void
    {
        $this->requireAdmin();

        $rows = $this->fetchFriendLinks();
        $this->jsonTable($rows, count($rows));
    }

    public function friendLinkCreate(): void
    {
        $this->requireAdmin();

        $input     = $this->input();
        $name      = trim($input['name'] ?? '');
        $url       = trim($input['url'] ?? '');
        $logo      = trim($input['logo'] ?? '');
        $sortOrder = (int)($input['sort_order'] ?? 0);

        if ($name === '' || $url === '') {
            $this->respondMutation(false, '名称和链接不能为空', fn() => $this->renderFriendLinksPage());
            return;
        }

        // 验证 URL 格式，防止 javascript: 等协议注入
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            $this->respondMutation(false, '链接格式不正确，请使用 http:// 或 https:// 开头', fn() => $this->renderFriendLinksPage());
            return;
        }

        \App\Models\FriendLink::create($name, $url, $logo, $sortOrder);
        $this->respondMutation(true, "已添加「{$name}」", fn() => $this->renderFriendLinksPage());
    }

    public function friendLinkDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderFriendLinksPage());
            return;
        }

        \App\Models\FriendLink::deleteById($id);
        $this->respondMutation(true, '已删除链接', fn() => $this->renderFriendLinksPage());
    }

    // ==================== 导航管理 ====================

    public function navigation(): void
    {
        $this->requireAdmin();
        $this->renderNavigationPage();
    }

    /**
     * 导航分类（每个分类带 links 子数组）
     */
    private function fetchNavigation(): array
    {
        $categories = \App\Models\NavCategory::allOrdered();
        $catIds = array_column($categories, 'id');

        $linksByCategory = [];
        if (!empty($catIds)) {
            $allLinks = \App\Models\NavLink::getByCategories($catIds);
            foreach ($allLinks as $link) {
                $linksByCategory[$link['category_id']][] = $link;
            }
        }

        foreach ($categories as &$cat) {
            $cat['links'] = $linksByCategory[$cat['id']] ?? [];
        }
        unset($cat);

        return $categories;
    }

    /**
     * 渲染导航管理页面片段（GET 与增删改后的刷新共用同一渲染路径）
     */
    private function renderNavigationPage(): void
    {
        $this->renderAdmin('admin/navigation', [
            'pageTitle'  => '导航管理',
            'categories' => $this->fetchNavigation(),
        ], 'navigation');
    }

    /**
     * 分类表单片段（layer iframe 弹层用）
     */
    public function navCategoryForm(): void
    {
        $this->requireAdmin();

        $id = max(0, (int)($_GET['id'] ?? 0));
        $category = null;

        if ($id > 0) {
            $category = \App\Models\NavCategory::findById($id);
            if (!$category) {
                http_response_code(404);
                echo '<blockquote class="layui-elem-quote layui-quote-nm">分类不存在</blockquote>';
                return;
            }
        }

        $this->renderAdmin('admin/partials/nav_category_form', [
            'pageTitle' => $category !== null ? '编辑分类' : '添加分类',
            'category' => $category,
            'isEdit'   => $category !== null,
        ], 'navigation');
    }

    /**
     * 链接表单片段（layer iframe 弹层用）
     *
     * ?category_id=N 用于新增时预选分类
     */
    public function navLinkForm(): void
    {
        $this->requireAdmin();

        $id = max(0, (int)($_GET['id'] ?? 0));
        $presetCategoryId = max(0, (int)($_GET['category_id'] ?? 0));
        $link = null;

        if ($id > 0) {
            $link = \App\Models\NavLink::findById($id);
            if (!$link) {
                http_response_code(404);
                echo '<blockquote class="layui-elem-quote layui-quote-nm">链接不存在</blockquote>';
                return;
            }
            $presetCategoryId = (int)$link['category_id'];
        }

        $categories = \App\Models\NavCategory::options();

        $this->renderAdmin('admin/partials/nav_link_form', [
            'pageTitle'        => $link !== null ? '编辑链接' : '添加链接',
            'link'             => $link,
            'isEdit'           => $link !== null,
            'categories'       => $categories,
            'selectedCategory' => $presetCategoryId,
        ], 'navigation');
    }

    /**
     * 校验链接字段
     *
     * @return array{0: bool, 1: string, 2: array}
     */
    private function collectNavLinkInput(array $input, bool $requireId): array
    {
        $id = (int)($input['id'] ?? 0);
        if ($requireId && $id <= 0) {
            return [false, '参数错误', []];
        }

        $categoryId = (int)($input['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return [false, '请选择分类', []];
        }

        $name = trim((string)($input['name'] ?? ''));
        $url  = trim((string)($input['url'] ?? ''));

        if ($name === '' || $url === '') {
            return [false, '名称和链接不能为空', []];
        }
        if (mb_strlen($name) > 50) {
            return [false, '链接名称不能超过 50 个字符', []];
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return [false, '链接格式不正确，请使用 http:// 或 https:// 开头的地址', []];
        }
        if (mb_strlen($url) > 500) {
            return [false, '链接不能超过 500 个字符', []];
        }

        return [true, '', [
            'id'          => $id,
            'category_id' => $categoryId,
            'name'        => $name,
            'url'         => $url,
            'description' => trim((string)($input['description'] ?? '')),
            'icon'        => trim((string)($input['icon'] ?? '')),
            'rank'        => (int)($input['rank'] ?? 0),
        ]];
    }

    public function navCategoryCreate(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            $this->respondMutation(false, '分类名称不能为空', fn() => $this->renderNavigationPage());
            return;
        }
        if (mb_strlen($name) > 50) {
            $this->respondMutation(false, '分类名称不能超过 50 个字符', fn() => $this->renderNavigationPage());
            return;
        }

        \App\Models\NavCategory::create($name, trim((string)($input['icon'] ?? '')), (int)($input['rank'] ?? 0));
        $this->respondMutation(true, '分类已创建', fn() => $this->renderNavigationPage());
    }

    public function navCategoryUpdate(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            $this->respondMutation(false, '参数错误', fn() => $this->renderNavigationPage());
            return;
        }
        if (mb_strlen($name) > 50) {
            $this->respondMutation(false, '分类名称不能超过 50 个字符', fn() => $this->renderNavigationPage());
            return;
        }

        \App\Models\NavCategory::update($id, $name, trim((string)($input['icon'] ?? '')), (int)($input['rank'] ?? 0));
        $this->respondMutation(true, '分类已更新', fn() => $this->renderNavigationPage());
    }

    public function navCategoryDelete(): void
    {
        $this->requireAdmin();

        $id = (int)($this->input()['id'] ?? 0);
        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderNavigationPage());
            return;
        }

        \App\Models\NavCategory::deleteWithLinks($id);
        $this->respondMutation(true, '分类已删除', fn() => $this->renderNavigationPage());
    }

    public function navLinkCreate(): void
    {
        $this->requireAdmin();

        [$ok, $msg, $f] = $this->collectNavLinkInput($this->input(), false);
        if (!$ok) {
            $this->respondMutation(false, $msg, fn() => $this->renderNavigationPage());
            return;
        }

        // 分类必须存在，否则会建出一条永远显示不出来的孤立链接
        $exists = \App\Models\NavCategory::exists((int)$f['category_id']);
        if (!$exists) {
            $this->respondMutation(false, '所选分类不存在', fn() => $this->renderNavigationPage());
            return;
        }

        \App\Models\NavLink::create($f['category_id'], $f['name'], $f['url'], $f['description'], $f['icon'], $f['rank']);
        $this->respondMutation(true, '链接已创建', fn() => $this->renderNavigationPage());
    }

    public function navLinkUpdate(): void
    {
        $this->requireAdmin();

        [$ok, $msg, $f] = $this->collectNavLinkInput($this->input(), true);
        if (!$ok) {
            $this->respondMutation(false, $msg, fn() => $this->renderNavigationPage());
            return;
        }

        $exists = \App\Models\NavCategory::exists((int)$f['category_id']);
        if (!$exists) {
            $this->respondMutation(false, '所选分类不存在', fn() => $this->renderNavigationPage());
            return;
        }

        \App\Models\NavLink::update($f['id'], $f['category_id'], $f['name'], $f['url'], $f['description'], $f['icon'], $f['rank']);
        $this->respondMutation(true, '链接已更新', fn() => $this->renderNavigationPage());
    }

    public function navLinkDelete(): void
    {
        $this->requireAdmin();

        $id = (int)($this->input()['id'] ?? 0);
        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderNavigationPage());
            return;
        }

        \App\Models\NavLink::deleteById($id);
        $this->respondMutation(true, '链接已删除', fn() => $this->renderNavigationPage());
    }

    // ==================== 操作日志 ====================

    /**
     * 版主日志数据（页面与 JSON API 共用同一份查询逻辑）
     *
     * @return array{rows: array, total: int}
     */
    private function fetchModLogs(int $page, int $limit, array $filters): array
    {
        // 去掉空条件，避免把 '' / 0 也当成筛选项传给查询
        $filters = array_filter($filters, static function ($v) {
            return $v !== '' && $v !== 0 && $v !== null;
        });

        return [
            'rows'  => \App\Services\LogService::getModLogs($page, $limit, $filters),
            'total' => (int)\App\Services\LogService::countModLogs($filters),
        ];
    }

    /**
     * 操作日志列表 API（版主日志，保留供外部 AJAX 调用）
     */
    public function logsApi(): void
    {
        $this->requireAdmin();

        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $filters = [
            'action'      => trim($_GET['action'] ?? ''),
            'user_id'     => (int)($_GET['user_id'] ?? 0),
            'target_type' => trim($_GET['target_type'] ?? ''),
            'date_from'   => trim($_GET['date_from'] ?? ''),
            'date_to'     => trim($_GET['date_to'] ?? ''),
        ];

        $result = $this->fetchModLogs($page, $limit, $filters);
        $this->jsonTable($result['rows'], $result['total']);
    }

    public function logs(): void
    {
        $this->requireAdmin();

        $filter = $_GET['filter'] ?? '';

        $date = $_GET['date'] ?? date('Y-m-d');
        // 验证日期格式，防止路径遍历
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        // ---------- 版主操作日志（服务端渲染表格）----------
        if ($filter === 'mod') {
            $page  = max(1, (int)($_GET['page'] ?? 1));
            $limit = 30;

            $filters = [
                'action'      => trim($_GET['action'] ?? ''),
                'target_type' => trim($_GET['target_type'] ?? ''),
            ];

            $result = $this->fetchModLogs($page, $limit, $filters);

            $this->renderAdmin('admin/logs', [
                'pageTitle'   => '操作日志',
                'filter'      => 'mod',
                'actionTypes' => \App\Services\LogService::getModActionTypes(),
                'rows'        => $result['rows'],
                'total'       => $result['total'],
                'page'        => $page,
                'pages'       => max(1, (int)ceil($result['total'] / $limit)),
                'search'      => $filters,
                'date'        => $date,
                'logs'        => [],
            ], 'logs');

            return;
        }

        // ---------- 文件日志 ----------
        $logFile = APP_PATH . 'storage/logs/' . $date . '.log';

        $logs = [];
        if (file_exists($logFile)) {
            // 限制读取大小，防止大日志文件导致内存溢出
            $maxSize = 2 * 1024 * 1024; // 2MB
            $fileSize = filesize($logFile);
            if ($fileSize > $maxSize) {
                $fp = fopen($logFile, 'r');
                fseek($fp, $fileSize - $maxSize);
                fgets($fp); // 跳过不完整的第一行
                $content = fread($fp, $maxSize);
                fclose($fp);
            } else {
                $content = file_get_contents($logFile);
            }
            $logs = array_filter(array_reverse(explode("\n", trim($content))));
        }

        $this->renderAdmin('admin/logs', [
            'pageTitle'   => '操作日志',
            'filter'      => '',
            'logs'        => $logs,
            'date'        => $date,
            'rows'        => [],
            'total'       => 0,
            'page'        => 1,
            'pages'       => 1,
            'search'      => [],
            'actionTypes' => [],
        ], 'logs');
    }

}
