<?php
/**
 * 后台 - 友情链接（layuimini 子页面）
 *
 * 形状照 forums.php（列表页参考实现）：
 *   <script type="text/html" id="friendLinkToolbar"> 顶部工具栏（添加链接）
 *   <table class="layui-hide" id="friendLinkTable">   表格占位
 *   <script type="text/html" id="friendLinkRowBar">   行内操作（删除）
 *   layui.use(['table','form','element']) → table.render({url:'/admin/api/friend-links', ...})
 *
 * 搜索区：FriendLink::allOrdered() 不接受任何筛选参数（friendLinksApi() 只是把它原样
 * jsonTable 出去），所以这里**没有**搜索表单。分页同样由 API 一次性返回全量 + count，
 * 所以 page:false 一次取完。
 *
 * 「添加链接」原来是可折叠面板：因为不能改 SystemController（没有独立表单页给 layer 的
 * iframe 用），这里保留内联表单，改成 layui-collapse + layui 表单，
 * 提交走 AdminUi.post() + table.reload()。
 */

$emptyText = '<div>暂无友情链接</div>'
           . '<div class="admin-muted">点上方「添加链接」把合作站点挂到这里</div>';
?>

<!-- 顶部工具栏 -->
<script type="text/html" id="friendLinkToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="add">
      <i class="layui-icon layui-icon-add-1"></i> 添加链接
    </button>
  </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="friendLinkRowBar">
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<!-- 添加链接（可折叠，由工具栏「添加链接」展开） -->
<div class="layui-collapse" id="addLinkPanel" lay-filter="addLinkPanel" style="margin-bottom:10px">
  <div class="layui-colla-item">
    <h2 class="layui-colla-title">添加友情链接</h2>
    <div class="layui-colla-content">
      <form class="layui-form" id="addLinkForm" lay-filter="addLinkForm" action="">

        <div class="layui-form-item">
          <div class="layui-inline">
            <label class="layui-form-label">名称</label>
            <div class="layui-input-inline">
              <input type="text" name="name" class="layui-input" lay-verify="required"
                     placeholder="链接名称" autocomplete="off">
            </div>
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">链接</label>
            <div class="layui-input-inline" style="width:260px">
              <input type="url" name="url" class="layui-input" lay-verify="required"
                     placeholder="https://" autocomplete="off">
            </div>
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">Logo（可选）</label>
            <div class="layui-input-inline" style="width:220px">
              <input type="text" name="logo" class="layui-input" placeholder="图片 URL" autocomplete="off">
            </div>
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">排序</label>
            <div class="layui-input-inline" style="width:100px">
              <input type="number" name="sort_order" class="layui-input" value="0" autocomplete="off">
            </div>
          </div>
        </div>

        <div class="layui-form-item" style="margin-bottom:0">
          <div class="layui-input-block" style="margin-left:0">
            <button class="layui-btn layui-btn-sm" lay-submit lay-filter="addLinkSubmit">添加</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<table class="layui-hide" id="friendLinkTable" lay-filter="friendLinkTable"></table>

<div class="admin-muted" style="margin-top:8px">共 <span id="friendLinkCount">0</span> 条</div>

<script>
layui.use(['table', 'form', 'element', 'util'], function () {
  var table = layui.table;
  var form = layui.form;
  var $ = layui.jquery;
  var util = layui.util;

  /** HTML 转义 */
  function esc(v) {
    return util.escape(v == null ? '' : String(v));
  }

  /** 按字符（码点）截断，等价于原来 PHP 的 mb_strlen / mb_substr */
  function cut(text, max) {
    var chars = Array.from(String(text == null ? '' : text));
    return chars.length > max ? chars.slice(0, max).join('') + '...' : chars.join('');
  }

  table.render({
    elem: '#friendLinkTable',
    url: '/admin/api/friend-links',
    toolbar: '#friendLinkToolbar',
    defaultToolbar: ['filter', 'print'],
    cols: [[
      { field: 'id', width: 70, title: 'ID', sort: true, templet: function (d) {
          return '<span class="admin-muted">' + (parseInt(d.id, 10) || 0) + '</span>';
        } },
      { field: 'name', width: 140, title: '名称', templet: function (d) {
          var name = String(d.name == null ? '' : d.name);
          return '<span class="admin-ellipsis" title="' + esc(name) + '">' + esc(name) + '</span>';
        } },
      { field: 'url', minWidth: 220, title: '链接', templet: function (d) {
          var url = String(d.url == null ? '' : d.url);
          return '<a class="admin-ellipsis" style="text-decoration:none" href="' + esc(url) + '"'
               + ' target="_blank" rel="noopener noreferrer" title="' + esc(url) + '">'
               + esc(cut(url, 50)) + '</a>';
        } },
      { field: 'logo', width: 110, title: 'Logo', templet: function (d) {
          var logo = String(d.logo == null ? '' : d.logo);
          if (logo === '') { return '<span class="admin-muted">-</span>'; }
          return '<img src="' + esc(logo) + '" alt="" style="max-height:28px;max-width:80px" loading="lazy">';
        } },
      { field: 'sort_order', width: 70, title: '排序', align: 'right', sort: true, templet: function (d) {
          return '<span class="admin-muted">' + (parseInt(d.sort_order, 10) || 0) + '</span>';
        } },
      { field: 'status', width: 80, title: '状态', templet: function (d) {
          return (parseInt(d.status, 10) || 0) === 0
            ? '<span class="layui-badge layui-bg-gray">禁用</span>'
            : '<span class="layui-badge layui-bg-green">启用</span>';
        } },
      { field: 'created_at', width: 150, title: '创建时间', templet: function (d) {
          var ts = parseInt(d.created_at, 10) || 0;
          var text = ts > 0 ? util.toDateString(ts * 1000, 'yyyy-MM-dd HH:mm') : '-';
          return '<span class="admin-muted">' + esc(text) + '</span>';
        } },
      { title: '操作', width: 90, toolbar: '#friendLinkRowBar', align: 'center' }
    ]],
    page: false,
    limit: 200,
    done: function (res, curr, count) {
      // api 一次返回全量，页码条不显示，数量自己标出来（原来是面板标题里的「共 N 条」）
      var el = document.getElementById('friendLinkCount');
      if (el) { el.textContent = count; }
    },
    text: { none: <?= json_encode($emptyText, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> },
    skin: 'line'
  });

  /** 展开添加面板（layui 的折叠面板：点标题开合，这里模拟一次点击） */
  function openAddPanel() {
    var $content = $('#addLinkPanel .layui-colla-content');
    if (!$content.hasClass('layui-show')) {
      $('#addLinkPanel .layui-colla-title').trigger('click');
    }
    $('#addLinkForm input[name="name"]').focus();
  }

  /** 收起添加面板并清空表单 */
  function resetAddPanel() {
    var $content = $('#addLinkPanel .layui-colla-content');
    if ($content.hasClass('layui-show')) {
      $('#addLinkPanel .layui-colla-title').trigger('click');
    }
    document.getElementById('addLinkForm').reset();
    form.render(null, 'addLinkForm');
  }

  // 工具栏事件
  table.on('toolbar(friendLinkTable)', function (obj) {
    if (obj.event === 'add') { openAddPanel(); }
  });

  // 行操作事件
  table.on('tool(friendLinkTable)', function (obj) {
    var d = obj.data;

    if (obj.event === 'delete') {
      AdminUi.confirmPost('确定要删除该友情链接吗？', '/admin/friend-links/delete', { id: d.id }, function () {
        table.reload('friendLinkTable');
      });
    }
  });

  // 添加链接
  form.on('submit(addLinkSubmit)', function (data) {
    AdminUi.post('/admin/friend-links/create', data.field, function () {
      table.reload('friendLinkTable');
      resetAddPanel();
    });
    return false;
  });
});
</script>
