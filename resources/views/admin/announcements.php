<?php
/**
 * 后台 - 公告管理（layuimini 子页面）
 *
 * 形状照 forums.php（列表页参考实现）：
 *   <script type="text/html" id="announcementToolbar"> 顶部工具栏（新增公告）
 *   <table class="layui-hide" id="announcementTable">   表格占位
 *   <script type="text/html" id="announcementRowBar">   行内操作（编辑/启用禁用/删除）
 *   layui.use(['table']) → table.render({url:'/admin/api/announcements', cols:[...]})
 *
 * 搜索区：Announcement::adminList() 只按 `rank` DESC 取全量，不接受任何筛选参数
 * （见 announcementsApi()，它只是把 adminList() 的结果原样 jsonTable 出去），
 * 所以这里**没有**搜索表单 —— 加一个点了没用的搜索框比没有更糟。
 *
 * 分页：api 不支持分页（返回全量 + count），所以 page:false、一次取完，
 * 排序（ID / 排序值）交给 layui 在前端做。
 *
 * 表单：/admin/announcements/form?id=N 是 layout_child 渲染的完整子页面，
 * 用 layer 的 iframe 弹层打开（AnnounceController::announcementForm 已改为 renderAdmin）。
 *
 * 变量：$typeLabels（类型 id → 中文标签，控制器单一数据源）
 *
 * 公告已没有「标题」这个字段（表单去掉了）：表格不再有「标题」列，只展示内容摘要。
 * 数据库/接口里的 title 列保留，由 Announcement 模型按内容首行派生。
 */

$typeLabels = is_array($typeLabels ?? null) ? $typeLabels : [0 => '普通', 1 => '重要', 2 => '紧急'];

/** 内联到 JS 的标签表：中文不转义、< > 等转义，避免结束标签注入 */
$typeLabelsJson = json_encode(
    $typeLabels,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>

<!-- 顶部工具栏 -->
<script type="text/html" id="announcementToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="add">
      <i class="layui-icon layui-icon-add-1"></i> 新增公告
    </button>
  </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="announcementRowBar">
  <a class="layui-btn layui-btn-xs" lay-event="edit">编辑</a>
  <a class="layui-btn layui-btn-xs layui-btn-normal" lay-event="toggle">{{= d.is_enabled ? '禁用' : '启用' }}</a>
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<table class="layui-hide" id="announcementTable" lay-filter="announcementTable"></table>

<div class="admin-muted" style="margin-top:8px">共 <span id="announcementCount">0</span> 条公告</div>

<script>
layui.use(['table', 'layer', 'util'], function () {
  var table = layui.table;
  var util = layui.util;

  var TYPE_LABELS = <?= $typeLabelsJson ?>;

  /** HTML 转义（单元格里所有来自数据库的文本都要过一遍） */
  function esc(v) {
    return util.escape(v == null ? '' : String(v));
  }

  /** 时间戳（秒）→ 指定格式；0/空 表示未设置 */
  function fmtTime(ts, pattern) {
    ts = parseInt(ts, 10) || 0;
    return ts > 0 ? util.toDateString(ts * 1000, pattern) : '';
  }

  /** 按字符（码点）截断，等价于原来 PHP 的 mb_strlen / mb_substr */
  function cut(text, max) {
    var chars = Array.from(String(text == null ? '' : text));
    return chars.length > max ? chars.slice(0, max).join('') + '...' : chars.join('');
  }

  /** 类型徽章：普通 / 重要 / 紧急 */
  function typeBadge(type) {
    var map = {
      2: 'layui-bg-red',
      1: 'layui-bg-orange'
    };
    var label = TYPE_LABELS[type] != null ? TYPE_LABELS[type] : '普通';
    return '<span class="layui-badge ' + (map[type] || 'layui-bg-gray') + '">' + esc(label) + '</span>';
  }

  table.render({
    elem: '#announcementTable',
    url: '/admin/api/announcements',
    toolbar: '#announcementToolbar',
    defaultToolbar: ['filter', 'print'],
    cols: [[
      { field: 'id', width: 70, title: 'ID', sort: true, templet: function (d) {
          return '<span class="admin-muted">' + (parseInt(d.id, 10) || 0) + '</span>';
        } },
      { field: 'content', minWidth: 320, title: '内容', templet: function (d) {
          // 公告已无「标题」概念：列表就展示内容摘要（老数据 content 为空时回退 title）
          var content = String(d.content == null ? '' : d.content);
          if (content === '') { content = String(d.title == null ? '' : d.title); }
          if (content === '') { return '<span class="admin-muted">-</span>'; }
          return '<span class="admin-ellipsis" title="' + esc(content) + '">'
               + esc(cut(content, 40)) + '</span>';
        } },
      { field: 'url', width: 80, title: '链接', templet: function (d) {
          var url = String(d.url == null ? '' : d.url);
          if (url === '') { return '<span class="admin-muted">-</span>'; }
          return '<a href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">查看</a>';
        } },
      { field: 'type', width: 80, title: '类型', templet: function (d) {
          return typeBadge(parseInt(d.type, 10) || 0);
        } },
      { field: 'is_enabled', width: 80, title: '状态', templet: function (d) {
          return d.is_enabled
            ? '<span class="layui-badge layui-bg-green">启用</span>'
            : '<span class="layui-badge layui-bg-gray">禁用</span>';
        } },
      { field: 'rank', width: 80, title: '排序', sort: true, align: 'center', templet: function (d) {
          return '<span class="admin-muted">' + (parseInt(d.rank, 10) || 0) + '</span>';
        } },
      { field: 'start_at', minWidth: 190, title: '有效期', templet: function (d) {
          var start = fmtTime(d.start_at, 'MM-dd HH:mm') || '即时';
          var end   = fmtTime(d.end_at, 'MM-dd HH:mm') || '永久';
          return '<span class="admin-muted">' + esc(start) + ' ~ ' + esc(end) + '</span>';
        } },
      { field: 'created_at', width: 140, title: '创建时间', templet: function (d) {
          return '<span class="admin-muted">' + esc(fmtTime(d.created_at, 'yyyy-MM-dd HH:mm')) + '</span>';
        } },
      { title: '操作', width: 200, toolbar: '#announcementRowBar', align: 'center' }
    ]],
    page: false,
    limit: 200,
    done: function (res, curr, count) {
      // api 一次返回全量，页码条不显示，数量自己标出来（原来是面板标题里的「共 N 条」）
      var el = document.getElementById('announcementCount');
      if (el) { el.textContent = count; }
    },
    text: { none: '还没有公告，点右上角「新增公告」发布第一条' },
    skin: 'line'
  });

  /** 打开新增 / 编辑表单（layer 的 iframe 弹层，内容是 layout_child 渲染的完整页面） */
  function openForm(id) {
    layui.layer.open({
      title: id ? '编辑公告' : '新增公告',
      type: 2,
      shade: 0.2,
      shadeClose: false,
      maxmin: true,
      area: ['820px', '92%'],
      content: '/admin/announcements/form?id=' + (id || 0)
    });
  }

  // 工具栏事件
  table.on('toolbar(announcementTable)', function (obj) {
    if (obj.event === 'add') { openForm(0); }
  });

  // 行操作事件
  table.on('tool(announcementTable)', function (obj) {
    var d = obj.data;

    if (obj.event === 'edit') {
      openForm(d.id);
    } else if (obj.event === 'toggle') {
      var enabled = d.is_enabled ? 0 : 1;
      AdminUi.post('/admin/announcements/toggle', { id: d.id, is_enabled: enabled }, function () {
        table.reload('announcementTable');
      });
    } else if (obj.event === 'delete') {
      AdminUi.confirmPost('确定要删除该公告吗？', '/admin/announcements/delete', { id: d.id }, function () {
        table.reload('announcementTable');
      });
    }
  });
});
</script>
