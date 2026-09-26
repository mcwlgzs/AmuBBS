<?php
/**
 * 插件加载器
 *
 * 关键设计：单个插件加载失败（文件缺失、类不存在、未实现接口、register() 抛异常）
 * 只记录日志并跳过，绝不让一个坏插件把整站打成白屏。
 * 这一点对共享虚拟主机尤其重要——用户没法 SSH 上去手动禁用插件。
 *
 * 实例化交给 PluginManager::instance()：enable()/uninstall() 需要同一个实例去调
 * install()/uninstall()，两处共用一份缓存，不会重复 new，也不会重复 require。
 */

namespace Core;

class PluginLoader
{
    private PluginManager $manager;

    public function __construct(PluginManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * 加载并启动指定插件
     *
     * @param string[] $names 插件名列表
     * @return array<string, object> 成功加载的 name => 实例
     */
    public function load(array $names): array
    {
        $loaded = [];

        foreach ($names as $name) {
            $instance = $this->manager->instance($name);
            if ($instance === null) {
                continue;
            }

            try {
                $instance->register();

                $this->manager->markLoaded($name, $instance);
                $loaded[$name] = $instance;
            } catch (\Throwable $e) {
                error_log("[Plugin] 加载插件 {$name} 失败: " . $e->getMessage());
            }
        }

        return $loaded;
    }
}
