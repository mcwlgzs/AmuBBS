<?php
/**
 * 后台 - 等级设置（layuimini 子页面片段）
 *
 * 形状照 resources/views/admin/forums.php：
 *   toolbar（添加等级）→ layui table（id = levelTable）→ RowBar（编辑 / 删除）
 *
 * 数据来自已有的 GET /admin/api/levels（一次返回全部等级，按 level 升序，不支持任何筛选参数），
 * 所以这里没有搜索区；又因为 page:false 时整份数据都在前端，列上的 sort:true 是**真的**
 * 客户端排序（layui 只重排缓存、不会再发请求），不是摆设，因此保留。
 *
 * 增删改走 POST /admin/levels/save、POST /admin/levels/delete，
 * 表单由 layer 的 iframe 弹层打开 /admin/levels/form（见 partials/level_form.php）。
 *
 * 变量：$rows
 */

$rows = is_array($rows ?? null) ? $rows : [];

/** HTML 转义：templet 里拼的是管理员录入的内容，layui 2.6 的 templet 不会自动转义 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>

<!-- 顶部工具栏 -->
<script type="text/html" id="levelToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="add">
      <i class="layui-icon layui-icon-add-1"></i> 添加等级
    </button>
  </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="levelRowBar">
  <a class="layui-btn layui-btn-xs" lay-event="edit">编辑</a>
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<table class="layui-hide" id="levelTable" lay-filter="levelTable"></table>

<script>
layui.use(['table', 'util'], function () {
  var table = layui.table;
  var util  = layui.util;

  var esc = function (v) { return util.escape(v == null ? '' : String(v)); };

  /** 等级颜色：非法/为空时回落到默认灰，避免拼出坏样式 */
  function levelColor(d) {
    var c = (d.color == null ? '' : String(d.color)).trim();
    return /^(#[0-9a-fA-F]{3,6}|var\(--[a-zA-Z0-9_-]+\))$/.test(c) ? c : '#999999';
  }

  table.render({
    elem: '#levelTable',
    url: '/admin/api/levels',
    toolbar: '#levelToolbar',
    defaultToolbar: ['filter', 'print'],
    cols: [[
      { field: 'id', width: 80, title: 'ID', sort: true, templet: function (d) {
          return '<span class="admin-muted admin-num">' + (parseInt(d.id, 10) || 0) + '</span>';
        } },
      { field: 'level', width: 90, title: '等级', sort: true, align: 'center', templet: function (d) {
          return '<span class="layui-badge layui-bg-gray">Lv' + (parseInt(d.level, 10) || 0) + '</span>';
        } },
      { field: 'name', width: 150, title: '名称', templet: function (d) {
          return esc(d.name);
        } },
      { field: 'min_credits', width: 120, title: '所需积分', sort: true, align: 'right', templet: function (d) {
          return '<span class="admin-muted admin-num">' + (parseInt(d.min_credits, 10) || 0) + '</span>';
        } },
      { field: 'color', width: 160, title: '颜色', templet: function (d) {
          var c = levelColor(d);
          return '<span style="display:inline-block;width:16px;height:16px;border-radius:3px;'
            + 'vertical-align:middle;background:' + esc(c) + '"></span>'
            + '<code style="margin-left:6px">' + esc(c) + '</code>';
        } },
      { field: 'icon', width: 80, title: '图标', align: 'center', templet: function (d) {
          return d.icon ? '<span style="font-size:18px">' + esc(d.icon) + '</span>'
                        : '<span class="admin-muted">-</span>';
        } },
      { field: 'preview', minWidth: 180, title: '预览', templet: function (d) {
          var c = levelColor(d);
          return '<span style="background:' + esc(c) + '22;color:' + esc(c) + ';border:1px solid '
            + esc(c) + ';padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;white-space:nowrap">'
            + 'Lv' + (parseInt(d.level, 10) || 0) + ' ' + esc(d.name) + '</span>';
        } },
      { title: '操作', width: 130, toolbar: '#levelRowBar', align: 'center' }
    ]],
    page: false,
    limit: 200,
    text: { none: '还没有等级，点右上角「添加等级」创建第一个' },
    skin: 'line'
  });

  /** 打开新增 / 编辑表单（layer 的 iframe 弹层，内容是 layout_child 渲染的完整页面） */
  function openForm(id) {
    layui.layer.open({
      title: id ? '编辑等级' : '添加等级',
      type: 2,
      shade: 0.2,
      shadeClose: false,
      maxmin: true,
      area: ['720px', '92%'],
      content: '/admin/levels/form?id=' + (id || 0)
    });
  }

  // 工具栏事件
  table.on('toolbar(levelTable)', function (obj) {
    if (obj.event === 'add') { openForm(0); }
  });

  // 行操作事件
  table.on('tool(levelTable)', function (obj) {
    var d = obj.data;

    if (obj.event === 'edit') {
      openForm(parseInt(d.id, 10) || 0);
    } else if (obj.event === 'delete') {
      AdminUi.confirmPost('确定删除等级「' + esc(d.name) + '」吗？删除后不可恢复。',
        '/admin/levels/delete', { id: d.id }, function () {
          table.reload('levelTable');
        });
    }
  });
});
</script>
