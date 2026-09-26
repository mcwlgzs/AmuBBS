<?php
/**
 * 后台 - 通知管理（layuimini 子页面）
 *
 * 形状照 forums.php（列表页参考实现）：
 *   <fieldset class="table-search-fieldset">           搜索区（search / type 都是 API 真正读取的参数）
 *   <script type="text/html" id="notificationToolbar"> 顶部工具栏（发送系统通知）
 *   <table class="layui-hide" id="notificationTable">  表格占位
 *   <script type="text/html" id="notificationRowBar">  行内操作（删除）
 *   layui.use(['table','form','element']) → table.render({url:'/admin/api/notifications', ...})
 *
 * 列表一行 = 一次发送（与控制器一致）：
 *   - is_batch=1：一行是一次群发，接收用户列显示范围（全部用户 / 用户名 / 用户组 · 组名），
 *     状态列显示整批的已读进度；
 *   - 其余通知一行一条，接收用户列显示收件人用户名。
 *   这套范围/进度的渲染原来在 PHP 里做，现在数据来自 JSON API，等价逻辑写在 JS 里。
 *
 * 筛选参数：Notification::adminQuery() 只认 search 与 type（就是原来那两项），
 * 所以搜索区只有这两个控件；page / limit 由 layui 传给 API。
 * 排序：api 不认 sort / dir 参数（模型里写死 ORDER BY id DESC），所以**不放**排序箭头 ——
 * layui 的 sort:true 只排当前页缓存里的几行，点到第二页就露馅，比没有更糟。
 *
 * 「发送系统通知」的弹层：弹层内容取自 <script type="text/html" id="sendNotifyTpl">，
 * 这样文档里只有一份模板（不会出现重复 id），弹层打开后再 form.render() 出 layui 控件。
 *
 * 变量：$filters（search / type，用于回显与深链）、$groups（用户组下拉）、
 *       $groupNames（用户组 id → 名称）、$page（页码回显）
 */

$filters    = is_array($filters ?? null) ? $filters : [];
$search     = (string)($filters['search'] ?? '');
$type       = (string)($filters['type'] ?? '');
$groups     = is_array($groups ?? null) ? $groups : [];
$groupNames = is_array($groupNames ?? null) ? $groupNames : [];
$page       = max(1, (int)($page ?? 1));

$emptyText = '<div>暂无通知记录</div>'
           . '<div class="admin-muted">发送系统通知后，发送记录会显示在这里</div>';

/** 内联到 JS 的映射表：中文不转义、< > 等转义，避免结束标签注入 */
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<!-- 搜索区 -->
<fieldset class="layui-elem-field layui-field-title table-search-fieldset">
  <legend>搜索</legend>
  <div style="margin: 5px 0 10px">
    <form class="layui-form" id="notificationSearch" lay-filter="notificationSearch" action="">
      <div class="layui-form-item">
        <div class="layui-inline">
          <label class="layui-form-label" style="width:110px">标题/用户名</label>
          <div class="layui-input-inline" style="width:200px">
            <input type="text" name="search" class="layui-input" autocomplete="off"
                   placeholder="搜索标题或用户名..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>
        <div class="layui-inline">
          <label class="layui-form-label">类型</label>
          <div class="layui-input-inline">
            <select name="type">
              <option value="" <?= $type === '' ? 'selected' : '' ?>>全部类型</option>
              <option value="system" <?= $type === 'system' ? 'selected' : '' ?>>系统</option>
              <option value="reply" <?= $type === 'reply' ? 'selected' : '' ?>>回复</option>
              <option value="mention" <?= $type === 'mention' ? 'selected' : '' ?>>提及</option>
            </select>
          </div>
        </div>
        <div class="layui-inline">
          <button class="layui-btn layui-btn-sm" lay-submit lay-filter="notificationSearchSubmit">搜索</button>
          <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="notificationSearchReset">清空</button>
        </div>
      </div>
    </form>
  </div>
</fieldset>

<!-- 顶部工具栏 -->
<script type="text/html" id="notificationToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="send">
      <i class="layui-icon layui-icon-release"></i> 发送系统通知
    </button>
  </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="notificationRowBar">
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<!-- 发送系统通知（弹层模板，静态表单，直接内联，无需额外接口） -->
<script type="text/html" id="sendNotifyTpl">
  <form class="layui-form layuimini-form" lay-filter="notifySendForm" action="" style="padding:20px 25px 0">

    <div class="layui-form-item">
      <label class="layui-form-label required">标题</label>
      <div class="layui-input-block">
        <input type="text" name="title" class="layui-input" lay-verify="required"
               placeholder="通知标题" autocomplete="off">
      </div>
    </div>

    <div class="layui-form-item layui-form-text">
      <label class="layui-form-label">内容</label>
      <div class="layui-input-block">
        <textarea name="content" class="layui-textarea" placeholder="通知内容（可选）"></textarea>
      </div>
    </div>

    <div class="layui-form-item">
      <label class="layui-form-label">发送目标</label>
      <div class="layui-input-block">
        <input type="radio" name="target" value="all" title="全部用户" lay-filter="notifyTarget" checked>
        <input type="radio" name="target" value="user" title="指定用户" lay-filter="notifyTarget">
        <input type="radio" name="target" value="group" title="指定用户组" lay-filter="notifyTarget">
      </div>
    </div>

    <div class="layui-form-item" id="targetUserRow" style="display:none">
      <label class="layui-form-label">用户名</label>
      <div class="layui-input-block">
        <input type="text" name="username" class="layui-input" placeholder="目标用户名" autocomplete="off">
      </div>
    </div>

    <div class="layui-form-item" id="targetGroupRow" style="display:none">
      <label class="layui-form-label">用户组</label>
      <div class="layui-input-block">
        <select name="group_id">
          <?php foreach ($groups as $g): ?>
            <option value="<?= (int)($g['id'] ?? 0) ?>">
              <?= htmlspecialchars((string)($g['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="layui-form-item" style="text-align:right;padding-bottom:15px">
      <button type="button" class="layui-btn layui-btn-primary" id="notifySendCancel">取消</button>
      <button class="layui-btn" lay-submit lay-filter="notifySendSubmit">发送</button>
    </div>
  </form>
</script>

<table class="layui-hide" id="notificationTable" lay-filter="notificationTable"></table>

<script>
layui.use(['table', 'form', 'layer', 'util'], function () {
  var table = layui.table;
  var form = layui.form;
  var $ = layui.jquery;
  var util = layui.util;
  var layer = layui.layer;

  var GROUP_NAMES = <?= json_encode($groupNames, $jsonFlags) ?>;

  /** HTML 转义 */
  function esc(v) {
    return util.escape(v == null ? '' : String(v));
  }

  /** 千分位，等价于原来 PHP 的 number_format */
  function num(n) {
    return String(parseInt(n, 10) || 0).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /** 时间戳（秒）→ yyyy-MM-dd HH:mm */
  function fmtTime(ts) {
    ts = parseInt(ts, 10) || 0;
    return ts > 0 ? util.toDateString(ts * 1000, 'yyyy-MM-dd HH:mm') : '-';
  }

  /** 类型徽章：系统 / 回复 / 提及，其余原样显示 */
  function typeBadge(typeKey) {
    var map = {
      'system':  ['系统', 'layui-bg-blue'],
      'reply':   ['回复', 'layui-bg-green'],
      'mention': ['提及', 'layui-bg-orange']
    };
    var hit = map[typeKey];
    if (hit) { return '<span class="layui-badge ' + hit[1] + '">' + esc(hit[0]) + '</span>'; }
    var label = typeKey !== '' ? typeKey : '未知';
    return '<span class="layui-badge layui-bg-gray">' + esc(label) + '</span>';
  }

  /** 接收用户：群发显示发送范围，逐条显示收件人 */
  function recipientCell(d) {
    var isBatch = parseInt(d.is_batch, 10) === 1;
    var count = Math.max(1, parseInt(d.recipients, 10) || 1);
    var html;

    if (isBatch) {
      var scope = String(d.target_type == null ? '' : d.target_type);
      if (scope === 'group') {
        var gid = parseInt(d.target_id, 10) || 0;
        var gname = GROUP_NAMES[gid] != null ? GROUP_NAMES[gid] : ('#' + gid);
        html = '<span class="layui-badge layui-bg-cyan">用户组 · ' + esc(gname) + '</span>';
      } else if (scope === 'user') {
        var uname = String(d.recipient_username == null ? '' : d.recipient_username);
        html = esc(uname !== '' ? uname : '指定用户');
      } else {
        html = '<span class="layui-badge layui-bg-blue">全部用户</span>';
      }
      if (count > 1) {
        html += ' <span class="admin-muted">共 ' + num(count) + ' 人</span>';
      }
      return html;
    }

    var recipient = String(d.recipient_username == null ? '' : d.recipient_username);
    return esc(recipient !== '' ? recipient : '-');
  }

  /** 状态：群发看整批已读进度，逐条看单条已读 */
  function statusCell(d) {
    var isBatch = parseInt(d.is_batch, 10) === 1;
    var count = Math.max(1, parseInt(d.recipients, 10) || 1);
    var read = parseInt(d.read_count, 10) || 0;

    if (isBatch && count > 1) {
      var done = read >= count;
      return '<span class="layui-badge ' + (done ? 'layui-bg-green' : 'layui-bg-gray') + '">'
           + (done ? '全部已读' : '未读 ' + num(count - read)) + '</span>'
           + '<div class="admin-muted">已读 ' + num(read) + '/' + num(count) + '</div>';
    }

    return read > 0
      ? '<span class="layui-badge layui-bg-green">已读</span>'
      : '<span class="layui-badge layui-bg-gray">未读</span>';
  }

  table.render({
    elem: '#notificationTable',
    url: '/admin/api/notifications',
    where: {
      search: <?= json_encode($search, $jsonFlags) ?>,
      type: <?= json_encode($type, $jsonFlags) ?>
    },
    toolbar: '#notificationToolbar',
    defaultToolbar: ['filter', 'print'],
    cols: [[
      { field: 'id', width: 70, title: 'ID', templet: function (d) {
          return '<span class="admin-muted">' + (parseInt(d.id, 10) || 0) + '</span>';
        } },
      { field: 'title', minWidth: 180, title: '标题', templet: function (d) {
          var title = String(d.title == null ? '' : d.title);
          return '<span class="admin-ellipsis" title="' + esc(title) + '">' + esc(title) + '</span>';
        } },
      { field: 'recipient_username', width: 190, title: '接收用户', templet: function (d) {
          return recipientCell(d);
        } },
      { field: 'from_username', width: 120, title: '发送者', templet: function (d) {
          var sender = String(d.from_username == null ? '' : d.from_username);
          return esc(sender !== '' ? sender : '系统');
        } },
      { field: 'type', width: 80, title: '类型', templet: function (d) {
          return typeBadge(String(d.type == null ? '' : d.type));
        } },
      { field: 'read_count', width: 110, title: '状态', templet: function (d) {
          return statusCell(d);
        } },
      { field: 'created_at', width: 150, title: '时间', templet: function (d) {
          return '<span class="admin-muted">' + esc(fmtTime(d.created_at)) + '</span>';
        } },
      { title: '操作', width: 90, toolbar: '#notificationRowBar', align: 'center' }
    ]],
    page: { curr: <?= $page ?>, limit: 20, limits: [10, 20, 30, 50] },
    text: { none: <?= json_encode($emptyText, $jsonFlags) ?> },
    skin: 'line'
  });

  // 搜索 / 清空
  form.on('submit(notificationSearchSubmit)', function (data) {
    table.reload('notificationTable', {
      where: { search: data.field.search || '', type: data.field.type || '' },
      page: { curr: 1 }
    });
    return false;
  });

  $('#notificationSearchReset').on('click', function () {
    $('input[name="search"]', '#notificationSearch').val('');
    $('select[name="type"]', '#notificationSearch').val('');
    form.render('select', 'notificationSearch');
    table.reload('notificationTable', {
      where: { search: '', type: '' },
      page: { curr: 1 }
    });
  });

  // 行操作事件
  table.on('tool(notificationTable)', function (obj) {
    var d = obj.data;

    if (obj.event === 'delete') {
      var isBatch = parseInt(d.is_batch, 10) === 1;
      var text = isBatch ? '确定要删除这次发送的全部通知吗？' : '确定要删除该通知吗？';
      var data = isBatch ? { id: d.id, batch: 1 } : { id: d.id };
      AdminUi.confirmPost(text, '/admin/notifications/delete', data, function () {
        table.reload('notificationTable');
      });
    }
  });

  // ---------------- 发送系统通知 ----------------

  var notifyLayerIndex = 0;

  /** 发送目标切换：只显示对应的输入项 */
  function syncTargetRows(value) {
    $('#targetUserRow').toggle(value === 'user');
    $('#targetGroupRow').toggle(value === 'group');
  }

  form.on('radio(notifyTarget)', function (data) {
    syncTargetRows(data.value);
  });

  function openSendPanel() {
    notifyLayerIndex = layer.open({
      title: '发送系统通知',
      type: 1,
      shade: 0.2,
      shadeClose: false,
      area: ['620px', '580px'],
      content: $('#sendNotifyTpl').html(),
      success: function () {
        form.render(null, 'notifySendForm');
        syncTargetRows('all');
      }
    });
  }

  // 工具栏事件
  table.on('toolbar(notificationTable)', function (obj) {
    if (obj.event === 'send') { openSendPanel(); }
  });

  // 提交发送
  form.on('submit(notifySendSubmit)', function (data) {
    AdminUi.post('/admin/notifications/send', data.field, function () {
      layer.close(notifyLayerIndex);
      table.reload('notificationTable', { page: { curr: 1 } });
    });
    return false;
  });

  // 取消（弹层里的按钮是后插入的，用事件委托）
  $(document).on('click', '#notifySendCancel', function () {
    layer.close(notifyLayerIndex);
  });
});
</script>
