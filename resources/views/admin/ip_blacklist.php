<?php
/**
 * 后台 - IP 黑名单（layuimini 子页面）
 *
 * 形状照 forums.php（列表页参考实现）：
 *   <fieldset class="table-search-fieldset">        搜索区（search 是 API 真正读取的参数）
 *   <script type="text/html" id="ipBlacklistToolbar"> 顶部工具栏（添加 IP）
 *   <table class="layui-hide" id="ipBlacklistTable">  表格占位
 *   <script type="text/html" id="ipBlacklistRowBar">  行内操作（解除）
 *   layui.use(['table','form','element']) → table.render({url:'/admin/api/ip-blacklist', ...})
 *
 * 筛选参数：只有 search（IpBlacklist::adminList() 按 ip 或 reason LIKE 它，
 * 见 ipBlacklistApi()）。分页由 layui 传 page / limit，api 的 limit 被夹在 10~50。
 * 排序：api 不认 sort / dir 参数（模型里写死 ORDER BY id DESC），所以**不放**排序箭头 ——
 * layui 的 sort:true 只排当前页缓存里的几行，点到第二页就露馅，比没有更糟。
 *
 * 「添加封禁」原来是可折叠面板：因为不能改 SystemController（没有独立表单页给 layer 的
 * iframe 用），这里保留内联表单，改成 layui-collapse + layui 表单，
 * 提交走 AdminUi.post() + table.reload()。
 *
 * 变量：$search（回显搜索词）、$page（页码回显）
 */

$search = (string)($search ?? '');
$page   = max(1, (int)($page ?? 1));

/** 空数据提示：沿用原来「搜索中 / 未搜索」两套文案（layui 的 text.none 允许 HTML） */
$emptyText = $search !== ''
    ? '<div>没有匹配「' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . '」的记录</div>'
      . '<div class="admin-muted">换个关键词，或清除搜索查看全部记录</div>'
    : '<div>暂无封禁记录</div>'
      . '<div class="admin-muted">点上方「添加 IP」把恶意来源挡在站外</div>';

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<!-- 搜索区 -->
<fieldset class="layui-elem-field layui-field-title table-search-fieldset">
  <legend>搜索</legend>
  <div style="margin: 5px 0 10px">
    <form class="layui-form" id="ipBlacklistSearch" lay-filter="ipBlacklistSearch" action="">
      <div class="layui-form-item">
        <div class="layui-inline">
          <label class="layui-form-label" style="width:110px">关键词</label>
          <div class="layui-input-inline" style="width:260px">
            <input type="text" name="search" class="layui-input" autocomplete="off"
                   placeholder="搜索 IP 或原因..."
                   value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>
        <div class="layui-inline">
          <button class="layui-btn layui-btn-sm" lay-submit lay-filter="ipBlacklistSearchSubmit">搜索</button>
          <?php if ($search !== ''): ?>
            <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="ipBlacklistSearchReset">清除</button>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</fieldset>

<!-- 顶部工具栏 -->
<script type="text/html" id="ipBlacklistToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="add">
      <i class="layui-icon layui-icon-add-1"></i> 添加 IP
    </button>
  </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="ipBlacklistRowBar">
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">解除</a>
</script>

<!-- 添加封禁（可折叠，由工具栏「添加 IP」展开） -->
<div class="layui-collapse" id="addIpPanel" lay-filter="addIpPanel" style="margin-bottom:10px">
  <div class="layui-colla-item">
    <h2 class="layui-colla-title">添加封禁</h2>
    <div class="layui-colla-content">
      <form class="layui-form" id="addIpForm" lay-filter="addIpForm" action="">

        <div class="layui-form-item">
          <div class="layui-inline">
            <label class="layui-form-label">IP 地址</label>
            <div class="layui-input-inline">
              <input type="text" name="ip" class="layui-input" lay-verify="required"
                     placeholder="如 192.168.1.1" autocomplete="off">
            </div>
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">原因</label>
            <div class="layui-input-inline" style="width:220px">
              <input type="text" name="reason" class="layui-input" placeholder="封禁原因" autocomplete="off">
            </div>
          </div>

          <div class="layui-inline">
            <label class="layui-form-label">过期时间</label>
            <div class="layui-input-inline">
              <select name="duration">
                <option value="0">永久</option>
                <option value="3600">1 小时</option>
                <option value="86400">1 天</option>
                <option value="604800">7 天</option>
                <option value="2592000">30 天</option>
              </select>
            </div>
          </div>
        </div>

        <div class="layui-form-item" style="margin-bottom:0">
          <div class="layui-input-block" style="margin-left:0">
            <button class="layui-btn layui-btn-sm" lay-submit lay-filter="addIpSubmit">添加</button>
            <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="addIpCancel">取消</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<table class="layui-hide" id="ipBlacklistTable" lay-filter="ipBlacklistTable"></table>

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

  /** 时间戳（秒）→ yyyy-MM-dd HH:mm */
  function fmtTime(ts) {
    ts = parseInt(ts, 10) || 0;
    return ts > 0 ? util.toDateString(ts * 1000, 'yyyy-MM-dd HH:mm') : '-';
  }

  table.render({
    elem: '#ipBlacklistTable',
    url: '/admin/api/ip-blacklist',
    where: { search: <?= json_encode($search, $jsonFlags) ?> },
    toolbar: '#ipBlacklistToolbar',
    defaultToolbar: ['filter', 'print'],
    cols: [[
      { field: 'id', width: 70, title: 'ID', templet: function (d) {
          return '<span class="admin-muted">' + (parseInt(d.id, 10) || 0) + '</span>';
        } },
      { field: 'ip', width: 200, title: 'IP 地址', templet: function (d) {
          return '<code>' + esc(d.ip) + '</code>';
        } },
      { field: 'reason', minWidth: 160, title: '原因', templet: function (d) {
          var reason = String(d.reason == null ? '' : d.reason);
          return reason !== '' ? esc(reason) : '<span class="admin-muted">-</span>';
        } },
      { field: 'expire_at', width: 160, title: '过期时间', templet: function (d) {
          var expireAt = parseInt(d.expire_at, 10) || 0;
          return expireAt > 0
            ? '<span class="admin-muted">' + esc(fmtTime(expireAt)) + '</span>'
            : '<span class="layui-badge layui-bg-gray">永久</span>';
        } },
      { field: 'created_at', width: 160, title: '添加时间', templet: function (d) {
          return '<span class="admin-muted">' + esc(fmtTime(d.created_at)) + '</span>';
        } },
      { title: '操作', width: 100, toolbar: '#ipBlacklistRowBar', align: 'center' }
    ]],
    page: { curr: <?= $page ?>, limit: 20, limits: [10, 20, 30, 50] },
    text: { none: <?= json_encode($emptyText, $jsonFlags) ?> },
    skin: 'line'
  });

  // 搜索 / 清除
  form.on('submit(ipBlacklistSearchSubmit)', function (data) {
    table.reload('ipBlacklistTable', {
      where: { search: data.field.search || '' },
      page: { curr: 1 }
    });
    return false;
  });

  $('#ipBlacklistSearchReset').on('click', function () {
    $('input[name="search"]', '#ipBlacklistSearch').val('');
    table.reload('ipBlacklistTable', {
      where: { search: '' },
      page: { curr: 1 }
    });
  });

  /** 展开添加面板（layui 的折叠面板：点标题开合，这里模拟一次点击） */
  function openAddPanel() {
    var $content = $('#addIpPanel .layui-colla-content');
    if (!$content.hasClass('layui-show')) {
      $('#addIpPanel .layui-colla-title').trigger('click');
    }
    $('#addIpForm input[name="ip"]').focus();
  }

  /** 收起添加面板并清空表单 */
  function resetAddPanel() {
    var $content = $('#addIpPanel .layui-colla-content');
    if ($content.hasClass('layui-show')) {
      $('#addIpPanel .layui-colla-title').trigger('click');
    }
    document.getElementById('addIpForm').reset();
    form.render(null, 'addIpForm');
  }

  // 工具栏事件
  table.on('toolbar(ipBlacklistTable)', function (obj) {
    if (obj.event === 'add') { openAddPanel(); }
  });

  // 行操作事件
  table.on('tool(ipBlacklistTable)', function (obj) {
    var d = obj.data;

    if (obj.event === 'delete') {
      AdminUi.confirmPost('确定要解除该 IP 的封禁吗？', '/admin/ip-blacklist/delete', { id: d.id }, function () {
        table.reload('ipBlacklistTable');
      });
    }
  });

  // 添加封禁 / 取消
  form.on('submit(addIpSubmit)', function (data) {
    AdminUi.post('/admin/ip-blacklist/create', data.field, function () {
      table.reload('ipBlacklistTable');
      resetAddPanel();
    });
    return false;
  });

  $('#addIpCancel').on('click', function () {
    resetAddPanel();
  });
});
</script>
