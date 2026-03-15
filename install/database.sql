-- AMuBBS 数据库初始化脚本
-- 版本: 1.0.0
-- 创建日期: 2026-02-22

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 用户表
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `username` VARCHAR(32) NOT NULL COMMENT '用户名',
  `nickname` VARCHAR(32) DEFAULT NULL COMMENT '昵称（可选，唯一）',
  `email` VARCHAR(100) NOT NULL COMMENT '邮箱',
  `password` CHAR(60) NOT NULL COMMENT '密码（bcrypt）',
  `api_token` VARCHAR(64) DEFAULT NULL COMMENT 'API Token',
  `remember_token` VARCHAR(64) DEFAULT NULL COMMENT 'Remember Me Token Hash',
  `avatar` VARCHAR(255) DEFAULT '' COMMENT '头像URL',
  `group_id` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '用户组ID',
  `signature` VARCHAR(255) DEFAULT '' COMMENT '个性签名',
  `nickname_color` VARCHAR(20) DEFAULT NULL COMMENT '昵称颜色（如 #FF0000）',
  `credits` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '积分',
  `thread_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '主题数',
  `post_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '回复数',
  `unread_notifications` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '未读通知数',
  `login_ip` VARCHAR(45) DEFAULT '' COMMENT '最后登录IP',
  `login_at` INT UNSIGNED DEFAULT 0 COMMENT '最后登录时间',
  `created_at` INT UNSIGNED NOT NULL COMMENT '注册时间',
  `updated_at` INT UNSIGNED DEFAULT 0 COMMENT '更新时间',
  `deleted_at` INT UNSIGNED DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  UNIQUE KEY `uk_users_email` (`email`),
  UNIQUE KEY `uk_users_nickname` (`nickname`),
  KEY `idx_users_group_id` (`group_id`),
  KEY `idx_users_credits` (`credits`),
  KEY `idx_users_created_deleted` (`deleted_at`, `created_at`),
  KEY `idx_users_credits_rank` (`deleted_at`, `credits` DESC),
  UNIQUE KEY `uk_users_api_token` (`api_token`),
  KEY `idx_users_remember_token` (`remember_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- ----------------------------
-- 用户组表
-- ----------------------------
DROP TABLE IF EXISTS `user_groups`;
CREATE TABLE `user_groups` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户组ID',
  `name` VARCHAR(32) NOT NULL COMMENT '组名',
  `permissions` TEXT COMMENT '权限列表（JSON）',
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否管理员',
  `allow_read` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '允许浏览',
  `allow_thread` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '允许发帖',
  `allow_post` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '允许回复',
  `allow_attach` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '允许上传附件',
  `allow_down` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '允许下载附件',
  `allow_top` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '允许置顶',
  `allow_update` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '允许编辑他人',
  `allow_delete` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '允许删除他人',
  `allow_move` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '允许移动帖子',
  `allow_ban_user` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '允许封禁用户',
  `allow_delete_user` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '允许删除用户',
  `allow_view_ip` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '允许查看IP',
  `credits_from` INT UNSIGNED DEFAULT NULL COMMENT '积分区间下限（含，自动升级用）',
  `credits_to` INT UNSIGNED DEFAULT NULL COMMENT '积分区间上限（不含，自动升级用）',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户组表';

-- 初始化用户组
INSERT INTO `user_groups` (`id`, `name`, `permissions`, `is_admin`, `allow_read`, `allow_thread`, `allow_post`, `allow_attach`, `allow_down`, `allow_top`, `allow_update`, `allow_delete`, `allow_move`, `allow_ban_user`, `allow_delete_user`, `allow_view_ip`, `created_at`) VALUES
(1, '普通用户', '{"thread.create":true,"post.create":true}', 0, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, UNIX_TIMESTAMP()),
(2, '版主', '{"thread.create":true,"post.create":true,"thread.edit":true,"post.edit":true}', 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 1, UNIX_TIMESTAMP()),
(3, '管理员', '{"*":true}', 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, UNIX_TIMESTAMP()),
(4, '待验证用户', '{}', 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, UNIX_TIMESTAMP()),
(5, '禁止用户组', '{}', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, UNIX_TIMESTAMP());

-- 初始化默认管理员（安装后请立即修改密码）
INSERT INTO `users` (`id`, `username`, `email`, `password`, `group_id`, `credits`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'admin@amubbs.com', '$2y$10$pa/F6kqJfSzuk8Z7Sm1YQusxzEPYMx.9lTRyAj8FTaoNgq9PgQOfm', 3, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- ----------------------------
-- 板块表
-- ----------------------------
DROP TABLE IF EXISTS `forums`;
CREATE TABLE `forums` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '板块ID',
  `parent_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '父板块ID（0为顶级）',
  `name` VARCHAR(64) NOT NULL COMMENT '板块名称',
  `description` VARCHAR(255) DEFAULT '' COMMENT '板块描述',
  `icon` VARCHAR(255) DEFAULT '' COMMENT '板块图标',
  `rank` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序权重',
  `thread_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '主题数',
  `post_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '回复数',
  `today_threads` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '今日主题数',
  `today_posts` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '今日回帖数',
  `last_thread_id` INT UNSIGNED DEFAULT 0 COMMENT '最新帖子ID',
  `last_post_time` INT UNSIGNED DEFAULT 0 COMMENT '最后回复时间',
  `moderators` VARCHAR(255) DEFAULT '' COMMENT '版主ID列表（逗号分隔）',
  `accesson` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用板块级权限控制',
  `announcement` TEXT DEFAULT NULL COMMENT '板块公告',
  `seo_title` VARCHAR(255) DEFAULT '' COMMENT 'SEO标题',
  `seo_keywords` VARCHAR(255) DEFAULT '' COMMENT 'SEO关键词',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  `updated_at` INT UNSIGNED DEFAULT 0 COMMENT '更新时间',
  `deleted_at` INT UNSIGNED DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_forums_parent_id` (`parent_id`),
  KEY `idx_forums_rank` (`rank`),
  KEY `idx_forums_active` (`deleted_at`, `parent_id`, `rank` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='板块表';

-- 初始化板块
INSERT INTO `forums` (`id`, `parent_id`, `name`, `description`, `rank`, `created_at`) VALUES
(1, 0, '站务管理', '网站公告、建议反馈', 100, UNIX_TIMESTAMP()),
(2, 0, '技术讨论', '技术交流、问题求助', 90, UNIX_TIMESTAMP()),
(3, 2, 'PHP', 'PHP 相关讨论', 80, UNIX_TIMESTAMP()),
(4, 2, 'JavaScript', 'JavaScript 相关讨论', 70, UNIX_TIMESTAMP()),
(5, 0, '灌水乐园', '闲聊灌水、娱乐八卦', 60, UNIX_TIMESTAMP());

-- ----------------------------
-- 板块访问权限表
-- ----------------------------
DROP TABLE IF EXISTS `forum_access`;
CREATE TABLE `forum_access` (
  `forum_id` INT UNSIGNED NOT NULL COMMENT '板块ID',
  `group_id` TINYINT UNSIGNED NOT NULL COMMENT '用户组ID',
  `allow_read` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '允许浏览',
  `allow_thread` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '允许发帖',
  `allow_post` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '允许回复',
  `allow_attach` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '允许上传附件',
  `allow_down` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '允许下载附件',
  PRIMARY KEY (`forum_id`, `group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='板块访问权限表';

-- ----------------------------
-- 帖子表
-- ----------------------------
DROP TABLE IF EXISTS `threads`;
CREATE TABLE `threads` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '帖子ID',
  `forum_id` INT UNSIGNED NOT NULL COMMENT '板块ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '发帖用户ID',
  `username` VARCHAR(32) NOT NULL COMMENT '用户名（冗余）',
  `user_ip` VARCHAR(45) DEFAULT '' COMMENT '发帖IP',
  `title` VARCHAR(100) NOT NULL COMMENT '帖子标题',
  `content` MEDIUMTEXT NOT NULL COMMENT '帖子内容',
  `content_fmt` MEDIUMTEXT COMMENT '格式化后的HTML内容',
  `views` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览次数',
  `reply_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '回复数',
  `is_top` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '置顶级别：0普通 1板块置顶 2全局置顶',
  `is_highlight` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '精华级别：0普通 1精华I 2精华II 3精华III',
  `is_locked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否锁定',
  `likes` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '点赞数',
  `last_post_user_id` INT UNSIGNED DEFAULT 0 COMMENT '最后回复用户ID',
  `last_post_time` INT UNSIGNED DEFAULT 0 COMMENT '最后回复时间',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  `updated_at` INT UNSIGNED DEFAULT 0 COMMENT '更新时间',
  `deleted_at` INT UNSIGNED DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_threads_forum_id` (`forum_id`, `is_top`, `last_post_time`),
  KEY `idx_threads_user_deleted_created` (`user_id`, `deleted_at`, `created_at` DESC),
  KEY `idx_threads_last_post_time` (`last_post_time`),
  KEY `idx_threads_list` (`deleted_at`, `is_top`, `created_at` DESC),
  KEY `idx_threads_hot` (`deleted_at`, `created_at`, `reply_count`, `views`),
  KEY `idx_threads_forum_latest` (`forum_id`, `deleted_at`, `id` DESC),
  KEY `idx_threads_highlight` (`deleted_at`, `is_highlight`),
  KEY `idx_threads_top` (`is_top`, `deleted_at`, `updated_at` DESC),
  FULLTEXT KEY `ft_threads_title_content` (`title`, `content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='帖子表';

-- ----------------------------
-- 回复表
-- ----------------------------
DROP TABLE IF EXISTS `posts`;
CREATE TABLE `posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '回复ID',
  `thread_id` INT UNSIGNED NOT NULL COMMENT '帖子ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '回复用户ID',
  `username` VARCHAR(32) NOT NULL COMMENT '用户名（冗余）',
  `user_ip` VARCHAR(45) DEFAULT '' COMMENT '回复IP',
  `content` MEDIUMTEXT NOT NULL COMMENT '回复内容',
  `content_fmt` MEDIUMTEXT COMMENT '格式化后的HTML内容',
  `content_format` ENUM('text', 'html', 'markdown') NOT NULL DEFAULT 'markdown' COMMENT '内容格式',
  `quote_post_id` INT UNSIGNED DEFAULT 0 COMMENT '引用回复ID',
  `floor` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '楼层号',
  `likes` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '点赞数',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  `updated_at` INT UNSIGNED DEFAULT 0 COMMENT '更新时间',
  `deleted_at` INT UNSIGNED DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_posts_thread_deleted_floor` (`thread_id`, `deleted_at`, `floor`),
  KEY `idx_posts_user_deleted_created` (`user_id`, `deleted_at`, `created_at` DESC),
  KEY `idx_posts_created_deleted` (`deleted_at`, `created_at`),
  FULLTEXT KEY `ft_posts_content` (`content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='回复表';

-- ----------------------------
-- 公告表
-- ----------------------------
DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '公告ID',
  `title` VARCHAR(200) NOT NULL COMMENT '公告标题',
  `content` TEXT COMMENT '公告内容（可选）',
  `url` VARCHAR(500) DEFAULT '' COMMENT '跳转链接（可选）',
  `type` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '类型：0普通 1重要 2紧急',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
  `rank` INT NOT NULL DEFAULT 0 COMMENT '排序权重（越大越靠前）',
  `start_at` INT UNSIGNED DEFAULT NULL COMMENT '生效时间（NULL则立即生效）',
  `end_at` INT UNSIGNED DEFAULT NULL COMMENT '过期时间（NULL则永不过期）',
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人ID',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  `updated_at` INT UNSIGNED DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_announcements_enabled` (`is_enabled`, `rank`),
  KEY `idx_announcements_time` (`is_enabled`, `start_at`, `end_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告表';

-- ----------------------------
-- 配置表
-- ----------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `key` VARCHAR(64) NOT NULL COMMENT '配置键',
  `value` TEXT COMMENT '配置值',
  `description` VARCHAR(255) DEFAULT '' COMMENT '配置描述',
  `updated_at` INT UNSIGNED DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='配置表';

-- 初始化配置
INSERT INTO `settings` (`key`, `value`, `description`, `updated_at`) VALUES
('site_name', 'AMuBBS', '站点名称', UNIX_TIMESTAMP()),
('site_url', 'http://localhost:8000', '站点URL', UNIX_TIMESTAMP()),
('site_status', '5', '站点状态：0关闭 1管理员可读写 2会员可读 3会员可读写 4所有人只读 5所有人可读写', UNIX_TIMESTAMP()),
('site_description', '基于 PHP 8.2 的轻量化论坛系统', '站点描述', UNIX_TIMESTAMP()),
('site_keywords', 'PHP,论坛,Alpine.js,轻量化', '站点关键词', UNIX_TIMESTAMP()),
('icp_number', '', 'ICP备案号', UNIX_TIMESTAMP()),
('cdn_url', '', 'CDN地址（留空不启用）', UNIX_TIMESTAMP()),
('watermark_enabled', '0', '图片水印开关：0关闭 1开启', UNIX_TIMESTAMP()),
('watermark_text', 'AMuBBS', '水印文字', UNIX_TIMESTAMP()),
('watermark_position', 'bottom-right', '水印位置：bottom-right/bottom-left/top-right/top-left/center', UNIX_TIMESTAMP()),
('watermark_opacity', '30', '水印透明度（0-100）', UNIX_TIMESTAMP()),
('image_max_width', '1920', '图片最大宽度（超过自动缩放，0不限制）', UNIX_TIMESTAMP()),
('image_thumb_width', '400', '缩略图宽度（0不生成）', UNIX_TIMESTAMP()),
('site_runlevel', '5', '站点运行级别：0关站 1仅管理员 2注册用户只读 3注册用户读写 4所有人只读 5完全开放', UNIX_TIMESTAMP()),
('site_maintenance_msg', '', '维护模式提示信息', UNIX_TIMESTAMP()),
('admin_bind_ip', '', '后台IP白名单（逗号分隔，留空不限制）', UNIX_TIMESTAMP()),
('cron_key', '', '定时任务密钥（留空不验证）', UNIX_TIMESTAMP()),
('ip_limit_thread', '20', '每日同IP发帖上限（0不限制）', UNIX_TIMESTAMP()),
('ip_limit_post', '50', '每日同IP回帖上限（0不限制）', UNIX_TIMESTAMP()),
('ip_limit_register', '5', '每日同IP注册上限（0不限制）', UNIX_TIMESTAMP()),
('ip_limit_upload', '30', '每日同IP上传上限（0不限制）', UNIX_TIMESTAMP());

-- ----------------------------
-- 会话表
-- ----------------------------
DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `id` CHAR(40) NOT NULL COMMENT 'Session ID',
  `user_id` INT UNSIGNED DEFAULT 0 COMMENT '用户ID（0为游客）',
  `ip` VARCHAR(45) NOT NULL COMMENT 'IP地址',
  `user_agent` VARCHAR(255) DEFAULT '' COMMENT 'User Agent',
  `data` TEXT COMMENT 'Session数据',
  `last_activity` INT UNSIGNED NOT NULL COMMENT '最后活动时间',
  `current_forum_id` INT UNSIGNED DEFAULT 0 COMMENT '当前浏览板块ID',
  `current_url` VARCHAR(255) DEFAULT '' COMMENT '当前页面URL',
  PRIMARY KEY (`id`),
  KEY `idx_sessions_user_id` (`user_id`),
  KEY `idx_sessions_last_activity` (`last_activity`),
  KEY `idx_sessions_forum` (`current_forum_id`, `last_activity`),
  KEY `idx_sessions_online` (`user_id`, `last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会话表';

-- ----------------------------
-- 集群节点表（分布式部署用）
-- ----------------------------
DROP TABLE IF EXISTS `cluster_nodes`;
CREATE TABLE `cluster_nodes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '节点ID',
  `type` ENUM('web', 'mysql', 'redis') NOT NULL COMMENT '节点类型',
  `name` VARCHAR(64) NOT NULL COMMENT '节点名称',
  `host` VARCHAR(255) NOT NULL COMMENT '主机地址',
  `port` INT UNSIGNED NOT NULL COMMENT '端口',
  `weight` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '权重',
  `config` TEXT COMMENT '配置信息（JSON）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1启用 0禁用',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  `updated_at` INT UNSIGNED DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='集群节点表';

-- ----------------------------
-- 通知表
-- ----------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '通知ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '接收用户ID',
  `from_user_id` INT UNSIGNED DEFAULT 0 COMMENT '发送用户ID（0为系统）',
  `type` VARCHAR(32) NOT NULL COMMENT '通知类型（reply/mention/system）',
  `title` VARCHAR(255) NOT NULL COMMENT '通知标题',
  `content` TEXT COMMENT '通知内容',
  `target_type` VARCHAR(32) DEFAULT '' COMMENT '关联对象类型（thread/post）',
  `target_id` INT UNSIGNED DEFAULT 0 COMMENT '关联对象ID',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已读',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`, `is_read`, `created_at`),
  KEY `idx_notifications_from` (`from_user_id`),
  KEY `idx_notif_unread` (`user_id`, `is_read`),
  KEY `idx_notif_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知表';

-- ----------------------------
-- 附件表
-- ----------------------------
DROP TABLE IF EXISTS `attachments`;
CREATE TABLE `attachments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '附件ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '上传用户ID',
  `thread_id` INT UNSIGNED DEFAULT 0 COMMENT '关联帖子ID',
  `post_id` INT UNSIGNED DEFAULT 0 COMMENT '关联回复ID',
  `filename` VARCHAR(255) NOT NULL COMMENT '原始文件名',
  `filepath` VARCHAR(255) NOT NULL COMMENT '存储路径',
  `filesize` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
  `mimetype` VARCHAR(100) DEFAULT '' COMMENT 'MIME类型',
  `is_image` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否图片',
  `width` SMALLINT UNSIGNED DEFAULT 0 COMMENT '图片宽度',
  `height` SMALLINT UNSIGNED DEFAULT 0 COMMENT '图片高度',
  `downloads` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '下载次数',
  `created_at` INT UNSIGNED NOT NULL COMMENT '上传时间',
  `deleted_at` INT UNSIGNED DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_attachments_thread` (`thread_id`),
  KEY `idx_attachments_user_deleted` (`user_id`, `deleted_at`),
  KEY `idx_attachments_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='附件表';

-- ----------------------------
-- 私信表
-- ----------------------------
DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '私信ID',
  `from_user_id` INT UNSIGNED NOT NULL COMMENT '发送者ID',
  `to_user_id` INT UNSIGNED NOT NULL COMMENT '接收者ID',
  `content` TEXT NOT NULL COMMENT '私信内容',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已读',
  `is_recalled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已撤回',
  `created_at` INT UNSIGNED NOT NULL COMMENT '发送时间',
  `deleted_by_from` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '发送者已删除',
  `deleted_by_to` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '接收者已删除',
  PRIMARY KEY (`id`),
  KEY `idx_messages_to` (`to_user_id`, `is_read`, `created_at`),
  KEY `idx_messages_from` (`from_user_id`, `created_at`),
  KEY `idx_msg_from_to_read` (`from_user_id`, `to_user_id`, `is_read`),
  KEY `idx_msg_to_from_read` (`to_user_id`, `from_user_id`, `is_read`),
  KEY `idx_msg_from_deleted` (`from_user_id`, `deleted_by_from`, `created_at`),
  KEY `idx_msg_to_deleted` (`to_user_id`, `deleted_by_to`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='私信表';

-- ----------------------------
-- 标签表
-- ----------------------------
DROP TABLE IF EXISTS `tags`;
CREATE TABLE `tags` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '标签ID',
  `name` VARCHAR(32) NOT NULL COMMENT '标签名',
  `category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类ID',
  `thread_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '帖子数',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tags_name` (`name`),
  KEY `idx_tags_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='标签表';

-- ----------------------------
-- 帖子标签关联表
-- ----------------------------
DROP TABLE IF EXISTS `thread_tags`;
CREATE TABLE `thread_tags` (
  `thread_id` INT UNSIGNED NOT NULL COMMENT '帖子ID',
  `tag_id` INT UNSIGNED NOT NULL COMMENT '标签ID',
  PRIMARY KEY (`thread_id`, `tag_id`),
  KEY `idx_thread_tags_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='帖子标签关联表';

-- ----------------------------
-- 标签分类表
-- ----------------------------
DROP TABLE IF EXISTS `tag_categories`;
CREATE TABLE `tag_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL COMMENT '分类名称',
  `forum_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联板块ID（0=全局）',
  `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tc_forum` (`forum_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='标签分类表';

-- ----------------------------
-- 友情链接表
-- ----------------------------
DROP TABLE IF EXISTS `friend_links`;
CREATE TABLE `friend_links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL COMMENT '链接名称',
  `url` VARCHAR(500) NOT NULL COMMENT '链接地址',
  `logo` VARCHAR(500) DEFAULT '' COMMENT 'Logo图片',
  `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序（越小越前）',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态：0隐藏 1显示',
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fl_status_sort` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='友情链接';

INSERT INTO `friend_links` (`name`, `url`, `logo`, `sort_order`, `status`, `created_at`) VALUES
('AMuBBS', 'http://bbs.amuchen.com/', '', 0, 1, UNIX_TIMESTAMP()),
('沐辰网络', 'http://amuchen.com/', '', 1, 1, UNIX_TIMESTAMP());

-- ----------------------------
-- 帖子编辑历史表
-- ----------------------------
DROP TABLE IF EXISTS `post_edit_logs`;
CREATE TABLE `post_edit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '回复ID（0表示主帖）',
  `thread_id` INT UNSIGNED NOT NULL COMMENT '帖子ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '编辑者ID',
  `old_content` MEDIUMTEXT COMMENT '编辑前内容',
  `new_content` MEDIUMTEXT COMMENT '编辑后内容',
  `reason` VARCHAR(255) DEFAULT '' COMMENT '编辑原因',
  `created_at` INT UNSIGNED NOT NULL COMMENT '编辑时间',
  PRIMARY KEY (`id`),
  KEY `idx_pel_thread` (`thread_id`),
  KEY `idx_pel_post` (`post_id`),
  KEY `idx_pel_thread_post_user` (`thread_id`, `post_id`, `user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='帖子编辑历史';

-- ----------------------------
-- IP 访问频率记录表
-- ----------------------------
DROP TABLE IF EXISTS `ip_access_logs`;
CREATE TABLE `ip_access_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(45) NOT NULL COMMENT 'IP地址',
  `action` VARCHAR(32) NOT NULL COMMENT '操作类型',
  `count` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '当日计数',
  `date` DATE NOT NULL COMMENT '日期',
  `updated_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip_action_date` (`ip`, `action`, `date`),
  KEY `idx_ial_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IP访问频率记录';

-- ----------------------------
-- 消息队列表
-- ----------------------------
DROP TABLE IF EXISTS `queue_jobs`;
CREATE TABLE `queue_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` VARCHAR(50) NOT NULL DEFAULT 'default' COMMENT '队列名',
  `payload` TEXT NOT NULL COMMENT '任务数据JSON',
  `available_at` INT UNSIGNED NOT NULL COMMENT '可执行时间',
  `reserved_at` INT UNSIGNED DEFAULT NULL COMMENT '被取出时间',
  `reserve_token` VARCHAR(16) DEFAULT NULL COMMENT '抢占令牌',
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_qj_queue_reserved` (`queue`, `reserved_at`, `available_at`),
  KEY `idx_qj_reserved_at` (`reserved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='消息队列';

-- ----------------------------
-- 敏感词表
-- ----------------------------
DROP TABLE IF EXISTS `sensitive_words`;
CREATE TABLE `sensitive_words` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `word` VARCHAR(64) NOT NULL COMMENT '敏感词',
  `replacement` VARCHAR(64) DEFAULT '***' COMMENT '替换文本',
  `level` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '级别：1替换 2禁止发布',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sensitive_word` (`word`),
  KEY `idx_sw_level` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='敏感词表';

-- ----------------------------
-- 默认敏感词数据
-- ----------------------------
INSERT INTO `sensitive_words` (`word`, `replacement`, `level`, `created_at`) VALUES
-- level 2: 禁止发布（涉及违法、诈骗、赌博等）
('代开发票', '', 2, UNIX_TIMESTAMP()),
('代办证件', '', 2, UNIX_TIMESTAMP()),
('办假证', '', 2, UNIX_TIMESTAMP()),
('刷单兼职', '', 2, UNIX_TIMESTAMP()),
('日赚千元', '', 2, UNIX_TIMESTAMP()),
('月入百万', '', 2, UNIX_TIMESTAMP()),
('网赚项目', '', 2, UNIX_TIMESTAMP()),
('彩票预测', '', 2, UNIX_TIMESTAMP()),
('赌博网站', '', 2, UNIX_TIMESTAMP()),
('澳门赌场', '', 2, UNIX_TIMESTAMP()),
('色情网站', '', 2, UNIX_TIMESTAMP()),
('裸聊', '', 2, UNIX_TIMESTAMP()),
('约炮', '', 2, UNIX_TIMESTAMP()),
('代孕', '', 2, UNIX_TIMESTAMP()),
('枪支弹药', '', 2, UNIX_TIMESTAMP()),
('迷药', '', 2, UNIX_TIMESTAMP()),
('窃听器', '', 2, UNIX_TIMESTAMP()),
('黑客接单', '', 2, UNIX_TIMESTAMP()),
('私服外挂', '', 2, UNIX_TIMESTAMP()),
('传奇私服', '', 2, UNIX_TIMESTAMP()),
-- level 1: 替换为 ***（广告、低俗、引流等）
('加微信', '***', 1, UNIX_TIMESTAMP()),
('加QQ群', '***', 1, UNIX_TIMESTAMP()),
('免费领取', '***', 1, UNIX_TIMESTAMP()),
('扫码领红包', '***', 1, UNIX_TIMESTAMP()),
('低价出售', '***', 1, UNIX_TIMESTAMP()),
('全网最低', '***', 1, UNIX_TIMESTAMP()),
('货到付款', '***', 1, UNIX_TIMESTAMP()),
('代理加盟', '***', 1, UNIX_TIMESTAMP()),
('招收代理', '***', 1, UNIX_TIMESTAMP()),
('兼职日结', '***', 1, UNIX_TIMESTAMP()),
('在家赚钱', '***', 1, UNIX_TIMESTAMP()),
('躺着赚钱', '***', 1, UNIX_TIMESTAMP()),
('稳赚不赔', '***', 1, UNIX_TIMESTAMP()),
('高仿', '***', 1, UNIX_TIMESTAMP()),
('A货', '***', 1, UNIX_TIMESTAMP()),
('复刻表', '***', 1, UNIX_TIMESTAMP()),
('原单', '***', 1, UNIX_TIMESTAMP()),
('尾单', '***', 1, UNIX_TIMESTAMP()),
('草泥马', '***', 1, UNIX_TIMESTAMP()),
('傻逼', '***', 1, UNIX_TIMESTAMP()),
('妈逼', '***', 1, UNIX_TIMESTAMP()),
('狗日的', '***', 1, UNIX_TIMESTAMP()),
('贱人', '***', 1, UNIX_TIMESTAMP()),
('废物', '***', 1, UNIX_TIMESTAMP()),
('去死吧', '***', 1, UNIX_TIMESTAMP());

-- ----------------------------
-- IP 黑名单表
-- ----------------------------
DROP TABLE IF EXISTS `ip_blacklist`;
CREATE TABLE `ip_blacklist` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `ip` VARCHAR(45) NOT NULL COMMENT 'IP地址',
  `reason` VARCHAR(255) DEFAULT '' COMMENT '封禁原因',
  `expire_at` INT UNSIGNED DEFAULT 0 COMMENT '过期时间（0为永久）',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip` (`ip`),
  KEY `idx_ibl_expire` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IP黑名单表';

-- ----------------------------
-- 操作日志表
-- ----------------------------
DROP TABLE IF EXISTS `logs`;
CREATE TABLE `logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作用户ID',
  `action` VARCHAR(64) NOT NULL COMMENT '操作类型',
  `target_type` VARCHAR(32) DEFAULT '' COMMENT '对象类型',
  `target_id` INT UNSIGNED DEFAULT 0 COMMENT '对象ID',
  `detail` TEXT COMMENT '详细信息（JSON）',
  `ip` VARCHAR(45) DEFAULT '' COMMENT 'IP地址',
  `created_at` INT UNSIGNED NOT NULL COMMENT '操作时间',
  PRIMARY KEY (`id`),
  KEY `idx_logs_user` (`user_id`),
  KEY `idx_logs_action` (`action`, `created_at`),
  KEY `idx_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志表';

-- ----------------------------
-- 点赞记录表
-- ----------------------------
DROP TABLE IF EXISTS `post_likes`;
CREATE TABLE `post_likes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '点赞ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `thread_id` INT UNSIGNED DEFAULT NULL COMMENT '帖子ID',
  `post_id` INT UNSIGNED DEFAULT NULL COMMENT '回复ID',
  `created_at` INT UNSIGNED NOT NULL COMMENT '点赞时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_thread` (`user_id`, `thread_id`),
  UNIQUE KEY `uk_user_post` (`user_id`, `post_id`),
  KEY `idx_thread_id` (`thread_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_pl_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='点赞记录表';

-- ----------------------------
-- 签到记录表
-- ----------------------------
DROP TABLE IF EXISTS `user_checkins`;
CREATE TABLE `user_checkins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '签到ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `checkin_date` DATE NOT NULL COMMENT '签到日期',
  `consecutive_days` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '连续签到天数',
  `credits` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '获得积分',
  `created_at` INT UNSIGNED NOT NULL COMMENT '签到时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date` (`user_id`, `checkin_date`),
  KEY `idx_checkin_date` (`checkin_date`),
  KEY `idx_checkin_rank` (`checkin_date`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='签到记录表';

-- ----------------------------
-- 帖子收藏表
-- ----------------------------
DROP TABLE IF EXISTS `user_favorites`;
CREATE TABLE `user_favorites` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '收藏ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `thread_id` INT UNSIGNED NOT NULL COMMENT '帖子ID',
  `created_at` INT UNSIGNED NOT NULL COMMENT '收藏时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_thread` (`user_id`, `thread_id`),
  KEY `idx_thread_id` (`thread_id`),
  KEY `idx_uf_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='帖子收藏表';

-- ----------------------------
-- 用户关注表
-- ----------------------------
DROP TABLE IF EXISTS `user_follows`;
CREATE TABLE `user_follows` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '关注ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '关注者ID',
  `follow_user_id` INT UNSIGNED NOT NULL COMMENT '被关注者ID',
  `created_at` INT UNSIGNED NOT NULL COMMENT '关注时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_follow` (`user_id`, `follow_user_id`),
  KEY `idx_follow_user` (`follow_user_id`),
  KEY `idx_ufl_user_created` (`user_id`, `created_at`),
  KEY `idx_ufl_follower_created` (`follow_user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户关注表';

-- ----------------------------
-- 积分记录表
-- ----------------------------
DROP TABLE IF EXISTS `credit_logs`;
CREATE TABLE `credit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `amount` INT NOT NULL COMMENT '积分变动（正数为增加，负数为减少）',
  `balance` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '变动后余额',
  `type` VARCHAR(32) NOT NULL COMMENT '类型（checkin/post/thread/reward/consume）',
  `description` VARCHAR(255) DEFAULT '' COMMENT '描述',
  `related_type` VARCHAR(32) DEFAULT '' COMMENT '关联对象类型（thread/post）',
  `related_id` INT UNSIGNED DEFAULT 0 COMMENT '关联对象ID',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`, `created_at`),
  KEY `idx_type` (`type`),
  KEY `idx_credit_purchase` (`user_id`, `type`, `related_type`, `related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='积分记录表';

-- ----------------------------
-- 打赏记录表
-- ----------------------------
DROP TABLE IF EXISTS `rewards`;
CREATE TABLE `rewards` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '打赏ID',
  `from_user_id` INT UNSIGNED NOT NULL COMMENT '打赏者ID',
  `to_user_id` INT UNSIGNED NOT NULL COMMENT '接收者ID',
  `amount` INT UNSIGNED NOT NULL COMMENT '打赏积分',
  `target_type` VARCHAR(32) NOT NULL COMMENT '打赏对象类型（thread/post）',
  `target_id` INT UNSIGNED NOT NULL COMMENT '打赏对象ID',
  `message` VARCHAR(255) DEFAULT '' COMMENT '打赏留言',
  `created_at` INT UNSIGNED NOT NULL COMMENT '打赏时间',
  PRIMARY KEY (`id`),
  KEY `idx_from_user` (`from_user_id`),
  KEY `idx_to_user` (`to_user_id`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_reward_check` (`from_user_id`, `target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='打赏记录表';

-- ----------------------------
-- 用户等级表
-- ----------------------------
DROP TABLE IF EXISTS `user_levels`;
CREATE TABLE `user_levels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '等级ID',
  `name` VARCHAR(50) NOT NULL COMMENT '等级名称',
  `level` INT UNSIGNED NOT NULL COMMENT '等级数值',
  `min_credits` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所需最低积分',
  `icon` VARCHAR(255) DEFAULT '' COMMENT '等级图标',
  `color` VARCHAR(20) DEFAULT '' COMMENT '等级颜色',
  `benefits` TEXT COMMENT '等级权益（JSON）',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_level` (`level`),
  KEY `idx_credits` (`min_credits`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户等级表';

-- 插入默认等级数据
INSERT INTO `user_levels` (`name`, `level`, `min_credits`, `color`, `created_at`) VALUES
('学前班', 0, 0, '#999999', UNIX_TIMESTAMP()),
('小学', 1, 100, '#52c41a', UNIX_TIMESTAMP()),
('初中', 2, 500, '#1890ff', UNIX_TIMESTAMP()),
('高中', 3, 1500, '#722ed1', UNIX_TIMESTAMP()),
('大学', 4, 3000, '#eb2f96', UNIX_TIMESTAMP()),
('研究生', 5, 6000, '#fa8c16', UNIX_TIMESTAMP()),
('博士', 6, 10000, '#f5222d', UNIX_TIMESTAMP()),
('博导', 7, 20000, '#faad14', UNIX_TIMESTAMP());

-- ----------------------------
-- 浏览历史表
-- ----------------------------
DROP TABLE IF EXISTS `browse_history`;
CREATE TABLE `browse_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `thread_id` INT UNSIGNED NOT NULL COMMENT '帖子ID',
  `created_at` INT UNSIGNED NOT NULL COMMENT '浏览时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_thread` (`user_id`, `thread_id`),
  KEY `idx_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='浏览历史表';

-- ----------------------------
-- 动态/说说表
-- ----------------------------
DROP TABLE IF EXISTS `moments`;
CREATE TABLE `moments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '动态ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `content` TEXT NOT NULL COMMENT '动态内容',
  `images` TEXT COMMENT '图片列表（JSON）',
  `likes` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '点赞数',
  `comment_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '评论数',
  `created_at` INT UNSIGNED NOT NULL COMMENT '发布时间',
  `deleted_at` INT UNSIGNED DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_moments_deleted_created` (`deleted_at`, `created_at` DESC),
  KEY `idx_moments_user_deleted_created` (`user_id`, `deleted_at`, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态/说说表';

-- ----------------------------
-- 动态评论表
-- ----------------------------
DROP TABLE IF EXISTS `moment_comments`;
CREATE TABLE `moment_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '评论ID',
  `moment_id` INT UNSIGNED NOT NULL COMMENT '动态ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `reply_user_id` INT UNSIGNED DEFAULT 0 COMMENT '回复用户ID',
  `content` TEXT NOT NULL COMMENT '评论内容',
  `created_at` INT UNSIGNED NOT NULL COMMENT '评论时间',
  `deleted_at` INT UNSIGNED DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_mc_moment_deleted_created` (`moment_id`, `deleted_at`, `created_at`),
  KEY `idx_mc_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态评论表';

-- ----------------------------
-- 动态点赞表
-- ----------------------------
DROP TABLE IF EXISTS `moment_likes`;
CREATE TABLE `moment_likes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '点赞ID',
  `moment_id` INT UNSIGNED NOT NULL COMMENT '动态ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `created_at` INT UNSIGNED NOT NULL COMMENT '点赞时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_moment_user` (`moment_id`, `user_id`),
  KEY `idx_ml_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='动态点赞表';

-- ----------------------------
-- 任务领取记录表
-- ----------------------------
DROP TABLE IF EXISTS `user_task_claims`;
CREATE TABLE `user_task_claims` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `task_key` VARCHAR(32) NOT NULL COMMENT '任务标识',
  `credits` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '获得积分',
  `claim_date` DATE NOT NULL COMMENT '领取日期',
  `created_at` INT UNSIGNED NOT NULL COMMENT '领取时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_task_date` (`user_id`, `task_key`, `claim_date`),
  KEY `idx_claim_date` (`claim_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任务领取记录表';

-- ----------------------------
-- VIP 会员表
-- ----------------------------
DROP TABLE IF EXISTS `user_vip`;
CREATE TABLE `user_vip` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `vip_level` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'VIP等级（1白银2黄金3铂金4钻石）',
  `expire_at` INT UNSIGNED NOT NULL COMMENT '到期时间',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  `updated_at` INT UNSIGNED DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_vip` (`user_id`),
  KEY `idx_expire` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VIP会员表';

-- ----------------------------
-- 导航分类表
-- ----------------------------
DROP TABLE IF EXISTS `nav_categories`;
CREATE TABLE `nav_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '分类ID',
  `name` VARCHAR(64) NOT NULL COMMENT '分类名称',
  `icon` VARCHAR(255) DEFAULT '' COMMENT '分类图标',
  `rank` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序权重',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  `deleted_at` INT UNSIGNED DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_nc_deleted_rank` (`deleted_at`, `rank` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='导航分类表';

-- ----------------------------
-- 导航链接表
-- ----------------------------
DROP TABLE IF EXISTS `nav_links`;
CREATE TABLE `nav_links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '链接ID',
  `category_id` INT UNSIGNED NOT NULL COMMENT '分类ID',
  `name` VARCHAR(100) NOT NULL COMMENT '链接名称',
  `url` VARCHAR(500) NOT NULL COMMENT '链接地址',
  `description` VARCHAR(255) DEFAULT '' COMMENT '链接描述',
  `icon` VARCHAR(500) DEFAULT '' COMMENT '图标URL',
  `clicks` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '点击次数',
  `rank` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序权重',
  `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
  `deleted_at` INT UNSIGNED DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_nl_category_deleted_rank` (`category_id`, `deleted_at`, `rank` DESC),
  KEY `idx_nl_deleted_clicks` (`deleted_at`, `clicks`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='导航链接表';

-- 插入示例导航数据
INSERT INTO `nav_categories` (`name`, `icon`, `rank`, `created_at`) VALUES
('常用工具', '🔧', 100, UNIX_TIMESTAMP()),
('技术社区', '💻', 90, UNIX_TIMESTAMP()),
('设计资源', '🎨', 80, UNIX_TIMESTAMP());

INSERT INTO `nav_links` (`category_id`, `name`, `url`, `description`, `clicks`, `rank`, `created_at`) VALUES
(1, 'GitHub', 'https://github.com', '全球最大的代码托管平台', 0, 100, UNIX_TIMESTAMP()),
(1, 'Google', 'https://www.google.com', '全球最大的搜索引擎', 0, 90, UNIX_TIMESTAMP()),
(2, 'Stack Overflow', 'https://stackoverflow.com', '程序员问答社区', 0, 100, UNIX_TIMESTAMP()),
(2, 'V2EX', 'https://www.v2ex.com', '创意工作者社区', 0, 90, UNIX_TIMESTAMP()),
(3, 'Dribbble', 'https://dribbble.com', '设计师作品展示平台', 0, 100, UNIX_TIMESTAMP()),
(3, 'Figma', 'https://www.figma.com', '在线协作设计工具', 0, 90, UNIX_TIMESTAMP());

-- ----------------------------
-- 默认帖子（站务公告）
-- ----------------------------
INSERT INTO `threads` (`id`, `forum_id`, `user_id`, `username`, `title`, `content`, `content_fmt`, `views`, `reply_count`, `is_top`, `is_highlight`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'admin', '欢迎来到 AMuBBS 社区',
'## 欢迎\n\n欢迎加入 AMuBBS 社区！这是一个轻量、快速、现代的论坛系统。\n\n### 快速开始\n\n- **浏览板块**：在首页选择感兴趣的板块\n- **发表主题**：点击「发表新主题」按钮\n- **参与讨论**：在帖子下方回复交流\n- **每日签到**：签到可获得积分奖励\n\n### 功能特色\n\n- Markdown 编辑器，支持代码高亮\n- 积分系统与等级体系\n- 每日签到与任务中心\n- 私信与通知系统\n- 暗色模式\n\n如有任何问题，欢迎在本板块发帖反馈。祝你在社区玩得愉快！',
'<h2>欢迎</h2><p>欢迎加入 AMuBBS 社区！这是一个轻量、快速、现代的论坛系统。</p><h3>快速开始</h3><ul><li><strong>浏览板块</strong>：在首页选择感兴趣的板块</li><li><strong>发表主题</strong>：点击「发表新主题」按钮</li><li><strong>参与讨论</strong>：在帖子下方回复交流</li><li><strong>每日签到</strong>：签到可获得积分奖励</li></ul><h3>功能特色</h3><ul><li>Markdown 编辑器，支持代码高亮</li><li>积分系统与等级体系</li><li>每日签到与任务中心</li><li>私信与通知系统</li><li>暗色模式</li></ul><p>如有任何问题，欢迎在本板块发帖反馈。祝你在社区玩得愉快！</p>',
10, 0, 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

(2, 1, 1, 'admin', '社区规范',
'## 社区规范\n\n为了维护良好的社区氛围，请所有用户遵守以下规范：\n\n### 一、基本准则\n\n1. **尊重他人**：禁止人身攻击、侮辱、歧视等不友善行为\n2. **文明用语**：禁止发布低俗、色情、暴力等不当内容\n3. **真实交流**：禁止恶意灌水、刷屏、发布垃圾广告\n4. **保护隐私**：禁止未经授权公开他人个人信息\n\n### 二、发帖规范\n\n1. 标题应简洁明了，准确描述帖子内容\n2. 选择正确的板块发帖\n3. 发布技术问题时，请提供必要的上下文信息\n4. 禁止重复发帖，发帖前请先搜索是否已有相关讨论\n\n### 三、回复规范\n\n1. 回复应与主题相关，有实质性内容\n2. 引用他人内容请注明出处\n3. 对于技术问题，鼓励提供详细的解答和参考资料\n\n### 四、违规处理\n\n- **轻微违规**：警告并删除相关内容\n- **多次违规**：临时禁言（1-30天）\n- **严重违规**：永久封禁账号\n\n管理员和版主有权根据实际情况进行处理。如对处理结果有异议，可通过私信联系管理员申诉。',
'<h2>社区规范</h2><p>为了维护良好的社区氛围，请所有用户遵守以下规范：</p><h3>一、基本准则</h3><ol><li><strong>尊重他人</strong>：禁止人身攻击、侮辱、歧视等不友善行为</li><li><strong>文明用语</strong>：禁止发布低俗、色情、暴力等不当内容</li><li><strong>真实交流</strong>：禁止恶意灌水、刷屏、发布垃圾广告</li><li><strong>保护隐私</strong>：禁止未经授权公开他人个人信息</li></ol><h3>二、发帖规范</h3><ol><li>标题应简洁明了，准确描述帖子内容</li><li>选择正确的板块发帖</li><li>发布技术问题时，请提供必要的上下文信息</li><li>禁止重复发帖，发帖前请先搜索是否已有相关讨论</li></ol><h3>三、回复规范</h3><ol><li>回复应与主题相关，有实质性内容</li><li>引用他人内容请注明出处</li><li>对于技术问题，鼓励提供详细的解答和参考资料</li></ol><h3>四、违规处理</h3><ul><li><strong>轻微违规</strong>：警告并删除相关内容</li><li><strong>多次违规</strong>：临时禁言（1-30天）</li><li><strong>严重违规</strong>：永久封禁账号</li></ul><p>管理员和版主有权根据实际情况进行处理。如对处理结果有异议，可通过私信联系管理员申诉。</p>',
5, 0, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

(3, 1, 1, 'admin', '隐私政策',
'## 隐私政策\n\n本隐私政策说明我们如何收集、使用和保护您的个人信息。\n\n### 一、信息收集\n\n1. **账户信息**：用户名、邮箱地址（注册时提供）\n2. **使用数据**：浏览记录、发帖记录、登录 IP 地址\n3. **设备信息**：浏览器类型、操作系统（通过 User-Agent）\n\n### 二、信息使用\n\n- 提供和改善社区服务\n- 账户安全保护和异常检测\n- 社区统计和数据分析（匿名化处理）\n- 发送必要的系统通知\n\n### 三、信息保护\n\n- 密码使用 bcrypt 算法加密存储\n- 敏感操作需要身份验证\n- 定期备份数据，防止数据丢失\n- 不会向第三方出售或共享您的个人信息\n\n### 四、Cookie 使用\n\n我们使用 Cookie 来维持您的登录状态和偏好设置。您可以通过浏览器设置管理 Cookie。\n\n### 五、用户权利\n\n- 查看和修改您的个人信息\n- 删除您发布的内容\n- 注销您的账户\n- 要求导出您的个人数据\n\n本政策可能会不定期更新，继续使用本站服务即表示您同意更新后的隐私政策。',
'<h2>隐私政策</h2><p>本隐私政策说明我们如何收集、使用和保护您的个人信息。</p><h3>一、信息收集</h3><ol><li><strong>账户信息</strong>：用户名、邮箱地址（注册时提供）</li><li><strong>使用数据</strong>：浏览记录、发帖记录、登录 IP 地址</li><li><strong>设备信息</strong>：浏览器类型、操作系统（通过 User-Agent）</li></ol><h3>二、信息使用</h3><ul><li>提供和改善社区服务</li><li>账户安全保护和异常检测</li><li>社区统计和数据分析（匿名化处理）</li><li>发送必要的系统通知</li></ul><h3>三、信息保护</h3><ul><li>密码使用 bcrypt 算法加密存储</li><li>敏感操作需要身份验证</li><li>定期备份数据，防止数据丢失</li><li>不会向第三方出售或共享您的个人信息</li></ul><h3>四、Cookie 使用</h3><p>我们使用 Cookie 来维持您的登录状态和偏好设置。您可以通过浏览器设置管理 Cookie。</p><h3>五、用户权利</h3><ul><li>查看和修改您的个人信息</li><li>删除您发布的内容</li><li>注销您的账户</li><li>要求导出您的个人数据</li></ul><p>本政策可能会不定期更新，继续使用本站服务即表示您同意更新后的隐私政策。</p>',
3, 0, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

(4, 1, 1, 'admin', '版权声明',
'## 版权声明\n\n### 一、用户内容\n\n1. 用户在本社区发布的原创内容，版权归原作者所有\n2. 发布内容即表示授权本社区在站内展示和传播\n3. 未经原作者许可，其他用户不得将内容用于商业用途\n\n### 二、转载规范\n\n1. 转载他人内容请注明原作者和出处链接\n2. 如原作者要求删除转载内容，管理员将予以配合\n3. 禁止大量转载未经授权的内容\n\n### 三、侵权处理\n\n如发现侵权内容，请通过私信联系管理员或在站务管理板块发帖说明。\n\n### 四、免责声明\n\n- 用户发布的内容不代表本社区立场\n- 本社区不对用户发布内容的准确性负责\n- 因用户内容引发的纠纷由发布者自行承担',
'<h2>版权声明</h2><h3>一、用户内容</h3><ol><li>用户在本社区发布的原创内容，版权归原作者所有</li><li>发布内容即表示授权本社区在站内展示和传播</li><li>未经原作者许可，其他用户不得将内容用于商业用途</li></ol><h3>二、转载规范</h3><ol><li>转载他人内容请注明原作者和出处链接</li><li>如原作者要求删除转载内容，管理员将予以配合</li><li>禁止大量转载未经授权的内容</li></ol><h3>三、侵权处理</h3><p>如发现侵权内容，请通过私信联系管理员或在站务管理板块发帖说明。</p><h3>四、免责声明</h3><ul><li>用户发布的内容不代表本社区立场</li><li>本社区不对用户发布内容的准确性负责</li><li>因用户内容引发的纠纷由发布者自行承担</li></ul>',
2, 0, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

(5, 1, 1, 'admin', '新手指南 - 快速上手 AMuBBS',
'## 新手指南\n\n欢迎新用户！本指南帮助你快速了解社区的主要功能。\n\n### 一、注册与登录\n\n- 点击右上角「注册」填写用户名、邮箱和密码\n- 注册成功后自动登录\n\n### 二、个人设置\n\n登录后点击头像进入个人中心，可以修改头像、签名和密码。\n\n### 三、发帖与回复\n\n- 进入板块后点击「发表新主题」\n- 编辑器支持 Markdown 语法，可插入图片、代码块、引用\n- 支持附件上传\n\n### 四、积分系统\n\n| 操作 | 积分 |\n|------|------|\n| 每日签到 | +1~10 |\n| 发表主题 | +5 |\n| 发表回复 | +1 |\n| 被点赞 | +1 |\n\n积分可用于：解锁付费内容、打赏其他用户、提升等级。\n\n### 五、等级体系\n\n学前班(0) → 小学(100) → 初中(500) → 高中(1500) → 大学(3000) → 研究生(6000) → 博士(10000) → 博导(20000)\n\n### 六、常用操作\n\n- **签到**：首页右侧签到卡片\n- **搜索**：顶部导航栏搜索框\n- **私信**：点击用户头像进入主页发送\n- **暗色模式**：页面右下角月亮图标\n\n有任何问题欢迎在站务管理板块提问！',
'<h2>新手指南</h2><p>欢迎新用户！本指南帮助你快速了解社区的主要功能。</p><h3>一、注册与登录</h3><ul><li>点击右上角「注册」填写用户名、邮箱和密码</li><li>注册成功后自动登录</li></ul><h3>二、个人设置</h3><p>登录后点击头像进入个人中心，可以修改头像、签名和密码。</p><h3>三、发帖与回复</h3><ul><li>进入板块后点击「发表新主题」</li><li>编辑器支持 Markdown 语法，可插入图片、代码块、引用</li><li>支持附件上传</li></ul><h3>四、积分系统</h3><table><thead><tr><th>操作</th><th>积分</th></tr></thead><tbody><tr><td>每日签到</td><td>+1~10</td></tr><tr><td>发表主题</td><td>+5</td></tr><tr><td>发表回复</td><td>+1</td></tr><tr><td>被点赞</td><td>+1</td></tr></tbody></table><p>积分可用于：解锁付费内容、打赏其他用户、提升等级。</p><h3>五、等级体系</h3><p>学前班(0) → 小学(100) → 初中(500) → 高中(1500) → 大学(3000) → 研究生(6000) → 博士(10000) → 博导(20000)</p><h3>六、常用操作</h3><ul><li><strong>签到</strong>：首页右侧签到卡片</li><li><strong>搜索</strong>：顶部导航栏搜索框</li><li><strong>私信</strong>：点击用户头像进入主页发送</li><li><strong>暗色模式</strong>：页面右下角月亮图标</li></ul><p>有任何问题欢迎在站务管理板块提问！</p>',
8, 0, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- 更新板块帖子计数
UPDATE `forums` SET `thread_count` = 5, `last_thread_id` = 5, `last_post_time` = UNIX_TIMESTAMP() WHERE `id` = 1;
UPDATE `users` SET `thread_count` = 5 WHERE `id` = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- 用户黑名单表
-- ----------------------------
DROP TABLE IF EXISTS `user_blacklist`;
CREATE TABLE `user_blacklist` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT '拉黑者ID',
  `block_user_id` INT UNSIGNED NOT NULL COMMENT '被拉黑者ID',
  `created_at` INT UNSIGNED NOT NULL COMMENT '拉黑时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_block` (`user_id`, `block_user_id`),
  KEY `idx_block_user` (`block_user_id`),
  KEY `idx_ub_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户黑名单';

-- 完成
SELECT '数据库初始化完成！' AS message;
