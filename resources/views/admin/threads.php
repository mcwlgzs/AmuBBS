<?php
/**
 * 后台 - 帖子管理（layuimini 子页面片段）
 * ==========================================================================
 * 由 layout_child.php 包成一个独立文档、跑在外壳的 iframe 里，
 * 所以这里是纯片段：没有文档声明，没有 html/head/body 标签，也没有任何 htmx 属性。
 *
 * 结构照 forums.php（列表页参考实现）：
 *   fieldset.table-search-fieldset  搜索区
 *   #threadToolbar                  批量操作（9 个批量动作 + 批量移动）
 *   #threadTable                    layui table，数据来自 /admin/api/threads
 *   #threadRowBar                   行内：加精/取消精、锁定/解锁、删除
 *
 * 能筛/能排的字段严格按 ThreadController::threadFilters() 来：
 *   search / username / ip / forum_id / status / date_from / date_to
 *   sort（id|views|reply_count|created_at）+ dir（asc|desc）
 * 排序是**服务端**的：layui 自带的点击排序只排当前页缓存，所以关掉 autoSort，
 * 用 sort 事件把 sort/dir 塞进 where 再重载（见下面的 sort 事件）。
 *
 * 变量：$filters, $sort, $forums（$rows/$total 已不再服务端渲染，接口会给）
 */

$filters = is_array($filters ?? null) ? $filters : [];
$sort    = is_array($sort ?? null) ? $sort : ['field' => 'id', 'dir' => 'desc'];
$forums  = is_array($forums ?? null) ? $forums : [];

$esc = static fn(string $key): string
    => htmlspecialchars((string)($filters[$key] ?? ''), ENT_QUOTES, 'UTF-8');

$curForum  = (int)($filters['forum_id'] ?? 0);
$curStatus = (string)($filters['status'] ?? '');

// 排序字段白名单，和 Thread::ADMIN_SORTS 保持一致（防止查询串里塞进乱七八糟的值）
$sortField = (string)($sort['field'] ?? 'id');
if (!in_array($sortField, ['id', 'views', 'reply_count', 'created_at'], true)) {
    $sortField = 'id';
}
$sortDir = ((string)($sort['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

/** 首屏 where：带着查询串打开（/admin/threads?search=x）时表格也要按它查 */
$initialWhere = array_filter([
    'search'    => trim((string)($filters['search'] ?? '')),
    'username'  => trim((string)($filters['username'] ?? '')),
    'ip'        => trim((string)($filters['ip'] ?? '')),
    'forum_id'  => $curForum,
    'status'    => $curStatus,
    'date_from' => trim((string)($filters['date_from'] ?? '')),
    'date_to'   => trim((string)($filters['date_to'] ?? '')),
], static fn($v): bool => $v !== '' && $v !== 0);

/** 批量移动的目标板块下拉（在弹层里现拼，交给 json_encode 转义） */
$forumOptions = [];
foreach ($forums as $forum) {
    $forumOptions[] = ['id' => (int)($forum['id'] ?? 0), 'name' => (string)($forum['name'] ?? '')];
}

// 内联脚本里的数据一律走 json_encode；HEX 系列保证 </script> 之类不会截断脚本
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<!-- 搜索（只放接口真正读的字段） -->
<fieldset class="table-search-fieldset">
  <legend>搜索信息</legend>
  <div style="margin:10px 10px 10px 10px">
    <form class="layui-form layui-form-pane" lay-filter="threadSearchForm" onsubmit="return false">
      <div class="layui-form-item">
        <div class="layui-inline">
          <label class="layui-form-label">标题</label>
          <div class="layui-input-inline">
            <input type="text" name="search" class="layui-input" placeholder="搜索标题"
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
          <label class="layui-form-label">IP</label>
          <div class="layui-input-inline">
            <input type="text" name="ip" class="layui-input" placeholder="IP 地址"
                   value="<?= $esc('ip') ?>">
          </div>
        </div>

        <div class="layui-inline">
          <label class="layui-form-label">板块</label>
          <div class="layui-input-inline">
            <select name="forum_id">
              <option value="0">全部板块</option>
              <?php foreach ($forums as $forum): ?>
                <?php $fid = (int)($forum['id'] ?? 0); ?>
                <option value="<?= $fid ?>" <?= $fid === $curForum && $curForum > 0 ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string)($forum['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="layui-inline">
          <label class="layui-form-label">状态</label>
          <div class="layui-input-inline">
            <select name="status">
              <option value="">全部状态</option>
              <option value="top" <?= $curStatus === 'top' ? 'selected' : '' ?>>置顶</option>
              <option value="highlight" <?= $curStatus === 'highlight' ? 'selected' : '' ?>>精华</option>
              <option value="locked" <?= $curStatus === 'locked' ? 'selected' : '' ?>>锁定</option>
            </select>
          </div>
        </div>

        <div class="layui-inline">
          <label class="layui-form-label">发布起止</label>
          <div class="layui-input-inline" style="width:140px">
            <input type="date" name="date_from" class="layui-input" value="<?= $esc('date_from') ?>">
          </div>
          <div class="layui-input-inline" style="width:140px">
            <input type="date" name="date_to" class="layui-input" value="<?= $esc('date_to') ?>">
          </div>
        </div>

        <div class="layui-inline">
          <button class="layui-btn layui-btn-primary" lay-submit lay-filter="thread-search">
            <i class="layui-icon layui-icon-search"></i> 搜索
          </button>
          <button type="button" class="layui-btn layui-btn-primary" id="threadSearchReset">重置</button>
        </div>
      </div>
    </form>
  </div>
</fieldset>

<!-- 批量操作（勾选后点这里；全选由 layui 的 checkbox 列负责） -->
<script type="text/html" id="threadToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm layui-btn-danger" lay-event="batch-delete">
      <i class="fa fa-trash-o"></i> 批量删除
    </button>
    <button class="layui-btn layui-btn-sm layui-btn-primary" lay-event="batch-lock">
      <i class="fa fa-lock"></i> 批量锁定
    </button>
    <button class="layui-btn layui-btn-sm layui-btn-primary" lay-event="batch-unlock">
      <i class="fa fa-unlock"></i> 批量解锁
    </button>
    <button class="layui-btn layui-btn-sm layui-btn-primary" lay-event="batch-top-1">板块置顶</button>
    <button class="layui-btn layui-btn-sm layui-btn-primary" lay-event="batch-top-2">全局置顶</button>
    <button class="layui-btn layui-btn-sm layui-btn-primary" lay-event="batch-top-0">取消置顶</button>
    <button class="layui-btn layui-btn-sm" lay-event="batch-highlight">
      <i class="fa fa-star"></i> 加精
    </button>
    <button class="layui-btn layui-btn-sm layui-btn-primary" lay-event="batch-unhighlight">取消加精</button>
    <button class="layui-btn layui-btn-sm layui-btn-primary" lay-event="batch-move">
      <i class="fa fa-arrows-h"></i> 批量移动
    </button>
    <span class="admin-muted" id="threadSelectedCount" style="margin-left:8px">已选 0 篇</span>
  </div>
</script>

<!-- 行内操作（模板里能拿到整行数据 d，所以按钮文案跟着状态变） -->
<script type="text/html" id="threadRowBar">
  {{# if(parseInt(d.is_highlight, 10) > 0){ }}
  <a class="layui-btn layui-btn-xs layui-btn-primary" lay-event="unhighlight">取消精</a>
  {{# } else { }}
  <a class="layui-btn layui-btn-xs" lay-event="highlight">加精</a>
  {{# } }}
  {{# if(parseInt(d.is_locked, 10) === 1){ }}
  <a class="layui-btn layui-btn-xs layui-btn-primary" lay-event="unlock">解锁</a>
  {{# } else { }}
  <a class="layui-btn layui-btn-xs layui-btn-primary" lay-event="lock">锁定</a>
  {{# } }}
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<table class="layui-hide" id="threadTable" lay-filter="threadTable"></table>

<script>
layui.use(['table', 'form'], function () {
  var table = layui.table;
  var form  = layui.form;
  var $     = layui.jquery;

  var TABLE_ID = 'threadTable';

  /** 当前查询条件（筛选 + 排序），每次 reload 都整体交给接口 */
  var lastWhere    = <?= json_encode((object)$initialWhere, $jsonFlags) ?>;
  var forumOptions = <?= json_encode($forumOptions, $jsonFlags) ?>;

  /** HTML 转义：templet 里的内容都来自用户输入 */
  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /** 千分位（原来是 PHP 的 number_format） */
  function num(n) {
    return (parseInt(n, 10) || 0).toLocaleString('en-US');
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
    elem: '#threadTable',
    url: '/admin/api/threads',
    toolbar: '#threadToolbar',
    defaultToolbar: ['filter', 'print'],
    where: lastWhere,
    // 排序完全交给服务端：layui 自带的排序只排当前页缓存，这里必须关掉
    autoSort: false,
    initSort: { field: <?= json_encode($sortField) ?>, type: <?= json_encode($sortDir) ?> },
    cols: [[
      { type: 'checkbox', width: 50 },
      { field: 'id', width: 80, title: 'ID', sort: true, templet: function (d) {
          return '<span class="admin-muted admin-num">' + (parseInt(d.id, 10) || 0) + '</span>';
      } },
      { field: 'title', minWidth: 220, title: '标题', templet: function (d) {
          var title = esc(d.title);
          var id    = parseInt(d.id, 10) || 0;

          return '<a class="admin-ellipsis" href="/thread/' + id + '" target="_blank"'
               + ' rel="noopener noreferrer" title="' + title + '">' + title + '</a>';
      } },
      { field: 'forum_name', width: 130, title: '板块', templet: function (d) {
          return d.forum_name
              ? '<span class="admin-muted">' + esc(d.forum_name) + '</span>'
              : '<span class="admin-muted">-</span>';
      } },
      { field: 'username', width: 110, title: '作者', templet: function (d) {
          var author = (d.nickname || '') !== '' ? d.nickname : d.username;

          return author ? esc(author) : '<span class="admin-muted">-</span>';
      } },
      { field: 'views', width: 100, title: '浏览', sort: true, align: 'right', templet: function (d) {
          return '<span class="admin-muted admin-num">' + num(d.views) + '</span>';
      } },
      { field: 'reply_count', width: 100, title: '回复', sort: true, align: 'right', templet: function (d) {
          return '<span class="admin-muted admin-num">' + num(d.reply_count) + '</span>';
      } },
      { field: 'is_top', width: 160, title: '状态', templet: function (d) {
          var top  = parseInt(d.is_top, 10) || 0;
          var html = '';

          if (top === 2) { html += '<span class="layui-badge">全局顶</span> '; }
          if (top === 1) { html += '<span class="admin-tag admin-tag-top">置顶</span> '; }
          if (parseInt(d.is_highlight, 10) > 0) { html += '<span class="admin-tag admin-tag-highlight">精华</span> '; }
          if (parseInt(d.is_locked, 10) === 1) { html += '<span class="admin-tag admin-tag-locked">锁定</span> '; }

          return html || '<span class="admin-muted">—</span>';
      } },
      { field: 'created_at', width: 170, title: '发布时间', sort: true, templet: function (d) {
          return fmtTime(d.created_at);
      } },
      // 原页面这一列是每行的置顶下拉（普通/板块顶/全局顶），照旧保留：
      // 单元格里放一个原生 select，change 时提交 /admin/threads/toggle-top
      { field: 'top_level', width: 120, title: '置顶', align: 'center', templet: function (d) {
          var top = parseInt(d.is_top, 10) || 0;
          var id  = parseInt(d.id, 10) || 0;
          var sel = function (v) { return top === v ? ' selected' : ''; };

          return '<select class="layui-input thread-top-select" lay-ignore'
               + ' data-id="' + id + '" aria-label="置顶级别"'
               + ' style="height:28px;padding:0 4px;line-height:28px">'
               + '<option value="0"' + sel(0) + '>普通</option>'
               + '<option value="1"' + sel(1) + '>板块顶</option>'
               + '<option value="2"' + sel(2) + '>全局顶</option>'
               + '</select>';
      } },
      { title: '操作', minWidth: 190, toolbar: '#threadRowBar', align: 'center' }
    ]],
    page: true,
    limit: 20,
    limits: [10, 20, 30, 50],
    skin: 'line',
    text: { none: '没有匹配的帖子，换个筛选条件试试' }
  });

  // ---------------- 勾选数量（原页面的「已选 N 篇」） ----------------
  function selectedRows() {
    return table.checkStatus(TABLE_ID).data || [];
  }

  function updateSelected() {
    var $count = $('#threadSelectedCount');
    if ($count.length) {
      $count.text('已选 ' + selectedRows().length + ' 篇');
    }
  }

  table.on('checkbox(threadTable)', function () {
    updateSelected();
  });

  // ---------------- 批量操作 ----------------
  /** 组装批量接口的入参；没勾选时给个提示并返回 null */
  function batchPayload(action, extra) {
    var rows = selectedRows();
    if (!rows.length) {
      AdminUi.warn('请先选择要操作的帖子');
      return null;
    }

    var data = { action: action, ids: rows.map(function (r) { return parseInt(r.id, 10) || 0; }) };

    return $.extend(data, extra || {});
  }

  function batchRun(action, extra, confirmText) {
    var data = batchPayload(action, extra);
    if (!data) { return; }

    var done = function () {
      table.reload(TABLE_ID);
      updateSelected();
    };

    if (confirmText) {
      AdminUi.confirmPost(confirmText, '/admin/threads/batch', data, done);
    } else {
      AdminUi.post('/admin/threads/batch', data, done);
    }
  }

  /** 批量移动：原来的 Bootstrap modal 换成 layer 弹层里的 target_forum_id 下拉 */
  function openMove() {
    var data = batchPayload('move');
    if (!data) { return; }

    var options = '';
    for (var i = 0; i < forumOptions.length; i++) {
      options += '<option value="' + forumOptions[i].id + '">' + esc(forumOptions[i].name) + '</option>';
    }

    var html = '<form class="layui-form" lay-filter="threadMoveForm" style="padding:20px 24px 0" onsubmit="return false">'
             + '<div class="layui-form-item">'
             + '<label class="layui-form-label">目标板块</label>'
             + '<div class="layui-input-block">'
             + '<select name="target_forum_id" id="threadMoveTarget">'
             + '<option value="">请选择...</option>' + options
             + '</select></div></div></form>';

    layui.layer.open({
      type: 1,
      title: '移动帖子到板块',
      area: ['420px', '240px'],
      shade: 0.2,
      shadeClose: false,
      content: html,
      btn: ['确认移动', '取消'],
      success: function () {
        form.render('select', 'threadMoveForm');
      },
      yes: function (index) {
        var target = parseInt($('#threadMoveTarget').val(), 10) || 0;
        if (target <= 0) {
          AdminUi.warn('请选择目标板块');
          return false;   // 不关弹层，让用户继续选
        }

        AdminUi.post('/admin/threads/batch', $.extend({}, data, { target_forum_id: target }), function () {
          layui.layer.close(index);
          table.reload(TABLE_ID);
          updateSelected();
        });
      }
    });
  }

  table.on('toolbar(threadTable)', function (obj) {
    switch (obj.event) {
      case 'batch-delete':
        batchRun('delete', null, '确定要批量删除选中的帖子吗？删除后不可恢复。');
        break;
      case 'batch-lock':
        batchRun('lock');
        break;
      case 'batch-unlock':
        batchRun('unlock');
        break;
      case 'batch-top-1':
        batchRun('top', { level: 1 });
        break;
      case 'batch-top-2':
        batchRun('top', { level: 2 });
        break;
      case 'batch-top-0':
        batchRun('top', { level: 0 });
        break;
      case 'batch-highlight':
        batchRun('highlight');
        break;
      case 'batch-unhighlight':
        batchRun('unhighlight');
        break;
      case 'batch-move':
        openMove();
        break;
    }
  });

  // ---------------- 行内操作 ----------------
  table.on('tool(threadTable)', function (obj) {
    var id = parseInt(obj.data.id, 10) || 0;

    if (obj.event === 'highlight' || obj.event === 'unhighlight') {
      // 接口不给 level 时按当前状态取反，所以两个按钮同一个端点
      AdminUi.post('/admin/threads/toggle-highlight', { thread_id: id }, function () {
        table.reload(TABLE_ID);
      });
    } else if (obj.event === 'lock' || obj.event === 'unlock') {
      AdminUi.post('/admin/threads/batch', { action: obj.event, ids: [id] }, function () {
        table.reload(TABLE_ID);
      });
    } else if (obj.event === 'delete') {
      AdminUi.confirmPost('确定要删除这篇帖子吗？', '/admin/threads/delete', { thread_id: id }, function () {
        table.reload(TABLE_ID);
        updateSelected();
      });
    }
  });

  // ---------------- 行内「置顶」下拉（templet 里渲染的 select） ----------------
  $(document).on('change', '.thread-top-select', function () {
    var $sel  = $(this);
    var id    = parseInt($sel.attr('data-id'), 10) || 0;
    var level = parseInt($sel.val(), 10) || 0;

    if (!id) { return; }

    AdminUi.post('/admin/threads/toggle-top', { thread_id: id, level: level }, function () {
      table.reload(TABLE_ID);
    });
  });

  // ---------------- 搜索 / 重置 / 排序 ----------------
  form.on('submit(thread-search)', function (data) {
    // 搜索框里没有排序字段，服务端就按默认的 id desc 排
    lastWhere = data.field;
    table.reload(TABLE_ID, {
      where: lastWhere,
      page: { curr: 1 },
      initSort: { field: 'id', type: 'desc' }
    });

    return false;   // 阻止 layui 默认的表单提交
  });

  $('#threadSearchReset').on('click', function () {
    var formEl = $('form[lay-filter="threadSearchForm"]')[0];
    if (formEl) { formEl.reset(); }
    form.render('select', 'threadSearchForm');   // 让 layui 渲染的下拉也回到「全部」

    lastWhere = {};
    table.reload(TABLE_ID, {
      where: lastWhere,
      page: { curr: 1 },
      initSort: { field: 'id', type: 'desc' }
    });
  });

  table.on('sort(threadTable)', function (obj) {
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
