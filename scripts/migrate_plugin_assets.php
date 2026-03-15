<?php
/**
 * 插件静态资源迁移脚本
 * 将插件资源从 plugins/ 和 public/plugin-assets/ 迁移到 public/assets/
 */

// 定义常量
define('APP_PATH', dirname(__DIR__) . '/');
define('DEBUG', true);

// 手动加载必要的类
require_once APP_PATH . 'core/Database.php';

class PluginAssetMigrator
{
    private array $migrated = [];
    private array $errors = [];
    private array $urlUpdates = [];

    public function run(): void
    {
        echo "开始迁移插件静态资源...\n\n";

        try {
            // 迁移头像资源
            $this->migrateAutoAvatarAssets();

            // 迁移 TinyMCE 资源
            $this->migrateTinymceAssets();

            // 更新数据库中的 URL 引用
            $this->updateDatabaseUrls();

            echo "\n迁移完成！\n";
            echo "成功迁移 " . count($this->migrated) . " 个文件\n";
            echo "更新 " . count($this->urlUpdates) . " 条数据库记录\n";

            if (!empty($this->errors)) {
                echo "\n警告信息：\n";
                foreach ($this->errors as $error) {
                    echo "  - {$error}\n";
                }
            }

        } catch (\Exception $e) {
            echo "\n迁移失败：" . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * 迁移 AutoAvatar 头像资源
     */
    private function migrateAutoAvatarAssets(): void
    {
        echo "迁移 AutoAvatar 头像资源...\n";

        $sourceDir = APP_PATH . 'plugins/AutoAvatar/assets/';
        $targetDir = APP_PATH . 'public/assets/avatars/';

        if (!is_dir($sourceDir)) {
            $this->errors[] = "AutoAvatar 资源目录不存在: {$sourceDir}";
            return;
        }

        // 创建目标目录
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new \Exception("无法创建目标目录: {$targetDir}");
            }
            echo "  ✓ 创建目录: {$targetDir}\n";
        }

        // 复制文件
        $files = scandir($sourceDir);
        $copiedCount = 0;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $sourcePath = $sourceDir . $file;
            $targetPath = $targetDir . $file;

            if (is_file($sourcePath)) {
                // 如果目标文件已存在，跳过
                if (file_exists($targetPath)) {
                    echo "  - 跳过已存在的文件: {$file}\n";
                    continue;
                }

                if (copy($sourcePath, $targetPath)) {
                    $this->migrated[] = "avatars/{$file}";
                    $copiedCount++;
                } else {
                    $this->errors[] = "复制文件失败: {$file}";
                }
            }
        }

        echo "  ✓ AutoAvatar 资源迁移完成，复制了 {$copiedCount} 个文件\n";
    }

    /**
     * 迁移 TinyMCE 编辑器资源
     */
    private function migrateTinymceAssets(): void
    {
        echo "迁移 TinyMCE 编辑器资源...\n";

        // 迁移 tinymce 目录
        $this->migrateDirectory(
            APP_PATH . 'plugins/TinymceEditor/tinymce/',
            APP_PATH . 'public/assets/tinymce/',
            'tinymce'
        );

        // 迁移 prism 目录
        $this->migrateDirectory(
            APP_PATH . 'plugins/TinymceEditor/prism/',
            APP_PATH . 'public/assets/prism/',
            'prism'
        );

        echo "  ✓ TinyMCE 资源迁移完成\n";
    }

    /**
     * 迁移目录
     */
    private function migrateDirectory(string $sourceDir, string $targetDir, string $label): void
    {
        if (!is_dir($sourceDir)) {
            $this->errors[] = "{$label} 资源目录不存在: {$sourceDir}";
            return;
        }

        // 创建目标目录
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new \Exception("无法创建目标目录: {$targetDir}");
            }
            echo "  ✓ 创建目录: {$targetDir}\n";
        }

        // 递归复制目录
        $copiedCount = $this->copyDirectory($sourceDir, $targetDir);
        echo "  ✓ {$label} 迁移完成，复制了 {$copiedCount} 个文件\n";
    }

    /**
     * 递归复制目录
     */
    private function copyDirectory(string $source, string $target): int
    {
        $count = 0;
        $dir = opendir($source);

        if ($dir === false) {
            throw new \Exception("无法打开源目录: {$source}");
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $sourcePath = $source . $file;
            $targetPath = $target . $file;

            if (is_dir($sourcePath)) {
                // 递归复制子目录
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
                $count += $this->copyDirectory($sourcePath . '/', $targetPath . '/');
            } else {
                // 复制文件
                if (file_exists($targetPath)) {
                    // 跳过已存在的文件
                    continue;
                }

                if (copy($sourcePath, $targetPath)) {
                    $this->migrated[] = str_replace(APP_PATH . 'public/', '', $targetPath);
                    $count++;
                } else {
                    $this->errors[] = "复制文件失败: {$sourcePath}";
                }
            }
        }

        closedir($dir);
        return $count;
    }

    /**
     * 更新数据库中的 URL 引用
     */
    private function updateDatabaseUrls(): void
    {
        echo "\n更新数据库中的 URL 引用...\n";

        \Core\Database::beginTransaction();

        try {
            // 更新用户头像 URL
            $this->updateAvatarUrls();

            // 更新帖子和回复中的附件 URL（如果有）
            $this->updateContentUrls();

            \Core\Database::commit();
            echo "  ✓ 数据库 URL 更新完成\n";

        } catch (\Exception $e) {
            \Core\Database::rollBack();
            throw new \Exception("更新数据库 URL 失败: " . $e->getMessage());
        }
    }

    /**
     * 更新用户头像 URL
     */
    private function updateAvatarUrls(): void
    {
        // 更新从 /plugin-assets/AutoAvatar/ 到 /assets/avatars/ 的 URL
        $result = \Core\Database::execute(
            "UPDATE users SET avatar = REPLACE(avatar, '/plugin-assets/AutoAvatar/', '/assets/avatars/') WHERE avatar LIKE '/plugin-assets/AutoAvatar/%'"
        );

        if ($result > 0) {
            $this->urlUpdates[] = "更新了 {$result} 个用户头像 URL";
            echo "  ✓ 更新了 {$result} 个用户头像 URL\n";
        }
    }

    /**
     * 更新帖子和回复内容中的 URL
     */
    private function updateContentUrls(): void
    {
        // 更新帖子内容中的插件资源 URL
        $threadResult = \Core\Database::execute(
            "UPDATE threads SET content = REPLACE(REPLACE(content, '/plugin-assets/TinymceEditor/', '/assets/'), '/plugin-assets/AutoAvatar/', '/assets/avatars/') WHERE content LIKE '%/plugin-assets/%'"
        );

        if ($threadResult > 0) {
            $this->urlUpdates[] = "更新了 {$threadResult} 个帖子内容 URL";
            echo "  ✓ 更新了 {$threadResult} 个帖子内容 URL\n";
        }

        // 更新回复内容中的插件资源 URL
        $postResult = \Core\Database::execute(
            "UPDATE posts SET content = REPLACE(REPLACE(content, '/plugin-assets/TinymceEditor/', '/assets/'), '/plugin-assets/AutoAvatar/', '/assets/avatars/') WHERE content LIKE '%/plugin-assets/%'"
        );

        if ($postResult > 0) {
            $this->urlUpdates[] = "更新了 {$postResult} 个回复内容 URL";
            echo "  ✓ 更新了 {$postResult} 个回复内容 URL\n";
        }
    }
}

// 执行迁移
$migrator = new PluginAssetMigrator();
$migrator->run();
