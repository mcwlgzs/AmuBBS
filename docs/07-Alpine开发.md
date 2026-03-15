# Alpine.js 开发指南

## 7.1 Alpine.js 简介

Alpine.js 是一个轻量级的 JavaScript 框架，仅 15KB，提供类似 Vue 的响应式和声明式语法，但无需构建步骤。

### 7.1.1 核心特性

- **轻量级**：压缩后仅 15KB
- **无需构建**：直接通过 CDN 引入
- **类 Vue 语法**：熟悉 Vue 的开发者可快速上手
- **响应式**：自动追踪数据变化并更新 DOM
- **易于集成**：可与服务端渲染完美配合

### 7.1.2 引入方式

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>AMuBBS</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <!-- 页面内容 -->

    <!-- Alpine.js CDN -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>

    <!-- 自定义 JS -->
    <script src="/assets/js/app.js"></script>
</body>
</html>
```

## 7.2 基础语法

### 7.2.1 数据绑定 (x-data)

```html
<!-- 简单计数器 -->
<div x-data="{ count: 0 }">
    <button @click="count++">增加</button>
    <span x-text="count"></span>
</div>

<!-- 帖子点赞 -->
<div x-data="{ likes: 10, liked: false }">
    <button
        @click="liked = !liked; likes += liked ? 1 : -1"
        :class="liked ? 'text-red-500' : 'text-gray-500'">
        ❤️ <span x-text="likes"></span>
    </button>
</div>
```

### 7.2.2 条件渲染 (x-show / x-if)

```html
<!-- x-show: 切换 display 属性 -->
<div x-data="{ open: false }">
    <button @click="open = !open">切换</button>
    <div x-show="open">
        这是可以显示/隐藏的内容
    </div>
</div>

<!-- x-if: 完全移除/添加 DOM 元素 -->
<template x-if="user.isLoggedIn">
    <div>欢迎回来，<span x-text="user.name"></span></div>
</template>
```

### 7.2.3 列表渲染 (x-for)

```html
<!-- 帖子列表 -->
<div x-data="{
    threads: [
        { id: 1, title: '第一个帖子', views: 100 },
        { id: 2, title: '第二个帖子', views: 200 }
    ]
}">
    <template x-for="thread in threads" :key="thread.id">
        <div class="thread-item">
            <h3 x-text="thread.title"></h3>
            <span x-text="thread.views + ' 次浏览'"></span>
        </div>
    </template>
</div>
```

### 7.2.4 事件处理 (@click / @submit)

```html
<!-- 表单提交 -->
<form x-data="{ email: '', password: '' }" @submit.prevent="login">
    <input type="email" x-model="email" placeholder="邮箱">
    <input type="password" x-model="password" placeholder="密码">
    <button type="submit">登录</button>
</form>

<script>
function login(event) {
    const formData = new FormData(event.target);
    // 发送登录请求
}
</script>
```

### 7.2.5 双向绑定 (x-model)

```html
<!-- 搜索框 -->
<div x-data="{ search: '' }">
    <input type="text" x-model="search" placeholder="搜索帖子">
    <p>你正在搜索：<span x-text="search"></span></p>
</div>

<!-- 复选框 -->
<div x-data="{ agreed: false }">
    <label>
        <input type="checkbox" x-model="agreed">
        我同意用户协议
    </label>
    <button :disabled="!agreed">注册</button>
</div>
```

## 7.3 实战示例

### 7.3.1 帖子列表组件

```html
<div x-data="threadList()" x-init="loadThreads()">
    <!-- 加载状态 -->
    <div x-show="loading" class="loading">
        加载中...
    </div>

    <!-- 帖子列表 -->
    <div x-show="!loading">
        <template x-for="thread in threads" :key="thread.id">
            <div class="thread-card">
                <a :href="'/thread/' + thread.id" x-text="thread.title"></a>
                <div class="thread-meta">
                    <span x-text="thread.user.username"></span>
                    <span x-text="thread.views + ' 浏览'"></span>
                    <span x-text="thread.posts + ' 回复'"></span>
                </div>
            </div>
        </template>
    </div>

    <!-- 分页 -->
    <div class="pagination">
        <button @click="prevPage" :disabled="page === 1">上一页</button>
        <span x-text="'第 ' + page + ' 页'"></span>
        <button @click="nextPage" :disabled="page >= totalPages">下一页</button>
    </div>
</div>

<script>
function threadList() {
    return {
        threads: [],
        loading: false,
        page: 1,
        totalPages: 1,

        async loadThreads() {
            this.loading = true;
            try {
                const response = await fetch(`/api/threads?page=${this.page}`);
                const data = await response.json();
                this.threads = data.data;
                this.totalPages = data.pagination.total_pages;
            } catch (error) {
                console.error('加载失败:', error);
            } finally {
                this.loading = false;
            }
        },

        prevPage() {
            if (this.page > 1) {
                this.page--;
                this.loadThreads();
            }
        },

        nextPage() {
            if (this.page < this.totalPages) {
                this.page++;
                this.loadThreads();
            }
        }
    }
}
</script>
```

### 7.3.2 回复编辑器

```html
<div x-data="postEditor()" class="post-editor">
    <!-- 编辑器工具栏 -->
    <div class="toolbar">
        <button @click="insertBold">粗体</button>
        <button @click="insertItalic">斜体</button>
        <button @click="insertCode">代码</button>
        <button @click="insertImage">图片</button>
    </div>

    <!-- 文本区域 -->
    <textarea
        x-model="content"
        x-ref="textarea"
        placeholder="写下你的回复..."
        rows="6">
    </textarea>

    <!-- 预览 -->
    <div x-show="showPreview" class="preview">
        <div x-html="renderedContent"></div>
    </div>

    <!-- 操作按钮 -->
    <div class="actions">
        <button @click="showPreview = !showPreview">
            <span x-text="showPreview ? '编辑' : '预览'"></span>
        </button>
        <button @click="submit" :disabled="!content.trim()">
            发布回复
        </button>
    </div>
</div>

<script>
function postEditor() {
    return {
        content: '',
        showPreview: false,

        get renderedContent() {
            // 简单的 Markdown 渲染（实际项目中使用 marked.js）
            return this.content
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                .replace(/`(.*?)`/g, '<code>$1</code>');
        },

        insertBold() {
            this.insertText('**', '**');
        },

        insertItalic() {
            this.insertText('*', '*');
        },

        insertCode() {
            this.insertText('`', '`');
        },

        insertImage() {
            this.insertText('![图片描述](', ')');
        },

        insertText(before, after) {
            const textarea = this.$refs.textarea;
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selectedText = this.content.substring(start, end);

            this.content =
                this.content.substring(0, start) +
                before + selectedText + after +
                this.content.substring(end);

            // 恢复光标位置
            this.$nextTick(() => {
                textarea.focus();
                textarea.setSelectionRange(
                    start + before.length,
                    start + before.length + selectedText.length
                );
            });
        },

        async submit() {
            if (!this.content.trim()) return;

            try {
                const response = await fetch('/api/posts', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    },
                    body: JSON.stringify({
                        thread_id: window.threadId,
                        content: this.content
                    })
                });

                if (response.ok) {
                    this.content = '';
                    window.location.reload(); // 刷新页面显示新回复
                }
            } catch (error) {
                alert('发布失败，请重试');
            }
        }
    }
}
</script>
```

### 7.3.3 实时通知

```html
<div x-data="notification()" x-init="connect()">
    <!-- 通知图标 -->
    <div class="notification-icon" @click="togglePanel">
        <span>🔔</span>
        <span x-show="unreadCount > 0"
              x-text="unreadCount"
              class="badge"></span>
    </div>

    <!-- 通知面板 -->
    <div x-show="showPanel"
         @click.away="showPanel = false"
         class="notification-panel">

        <div class="panel-header">
            <h3>通知</h3>
            <button @click="markAllRead" x-show="unreadCount > 0">
                全部已读
            </button>
        </div>

        <div class="notification-list">
            <template x-for="item in notifications" :key="item.id">
                <div class="notification-item"
                     :class="{ 'unread': !item.is_read }"
                     @click="markRead(item.id)">
                    <img :src="item.from_user.avatar" class="avatar">
                    <div class="content">
                        <p x-text="item.content"></p>
                        <span x-text="formatTime(item.created_at)"></span>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function notification() {
    return {
        notifications: [],
        unreadCount: 0,
        showPanel: false,
        ws: null,

        connect() {
            // 加载初始通知
            this.loadNotifications();

            // 建立 WebSocket 连接
            const token = localStorage.getItem('token');
            this.ws = new WebSocket(`ws://amubbs.com/ws?token=${token}`);

            this.ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                this.notifications.unshift(data);
                this.unreadCount++;

                // 显示浏览器通知
                if (Notification.permission === 'granted') {
                    new Notification('新通知', {
                        body: data.content,
                        icon: data.from_user.avatar
                    });
                }
            };
        },

        async loadNotifications() {
            const response = await fetch('/api/notifications', {
                headers: {
                    'Authorization': 'Bearer ' + localStorage.getItem('token')
                }
            });
            const data = await response.json();
            this.notifications = data.data;
            this.unreadCount = data.unread_count;
        },

        togglePanel() {
            this.showPanel = !this.showPanel;
        },

        async markRead(id) {
            await fetch(`/api/notifications/${id}/read`, {
                method: 'PUT',
                headers: {
                    'Authorization': 'Bearer ' + localStorage.getItem('token')
                }
            });

            const item = this.notifications.find(n => n.id === id);
            if (item && !item.is_read) {
                item.is_read = true;
                this.unreadCount--;
            }
        },

        async markAllRead() {
            await fetch('/api/notifications/read-all', {
                method: 'PUT',
                headers: {
                    'Authorization': 'Bearer ' + localStorage.getItem('token')
                }
            });

            this.notifications.forEach(n => n.is_read = true);
            this.unreadCount = 0;
        },

        formatTime(timestamp) {
            const date = new Date(timestamp * 1000);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000);

            if (diff < 60) return '刚刚';
            if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
            if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前';
            return Math.floor(diff / 86400) + ' 天前';
        }
    }
}
</script>
```

### 7.3.4 搜索功能

```html
<div x-data="search()" class="search-box">
    <!-- 搜索输入框 -->
    <input
        type="text"
        x-model="query"
        @input.debounce.300ms="performSearch"
        placeholder="搜索帖子..."
        @focus="showResults = true">

    <!-- 搜索结果下拉 -->
    <div x-show="showResults && results.length > 0"
         @click.away="showResults = false"
         class="search-results">

        <template x-for="result in results" :key="result.id">
            <a :href="'/thread/' + result.id" class="result-item">
                <h4 x-text="highlightQuery(result.title)"></h4>
                <p x-text="result.excerpt"></p>
            </a>
        </template>

        <a href="#" @click.prevent="searchAll" class="view-all">
            查看全部结果
        </a>
    </div>
</div>

<script>
function search() {
    return {
        query: '',
        results: [],
        showResults: false,

        async performSearch() {
            if (this.query.length < 2) {
                this.results = [];
                return;
            }

            try {
                const response = await fetch(
                    `/api/search?q=${encodeURIComponent(this.query)}&limit=5`
                );
                const data = await response.json();
                this.results = data.data;
                this.showResults = true;
            } catch (error) {
                console.error('搜索失败:', error);
            }
        },

        highlightQuery(text) {
            if (!this.query) return text;
            const regex = new RegExp(`(${this.query})`, 'gi');
            return text.replace(regex, '<mark>$1</mark>');
        },

        searchAll() {
            window.location.href = `/search?q=${encodeURIComponent(this.query)}`;
        }
    }
}
</script>
```

## 7.4 Alpine.js 插件

### 7.4.1 Alpine Intersect（懒加载）

```html
<!-- 图片懒加载 -->
<img
    x-data
    x-intersect="$el.src = $el.dataset.src"
    data-src="/uploads/image.jpg"
    alt="图片">

<!-- 无限滚动 -->
<div x-data="infiniteScroll()" x-init="loadMore()">
    <template x-for="item in items" :key="item.id">
        <div x-text="item.title"></div>
    </template>

    <div x-intersect="loadMore" class="loading-trigger"></div>
</div>
```

### 7.4.2 Alpine Mask（输入格式化）

```html
<script src="https://cdn.jsdelivr.net/npm/@alpinejs/mask@3.x.x/dist/cdn.min.js"></script>

<!-- 手机号格式化 -->
<input type="text" x-mask="999-9999-9999" placeholder="手机号">

<!-- 日期格式化 -->
<input type="text" x-mask="9999-99-99" placeholder="日期 (YYYY-MM-DD)">
```

## 7.5 性能优化

### 7.5.1 使用 x-cloak 避免闪烁

```html
<style>
[x-cloak] { display: none !important; }
</style>

<div x-data="{ loaded: false }" x-init="loaded = true" x-cloak>
    <!-- 内容在 Alpine 初始化后才显示 -->
</div>
```

### 7.5.2 延迟加载组件

```html
<!-- 只在需要时加载 -->
<div x-data="{ show: false }">
    <button @click="show = true">显示编辑器</button>

    <template x-if="show">
        <div x-data="postEditor()">
            <!-- 编辑器组件 -->
        </div>
    </template>
</div>
```

### 7.5.3 防抖和节流

```html
<!-- 防抖：搜索输入 -->
<input @input.debounce.500ms="search">

<!-- 节流：滚动事件 -->
<div @scroll.throttle.200ms="handleScroll">
```

## 7.6 与后端集成

### 7.6.1 API 请求封装

```javascript
// assets/js/utils/http.js

const http = {
    baseURL: '/api',

    getToken() {
        return localStorage.getItem('token');
    },

    async request(url, options = {}) {
        const headers = {
            'Content-Type': 'application/json',
            ...options.headers
        };

        const token = this.getToken();
        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }

        const response = await fetch(this.baseURL + url, {
            ...options,
            headers
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return response.json();
    },

    get(url, params = {}) {
        const query = new URLSearchParams(params).toString();
        return this.request(url + (query ? '?' + query : ''));
    },

    post(url, data) {
        return this.request(url, {
            method: 'POST',
            body: JSON.stringify(data)
        });
    },

    put(url, data) {
        return this.request(url, {
            method: 'PUT',
            body: JSON.stringify(data)
        });
    },

    delete(url) {
        return this.request(url, {
            method: 'DELETE'
        });
    }
};
```

### 7.6.2 全局状态管理

```javascript
// assets/js/store.js

const store = {
    user: Alpine.reactive({
        id: 0,
        username: '',
        avatar: '',
        isLoggedIn: false,

        async load() {
            const token = localStorage.getItem('token');
            if (!token) return;

            try {
                const data = await http.get('/user/me');
                Object.assign(this, data.data, { isLoggedIn: true });
            } catch (error) {
                this.logout();
            }
        },

        logout() {
            localStorage.removeItem('token');
            this.isLoggedIn = false;
            window.location.href = '/';
        }
    })
};

// 初始化
document.addEventListener('alpine:init', () => {
    Alpine.store('user', store.user);
    store.user.load();
});
```

使用全局状态：

```html
<div x-data>
    <template x-if="$store.user.isLoggedIn">
        <div>
            欢迎，<span x-text="$store.user.username"></span>
            <button @click="$store.user.logout()">退出</button>
        </div>
    </template>
</div>
```

---

**文档版本**: v1.0
**创建日期**: 2026-02-22
