/**
 * AMuBBS 前台公共工具库
 * 统一 CSRF、fetch 请求、toast 反馈、确认操作
 *
 * 用法:
 *   App.post('/thread/reply', { thread_id: 1, content: '...' })
 *   App.post('/like/toggle', { thread_id: 1 }, { silent: true })
 *   App.upload('/attachment/upload', formData)
 *   App.confirmDelete('/thread/delete', { id: 1 })
 *   App.postThen('/thread/reply', data, () => location.reload())
 */
window.App = {

    /** 获取 CSRF token */
    csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    },

    /**
     * 通用请求
     * @param {string} url
     * @param {object} opts - { method, body, headers, silent, successMsg, errorMsg }
     *   body: object → URLSearchParams, FormData → 原样, string → 原样
     *   silent: true 时不弹 toast
     */
    async request(url, opts) {
        opts = opts || {};
        var method = (opts.method || 'GET').toUpperCase();
        var headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest' }, opts.headers || {});

        if (method !== 'GET') {
            headers['X-CSRF-TOKEN'] = this.csrf();
        }

        var fetchOpts = { method: method, headers: headers };

        // 处理请求体
        if (opts.body instanceof FormData) {
            fetchOpts.body = opts.body;
        } else if (opts.json) {
            // 显式 JSON 模式
            headers['Content-Type'] = 'application/json';
            fetchOpts.body = JSON.stringify(opts.json);
        } else if (opts.body && typeof opts.body === 'object') {
            // 默认 URLSearchParams（与大多数视图一致）
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
            fetchOpts.body = new URLSearchParams(opts.body).toString();
        } else if (opts.body) {
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
            fetchOpts.body = String(opts.body);
        }

        fetchOpts.headers = headers;

        try {
            var resp = await fetch(url, fetchOpts);
            var data = await resp.json();
            if (!opts.silent) {
                if (data.success) {
                    toast(opts.successMsg || data.message || '操作成功', 'success');
                } else {
                    toast(opts.errorMsg || data.message || '操作失败', 'error');
                }
            }
            return data;
        } catch (e) {
            if (!opts.silent) toast('网络错误', 'error');
            return { success: false, message: '网络错误' };
        }
    },

    /** GET 请求（默认静默） */
    get(url, params, opts) {
        if (params) {
            var qs = new URLSearchParams(params).toString();
            if (qs) url += (url.indexOf('?') > -1 ? '&' : '?') + qs;
        }
        return this.request(url, Object.assign({ silent: true }, opts || {}));
    },

    /** POST 请求 */
    post(url, data, opts) {
        return this.request(url, Object.assign({ method: 'POST', body: data }, opts || {}));
    },

    /** POST JSON */
    postJSON(url, data, opts) {
        return this.request(url, Object.assign({ method: 'POST', json: data }, opts || {}));
    },

    /** 文件上传（FormData） */
    upload(url, formData, opts) {
        return this.request(url, Object.assign({ method: 'POST', body: formData }, opts || {}));
    },

    /** POST 成功后执行回调 */
    async postThen(url, data, onSuccess, opts) {
        var result = await this.post(url, data, opts);
        if (result.success && onSuccess) {
            setTimeout(onSuccess, 600);
        }
        return result;
    },

    /** POST 成功后刷新页面 */
    postReload(url, data, opts) {
        return this.postThen(url, data, function() { location.reload(); }, opts);
    },

    /** POST 成功后跳转 */
    postRedirect(url, data, redirectUrl, opts) {
        return this.postThen(url, data, function() { location.href = redirectUrl || '/'; }, opts);
    },

    /** 确认后 POST */
    async confirmPost(url, data, msg, opts) {
        if (!confirm(msg || '确定执行此操作？')) return false;
        return this.post(url, data, opts);
    },

    /** 确认删除 */
    confirmDelete(url, data, msg) {
        return this.confirmPost(url, data, msg || '确定删除？', { successMsg: '已删除' });
    }
};
