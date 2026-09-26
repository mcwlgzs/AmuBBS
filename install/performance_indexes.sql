-- ============================================================
-- AMuBBS 性能优化索引（幂等脚本）
-- 修订日期: 2026-09-25
-- ============================================================
--
-- 【本文件的历史问题（已修）】
--   本文件在 2026-09-25 之前是「索引建议清单」，存在三个硬伤：
--     1. 全项目零引用：安装器只执行 install/database.sql（可选 optional_fulltext.sql），
--        cron / 脚本都不跑本文件 —— 所以这 11 条 ALTER 在任何环境都没被执行过。
--     2. 与库内现状脱节：其中 posts/thread_tags/forum_access/users/credit_logs/logs/tags
--        的建议在 database.sql 建表时已有等效索引，加进去只会多一份冗余索引。
--     3. 索引方向与查询不匹配：`(forum_id, is_top DESC, created_at DESC)` 这类「版块内」索引
--        修不了首页/全部帖子页那三条**全局**列表查询（它们不带 forum_id）。
--
-- 【现在的定位】
--   本文件是**幂等**脚本：可以在任意环境反复执行，已存在的索引自动跳过，
--   不会报错、不会重复建索引。它与
--       install/migrations/2026_09_25_add_list_indexes.sql
--   内容一致（同一批索引），并且新装站点已经在 install/database.sql 的 CREATE TABLE 里自带。
--   老库升级用迁移文件，本文件作为「性能索引总入口」保留（docs/PERFORMANCE.md 仍指向它）。
--
-- 【幂等实现】
--   MariaDB 10.1.4+ 支持 `ALTER TABLE ... ADD INDEX IF NOT EXISTS`，本机是 10.11.9。
--   为兼容不支持该语法的 MySQL 5.7，下面每张表都用两种写法给出：
--     写法 A（MariaDB 10.1.4+ / MySQL 8.0.29+）：直接 ADD INDEX IF NOT EXISTS
--     写法 B（MySQL 5.7 等）：手工执行「步骤1 生成语句 → 步骤2 执行输出」
--   按需选一种，不要两种都跑（都跑也安全，第二种会因为索引已存在而报 1061，属预期）。
--
-- 【只加这些索引的理由（全部有实测 EXPLAIN 支撑，详见 _tools/review/index_fix.md）】
--   demo 库只有 5 行主题，优化器在 5 行时会正确地选择全表扫描，所以判断依据是
--   在 bbs 库内克隆一份 20k 行的临时表（用完即 DROP）上跑 EXPLAIN：
--     Q1 ORDER BY is_top DESC, created_at DESC
--        旧 idx_threads_list(deleted_at, is_top, created_at DESC) → rows=10045 + Using filesort
--        新 idx_threads_all_list(deleted_at, is_top DESC, created_at DESC) → rows=10045、无 filesort
--     Q2 ORDER BY is_top DESC, reply_count DESC, views DESC
--        旧 idx_threads_hot(deleted_at, created_at, reply_count, views) → rows=10045 + Using filesort
--        新 idx_threads_all_hot(deleted_at, is_top DESC, reply_count DESC, views DESC) → 无 filesort
--     Q3 users 活跃用户 WHERE deleted_at IS NULL AND login_at > ? ORDER BY login_at DESC
--        旧 idx_users_created_deleted → rows=9900 + Using filesort
--        新 idx_users_active_login(deleted_at, login_at DESC) → rows=1、无 filesort
--     Q4 主题内回复分页 WHERE thread_id = ? AND deleted_at IS NULL ORDER BY created_at
--        旧 idx_posts_created_deleted → rows=9897
--        新 idx_posts_thread_deleted_created(thread_id, deleted_at, created_at) → rows=4954
--
-- 【明确不建的索引（原 11 条里的其余条目）】
--   idx_posts_user_created   —— 与现有 idx_posts_user_deleted_created(user_id, deleted_at, created_at DESC) 重复
--   idx_thread_tags_tag_thread —— 与现有 idx_thread_tags_tag(tag_id) 重复（tag_id 前缀相同）
--   idx_forum_access_group   —— 与现有 PRIMARY KEY(forum_id, group_id) 重复（顺序不同但可互换使用）
--   idx_users_username       —— 与现有 UNIQUE uk_users_username(username) 重复
--   idx_tags_name            —— 与现有 UNIQUE uk_tags_name(name) 重复
--   idx_credit_user_time     —— 与现有 idx_user_id(user_id, created_at) 重复
--   idx_logs_user_time       —— 与现有 idx_logs_user(user_id) 重复
--   idx_announcements_active —— 与现有 idx_announcements_time(is_enabled, start_at, end_at) 高度重复且排序列 rank 无法复用
--   idx_links_status_sort    —— 与现有 idx_fl_status_sort(status, sort_order) 完全重复
--   idx_threads_forum_top / idx_threads_forum_hl —— 版块内排序索引，本机板块页实测未出现 filesort，
--                                                  缺少更大数据量的证据，暂不建
--   notifications 列表索引 —— 现有 idx_notif_user_created(user_id, created_at) 已能为
--                            listForUser()(app/Models/Notification.php:529-537) 消除 filesort（FORCE 实测 Extra 只剩 Using where），不需要新增
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- 写法 A：MariaDB 10.1.4+ / MySQL 8.0.29+（推荐，本机 MariaDB 10.11.9）
-- ============================================================
ALTER TABLE `threads`
  ADD INDEX IF NOT EXISTS `idx_threads_all_list` (`deleted_at`, `is_top` DESC, `created_at` DESC);
ALTER TABLE `threads`
  ADD INDEX IF NOT EXISTS `idx_threads_all_hot` (`deleted_at`, `is_top` DESC, `reply_count` DESC, `views` DESC);
ALTER TABLE `posts`
  ADD INDEX IF NOT EXISTS `idx_posts_thread_deleted_created` (`thread_id`, `deleted_at`, `created_at`);
ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_users_active_login` (`deleted_at`, `login_at` DESC);

-- ============================================================
-- 写法 B（备用）：MySQL 5.7 等不支持 ADD INDEX IF NOT EXISTS 的环境
--   步骤 1：执行下面的 SELECT，把输出的 4 行 ALTER 语句复制出来
--   步骤 2：执行复制出来的语句（已存在的话服务器会报 1061 Duplicate key name，可忽略）
-- ============================================================
-- SELECT GROUP_CONCAT(
--          CONCAT('ALTER TABLE `', t.TABLE_NAME, '` ADD INDEX `', t.INDEX_NAME, '` (', t.COLS, ');')
--          SEPARATOR '\n') AS ddl
--   FROM (
--     SELECT 'threads' AS TABLE_NAME, 'idx_threads_all_list' AS INDEX_NAME, '`deleted_at`, `is_top` DESC, `created_at` DESC' AS COLS
--     UNION ALL SELECT 'threads', 'idx_threads_all_hot', '`deleted_at`, `is_top` DESC, `reply_count` DESC, `views` DESC'
--     UNION ALL SELECT 'posts',   'idx_posts_thread_deleted_created', '`thread_id`, `deleted_at`, `created_at`'
--     UNION ALL SELECT 'users',   'idx_users_active_login', '`deleted_at`, `login_at` DESC'
--   ) t
--   LEFT JOIN information_schema.STATISTICS s
--     ON s.TABLE_SCHEMA = DATABASE()
--    AND s.TABLE_NAME   = t.TABLE_NAME
--    AND s.INDEX_NAME   = t.INDEX_NAME
--  WHERE s.INDEX_NAME IS NULL;

-- ============================================================
-- 验证（执行后应各返回 4 行：每条索引 1~4 个列序）
-- ============================================================
-- SHOW INDEX FROM `threads` WHERE Key_name LIKE 'idx_threads_all%';
-- SHOW INDEX FROM `posts`   WHERE Key_name = 'idx_posts_thread_deleted_created';
-- SHOW INDEX FROM `users`   WHERE Key_name = 'idx_users_active_login';

-- ============================================================
-- 维护提示（只是注释，无任何计划任务承载，需要就手工执行）
-- ============================================================
-- 查看从未被使用过的索引：
--   SELECT * FROM sys.schema_unused_indexes WHERE object_schema = DATABASE();
-- 整理表碎片：
--   OPTIMIZE TABLE `threads`, `posts`, `users`;
