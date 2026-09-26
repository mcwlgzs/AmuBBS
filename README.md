<div align="center">

# AMuBBS

### 轻量、高性能、零依赖的现代 PHP 论坛系统

[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-8892BF?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL 5.6+](https://img.shields.io/badge/MySQL-5.6%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Redis 可选](https://img.shields.io/badge/Redis-Optional-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![htmx](https://img.shields.io/badge/htmx-2.x-3D72D7?style=for-the-badge)](https://htmx.org)
[![Layui](https://img.shields.io/badge/Layui-2.6.3-16baaa?style=for-the-badge)](https://layui.dev)
[![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](./LICENSE)
[![Disclaimer](https://img.shields.io/badge/Illegal%20Use-Not%20Our%20Responsibility-orange?style=for-the-badge)](./LICENSE)

中文 | [English](./README.en.md)

---

<br>

<table>
<tr>
<td align="center"><b>⚡ 2000+ QPS</b><br><sub>4C8G 服务器实测</sub></td>
<td align="center"><b>🚀 < 5ms</b><br><sub>缓存命中响应</sub></td>
<td align="center"><b>📦 零依赖</b><br><sub>无 Composer / npm</sub></td>
<td align="center"><b>🪶 < 4MB</b><br><sub>单请求内存占用</sub></td>
</tr>
</table>

<br>

</div>

## 📖 项目简介

AMuBBS 是一款面向中小型社区的**现代论坛系统**。不依赖任何第三方框架，基于 PHP 8（**兼容 8.0+，推荐 8.2**）从零构建自研微框架，采用 **Controller + Service + Model** 架构，缓存默认走文件驱动（没有 Redis 也能跑），追求极致的轻量与部署体验。

> 💡 设计理念参考 [Xiuno BBS](https://bbs.xiuno.com/)，以现代 PHP 架构全面重新实现。

### 🎯 优势速览

| 优势 | 具体表现 | 为什么重要 |
|:-----|:---------|:-----------|
| **真零依赖** | 不需要 Composer、npm/Node、任何构建步骤；框架自研，`core/` 只有 25 个文件，仓库里没有 `vendor/` 目录 | 不用装工具链，上传即用；少一层供应链风险 |
| **30 秒装完** | 访问 `/install` 走六步网页向导，自动建库建表、建管理员、写安装锁 | 不用 SSH、不用手敲 SQL；面板主机也能装 |
| **没有 Redis 也能跑** | `CACHE_DRIVER=auto` 探测不到 Redis 就自动用 `storage/cache/` 文件缓存，功能不降级 | 少一台中间件、少一份运维；小站本来不需要 Redis |
| **没有 cron 也不脏** | 文件缓存惰性 GC，过期数据在读写时顺手清理；cron 只做可选的保洁任务 | 共享主机/面板主机常常不给 crontab |
| **一套代码从共享主机跑到多节点** | 1 核 1 GB 虚拟机、共享虚拟主机、多节点集群，改的只是 `.env` | 先低成本上线，长大了不用换系统 |
| **默认就带安全基线** | 全局 CSRF（双提交 Cookie）、XSS 二次清洗、SQL 全参数化、登录「账号 + IP」双维度锁定、上传白名单 + 禁执行 | 论坛是被扫的重点目标，默认配置不裸奔 |
| **零构建前端** | htmx 2 + 手写 CSS，前台不加载 layui；后台用 layuimini v2 + Layui 2.6.3 | 首屏体积小、不依赖 CDN、没有打包产物 |
| **可验证** | 仓库自带 125 项 HTTP 冒烟、102 项安装自检、7 个静态检查器（`scripts/verify.ps1` 一条命令跑完） | 升级/改造后能自己证明「没坏」 |

### 与 Xiuno BBS 的对比

| 维度 | Xiuno BBS | AMuBBS |
|:-----|:----------|:-------|
| PHP 版本 | PHP 7.0+ | **PHP 8.0+**（类型系统增强、match 表达式） |
| 前端方案 | Bootstrap 4 + jQuery 3 | **htmx 2 + 自研 CSS**（零构建，服务端渲染片段；Alpine.js 已移除，后台另用 layuimini v2 + Layui 2.6.3） |
| 架构模式 | MVC 混合 | **Controller + Service + Model**（数据访问收敛在 Model，检查器强制） |
| 缓存策略 | 可选多种 | **文件缓存 / Redis 双驱动**，没有 Redis 也能跑（`CACHE_DRIVER=auto`） |
| API 设计 | 传统表单提交 | **RESTful API** 优先，`{code, message, data}` JSON 响应 |
| 实时通知 | 轮询 | 轮询 + REST API（`/api/notifications`），不做 WebSocket |
| 插件系统 | Hook + Overwrite | **WordPress 风格钩子**（`add_action` / `apply_filters`，后台可启停） |

---

## ✨ 核心特性

<table>
<tr>
<td width="50%" valign="top">

### 💬 社区核心
- 🏠 多板块管理（子板块、板块权限、版主系统）
- 📝 发帖 / 回复 / 编辑 / 删除 / 移动
- 📌 置顶 / 加精 / 锁帖
- ✍️ Markdown 编辑器（无第三方富文本依赖）
- 🖼️ 图片上传、附件上传下载
- 🔍 MySQL 全文搜索 + 高级筛选
- 💰 付费内容、隐藏内容
- 🏷️ 标签分类体系
- 📜 帖子编辑历史记录
- 💭 动态说说（类朋友圈）
- 🔗 网址导航

</td>
<td width="50%" valign="top">

### 👤 用户体系
- 📋 注册 / 登录 / 邮箱验证
- 🔗 OAuth 第三方登录
- <sub>GitHub / Google / 微信 / QQ</sub>
- ⭐ 积分等级系统（8 级：学前班 → 博导）
- 💎 VIP 会员（白银 / 黄金 / 铂金 / 钻石）
- 📅 每日签到（连续签到奖励）+ 任务中心
- 👥 关注 / 粉丝 / 私信 / 黑名单
- 🔔 实时消息通知
- 🏆 积分排行榜 + 签到排行
- 💝 打赏系统
- 📚 浏览历史 / 收藏夹

</td>
</tr>
<tr>
<td width="50%" valign="top">

### 🛠️ 管理后台
- 📊 数据仪表盘 + 系统实时监控
- 👥 用户 / 用户组管理（5 个默认组）
- 📋 帖子 / 回复管理（批量操作）
- 📢 公告系统 + 站点设置
- 🚫 敏感词过滤（替换 / 禁止，预置 40+）
- 🛑 IP 黑名单 + 访问频率配置
- 🔌 插件管理（启用 / 禁用 / 配置）
- 📜 操作日志审计
- 🔗 友情链接 / 导航管理
- 🏷️ 标签分类 / 等级管理
- 🖥️ 集群节点管理（分布式）

</td>
<td width="50%" valign="top">

### 🛡️ 安全防护
- 🔒 CSRF Token 全局防护
- 🧹 HTML Sanitizer（XSS 防御）
- 💉 PDO 预处理（SQL 注入防御）
- ⏱️ 三档频率限制（全局 / 搜索 / 严格）
- 🚦 六级站点运行模式（关站 → 完全开放）
- 🌐 分布式 Session 支持
- 🔐 Remember Token 持久登录
- 🧮 验证码系统
- 🔒 后台 IP 白名单
- 🛡️ Open Redirect / CRLF 注入防护

</td>
</tr>
</table>

---

## 🏗️ 技术架构

```
┌─────────────────────────────────────────────────────────────┐
│                        客户端层                              │
│              浏览器 / 移动端 / API 调用方                      │
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│                       接入层 Nginx                           │
│          静态资源 CDN · 反向代理 · 负载均衡 · SSL              │
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│                     应用层 PHP-FPM                           │
│  ┌─────────┐  ┌──────────┐  ┌───────────┐  ┌────────────┐  │
│  │ 中间件   │→│ 控制器    │→│  服务层    │→│  模型层     │  │
│  │Auth     │  │Controller│  │ Service   │  │  Model     │  │
│  │CSRF     │  │          │  │           │  │（数据访问） │  │
│  │RateLimit│  │          │  │           │  │            │  │
│  └─────────┘  └──────────┘  └───────────┘  └────────────┘  │
└──────────┬──────────────────────────┬───────────────────────┘
           │                          │
┌──────────▼──────────┐  ┌────────────▼───────────────────────┐
│  缓存层 文件 / Redis  │  │         数据层 MySQL               │
│  · 页面缓存          │  │  · 30 张数据表                     │
│  · 数据缓存          │  │  · InnoDB 引擎                    │
│  · Session 存储      │  │  · 全文索引                       │
│  · 频率限制计数       │  │  · 读写分离就绪                    │
└─────────────────────┘  └────────────────────────────────────┘
```

### 技术栈一览

| 层级 | 技术 | 说明 |
|:-----|:-----|:-----|
| **后端语言** | PHP 8.0+ | 兼容共享主机常见的 8.0 / 8.1 / 8.2，可开启 OPcache 加速 |
| **Web 服务** | Nginx / Apache / 虚拟主机 | 无 root、无 shell、无常驻进程也能跑 |
| **数据库** | MySQL 5.6+ / MariaDB 10+ | InnoDB 引擎，不依赖 MySQL 8 专有特性 |
| **缓存** | 文件缓存（默认）/ Redis（可选） | 没有 Redis 时自动使用文件缓存，零扩展依赖 |
| **前端** | htmx 2.0.11 + 自研 CSS | 零构建、无 CDN 依赖，交互以服务端渲染片段为主（Alpine.js 已移除） |
| **后台 UI** | layuimini v2 + Layui 2.6.3 + Font Awesome 4.7 | 本地 vendored，iframe 多标签外壳；只在后台加载（前台不加载 layui） |
| **架构模式** | Controller + Service + Model | 数据访问集中在 Model，Service 只放业务流程与事务边界 |
| **插件系统** | WordPress 风格钩子 | `add_action` / `apply_filters`，零依赖，后台可启停 |

---

## 🚀 快速开始

### 环境要求

| 软件 | 最低版本 | 推荐版本 | 说明 |
|:-----|:---------|:---------|:-----|
| PHP | 8.0 | 8.2+ | 只用到 `str_starts_with` / `match` 等 8.0 特性 |
| MySQL | 5.6 | 5.7+ / 8.0 | 或 MariaDB 10.0+；不依赖 MySQL 8 专有语法 |
| Redis | 不需要 | 7.0+ | **可选**，没有时自动使用文件缓存 |
| Web 服务器 | 任意 | Nginx 1.20+ | 也支持 Apache、共享虚拟主机、PHP 内置服务器 |


> ⚠️ 必须启用的 PHP 扩展：`pdo_mysql`、`mbstring`、`json`
>
> 💡 可选扩展：`redis`（有则启用，无则自动走文件缓存）、`opcache`（提速）、`gd`（图片处理）、`zip`（备份/插件）
>
> 📁 需要**可写目录**：`storage/`、`config/`、`install/`（写安装锁）、`public/uploads/`。
> 这些目录要归 PHP 运行用户所有（Nginx + PHP-FPM 通常是 `www-data`）；安装向导第 2 步会逐个检测并指出哪个不可写。
>
> ✅ 不需要：Composer、npm/Node、root 权限、shell 访问、常驻进程、cron

### 安装步骤

两种方式，**推荐方式 A**（不需要 SSH，也不需要手敲 SQL）：

#### 方式 A：网页安装向导（推荐，约 30 秒）

```bash
# 1️⃣ 拿到代码
git clone https://github.com/mcwlgzs/AMuBBS.git && cd AMuBBS

# 2️⃣ 复制配置 + 给目录写权限（向导会检测这些目录可写）
cp .env.example .env        # 通常只填 DB_* 四项；没有 Redis 就保持 CACHE_DRIVER=auto
chmod -R 755 storage/ config/ install/ public/uploads/
# 若 PHP 以别的用户运行（如 www-data），改用 chown -R www-data:www-data storage config install public/uploads

# 3️⃣ 起服务：开发用内置服务器；生产把 public/ 指向 Nginx/Apache 站点根目录
php -S localhost:8000 -t public public/router.php
```

浏览器打开站点即自动进入 `/install`，六步走完：

| 步骤 | 你要做的 | 程序自动完成的 |
|:-----|:---------|:---------------|
| 1️⃣ 安装说明 | 按提示继续 | 展示环境要求与许可（见 [LICENSE](./LICENSE) / [DISCLAIMER.md](./DISCLAIMER.md)） |
| 2️⃣ 环境检测 | 无 | 校验 PHP ≥ 8.0、`pdo` / `pdo_mysql` / `mbstring` / `json`（`redis`、`OPcache` 可选），以及 `config/`、`storage/logs|cache|sessions/`、`install/` 是否可写 |
| 3️⃣ 数据库 | 填主机 / 端口 / 库名 / 账号（**库可以让向导建**，只要账号有建库权限） | 建库、导入 39 张表 |
| 4️⃣ 管理员 | 设置管理员账号与密码 | 创建管理员（bcrypt 哈希） |
| 5️⃣ 站点设置 | 填站点名称、站点 URL 等 | 写入站点配置 |
| 6️⃣ 完成 | —— | 写 `install/install.lock`；此后 `/install` 只显示「已安装」提示，**要重装需先删掉这个锁文件** |

安装完成后进后台：`/admin`（用第 4 步创建的账号登录）。

#### 方式 B：命令行手动安装（适合 CI / 自动化）

```bash
# 1️⃣ 克隆项目
git clone https://github.com/mcwlgzs/AMuBBS.git
cd AMuBBS

# 2️⃣ 配置环境变量
cp .env.example .env
# 编辑 .env，通常只需填 DB_* 四项；
# 没有 Redis 就保持 CACHE_DRIVER=auto，会自动使用文件缓存

# 3️⃣ 创建数据库并导入
mysql -u root -p -e "CREATE DATABASE amubbs DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p amubbs < install/database.sql

# 3.5️⃣ 可选：补上全文索引（MySQL 5.6+ / MariaDB 10+ 建议执行，加速英文搜索）
mysql -u root -p amubbs < install/optional_fulltext.sql

# 4️⃣ 设置目录权限
chmod -R 755 storage/ config/ public/uploads/

# 5️⃣ 启动开发服务器
php -S localhost:8000 -t public public/router.php
```

手动导入表结构后，浏览器首次访问仍会被引导到 `/install` 完成管理员与站点设置（此步骤会写锁文件；不需要向导时见 [`install/INSTALL.md`](./install/INSTALL.md)）。

### 环境变量配置

<details>
<summary>📄 <b>.env 完整参考</b>（点击展开）</summary>

```ini
# ── 应用配置 ──────────────────────────
APP_DEBUG=false              # 调试模式
APP_URL=http://localhost:8000
APP_KEY=                     # 「记住我」/后台 API Token 的签名密钥，建议显式配置
                             # 生成：php -r "echo bin2hex(random_bytes(32));"

# ── 数据库 ────────────────────────────
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=amubbs
DB_USERNAME=root
DB_PASSWORD=
# 有从库时才配，读连接会自动回退主库
# DB_READ_HOST=192.168.1.202,192.168.1.203

# ── Redis（可选，没有会自动用文件缓存）──
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# ── 驱动选择 ──────────────────────────
SESSION_DRIVER=file          # file（默认）| redis（多节点共享登录态时用）
CACHE_DRIVER=auto            # auto（推荐）| file | redis

# 上传：附件与图片固定存 public/uploads/，多节点时把该目录做成共享存储挂载，
# 没有 OSS 之类的驱动开关。
```

> 上面每一项都被代码真实读取（`php scripts/check_env.php` 会校验 `.env.example` 与代码一致，
> 防止出现「文档里有、代码不读」的死配置）。

</details>

---

## 🪶 部署到共享虚拟主机

如果只有 1C1G 小机器或共享空间（无 root、无 shell、无 Redis），按下面做即可：

1. 把 **`public/` 目录**作为网站根目录（`storage/` 必须留在 Web 根目录之外，否则缓存文件可被直接下载）
2. 确保 `storage/` 可写（Linux 下 `chmod -R 755 storage`，个别主机需要 `777`）
3. 编辑 `.env`，通常只填 `DB_*` 四项：

   ```ini
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=你的库名
   DB_USERNAME=你的用户名
   DB_PASSWORD=你的密码
   CACHE_DRIVER=auto      # 没有 Redis 时自动使用文件缓存
   ```

4. 浏览器访问 `https://你的域名/install` 走完安装向导

**为什么在这些环境里能跑：**

| 约束 | 本项目如何应对 |
|:-----|:-----|
| 没有 Redis | `CACHE_DRIVER=auto` 探测不到 Redis 时自动使用 `storage/cache/` 文件缓存，功能不降级 |
| 没有 shell / cron | 文件缓存自带惰性 GC，过期数据在读写时顺手清理，不依赖任何定时任务 |
| 没有 root | 只需要 `storage/` 可写，不需要任何系统级权限或常驻进程 |
| MySQL 版本老 | 主库脚本不含 FULLTEXT 索引，MySQL 5.6+ / MariaDB 10+ 都能顺利建表 |
| 中文搜索不准 | 中文关键词自动改走 LIKE，绕开 InnoDB FULLTEXT 对 CJK 分词不准导致的漏结果 |

> 💡 缓存目录默认 `storage/cache/`，可用 `CACHE_FILE_PATH` 指到站点目录之外。

---

## ☁️ 部署到虚拟机 / VPS / 云服务器

**可以，而且这是最舒服的部署形态。** 一台 **1 核 1 GB** 的 Ubuntu / Debian 虚拟机（本地 VMware / VirtualBox / Hyper-V，或云上的轻量应用服务器、VPS）就能跑起一个中小社区：

- 不需要 Docker、不需要 Composer/Node、不需要 daemon 或常驻进程；
- 只要求 **PHP 8.0+**（推荐 8.2）、**MySQL 5.6+ / MariaDB 10+**、Web 服务器（Nginx / Apache 都行）；
- 有 root 权限当然更好（能装 PHP-FPM、配 systemd），**但没有也能装**——面板主机就够。

### 三种常见做法

| 方式 | 适合谁 | 关键点 |
|:-----|:-------|:-------|
| **面板（宝塔 / aaPanel / 1Panel 等）** | 不熟命令行 | 建站时把**运行目录设为 `public/`**、PHP 选 8.0+；上传代码 → 建库 → 访问 `/install` 走向导 |
| **命令行 LEMP** | 想要干净可控 | `nginx + php-fpm + mariadb`，抄 [`install/nginx.conf.example`](./install/nginx.conf.example)（已含 `/uploads/` 禁执行、静态缓存、隐藏文件兜底） |
| **容器 / 一键包** | 已在用 Docker | 本项目**不带 Dockerfile**；用任意 `nginx + php-fpm + mariadb` 镜像，站点根指向 `public/`，把 `storage/`、`public/uploads/` 挂成卷即可 |

### 命令行部署（Ubuntu 22.04 / 24.04 为例）

```bash
# 1. 依赖：PHP 8.2 + 必需扩展 + MariaDB + Nginx
sudo apt update
sudo apt install -y nginx mariadb-server git unzip \
    php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-gd php8.2-zip

# 2. 放代码：网站根目录必须指向 public/，storage/ 要留在 Web 根目录之外
sudo mkdir -p /var/www/amubbs && cd /var/www/amubbs
sudo git clone https://github.com/mcwlgzs/AMuBBS.git .

# 3. 配置与权限（安装向导会检测这些目录是否可写）
sudo cp .env.example .env
sudo chown -R www-data:www-data /var/www/amubbs
sudo find /var/www/amubbs -type d -exec chmod 755 {} \;
sudo chmod 640 /var/www/amubbs/.env

# 4. Nginx：抄仓库里的示例，改 server_name 与 root（root 指向 .../public）
sudo cp install/nginx.conf.example /etc/nginx/sites-available/amubbs
sudo ln -s /etc/nginx/sites-available/amubbs /etc/nginx/sites-enabled/amubbs
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl enable --now php8.2-fpm mariadb

# 5. 建库（也可以让安装向导建，只要账号有建库权限）
sudo mariadb -e "CREATE DATABASE amubbs DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 6. 打开 http://<虚拟机IP>/ 走向导六步；生产保持 APP_DEBUG=false
```

**虚拟机部署的 4 个常见坑**：

1. **根目录指错了** —— 站点根必须是 `public/`，不是项目根；否则 `.env`、`storage/`、`config/` 可能被直接下载。
2. **目录不可写** —— 向导第 2 步会明确报出哪个目录不可写；把 `storage/`、`config/`、`install/` 交给 PHP-FPM 的运行用户（`www-data`）即可。
3. **安全组/防火墙没开** —— 云主机要在控制台放行 80/443；本机虚拟机注意网卡选「桥接」才能被局域网访问。
4. **没配 HTTPS** —— 生产建议 `sudo apt install certbot python3-certbot-nginx && sudo certbot --nginx`；同时在 `.env` 里把 `APP_URL` 写成 `https://你的域名`。

> 需要定时保洁（清理过期缓存/日志、补零头浏览量）时，加一条计划任务即可，不依赖常驻进程。
> 注意密钥**只走请求头 `X-Cron-Key`**（放 query 会进访问日志；`cron_key` 未配置时一律 403）：
> `*/10 * * * * curl -fsS -H "X-Cron-Key: 后台设置的cron_key" "https://你的域名/cron/run" >/dev/null`

---

## 📂 项目结构

```
AMuBBS/
├── app/                             # 📦 应用层
│   ├── Controllers/                 #   控制器（17 个前台 + 16 个后台）
│   │   ├── Admin/                   #   后台管理控制器
│   │   ├── Index.php                #   首页
│   │   ├── Thread.php               #   帖子
│   │   ├── User.php                 #   用户
│   │   └── ...
│   ├── Services/                    #   业务逻辑层（26 个服务：规则 + 事务边界 + 审计日志）
│   ├── Models/                      #   数据访问层（40 个模型：SQL + 行级缓存失效）
│   ├── Middlewares/                  #   中间件（Auth / CSRF / RateLimit / RunLevel）
│   ├── Events/                      #   事件定义（30 个事件常量）
│   └── Listeners/                   #   事件监听器
│
├── core/                            # ⚙️ 自研微框架（24 个核心类）
│   ├── Bootstrap.php                #   启动引导
│   ├── Router.php                   #   路由器
│   ├── Database.php                 #   数据库封装
│   ├── Cache.php                    #   缓存封装（文件 / Redis）
│   ├── Security.php                 #   安全组件
│   ├── Event.php                    #   钩子调度器（add_action / apply_filters）
│   ├── hooks.php                    #   全局钩子函数（插件作者用）
│   ├── PluginManager.php            #   插件管理器（含 install / uninstall 生命周期）
│   └── ...
│
├── config/                          # ⚙️ 配置文件
│   ├── app.php                      #   应用配置
│   ├── database.php                 #   数据库配置（含读写分离）
│   └── cache.php                    #   缓存配置
│
├── plugins/                         # 🔌 插件目录（后台「系统管理 → 插件管理」启停）
│   └── Example/                     #   自带示例插件
│
├── resources/                       # 🎨 资源文件
│   ├── views/                       #   视图模板（103 个）
│   └── lang/                        #   多语言（zh-cn / en-us）
│
├── public/                          # 🌐 Web 根目录（单一入口）
│   ├── index.php                    #   入口文件
│   ├── router.php                   #   开发服务器路由
│   └── assets/                      #   静态资源（CSS / JS / 图片）
│
├── storage/                         # 💾 运行时存储
│   ├── cache/                       #   文件缓存
│   ├── logs/                        #   日志
│   ├── plugin_config/               #   插件启用状态（plugins.json）
│   └── sessions/                    #   Session 文件
│
├── install/                         # 📥 安装器 & 数据库结构（39 张表）
├── scripts/                         # ✅ 验证脚本（冒烟 + 自检 + 5 个静态检查器）
├── docs/                            # 📚 开发文档（16 篇）
└── .env.example                     # 环境变量模板（每项都会被代码读取）
```

---

## 🔌 插件系统

AMuBBS 采用 **WordPress 风格钩子**的插件架构：插件通过 `add_action()` / `add_filter()`
挂到核心已经派发的事件与过滤器上，零依赖（不需要 Composer、不需要改核心文件）。

- 启用状态存 `storage/plugin_config/plugins.json`，后台 **系统管理 → 插件管理** 可启停/卸载；
- 首次启用调用插件的 `install()`，卸载调用 `uninstall()`（都是可选的）；
- 单个插件加载失败只记日志并跳过，不会把整站打成白屏。

### 开发自己的插件

<details>
<summary>🧩 <b>插件目录结构</b>（点击展开）</summary>

```
plugins/MyPlugin/
├── Plugin.php               # 主类（必须，实现 Core\PluginInterface 的 register()）
├── plugin.json              # 元数据（必须，name / title / version / entry / class）
└── assets/                  # 静态资源（可选，通过 /plugin-assets/MyPlugin/... 访问）
```

**plugin.json 示例：**

```json
{
    "name": "myplugin",
    "title": "我的插件",
    "version": "1.0.0",
    "description": "我的自定义插件",
    "author": "Your Name",
    "entry": "Plugin.php",
    "class": "Plugins\\MyPlugin\\Plugin"
}
```

**Plugin.php 示例：**

```php
<?php
namespace Plugins\MyPlugin;

use Core\PluginInterface;

class Plugin implements PluginInterface
{
    public function register(): void
    {
        add_filter('thread.title', fn(string $t) => $t . ' [MyPlugin]');
        add_action('thread.created', function (array $data) {
            // $data = ['thread_id'=>, 'forum_id'=>, 'user_id'=>, 'username'=>]
        });
    }
}
```

详细开发指南请参阅 [`docs/10-插件开发.md`](./docs/10-插件开发.md)，
实现原理见 [`docs/08-插件系统.md`](./docs/08-插件系统.md)。

</details>

---

## 📊 性能基准

> 测试环境：4C8G CentOS 8 · PHP 8.2（OPcache + JIT）· MySQL 8.0 · Redis 7.0

| 场景 | 响应时间 | 吞吐量 | 说明 |
|:-----|:--------:|:------:|:-----|
| 首页（缓存命中） | **< 5ms** | 5000+ QPS | Redis 页面缓存 |
| 首页（无缓存） | < 50ms | 2000 QPS | 数据库直查 |
| 帖子列表 | < 80ms | 1500 QPS | 50 条/页，带分页 |
| 帖子详情 | < 100ms | 1200 QPS | 含 50 条回复 |
| 全文搜索 | < 200ms | 500 QPS | MySQL FTS，10 万条数据 |
| 发帖 | < 150ms | — | 含图片异步处理 |

### 四级缓存体系

```
请求 → ① 进程内缓存（静态变量，零开销）
     → ② OPcache（字节码缓存 + JIT 编译）
     → ③ 分布式缓存（Redis；没有 Redis 时自动落到 storage/cache/ 文件缓存）
     → ④ MySQL（持久化存储，InnoDB Buffer Pool）
```

### 性能优化细节

| 优化项 | 说明 |
|:-------|:-----|
| OPcache 预加载 | `preload.php` 预编译 46 个核心文件到共享内存 |
| 页面级缓存 | 匿名用户首页整页缓存（Redis 或文件驱动），命中即直接返回 |
| Session 按需启动 | 匿名 GET 请求跳过 `session_start()` |
| 延迟写入 | `fastcgi_finish_request()` 后执行非关键写操作 |
| 路由 O(1) 查找 | 静态路由使用 hashmap，动态路由正则匹配 |
| 游客访问节流 | 30 秒内同一游客不重复写数据库 |
| 配置懒加载 | 配置文件按需加载，减少启动开销 |

---

## 🖥️ 服务器 / 虚拟机配置建议

| 规模 | CPU | 内存 | 磁盘 | 说明 |
|:-----|:----|:-----|:-----|:-----|
| 个人 / 测试 | 1 核 | 1 GB | 10 GB | 关掉用不到的服务即可跑；文件缓存 |
| 小社区（推荐起步） | 1–2 核 | 2 GB | 20 GB | 开 OPcache；可选 Redis |
| 中型社区 | 2–4 核 | 4 GB+ | 40 GB+ | 加 Redis，静态资源走 CDN，数据库可独立一台 |

**操作系统**：Ubuntu 22.04 / 24.04 LTS、Debian 12、CentOS 8+ / AlmaLinux / Rocky 均可（只要 PHP 8.0+）；Windows 建议只用于开发自测（`php -S` 或 Nginx for Windows）。

> 内存主要被 MySQL/MariaDB 占用（默认配置约 300–500 MB），PHP-FPM 按 `pm.max_children` 估算。本项目自身很轻，**不需要为它单独买大机器**；具体部署步骤见上面的「部署到虚拟机 / VPS / 云服务器」与 [`install/INSTALL.md`](./install/INSTALL.md)。

---

## 🌐 生产部署

<details>
<summary>📋 <b>Nginx 配置</b>（点击展开）</summary>

仓库里有**完整、可直接抄**的配置文件：[`install/nginx.conf.example`](./install/nginx.conf.example)（含上传目录禁执行、静态资源缓存/压缩、隐藏文件兜底）。
下面是最关键的部分——注意 `root` 必须指向 `public/`，且 **`/uploads/` 必须禁止执行脚本**（上传目录全是用户可控内容）：

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/AMuBBS/public;      # 不是项目根！
    index index.php;
    client_max_body_size 25m;

    # 安全头
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # 路由重写
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # 上传目录：禁止任何脚本被解析（伪装成图片的 .php 是经典打穿方式）
    location ^~ /uploads/ {
        location ~ \.(php|phtml|php[0-9]|pht|phar|cgi|pl|py|sh|asp|aspx|jsp)$ { return 403; }
        add_header X-Content-Type-Options "nosniff";
        add_header Content-Security-Policy "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox";
        add_header Cache-Control "public, max-age=2592000";
        try_files $uri =404;
    }

    # 只允许 index.php 走 PHP，其余 .php 一律 404
    location = /index.php {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        include fastcgi_params;
    }
    location ~ \.php$ { return 404; }

    # 静态资源缓存
    location ~* \.(css|js|png|jpg|gif|ico|svg|woff2?|ttf|eot)$ {
        expires 7d;
        access_log off;
        add_header Cache-Control "public";
    }

    # 禁止访问隐藏文件
    location ~ /\.(?!well-known) { deny all; }
}
```

> 反向代理部署（Nginx 终止 TLS 后转 php-fpm）时，请到 **后台 → 系统设置** 把反代 IP 填进 `trusted_proxies`，
> 否则应用无法区分真实客户端 IP，限流 / 登录锁定 / `admin_bind_ip` 全部失效。

</details>

<details>
<summary>⚙️ <b>PHP 优化配置</b>（点击展开）</summary>

完整版见 [`install/php.ini.example`](./install/php.ini.example)（含 Session GC、上传上限、生产环境必须关闭的项）：

```ini
; ── OPcache（未开时首页冷渲染 p50 ≈ 39ms）─────
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.preload=/var/www/AMuBBS/preload.php
opcache.preload_user=www-data

; ── Session（必须与 config/app.php 的 SESSION_LIFETIME 对齐）─────
session.gc_maxlifetime=7200
session.gc_probability=1
session.gc_divisor=100
session.use_strict_mode=1

; ── 上传与超时 ─────────────────────
memory_limit=256M
upload_max_filesize=20M
post_max_size=25M
max_execution_time=60

; ── 生产环境务必 ───────────────────
display_errors=Off
log_errors=On
expose_php=Off
```

</details>

<details>
<summary>🔄 <b>分布式部署</b>（点击展开）</summary>

AMuBBS 支持从单机到分布式的平滑扩展：

```
阶段 1：单机部署（Nginx + PHP-FPM + MySQL + Redis 同一台）
    ↓
阶段 2：数据库分离（MySQL 独立服务器）
    ↓
阶段 3：读写分离（MySQL 主从 + Redis 集群）
    ↓
阶段 4：负载均衡（多台 Web 节点 + Session 共享）
```

项目自带 `deploy.sh` 自动部署脚本，支持本机部署和远程节点部署（rsync + 健康检查）。

详见 [`docs/09-分布式.md`](./docs/09-分布式.md) 和 [`docs/11-扩展.md`](./docs/11-扩展.md)

</details>

<details>
<summary>🔗 <b>RESTful API</b>（点击展开）</summary>

AMuBBS 提供完整的 RESTful API，支持移动端和第三方集成：

- Token 认证机制
- 用户、板块、帖子、回复、搜索、通知等完整接口
- 统一 JSON 响应格式
- 频率限制 & 错误码规范

详见 [`docs/05-API.md`](./docs/05-API.md)

</details>

---

## 📚 开发文档

完整文档位于 [`docs/`](./docs/) 目录，共 16 篇：

| # | 文档 | 内容 |
|:-:|:-----|:-----|
| 00 | [总览](./docs/00-总览.md) | 文档导航、技术栈总结、快速开始 |
| 01 | [概述](./docs/01-概述.md) | 项目定位、技术选型、开发规划 |
| 02 | [架构](./docs/02-架构.md) | 分层架构、核心框架（Bootstrap/Router/Database/Cache）、Controller + Model 分层纪律 |
| 03 | [数据库](./docs/03-数据库.md) | 表结构设计、索引优化 |
| 04 | [性能](./docs/04-性能.md) | 多级缓存、查询优化 |
| 05 | [API](./docs/05-API.md) | RESTful 接口、鉴权、限流 |
| 06 | [前端选型](./docs/06-前端选型.md) | 自研 CSS + htmx 方案与历史选型对比 |
| 07 | [htmx 开发](./docs/07-htmx开发.md) | 服务端渲染片段、data-* 增强、实战示例 |
| 08 | [插件系统](./docs/08-插件系统.md) | 钩子内核、插件生命周期、后台管理、验证方式 |
| 09 | [分布式](./docs/09-分布式.md) | CDN、负载均衡、读写分离 |
| 10 | [插件开发](./docs/10-插件开发.md) | 快速开始、事件与过滤器清单、发布检查清单 |
| 11 | [扩展](./docs/11-扩展.md) | 从单机到多机：无状态设计、平滑迁移、自动化部署 |
| 12 | [后台](./docs/12-后台.md) | 后台架构（layuimini v2 + Layui 2.6.3）、模块清单、权限与交互约定 |

---

## 🗺️ 路线图

所有功能均已完成！

---

## 🤝 参与贡献

欢迎提交 Issue 和 Pull Request！

```bash
# 1. Fork 并克隆
git clone https://github.com/mcwlgzs/AMuBBS.git

# 2. 创建特性分支
git checkout -b feature/your-feature

# 3. 提交变更
git commit -m 'feat: 描述你的改动'

# 4. 推送并创建 PR
git push origin feature/your-feature
```

**提交前请跑一遍验证**（语法 + 7 个静态检查器 + 3 个自检 + 125 项冒烟），必须在**无 Redis**环境下全绿：

```bash
bash scripts/verify.sh          # Linux / macOS / CI
powershell scripts/verify.ps1   # Windows
```

提交信息遵循 [Conventional Commits](https://www.conventionalcommits.org/) 规范：

| 前缀 | 用途 |
|:-----|:-----|
| `feat` | 新增功能 |
| `fix` | 修复 Bug |
| `perf` | 性能优化 |
| `refactor` | 代码重构 |
| `docs` | 文档更新 |
| `style` | 代码格式 |
| `test` | 测试相关 |
| `chore` | 构建 / 工具链 |

---

## 📄 开源协议

**MIT 许可证** — 详情请阅读 [LICENSE](./LICENSE)

- ✅ 允许商用、修改、分发、再授权、私有部署
- ✅ 只需保留版权声明与许可声明
- ✅ 第三方组件（layui / layuimini / Font Awesome / htmx）遵循各自许可，清单见
  [`public/assets/vendor/VERSIONS.txt`](./public/assets/vendor/VERSIONS.txt)
- ⚠️ **补充免责声明**（见 [DISCLAIMER.md](./DISCLAIMER.md)，不缩减 MIT 授予的任何权利）：
  本程序只提供技术工具本身，按「原样」提供；使用者使用本程序搭建、运营的**任何违法
  违规（含违法犯罪）网站及其行为，与作者和版权所有者无关**，作者不对此承担任何责任，
  相关法律责任与后果由使用者自行承担。

---

<div align="center">

<sub>用心构建 · 追求极致</sub>

**[AMuBBS](https://github.com/mcwlgzs/AMuBBS)**

</div>
