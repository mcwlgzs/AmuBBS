<?php
/**
 * 后台 - 标签分类（layuimini 子页面）
 *
 * 形状照 forums.php（列表页参考实现）：
 *   <script type="text/html" id="tagCategoryToolbar"> 顶部工具栏（添加分类）
 *   <table class="layui-hide" id="tagCategoryTable">   表格占位
 *   <script type="text/html" id="tagCategoryRowBar">   行内操作（删除）
 *   layui.use(['table','form','element']) → table.render({url:'/admin/api/tag-categories', ...})
 *
 * 搜索区：TagCategory::adminList() 不接受任何筛选参数（tagCategoriesApi() 只是把它
 * 原样 jsonTable 出去），所以这里**没有**搜索表单。
 * 分页：api 返回全量 + count，所以 page:false 一次取完。
 *
 * 「添加分类」原来是一个可折叠面板：因为不能改 TagController（也就没有独立的表单页
 * 给 layer 的 iframe 用），这里保留内联表单，改成 layui-collapse + layui 表单，
 * 提交走 AdminUi.post() + table.reload()。
 *
 * 变量：$forums（关联板块下拉，Forum::getOptions()）
 */

$forums = is_array($forums ?? null) ? $forums : [];
?>

<!-- 顶部工具栏 -->
<script type="text/html" id="tagCategoryToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="add">
      <i class="layui-icon layui-icon-add-1"></i> 添加分类
    </button>
  </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="tagCategoryRowBar">
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<blockquote class="layui-elem-quote layui-quote-nm" style="margin-bottom:10px">
  标签分类可关联到板块，发帖时会显示该板块关联的标签供选择。<code>全局</code> 表示所有板块都可以用。
</blockquote>

<!-- 添加分类（可折叠，由工具栏「添加分类」展开） -->
<div class="layui-collapse" id="addCatePanel" lay-filter="addCatePanel" style="margin-bottom:10px">
  <div class="layui-colla-item">
    <h2 class="layui-colla-title">添加标签分类</h2>
    <div class="layui-colla-content">
      <form class="layui-form" id="addCateForm" lay-filter="addCateForm" action="">

        <div class="layui-form-item">
          <div class="layui-inline">
            <label class="layui-form-label">分类名称</label>
            <div class="layui-input-inline">
              <input type="text" name="name" class="layui-input" lay-verify="required"
                     placeholder="分类名称" autocomplete="off">
            </div>
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">关联板块</label>
            <div class="layui-input-inline">
              <select name="forum_id">
                <option value="0">全局（所有板块可用）</option>
                <?php foreach ($forums as $f): ?>
                  <option value="<?= (int)($f['id'] ?? 0) ?>">
                    <?= htmlspecialchars((string)($f['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
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
            <button class="layui-btn layui-btn-sm" lay-submit lay-filter="addCateSubmit">添加</button>
            <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="addCateCancel">取消</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<table class="layui-hide" id="tagCategoryTable" lay-filter="tagCategoryTable"></table>

<div class="admin-muted" style="margin-top:8px">共 <span id="tagCategoryCount">0</span> 个</div>

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

  table.render({
    elem: '#tagCategoryTable',
    url: '/admin/api/tag-categories',
    toolbar: '#tagCategoryToolbar',
    defaultToolbar: ['filter', 'print'],
    cols: [[
      { field: 'id', width: 80, title: 'ID', sort: true, templet: function (d) {
          return '<span class="admin-muted">' + (parseInt(d.id, 10) || 0) + '</span>';
        } },
      { field: 'name', minWidth: 160, title: '名称', templet: function (d) {
          return esc(d.name);
        } },
      { field: 'forum_id', width: 160, title: '关联板块', templet: function (d) {
          var forumId = parseInt(d.forum_id, 10) || 0;
          if (forumId <= 0) { return '<span class="admin-muted">全局</span>'; }
          var forumName = String(d.forum_name == null ? '' : d.forum_name);
          return esc(forumName !== '' ? forumName : ('板块#' + forumId));
        } },
      { field: 'tag_count', width: 90, title: '标签数', align: 'right', sort: true, templet: function (d) {
          return '<span class="admin-num">' + (parseInt(d.tag_count, 10) || 0) + '</span>';
        } },
      { field: 'sort_order', width: 80, title: '排序', align: 'right', sort: true, templet: function (d) {
          return '<span class="admin-muted">' + (parseInt(d.sort_order, 10) || 0) + '</span>';
        } },
      { title: '操作', width: 100, toolbar: '#tagCategoryRowBar', align: 'center' }
    ]],
    page: false,
    limit: 200,
    done: function (res, curr, count) {
      // api 一次返回全量，页码条不显示，数量自己标出来（原来是面板标题里的「共 N 个」）
      var el = document.getElementById('tagCategoryCount');
      if (el) { el.textContent = count; }
    },
    text: { none: '暂无标签分类，点右上角「添加分类」创建第一个标签分类' },
    skin: 'line'
  });

  /** 展开添加面板（layui 的折叠面板：点标题开合，这里模拟一次点击） */
  function openAddPanel() {
    var $content = $('#addCatePanel .layui-colla-content');
    if (!$content.hasClass('layui-show')) {
      $('#addCatePanel .layui-colla-title').trigger('click');
    }
    $('#addCateForm input[name="name"]').focus();
  }

  /** 收起添加面板并清空表单 */
  function resetAddPanel() {
    var $content = $('#addCatePanel .layui-colla-content');
    if ($content.hasClass('layui-show')) {
      $('#addCatePanel .layui-colla-title').trigger('click');
    }
    document.getElementById('addCateForm').reset();
    form.render(null, 'addCateForm');
  }

  // 工具栏事件
  table.on('toolbar(tagCategoryTable)', function (obj) {
    if (obj.event === 'add') { openAddPanel(); }
  });

  // 行操作事件
  table.on('tool(tagCategoryTable)', function (obj) {
    var d = obj.data;

    if (obj.event === 'delete') {
      AdminUi.confirmPost('删除分类后，该分类下的标签将变为未分类。确定删除吗？',
        '/admin/tag-categories/delete', { id: d.id }, function () {
          table.reload('tagCategoryTable');
        });
    }
  });

  // 添加分类
  form.on('submit(addCateSubmit)', function (data) {
    AdminUi.post('/admin/tag-categories/create', data.field, function () {
      table.reload('tagCategoryTable');
      resetAddPanel();
    });
    return false;
  });

  // 取消（收起面板并清空）
  $('#addCateCancel').on('click', function () {
    resetAddPanel();
  });
});
</script>
