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

// ========== htmx 集成 ==========
// 后端变更操作通过 HX-Trigger: {"frontFlash":{...}} 回传提示，这里统一弹 toast，
// 各页面视图不用自己处理提示逻辑（与后台 adminFlash 是同一套约定）。
(function () {
    // 后端沿用语义化类型名，前端 toast() 只认 success/error/info
    function normalizeType(t) {
        if (t === 'danger' || t === 'error') return 'error';
        if (t === 'success') return 'success';
        return 'info';
    }

    // 「改完就整页重载」的响应（HX-Refresh）里，提示是在重载前一瞬间触发的，用户根本看不到。
    // htmx 的处理顺序是：htmx:beforeOnLoad → 处理 HX-Trigger（本文件的 frontFlash 监听）
    // → 执行 HX-Refresh。所以在 beforeOnLoad 里先看响应头，就知道这次提示该不该延后。
    var FLASH_KEY = 'amubbs_pending_flash';
    var refreshing = false;

    document.addEventListener('htmx:beforeOnLoad', function (e) {
        var xhr = e.detail && e.detail.xhr;
        refreshing = !!(xhr && xhr.getResponseHeader && xhr.getResponseHeader('HX-Refresh') === 'true');
    });

    document.addEventListener('frontFlash', function (e) {
        var d = e.detail || {};
        // 兼容 htmx 对 detail 的两种包装：直接是对象，或 { value: {...} }
        if (d.value && typeof d.value === 'object') d = d.value;
        if (!d.message) return;

        if (refreshing) {
            try { sessionStorage.setItem(FLASH_KEY, JSON.stringify({ message: d.message, type: d.type })); } catch (err) {}
            return;
        }
        toast(d.message, normalizeType(d.type));
    });

    // 重载完成后补弹一次（读过就删，只弹一次）
    try {
        var pending = JSON.parse(sessionStorage.getItem(FLASH_KEY) || 'null');
        sessionStorage.removeItem(FLASH_KEY);
        if (pending && pending.message) {
            setTimeout(function () { toast(pending.message, normalizeType(pending.type)); }, 0);
        }
    } catch (err) {}

    // 片段换进来之后，重新初始化依赖 DOM 的增强（文本域自适应、相对时间、懒加载、已读样式）
    document.addEventListener('htmx:afterSwap', function (e) {
        var root = (e.detail && e.detail.elt) || document.body;
        if (root.nodeType !== 1) root = document.body;

        var scope = function (sel) {
            var found = Array.prototype.slice.call(root.querySelectorAll(sel));
            if (root.matches && root.matches(sel)) found.push(root);
            return found;
        };

        if (typeof autosize === 'function') autosize(scope('textarea'));
        if (window.timeago) {
            scope('.timeago').forEach(function (el) {
                var dt = el.getAttribute('datetime');
                if (dt) el.textContent = timeago.format(dt, 'zh_CN');
            });
        }
        if (window._lazy && typeof window._lazy.update === 'function') window._lazy.update();

        // 动态评论区的子回复是 beforeend 到 .moment-replies 里，新节点不一定落在 root 内，
        // 所以这里整篇兜一次：先摘掉「空容器」标记，再初始化增强
        //（eachNew 在节点上留标记，重复调用不会重复绑定）。
        document.querySelectorAll('.moment-comments.is-empty, .moment-replies.is-empty').forEach(function (el) {
            if (el.querySelector('.moment-comment')) el.classList.remove('is-empty');
        });
        if (window.initHtmxForms) initHtmxForms(document);
        if (window.ThreadRead) ThreadRead.applyToList();
    });

    // 片段请求失败时给个统一提示，避免点了没反应。
    // 后端已经用 HX-Trigger 带了具体原因时不重复弹。
    document.addEventListener('htmx:responseError', function (e) {
        var xhr = e.detail && e.detail.xhr;
        if (xhr && typeof xhr.getResponseHeader === 'function' && xhr.getResponseHeader('HX-Trigger')) return;
        toast('请求失败（' + (xhr ? xhr.status : '?') + '）', 'error');
    });
})();

// ========== htmx 表单增强（原生 JS，替代原来的 Alpine 组件）==========
//
// 三类可复用行为都靠 data-* 声明，页面视图不用再写自己的 Alpine 组件：
//   - data-captcha-scene  验证码容器，验证通过后回填同表单的 captcha_id / captcha_answer
//   - data-draft-key      草稿自动保存到 localStorage，提交成功后自动清掉
//   - data-tag-add        标签快捷点选，写入 data-tags-input 那个输入框
(function () {
    /** 只初始化还没初始化过的节点：htmx 换入片段时可能混有旧节点 */
    function eachNew(root, selector, flag, fn) {
        var scope = (root && root.nodeType === 1) ? root : document;
        var found = Array.prototype.slice.call(scope.querySelectorAll(selector));
        if (scope.matches && scope.matches(selector)) found.push(scope);
        found.forEach(function (el) {
            if (el[flag]) return;
            el[flag] = true;
            fn(el);
        });
    }

    /** 验证码：容器声明 data-captcha-scene，回填同表单里的两个隐藏字段 */
    function initCaptcha(root) {
        if (typeof CaptchaWidget === 'undefined') return;
        eachNew(root, '[data-captcha-scene]', '_amubbsCaptcha', function (el) {
            var form = el.closest('form');
            var idInput = form ? form.querySelector('input[name="captcha_id"]') : null;
            var ansInput = form ? form.querySelector('input[name="captcha_answer"]') : null;

            var widget = new CaptchaWidget(el, {
                scene: el.getAttribute('data-captcha-scene'),
                onVerified: function (ok, id, answer) {
                    if (idInput) idInput.value = id || '';
                    if (ansInput) ansInput.value = answer || '';
                }
            });

            // 提交失败时刷新验证码：一次性验证码已经被服务端消费掉了
            if (form) {
                form.addEventListener('htmx:responseError', function () { widget.load(); });
            }
        });
    }

    /** 草稿自动保存：表单声明 data-draft-key，可选 data-draft-hint 显示「已保存」 */
    function initDraft(root) {
        eachNew(root, 'form[data-draft-key]', '_amubbsDraft', function (form) {
            var key = form.getAttribute('data-draft-key');
            var hint = form.querySelector('[data-draft-hint]');
            var names = ['title', 'content', 'tags'];

            var read = function (name) {
                var el = form.querySelector('[name="' + name + '"]');
                return el ? el.value : '';
            };
            var write = function (name, value) {
                var el = form.querySelector('[name="' + name + '"]');
                if (el) el.value = value;
            };

            // 回填上次没提交成功的草稿。
            // 默认只在字段为空时填（不覆盖服务端渲染出来的内容）；
            // 声明 data-draft-restore="always" 的表单让草稿优先（编辑页沿用迁移前的行为）。
            var restoreAlways = form.getAttribute('data-draft-restore') === 'always';
            var saved = null;
            try { saved = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { saved = null; }
            if (saved && typeof saved === 'object') {
                names.forEach(function (n) {
                    if (!saved[n]) return;
                    if (restoreAlways || !read(n)) write(n, saved[n]);
                });
            }

            form.addEventListener('input', function () {
                var data = {};
                names.forEach(function (n) { data[n] = read(n); });
                if (!data.title && !data.content) return;
                try { localStorage.setItem(key, JSON.stringify(data)); } catch (e) {}
                if (hint) hint.style.display = '';
            });

            // 提交成功（2xx）后草稿就没用了；失败时保留，用户内容不丢
            form.addEventListener('htmx:afterRequest', function (e) {
                if (!e.detail || !e.detail.successful) return;
                try { localStorage.removeItem(key); } catch (err) {}
                if (hint) hint.style.display = 'none';
            });
        });
    }

    /** 标签快捷点选：可点元素声明 data-tag-add="标签名"，目标输入框声明 data-tags-input */
    function initTags(root) {
        eachNew(root, '[data-tag-add]', '_amubbsTag', function (el) {
            el.addEventListener('click', function () {
                var form = el.closest('form');
                var input = form ? form.querySelector('[data-tags-input]') : null;
                if (!input) return;

                var name = el.getAttribute('data-tag-add');
                var current = input.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                if (current.indexOf(name) === -1) {
                    current.push(name);
                    input.value = current.join(',');
                    input.dispatchEvent(new Event('input', { bubbles: true }));  // 让草稿保存也跟上
                }
            });
        });
    }

    /** 密码显示/隐藏：按钮声明 data-pwd-toggle="#目标输入框" */
    function initPwdToggles(root) {
        eachNew(root, '[data-pwd-toggle]', '_amubbsPwd', function (btn) {
            btn.addEventListener('click', function () {
                var sel = btn.getAttribute('data-pwd-toggle');
                var input = sel ? document.querySelector(sel)
                                : (btn.closest('.input-pwd-wrap') || btn.parentElement).querySelector('input');
                if (!input) return;
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            });
        });
    }

    /** 邮箱验证码按钮：声明 data-code-countdown="60"，请求成功后开始倒计时 */
    function initCodeButtons(root) {
        eachNew(root, '[data-code-countdown]', '_amubbsCode', function (btn) {
            var seconds = parseInt(btn.getAttribute('data-code-countdown'), 10) || 60;
            var label = btn.querySelector('[data-code-label]');
            var left = 0;
            var timer = null;

            function render() {
                if (left > 0) {
                    btn.disabled = true;
                    if (label) label.textContent = left + 's 后重发';
                } else {
                    btn.disabled = false;
                    if (label) label.textContent = '获取验证码';
                }
            }

            btn.addEventListener('htmx:beforeRequest', function () { btn.disabled = true; });
            btn.addEventListener('htmx:afterRequest', function (e) {
                if (!e.detail || !e.detail.successful) { render(); return; }   // 失败：恢复可点
                left = seconds;
                render();
                timer = setInterval(function () {
                    left--;
                    if (left <= 0) { clearInterval(timer); timer = null; left = 0; }
                    render();
                }, 1000);
            });

            render();
        });
    }

    /**
     * 标签页：容器写 [data-tabs="默认标签"]，按钮写 [data-tab="key"]，面板写 [data-tab-panel="key"]
     *
     * 切的是既有的 .active 类（CSS 已经按它控制显隐），所以面板内容照旧由服务端一次性渲染，
     * 不需要为每个标签单独发请求。
     */
    function initTabs(root) {
        eachNew(root, '[data-tabs]', '_amubbsTabs', function (box) {
            var buttons = Array.prototype.slice.call(box.querySelectorAll('[data-tab]'));
            var panels = Array.prototype.slice.call(box.querySelectorAll('[data-tab-panel]'));
            if (!buttons.length) return;

            function activate(key) {
                buttons.forEach(function (b) {
                    b.classList.toggle('active', b.getAttribute('data-tab') === key);
                });
                panels.forEach(function (p) {
                    p.classList.toggle('active', p.getAttribute('data-tab-panel') === key);
                });
            }

            buttons.forEach(function (b) {
                b.addEventListener('click', function () { activate(b.getAttribute('data-tab')); });
            });

            var initial = box.getAttribute('data-tabs') || buttons[0].getAttribute('data-tab');
            activate(initial);
        });
    }

    /**
     * 滚动到底：容器写 [data-scroll-bottom]
     *
     * 私信这类「最新在下面」的列表，初次加载和 htmx 追加新内容后都要贴到底部。
     */
    function initScrollBottom(root) {
        eachNew(root, '[data-scroll-bottom]', '_amubbsScroll', function (el) {
            var toBottom = function () { el.scrollTop = el.scrollHeight; };
            toBottom();
            // 片段是换到容器内部的，所以监听容器自身的 htmx:afterSwap
            el.addEventListener('htmx:afterSwap', toBottom);
        });
    }

    /**
     * 可关闭的公告：容器写 [data-ann-wrap] + [data-ann-hide-mins]，
     * 关闭按钮写 [data-ann-hide="公告ID"]，条目写 [data-ann-item]
     *
     * 访客没有账号，所以「关闭」只能记在 localStorage（和迁移前的行为一致）；
     * 页面头部还有一段行内脚本会先用同一个键预生成 display:none 样式，避免刷新时闪一下。
     */
    function initAnnouncements(root) {
        eachNew(root, '[data-ann-hide]', '_amubbsAnn', function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-ann-hide');
                if (!id) return;

                var wrap = btn.closest('[data-ann-wrap]');
                var mins = wrap ? (parseInt(wrap.getAttribute('data-ann-hide-mins'), 10) || 0) : 0;

                try {
                    var data = JSON.parse(localStorage.getItem('ann_hidden') || '{}');
                    // 顺手清掉已过期的记录，别让这个键无限增长
                    var now = Date.now();
                    for (var k in data) {
                        if (mins <= 0 || (now - data[k]) >= mins * 60000) delete data[k];
                    }
                    data[id] = now;
                    localStorage.setItem('ann_hidden', JSON.stringify(data));
                } catch (e) {}

                var item = btn.closest('[data-ann-item]');
                if (item) item.style.display = 'none';
            });
        });
    }

    /**
     * 显示/隐藏切换：按钮写 [data-toggle-target="#某元素"]，点击就翻转目标的 hidden
     *
     * 取代原来用 Alpine 变量 + x-show 控制显隐的那种写法（如动态的评论框）。
     * 可选 [data-toggle-alt="#另一块"]：目标显示时把「另一块」藏起来——用于
     * 「正文 / 编辑表单」这种二选一的场景，编辑和取消共用同一组属性。
     */
    function initToggles(root) {
        eachNew(root, '[data-toggle-target]', '_amubbsToggle', function (btn) {
            btn.addEventListener('click', function () {
                var target = document.querySelector(btn.getAttribute('data-toggle-target'));
                if (!target) return;
                target.hidden = !target.hidden;

                var altSel = btn.getAttribute('data-toggle-alt');
                if (altSel) {
                    var alt = document.querySelector(altSel);
                    if (alt) alt.hidden = !target.hidden;
                }
            });
        });
    }

    /**
     * 「回复某人」：可点元素写 [data-reply-to] / [data-reply-name] / [data-reply-form="#表单"]
     * 可选 [data-reply-floor="#楼层元素"]，用于「引用 某人 #3」这种带楼层号的提示
     * 可选 [data-reply-post]：帖子页「引用」写的是帖子 id（[data-reply-input] 对应 quote_post_id）；
     *   不带该属性时回落到 [data-reply-to]（动态评论写的是被回复的用户 id）
     *
     * 点击后把被回复人的 id 写进表单里的 [data-reply-input]、显示 [data-reply-indicator]
     * 并聚焦输入框；提交成功后自动清掉回复状态（回复目标不该粘在下一条评论上）。
     */
    function initReplyTargets(root) {
        eachNew(root, '[data-reply-to]', '_amubbsReply', function (el) {
            el.addEventListener('click', function () {
                var form = document.querySelector(el.getAttribute('data-reply-form'));
                if (!form) return;

                var input = form.querySelector('[data-reply-input]');
                if (input) input.value = el.getAttribute('data-reply-post') || el.getAttribute('data-reply-to');

                var box = form.closest('.moment-comment-form') || form;
                var indicator = box.querySelector('[data-reply-indicator]');
                var nameEl = box.querySelector('[data-reply-name]');
                var floorEl = box.querySelector('[data-reply-floor]');
                if (nameEl) nameEl.textContent = el.getAttribute('data-reply-name') || '';
                if (floorEl) floorEl.textContent = el.getAttribute('data-reply-floor') || '';
                if (indicator) indicator.hidden = false;
                // 每条评论自带一个回复框（.moment-reply-form，默认 hidden）：点「回复」时显形。
                // 顶层评论框是靠 [data-toggle-target] 翻显隐的，这里 un-hide 是幂等的。
                if (box.hidden && box.classList.contains('moment-reply-form')) box.hidden = false;

                // 输入框可能是 <input> 也可能是 <textarea>（帖子回复框就是后者）
                var text = form.querySelector('[name="content"]');
                if (text) {
                    if (form.scrollIntoView) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    text.focus();
                }

                // 提交成功后清掉回复状态（只在首次绑定时挂一次）
                if (!form._amubbsReplyBound) {
                    form._amubbsReplyBound = true;
                    form.addEventListener('htmx:afterRequest', function (e) {
                        if (!e.detail || !e.detail.successful) return;
                        form.reset();
                        var ind = box.querySelector('[data-reply-indicator]');
                        if (ind) ind.hidden = true;
                        var inp = form.querySelector('[data-reply-input]');
                        if (inp) inp.value = '';
                        // 线程内的回复框提交完就收起来，免得一条条堆在评论区里
                        if (box.classList.contains('moment-reply-form')) box.hidden = true;
                    });
                }
            });
        });

        // 「取消」只清回复目标，不动已输入的内容
        eachNew(root, '[data-reply-cancel]', '_amubbsReplyCancel', function (el) {
            el.addEventListener('click', function () {
                var box = el.closest('.moment-comment-form') || el.closest('form') || el.parentElement;
                if (!box) return;
                var indicator = box.querySelector('[data-reply-indicator]');
                if (indicator) indicator.hidden = true;
                var input = box.querySelector('[data-reply-input]');
                if (input) input.value = '';
                // 线程内的回复框：取消后收起来
                if (box.classList.contains('moment-reply-form')) box.hidden = true;
            });
        });
    }

    /**
     * 表情面板：按钮写 [data-emoji-picker="#输入框"]，表情表取 window.__AMUBBS_EMOJI
     *
     * 取代原来写在 Alpine 组件里的 showEmojiPanel()：面板按需创建、点外部或再点按钮收起，
     * 选中后把 :code: 插到光标处并派发 input 事件（草稿保存/字数统计才跟得上）。
     */
    function initEmojiPickers(root) {
        eachNew(root, '[data-emoji-picker]', '_amubbsEmoji', function (btn) {
            btn.addEventListener('click', function () {
                var map = window.__AMUBBS_EMOJI;
                var input = document.querySelector(btn.getAttribute('data-emoji-picker'));
                if (!map || !input) return;

                var opened = document.querySelector('.amubbs-emoji-panel');
                var reopen = opened && opened._amubbsFor === input;
                document.querySelectorAll('.amubbs-emoji-panel,.amubbs-emoji-backdrop').forEach(function (el) { el.remove(); });
                if (reopen) return;   // 再点一次就是收起

                var items = '';
                for (var name in map) {
                    items += '<span class="amubbs-emoji-item" data-code=":' + name + ':" title=":' + name + ':" style="cursor:pointer;font-size:20px;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;">' + map[name] + '</span>';
                }
                var panel = document.createElement('div');
                panel.className = 'amubbs-emoji-panel';
                panel._amubbsFor = input;
                panel.innerHTML = items;

                var rect = btn.getBoundingClientRect();
                panel.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
                panel.style.left = Math.max(8, rect.left) + 'px';

                panel.addEventListener('mouseover', function (e) {
                    if (e.target.classList.contains('amubbs-emoji-item')) e.target.style.background = 'var(--bg-hover,#f1f5f9)';
                });
                panel.addEventListener('mouseout', function (e) {
                    if (e.target.classList.contains('amubbs-emoji-item')) e.target.style.background = '';
                });
                panel.addEventListener('click', function (e) {
                    var item = e.target.closest('.amubbs-emoji-item');
                    if (!item) return;
                    var code = item.getAttribute('data-code');
                    var start = input.selectionStart, end = input.selectionEnd;
                    input.value = input.value.substring(0, start) + code + input.value.substring(end);
                    input.focus();
                    input.selectionStart = input.selectionEnd = start + code.length;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    panel.remove();
                    backdrop.remove();
                });

                var backdrop = document.createElement('div');
                backdrop.className = 'amubbs-emoji-backdrop';
                backdrop.addEventListener('click', function () { panel.remove(); backdrop.remove(); });
                document.body.appendChild(backdrop);
                document.body.appendChild(panel);
            });
        });
    }

    /** 上传缩略图的「×」：整块移除（连同里面隐藏的字段） */
    function initUploadThumbs(root) {
        eachNew(root, '[data-remove-thumb]', '_amubbsThumb', function (btn) {
            btn.addEventListener('click', function () {
                var box = btn.closest('[data-upload-thumb]');
                if (box) box.remove();
            });
        });
    }

    /** 字数统计：容器写 [data-count-for="#输入框"] [data-count-max="1000"] */
    function initCharCounters(root) {
        eachNew(root, '[data-count-for]', '_amubbsCount', function (el) {
            var input = document.querySelector(el.getAttribute('data-count-for'));
            if (!input) return;
            var max = parseInt(el.getAttribute('data-count-max'), 10) || 0;
            var render = function () { el.textContent = input.value.length + '/' + max; };
            input.addEventListener('input', render);
            render();
        });
    }

    /**
     * 浮层点遮罩关闭：容器写 [data-overlay-close]，点到容器自身（不是里面的内容）就隐藏它
     *
     * 对应原来 Alpine 的 @click.self="showPanel = false"。
     */
    function initOverlayClose(root) {
        eachNew(root, '[data-overlay-close]', '_amubbsOverlay', function (el) {
            el.addEventListener('click', function (e) {
                if (e.target === el) el.hidden = true;
            });
        });
    }

    /**
     * 勾选式批量操作栏
     *
     * 工具栏写 [data-check-toolbar="项目选择器"]，里面可选：
     *   [data-check-count] 显示已选数量、[data-check-all] 全选框
     * 没有任何勾选时整条工具栏隐藏（对应原来 Alpine 的 selected.length > 0）。
     */
    function initCheckToolbars(root) {
        eachNew(root, '[data-check-toolbar]', '_amubbsCheckbar', function (bar) {
            var sel = bar.getAttribute('data-check-toolbar') || '[data-check-item]';
            var countEl = bar.querySelector('[data-check-count]');
            var allEl = bar.querySelector('[data-check-all]');
            var items = function () { return Array.prototype.slice.call(document.querySelectorAll(sel)); };

            function sync() {
                var all = items();
                var checked = all.filter(function (i) { return i.checked; }).length;
                if (countEl) countEl.textContent = checked;
                bar.hidden = checked === 0;
                if (allEl) allEl.checked = all.length > 0 && checked === all.length;
            }

            items().forEach(function (i) { i.addEventListener('change', sync); });
            if (allEl) {
                allEl.addEventListener('change', function () {
                    items().forEach(function (i) { i.checked = allEl.checked; });
                    sync();
                });
            }
            sync();
        });
    }

    /** 快捷填值：按钮写 [data-set-value="#目标输入框"] [data-value="10"]（如打赏金额快捷键） */
    function initSetValues(root) {
        eachNew(root, '[data-set-value]', '_amubbsSetVal', function (btn) {
            btn.addEventListener('click', function () {
                var input = document.querySelector(btn.getAttribute('data-set-value'));
                if (!input) return;
                input.value = btn.getAttribute('data-value') || '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    }

    window.initHtmxForms = function (root) {
        initCaptcha(root || document);
        initDraft(root || document);
        initTags(root || document);
        initPwdToggles(root || document);
        initCodeButtons(root || document);
        initDropdowns(root || document);
        initBackToTop(root || document);
        initThemeToggles(root || document);
        initTabs(root || document);
        initScrollBottom(root || document);
        initAnnouncements(root || document);
        initToggles(root || document);
        initReplyTargets(root || document);
        initEmojiPickers(root || document);
        initUploadThumbs(root || document);
        initCharCounters(root || document);
        initOverlayClose(root || document);
        initCheckToolbars(root || document);
        initSetValues(root || document);
    };

    // ---------- 通用下拉菜单（替代 Alpine 的 @click.outside / x-show）----------
    // 容器写 [data-dropdown]，按钮写 [data-dropdown-toggle]，菜单写 [data-dropdown-menu]（初始带 hidden）
    function closeAllDropdowns() {
        document.querySelectorAll('[data-dropdown-menu]').forEach(function (menu) {
            menu.hidden = true;
        });
    }

    function initDropdowns(root) {
        eachNew(root, '[data-dropdown]', '_amubbsDropdown', function (box) {
            var toggle = box.querySelector('[data-dropdown-toggle]');
            var menu = box.querySelector('[data-dropdown-menu]');
            if (!toggle || !menu) return;

            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                var willOpen = menu.hidden;
                closeAllDropdowns();
                menu.hidden = !willOpen;
            });
        });
    }

    // ---------- 返回顶部 ----------
    function initBackToTop(root) {
        eachNew(root, '[data-back-to-top]', '_amubbsTop', function (el) {
            var onScroll = function () {
                el.classList.toggle('visible', window.scrollY > 300);
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            el.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
            onScroll();
        });
    }

    // ---------- 暗色模式切换 ----------
    // 亮/暗图标由 CSS 依据 html.dark 决定，这里只切换类和 localStorage。
    // （页面加载前的防闪烁逻辑在 header.php 的行内脚本里）
    function initThemeToggles(root) {
        eachNew(root, '[data-theme-toggle]', '_amubbsTheme', function (el) {
            var sync = function () {
                var dark = document.documentElement.classList.contains('dark');
                el.setAttribute('title', dark ? '切换亮色' : '切换暗色');
            };
            el.addEventListener('click', function () {
                var dark = !document.documentElement.classList.contains('dark');
                document.documentElement.classList.toggle('dark', dark);
                try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch (e) {}
                sync();
            });
            sync();
        });
    }

    // 首次加载就初始化一遍（app.js 是 defer，此时 DOM 已就绪）
    window.initHtmxForms(document);

    // 点空白处 / 按 Esc 关掉下拉菜单（弹窗的关闭逻辑在下面单独处理）
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || typeof t.closest !== 'function') return;
        if (!t.closest('[data-dropdown]')) closeAllDropdowns();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllDropdowns();
    });

    // ---------- 登录 / 注册弹窗 ----------
    // 弹窗里塞的是 htmx 取回的 /login?modal=1 片段，所以打开动作要按需拉取。
    function authModalEl() { return document.getElementById('authModal'); }

    /**
     * 弹窗在当前视口里是否真的能显示
     *
     * CSS 在 max-width:767px 时把 .auth-overlay 设成 display:none（移动端走独立页面）。
     * 这里靠「临时去掉 hidden 再问一次计算样式」来判断，让 CSS 保持唯一事实来源，
     * 而不是在 JS 里再抄一份断点。
     */
    function authModalUsable(modal) {
        var wasHidden = modal.hidden;
        modal.hidden = false;
        var usable = window.getComputedStyle(modal).display !== 'none';
        modal.hidden = wasHidden;
        return usable;
    }

    window.openAuth = function (which) {
        var modal = authModalEl();
        if (!modal) return;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        if (window.htmx) {
            // 把宿主页面地址一起带过去：弹窗里的表单要把它写进 hidden redirect，
            // 否则请求 URL 是弹窗自己拉的 /login?modal=1，登录成功会回到 /login
            var back = window.location.pathname + window.location.search;
            htmx.ajax('GET', (which === 'register' ? '/register' : '/login')
                + '?modal=1&redirect=' + encodeURIComponent(back), {
                target: '#authModalBody', swap: 'innerHTML'
            });
        }
        var first = modal.querySelector('input:not([type=hidden])');
        if (first) first.focus();
    };

    window.closeAuth = function () {
        var modal = authModalEl();
        if (!modal) return;
        modal.hidden = true;
        document.body.style.overflow = '';
    };

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || typeof t.closest !== 'function') return;

        var opener = t.closest('[data-auth-open]');
        if (opener) {
            var modal = authModalEl();
            // 没有弹窗（已登录）或当前视口不显示弹窗 → 不拦截，让 <a href="/login"> 正常跳转
            if (!modal || !authModalUsable(modal)) return;
            e.preventDefault();
            window.openAuth(opener.getAttribute('data-auth-open'));
            return;
        }

        if (t.closest('[data-auth-close]')) { e.preventDefault(); window.closeAuth(); return; }

        // 点遮罩空白处关闭
        var m = authModalEl();
        if (m && !m.hidden && t === m) { window.closeAuth(); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var m = authModalEl();
        if (m && !m.hidden) window.closeAuth();
    });
})();

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

// ========== 通知轮询 ==========
(function() {
    if (typeof window._initUnreadCount === 'undefined') return;
    window._lastNotifCount = window._initUnreadCount;
    setInterval(function() {
        if (document.visibilityState === 'hidden') return;
        fetch('/notifications/unread-count').then(function(r) { return r.json(); }).then(function(data) {
            var count = data.count || 0;
            document.querySelectorAll('#notifBadge').forEach(function(el) {
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
// 保留实例引用：htmx 换入片段后要调 update() 重新扫描，而不是再 new 一个
if (typeof LazyLoad !== 'undefined') {
    window._lazy = new LazyLoad({ elements_selector: '.lazy' });
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

// ========== 暗色模式的防闪烁 ==========
// 页面加载前立即应用，避免闪白；切换逻辑在 [data-theme-toggle] 里（见上方 htmx 表单增强）
(function() {
    if (localStorage.getItem('theme') === 'dark') {
        document.documentElement.classList.add('dark');
    }
})();

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
