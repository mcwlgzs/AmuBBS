-- ----------------------------
-- 可选：全文索引（FULLTEXT）
-- ----------------------------
-- 为什么单独放一个文件？
--   主库脚本 install/database.sql 刻意【不包含】FULLTEXT 索引。
--   因为 MySQL 5.5 及部分共享主机不支持 InnoDB 的 FULLTEXT，
--   一旦写在 CREATE TABLE 里，整个建表语句会失败，导致安装彻底中断。
--
--   移除之后，database.sql 可以在任何 MySQL 5.5+ / MariaDB 10+ 上顺利安装。
--
-- 要不要执行本脚本？
--   - MySQL >= 5.6 或 MariaDB >= 10.0：建议执行，搜索会走全文索引，快很多。
--   - 更老的环境 / 不确定：不执行也完全可用，
--     Search 控制器与 API 会自动回退到 LIKE 查询。
--
-- 执行方式：
--   mysql -u 用户名 -p 数据库名 < install/optional_fulltext.sql
--
-- 幂等性：重复执行会因索引已存在而报错，属正常现象，可忽略。

ALTER TABLE `threads` ADD FULLTEXT KEY `ft_threads_title_content` (`title`, `content`);
ALTER TABLE `posts` ADD FULLTEXT KEY `ft_posts_content` (`content`);
