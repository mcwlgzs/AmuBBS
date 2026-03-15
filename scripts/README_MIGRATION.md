# 插件系统移除迁移指南

## 概述

本指南说明如何将 AMuBBS 论坛系统的插件架构迁移为内置服务系统。

## 已完成的工作

### 1. 服务类创建
已创建以下服务类，位于 `app/Services/` 目录：
- `AutoAvatarService.php` - 自动头像分配服务
- `EmojiService.php` - Emoji 短代码转换服务
- `SocialLoginService.php` - 社交登录服务
- `EditorService.php` - 编辑器管理服务

### 2. 迁移脚本
已创建以下迁移脚本，位于 `scripts/` 目录：
- `migrate_plugin_config.php` - 配置迁移脚本
- `migrate_plugin_assets.php` - 静态资源迁移脚本

### 3. 事件系统更新
已更新 `core/Bootstrap.php`：
- 注册自动头像事件监听器（用户注册时触发）
- 注册 Emoji 过滤器（Markdown 渲染时触发）
- 更新社交登录路由，使用 SocialLoginService 替代插件

## 迁移步骤

### 步骤 1: 备份数据
```bash
# 备份数据库
mysqldump -u root -p amubbs > backup_$(date +%Y%m%d).sql

# 备份插件配置和资源
cp -r storage/plugin_config storage/plugin_config.backup
cp -r plugins plugins.backup
cp -r public/plugin-assets public/plugin-assets.backup
```

### 步骤 2: 运行配置迁移
```bash
php scripts/migrate_plugin_config.php
```

这将：
- 读取 `storage/plugin_config/` 下的所有插件配置
- 将配置迁移到 `settings` 表
- 输出迁移结果

### 步骤 3: 运行资源迁移
```bash
php scripts/migrate_plugin_assets.php
```

这将：
- 将头像资源从 `plugins/AutoAvatar/assets/` 迁移到 `public/assets/avatars/`
- 将 TinyMCE 资源从 `plugins/TinymceEditor/` 迁移到 `public/assets/tinymce/` 和 `public/assets/prism/`
- 更新数据库中的 URL 引用

### 步骤 4: 验证迁移结果
```bash
# 检查配置是否已迁移
mysql -u root -p amubbs -e "SELECT * FROM settings WHERE \`key\` LIKE 'auto_avatar%' OR \`key\` LIKE 'emoji%' OR \`key\` LIKE 'social_login%' OR \`key\` LIKE 'editor%' OR \`key\` LIKE 'tinymce%';"

# 检查资源文件是否已迁移
ls -la public/assets/avatars/
ls -la public/assets/tinymce/
ls -la public/assets/prism/
```

### 步骤 5: 删除插件基础设施（可选）
**警告：此步骤不可逆，请确保迁移成功后再执行**

```bash
# 删除插件核心类
rm -f core/Plugin.php
rm -f core/PluginInterface.php
rm -f core/PluginLoader.php
rm -f core/PluginManager.php
rm -f core/PluginBootedEvent.php
rm -f core/PluginRegisteredEvent.php

# 删除插件目录和配置
rm -rf plugins/
rm -rf storage/plugin_config/
rm -f storage/plugins.json
rm -rf public/plugin-assets/

# 删除后台插件管理
rm -f app/Controllers/Admin/PluginController.php
rm -f resources/views/admin/plugins.php
rm -f resources/views/admin/plugin_config.php
```

### 步骤 6: 更新 Bootstrap.php
从 `core/Bootstrap.php` 中移除插件系统初始化代码：

找到 `initPlugins()` 方法调用并注释或删除：
```php
// 在 __construct() 方法中
// $this->initPlugins(); // 已移除插件系统
```

找到 `initPlugins()` 方法并删除整个方法。

### 步骤 7: 清理路由
从 `core/Bootstrap.php` 的 `loadRoutes()` 方法中删除插件静态资源路由：

找到并删除以下路由：
```php
// 插件静态资源（支持多级路径）
$this->router->get('/plugin-assets/{plugin}/{file:.+}', function($plugin, $file) {
    // ... 整个路由处理代码
});
```

找到并删除后台插件管理路由：
```php
// 插件管理
$this->router->get('/admin/plugins', ...);
$this->router->post('/admin/plugins/toggle', ...);
// ... 其他插件管理路由
```

## 配置说明

### 自动头像配置
在系统设置中添加以下配置项：
- `auto_avatar_enabled` (布尔值) - 是否启用自动头像
- `auto_avatar_overwrite` (布尔值) - 是否覆盖已有头像

### Emoji 配置
- `emoji_enabled` (布尔值) - 是否启用 Emoji 短代码解析

### 社交登录配置
每个提供者需要 3 个配置项：
- `social_login_{provider}_enabled` (布尔值) - 是否启用
- `social_login_{provider}_client_id` (字符串) - Client ID
- `social_login_{provider}_client_secret` (字符串) - Client Secret

支持的提供者：github, google, wechat, qq

### 编辑器配置
- `editor_type` (字符串) - 编辑器类型（'markdown' 或 'tinymce'）
- `tinymce_enable_post_editor` (布尔值) - 发帖时启用 TinyMCE
- `tinymce_enable_reply_editor` (布尔值) - 回复时启用 TinyMCE

## 测试清单

### 功能测试
- [ ] 用户注册时自动分配头像
- [ ] Emoji 短代码在帖子和回复中正确转换
- [ ] GitHub 社交登录正常工作
- [ ] Google 社交登录正常工作
- [ ] 微信社交登录正常工作（如已配置）
- [ ] QQ 社交登录正常工作（如已配置）
- [ ] Markdown 编辑器正常工作
- [ ] TinyMCE 编辑器正常工作（如已启用）

### 数据完整性测试
- [ ] 用户头像 URL 正确指向新位置
- [ ] social_logins 表数据完整
- [ ] 帖子和回复内容中的资源 URL 已更新
- [ ] 所有配置项已迁移到 settings 表

### 性能测试
- [ ] 配置读取使用 Redis 缓存
- [ ] 系统启动时间未明显增加
- [ ] 页面加载速度正常

## 回滚方案

如果迁移出现问题，可以按以下步骤回滚：

1. 恢复数据库备份：
```bash
mysql -u root -p amubbs < backup_YYYYMMDD.sql
```

2. 恢复插件文件：
```bash
cp -r plugins.backup plugins
cp -r storage/plugin_config.backup storage/plugin_config
cp -r public/plugin-assets.backup public/plugin-assets
```

3. 恢复 Bootstrap.php 到原始版本（使用 git）：
```bash
git checkout core/Bootstrap.php
```

## 注意事项

1. **不要在生产环境直接执行**：先在测试环境完整测试
2. **保留备份**：至少保留 7 天的备份数据
3. **分步执行**：每个步骤执行后验证结果
4. **监控日志**：迁移后密切关注错误日志
5. **用户通知**：如果社交登录配置有变化，提前通知用户

## 故障排查

### 问题：用户注册后没有自动分配头像
- 检查 `auto_avatar_enabled` 配置是否为 true
- 检查 `public/assets/avatars/` 目录是否存在且有头像文件
- 检查错误日志

### 问题：Emoji 短代码没有转换
- 检查 `emoji_enabled` 配置是否为 true
- 检查 Bootstrap.php 中是否正确注册了 Emoji 过滤器

### 问题：社交登录失败
- 检查对应提供者的配置是否完整
- 检查 client_id 和 client_secret 是否正确
- 检查回调 URL 是否在提供者后台正确配置
- 检查错误日志中的详细错误信息

## 支持

如有问题，请查看：
- 错误日志：`storage/logs/`
- 系统日志：`/admin/logs`
- 数据库日志表：`logs`
