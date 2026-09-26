<?php
/**
 * 后台 - 用户管理（layuimini 子页面片段）
 *
 * 形状照 resources/views/admin/forums.php：
 *   搜索 fieldset（只放 /admin/api/users 真正会读的筛选参数）
 *   → toolbar（新增 / 批量封禁 / 批量解封 / 批量修改用户组 / 批量删除）
 *   → layui table（服务端分层取数，表 id = userTable）
 *   → RowBar（每行的「管理」按钮）
 *
 * 数据来自已有的 GET /admin/api/users（支持 search / uid / group_id / ip / sort / dir / page / limit），
 * 增删改走 POST /admin/users/update、POST /admin/users/batch，统一由 AdminUi.post() 带 CSRF 并处理 {code,msg}。
 *
 * 排序是**服务端**的：
 *   - autoSort:false —— layui 默认只把当前页缓存重排一遍，不会重新请求，这里必须是 false；
 *   - 点击表头触发 table.on('sort(...)')，把列字段名映射成 User::adminQuery() 白名单里的 sort 值
 *     （列 thread_count → API threads），再带 dir=asc|desc 重载；
 *   - table.reload 的 where 是**整体替换**（$.extend 浅合并），所以每次重载都传全量条件，
 *     否则排序一下筛选条件就丢了。
 *
 * 变量：$groups, $users, $total, $page, $pages, $limit, $filters, $sort, $dir,
 *       $adminGroupId, $currentUserId
 */

$groups        = is_array($groups ?? null) ? $groups : [];
$filters       = is_array($filters ?? null) ? $filters : [];
$limit         = (int)($limit ?? 20);
// 分页下拉只提供 10/20/30/50；控制器允许 ?limit=37 这种值，落回 20 免得下拉显示空白
$limit         = in_array($limit, [10, 20, 30, 50], true) ? $limit : 20;
$sort          = (string)($sort ?? 'id');
$dir           = strtolower((string)($dir ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$adminGroupId  = (int)($adminGroupId ?? 3);
$currentUserId = (int)($currentUserId ?? 0);

/** HTML 转义：表格 templet 里拼的是用户内容，layui 2.6 的 templet 不会自动转义，必须自己来 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/** 把 PHP 值安全地塞进 <script> 里的 JS 字面量 */
$js = static fn($v): string => (string)json_encode(
    $v,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$fSearch  = (string)($filters['search'] ?? '');
$fUid     = (string)($filters['uid'] ?? '');
$fGroupId = (string)($filters['group_id'] ?? '');
$fIp      = (string)($filters['ip'] ?? '');

/** 批量「修改用户组」弹层里的下拉选项（id + 名称） */
$groupOptions = [];
foreach ($groups as $g) {
    $groupOptions[] = [
        'id'   => (int)($g['id'] ?? 0),
        'name' => (string)($g['name'] ?? ''),
    ];
}
?>

<!-- 搜索：只放 API 真正读取的筛选参数（search / uid / group_id / ip） -->
<fieldset class="layui-elem-field layui-field-title table-search-fieldset">
  <legend>筛选</legend>
  <div class="layui-form" id="userSearchForm">
    <div class="layui-form-item">
      <div class="layui-inline">
        <label class="layui-form-label">用户名</label>
        <div class="layui-input-inline">
          <input type="text" id="userSearchKey" class="layui-input"
                 placeholder="用户名 / 昵称 / 邮箱" value="<?= $e($fSearch) ?>">
        </div>
      </div>

      <div class="layui-inline">
        <label class="layui-form-label">UID</label>
        <div class="layui-input-inline">
          <input type="number" id="userSearchUid" class="layui-input" min="1"
                 placeholder="用户 ID" value="<?= $e($fUid) ?>">
        </div>
      </div>

      <div class="layui-inline">
        <label class="layui-form-label">用户组</label>
        <div class="layui-input-inline">
          <select id="userSearchGroup">
            <option value="">全部</option>
            <?php foreach ($groups as $g): ?>
              <?php $gid = (int)($g['id'] ?? 0); ?>
              <option value="<?= $gid ?>" <?= $fGroupId === (string)$gid ? 'selected' : '' ?>>
                <?= $e($g['name'] ?? '') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="layui-inline">
        <label class="layui-form-label">IP</label>
        <div class="layui-input-inline">
          <input type="text" id="userSearchIp" class="layui-input"
                 placeholder="登录 / 注册 IP" value="<?= $e($fIp) ?>">
        </div>
      </div>

      <div class="layui-inline">
        <button type="button" class="layui-btn" id="userSearchBtn">
          <i class="layui-icon layui-icon-search"></i> 搜索
        </button>
        <button type="button" class="layui-btn layui-btn-primary" id="userSearchReset">重置</button>
      </div>
    </div>
  </div>
</fieldset>

<!-- 顶部工具栏：新增 + 批量操作 -->
<script type="text/html" id="userToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="add">
      <i class="layui-icon layui-icon-add-1"></i> 添加用户
    </button>
    <button class="layui-btn layui-btn-sm layui-btn-warm" lay-event="ban">批量封禁</button>
    <button class="layui-btn layui-btn-sm layui-btn-normal" lay-event="unban">批量解封</button>
    <button class="layui-btn layui-btn-sm layui-btn-primary" lay-event="change_group">批量修改用户组</button>
    <button class="layui-btn layui-btn-sm layui-btn-danger" lay-event="delete">批量删除</button>
  </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="userRowBar">
  <a class="layui-btn layui-btn-xs" lay-event="manage">管理</a>
</script>

<table class="layui-hide" id="userTable" lay-filter="userTable"></table>

<script>
var USER_GROUPS      = <?= $js($groupOptions) ?>;
var USER_ADMIN_GROUP = <?= $js($adminGroupId) ?>;
var USER_SELF_ID     = <?= $js($currentUserId) ?>;

layui.use(['table', 'form', 'util'], function () {
  var table = layui.table;
  var form  = layui.form;
  var util  = layui.util;
  var $     = layui.jquery;

  var esc = function (v) { return util.escape(v == null ? '' : String(v)); };

  /** 时间戳（秒）→ YYYY-MM-DD */
  function fmtDate(ts) {
    ts = parseInt(ts, 10) || 0;
    if (ts <= 0) { return '-'; }
    var d = new Date(ts * 1000);
    var z = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate());
  }

  /** 用户组徽章：管理员 / 版主 用固定色，其余用组名 */
  function groupBadge(d) {
    var gid = parseInt(d.group_id, 10) || 0;
    if (gid === USER_ADMIN_GROUP) { return '<span class="layui-badge">管理员</span>'; }
    if (gid === 2) { return '<span class="layui-badge layui-bg-blue">版主</span>'; }
    return '<span class="layui-badge layui-bg-gray">' + esc(d.group_name || '普通用户') + '</span>';
  }

  /** 列字段名 → 后端 User::adminQuery() 白名单里的 sort 值（只有可排序列在这里） */
  var COL_TO_API = {
    id: 'id',
    username: 'username',
    credits: 'credits',
    thread_count: 'threads',
    created_at: 'created_at'
  };

  /** 后端 sort 值 → 列字段名（给 initSort 认表头用，posts 和 threads 共用同一列） */
  var API_TO_COL = {
    id: 'id',
    username: 'username',
    credits: 'credits',
    threads: 'thread_count',
    posts: 'thread_count',
    created_at: 'created_at'
  };

  // 当前筛选条件（不含排序）：每次重载都要给全量，layui 的 where 是整体替换
  var baseWhere = {
    search: <?= $js($fSearch) ?>,
    uid: <?= $js($fUid) ?>,
    group_id: <?= $js($fGroupId) ?>,
    ip: <?= $js($fIp) ?>
  };

  // 当前排序：sortApi 是后端认的名字，sortCol 是表头列名
  var sortApi = <?= $js($sort) ?>;
  var sortDir = <?= $js($dir) ?>;
  var sortCol = API_TO_COL[sortApi] || 'id';

  function whereAll() {
    var w = {};
    for (var k in baseWhere) { w[k] = baseWhere[k]; }
    w.sort = sortApi;
    w.dir  = sortDir;
    return w;
  }

  table.render({
    elem: '#userTable',
    url: '/admin/api/users',
    toolbar: '#userToolbar',
    defaultToolbar: ['filter', 'print'],
    autoSort: false,
    initSort: { field: sortCol, type: sortDir },
    where: whereAll(),
    page: true,
    limit: <?= $limit ?>,
    limits: [10, 20, 30, 50],
    cols: [[
      { type: 'checkbox', fixed: 'left' },
      { field: 'id', width: 80, title: 'ID', sort: true, templet: function (d) {
          return '<span class="admin-muted admin-num">' + (parseInt(d.id, 10) || 0) + '</span>';
        } },
      { field: 'username', minWidth: 150, title: '用户名', sort: true, templet: function (d) {
          var uid = parseInt(d.id, 10) || 0;
          var html = '<a href="/user/' + uid + '" target="_blank" rel="noopener">' + esc(d.username) + '</a>';
          if (uid === USER_SELF_ID) {
            html += ' <span class="layui-badge layui-bg-gray">自己</span>';
          }
          return html;
        } },
      { field: 'nickname', width: 120, title: '昵称', templet: function (d) {
          return d.nickname ? esc(d.nickname) : '<span class="admin-muted">未设置</span>';
        } },
      { field: 'email', minWidth: 180, title: '邮箱', templet: function (d) {
          return '<span class="admin-ellipsis" title="' + esc(d.email) + '">' + esc(d.email) + '</span>';
        } },
      { field: 'group_id', width: 110, title: '用户组', align: 'center', templet: groupBadge },
      { field: 'credits', width: 100, title: '积分', sort: true, align: 'right', templet: function (d) {
          return '<span class="admin-muted admin-num">' + (parseInt(d.credits, 10) || 0) + '</span>';
        } },
      { field: 'thread_count', width: 130, title: '帖子 / 回复', sort: true, align: 'right', templet: function (d) {
          return '<span class="admin-muted admin-num">'
            + (parseInt(d.thread_count, 10) || 0) + ' / ' + (parseInt(d.post_count, 10) || 0) + '</span>';
        } },
      { field: 'created_at', width: 120, title: '注册时间', sort: true, templet: function (d) {
          return '<span class="admin-muted">' + fmtDate(d.created_at) + '</span>';
        } },
      { title: '操作', width: 90, toolbar: '#userRowBar', align: 'center', fixed: 'right' }
    ]],
    text: { none: '没有匹配的用户，换个筛选条件试试' },
    skin: 'line'
  });

  /** 换筛选条件后回到第 1 页 */
  function doSearch() {
    baseWhere = {
      search: $('#userSearchKey').val(),
      uid: $('#userSearchUid').val(),
      group_id: $('#userSearchGroup').val(),
      ip: $('#userSearchIp').val()
    };
    table.reload('userTable', { where: whereAll(), page: { curr: 1 } });
  }

  $('#userSearchBtn').on('click', doSearch);

  $('#userSearchReset').on('click', function () {
    $('#userSearchKey').val('');
    $('#userSearchUid').val('');
    $('#userSearchIp').val('');
    $('#userSearchGroup').val('');
    form.render('select');
    doSearch();
  });

  // 回车即搜索
  $('#userSearchForm').on('keydown', 'input', function (ev) {
    if (ev.keyCode === 13) { doSearch(); return false; }
  });

  // 排序：列字段名 → 后端 sort/dir，并带上全量筛选条件
  table.on('sort(userTable)', function (obj) {
    sortApi = COL_TO_API[obj.field] || 'id';
    sortDir = obj.type === 'asc' ? 'asc' : 'desc';
    sortCol = obj.field;
    table.reload('userTable', {
      initSort: { field: sortCol, type: sortDir },
      where: whereAll(),
      page: { curr: 1 }
    });
  });

  /** 打开新增 / 管理表单（layer 的 iframe 弹层，内容是 layout_child 渲染的完整页面） */
  function openForm(url, title, area) {
    layui.layer.open({
      title: title,
      type: 2,
      shade: 0.2,
      shadeClose: false,
      maxmin: true,
      area: area,
      content: url
    });
  }

  /** 批量操作：/admin/users/batch 认 action + ids[] + group_id */
  function batch(action, ids, groupId) {
    var data = { action: action, ids: ids };
    if (groupId) { data.group_id = groupId; }
    AdminUi.post('/admin/users/batch', data, function () {
      table.reload('userTable');
    });
  }

  var BATCH_CONFIRM = {
    ban: '确定批量封禁选中的 %n 个用户吗？',
    unban: '确定批量解封选中的 %n 个用户吗？',
    change_group: '确定把选中的 %n 个用户改成该用户组吗？',
    delete: '确定批量删除选中的 %n 个用户吗？此操作不可恢复！'
  };

  /** 「批量修改用户组」：用一个 layer 让管理员选目标用户组 */
  function openGroupPicker(ids) {
    var opts = '';
    layui.each(USER_GROUPS, function (_, g) {
      opts += '<option value="' + g.id + '">' + esc(g.name) + '</option>';
    });

    layui.layer.open({
      type: 1,
      title: '批量修改用户组',
      area: ['360px', 'auto'],
      content: '<div class="layui-form" style="padding:18px 20px">'
        + '<select id="userBatchGroup">' + opts + '</select>'
        + '</div>',
      btn: ['确定', '取消'],
      success: function () { form.render('select'); },
      yes: function (idx) {
        var gid = parseInt($('#userBatchGroup').val(), 10) || 0;
        layui.layer.close(idx);
        if (!gid) { AdminUi.warn('请选择用户组'); return; }
        layui.layer.confirm(
          BATCH_CONFIRM.change_group.replace('%n', ids.length),
          function (i) {
            layui.layer.close(i);
            batch('change_group', ids, gid);
          }
        );
      }
    });
  }

  // 工具栏事件（批量动作靠 table.checkStatus，不手写全选逻辑）
  table.on('toolbar(userTable)', function (obj) {
    if (obj.event === 'add') {
      openForm('/admin/users/form?action=add', '添加用户', ['700px', '92%']);
      return;
    }

    var ids = [];
    layui.each(table.checkStatus('userTable').data, function (_, row) {
      ids.push(parseInt(row.id, 10) || 0);
    });

    if (!ids.length) {
      AdminUi.warn('请先勾选要操作的用户');
      return;
    }

    if (obj.event === 'change_group') {
      openGroupPicker(ids);
      return;
    }

    if (!BATCH_CONFIRM[obj.event]) { return; }

    AdminUi.confirmPost(
      BATCH_CONFIRM[obj.event].replace('%n', ids.length),
      '/admin/users/batch',
      { action: obj.event, ids: ids },
      function () { table.reload('userTable'); }
    );
  });

  // 行操作：管理面板
  table.on('tool(userTable)', function (obj) {
    if (obj.event === 'manage') {
      openForm('/admin/users/form?action=manage&id=' + (parseInt(obj.data.id, 10) || 0),
        '管理用户', ['860px', '92%']);
    }
  });
});
</script>
