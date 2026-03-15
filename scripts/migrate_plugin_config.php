<?php
/**
 * 插件配置迁移脚本
 * 将插件配置从 storage/plugin_config/ 迁移到 settings 表
 */

// 定义常量
define('APP_PATH', dirname(__DIR__) . '/');
define('DEBUG', true);

// 手动加载必要的类
require_once APP_PATH . 'core/Database.php';
require_once APP_PATH . 'core/Cache.php';
require_once APP_PATH . 'app/Services/SettingSvc.php';

class PluginConfigMigrator
{
    private array $migrated = [];
    private array $errors = [];

    public function run(): void
    {
        echo "开始迁移插件配置...\n\n";

        try {
            \Core\Database::beginTransaction();

            // 迁移各个插件的配置
            $this->migrateAutoAvatar();
            $this->migrateEmoji();
            $this->migrateSocialLogin();
            $this->migrateTinymceEditor();

            \Core\Database::commit();

            echo "\n迁移完成！\n";
            echo "成功迁移 " . count($this->migrated) . " 个配置项：\n";
            foreach ($this->migrated as $key => $value) {
                $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                echo "  - {$key} = {$displayValue}\n";
            }

            if (!empty($this->errors)) {
                echo "\n警告信息：\n";
                foreach ($this->errors as $error) {
                    echo "  - {$error}\n";
                }
            }

            // 清除设置缓存（如果可用）
            try {
                \App\Services\SettingSvc::clearCache();
                echo "\n设置缓存已清除\n";
            } catch (\Throwable $e) {
                echo "\n注意：无法清除缓存（" . $e->getMessage() . "），请手动重启应用\n";
            }

        } catch (\Exception $e) {
            \Core\Database::rollBack();
            echo "\n迁移失败：" . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * 迁移 AutoAvatar 配置
     */
    private function migrateAutoAvatar(): void
    {
        echo "迁移 AutoAvatar 配置...\n";

        $configFile = APP_PATH . 'storage/plugin_config/AutoAvatar.json';

        if (!file_exists($configFile)) {
            $this->errors[] = "AutoAvatar 配置文件不存在，使用默认值";
            $this->setSetting('auto_avatar_enabled', true);
            $this->setSetting('auto_avatar_overwrite', false);
            return;
        }

        $config = json_decode(file_get_contents($configFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = "AutoAvatar 配置文件解析失败，使用默认值";
            $this->setSetting('auto_avatar_enabled', true);
            $this->setSetting('auto_avatar_overwrite', false);
            return;
        }

        // 如果插件在 plugins.json 中启用，则设置为启用
        $enabled = $this->isPluginEnabled('AutoAvatar');
        $this->setSetting('auto_avatar_enabled', $enabled);

        // 迁移覆盖策略
        $overwrite = $config['overwrite_existing'] ?? false;
        $this->setSetting('auto_avatar_overwrite', $overwrite);

        echo "  ✓ AutoAvatar 配置迁移完成\n";
    }

    /**
     * 迁移 Emoji 配置
     */
    private function migrateEmoji(): void
    {
        echo "迁移 Emoji 配置...\n";

        $configFile = APP_PATH . 'storage/plugin_config/Emoji.json';

        if (!file_exists($configFile)) {
            $this->errors[] = "Emoji 配置文件不存在，使用默认值";
            $this->setSetting('emoji_enabled', true);
            return;
        }

        $config = json_decode(file_get_contents($configFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = "Emoji 配置文件解析失败，使用默认值";
            $this->setSetting('emoji_enabled', true);
            return;
        }

        // 如果插件在 plugins.json 中启用，则设置为启用
        $enabled = $this->isPluginEnabled('Emoji');
        $this->setSetting('emoji_enabled', $enabled);

        echo "  ✓ Emoji 配置迁移完成\n";
    }

    /**
     * 迁移 SocialLogin 配置
     */
    private function migrateSocialLogin(): void
    {
        echo "迁移 SocialLogin 配置...\n";

        $configFile = APP_PATH . 'storage/plugin_config/SocialLogin.json';

        if (!file_exists($configFile)) {
            $this->errors[] = "SocialLogin 配置文件不存在，使用默认值";
            $this->setDefaultSocialLoginConfig();
            return;
        }

        $config = json_decode(file_get_contents($configFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = "SocialLogin 配置文件解析失败，使用默认值";
            $this->setDefaultSocialLoginConfig();
            return;
        }

        $providers = $config['providers'] ?? [];

        // 迁移 GitHub 配置
        if (isset($providers['github'])) {
            $github = $providers['github'];
            $this->setSetting('social_login_github_enabled', $github['enabled'] ?? false);
            $this->setSetting('social_login_github_client_id', $github['client_id'] ?? '');
            $this->setSetting('social_login_github_client_secret', $github['client_secret'] ?? '');
        }

        // 迁移 Google 配置
        if (isset($providers['google'])) {
            $google = $providers['google'];
            $this->setSetting('social_login_google_enabled', $google['enabled'] ?? false);
            $this->setSetting('social_login_google_client_id', $google['client_id'] ?? '');
            $this->setSetting('social_login_google_client_secret', $google['client_secret'] ?? '');
        }

        // 迁移微信配置
        if (isset($providers['wechat'])) {
            $wechat = $providers['wechat'];
            $this->setSetting('social_login_wechat_enabled', $wechat['enabled'] ?? false);
            $this->setSetting('social_login_wechat_client_id', $wechat['app_id'] ?? '');
            $this->setSetting('social_login_wechat_client_secret', $wechat['app_secret'] ?? '');
        }

        // 迁移 QQ 配置
        if (isset($providers['qq'])) {
            $qq = $providers['qq'];
            $this->setSetting('social_login_qq_enabled', $qq['enabled'] ?? false);
            $this->setSetting('social_login_qq_client_id', $qq['app_id'] ?? '');
            $this->setSetting('social_login_qq_client_secret', $qq['app_secret'] ?? '');
        }

        echo "  ✓ SocialLogin 配置迁移完成\n";
    }

    /**
     * 迁移 TinymceEditor 配置
     */
    private function migrateTinymceEditor(): void
    {
        echo "迁移 TinymceEditor 配置...\n";

        $configFile = APP_PATH . 'storage/plugin_config/TinymceEditor.json';

        // TinymceEditor 插件可能不存在，使用默认值
        if (!file_exists($configFile)) {
            $this->errors[] = "TinymceEditor 配置文件不存在，使用默认值";
            $this->setSetting('editor_type', 'markdown');
            $this->setSetting('tinymce_enable_post_editor', false);
            $this->setSetting('tinymce_enable_reply_editor', false);
            return;
        }

        $config = json_decode(file_get_contents($configFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = "TinymceEditor 配置文件解析失败，使用默认值";
            $this->setSetting('editor_type', 'markdown');
            $this->setSetting('tinymce_enable_post_editor', false);
            $this->setSetting('tinymce_enable_reply_editor', false);
            return;
        }

        // 如果插件启用，则设置编辑器类型为 tinymce
        $enabled = $this->isPluginEnabled('TinymceEditor');
        $this->setSetting('editor_type', $enabled ? 'tinymce' : 'markdown');

        // 迁移编辑器启用配置
        $this->setSetting('tinymce_enable_post_editor', $config['enable_post_editor'] ?? true);
        $this->setSetting('tinymce_enable_reply_editor', $config['enable_reply_editor'] ?? true);

        echo "  ✓ TinymceEditor 配置迁移完成\n";
    }

    /**
     * 设置默认社交登录配置
     */
    private function setDefaultSocialLoginConfig(): void
    {
        $providers = ['github', 'google', 'wechat', 'qq'];

        foreach ($providers as $provider) {
            $this->setSetting("social_login_{$provider}_enabled", false);
            $this->setSetting("social_login_{$provider}_client_id", '');
            $this->setSetting("social_login_{$provider}_client_secret", '');
        }
    }

    /**
     * 检查插件是否在 plugins.json 中启用
     */
    private function isPluginEnabled(string $pluginName): bool
    {
        $pluginsFile = APP_PATH . 'storage/plugins.json';

        if (!file_exists($pluginsFile)) {
            return false;
        }

        $plugins = json_decode(file_get_contents($pluginsFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return in_array($pluginName, $plugins, true);
    }

    /**
     * 设置配置项
     */
    private function setSetting(string $key, $value): void
    {
        // 转换布尔值为字符串
        if (is_bool($value)) {
            $valueStr = $value ? '1' : '0';
        } else {
            $valueStr = (string)$value;
        }

        // 检查配置项是否已存在
        $existing = \Core\Database::fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);

        if ($existing) {
            // 更新现有配置
            \Core\Database::execute(
                "UPDATE settings SET `value` = ?, updated_at = ? WHERE `key` = ?",
                [$valueStr, time(), $key]
            );
        } else {
            // 插入新配置
            \Core\Database::execute(
                "INSERT INTO settings (`key`, `value`, `description`, updated_at) VALUES (?, ?, ?, ?)",
                [$key, $valueStr, '', time()]
            );
        }

        $this->migrated[$key] = $value;
    }
}

// 执行迁移
$migrator = new PluginConfigMigrator();
$migrator->run();
