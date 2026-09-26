# API 接口

> 本文按当前实现重写。早期草稿里的响应格式（`{success, data, error:{code}}`）、
> WebSocket 实时通知、`RateLimiter` 直接用 Redis `incr` 等都与代码不符，已全部替换。
>
> 真实约定：**响应体统一是 `{code, message, data}`，`code=0` 表示成功**，
> 错误用 HTTP 状态码表达类别；通知走轮询，**没有 WebSocket**。

## 5.1 通用约定

### 5.1.1 响应格式

```json
// 成功（HTTP 200）
{ "code": 0, "message": "ok", "data": { "id": 12, "title": "示例" } }

// 失败（HTTP 400/401/403/404/429）
{ "code": -1, "message": "帖子不存在", "data": null }
```

- `code`：`0` 成功，`-1`（或其它非 0）失败；判成功请用 `code === 0`，不要只看 HTTP 200。
- `message`：给自己看的中文提示，可直接展示。
- `data`：成功时的数据；失败时为 `null`。
- 实现见 `app/Controllers/ApiBase.php` 的 `apiSuccess()` / `apiError()`。

> ⚠️ 后台的 JSON 表格接口（`/admin/api/*`）用的是另一套：`{code, msg, data, count}`。
> 两套别混用，见 5.6。

### 5.1.2 状态码

| 状态码 | 含义 | 典型场景 |
| --- | --- | --- |
| 200 | 成功 | 正常返回 |
| 400 | 参数错误 | 标题太短、缺少必填字段 |
| 401 | 未认证 / Token 失效 | 没带头、Token 过期（有效期 30 天） |
| 403 | 无权限 | 没有该板块的发帖权限 |
| 404 | 资源不存在 | 用户 / 板块 / 帖子不存在 |
| 429 | 触发限流 | 全局或严格限流（见 5.3） |
| 500 | 服务端错误 | 数据库异常等，`message` 为通用提示 |

### 5.1.3 认证

```http
Authorization: Bearer {token}
```

- Token 由 `POST /api/auth/login` 或 `POST /api/auth/register` 返回；
- 服务端只存 **sha256 哈希**（`User::findByApiToken(hash('sha256', $token))`），
  所以 Token 只在生成时出现一次，丢了只能重新登录；
- 有效期 **30 天**（`ApiBase::TOKEN_TTL = 86400 * 30`），过期或登出后清除；
- 需要登录的端点用 `authenticate()`（未登录返回 401），公开端点用 `optionalAuth()`（登录则带出用户）。

### 5.1.4 分页

列表类端点统一返回：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "items": [ ... ],
    "total": 137,
    "page": 1,
    "per_page": 20,
    "total_pages": 7
  }
}
```

`per_page` 上限 50，`page` 上限 500。

### 5.1.5 请求体

JSON 请求体或表单 `application/x-www-form-urlencoded` 都可以（`Base::getJsonInput()` 会兼容）；
带 Bearer Token 的 `/api/*` 请求**免除 CSRF 校验**，没有 Token 的 `/api/*` POST 仍要过 CSRF。

## 5.2 端点清单

共 15 条路由（`core/Bootstrap.php` 注册）：

### 5.2.1 认证

| 方法 | 路径 | 参数 | 说明 |
| --- | --- | --- | --- |
| POST | `/api/auth/login` | `username`, `password` | 返回 `token` + `user`；受严格限流 |
| POST | `/api/auth/register` | `username`, `email`, `password`, `password_confirm`, `nickname`(可选) | 返回 `token` + `user`；受严格限流 |
| POST | `/api/auth/logout` | 无 | 清除当前 Token |

```http
POST /api/auth/login
Content-Type: application/json

{ "username": "admin", "password": "******" }
```

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "token": "9f2c…（仅此一次可见）",
    "user": { "id": 1, "username": "admin", "nickname": "管理员", "group_id": 3 }
  }
}
```

### 5.2.2 用户

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/users/me` | 需要登录，返回自己的安全字段（`UserSvc::safeInfo`） |
| GET | `/api/users/{id}` | 公开的用户资料，不存在返回 404 |

### 5.2.3 板块

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/forums` | 板块列表（含层级信息） |
| GET | `/api/forums/{id}` | 单个板块，不存在返回 404 |

### 5.2.4 主题与回复

| 方法 | 路径 | 参数 | 说明 |
| --- | --- | --- | --- |
| GET | `/api/threads` | `forum_id`(可选), `page`, `per_page` | 不传 `forum_id` 时是全站最新 |
| GET | `/api/threads/{id}` | — | 主题详情 |
| POST | `/api/threads` | `forum_id`, `title`(≥2 字), `content`(≥5 字) | 需登录；无权限返回 403 |
| GET | `/api/threads/{id}/posts` | `page`, `per_page` | 回复列表（分页） |
| POST | `/api/threads/{id}/posts` | `content` | 需登录，发回复 |

发帖走的是与网页版**同一个** `ThreadSvc::createThread()`：
防灌水、IP 频率、敏感词、插件过滤器（`thread.title` / `post.content`）全部一样生效，
也会正常派发 `thread.created` / `post.created` 事件。

### 5.2.5 搜索

| 方法 | 路径 | 参数 | 说明 |
| --- | --- | --- | --- |
| GET | `/api/search` | `q`, `type`(`thread`\|`post`), `page` | 受搜索专用限流；空 `q` 直接返回空结果 |

中文短词不依赖 FULLTEXT：`SearchSvc` 在无全文索引时会走 LIKE 回退（见 `install/INSTALL.md` 的说明）。

### 5.2.6 通知

| 方法 | 路径 | 参数 | 说明 |
| --- | --- | --- | --- |
| GET | `/api/notifications` | `page` | 返回 `items` / `total` / `unread` / `page` |
| POST | `/api/notifications/read` | `ids`(数组，可选) | 传 `ids` 标记指定几条（最多 100 条），不传则全部已读 |

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "items": [
      { "id": 88, "type": "reply", "title": "有人回复了你的帖子", "is_read": 0, "created_at": 1790313968 }
    ],
    "total": 12,
    "unread": 3,
    "page": 1
  }
}
```

## 5.3 频率限制

限流在**全局中间件**里，基于缓存计数器（`Cache::incrementWithLimit()`，文件驱动下同样原子），
配置项在后台「系统设置」里可改：

| 范围 | 设置键（默认值） | 适用 |
| --- | --- | --- |
| 全局 | `rate_limit_global_max` = 60 / `rate_limit_global_window` = 60 秒 | 所有请求（按 IP 计数） |
| 严格 | `rate_limit_strict_max` = 5 / `rate_limit_strict_window` = 300 秒 | `/api/auth/login`、`/api/auth/register` |
| 搜索 | `rate_limit_search_max` = 10 / `rate_limit_search_window` = 60 秒 | 搜索类路由 |

超限返回 **429**：

```json
{ "code": -1, "message": "操作过于频繁，请稍后再试", "data": null }
```

htmx 请求额外带 `HX-Trigger: frontFlash`，前台会弹出具体原因而不是一句「请求失败」。
注意：**不做限流的极端情况是「缓存后端不可用」**——那时 `Cache` 会记录日志并放行本次请求
（宁可放行也不误伤正常用户，见 `core/Cache.php` 的降级分支）。

## 5.4 通知为什么是轮询

前台与后台都用**轮询**，不用 WebSocket：

| 场景 | 实现 |
| --- | --- |
| 前台未读红点 | `assets/js/app.js` 定时 `fetch('/notifications/unread-count')` |
| 前台通知面板 | htmx `GET /notifications/popup` 取回片段塞进 `#notifPopup`，`POST /notifications/read-all` 全部已读 |
| 后台通知角标 | 定时 `fetch('/admin/api/notifications?page=1&limit=1')` |
| 移动端 / 第三方 | `GET /api/notifications` + `POST /api/notifications/read` |

原因：WebSocket 需要常驻进程（Swoole / Workerman），本项目目标环境是**没有 root、没有常驻进程**
的共享虚拟主机。轮询间隔刻意放长（未读数 30–60 秒级），代价是可控的几次轻查询。

## 5.5 快速自测

```bash
BASE=http://127.0.0.1:8000

# 1. 登录拿 Token
TOKEN=$(curl -s -X POST $BASE/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin123456"}' | php -r 'echo json_decode(stream_get_contents(STDIN), true)["data"]["token"] ?? "";')

# 2. 带 Token 访问
curl -s $BASE/api/users/me -H "Authorization: Bearer $TOKEN"

# 3. 主题列表（公开）
curl -s "$BASE/api/threads?forum_id=1&per_page=5"
```

## 5.6 与后台 JSON 接口的区别

| | `/api/*` | `/admin/api/*` |
| --- | --- | --- |
| 用途 | 移动端 / 第三方集成 | 后台页面与顶栏角标的兼容接口 |
| 认证 | `Authorization: Bearer` | 管理员会话 + AdminAuth 中间件 |
| 格式 | `{code, message, data}` | `{code, msg, data, count}` |
| 数量 | 15 条 | 16 条 |

后台页面本身是 **layuimini v2 + Layui 2.6.3 的 iframe 多标签外壳**（完整服务端渲染页面，不用 htmx——htmx 只在前台加载），这些 `/admin/api/*` 接口主要为后台顶栏角标、弹窗内的异步提交与旧调用方保留。

## 5.7 排错

| 现象 | 原因 |
| --- | --- |
| 一律 401 | 没带 `Authorization` 头、格式不是 `Bearer xxx`，或 Token 已过期（30 天） |
| 401 但刚登录过 | 密码/用户名改动或后台清过 `api_token` 字段，需要重新登录 |
| POST `/api/*` 报 CSRF 失败 | 没带 Bearer Token（无 Token 的 `/api/*` POST 仍受 CSRF 保护） |
| 429 频繁出现 | 全网 60/60s 或搜索 10/60s 上限；调后台设置或给脚本加节流 |
| `code` 非 0 但 HTTP 200 | 不可能：失败一定配 4xx/5xx；若真遇到，说明是缓存的旧响应，清一次缓存 |
| 搜索中文没结果 | 先看 `install/optional_fulltext.sql` 是否导入；短词走 LIKE，属预期行为 |
