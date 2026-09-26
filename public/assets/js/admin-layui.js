/**
 * 后台子页面公共脚本（layuimini / layui 之上的一层薄封装）
 * ==========================================================================
 * 只做三件 layui 本身不提供、而每个后台页面都需要的事：
 *
 *   1. 给所有 jQuery ajax 自动带上 CSRF 头
 *      —— 服务端 Csrf 中间件接受 X-CSRF-TOKEN；带了这个头会被判定为 AJAX，
 *         不会轮换 token，所以一个页面里连发多次请求也不会 403。
 *   2. 统一的「操作成功/失败」提示与「确认后提交」流程
 *      —— 都在 layui 的 layer 上转一层，避免每个页面各写一遍。
 *   3. 提交后刷新父窗口的表格
 *      —— 弹层（layer type:2）里的表单保存成功后，要通知 iframe 外层的表格重载。
 *
 * 依赖：layui.js 必须先加载；本文件在 layui.use 之前被同步引入，
 *      所以只挂全局函数，不直接调用 layui。
 * ==========================================================================
 */
(function (window) {
  'use strict';

  /** 当前页面的 CSRF token（由 layout_child.php 的 meta 标签提供） */
  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  /**
   * 给 jQuery ajax 统一加 CSRF 头。
   * layui 的 table/form 内部都走 $.ajax，所以设置一次就够。
   * 注意：layui 的 jquery 模块要先 use 出来才有 $，这里用轮询等它就绪。
   */
  function installCsrfHeader() {
    var tries = 0;
    (function wait() {
      var layui = window.layui;
      if (layui && layui.jquery) {
        layui.jquery.ajaxSetup({
          headers: { 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
        });
        // layui 的 table 用的是自己的 ajax，同样吃 $.ajaxSetup
        return;
      }
      if (tries++ < 100) { setTimeout(wait, 30); }
    })();
  }

  installCsrfHeader();

  /**
   * 子页面里点「站内后台链接」→ 让外壳开一个新 tab，而不是在 iframe 里就地跳转。
   *
   * 为什么必须在这里做：layuimini 的 [layuimini-content-href] 是 miniTab.listen()
   * 绑定的，而 listen() 只在**外壳文档**里跑（miniAdmin.render() 调用它）。
   * 子页面在自己的 iframe 里，点击事件不会冒泡到父文档，所以那个属性在子页面里
   * 等于装饰品 —— 链接会退化成 iframe 内跳转，整个后台的「多 tab」就没意义了。
   *
   * miniTab 已经为这种情况导出了 openNewTabByIframe({href,title})，这里调用它即可。
   * 用原生委托监听而不是 jQuery：本文件在 layui.use 之前就执行，$ 还没就绪。
   */
  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('[layuimini-content-href]') : null;
    if (!el) { return; }

    var href = el.getAttribute('layuimini-content-href');
    if (!href) { return; }

    var title = el.getAttribute('data-title') || (el.textContent || '').replace(/\s+/g, ' ').trim();

    try {
      var pTab = window.parent && window.parent.layui && window.parent.layui.miniTab;
      if (pTab && typeof pTab.openNewTabByIframe === 'function') {
        e.preventDefault();   // 否则外壳开 tab 的同时，本 iframe 也会跳过去
        pTab.openNewTabByIframe({ href: href, title: title });
        return;
      }
    } catch (err) {
      // 跨域等异常：什么都不做，让浏览器按 href 正常跳转
    }
  });

  window.AdminUi = {
    csrfToken: csrfToken,

    /** 成功/失败提示（layui layer.msg 的薄封装） */
    ok: function (msg) {
      window.layui && layui.layer.msg(msg || '操作成功', { icon: 1, time: 1500 });
    },
    fail: function (msg) {
      window.layui && layui.layer.msg(msg || '操作失败', { icon: 2, time: 2200 });
    },
    warn: function (msg) {
      window.layui && layui.layer.msg(msg || '请注意', { icon: 0, time: 2200 });
    },

    /**
     * POST 一个后台接口，自动带 CSRF，统一处理 {code, msg} 响应。
     * 服务端约定：code === 0 表示成功（Base::success / jsonTable 都是这个约定）。
     */
    post: function (url, data, onSuccess) {
      var $ = window.layui && layui.jquery;
      if (!$) { return; }
      $.post(url, data, function (res) {
        if (res && res.code === 0) {
          AdminUi.ok(res.msg || '操作成功');
          if (typeof onSuccess === 'function') { onSuccess(res); }
        } else {
          AdminUi.fail((res && (res.msg || res.message)) || '操作失败');
        }
      }, 'json').fail(function (xhr) {
        var msg = '请求失败';
        try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
        AdminUi.fail(msg);
      });
    },

    /** 确认后 POST */
    confirmPost: function (text, url, data, onSuccess) {
      window.layui && layui.layer.confirm(text || '确定执行该操作吗？', function (idx) {
        layui.layer.close(idx);
        AdminUi.post(url, data, onSuccess);
      });
    },

    /**
     * 表单弹层保存成功后，重载外层列表页的表格。
     * 弹层是 iframe（layer type:2），所以要往上找一层拿 layui table。
     */
    reloadParentTable: function (tableId) {
      try {
        var win = window.parent;
        if (win && win.layui && win.layui.table && tableId) {
          win.layui.table.reload(tableId);
        }
      } catch (e) { /* 跨域或父层没有表格时忽略 */ }
    },

    /**
     * 关闭当前弹层，并让外层表格刷新。
     * 用于「保存成功」后的收尾：关掉自己 + 刷新列表。
     */
    closeLayerAndReload: function (tableId) {
      AdminUi.reloadParentTable(tableId);
      try {
        var idx = window.parent.layer.getFrameIndex(window.name);
        window.parent.layer.close(idx);
      } catch (e) { /* 不是弹层里打开的（直接访问表单页）就忽略 */ }
    },
  };
})(window);
