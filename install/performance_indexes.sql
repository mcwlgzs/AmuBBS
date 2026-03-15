-- ============================================
-- AMuBBS 性能优化索引
-- 执行此文件以添加缺失的关键索引
-- ============================================

-- 注意：执行前请先检查索引是否已存在，避免重复创建

-- 1. posts 表：优化用户发帖查询
-- 检查是否已有类似索引：SHOW INDEX FROM posts WHERE Key_name LIKE '%user%';
ALTER TABLE `posts` ADD INDEX `idx_posts_user_created` (`user_id`, `created_at` DESC);

-- 2. thread_tags 表：优化标签查询（复合索引，可替代单列索引）
-- 如果已有 idx_thread_tags_tag，建议先删除：DROP INDEX idx_thread_tags_tag ON thread_tags;
ALTER TABLE `thread_tags` ADD INDEX `idx_thread_tags_tag_thread` (`tag_id`, `thread_id`);

-- 3. forum_access 表：优化权限查询
ALTER TABLE `forum_access` ADD INDEX `idx_forum_access_group` (`group_id`, `forum_id`);

-- 4. threads 表：优化板块+置顶查询
ALTER TABLE `threads` ADD INDEX `idx_threads_forum_top` (`forum_id`, `is_top` DESC, `created_at` DESC);

-- 5. threads 表：优化板块+精华查询
ALTER TABLE `threads` ADD INDEX `idx_threads_forum_hl` (`forum_id`, `is_highlight`, `created_at` DESC);

-- 6. users 表：优化用户名搜索
ALTER TABLE `users` ADD INDEX `idx_users_username` (`username`, `deleted_at`);

-- 7. credit_logs 表：优化用户积分记录查询
ALTER TABLE `credit_logs` ADD INDEX `idx_credit_user_time` (`user_id`, `created_at` DESC);

-- 8. mod_logs 表：优化管理日志查询
ALTER TABLE `mod_logs` ADD INDEX `idx_mod_admin_time` (`admin_id`, `created_at` DESC);

-- 9. tags 表：优化标签名称查询
ALTER TABLE `tags` ADD INDEX `idx_tags_name` (`name`);

-- 10. announcements 表：优化公告查询
ALTER TABLE `announcements` ADD INDEX `idx_announce_status` (`status`, `rank` DESC);

-- 11. friend_links 表：优化友链查询
ALTER TABLE `friend_links` ADD INDEX `idx_links_status` (`status`, `rank` DESC);

-- ============================================
-- 索引优化建议
-- ============================================

-- 查看表的索引使用情况：
-- SELECT * FROM sys.schema_unused_indexes WHERE object_schema = 'your_database';

-- 分析查询性能：
-- EXPLAIN SELECT * FROM posts WHERE user_id = 1 ORDER BY created_at DESC LIMIT 20;

-- 整理表碎片（定期执行）：
-- OPTIMIZE TABLE threads, posts, users, tags;
