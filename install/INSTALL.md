# AMuBBS 安装指南

## 环境要求

| 软件 | 最低版本 | 说明 |
|:-----|:---------|:-----|
| PHP | 8.0 | 只用 `str_starts_with` / `match` 等 8.0 特性；8.1 / 8.2 均可 |
| MySQL | 5.6 | 或 MariaDB 10.0+；**不要求 MySQL 8** |
| Redis | 不需要 | 可选。没有 Redis 时自动使用文件缓存 |
| Web 服务器 | 任意 | Nginx / Apache / 共享虚拟主机 / PHP 内置服务器 |

**必须开启的 PHP 扩展**：`pdo_mysql`、`mbstring`、`json`

**可选扩展**：`redis`（有则自动启用）、`opcache`（提速）、`gd`（图片处理）、`zip`（备份）

**不需要**：Composer、npm / Node、root 权限、shell 访问、常驻进程、cron

---

## 一、推荐方式：安装向导

1. 将 **`public/` 目录**设为网站根目录（`storage/` 必须在 Web 根目录之外）
2. 确保 `storage/` 可写（Linux：`chmod -R 755 storage`，个别主机需 `777`）
3. 复制配置文件：`cp .env.example .env`
4. 浏览器访问 `/install`，按向导填写数据库信息并创建管理员账号

向导会自动完成建库、导入表结构和写入 `install/install.lock`。

## 二、手动方式

### 1. 创建数据库并导入表结构

```bash
mysql -u 用户名 -p -e "CREATE DATABASE amubbs DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u 用户名 -p amubbs < install/database.sql
```

> 主库脚本**刻意不包含 FULLTEXT 索引**。因为 MySQL 5.5 及部分共享主机不支持 InnoDB FULLTEXT，
> 一旦写在 `CREATE TABLE` 里会导致整条建表语句失败、安装中断。
> 移除后，MySQL 5.6+ / MariaDB 10+ 都能顺利安装。

### 2. 可选：补上全文索引（建议）

```bash
mysql -u 用户名 -p amubbs < install/optional_fulltext.sql
```

执行后**英文/数字关键词**搜索会走全文索引，明显更快。
不执行也完全可用，搜索会自动回退 `LIKE`。

> 中文关键词无论如何都会走 `LIKE`：InnoDB 默认分词器对 CJK 支持很差，
> 会把一段中文切成极少的 token，导致 `MATCH...AGAINST` 静默漏掉结果（且不报错）。

### 3. 配置 `.env`

```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=amubbs
DB_USERNAME=root
DB_PASSWORD=你的密码
DB_CHARSET=utf8mb4

# 缓存驱动：auto（推荐）| file | redis
CACHE_DRIVER=auto

# Session：共享主机建议用 file
SESSION_DRIVER=file
```

`CACHE_DRIVER=auto` 的行为：探测到 Redis 就用 Redis，否则自动使用 `storage/cache/` 文件缓存，
两者都不可用时退化为进程内缓存，**任何情况都不会因为缓存问题白屏**。

> 缓存目录默认 `storage/cache/`，可用 `CACHE_FILE_PATH` 改到站点目录之外。

### 4. 创建安装锁

```bash
echo "installed" > install/install.lock
```

不创建该文件时，所有请求都会被重定向到 `/install` 安装向导。

### 5. 创建管理员

可用安装向导，或直接写库（密码需为 bcrypt 哈希）：

```sql
INSERT INTO users (id, username, email, password, group_id, credits, created_at, updated_at)
VALUES (1, 'admin', 'admin@example.com', '<bcrypt hash>', 3, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
```

`group_id = 3` 即管理员组。

### 6. 启动

```bash
# 开发环境（PHP 内置服务器）
php -S localhost:8000 -t public public/router.php

# 生产环境：把 public/ 指向 Nginx / Apache 站点根目录
```

访问 `http://localhost:8000`。

### 7. 生产环境：Web 服务器与 PHP 配置

仓库里直接给了两份可抄的配置，**不用自己从零写**：

| 文件 | 用途 |
| --- | --- |
| `install/nginx.conf.example` | Nginx 站点配置：docroot=public/、上传目录禁止执行脚本、静态资源缓存/压缩、隐藏文件兜底 |
| `install/php.ini.example` | 推荐的 php.ini：OPcache（未开时首页冷渲染 p50 ≈ 39ms）、Session GC 与 `SESSION_LIFETIME` 对齐、上传/超时、生产环境关闭 display_errors |
| `public/.htaccess` | Apache / 共享虚拟主机版（mod_rewrite + mod_expires + mod_deflate） |
| `public/uploads/.htaccess` | 上传目录禁止脚本解析（Apache 专用，Nginx 见上面示例的 `location ^~ /uploads/`） |

用 `./deploy.sh [远程IP]` 部署时脚本会：同步代码 → 把 `storage/` 设成 750/640（**不是 -R 755**，否则会话文件全机可读）→ reload php-fpm → 调 `/health` 校验（非 200 直接失败）。

> 反向代理（Nginx 终止 TLS 后转 php-fpm）部署时，请到 **后台 → 系统设置** 把反代 IP 填进 `trusted_proxies`：
> 应用只信任这里显式列出的代理，否则限流、登录锁定、`admin_bind_ip` 都会因为无法区分真实客户端 IP 而失效。

---

## 已初始化的数据

- **用户组**：普通用户(1)、版主(2)、管理员(3)、待验证用户(4)、禁止用户组(5)
- **板块**：站务管理、技术讨论（PHP / JavaScript）、灌水乐园
- **配置**：站点名称 AMuBBS，站点状态 5（所有人可读写）

## 站点状态说明

参考 Xiuno BBS 设计，`settings` 表的 `site_status`：

| 值 | 含义 |
|:--|:--|
| 0 | 站点关闭 |
| 1 | 管理员可读写 |
| 2 | 会员可读 |
| 3 | 会员可读写 |
| 4 | 所有人只读 |
| 5 | 所有人可读写（默认） |

---

## 故障排查

### 数据库连接失败

```bash
mysql -u 用户名 -p -e "SELECT 1"                 # MySQL 是否在跑
mysql -u 用户名 -p -e "SHOW DATABASES LIKE 'amubbs'"
```

确认 `.env` 里的 `DB_*` 与实际一致。注意：**`.env` 是唯一生效处**，不要再改 `config/database.php`。

### 缓存目录不可写

页面能开但性能差，日志出现 `[Cache] 缓存目录不可写` 时：

```bash
chmod -R 755 storage        # 个别共享主机需要 777
```

### 搜索没有结果

- 中文短词/部分词查不到：属预期内的分词限制，中文一律走 `LIKE`
- 英文短词（少于 3 个字符）查不到：InnoDB 全文索引有最小分词长度（默认 3），此时会自动回退 `LIKE`

### 页面空白

打开 `APP_DEBUG=true` 后重试，或查看 PHP 错误日志与 `storage/logs/`。

---

## 许可与合规

本程序采用 **MIT 许可证**（见仓库根目录 `LICENSE`）：可自由用于商用、修改、分发与私有
部署，只需保留版权声明与许可声明。内置的第三方前端资源（layui / layuimini / Font Awesome /
htmx）遵循各自许可，清单见 `public/assets/vendor/VERSIONS.txt`。

> ⚠️ **免责声明**：本程序只提供论坛软件本身，不提供任何内容或运营服务。使用者使用本程序
> 搭建、运营的**任何违法违规（含违法犯罪）网站及其行为，与作者和版权所有者无关**，作者
> 不对此承担任何责任。请自行确认所在地法律法规与服务器托管地的法律要求（备案、内容审核、
> 个人信息保护等），相关法律责任与后果由使用者自行承担。

---

**当前版本**: v0.1.0
