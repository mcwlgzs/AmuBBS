<?php
/**
 * 后台 - 板块管理（layuimini 子页面）
 *
 * 这是「列表页」的参考实现，其余列表页照这个形状写：
 *
 *   <fieldset class="table-search-fieldset">  搜索区（layui form，按需）
 *   <script type="text/html" id="xxxToolbar"> 顶部工具栏（新增等）
 *   <table class="layui-hide" id="xxxTable">  表格占位
 *   <script type="text/html" id="xxxRowBar">  行内操作按钮
 *   layui.use(['table','form']) → table.render({url:'/admin/api/xxx', cols:[...]})
 *
 * 数据全部来自已有的 /admin/api/*（返回 layui 表格约定的 {code,msg,data,count}），
 * 增删改走 /admin/forums/*，由 AdminUi.post() 统一带 CSRF 并处理 {code,msg}。
 *
 * 板块数量少且 API 不支持按名称过滤，所以这里**没有**搜索区 ——
 * 加一个点了没用的搜索框比没有更糟。其余列表页（帖子/用户/回帖…）的
 * /admin/api/* 支持筛选参数，照它们自己的筛选项写 layui 表单即可。
 */
?>

<!-- 顶部工具栏 -->
<script type="text/html" id="forumToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="add">
      <i class="layui-icon layui-icon-add-1"></i> 新增板块
    </button>
  </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="forumRowBar">
  <a class="layui-btn layui-btn-xs" lay-event="edit">编辑</a>
  <a class="layui-btn layui-btn-xs layui-btn-normal" lay-event="access">权限</a>
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<table class="layui-hide" id="forumTable" lay-filter="forumTable"></table>

<script>
layui.use(['table', 'form'], function () {
  var table = layui.table;
  var $ = layui.jquery;

  table.render({
    elem: '#forumTable',
    url: '/admin/api/forums',
    toolbar: '#forumToolbar',
    defaultToolbar: ['filter', 'print'],
    cols: [[
      { field: 'id', width: 70, title: 'ID', sort: true },
      { field: 'name', minWidth: 160, title: '板块名称' },
      { field: 'description', minWidth: 200, title: '描述' },
      { field: 'parent_id', width: 90, title: '上级', align: 'center', templet: function (d) {
          return d.parent_id > 0 ? d.parent_id : '-';
        } },
      { field: 'rank', width: 80, title: '排序', sort: true, align: 'center' },
      { field: 'moderators_display', width: 130, title: '版主', templet: function (d) {
          return d.moderators_display || '<span class="admin-muted">-</span>';
        } },
      { field: 'thread_count', width: 120, title: '帖子/回复', align: 'center', templet: function (d) {
          return '<span class="admin-num">' + d.thread_count + ' / ' + d.post_count + '</span>';
        } },
      { title: '操作', minWidth: 170, toolbar: '#forumRowBar', align: 'center' }
    ]],
    page: false,
    limit: 200,
    text: { none: '还没有板块，点右上角「新增板块」创建第一个' },
    skin: 'line'
  });

  /** 打开新增 / 编辑表单（layer 的 iframe 弹层，内容是 layout_child 渲染的完整页面） */
  function openForm(id) {
    layui.layer.open({
      title: id ? '编辑板块' : '新增板块',
      type: 2,
      shade: 0.2,
      shadeClose: false,
      maxmin: true,
      area: ['780px', '92%'],
      content: '/admin/forums/form?id=' + (id || 0)
    });
  }

  // 工具栏事件
  table.on('toolbar(forumTable)', function (obj) {
    if (obj.event === 'add') { openForm(0); }
  });

  // 行操作事件
  table.on('tool(forumTable)', function (obj) {
    var d = obj.data;

    if (obj.event === 'edit') {
      openForm(d.id);
    } else if (obj.event === 'access') {
      layui.layer.open({
        title: '板块权限 - ' + d.name,
        type: 2,
        shade: 0.2,
        shadeClose: false,
        maxmin: true,
        area: ['920px', '90%'],
        content: '/admin/forums/access?forum_id=' + d.id
      });
    } else if (obj.event === 'delete') {
      AdminUi.confirmPost('确定删除板块「' + d.name + '」吗？删除后不可恢复。',
        '/admin/forums/delete', { id: d.id }, function () {
          table.reload('forumTable');
        });
    }
  });
});
</script>
