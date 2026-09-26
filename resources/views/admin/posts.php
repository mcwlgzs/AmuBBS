<?php
/**
 * 后台 - 回帖管理（layuimini 子页面片段）
 * ==========================================================================
 * 由 layout_child.php 包成一个独立文档、跑在外壳的 iframe 里，
 * 所以这里是纯片段：没有文档声明，没有 html/head/body 标签，也没有任何 htmx 属性。
 *
 * 结构照 forums.php（列表页参考实现）：
 *   fieldset.table-search-fieldset  搜索区
 *   #postToolbar                    批量删除（全选交给 layui 的 checkbox 列）
 *   #postTable                      layui table，数据来自 /admin/api/posts
 *   #postRowBar                     行内删除
 *
 * 能筛/能排的字段严格按 PostController::postFilters() 来：
 *   search / username / thread_id / ip
 *   sort（id|created_at）+ dir（asc|desc）
 * 排序是**服务端**的：layui 自带的点击排序只排当前页缓存，所以关掉 autoSort，
 * 用 sort 事件把 sort/dir 塞进 where 再重载（见下面的 sort 事件）。
 *
 * 变量：$filters, $sort（$rows/$total 已不再服务端渲染，接口会给）
 */

$filters = is_array($filters ?? null) ? $filters : [];
$sort    = is_array($sort ?? null) ? $sort : ['field' => 'id', 'dir' => 'desc'];

$esc = static fn(string $key): string
    => htmlspecialchars((string)($filters[$key] ?? ''), ENT_QUOTES, 'UTF-8');

// 排序字段白名单，和 Post::ADMIN_SORTS 保持一致
$sortField = (string)($sort['field'] ?? 'id');
if (!in_array($sortField, ['id', 'created_at'], true)) {
    $sortField = 'id';
}
$sortDir = ((string)($sort['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

/** 首屏 where：带着查询串打开（/admin/posts?search=x）时表格也要按它查 */
$initialWhere = array_filter([
    'search'    => trim((string)($filters['search'] ?? '')),
    'username'  => trim((string)($filters['username'] ?? '')),
    'thread_id' => (int)($filters['thread_id'] ?? 0),
    'ip'        => trim((string)($filters['ip'] ?? '')),
], static fn($v): bool => $v !== '' && $v !== 0);

// 内联脚本里的数据一律走 json_encode；HEX 系列保证 </script> 之类不会截断脚本
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<!-- 搜索（只放接口真正读的字段） -->
<fieldset class="table-search-fieldset">
  <legend>搜索信息</legend>
  <div style="margin:10px 10px 10px 10px">
    <form class="layui-form layui-form-pane" lay-filter="postSearchForm" onsubmit="return false">
      <div class="layui-form-item">
        <div class="layui-inline">
          <label class="layui-form-label">内容</label>
          <div class="layui-input-inline">
            <input type="text" name="search" class="layui-input" placeholder="搜索内容"
                   value="<?= $esc('search') ?>">
          </div>
        </div>

        <div class="layui-inline">
          <label class="layui-form-label">用户名</label>
          <div class="layui-input-inline">
            <input type="text" name="username" class="layui-input" placeholder="用户名"
                   value="<?= $esc('username') ?>">
          </div>
        </div>

        <div class="layui-inline">
          <label class="layui-form-label">帖子 ID</label>
          <div class="layui-input-inline">
            <input type="text" name="thread_id" class="layui-input" placeholder="帖子 ID"
                   value="<?= $esc('thread_id') ?>">
          </div>
        </div>

        <div class="layui-inline">
          <label class="layui-form-label">IP</label>
          <div class="layui-input-inline">
            <input type="text" name="ip" class="layui-input" placeholder="IP 地址"
                   value="<?= $esc('ip') ?>">
          </div>
        </div>

        <div class="layui-inline">
          <button class="layui-btn layui-btn-primary" lay-submit lay-filter="post-search">
            <i class="layui-icon layui-icon-search"></i> 搜索
          </button>
          <button type="button" class="layui-btn layui-btn-primary" id="postSearchReset">重置</button>
        </div>
      </div>
    </form>
  </div>
</fieldset>

<!-- 批量操作（原页面的批量条只有「批量删除」） -->
<script type="text/html" id="postToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm layui-btn-danger" lay-event="batch-delete">
      <i class="fa fa-trash-o"></i> 批量删除
    </button>
    <span class="admin-muted" id="postSelectedCount" style="margin-left:8px">已选 0 条</span>
  </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="postRowBar">
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<table class="layui-hide" id="postTable" lay-filter="postTable"></table>

<script>
layui.use(['table', 'form'], function () {
  var table = layui.table;
  var form  = layui.form;
  var $     = layui.jquery;

  var TABLE_ID  = 'postTable';
  var BRIEF_LEN = 80;    // 内容列的截断长度（原页面也是 80）
  var TITLE_LEN = 500;   // title 属性的截断长度（原页面给的是全文，这里收一收）

  /** 当前查询条件（筛选 + 排序），每次 reload 都整体交给接口 */
  var lastWhere = <?= json_encode((object)$initialWhere, $jsonFlags) ?>;

  /** HTML 转义：templet 里的内容都来自用户输入 */
  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /** 去掉 HTML 标签并按字符（码点）截断 —— 原来由 PHP 的 strip_tags + mb_substr 做 */
  function brief(s, max) {
    var text  = String(s === null || s === undefined ? '' : s).replace(/<[^>]*>/g, '').trim();
    var chars = Array.from(text);

    return chars.length > max ? chars.slice(0, max).join('') + '...' : text;
  }

  /** Unix 秒 → YYYY-MM-DD HH:mm（原来是 PHP 的 date()） */
  function fmtTime(ts) {
    ts = parseInt(ts, 10) || 0;
    if (!ts) { return '<span class="admin-muted">-</span>'; }
    var d = new Date(ts * 1000);
    var p = function (n) { return (n < 10 ? '0' : '') + n; };

    return '<span class="admin-muted">'
         + d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
         + ' ' + p(d.getHours()) + ':' + p(d.getMinutes())
         + '</span>';
  }

  table.render({
    elem: '#postTable',
    url: '/admin/api/posts',
    toolbar: '#postToolbar',
    defaultToolbar: ['filter', 'print'],
    where: lastWhere,
    // 排序完全交给服务端：layui 自带的排序只排当前页缓存，这里必须关掉
    autoSort: false,
    initSort: { field: <?= json_encode($sortField) ?>, type: <?= json_encode($sortDir) ?> },
    cols: [[
      { type: 'checkbox', width: 50 },
      { field: 'id', width: 90, title: 'ID', sort: true, templet: function (d) {
          return '<span class="admin-muted admin-num">' + (parseInt(d.id, 10) || 0) + '</span>';
      } },
      { field: 'content', minWidth: 260, title: '内容', templet: function (d) {
          var full = brief(d.content, TITLE_LEN);

          return '<span class="admin-ellipsis" title="' + esc(full) + '">'
               + esc(brief(d.content, BRIEF_LEN)) + '</span>';
      } },
      { field: 'thread_id', width: 120, title: '所属帖子', templet: function (d) {
          var tid = parseInt(d.thread_id, 10) || 0;
          if (!tid) { return '<span class="admin-muted">-</span>'; }

          return '<a href="/thread/' + tid + '" target="_blank" rel="noopener noreferrer">#' + tid + '</a>';
      } },
      { field: 'username', width: 120, title: '作者', templet: function (d) {
          var author = (d.nickname || '') !== '' ? d.nickname : d.username;

          return author ? esc(author) : '<span class="admin-muted">-</span>';
      } },
      { field: 'user_ip', width: 150, title: 'IP', templet: function (d) {
          var ip = String(d.user_ip === null || d.user_ip === undefined ? '' : d.user_ip);
          if (ip === '') { return '<span class="admin-muted">-</span>'; }

          return '<span class="admin-muted" style="font-family:ui-monospace,Menlo,monospace">'
               + esc(ip) + '</span>';
      } },
      { field: 'floor', width: 80, title: '楼层', align: 'right', templet: function (d) {
          var floor = parseInt(d.floor, 10) || 0;

          return floor ? '<span class="admin-muted admin-num">' + floor + '</span>'
                       : '<span class="admin-muted">-</span>';
      } },
      { field: 'created_at', width: 170, title: '时间', sort: true, templet: function (d) {
          return fmtTime(d.created_at);
      } },
      { title: '操作', minWidth: 110, toolbar: '#postRowBar', align: 'center' }
    ]],
    page: true,
    limit: 20,
    limits: [10, 20, 30, 50],
    skin: 'line',
    text: { none: '没有匹配的回帖，换个筛选条件试试' }
  });

  // ---------------- 勾选数量（原页面的「已选 N 条」） ----------------
  function selectedRows() {
    return table.checkStatus(TABLE_ID).data || [];
  }

  function updateSelected() {
    var $count = $('#postSelectedCount');
    if ($count.length) {
      $count.text('已选 ' + selectedRows().length + ' 条');
    }
  }

  table.on('checkbox(postTable)', function () {
    updateSelected();
  });

  // ---------------- 批量操作（接口只认 action=delete） ----------------
  table.on('toolbar(postTable)', function (obj) {
    if (obj.event !== 'batch-delete') { return; }

    var rows = selectedRows();
    if (!rows.length) {
      AdminUi.warn('请先选择要删除的回帖');
      return;
    }

    AdminUi.confirmPost(
      '确定要删除选中的回帖吗？删除后不可恢复。',
      '/admin/posts/batch',
      {
        action: 'delete',
        ids: rows.map(function (r) { return parseInt(r.id, 10) || 0; })
      },
      function () {
        table.reload(TABLE_ID);
        updateSelected();
      }
    );
  });

  // ---------------- 行内操作 ----------------
  table.on('tool(postTable)', function (obj) {
    if (obj.event !== 'delete') { return; }

    var id = parseInt(obj.data.id, 10) || 0;

    // 端点同时接受 id 与 post_id，这里沿用原页面的字段名
    AdminUi.confirmPost('确定要删除这条回帖吗？', '/admin/posts/delete', { post_id: id }, function () {
      table.reload(TABLE_ID);
      updateSelected();
    });
  });

  // ---------------- 搜索 / 重置 / 排序 ----------------
  form.on('submit(post-search)', function (data) {
    // 搜索框里没有排序字段，服务端就按默认的 id desc 排
    lastWhere = data.field;
    table.reload(TABLE_ID, {
      where: lastWhere,
      page: { curr: 1 },
      initSort: { field: 'id', type: 'desc' }
    });

    return false;   // 阻止 layui 默认的表单提交
  });

  $('#postSearchReset').on('click', function () {
    var formEl = $('form[lay-filter="postSearchForm"]')[0];
    if (formEl) { formEl.reset(); }

    lastWhere = {};
    table.reload(TABLE_ID, {
      where: lastWhere,
      page: { curr: 1 },
      initSort: { field: 'id', type: 'desc' }
    });
  });

  table.on('sort(postTable)', function (obj) {
    // 服务端排序：layui 的 sort:true 只排当前页缓存，必须自己重载并把 sort/dir 传给接口；
    // Object.assign 保留了当前筛选条件，排序不会把搜索条件丢掉
    lastWhere = Object.assign({}, lastWhere, {
      sort: obj.field,
      dir: obj.type === 'asc' ? 'asc' : 'desc'
    });

    table.reload(TABLE_ID, {
      where: lastWhere,
      page: { curr: 1 },
      initSort: obj   // 重载后重画表头，靠它把箭头标回当前列
    });
  });
});
</script>
