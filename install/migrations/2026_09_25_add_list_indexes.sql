-- ============================================================
-- AMuBBS 迁移：列表页 / 活跃用户 / 回复分页 索引补齐
-- 日期: 2026-09-25
-- 适用: MariaDB 10.11 / MySQL 8.0（使用 ADD INDEX IF NOT EXISTS，MariaDB 10.1.4+ 支持）
-- 目标库: bbs（只针对站点库执行）
-- ============================================================
--
-- 背景（前序审计实测，均在本机 MariaDB 10.11.9 复现）：
--   1) `idx_threads_list (deleted_at, is_top, created_at DESC)` 的 is_top 方向写反。
--      应用要的是 ORDER BY is_top DESC, created_at DESC
--      （app/Models/Thread.php:549 最新主题 / app/Models/Thread.php:646 全部帖子页），
--      该索引只能服务 (is_top ASC, created_at DESC)，或反向扫 (is_top DESC, created_at ASC)。
--      实测 OFFSET 1840 = 26.94ms，Extra=Using filesort。
--   2) `idx_threads_hot (deleted_at, created_at, reply_count, views)` 不含 is_top，
--      无法服务 ORDER BY is_top DESC, reply_count DESC, views DESC
--      （app/Models/Thread.php:564），实测 29.18ms + filesort。
--   3) posts 缺 (thread_id, deleted_at, created_at)：按回复时间分页的
--      app/Models/Post.php:312-314 走 idx_posts_created_deleted(deleted_at,...) 扫全域再 filesort。
--   4) users 缺以 login_at 开头的索引：
--      resources/views/components/sidebar-active-users.php:11-12 的
--      WHERE deleted_at IS NULL AND login_at > ? ORDER BY login_at DESC 实测 19.32ms + filesort。
--
-- 幂等说明：
--   MariaDB 10.1.4+ 支持 `ALTER TABLE ... ADD INDEX IF NOT EXISTS`，未知索引名时执行；
--   已存在则跳过并给出 Note（不是 Error）。重复执行本文件是安全的、不会报错。
--
-- 回滚（如需）：
--   ALTER TABLE `threads` DROP INDEX `idx_threads_all_list`;
--   ALTER TABLE `threads` DROP INDEX `idx_threads_all_hot`;
--   ALTER TABLE `posts`   DROP INDEX `idx_posts_thread_deleted_created`;
--   ALTER TABLE `users`   DROP INDEX `idx_users_active_login`;
--
-- 未纳入本迁移的候选（见 _tools/review/index_fix.md）：
--   threads(idx_threads_top) 已存在，FORCE 后可为全局置顶查询 (is_top=2 ORDER BY updated_at DESC)
--   消除 filesort，但优化器默认不选它（选了 idx_threads_list）。已存在于库中且建表脚本已带，
--   因此不重复添加、也不建议在没有 SQL 侧索引提示的情况下改动。
--   notifications 的列表查询 (user_id ORDER BY created_at DESC) 已由现有
--   `idx_notif_user_created (user_id, created_at)` 消除 filesort（FORCE 实测 Extra 只剩 Using where），
--   无新增索引的必要，因此本迁移不为 notifications 加索引，避免造出纯冗余索引。
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- threads：全局列表页（最新 / 全部帖子 / 全部帖子-回复数）
-- ------------------------------------------------------------
-- 服务 ORDER BY is_top DESC, created_at DESC
--   app/Models/Thread.php:549  latestPageCached()
--   app/Models/Thread.php:646  allThreadsCached() default 分支
-- 同时也能服务全局置顶查询 t.is_top = 2 AND deleted_at IS NULL ORDER BY updated_at DESC（有序扫描，无 filesort）。
ALTER TABLE `threads`
  ADD INDEX IF NOT EXISTS `idx_threads_all_list` (`deleted_at`, `is_top` DESC, `created_at` DESC);

-- 服务 ORDER BY is_top DESC, reply_count DESC, views DESC
--   app/Models/Thread.php:564  hotPageCached()
--   app/Models/Thread.php:635  allThreadsCached() 'hot' 分支
ALTER TABLE `threads`
  ADD INDEX IF NOT EXISTS `idx_threads_all_hot` (`deleted_at`, `is_top` DESC, `reply_count` DESC, `views` DESC);

-- ------------------------------------------------------------
-- posts：主题内回复分页（按回复时间）
-- ------------------------------------------------------------
-- 服务 WHERE thread_id = ? AND deleted_at IS NULL ORDER BY created_at ASC|DESC
--   app/Models/Post.php:312-314  listByThread()
--   app/Models/Thread.php:373-375 refreshLastPost()
ALTER TABLE `posts`
  ADD INDEX IF NOT EXISTS `idx_posts_thread_deleted_created` (`thread_id`, `deleted_at`, `created_at`);

-- ------------------------------------------------------------
-- users：侧栏「活跃用户」
-- ------------------------------------------------------------
-- 服务 WHERE deleted_at IS NULL AND login_at > ? ORDER BY login_at DESC
--   resources/views/components/sidebar-active-users.php:11-12
ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_users_active_login` (`deleted_at`, `login_at` DESC);

-- ============================================================
-- 校验（执行后应各返回 1 行）
-- ============================================================
-- SHOW INDEX FROM `threads` WHERE Key_name = 'idx_threads_all_list';
-- SHOW INDEX FROM `threads` WHERE Key_name = 'idx_threads_all_hot';
-- SHOW INDEX FROM `posts`   WHERE Key_name = 'idx_posts_thread_deleted_created';
-- SHOW INDEX FROM `users`   WHERE Key_name = 'idx_users_active_login';
