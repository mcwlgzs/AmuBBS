# AMuBBS 性能优化指南

> ⚠️ **历史文档**：本文写于「默认用 Redis」的阶段。当前项目**默认是文件缓存**
> （`CACHE_DRIVER=auto` 在没有 Redis 扩展时自动降级），目标环境是共享虚拟主机，
> 所以文中「上 Redis / 上连接池 / 上 JIT」的建议请当作**可选升级路线**，
> 不是运行前提。现状与真实 API 见 [`02-架构.md`](02-架构.md) 的 2.2.4 与 [`04-性能.md`](04-性能.md)。

本文档记录了 AMuBBS 论坛系统的性能优化措施和使用说明。

## 📊 优化概览

本次优化主要针对以下几个方面：

| 优化项 | 预期收益 | 优先级 |
|--------|---------|--------|
| 数据库索引优化 | 30-50% 查询性能提升 | P0 |
| 缓存策略优化 | 减少 20-30% 数据库查询 | P0 |
| 标签批量操作 | 减少 50% 标签创建时间 | P1 |
| 编辑器加载优化 | 减少 40% 页面加载时间 | P1 |
| 会话清理机制 | 防止数据库膨胀 | P1 |

---

## 🗄️ 数据库索引优化

### 执行方式

```bash
# 在 MySQL 中执行索引优化脚本
mysql -u your_user -p your_database < install/performance_indexes.sql
```

### 新增索引列表

> 本列表以 [`install/performance_indexes.sql`](../install/performance_indexes.sql) 为准（脚本 = 唯一事实来源，列表跟随同步）。
> 脚本是**幂等**的（`ADD INDEX IF NOT EXISTS`，MariaDB 10.1.4+ / MySQL 8.0.29+），可以重复执行；
> 老站升级用 [`install/migrations/2026_09_25_add_list_indexes.sql`](../install/migrations/2026_09_25_add_list_indexes.sql)，
> 新装站点的这些索引已经写在 `install/database.sql` 的 `CREATE TABLE` 里。

1. **threads 表** - `idx_threads_all_list`（`deleted_at, is_top DESC, created_at DESC`）：优化首页/全部帖子页的全局排序
2. **threads 表** - `idx_threads_all_hot`（`deleted_at, is_top DESC, reply_count DESC, views DESC`）：优化「全部帖子-回复数」排序
3. **posts 表** - `idx_posts_thread_deleted_created`（`thread_id, deleted_at, created_at`）：优化主题内回复分页
4. **users 表** - `idx_users_active_login`（`deleted_at, login_at DESC`）：优化侧栏活跃用户列表

> **历史上那份「11 条索引建议清单」已废弃**：其中 `mod_logs` 表在本项目不存在、
> `idx_announce_status` 引用了不存在的 `announcements.status`、`idx_links_status` 引用了不存在的 `friend_links.rank`，
> 直接在库里执行必然报 ERROR 1072（这三条也是本项目早期版本的原始缺陷）。
> 其余条目（`idx_posts_user_created` / `idx_thread_tags_tag_thread` / `idx_forum_access_group` /
> `idx_users_username` / `idx_tags_name` / `idx_credit_user_time` / `idx_logs_user_time` /
> `idx_announcements_active` / `idx_links_status_sort` / `idx_threads_forum_top` / `idx_threads_forum_hl`）
> 与 `install/database.sql` 建表时已有的索引重复或缺少量级支撑，**刻意不建** ——
> 逐条理由和 EXPLAIN 实测记录在 `install/performance_indexes.sql` 头部注释里。

### 验证索引

```sql
-- 查看某个表的所有索引
SHOW INDEX FROM posts;

-- 分析查询性能
EXPLAIN SELECT * FROM posts WHERE user_id = 1 ORDER BY created_at DESC LIMIT 20;
```

---

## 💾 缓存策略优化

### 优化内容

#### 1. 论坛列表缓存
- **位置**：`app/Controllers/Thread.php`
- **缓存时间**：3600 秒（1 小时）
- **缓存键**：`forums:all`

```php
$forums = Cache::get('forums:all', function() {
    return Database::fetchAll("SELECT * FROM forums WHERE deleted_at IS NULL ORDER BY parent_id ASC, `rank` DESC");
}, 3600);
```

#### 2. 热门标签缓存
- **位置**：`app/Controllers/Thread.php`
- **缓存时间**：1800 秒（30 分钟）
- **缓存键**：`tags:popular:50`

```php
$allTags = Cache::get('tags:popular:50', function() {
    return Database::fetchAll("SELECT * FROM tags ORDER BY thread_count DESC LIMIT 50");
}, 1800);
```

### 缓存清理

当论坛结构或标签发生变化时，需要手动清理缓存：

```php
// 清理论坛列表缓存
Cache::delete('forums:all');

// 清理标签缓存
Cache::delete('tags:popular:50');
```

---

## 🏷️ 标签批量操作优化

### 优化前（循环插入）

```php
foreach ($names as $name) {
    Database::execute("INSERT INTO tags ...", [$name]);
    Database::execute("INSERT INTO thread_tags ...", [$threadId, $tagId]);
    Database::execute("UPDAs SET thread_count = thread_count + 1 ...", [$tagId]);
}
```

**问题**：N 次数据库查询，性能差

### 优化后（批量插入）

```php
// 1. 批量创建标签
Database::execute("INSERT IGNORE INTO tags ... VALUES (?, ?, ?), (?, ?, ?), ...", $params);

// 2. 批量关联标签
Database::execute("INSERT IGNORE INTO thread_tags ... VALUES (?, ?), (?, ?), ...", $params);

// 3. 批量更新计数
Database::execute("UPDATE tags SET thread_count = thread_count + 1 WHERE id IN (?, ?, ...)", $tagIds);
```

**收益**：从 N 次查询减少到 3 次查询

---

## 🧹 会话清理机制

### 自动清理脚本

**位置**：`scripts/cleanup_sessions.php`

#### 配置 Crontab（Linux）

```bash
# 编辑 crontab
crontab -e

# 添加以下行（每小时执行一次）
0 * * * * /usr/bin/php /path/to/amubbs/scripts/cleanup_sessions.php >> /var/log/amubbs_cleanup.log 2>&1
```

#### 手动执行

```bash
php scripts/cleanup_sessions.php
```

#### Windows 计划任务

1. 打开"任务计划程序"
2. 创建基本任务
3. 触发器：每小时
4. 操作：启动程序
   - 程序：`C:\php\php.exe`
   - 参数：`C:\path\to\amubbs\scripts\cleanup_sessions.php`

### 会话清理策略

- **过期时间**：2 小时未活动的会话将被清理
- **用户会话**：每个用户最多保留 3 个最新会话
- **统计信息**：清理后输出会话统计

### 手动调用

```php
use App\Services\SessionCleanupSvc;

// 清理过期会话
$count = SessionCleanupSvc::cleanExpiredSessions(7200);

// 清理用户旧会话
$count = SessionCleanupSvc::cleanUserOldSessions($userId, 3);

// 获取会话统计
$stats = SessionCleanupSvc::getSessionStats();
```

---

## 📈 性能监控

### 数据库查询监控

在开发环境中启用查询日志：

```php
// config/database.php
'log_queries' => true,
'slow_query_threshold' => 1000, // 毫秒
```

### 缓存命中率监控

```php
// 查看缓存统计
$stats = Cache::getStats();
echo "命中率: " . ($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100) . "%";
```

### 会话表大小监控

```sql
-- 查看 sessions 表大小
SELECT
    table_name AS 'Table',
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)'
FROM information_schema.TABLES
WHERE table_schema = 'your_database' AND table_name = 'sessions';
```

---

## ⚠️ 注意事项

### 1. 索引维护

- 定期运行 `OPTIMIZE TABLE` 整理表碎片
- 监控索引使用情况，删除未使用的索引

```sql
-- 整理表碎片
OPTIMIZE TABLE threads, posts, users;
```

### 2. 缓存失效

以下操作会导致缓存失效，需要手动清理：

- 创建/编辑/删除论坛
- 创建/编辑标签
- 修改系统设置

### 3. 会话清理

- 确保 crontab 正常运行
- 定期检查 sessions 表大小
- 如果使用 Redis，考虑迁移会话存储

### 4. 备份建议

执行索引优化前，建议备份数据库：

```bash
mysqldump -u user -p database > backup_$(date +%Y%m%d).sql
```

---

## 🚀 进一步优化建议

### 短期优化（1-2 周）

1. **启用 OPcache**
   ```ini
   ; php.ini
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.max_accelerated_files=10000
   ```

2. **启用 Gzip 压缩**
   ```apache
   # .htaccess
   <IfModule mod_deflate.c>
       AddOutputFilterByType DEFLATE text/html text/css text/javascript application/javascript
   </IfModule>
   ```

3. **静态资源 CDN**
   - 将 CSS/JS/图片迁移到 CDN
   - 减轻服务器负载

### 中期优化（1-2 月）

1. **Redis 缓存**
   - 替换文件缓存为 Redis
   - 存储会话到 Redis
   - 缓存热点数据

2. **数据库读写分离**
   - 配置主从复制
   - 读操作分流到从库

3. **图片优化**
   - 自动压缩上传图片
   - 生成多尺寸缩略图
   - 使用 WebP 格式

### 长期优化（3-6 月）

1. **全文搜索**
   - 集成 Elasticsearch
   - 提升搜索性能

2. **消息队列**
   - 异步处理通知
   - 异步发送邮件

3. **负载均衡**
   - 多台 Web 服务器
   - Nginx 负载均衡

---

## 📞 技术支持

如有问题，请查看：

- 项目文档：`README.md`
- 问题反馈：GitHub Issues
- 性能分析：使用 Xdebug 或 Blackfire

---

**最后更新**：2026-03-04
**版本**：v1.0
