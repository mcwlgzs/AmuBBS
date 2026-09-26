<?php
/**
 * 后台 - 敏感词管理（layuimini 子页面）
 *
 * 形状照 forums.php（列表页参考实现）：
 *   <fieldset class="table-search-fieldset">           搜索区（search 为 API 真正读取的参数）
 *   <script type="text/html" id="sensitiveWordToolbar"> 顶部工具栏（添加敏感词）
 *   <table class="layui-hide" id="sensitiveWordTable">  表格占位
 *   <script type="text/html" id="sensitiveWordRowBar">  行内操作（删除）
 *   layui.use(['table','form','element']) → table.render({url:'/admin/api/sensitive-words', ...})
 *
 * 筛选参数：只有 search（SensitiveWord::adminList() 的 SQL 只认它，见 sensitiveWordsApi()）。
 * 分页：api 支持 page / limit（limit 被夹在 10~50），所以 page:true，limits 不超出该区间，
 * 否则「每页 60 条」会被服务端夹成 50，页码条与实际条数对不上。
 * 排序：api 不认 sort / dir 参数（模型里写死 ORDER BY id DESC），所以**不放**排序箭头 ——
 * layui 的 sort:true 只排当前页缓存里的几行，点到第二页就露馅，比没有更糟。
 *
 * 「添加敏感词」原来是可折叠面板：因为不能改 FilterController（没有独立表单页给 layer 的
 * iframe 用），这里保留内联表单，改成 layui-collapse + layui 表单，
 * 提交走 AdminUi.post() + table.reload()。
 *
 * 变量：$search（回显搜索词）
 */

$search = (string)($search ?? '');

/** 空数据提示：沿用原来「搜索中 / 未搜索」两套文案（layui 的 text.none 允许 HTML） */
$emptyText = $search !== ''
    ? '<div>没有匹配「' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . '」的敏感词</div>'
      . '<div class="admin-muted">换个关键词，或清除搜索查看全部敏感词</div>'
    : '<div>暂无敏感词</div>'
      . '<div class="admin-muted">点上方「添加敏感词」把需要过滤的词语加进来</div>';
?>

<!-- 搜索区 -->
<fieldset class="layui-elem-field layui-field-title table-search-fieldset">
  <legend>搜索</legend>
  <div style="margin: 5px 0 10px">
    <form class="layui-form" id="sensitiveWordSearch" lay-filter="sensitiveWordSearch" action="">
      <div class="layui-form-item">
        <div class="layui-inline">
          <label class="layui-form-label">敏感词</label>
          <div class="layui-input-inline">
            <input type="text" name="search" class="layui-input" autocomplete="off"
                   placeholder="搜索敏感词..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>
        <div class="layui-inline">
          <button class="layui-btn layui-btn-sm" lay-submit lay-filter="sensitiveWordSearchSubmit">搜索</button>
          <?php if ($search !== ''): ?>
            <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="sensitiveWordSearchReset">清除</button>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</fieldset>

<!-- 顶部工具栏 -->
<script type="text/html" id="sensitiveWordToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="add">
      <i class="layui-icon layui-icon-add-1"></i> 添加敏感词
    </button>
  </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="sensitiveWordRowBar">
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<!-- 添加敏感词（可折叠，由工具栏「添加敏感词」展开） -->
<div class="layui-collapse" id="addWordPanel" lay-filter="addWordPanel" style="margin-bottom:10px">
  <div class="layui-colla-item">
    <h2 class="layui-colla-title">添加敏感词</h2>
    <div class="layui-colla-content">
      <form class="layui-form" id="addWordForm" lay-filter="addWordForm" action="">

        <div class="layui-form-item">
          <div class="layui-inline">
            <label class="layui-form-label">敏感词</label>
            <div class="layui-input-inline">
              <input type="text" name="word" class="layui-input" lay-verify="required"
                     placeholder="敏感词" autocomplete="off">
            </div>
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">替换为</label>
            <div class="layui-input-inline">
              <input type="text" name="replacement" class="layui-input"
                     placeholder="留空则用 ***" autocomplete="off">
            </div>
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">级别</label>
            <div class="layui-input-inline">
              <select name="level">
                <option value="1">替换</option>
                <option value="2">禁止发布</option>
              </select>
            </div>
          </div>
        </div>

        <div class="layui-form-item" style="margin-bottom:0">
          <div class="layui-input-block" style="margin-left:0">
            <button class="layui-btn layui-btn-sm" lay-submit lay-filter="addWordSubmit">添加</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<table class="layui-hide" id="sensitiveWordTable" lay-filter="sensitiveWordTable"></table>

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
    elem: '#sensitiveWordTable',
    url: '/admin/api/sensitive-words',
    toolbar: '#sensitiveWordToolbar',
    defaultToolbar: ['filter', 'print'],
    cols: [[
      { field: 'id', width: 80, title: 'ID', templet: function (d) {
          return '<span class="admin-muted">' + (parseInt(d.id, 10) || 0) + '</span>';
        } },
      { field: 'word', minWidth: 160, title: '敏感词', templet: function (d) {
          return '<code>' + esc(d.word) + '</code>';
        } },
      { field: 'replacement', minWidth: 160, title: '替换为', templet: function (d) {
          var replacement = String(d.replacement == null ? '' : d.replacement);
          return '<span class="admin-muted">' + esc(replacement !== '' ? replacement : '***') + '</span>';
        } },
      { field: 'level', width: 120, title: '级别', templet: function (d) {
          return (parseInt(d.level, 10) || 1) === 2
            ? '<span class="layui-badge layui-bg-red">禁止发布</span>'
            : '<span class="layui-badge layui-bg-gray">替换</span>';
        } },
      { field: 'created_at', width: 160, title: '添加时间', templet: function (d) {
          var ts = parseInt(d.created_at, 10) || 0;
          var text = ts > 0 ? util.toDateString(ts * 1000, 'yyyy-MM-dd HH:mm') : '-';
          return '<span class="admin-muted">' + esc(text) + '</span>';
        } },
      { title: '操作', width: 90, toolbar: '#sensitiveWordRowBar', align: 'center' }
    ]],
    page: true,
    limit: 20,
    limits: [10, 20, 30, 50],
    text: { none: <?= json_encode($emptyText, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> },
    skin: 'line'
  });

  // 搜索 / 清除
  form.on('submit(sensitiveWordSearchSubmit)', function (data) {
    table.reload('sensitiveWordTable', {
      where: { search: data.field.search || '' },
      page: { curr: 1 }
    });
    return false;
  });

  $('#sensitiveWordSearchReset').on('click', function () {
    $('input[name="search"]', '#sensitiveWordSearch').val('');
    table.reload('sensitiveWordTable', {
      where: { search: '' },
      page: { curr: 1 }
    });
  });

  /** 展开添加面板（layui 的折叠面板：点标题开合，这里模拟一次点击） */
  function openAddPanel() {
    var $content = $('#addWordPanel .layui-colla-content');
    if (!$content.hasClass('layui-show')) {
      $('#addWordPanel .layui-colla-title').trigger('click');
    }
    $('#addWordForm input[name="word"]').focus();
  }

  /** 收起添加面板并清空表单 */
  function resetAddPanel() {
    var $content = $('#addWordPanel .layui-colla-content');
    if ($content.hasClass('layui-show')) {
      $('#addWordPanel .layui-colla-title').trigger('click');
    }
    document.getElementById('addWordForm').reset();
    form.render(null, 'addWordForm');
  }

  // 工具栏事件
  table.on('toolbar(sensitiveWordTable)', function (obj) {
    if (obj.event === 'add') { openAddPanel(); }
  });

  // 行操作事件
  table.on('tool(sensitiveWordTable)', function (obj) {
    var d = obj.data;

    if (obj.event === 'delete') {
      AdminUi.confirmPost('确定要删除该敏感词吗？', '/admin/sensitive-words/delete', { id: d.id }, function () {
        table.reload('sensitiveWordTable');
      });
    }
  });

  // 添加敏感词
  form.on('submit(addWordSubmit)', function (data) {
    AdminUi.post('/admin/sensitive-words/create', data.field, function () {
      table.reload('sensitiveWordTable');
      resetAddPanel();
    });
    return false;
  });
});
</script>
