-- ============================================================
-- AMuBBS 迁移：moment_comments 增加 parent_id 列 + 索引（幂等）
-- 日期: 2026-09-26
-- ============================================================
--
-- 【背景】
--   动态评论原来只有 reply_user_id（「回复了谁」），没有「回复了哪条评论」。
--   于是所有子回复都被拍平成一维列表，页面上的表现是：
--     admin 一条
--     qaz 回复 admin 一条
--     admin 回复 qaz 一条
--   三行同级同字号，完全看不出归属关系，回复也总是追加到整段列表的末尾。
--
-- 【改法】只加一层嵌套（对齐 GitHub Discussions：顶层评论 + 其回复成组）：
--   parent_id = 0            → 顶层评论
--   parent_id = 顶层评论的 id → 该评论的回复
--   回复「某条回复」时不再产生第三层，PHP 侧会把它归一到同一个父评论
--   （app/Models/MomentComment.php::normalizeParentId()）。
--
-- 【历史数据】
--   旧的 reply_user_id > 0 的行不会被回填 parent_id（保持 0，即仍作为顶层评论显示，
--   只是继续带「回复 @某人」标注）。原因是回填只能靠「同动态里更早的、同作者的评论」
--   猜，猜错会把评论挂到无关的楼层下，宁可不猜。
--
-- 【幂等】
--   下面是 MariaDB 的 IF NOT EXISTS 写法（10.1.4+ 支持，本机 10.11 通过）。
--   MySQL 8 的语法不支持 IF NOT EXISTS，请先查：
--     SELECT COUNT(*) FROM information_schema.COLUMNS
--      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='moment_comments' AND COLUMN_NAME='parent_id';
--     SELECT COUNT(*) FROM information_schema.STATISTICS
--      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='moment_comments' AND INDEX_NAME='idx_mc_moment_parent_created';
--   返回 0 再分别执行，并去掉 IF NOT EXISTS。
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `moment_comments`
  ADD COLUMN IF NOT EXISTS `parent_id` INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT '所属顶层评论ID（0=顶层评论；回复子评论时归一到同一父评论，仅一层嵌套）'
    AFTER `reply_user_id`;

ALTER TABLE `moment_comments`
  ADD INDEX IF NOT EXISTS `idx_mc_moment_parent_created` (`moment_id`, `parent_id`, `created_at`);

-- 验证：
-- SHOW COLUMNS FROM `moment_comments` LIKE 'parent_id';
-- SHOW INDEX FROM `moment_comments` WHERE Key_name = 'idx_mc_moment_parent_created';
