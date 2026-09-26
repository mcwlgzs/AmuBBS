<?php
/**
 * 后台 - 用户组管理（layuimini 子页面片段）
 *
 * 形状照 resources/views/admin/forums.php：
 *   toolbar（新增用户组）→ layui table（id = userGroupTable）→ RowBar（编辑 / 删除）
 *
 * 数据来自已有的 GET /admin/api/user-groups（一次返回全部用户组，不支持任何筛选参数），
 * 所以这里和板块管理一样**没有搜索区**；又因为 page:false 时整份数据都在前端，
 * 列上的 sort:true 是**真的**客户端排序（layui 只重排缓存、不会再发请求），因此保留。
 *
 * 增删改走 POST /admin/user-groups/save、POST /admin/user-groups/delete，
 * 表单由 layer 的 iframe 弹层打开 /admin/user-groups/form（见 partials/group_form.php）。
 *
 * 变量：$groups, $permLabels
 */

$groups     = is_array($groups ?? null) ? $groups : [];
$permLabels = is_array($permLabels ?? null) ? $permLabels : [];

/** 内置用户组（1 普通 / 2 版主 / 3 管理员）不可删除，与控制器 UserGroupController::BUILTIN_MAX_ID 一致 */
$builtinMax = 3;

/** HTML 转义：templet 里拼的是用户内容，layui 2.6 的 templet 不会自动转义 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/** 把 PHP 值安全地塞进 <script> 里的 JS 字面量 */
$js = static fn($v): string => (string)json_encode(
    $v,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>

<!-- 顶部工具栏 -->
<script type="text/html" id="userGroupToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="add">
      <i class="layui-icon layui-icon-add-1"></i> 新增用户组
    </button>
  </div>
</script>

<!-- 行内操作：内置用户组不给删除按钮 -->
<script type="text/html" id="userGroupRowBar">
  <a class="layui-btn layui-btn-xs" lay-event="edit">编辑</a>
  {{# if (d.id > <?= (int)$builtinMax ?>) { }}
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
  {{# } }}
</script>

<table class="layui-hide" id="userGroupTable" lay-filter="userGroupTable"></table>

<script>
var GROUP_PERMS = <?= $js($permLabels) ?>;

layui.use(['table', 'util'], function () {
  var table = layui.table;
  var util  = layui.util;

  var esc = function (v) { return util.escape(v == null ? '' : String(v)); };

  /** 已开启的核心权限 → 一排自己的小标签 */
  function permBadges(d) {
    var html = '';
    layui.each(GROUP_PERMS, function (field, label) {
      if (Number(d[field]) === 1) {
        html += '<span class="layui-badge layui-bg-blue">' + esc(label) + '</span> ';
      }
    });
    return html || '<span class="admin-muted">无</span>';
  }

  table.render({
    elem: '#userGroupTable',
    url: '/admin/api/user-groups',
    toolbar: '#userGroupToolbar',
    defaultToolbar: ['filter', 'print'],
    cols: [[
      { field: 'id', width: 80, title: 'ID', sort: true, templet: function (d) {
          return '<span class="admin-muted admin-num">' + (parseInt(d.id, 10) || 0) + '</span>';
        } },
      { field: 'name', minWidth: 160, title: '组名', templet: function (d) {
          var html = '<span>' + esc(d.name) + '</span>';
          if ((parseInt(d.id, 10) || 0) <= <?= (int)$builtinMax ?>) {
            html += ' <span class="layui-badge layui-bg-gray">内置</span>';
          }
          return html;
        } },
      { field: 'is_admin', width: 100, title: '管理员', align: 'center', templet: function (d) {
          return Number(d.is_admin) === 1
            ? '<span class="layui-badge layui-bg-green">是</span>'
            : '<span class="admin-muted">否</span>';
        } },
      { field: 'user_count', width: 100, title: '用户数', sort: true, align: 'right', templet: function (d) {
          return '<span class="admin-muted admin-num">' + (parseInt(d.user_count, 10) || 0) + '</span>';
        } },
      { field: 'permissions', minWidth: 300, title: '核心权限', templet: permBadges },
      { title: '操作', width: 130, toolbar: '#userGroupRowBar', align: 'center' }
    ]],
    page: false,
    limit: 200,
    text: { none: '还没有用户组，点右上角「新增用户组」创建第一个' },
    skin: 'line'
  });

  /** 打开新增 / 编辑表单（layer 的 iframe 弹层，内容是 layout_child 渲染的完整页面） */
  function openForm(id) {
    layui.layer.open({
      title: id ? '编辑用户组' : '新增用户组',
      type: 2,
      shade: 0.2,
      shadeClose: false,
      maxmin: true,
      area: ['720px', '92%'],
      content: '/admin/user-groups/form?id=' + (id || 0)
    });
  }

  // 工具栏事件
  table.on('toolbar(userGroupTable)', function (obj) {
    if (obj.event === 'add') { openForm(0); }
  });

  // 行操作事件
  table.on('tool(userGroupTable)', function (obj) {
    var d = obj.data;

    if (obj.event === 'edit') {
      openForm(parseInt(d.id, 10) || 0);
    } else if (obj.event === 'delete') {
      AdminUi.confirmPost('确定删除用户组「' + esc(d.name) + '」吗？删除后不可恢复。',
        '/admin/user-groups/delete', { id: d.id }, function () {
          table.reload('userGroupTable');
        });
    }
  });
});
</script>
