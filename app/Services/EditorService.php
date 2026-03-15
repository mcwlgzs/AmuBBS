<?php
/**
 * 编辑器服务 - 管理编辑器类型和配置
 * 支持 Markdown 和 TinyMCE 两种编辑器
 */

namespace App\Services;

class EditorService
{
    /**
     * 获取当前编辑器类型
     *
     * @return string 'markdown' 或 'tinymce'
     */
    public static function getEditorType(): string
    {
        $type = SettingSvc::get('editor_type', 'markdown');
        
        // 验证编辑器类型
        if (!in_array($type, ['markdown', 'tinymce'], true)) {
            return 'markdown';
        }
        
        return $type;
    }

    /**
     * 检查特定功能是否启用
     *
     * @param string $feature 功能名称
     * @return bool
     */
    public static function isFeatureEnabled(string $feature): bool
    {
        // 如果编辑器类型不是 tinymce，则所有 tinymce 功能都不启用
        if (self::getEditorType() !== 'tinymce' && strpos($feature, 'tinymce_') === 0) {
            return false;
        }
        
        // 支持的功能配置项
        $validFeatures = [
            'tinymce_enable_post_editor',
            'tinymce_enable_reply_editor',
        ];
        
        if (!in_array($feature, $validFeatures, true)) {
            return false;
        }
        
        return SettingSvc::getBool($feature, false);
    }

    /**
     * 获取 TinyMCE 资源 URL
     *
     * @param string $path 资源路径（相对于 tinymce 目录）
     * @return string 完整的资源 URL
     */
    public static function getAssetUrl(string $path): string
    {
        // 验证路径，防止路径遍历攻击
        $path = str_replace(['..', '\\'], ['', '/'], $path);
        $path = ltrim($path, '/');
        
        return '/assets/tinymce/' . $path;
    }

    /**
     * 检查是否应该在发帖页面使用 TinyMCE
     *
     * @return bool
     */
    public static function shouldUseInPostEditor(): bool
    {
        return self::getEditorType() === 'tinymce' 
            && self::isFeatureEnabled('tinymce_enable_post_editor');
    }

    /**
     * 检查是否应该在回复页面使用 TinyMCE
     *
     * @return bool
     */
    public static function shouldUseInReplyEditor(): bool
    {
        return self::getEditorType() === 'tinymce' 
            && self::isFeatureEnabled('tinymce_enable_reply_editor');
    }

    /**
     * 获取编辑器配置（用于前端）
     *
     * @return array 编辑器配置数组
     */
    public static function getEditorConfig(): array
    {
        $type = self::getEditorType();
        
        $config = [
            'type' => $type,
        ];
        
        if ($type === 'tinymce') {
            $config['tinymce'] = [
                'enable_post_editor' => self::isFeatureEnabled('tinymce_enable_post_editor'),
                'enable_reply_editor' => self::isFeatureEnabled('tinymce_enable_reply_editor'),
                'asset_url' => self::getAssetUrl('tinymce.min.js'),
            ];
        }
        
        return $config;
    }
}
