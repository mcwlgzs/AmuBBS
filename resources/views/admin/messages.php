<?php
/**
 * 后台 - 私信监控（layuimini 子页面）
 *
 * 形状照 forums.php（列表页参考实现）：
 *   <fieldset class="table-search-fieldset">      搜索区（search 是 API 真正读取的参数）
 *   <table class="layui-hide" id="messageTable">  表格占位
 *   <script type="text/html" id="messageRowBar">  行内操作（删除）
 *   layui.use(['table','form']) → table.render({url:'/admin/api/messages', ...})
 *
 * 筛选参数：只有 search（Message::adminList() 按收发双方用户名或内容 LIKE 它，
 * 见 messagesApi()）。分页由 layui 传 page / limit，api 的 limit 被夹在 10~50，
 * 所以 limits 不超出该区间。
 * 排序：api 不认 sort / dir 参数（模型里写死 ORDER BY m.id DESC），所以**不放**排序箭头 ——
 * layui 的 sort:true 只排当前页缓存里的几行，点到第二页就露馅，比没有更糟。
 *
 * 变量：$search（回显搜索词）、$page（页码回显）
 */

$search = (string)($search ?? '');
$page   = max(1, (int)($page ?? 1));

/** 空数据提示：沿用原来「搜索中 / 未搜索」两套文案（layui 的 text.none 允许 HTML） */
$emptyText = $search !== ''
    ? '<div>没有匹配「' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . '」的私信</div>'
      . '<div class="admin-muted">换个关键词，或清除搜索查看全部私信</div>'
    : '<div>暂无私信记录</div>'
      . '<div class="admin-muted">用户之间的私信会显示在这里</div>';

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<!-- 搜索区 -->
<fieldset class="layui-elem-field layui-field-title table-search-fieldset">
  <legend>搜索</legend>
  <div style="margin: 5px 0 10px">
    <form class="layui-form" id="messageSearch" lay-filter="messageSearch" action="">
      <div class="layui-form-item">
        <div class="layui-inline">
          <label class="layui-form-label" style="width:110px">关键词</label>
          <div class="layui-input-inline" style="width:300px">
            <input type="text" name="search" class="layui-input" autocomplete="off"
                   placeholder="搜索发送者 / 接收者用户名或内容..."
                   value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>
        <div class="layui-inline">
          <button class="layui-btn layui-btn-sm" lay-submit lay-filter="messageSearchSubmit">搜索</button>
          <?php if ($search !== ''): ?>
            <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="messageSearchReset">清除</button>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</fieldset>

<!-- 行内操作 -->
<script type="text/html" id="messageRowBar">
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<table class="layui-hide" id="messageTable" lay-filter="messageTable"></table>

<script>
layui.use(['table', 'form', 'util'], function () {
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

  /** 时间戳（秒）→ yyyy-MM-dd HH:mm */
  function fmtTime(ts) {
    ts = parseInt(ts, 10) || 0;
    return ts > 0 ? util.toDateString(ts * 1000, 'yyyy-MM-dd HH:mm') : '-';
  }

  table.render({
    elem: '#messageTable',
    url: '/admin/api/messages',
    where: { search: <?= json_encode($search, $jsonFlags) ?> },
    defaultToolbar: ['filter', 'print'],
    cols: [[
      { field: 'id', width: 70, title: 'ID', templet: function (d) {
          return '<span class="admin-muted">' + (parseInt(d.id, 10) || 0) + '</span>';
        } },
      { field: 'from_username', width: 120, title: '发送者', templet: function (d) {
          var name = String(d.from_username == null ? '' : d.from_username);
          return esc(name !== '' ? name : '-');
        } },
      { field: 'to_username', width: 120, title: '接收者', templet: function (d) {
          var name = String(d.to_username == null ? '' : d.to_username);
          return esc(name !== '' ? name : '-');
        } },
      { field: 'content', minWidth: 220, title: '内容', templet: function (d) {
          var content = String(d.content == null ? '' : d.content);
          return '<span class="admin-ellipsis" title="' + esc(content) + '">'
               + esc(cut(content, 60)) + '</span>';
        } },
      { field: 'is_read', width: 80, title: '状态', templet: function (d) {
          return d.is_read
            ? '<span class="layui-badge layui-bg-green">已读</span>'
            : '<span class="layui-badge layui-bg-gray">未读</span>';
        } },
      { field: 'created_at', width: 150, title: '时间', templet: function (d) {
          return '<span class="admin-muted">' + esc(fmtTime(d.created_at)) + '</span>';
        } },
      { title: '操作', width: 90, toolbar: '#messageRowBar', align: 'center' }
    ]],
    page: { curr: <?= $page ?>, limit: 20, limits: [10, 20, 30, 50] },
    text: { none: <?= json_encode($emptyText, $jsonFlags) ?> },
    skin: 'line'
  });

  // 搜索 / 清除
  form.on('submit(messageSearchSubmit)', function (data) {
    table.reload('messageTable', {
      where: { search: data.field.search || '' },
      page: { curr: 1 }
    });
    return false;
  });

  $('#messageSearchReset').on('click', function () {
    $('input[name="search"]', '#messageSearch').val('');
    table.reload('messageTable', {
      where: { search: '' },
      page: { curr: 1 }
    });
  });

  // 行操作事件
  table.on('tool(messageTable)', function (obj) {
    var d = obj.data;

    if (obj.event === 'delete') {
      AdminUi.confirmPost('确定要删除这条私信吗？', '/admin/messages/delete', { id: d.id }, function () {
        table.reload('messageTable');
      });
    }
  });
});
</script>
