<?php
/**
 * 后台 - 积分记录（layuimini 子页面片段）
 *
 * 形状照 resources/views/admin/forums.php：
 *   搜索 fieldset（只放 /admin/api/credit-logs 真正会读的 search）
 *   → layui table（id = creditLogTable，服务端分页）
 *
 * 只读页面：没有工具栏、没有行内操作，增删改入口本来就不存在。
 * 数据来自已有的 GET /admin/api/credit-logs?search=&page=&limit=。
 *
 * 变量：$rows, $total, $page, $pages, $search
 */

$search = (string)($search ?? '');

/** HTML 转义：templet 里拼的是用户内容，layui 2.6 的 templet 不会自动转义 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/** 把 PHP 值安全地塞进 <script> 里的 JS 字面量 */
$js = static fn($v): string => (string)json_encode(
    $v,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>

<!-- 搜索：API 只认 search -->
<fieldset class="layui-elem-field layui-field-title table-search-fieldset">
  <legend>筛选</legend>
  <div class="layui-form" id="creditLogSearchForm">
    <div class="layui-form-item">
      <div class="layui-inline">
        <label class="layui-form-label">用户名</label>
        <div class="layui-input-inline" style="width:200px">
          <input type="text" id="creditLogSearchKey" class="layui-input"
                 placeholder="搜索用户名" value="<?= $e($search) ?>">
        </div>
      </div>
      <div class="layui-inline">
        <button type="button" class="layui-btn" id="creditLogSearchBtn">
          <i class="layui-icon layui-icon-search"></i> 搜索
        </button>
        <button type="button" class="layui-btn layui-btn-primary" id="creditLogSearchClear">
          <i class="layui-icon layui-icon-refresh"></i> 清除
        </button>
      </div>
    </div>
  </div>
</fieldset>

<table class="layui-hide" id="creditLogTable" lay-filter="creditLogTable"></table>

<script>
layui.use(['table', 'util'], function () {
  var table = layui.table;
  var util  = layui.util;
  var $     = layui.jquery;

  var esc = function (v) { return util.escape(v == null ? '' : String(v)); };

  /** 积分变动类型 → [中文名, layui 徽章样式] */
  var TYPE_MAP = {
    checkin:  ['签到', 'layui-bg-green'],
    thread:   ['发帖', 'layui-bg-blue'],
    post:     ['回复', 'layui-bg-blue'],
    like:     ['点赞', 'layui-bg-cyan'],
    reward:   ['打赏', 'layui-bg-cyan'],
    transfer: ['转账', 'layui-bg-gray'],
    consume:  ['消费', ''],
    refund:   ['退款', 'layui-bg-gray']
  };

  /** 时间戳（秒）→ YYYY-MM-DD HH:mm */
  function fmtDateTime(ts) {
    ts = parseInt(ts, 10) || 0;
    if (ts <= 0) { return '-'; }
    var d = new Date(ts * 1000);
    var z = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate())
      + ' ' + z(d.getHours()) + ':' + z(d.getMinutes());
  }

  table.render({
    elem: '#creditLogTable',
    url: '/admin/api/credit-logs',
    where: { search: <?= $js($search) ?> },
    page: true,
    limit: 20,
    limits: [10, 20, 30, 50],
    cols: [[
      { field: 'id', width: 90, title: 'ID', templet: function (d) {
          return '<span class="admin-muted admin-num">' + (parseInt(d.id, 10) || 0) + '</span>';
        } },
      { field: 'username', width: 150, title: '用户', templet: function (d) {
          var uid = parseInt(d.user_id, 10) || 0;
          if (uid <= 0) { return '<span class="admin-muted">已注销</span>'; }
          return '<a href="/user/' + uid + '" target="_blank" rel="noopener">'
            + esc(d.username || ('#' + uid)) + '</a>';
        } },
      { field: 'amount', width: 110, title: '变动', align: 'right', templet: function (d) {
          var amount = parseInt(d.amount, 10) || 0;
          var cls = amount > 0 ? 'layui-font-green' : 'layui-font-red';
          return '<span class="' + cls + ' admin-num">' + (amount > 0 ? '+' : '') + amount + '</span>';
        } },
      { field: 'type', width: 110, title: '类型', align: 'center', templet: function (d) {
          var type = (d.type == null ? '' : String(d.type));
          var hit  = TYPE_MAP[type];
          var label = hit ? hit[0] : (type !== '' ? type : '其他');
          var cls   = hit ? hit[1] : 'layui-bg-gray';
          return '<span class="layui-badge ' + cls + '">' + esc(label) + '</span>';
        } },
      { field: 'description', minWidth: 240, title: '描述', templet: function (d) {
          var desc = (d.description == null || d.description === '') ? '' : String(d.description);
          if (desc === '') { return '<span class="admin-muted">-</span>'; }
          return '<span class="admin-ellipsis" title="' + esc(desc) + '">' + esc(desc) + '</span>';
        } },
      { field: 'created_at', width: 170, title: '时间', templet: function (d) {
          return '<span class="admin-muted admin-num">' + fmtDateTime(d.created_at) + '</span>';
        } }
    ]],
    text: { none: '暂无积分记录' },
    skin: 'line'
  });

  function doSearch() {
    table.reload('creditLogTable', {
      where: { search: $('#creditLogSearchKey').val() },
      page: { curr: 1 }
    });
  }

  $('#creditLogSearchBtn').on('click', doSearch);

  $('#creditLogSearchClear').on('click', function () {
    $('#creditLogSearchKey').val('');
    doSearch();
  });

  $('#creditLogSearchForm').on('keydown', 'input', function (ev) {
    if (ev.keyCode === 13) { doSearch(); return false; }
  });
});
</script>
