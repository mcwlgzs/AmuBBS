<div align="center">

<img src="public/assets/images/logo.png" alt="AMuBBS" width="100" height="100">

# AMuBBS

### 轻量、高性能、零依赖的现代 PHP 论坛系统

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-8892BF?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL 8.0+](https://img.shields.io/badge/MySQL-8.0+-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Redis 7.0+](https://img.shields.io/badge/Redis-7.0+-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![Alpine.js](https://img.shields.io/badge/Alpine.js-3.x-77C1D2?style=for-the-badge&logo=alpine.js&logoColor=white)](https://alpinejs.dev)
[![License](https://img.shields.io/badge/License-Proprietary-red?style=for-the-badge)](./LICENSE)
[![Commercial Use](https://img.shields.io/badge/Commercial-Forbidden-red?style=for-the-badge)](./LICENSE)

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

AMuBBS 是一款面向中小型社区的**现代论坛系统**。不依赖任何第三方框架，基于 PHP 8.2 从零构建自研微框架，采用 **Controller → Service → Repository** 三层架构，配合 Redis 多级缓存，追求极致的性能与开发体验。

> 💡 设计理念参考 [Xiuno BBS](https://bbs.xiuno.com/)，以现代 PHP 8.2 架构全面重新实现。

### 与 Xiuno BBS 的对比

| 维度 | Xiuno BBS | AMuBBS |
|:-----|:----------|:-------|
| PHP 版本 | PHP 7.0+ | **PHP 8.2+**（JIT、属性、枚举、纤程） |
| 前端方案 | Bootstrap 4 + jQuery 3 | **Alpine.js**（15KB，零构建） |
| 架构模式 | MVC 混合 | **三层架构**（Controller → Service → Repository） |
| 缓存策略 | 可选多种 | **Redis 为核心**，四级缓存体系 |
| API 设计 | 传统表单提交 | **RESTful API** 优先，JSON 响应 |
| 实时通知 | 轮询 | **WebSocket**（Swoole / Workerman） |
| 插件系统 | Hook + Overwrite | **事件驱动 + 依赖注入** |

---

## ✨ 核心特性

<table>
<tr>
<td width="50%" valign="top">

### 💬 社区核心
- 🏠 多板块管理（子板块、板块权限、版主系统）
- 📝 发帖 / 回复 / 编辑 / 删除 / 移动
- 📌 置顶 / 加精 / 锁帖
- ✍️ Markdown + TinyMCE 双编辑器
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
│  │ 中间件   │→│ 控制器    │→│  服务层    │→│  仓库层     │  │
│  │Auth     │  │Controller│  │ Service   │  │Repository  │  │
│  │CSRF     │  │          │  │           │  │            │  │
│  │RateLimit│  │          │  │           │  │            │  │
│  └─────────┘  └──────────┘  └───────────┘  └────────────┘  │
└──────────┬──────────────────────────┬───────────────────────┘
           │                          │
┌──────────▼──────────┐  ┌────────────▼───────────────────────┐
│    缓存层 Redis      │  │         数据层 MySQL               │
│  · 页面缓存          │  │  · 30 张数据表                     │
│  · 数据缓存          │  │  · InnoDB 引擎                    │
│  · Session 存储      │  │  · 全文索引                       │
│  · 频率限制计数       │  │  · 读写分离就绪                    │
└─────────────────────┘  └────────────────────────────────────┘
```

### 技术栈一览

| 层级 | 技术 | 说明 |
|:-----|:-----|:-----|
| **后端语言** | PHP 8.2+ | OPcache + JIT 加速，利用属性、枚举、纤程等新特性 |
| **Web 服务** | Nginx + PHP-FPM | 高并发处理，静态资源优化 |
| **数据库** | MySQL 8.0+ | InnoDB 引擎，全文索引，窗口函数 |
| **缓存** | Redis 7.0+ | 多级缓存，Session 共享，频率限制 |
| **前端** | Alpine.js 3.x | 15KB 极致轻量，类 Vue 语法，零构建 |
| **后台 UI** | Layui | 开箱即用的后台管理界面 |
| **架构模式** | 三层架构 | Controller → Service → Repository |
| **插件系统** | 事件驱动 | EventDispatcher + 依赖注入容器 |

---

## 🚀 快速开始

### 环境要求

| 软件 | 最低版本 | 推荐版本 |
|:-----|:---------|:---------|
| PHP | 8.2 | 8.3 |
| MySQL | 8.0 | 8.0+ |
| Redis | 7.0 | 7.2+ |
| Nginx | 1.20 | 1.24+ |

> ⚠️ 需要启用的 PHP 扩展：`pdo_mysql`、`redis`、`opcache`、`mbstring`、`gd`

### 安装步骤

```bash
# 1️⃣ 克隆项目
git clone https://github.com/mcwlgzs/AMuBBS.git
cd AMuBBS

# 2️⃣ 配置环境变量
cp .env.example .env
# 编辑 .env，填写数据库和 Redis 连接信息

# 3️⃣ 创建数据库并导入
mysql -u root -p -e "CREATE DATABASE amubbs DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p amubbs < install/database.sql

# 4️⃣ 设置目录权限
chmod -R 755 storage/ public/uploads/

# 5️⃣ 启动开发服务器
php -S localhost:8000 -t public public/router.php
```

打开浏览器访问 `http://localhost:8000`，首次进入将引导完成安装配置。

### 环境变量配置

<details>
<summary>📄 <b>.env 完整参考</b>（点击展开）</summary>

```ini
# ── 应用配置 ──────────────────────────
APP_MODE=single              # single（单机）| distributed（分布式）
APP_DEBUG=false              # 调试模式
APP_URL=http://localhost:8000

# ── 数据库 ────────────────────────────
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=amubbs
DB_USERNAME=root
DB_PASSWORD=

# ── Redis ─────────────────────────────
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# ── 驱动选择 ──────────────────────────
SESSION_DRIVER=file          # file | redis
CACHE_DRIVER=redis           # redis | file
UPLOAD_DRIVER=local          # local | oss | nfs
```

</details>

---

## 📂 项目结构

```
AMuBBS/
├── app/                             # 📦 应用层
│   ├── Controllers/                 #   控制器（17 个前台 + 15 个后台）
│   │   ├── Admin/                   #   后台管理控制器
│   │   ├── Index.php                #   首页
│   │   ├── Thread.php               #   帖子
│   │   ├── User.php                 #   用户
│   │   └── ...
│   ├── Services/                    #   业务逻辑层（35 个服务）
│   ├── Repositories/                #   数据访问层
│   ├── Middlewares/                  #   中间件（Auth / CSRF / RateLimit / RunLevel）
│   ├── Events/                      #   事件定义
│   └── Listeners/                   #   事件监听器
│
├── core/                            # ⚙️ 自研微框架（21 个核心类）
│   ├── Bootstrap.php                #   启动引导
│   ├── Router.php                   #   路由器
│   ├── Database.php                 #   数据库封装
│   ├── Cache.php                    #   缓存封装
│   ├── Security.php                 #   安全组件
│   ├── Container.php                #   依赖注入容器
│   ├── EventDispatcher.php          #   事件调度器
│   ├── PluginManager.php            #   插件管理器
│   └── ...
│
├── config/                          # ⚙️ 配置文件
│   ├── app.php                      #   应用配置
│   ├── database.php                 #   数据库配置
│   └── cache.php                    #   缓存配置
│
├── plugins/                         # 🔌 插件目录（需手动加载）
│
├── resources/                       # 🎨 资源文件
│   ├── views/                       #   视图模板
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
│   └── sessions/                    #   Session 文件
│
├── install/                         # 📥 安装器 & 数据库结构（30 张表）
├── docs/                            # 📚 开发文档（16 篇）
├── preload.php                      # OPcache 预加载
├── deploy.sh                        # 🚀 自动部署脚本
└── .env.example                     # 环境变量模板
```

---

## 🔌 插件系统

AMuBBS 采用**事件驱动 + 依赖注入**的插件架构，支持热插拔、配置管理和生命周期管理。

> ⚠️ **注意**：插件系统正在重构中，当前版本需手动加载插件。

### 开发自己的插件

<details>
<summary>🧩 <b>插件目录结构</b>（点击展开）</summary>

```
plugins/MyPlugin/
├── MyPluginPlugin.php       # 主类（必须，实现 PluginInterface）
├── plugin.json              # 元数据（必须，名称/版本/描述）
├── config.php               # 默认配置（可选）
└── assets/                  # 静态资源（可选）
```

**plugin.json 示例：**

```json
{
    "name": "MyPlugin",
    "version": "1.0.0",
    "description": "我的自定义插件",
    "author": "Your Name",
    "require": {
        "php": ">=8.2"
    }
}
```

详细开发指南请参阅 [`docs/10-插件开发.md`](./docs/10-插件开发.md)

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
     → ③ Redis（数据缓存 / 页面缓存 / MGET 批量预热）
     → ④ MySQL（持久化存储，InnoDB Buffer Pool）
```

### 性能优化细节

| 优化项 | 说明 |
|:-------|:-----|
| OPcache 预加载 | `preload.php` 预编译 42 个核心文件到共享内存 |
| 页面级缓存 | 匿名用户整页 Redis 缓存，< 5ms 响应 |
| Session 按需启动 | 匿名 GET 请求跳过 `session_start()` |
| 延迟写入 | `fastcgi_finish_request()` 后执行非关键写操作 |
| 路由 O(1) 查找 | 静态路由使用 hashmap，动态路由正则匹配 |
| 游客访问节流 | 30 秒内同一游客不重复写数据库 |
| 配置懒加载 | 配置文件按需加载，减少启动开销 |

---

## 🖥️ 服务器要求

<table>
<tr>
<th></th>
<th>最低配置</th>
<th>推荐配置</th>
</tr>
<tr>
<td><b>CPU</b></td>
<td>2 核</td>
<td>4 核</td>
</tr>
<tr>
<td><b>内存</b></td>
<td>4 GB</td>
<td>8 GB</td>
</tr>
<tr>
<td><b>硬盘</b></td>
<td>20 GB SSD</td>
<td>50 GB SSD</td>
</tr>
<tr>
<td><b>带宽</b></td>
<td>5 Mbps</td>
<td>10 Mbps</td>
</tr>
<tr>
<td><b>系统</b></td>
<td colspan="2">Ubuntu 22.04 LTS / CentOS 8+ / Debian 12</td>
</tr>
</table>

---

## 🌐 生产部署

<details>
<summary>📋 <b>Nginx 配置</b>（点击展开）</summary>

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/AMuBBS/public;
    index index.php;

    # 安全头
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # 路由重写
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP 处理
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 静态资源缓存
    location ~* \.(css|js|png|jpg|gif|ico|svg|woff2?|ttf|eot)$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    # 禁止访问敏感文件
    location ~ /\.(env|git|htaccess) { deny all; }
    location ~ ^/(storage|config|core|app)/ { deny all; }
}
```

</details>

<details>
<summary>⚙️ <b>PHP 优化配置</b>（点击展开）</summary>

```ini
; ── OPcache ───────────────────────
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0
opcache.preload=/var/www/AMuBBS/preload.php
opcache.preload_user=www-data

; ── JIT ───────────────────────────
opcache.jit=1255
opcache.jit_buffer_size=64M

; ── PHP ───────────────────────────
memory_limit=256M
upload_max_filesize=10M
post_max_size=10M
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
| 02 | [架构](./docs/02-架构.md) | 五层架构、核心框架、三层模式 |
| 03 | [数据库](./docs/03-数据库.md) | 30 张表设计、索引优化、分表方案 |
| 04 | [性能](./docs/04-性能.md) | 多级缓存、查询优化、JIT 配置 |
| 05 | [API](./docs/05-API.md) | RESTful 接口、WebSocket、限流 |
| 06 | [前端选型](./docs/06-前端选型.md) | jQuery vs Alpine.js vs Vue 3 对比 |
| 07 | [Alpine 开发](./docs/07-Alpine开发.md) | 组件开发、实战示例、性能技巧 |
| 08 | [插件系统](./docs/08-插件系统.md) | 架构设计、核心组件、完整实现 |
| 09 | [分布式](./docs/09-分布式.md) | CDN、负载均衡、读写分离、集群 |
| 10 | [插件开发](./docs/10-插件开发.md) | 快速开始、实战示例、发布流程 |
| 11 | [扩展](./docs/11-扩展.md) | 无状态设计、平滑迁移、自动化部署 |
| 12 | [后台](./docs/12-后台.md) | 分布式配置、节点监控、系统设置 |

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

**非商业使用许可证** — 详情请阅读 [LICENSE](./LICENSE)

- ✅ 免费用于个人学习、研究、非营利性目的
- ✅ 可以修改和再分发
- ❌ **禁止任何商业用途**（需获得授权）
- ❌ 禁止去除版权声明

---

<div align="center">

<sub>用心构建 · 追求极致</sub>

**[AMuBBS](https://github.com/mcwlgzs/AMuBBS)**

</div>
