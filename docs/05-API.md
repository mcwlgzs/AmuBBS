# API 接口设计

## 5.1 API 设计原则

### 5.1.1 RESTful 规范
- **资源导向**：URL 表示资源，HTTP 方法表示操作
- **统一接口**：GET（查询）、POST（创建）、PUT（更新）、DELETE（删除）
- **无状态**：每次请求包含完整信息，支持 Token 认证
- **分层系统**：客户端无需关心服务端实现细节

### 5.1.2 响应格式

#### 成功响应
```json
{
  "success": true,
  "data": {
    "id": 123,
    "title": "示例帖子"
  },
  "message": "操作成功",
  "timestamp": 1708617600
}
```

#### 错误响应
```json
{
  "success": false,
  "error": {
    "code": "THREAD_NOT_FOUND",
    "message": "帖子不存在",
    "details": null
  },
  "timestamp": 1708617600
}
```

#### 分页响应
```json
{
  "success": true,
  "data": [
    {"id": 1, "title": "帖子1"},
    {"id": 2, "title": "帖子2"}
  ],
  "pagination": {
    "total": 100,
    "page": 1,
    "page_size": 20,
    "total_pages": 5
  }
}
```

### 5.1.3 HTTP 状态码

| 状态码 | 说明 | 使用场景 |
|--------|------|----------|
| 200 | OK | 请求成功 |
| 201 | Created | 资源创建成功 |
| 204 | No Content | 删除成功（无返回内容） |
| 400 | Bad Request | 请求参数错误 |
| 401 | Unauthorized | 未认证 |
| 403 | Forbidden | 无权限 |
| 404 | Not Found | 资源不存在 |
| 422 | Unprocessable Entity | 验证失败 |
| 429 | Too Many Requests | 请求过于频繁 |
| 500 | Internal Server Error | 服务器错误 |

## 5.2 认证与授权

### 5.2.1 Token 认证

#### 登录获取 Token
```http
POST /api/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password123"
}
```

响应：
```json
{
  "success": true,
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_in": 86400,
    "user": {
      "id": 123,
      "username": "testuser",
      "email": "user@example.com"
    }
  }
}
```

#### 使用 Token
```http
GET /api/threads
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### 5.2.2 Token 实现

```php
<?php
// app/Services/AuthService.php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthService
{
    private string $secretKey;
    private int $expiresIn = 86400; // 24小时

    public function __construct()
    {
        $this->secretKey = $_ENV['JWT_SECRET'] ?? 'your-secret-key';
    }

    /**
     * 生成 Token
     */
    public function generateToken(int $userId): string
    {
        $payload = [
            'iss' => 'amubbs',              // 签发者
            'iat' => time(),                // 签发时间
            'exp' => time() + $this->expiresIn,  // 过期时间
            'uid' => $userId,               // 用户ID
        ];

        return JWT::encode($payload, $this->secretKey, 'HS256');
    }

    /**
     * 验证 Token
     */
    public function verifyToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 从请求头获取用户ID
     */
    public function getUserIdFromRequest(): ?int
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return null;
        }

        $token = $matches[1];
        $payload = $this->verifyToken($token);

        return $payload['uid'] ?? null;
    }
}
```

## 5.3 用户相关接口

### 5.3.1 用户注册

```http
POST /api/users/register
Content-Type: application/json

{
  "username": "newuser",
  "email": "newuser@example.com",
  "password": "password123"
}
```

响应：
```json
{
  "success": true,
  "data": {
    "user_id": 124,
    "username": "newuser",
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
  },
  "message": "注册成功"
}
```

### 5.3.2 用户登录

```http
POST /api/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password123"
}
```

### 5.3.3 获取用户信息

```http
GET /api/users/{user_id}
Authorization: Bearer {token}
```

响应：
```json
{
  "success": true,
  "data": {
    "id": 123,
    "username": "testuser",
    "avatar": "https://example.com/avatar.jpg",
    "group_id": 1,
    "credits": 1000,
    "threads": 50,
    "posts": 200,
    "created_at": 1708617600
  }
}
```

### 5.3.4 更新用户信息

```http
PUT /api/users/{user_id}
Authorization: Bearer {token}
Content-Type: application/json

{
  "avatar": "https://example.com/new-avatar.jpg",
  "signature": "这是我的个性签名"
}
```

## 5.4 板块相关接口

### 5.4.1 获取板块列表

```http
GET /api/forums
```

响应：
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "技术讨论",
      "description": "技术相关话题",
      "threads": 1000,
      "posts": 5000,
      "children": [
        {
          "id": 2,
          "name": "PHP",
          "threads": 500,
          "posts": 2500
        }
      ]
    }
  ]
}
```

### 5.4.2 获取板块详情

```http
GET /api/forums/{forum_id}
```

### 5.4.3 创建板块（管理员）

```http
POST /api/forums
Authorization: Bearer {token}
Content-Type: application/json

{
  "parent_id": 0,
  "name": "新板块",
  "description": "板块描述",
  "rank": 10
}
```

## 5.5 帖子相关接口

### 5.5.1 获取帖子列表

```http
GET /api/threads?forum_id=1&page=1&page_size=20&sort=latest
```

查询参数：
- `forum_id`: 板块ID（可选）
- `page`: 页码（默认1）
- `page_size`: 每页数量（默认20，最大100）
- `sort`: 排序方式（latest/hot/replies）

响应：
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "forum_id": 1,
      "title": "示例帖子",
      "user": {
        "id": 123,
        "username": "testuser",
        "avatar": "https://example.com/avatar.jpg"
      },
      "views": 1000,
      "posts": 50,
      "is_top": false,
      "is_elite": false,
      "last_post_time": 1708617600,
      "created_at": 1708617000
    }
  ],
  "pagination": {
    "total": 100,
    "page": 1,
    "page_size": 20,
    "total_pages": 5
  }
}
```

### 5.5.2 获取帖子详情

```http
GET /api/threads/{thread_id}
```

响应：
```json
{
  "success": true,
  "data": {
    "thread": {
      "id": 1,
      "forum_id": 1,
      "title": "示例帖子",
      "user": {
        "id": 123,
        "username": "testuser",
        "avatar": "https://example.com/avatar.jpg"
      },
      "views": 1000,
      "posts": 50,
      "is_top": false,
      "is_elite": false,
      "created_at": 1708617000
    },
    "posts": [
      {
        "id": 1,
        "thread_id": 1,
        "user": {
          "id": 123,
          "username": "testuser",
          "avatar": "https://example.com/avatar.jpg"
        },
        "content": "这是帖子内容",
        "content_format": "markdown",
        "floor": 1,
        "likes": 10,
        "created_at": 1708617000
      }
    ]
  }
}
```

### 5.5.3 创建帖子

```http
POST /api/threads
Authorization: Bearer {token}
Content-Type: application/json

{
  "forum_id": 1,
  "title": "新帖子标题",
  "content": "帖子内容",
  "content_format": "markdown",
  "tags": ["PHP", "性能优化"]
}
```

响应：
```json
{
  "success": true,
  "data": {
    "thread_id": 101,
    "title": "新帖子标题"
  },
  "message": "发帖成功"
}
```

### 5.5.4 更新帖子

```http
PUT /api/threads/{thread_id}
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "修改后的标题",
  "content": "修改后的内容"
}
```

### 5.5.5 删除帖子

```http
DELETE /api/threads/{thread_id}
Authorization: Bearer {token}
```

### 5.5.6 置顶/加精帖子（版主/管理员）

```http
POST /api/threads/{thread_id}/actions
Authorization: Bearer {token}
Content-Type: application/json

{
  "action": "top",  // top/untop/elite/unelite/lock/unlock
  "value": true
}
```

## 5.6 回复相关接口

### 5.6.1 获取回复列表

```http
GET /api/threads/{thread_id}/posts?page=1&page_size=20
```

### 5.6.2 创建回复

```http
POST /api/threads/{thread_id}/posts
Authorization: Bearer {token}
Content-Type: application/json

{
  "content": "回复内容",
  "content_format": "markdown",
  "quote_post_id": 5  // 可选：引用的回复ID
}
```

响应：
```json
{
  "success": true,
  "data": {
    "post_id": 201,
    "floor": 51
  },
  "message": "回复成功"
}
```

### 5.6.3 更新回复

```http
PUT /api/posts/{post_id}
Authorization: Bearer {token}
Content-Type: application/json

{
  "content": "修改后的回复内容"
}
```

### 5.6.4 删除回复

```http
DELETE /api/posts/{post_id}
Authorization: Bearer {token}
```

### 5.6.5 点赞回复

```http
POST /api/posts/{post_id}/like
Authorization: Bearer {token}
```

响应：
```json
{
  "success": true,
  "data": {
    "likes": 11
  },
  "message": "点赞成功"
}
```

## 5.7 搜索接口

### 5.7.1 全文搜索

```http
GET /api/search?q=关键词&type=thread&page=1&page_size=20
```

查询参数：
- `q`: 搜索关键词
- `type`: 搜索类型（thread/post/user）
- `forum_id`: 限定板块（可选）
- `page`: 页码
- `page_size`: 每页数量

响应：
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "包含关键词的帖子",
      "excerpt": "...关键词...",
      "user": {
        "id": 123,
        "username": "testuser"
      },
      "created_at": 1708617000
    }
  ],
  "pagination": {
    "total": 50,
    "page": 1,
    "page_size": 20,
    "total_pages": 3
  }
}
```

### 5.7.2 标签搜索

```http
GET /api/tags/{tag_name}/threads?page=1
```

## 5.8 通知接口

### 5.8.1 获取通知列表

```http
GET /api/notifications?is_read=0&page=1&page_size=20
Authorization: Bearer {token}
```

响应：
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "type": "reply",
      "content": "testuser 回复了你的帖子",
      "from_user": {
        "id": 124,
        "username": "testuser",
        "avatar": "https://example.com/avatar.jpg"
      },
      "related_id": 101,
      "is_read": false,
      "created_at": 1708617000
    }
  ],
  "unread_count": 5
}
```

### 5.8.2 标记通知已读

```http
PUT /api/notifications/{notification_id}/read
Authorization: Bearer {token}
```

### 5.8.3 批量标记已读

```http
PUT /api/notifications/read-all
Authorization: Bearer {token}
```

## 5.9 文件上传接口

### 5.9.1 上传图片

```http
POST /api/upload/image
Authorization: Bearer {token}
Content-Type: multipart/form-data

file: [binary data]
```

响应：
```json
{
  "success": true,
  "data": {
    "url": "https://example.com/uploads/2026/02/image.jpg",
    "width": 1920,
    "height": 1080,
    "size": 524288
  }
}
```

### 5.9.2 上传附件

```http
POST /api/upload/attachment
Authorization: Bearer {token}
Content-Type: multipart/form-data

file: [binary data]
```

## 5.10 WebSocket 实时通知

### 5.10.1 连接建立

```javascript
const ws = new WebSocket('ws://amubbs.com/ws?token=' + userToken);

ws.onopen = function() {
    console.log('WebSocket 连接已建立');
};

ws.onmessage = function(event) {
    const data = JSON.parse(event.data);
    handleNotification(data);
};

ws.onerror = function(error) {
    console.error('WebSocket 错误:', error);
};

ws.onclose = function() {
    console.log('WebSocket 连接已关闭');
    // 重连逻辑
    setTimeout(() => reconnect(), 3000);
};
```

### 5.10.2 消息格式

#### 新回复通知
```json
{
  "type": "reply",
  "data": {
    "thread_id": 101,
    "post_id": 201,
    "user": {
      "id": 124,
      "username": "testuser"
    },
    "content": "回复内容摘要..."
  },
  "timestamp": 1708617000
}
```

#### @提醒通知
```json
{
  "type": "mention",
  "data": {
    "thread_id": 101,
    "post_id": 201,
    "user": {
      "id": 124,
      "username": "testuser"
    },
    "content": "@你 的内容..."
  },
  "timestamp": 1708617000
}
```

#### 私信通知
```json
{
  "type": "message",
  "data": {
    "message_id": 301,
    "from_user": {
      "id": 124,
      "username": "testuser"
    },
    "content": "私信内容..."
  },
  "timestamp": 1708617000
}
```

## 5.11 限流策略

### 5.11.1 限流规则

| 操作 | 限制 | 时间窗口 |
|------|------|----------|
| 登录 | 5次 | 5分钟 |
| 注册 | 3次 | 1小时 |
| 发帖 | 10次 | 1小时 |
| 回复 | 30次 | 1小时 |
| 搜索 | 60次 | 1分钟 |
| 上传 | 20次 | 1小时 |

### 5.11.2 限流实现

```php
<?php
// app/Middlewares/RateLimiter.php

namespace App\Middlewares;

use Core\Cache;

class RateLimiter
{
    /**
     * 检查限流
     */
    public function check(string $action, int $userId, int $limit, int $window): bool
    {
        $key = "ratelimit:{$action}:{$userId}";
        $count = Cache::getRedis()->incr($key);

        if ($count === 1) {
            Cache::getRedis()->expire($key, $window);
        }

        if ($count > $limit) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'RATE_LIMIT_EXCEEDED',
                    'message' => '操作过于频繁，请稍后再试',
                ],
            ]);
            exit;
        }

        return true;
    }
}
```

## 5.12 错误码定义

| 错误码 | 说明 |
|--------|------|
| `INVALID_PARAMS` | 参数错误 |
| `UNAUTHORIZED` | 未认证 |
| `FORBIDDEN` | 无权限 |
| `USER_NOT_FOUND` | 用户不存在 |
| `THREAD_NOT_FOUND` | 帖子不存在 |
| `POST_NOT_FOUND` | 回复不存在 |
| `FORUM_NOT_FOUND` | 板块不存在 |
| `DUPLICATE_USERNAME` | 用户名已存在 |
| `DUPLICATE_EMAIL` | 邮箱已存在 |
| `INVALID_CREDENTIALS` | 用户名或密码错误 |
| `RATE_LIMIT_EXCEEDED` | 请求过于频繁 |
| `FLOOD_CONTROL` | 发帖/回复太频繁 |
| `SENSITIVE_WORD` | 包含敏感词 |
| `FILE_TOO_LARGE` | 文件过大 |
| `INVALID_FILE_TYPE` | 文件类型不支持 |
| `SERVER_ERROR` | 服务器错误 |

---

**文档版本**: v1.0
**创建日期**: 2026-02-22
