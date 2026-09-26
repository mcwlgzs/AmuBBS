<?php
/**
 * 插件管理器
 *
 * 设计约束（对齐共享虚拟主机目标）：
 *  - 零依赖：不需要 Composer
 *  - 状态存文件：storage/plugin_config/plugins.json，不需要建表
 *  - 不依赖 shell / cron / 常驻进程
 *
 * 插件目录约定：
 *   plugins/Emoji/
 *   ├── plugin.json    元数据（name/title/version/description/entry/class）
 *   └── Plugin.php     主类，实现 Core\PluginInterface
 */

namespace Core;

class PluginManager
{
    private string $pluginPath;
    private string $stateFile;

    /** @var array<string, array> name => 元数据 */
    private array $registry = [];

    /** @var array<string, array> name => ['enabled' => bool, 'installed_at' => int] */
    private array $state = [];

    /** @var array<string, object> name => 插件实例 */
    private array $loaded = [];

    /** @var array<string, object> name => 已实例化（未必已 register()，供生命周期回调复用） */
    private array $instantiated = [];

    private bool $discovered = false;

    public function __construct(string $pluginPath, string $statePath)
    {
        $this->pluginPath = rtrim($pluginPath, '/\\') . DIRECTORY_SEPARATOR;
        $this->stateFile  = rtrim($statePath, '/\\') . DIRECTORY_SEPARATOR . 'plugins.json';

        $this->loadState();
    }

    // ------------------------------------------------------------------
    // 发现
    // ------------------------------------------------------------------

    /**
     * 扫描 plugins/ 目录，读取每个插件的 plugin.json
     *
     * @return array<string, array>
     */
    public function discover(): array
    {
        $this->registry   = [];
        $this->discovered = true;

        if (!is_dir($this->pluginPath)) {
            return $this->registry;
        }

        $entries = @scandir($this->pluginPath);
        if ($entries === false) {
            error_log('[Plugin] 无法读取插件目录: ' . $this->pluginPath);
            return $this->registry;
        }

        foreach ($entries as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $path = $this->pluginPath . $dir;
            if (!is_dir($path)) {
                continue;
            }

            $manifest = $path . DIRECTORY_SEPARATOR . 'plugin.json';
            if (!is_file($manifest)) {
                continue;
            }

            $meta = json_decode((string)@file_get_contents($manifest), true);
            if (!is_array($meta)) {
                error_log("[Plugin] {$dir}/plugin.json 解析失败，已跳过");
                continue;
            }

            $name = (string)($meta['name'] ?? $dir);

            $this->registry[$name] = [
                'name'        => $name,
                'dir'         => $dir,
                'title'       => (string)($meta['title'] ?? $name),
                'version'     => (string)($meta['version'] ?? '1.0.0'),
                'description' => (string)($meta['description'] ?? ''),
                'author'      => (string)($meta['author'] ?? ''),
                'entry'       => (string)($meta['entry'] ?? 'Plugin.php'),
                'class'       => (string)($meta['class'] ?? ('Plugins\\' . self::studly($dir) . '\\Plugin')),
                'path'        => $path,
            ];
        }

        ksort($this->registry);

        return $this->registry;
    }

    private function ensureDiscovered(): void
    {
        if (!$this->discovered) {
            $this->discover();
        }
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

    public function meta(string $name): ?array
    {
        $this->ensureDiscovered();

        return $this->registry[$name] ?? null;
    }

    /**
     * 元数据 + 启用状态，供后台插件列表使用
     */
    public function all(): array
    {
        $this->ensureDiscovered();

        $out = [];
        foreach ($this->registry as $name => $meta) {
            $meta['enabled']      = $this->isEnabled($name);
            $meta['installed_at'] = (int)($this->state[$name]['installed_at'] ?? 0);
            $out[$name] = $meta;
        }

        return $out;
    }

    /**
     * @return string[] 已启用的插件名
     */
    public function getEnabled(): array
    {
        $this->ensureDiscovered();

        $enabled = [];
        foreach (array_keys($this->registry) as $name) {
            if ($this->isEnabled($name)) {
                $enabled[] = $name;
            }
        }

        return $enabled;
    }

    public function isEnabled(string $name): bool
    {
        return !empty($this->state[$name]['enabled']);
    }

    // ------------------------------------------------------------------
    // 状态变更
    // ------------------------------------------------------------------

    public function enable(string $name): bool
    {
        $this->ensureDiscovered();
        if (!isset($this->registry[$name])) {
            return false;
        }

        // installed_at 为空 = 从没装过，install() 只在首次启用时跑一次
        $firstTime = empty($this->state[$name]['installed_at']);

        $this->state[$name] = [
            'enabled'      => true,
            'installed_at' => (int)($this->state[$name]['installed_at'] ?? time()),
        ];

        if (!$this->saveState()) {
            return false;
        }

        if ($firstTime) {
            $this->callLifecycle($name, 'install');
        }

        return true;
    }

    public function disable(string $name): bool
    {
        $this->state[$name]['enabled']      = false;
        $this->state[$name]['installed_at'] = (int)($this->state[$name]['installed_at'] ?? 0);

        return $this->saveState();
    }

    /**
     * 卸载：调用插件的 uninstall() 并清掉启用状态
     *
     * 只清状态不动文件：共享主机上用户删不了目录也无所谓，
     * 状态没了插件就不再加载，重新启用即可恢复。
     */
    public function uninstall(string $name): bool
    {
        $this->ensureDiscovered();
        if (!isset($this->registry[$name])) {
            return false;
        }

        $this->callLifecycle($name, 'uninstall');

        return $this->forget($name);
    }

    /**
     * 从状态文件里彻底移除（用于卸载）
     */
    public function forget(string $name): bool
    {
        unset($this->state[$name]);

        return $this->saveState();
    }

    // ------------------------------------------------------------------
    // 实例化与生命周期
    // ------------------------------------------------------------------

    /**
     * 实例化插件主类（不调用 register()）
     *
     * 之所以放在 PluginManager 而不是 PluginLoader：
     * enable()/uninstall() 需要实例才能调 install()/uninstall()，
     * 而这两个入口只有 PluginManager 有。PluginLoader 复用同一个实例，不会重复 new。
     */
    public function instance(string $name): ?object
    {
        $this->ensureDiscovered();

        if (isset($this->instantiated[$name])) {
            return $this->instantiated[$name];
        }

        $meta = $this->meta($name);
        if ($meta === null) {
            error_log("[Plugin] 找不到插件元数据，已跳过: {$name}");
            return null;
        }

        $class = $meta['class'];
        $file  = $meta['path'] . DIRECTORY_SEPARATOR . $meta['entry'];

        if (!is_file($file)) {
            error_log("[Plugin] 入口文件不存在: {$file}");
            return null;
        }

        require_once $file;

        if (!class_exists($class)) {
            error_log("[Plugin] 类不存在: {$class}（入口文件 {$file}）");
            return null;
        }

        $instance = new $class();

        if (!$instance instanceof PluginInterface) {
            error_log("[Plugin] {$class} 未实现 " . PluginInterface::class . '，已跳过');
            return null;
        }

        $this->instantiated[$name] = $instance;

        return $instance;
    }

    /**
     * 调用可选生命周期方法（未实现就什么都不做）
     *
     * 生命周期抛异常不能把后台点击「启用」变成 500：只记日志，插件保持可用状态，
     * 管理员看得到日志、也能再点一次停用。
     */
    private function callLifecycle(string $name, string $method): void
    {
        $instance = $this->instance($name);
        if ($instance === null || !method_exists($instance, $method)) {
            return;
        }

        try {
            $instance->{$method}();
            error_log("[Plugin] {$name}::{$method}() 已执行");
        } catch (\Throwable $e) {
            error_log("[Plugin] {$name}::{$method}() 失败: " . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 已加载实例
    // ------------------------------------------------------------------

    public function markLoaded(string $name, object $instance): void
    {
        $this->loaded[$name] = $instance;
    }

    /**
     * @return array<string, object>
     */
    public function getLoaded(): array
    {
        return $this->loaded;
    }

    /**
     * 按名字取【已加载】的插件实例，取不到返回 null
     *
     * 兼容旧插件系统的调用方式，例如：
     *   $pm->get('SocialLogin')
     *   $pm->get('social_login')
     *   $pm->get('social-login')
     * 三种写法都能匹配到同一个插件（忽略大小写与分隔符）。
     *
     * 注意：只返回已启用的插件；未启用或不存在都返回 null，
     * 调用方按「没有这个插件」处理即可（auth-modal 就是这么用的）。
     */
    public function get(string $name): ?object
    {
        if (isset($this->loaded[$name])) {
            return $this->loaded[$name];
        }

        $normalized = self::normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->loaded as $key => $instance) {
            if (self::normalizeName((string)$key) === $normalized) {
                return $instance;
            }
        }

        return null;
    }

    private static function normalizeName(string $value): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $value));
    }

    // ------------------------------------------------------------------
    // 状态持久化
    // ------------------------------------------------------------------

    private function loadState(): void
    {
        if (!is_file($this->stateFile)) {
            $this->state = [];
            return;
        }

        $data = json_decode((string)@file_get_contents($this->stateFile), true);
        $this->state = is_array($data) ? $data : [];
    }

    private function saveState(): bool
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[Plugin] 无法创建状态目录: ' . $dir);
            return false;
        }

        $json = json_encode(
            $this->state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // 临时文件 + rename，避免并发写坏状态文件
        $tmp = $this->stateFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            error_log('[Plugin] 无法写入状态文件: ' . $this->stateFile);
            return false;
        }

        if (!@rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    private static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
