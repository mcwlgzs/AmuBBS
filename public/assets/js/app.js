/**
 * AMuBBS - 前端全局脚本
 * 整合自 header.php / footer.php 内联脚本 + 全局组件
 */

// 过滤浏览器扩展引起的错误
window.addEventListener('error', function(e) {
    if (e.message && e.message.includes('message channel closed')) {
        e.preventDefault();
        return true;
    }
});

window.addEventListener('unhandledrejection', function(e) {
    if (e.reason && e.reason.message && e.reason.message.includes('message channel closed')) {
        e.preventDefault();
        return true;
    }
});

// ========== 已读/未读标记 ==========
window.ThreadRead = {
    KEY: 'amubbs_read_threads',
    MAX: 500,
    _cache: null,
    _load() {
        if (this._cache) return this._cache;
        try { this._cache = JSON.parse(localStorage.getItem(this.KEY)) || {}; } catch(e) { this._cache = {}; }
        return this._cache;
    },
    isRead(tid, lastPostTime) {
        var data = this._load();
        var readAt = data[tid];
        if (!readAt) return false;
        return !lastPostTime || readAt >= lastPostTime;
    },
    markRead(tid) {
        var data = this._load();
        data[tid] = Math.floor(Date.now() / 1000);
        // 超过上限时清理最旧的
        var keys = Object.keys(data);
        if (keys.length > this.MAX) {
            keys.sort(function(a,b){ return data[a] - data[b]; });
            for (var i = 0; i < keys.length - this.MAX; i++) delete data[keys[i]];
        }
        this._cache = data;
        try { localStorage.setItem(this.KEY, JSON.stringify(data)); } catch(e) {}
    },
    applyToList() {
        document.querySelectorAll('.thread-list-title[data-tid]').forEach(function(el) {
            var tid = el.getAttribute('data-tid');
            var lpt = parseInt(el.getAttribute('data-lpt')) || 0;
            if (ThreadRead.isRead(tid, lpt)) {
                el.classList.add('thread-read');
            } else {
                el.classList.add('thread-unread');
            }
        });
    }
};
// 帖子详情页自动标记已读
(function(){
    var m = window.location.pathname.match(/^\/thread\/(\d+)/);
    if (m) ThreadRead.markRead(m[1]);
    // 列表页应用已读样式
    document.addEventListener('DOMContentLoaded', function(){ ThreadRead.applyToList(); });
})();

// ========== Toast 工具 ==========
window.toast = function(msg, type) {
    var bg = type === 'error' ? '#ef4444' : type === 'success' ? '#22c55e' : '#3b82f6';
    if (typeof Toastify === 'function') {
        Toastify({
            text: msg, duration: 3000, gravity: 'top', position: 'center',
            style: { background: bg, borderRadius: '8px', fontSize: '14px', padding: '10px 20px' }
        }).showToast();
    } else {
        var el = document.createElement('div');
        el.textContent = msg;
        el.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);background:' + bg + ';color:#fff;padding:10px 20px;border-radius:8px;font-size:14px;z-index:99999;transition:opacity .3s';
        document.body.appendChild(el);
        setTimeout(function() { el.style.opacity = '0'; setTimeout(function() { el.remove(); }, 300); }, 3000);
    }
};

// ========== 全局 API 请求工具 ==========
window.apiPost = async function(url, body, opts) {
    opts = opts || {};
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var headers = Object.assign({
        'X-CSRF-TOKEN': csrf ? csrf.content : '',
        'X-Requested-With': 'XMLHttpRequest'
    }, opts.headers || {});
    var fetchOpts = { method: opts.method || 'POST', headers: headers };
    if (body instanceof FormData) {
        fetchOpts.body = body;
    } else if (typeof body === 'object') {
        headers['Content-Type'] = 'application/json';
        fetchOpts.headers = headers;
        fetchOpts.body = JSON.stringify(body);
    } else if (typeof body === 'string') {
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        fetchOpts.headers = headers;
        fetchOpts.body = body;
    }
    try {
        var resp = await fetch(url, fetchOpts);
        var data = await resp.json();
        if (opts.silent) return data;
        if (data.success) { toast(data.message || '操作成功', 'success'); }
        else { toast(data.message || '操作失败', 'error'); }
        return data;
    } catch(e) {
        if (!opts.silent) toast('网络错误', 'error');
        return { success: false, message: '网络错误' };
    }
};

// ========== 时间格式化工具 ==========
window.formatTime = function(timestamp) {
    var diff = Math.floor(Date.now() / 1000) - parseInt(timestamp);
    if (diff < 60) return '刚刚';
    if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
    if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
    if (diff < 2592000) return Math.floor(diff / 86400) + '天前';
    return new Date(timestamp * 1000).toLocaleDateString('zh-CN');
};

// ========== 通知面板 Alpine 组件 ==========
window.notifPanel = function() {
    return {
        open: false, loading: false, items: [],
        toggle() {
            this.open = !this.open;
            if (this.open) this.load();
        },
        load() {
            this.loading = true;
            fetch('/notifications/unread-count?detail=1')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    this.items = data.items || [];
                    this.updateBadge(data.count || 0);
                    this.loading = false;
                }.bind(this))
                .catch(function() { this.loading = false; }.bind(this));
        },
        readAll() {
            fetch('/notifications/read-all', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || '', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function() {
                this.items = [];
                this.updateBadge(0);
                toast('已全部标记为已读', 'success');
            }.bind(this));
        },
        updateBadge: function(count) {
            var badge = this.$refs.badge;
            if (!badge) return;
            if (count > 0) { badge.textContent = count > 99 ? '99+' : count; badge.style.display = ''; }
            else { badge.style.display = 'none'; }
            document.title = count > 0
                ? '(' + count + ') ' + document.title.replace(/^\(\d+\)\s*/, '')
                : document.title.replace(/^\(\d+\)\s*/, '');
        },
        getLink: function(item) {
            if (item.target_type === 'thread' && item.target_id) return '/thread/' + item.target_id;
            if (item.target_type === 'message') return '/messages';
            return '/notifications';
        },
        timeAgo: function(ts) { return formatTime(ts); }
    };
};

// ========== 通知轮询 ==========
(function() {
    if (typeof window._initUnreadCount === 'undefined') return;
    window._lastNotifCount = window._initUnreadCount;
    setInterval(function() {
        if (document.visibilityState === 'hidden') return;
        fetch('/notifications/unread-count').then(function(r) { return r.json(); }).then(function(data) {
            var count = data.count || 0;
            document.querySelectorAll('.notif-dropdown .badge').forEach(function(el) {
                if (count > 0) { el.textContent = count > 99 ? '99+' : count; el.style.display = ''; }
                else { el.style.display = 'none'; }
            });
            if (count > (window._lastNotifCount || 0) && count > 0) {
                toast('你有 ' + count + ' 条未读通知', 'info');
            }
            window._lastNotifCount = count;
        }).catch(function(){});
    }, 30000);
})();

// ========== LazyLoad ==========
if (typeof LazyLoad !== 'undefined') {
    new LazyLoad({ elements_selector: '.lazy' });
}

// ========== Textarea 自动增高 ==========
if (typeof autosize !== 'undefined') {
    autosize(document.querySelectorAll('textarea'));
}

// ========== 相对时间格式化 (timeago) ==========
(function() {
    if (!window.timeago) return;
    timeago.register('zh_CN', function(number, index) {
        return [
            ['刚刚','片刻后'],['%s秒前','%s秒后'],['1分钟前','1分钟后'],['%s分钟前','%s分钟后'],
            ['1小时前','1小时后'],['%s小时前','%s小时后'],['1天前','1天后'],['%s天前','%s天后'],
            ['1周前','1周后'],['%s周前','%s周后'],['1个月前','1个月后'],['%s个月前','%s个月后'],
            ['1年前','1年后'],['%s年前','%s年后']
        ][index];
    });
    document.querySelectorAll('.timeago').forEach(function(el) {
        var dt = el.getAttribute('datetime');
        if (dt) el.textContent = timeago.format(dt, 'zh_CN');
    });
})();

// ========== 按需加载：代码高亮 + 复制 + 图片缩放 ==========
(function() {
    var hasContent = document.querySelector('.markdown-body, .thread-content, .post-content, .moment-img');
    if (!hasContent) return;
    var s1 = document.createElement('script');
    s1.src = '/assets/js/highlight.min.js';
    s1.onload = function() {
        hljs.highlightAll();
        var s2 = document.createElement('script');
        s2.src = '/assets/js/clipboard.min.js';
        s2.onload = function() {
            document.querySelectorAll('.markdown-body pre, .thread-content pre, .post-content pre').forEach(function(pre) {
                var btn = document.createElement('button');
                btn.className = 'code-copy-btn';
                btn.textContent = '复制';
                btn.setAttribute('data-clipboard-text', pre.querySelector('code') ? pre.querySelector('code').textContent : pre.textContent);
                pre.style.position = 'relative';
                pre.appendChild(btn);
            });
            var clip = new ClipboardJS('.code-copy-btn');
            clip.on('success', function(e) { e.trigger.textContent = '已复制'; setTimeout(function(){ e.trigger.textContent = '复制'; }, 1500); e.clearSelection(); });
            clip.on('error', function(e) { e.trigger.textContent = '失败'; setTimeout(function(){ e.trigger.textContent = '复制'; }, 1500); });
        };
        document.body.appendChild(s2);
    };
    document.body.appendChild(s1);
    var s3 = document.createElement('script');
    s3.src = '/assets/js/medium-zoom.min.js';
    s3.onload = function() {
        mediumZoom('.markdown-body img, .thread-content img, .post-content img, .moment-img', { margin: 24, background: 'rgba(0,0,0,.85)' });
    };
    document.body.appendChild(s3);
})();

// ========== 暗色模式切换 ==========
window.darkMode = function() {
    return {
        dark: localStorage.getItem('theme') === 'dark',
        init() {
            this.apply();
        },
        toggle() {
            this.dark = !this.dark;
            localStorage.setItem('theme', this.dark ? 'dark' : 'light');
            this.apply();
        },
        apply() {
            document.documentElement.classList.toggle('dark', this.dark);
        }
    };
};
// 页面加载前立即应用暗色模式，避免闪烁
(function() {
    if (localStorage.getItem('theme') === 'dark') {
        document.documentElement.classList.add('dark');
    }
})();

// ========== 返回顶部 ==========
window.backToTop = function() {
    return {
        visible: false,
        init() {
            var self = this;
            window.addEventListener('scroll', function() {
                self.visible = window.scrollY > 300;
            }, { passive: true });
        },
        scrollTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };
};

// ========== 阅读进度条 ==========
(function() {
    var bar = document.getElementById('readingProgress');
    if (!bar) return;
    // 仅在有长内容的页面显示
    var content = document.querySelector('.thread-content, .post-content');
    if (!content) { bar.style.display = 'none'; return; }
    window.addEventListener('scroll', function() {
        var scrollTop = window.scrollY;
        var docHeight = document.documentElement.scrollHeight - window.innerHeight;
        if (docHeight > 0) {
            bar.style.width = Math.min(100, (scrollTop / docHeight) * 100) + '%';
        }
    }, { passive: true });
})();
