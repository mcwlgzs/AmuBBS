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

    public function settings(): void
    {
        $this->requireAdmin();

        $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $this->render('admin/settings', [
            'pageTitle' => '系统设置',
            'settings' => $settings,
        ]);
    }

    public function settingsSave(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);

        // 运行级别值域校验（0-5）
        if (isset($input['site_runlevel'])) {
            $rl = (int)$input['site_runlevel'];
            if ($rl < 0 || $rl > 5) {
                $this->error('运行级别无效，请选择 0-5 之间的值');
                return;
            }
        }

        $allowed = ['site_name', 'site_url', 'site_description', 'site_keywords', 'icp_number',
                    'cdn_url', 'site_runlevel', 'site_maintenance_msg', 'admin_bind_ip',
                    'watermark_enabled', 'watermark_text', 'watermark_position', 'watermark_opacity',
                    'image_max_width', 'image_thumb_width', 'cron_key',
                    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name', 'smtp_encryption',
                    'ip_limit_thread', 'ip_limit_post', 'ip_limit_register', 'ip_limit_upload',
                    'announcement_hide_duration',
                    'captcha_enabled', 'captcha_type', 'captcha_scenes',
                    'threads_per_page',
                    'rate_limit_global_max', 'rate_limit_global_window',
                    'rate_limit_strict_max', 'rate_limit_strict_window',
                    'rate_limit_search_max', 'rate_limit_search_window',
                    'url_html_suffix',
                    'auto_avatar_enabled', 'auto_avatar_overwrite',
                    'emoji_enabled',
                    'social_login_github_enabled', 'social_login_github_client_id', 'social_login_github_client_secret',
                    'social_login_google_enabled', 'social_login_google_client_id', 'social_login_google_client_secret',
                    'social_login_wechat_enabled', 'social_login_wechat_app_id', 'social_login_wechat_app_secret',
                    'social_login_qq_enabled', 'social_login_qq_app_id', 'social_login_qq_app_key',
                    'editor_type', 'tinymce_enable_post_editor', 'tinymce_enable_reply_editor'];
        $now = time();

        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $value = trim((string)($input[$key] ?? ''));
                Database::execute(
                    "INSERT INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = ?, `updated_at` = ?",
                    [$key, $value, $now, $value, $now]
                );
            }
        }

        Cache::delete('settings:all');
        Event::dispatch(Events::ADMIN_SETTINGS_SAVED, [
            'action' => '修改站点设置',
            'admin_id' => $_SESSION['user_id'],
            'detail' => '修改了站点设置',
            'target_type' => 'settings',
        ]);
        $this->success('设置已保存');
    }

    public function testEmail(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
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
            $this->error('请先填写 SMTP 服务器、用户名和密码');
            return;
        }

        $to = $config['smtp_from'] ?: $config['smtp_user'];

        try {
            $mailer = new \Core\Mailer($config);
            $mailer->send($to, 'AMuBBS 邮件测试', '<h3>邮件配置成功</h3><p>如果你收到这封邮件，说明 SMTP 配置正确。</p>');
            $this->success("测试邮件已发送到 {$to}");
        } catch (\Throwable $e) {
            error_log('[Admin:System] test mail failed: ' . $e->getMessage());
            $this->error('邮件发送失败，请检查 SMTP 配置是否正确（详细错误已记录到日志）');
            return;
        }
    }

    // ==================== 系统信息 ====================

    public function systemInfo(): void
    {
        $this->requireAdmin();

        $mysqlVersion = '-';
        try { $mysqlVersion = Database::fetchOne("SELECT VERSION() as v")['v'] ?? '-'; } catch (\Throwable $e) { error_log('[Admin:System] mysql version: ' . $e->getMessage()); }

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

        $this->render('admin/system_info', [
            'pageTitle' => '系统信息',
            'info' => $info,
        ]);
    }

    // ==================== 缓存管理 ====================

    public function cacheManage(): void
    {
        $this->requireAdmin();

        $redisInfo = null;
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
                $redisInfo = [
                    'version' => $info['redis_version'] ?? '-',
                    'used_memory_human' => $info['used_memory_human'] ?? '-',
                    'connected_clients' => $info['connected_clients'] ?? 0,
                    'total_keys' => $redis->dbSize(),
                    'uptime_days' => round(($info['uptime_in_seconds'] ?? 0) / 86400, 1),
                    'hit_rate' => ($info['keyspace_hits'] ?? 0) + ($info['keyspace_misses'] ?? 0) > 0
                        ? round(($info['keyspace_hits'] ?? 0) / (($info['keyspace_hits'] ?? 0) + ($info['keyspace_misses'] ?? 0)) * 100, 1)
                        : 0,
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Admin:System] redis info: ' . $e->getMessage());
        }

        $this->render('admin/cache', [
            'pageTitle' => '缓存管理',
            'redisInfo' => $redisInfo,
        ]);
    }

    public function cacheClear(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? 'all';
        $validTypes = ['forums', 'threads', 'users', 'all'];
        if (!in_array($type, $validTypes, true)) {
            $this->error('无效的缓存类型');
            return;
        }

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
                    Cache::deletePattern('*');
                    break;
            }
            Event::dispatch(Events::ADMIN_CACHE_CLEARED, [
                'action' => '清除缓存',
                'admin_id' => $_SESSION['user_id'],
                'detail' => "清除缓存类型:{$type}",
                'target_type' => 'cache',
            ]);
            $this->success('缓存已清除');
        } catch (\Throwable $e) {
            error_log('[Admin:System] cache clear failed: ' . $e->getMessage());
            $this->error('缓存清除失败，请检查 Redis 连接（详细错误已记录到日志）');
            return;
        }
    }

    // ==================== 集群管理 ====================

    public function cluster(): void
    {
        $this->requireAdmin();

        $nodes = [];
        try {
            $nodes = Database::fetchAll("SELECT * FROM cluster_nodes ORDER BY type, created_at DESC");
            foreach ($nodes as &$node) {
                $node['status_info'] = $this->checkNodeStatus($node);
                $node['config_data'] = json_decode($node['config'] ?? '{}', true) ?: [];
            }
            unset($node);
        } catch (\Throwable $e) {
            error_log('[Admin:System] cluster nodes: ' . $e->getMessage());
        }

        $this->render('admin/cluster', [
            'pageTitle' => '集群管理',
            'nodes' => $nodes,
        ]);
    }

    public function clusterCreate(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? '';
        $name = trim($input['name'] ?? '');
        $host = trim($input['host'] ?? '');
        $port = (int)($input['port'] ?? 0);
        $weight = max(1, (int)($input['weight'] ?? 1));
        $config = json_encode($input['config'] ?? []);

        if (!in_array($type, ['web', 'mysql', 'redis'], true)) {
            $this->error('无效的节点类型', 200);
            return;
        }
        if ($name === '' || $host === '' || $port <= 0 || $port > 65535) {
            $this->error('请填写完整信息', 200);
            return;
        }

        // 解析主机名并过滤内网地址，防止 SSRF
        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            $this->error('无法解析主机名', 200);
            return;
        }
        if ($this->isPrivateIp($ip)) {
            $this->error('不允许使用内网地址', 200);
            return;
        }

        Database::execute(
            "INSERT INTO cluster_nodes (type, name, host, port, weight, config, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            [$type, $name, $host, $port, $weight, $config, time()]
        );

        Cache::delete('cluster:nodes');
        Event::dispatch(Events::ADMIN_CLUSTER_NODE_CREATED, [
            'action' => '添加集群节点',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "添加{$type}节点:{$name}({$host}:{$port})",
            'target_type' => 'cluster_node',
        ]);
        $this->success('节点添加成功');
    }

    public function clusterToggle(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);

        $node = Database::fetchOne("SELECT * FROM cluster_nodes WHERE id = ?", [$id]);
        if (!$node) {
            $this->error('节点不存在', 200);
            return;
        }

        $newStatus = $node['status'] == 1 ? 0 : 1;
        Database::execute("UPDATE cluster_nodes SET status = ?, updated_at = ? WHERE id = ?", [$newStatus, time(), $id]);
        Cache::delete('cluster:nodes');
        Cache::delete("cluster:node:status:{$id}");

        $statusText = $newStatus == 1 ? '启用' : '禁用';
        Event::dispatch(Events::ADMIN_CLUSTER_NODE_TOGGLED, [
            'action' => "{$statusText}集群节点",
            'admin_id' => $_SESSION['user_id'],
            'detail' => "{$statusText}节点:{$node['name']}({$node['host']}:{$node['port']})",
            'target_type' => 'cluster_node',
            'target_id' => $id,
        ]);
        $this->success("节点已{$statusText}");
    }

    public function clusterUpdate(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $host = trim($input['host'] ?? '');
        $port = (int)($input['port'] ?? 0);
        $weight = max(1, (int)($input['weight'] ?? 1));
        $config = json_encode($input['config'] ?? []);

        if ($id <= 0) {
            $this->error('参数错误', 200);
            return;
        }

        $node = Database::fetchOne("SELECT * FROM cluster_nodes WHERE id = ?", [$id]);
        if (!$node) {
            $this->error('节点不存在', 200);
            return;
        }

        if ($name === '' || $host === '' || $port <= 0 || $port > 65535) {
            $this->error('请填写完整信息', 200);
            return;
        }

        // 解析主机名并过滤内网地址，防止 SSRF
        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            $this->error('无法解析主机名', 200);
            return;
        }
        if ($this->isPrivateIp($ip)) {
            $this->error('不允许使用内网地址', 200);
            return;
        }

        Database::execute(
            "UPDATE cluster_nodes SET name = ?, host = ?, port = ?, weight = ?, config = ?, updated_at = ? WHERE id = ?",
            [$name, $host, $port, $weight, $config, time(), $id]
        );

        Cache::delete('cluster:nodes');
        Cache::delete("cluster:node:status:{$id}");
        Event::dispatch(Events::ADMIN_CLUSTER_NODE_TOGGLED, [
            'action' => '编辑集群节点',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "编辑节点:{$name}({$host}:{$port})",
            'target_type' => 'cluster_node',
            'target_id' => $id,
        ]);
        $this->success('节点已更新');
    }

    public function clusterDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->error('参数错误', 200);
            return;
        }

        $node = Database::fetchOne("SELECT * FROM cluster_nodes WHERE id = ?", [$id]);
        Database::execute("DELETE FROM cluster_nodes WHERE id = ?", [$id]);
        Cache::delete('cluster:nodes');
        Cache::delete("cluster:node:status:{$id}");

        Event::dispatch(Events::ADMIN_CLUSTER_NODE_DELETED, [
            'action' => '删除集群节点',
            'admin_id' => $_SESSION['user_id'],
            'detail' => $node ? "删除节点:{$node['name']}({$node['host']}:{$node['port']})" : "删除节点ID:{$id}",
            'target_type' => 'cluster_node',
            'target_id' => $id,
        ]);
        $this->success('节点已删除');
    }

    /**
     * 测试节点连接（不走缓存）
     */
    public function clusterTest(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? '';
        $host = trim($input['host'] ?? '');
        $port = (int)($input['port'] ?? 0);
        $config = $input['config'] ?? [];

        if (!in_array($type, ['web', 'mysql', 'redis'], true)) {
            $this->error('无效的节点类型', 200);
            return;
        }
        if ($host === '' || $port <= 0 || $port > 65535) {
            $this->error('请填写主机和端口', 200);
            return;
        }

        // 解析主机名并过滤内网地址，防止 SSRF
        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            $this->error('无法解析主机名', 200);
            return;
        }
        if ($this->isPrivateIp($ip)) {
            $this->error('不允许使用内网地址', 200);
            return;
        }

        $start = microtime(true);
        try {
            switch ($type) {
                case 'web':
                    $fp = @fsockopen($ip, $port, $errno, $errstr, 3);
                    if (!$fp) {
                        $this->error("连接失败: {$errstr}", 200);
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
                        $this->error('服务器未安装 Redis 扩展', 200);
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
            $this->error('连接失败，请检查主机和端口配置', 200);
            return;
        }

        $ms = round((microtime(true) - $start) * 1000, 2);
        $this->success("连接成功，响应时间 {$ms}ms");
    }

    private function checkNodeStatus(array $node): array
    {
        $cacheKey = "cluster:node:status:{$node['id']}";
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
        $this->render('admin/ip_blacklist', ['pageTitle' => 'IP 黑名单']);
    }

    /**
     * IP 黑名单列表 API
     */
    public function ipBlacklistApi(): void
    {
        $this->requireAdmin();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $search = trim($_GET['search'] ?? '');

        $where = "WHERE 1=1";
        $params = [];
        if ($search !== '') {
            $where .= " AND (ip LIKE ? OR reason LIKE ?)";
            $escaped = addcslashes($search, '%_\\');
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
        }

        $total = (int)(Database::fetchOne("SELECT COUNT(*) as cnt FROM ip_blacklist {$where}", $params)['cnt'] ?? 0);
        $offset = ($page - 1) * $limit;

        $ips = Database::fetchAll(
            "SELECT * FROM ip_blacklist {$where} ORDER BY id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $this->layuiJson($ips, $total);
    }

    public function ipBlacklistCreate(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $ip = trim($input['ip'] ?? '');
        $reason = trim($input['reason'] ?? '');
        $duration = (int)($input['duration'] ?? 0);

        if ($ip === '') {
            $this->error('IP 地址不能为空');
            return;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->error('IP 地址格式不正确');
            return;
        }

        $expireAt = $duration > 0 ? time() + $duration : null;

        Database::execute(
            "INSERT INTO ip_blacklist (ip, reason, expire_at, created_at) VALUES (?, ?, ?, ?)",
            [$ip, $reason, $expireAt, time()]
        );

        \App\Services\IpBlacklistService::clearCache();
        Event::dispatch(Events::ADMIN_IP_BLOCKED, [
            'action' => '封禁IP',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "封禁 IP:{$ip} 原因:{$reason}",
            'target_type' => 'ip_blacklist',
        ]);
        $this->success('添加成功');
    }

    public function ipBlacklistDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->error('参数错误');
            return;
        }

        Database::execute("DELETE FROM ip_blacklist WHERE id = ?", [$id]);
        \App\Services\IpBlacklistService::clearCache();
        Event::dispatch(Events::ADMIN_IP_UNBLOCKED, [
            'action' => '解封IP',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "删除 IP 黑名单 ID:{$id}",
            'target_type' => 'ip_blacklist',
            'target_id' => $id,
        ]);
        $this->success('删除成功');
    }

    // ==================== 友情链接 ====================

    public function friendLinks(): void
    {
        $this->requireAdmin();
        $this->render('admin/friend_links', ['pageTitle' => '友情链接']);
    }

    /**
     * 友情链接列表 API
     */
    public function friendLinksApi(): void
    {
        $this->requireAdmin();

        $links = [];
        try {
            $links = Database::fetchAll("SELECT * FROM friend_links ORDER BY sort_order ASC, id ASC");
        } catch (\Throwable $e) {
            error_log('[Admin:System] friendLinksApi: ' . $e->getMessage());
        }

        $this->layuiJson($links, count($links));
    }

    public function friendLinkCreate(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $url = trim($input['url'] ?? '');
        $logo = trim($input['logo'] ?? '');
        $sortOrder = (int)($input['sort_order'] ?? 0);

        if ($name === '' || $url === '') {
            $this->error('名称和链接不能为空');
            return;
        }

        // 验证 URL 格式，防止 javascript: 等协议注入
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            $this->error('链接格式不正确，请使用 http:// 或 https:// 开头的地址');
            return;
        }

        Database::execute(
            "INSERT INTO friend_links (name, url, logo, sort_order, status, created_at) VALUES (?, ?, ?, ?, 1, ?)",
            [$name, $url, $logo, $sortOrder, time()]
        );

        Cache::delete('friend_links:active');
        $this->success('添加成功');
    }

    public function friendLinkDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->error('参数错误');
            return;
        }

        Database::execute("DELETE FROM friend_links WHERE id = ?", [$id]);
        Cache::delete('friend_links:active');
        $this->success('删除成功');
    }

    // ==================== 导航管理 ====================

    public function navigation(): void
    {
        $this->requireAdmin();

        $categories = Database::fetchAll("SELECT * FROM nav_categories WHERE deleted_at IS NULL ORDER BY `rank` DESC, id ASC");
        $catIds = array_column($categories, 'id');

        $linksByCategory = [];
        if (!empty($catIds)) {
            $placeholders = implode(',', array_fill(0, count($catIds), '?'));
            $allLinks = Database::fetchAll(
                "SELECT * FROM nav_links WHERE category_id IN ({$placeholders}) AND deleted_at IS NULL ORDER BY `rank` DESC, id ASC",
                $catIds
            );
            foreach ($allLinks as $link) {
                $linksByCategory[$link['category_id']][] = $link;
            }
        }
        foreach ($categories as &$cat) {
            $cat['links'] = $linksByCategory[$cat['id']] ?? [];
        }
        unset($cat);

        $this->render('admin/navigation', [
            'pageTitle' => '导航管理',
            'categories' => $categories,
        ]);
    }

    public function navCategoryCreate(): void
    {
        $this->requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $icon = trim($input['icon'] ?? '');
        $rank = (int)($input['rank'] ?? 0);
        if ($name === '') { $this->error('分类名称不能为空'); return; }

        Database::execute("INSERT INTO nav_categories (name, icon, `rank`, created_at) VALUES (?, ?, ?, ?)",
            [$name, $icon, $rank, time()]);
        Cache::delete('nav_links:all');
        $this->success('分类已创建', ['id' => Database::lastInsertId()]);
    }

    public function navCategoryUpdate(): void
    {
        $this->requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $icon = trim($input['icon'] ?? '');
        $rank = (int)($input['rank'] ?? 0);
        if ($id <= 0 || $name === '') { $this->error('参数错误'); return; }

        Database::execute("UPDATE nav_categories SET name=?, icon=?, `rank`=? WHERE id=?", [$name, $icon, $rank, $id]);
        Cache::delete('nav_links:all');
        $this->success('分类已更新');
    }

    public function navCategoryDelete(): void
    {
        $this->requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { $this->error('参数错误'); return; }

        Database::useMaster();
        try {
            Database::beginTransaction();
            Database::execute("UPDATE nav_categories SET deleted_at = ? WHERE id = ?", [time(), $id]);
            Database::execute("UPDATE nav_links SET deleted_at = ? WHERE category_id = ?", [time(), $id]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        } finally {
            Database::restoreReadWrite();
        }
        Cache::delete('nav_links:all');
        $this->success('分类已删除');
    }

    public function navLinkCreate(): void
    {
        $this->requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $categoryId = (int)($input['category_id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $url = trim($input['url'] ?? '');
        $description = trim($input['description'] ?? '');
        $icon = trim($input['icon'] ?? '');
        $rank = (int)($input['rank'] ?? 0);

        if ($name === '' || $url === '') { $this->error('名称和链接不能为空'); return; }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) { $this->error('链接格式不正确，请使用 http:// 或 https:// 开头的地址'); return; }
        if ($categoryId <= 0) { $this->error('请选择分类'); return; }

        Database::execute(
            "INSERT INTO nav_links (category_id, name, url, description, icon, `rank`, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$categoryId, $name, $url, $description, $icon, $rank, time()]
        );
        Cache::delete('nav_links:all');
        $this->success('链接已创建');
    }

    public function navLinkUpdate(): void
    {
        $this->requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        $categoryId = (int)($input['category_id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $url = trim($input['url'] ?? '');
        $description = trim($input['description'] ?? '');
        $icon = trim($input['icon'] ?? '');
        $rank = (int)($input['rank'] ?? 0);

        if ($id <= 0 || $name === '' || $url === '') { $this->error('参数错误'); return; }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            $this->error('链接格式不正确，请使用 http:// 或 https:// 开头的地址');
            return;
        }

        Database::execute(
            "UPDATE nav_links SET category_id=?, name=?, url=?, description=?, icon=?, `rank`=? WHERE id=?",
            [$categoryId, $name, $url, $description, $icon, $rank, $id]
        );
        Cache::delete('nav_links:all');
        $this->success('链接已更新');
    }

    public function navLinkDelete(): void
    {
        $this->requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { $this->error('参数错误'); return; }
        Database::execute("UPDATE nav_links SET deleted_at = ? WHERE id = ?", [time(), $id]);
        Cache::delete('nav_links:all');
        $this->success('链接已删除');
    }

    // ==================== 操作日志 ====================

    /**
     * 操作日志列表 API（版主日志）
     */
    public function logsApi(): void
    {
        $this->requireAdmin();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $filters = array_filter([
            'action' => trim($_GET['action'] ?? ''),
            'user_id' => (int)($_GET['user_id'] ?? 0) ?: '',
            'target_type' => trim($_GET['target_type'] ?? ''),
            'date_from' => trim($_GET['date_from'] ?? ''),
            'date_to' => trim($_GET['date_to'] ?? ''),
        ]);

        $total = (int)\App\Services\LogService::countModLogs($filters);
        $logs = \App\Services\LogService::getModLogs($page, $limit, $filters);

        $this->layuiJson($logs, $total);
    }

    public function logs(): void
    {
        $this->requireAdmin();

        $filter = $_GET['filter'] ?? '';

        if ($filter === 'mod') {
            $actionTypes = \App\Services\LogService::getModActionTypes();
            $this->render('admin/logs', [
                'pageTitle' => '操作日志',
                'filter' => 'mod',
                'actionTypes' => $actionTypes,
                'logs' => [],
            ]);
            return;
        }

        $date = $_GET['date'] ?? date('Y-m-d');
        // 验证日期格式，防止路径遍历
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
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

        $this->render('admin/logs', [
            'pageTitle' => '操作日志',
            'logs' => $logs,
            'date' => $date,
        ]);
    }

}
