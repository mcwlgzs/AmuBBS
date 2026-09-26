-- ============================================================
-- AMuBBS 迁移：queue_jobs 增加 attempts 列（幂等）
-- 日期: 2026-09-25
-- ============================================================
--
-- 【背景】
--   原来的「失败重试计数」只存在内存里的 $job['attempts']（app/Services/QueueSvc.php:132），
--   MySQL 模式下每次从表里 reserve 出来的行都不带 attempts 列，
--   于是：
--     1. 重试次数永远是 1，`$attempts >= 3 则删除` 是死代码 ⇒ 坏任务被 cron 无限重试；
--     2. release() 只清 reserved_at/reserve_token，不同步失败次数。
--   本迁移给 queue_jobs 加一列持久化 attempts，配合 QueueJob::fail() 使用。
--
-- 【同样修掉的两个租约缺陷（PHP 侧，见 app/Models/QueueJob.php）】
--   - releaseStale() 以前只清 reserved_at、不清 reserve_token，
--     导致租约过期后旧 worker 仍能用旧 token 删掉新 worker 正在处理的行；
--   - remove() 只按 id 删，加了可选 token 参数后变成「只删自己抢占的那一行」。
--
-- 【幂等】
--   本机 MariaDB 10.11 / MySQL 8 直接执行下面的 ALTER（列已存在时会报 1060，可忽略）。
--   更保险的做法是先查 information_schema：
--     SELECT COUNT(*) FROM information_schema.COLUMNS
--      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'queue_jobs' AND COLUMN_NAME = 'attempts';
--   返回 0 再执行。
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `queue_jobs`
  ADD COLUMN `attempts` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已失败重试次数' AFTER `reserve_token`;

-- 验证：
-- SHOW COLUMNS FROM `queue_jobs` LIKE 'attempts';
