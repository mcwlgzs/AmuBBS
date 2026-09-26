<?php
/**
 * 后台 - 附件管理（layuimini 子页面片段）
 * ==========================================================================
 * 由 layout_child.php 包成一个独立文档、跑在外壳的 iframe 里，
 * 所以这里是纯片段：没有文档声明，没有 html/head/body 标签，也没有任何 htmx 属性。
 *
 * 结构照 forums.php（列表页参考实现）：
 *   统计概览（4 张卡，来自 Attachment::adminStats()）
 *   fieldset.table-search-fieldset  搜索区
 *   #attachToolbar                  刷新（本页没有批量接口，所以没有批量按钮）
 *   #attachTable                    layui table，数据来自 /admin/api/attachments
 *   #attachRowBar                   行内删除
 *
 * 能筛的只有 AttachController::fetchAttachments() 真正读的两个参数：
 *   search（文件名）/ type（image|file）
 * **没有排序**：Attachment::adminList() 的 ORDER BY 是写死的 a.id DESC，
 * 接口根本不看 sort/dir，所以这一页一列都不加 sort: true —— 放一个点了没反应的
 * 排序箭头比不放更糟。
 *
 * 变量：$rows 已不再服务端渲染；统计与筛选值仍来自控制器
 *       $total, $imageCount, $fileCount, $totalSize, $search, $type
 */

$search    = (string)($search ?? '');
$type      = (string)($type ?? '');
$total     = (int)($total ?? 0);
$imageCount = (int)($imageCount ?? 0);
$fileCount  = (int)($fileCount ?? 0);
$totalSize  = (int)($totalSize ?? 0);

/** 字节数 → 人类可读（和原页面同一套规则，前端 templet 里还有一份等价的） */
$formatSize = static function (int $size): string {
    if ($size < 1024) {
        return $size . 'B';
    }
    if ($size < 1048576) {
        return round($size / 1024, 1) . 'KB';
    }

    return round($size / 1048576, 1) . 'MB';
};

/** 顶部四张统计卡 */
$cards = [
    ['label' => '附件总数', 'value' => number_format($total), 'icon' => 'fa-paperclip',
     'color' => '#1e9fff', 'sub' => '全部上传文件', 'id' => 'attachStatTotal'],
    ['label' => '图片数量', 'value' => number_format($imageCount), 'icon' => 'fa-image',
     'color' => '#5fb878', 'sub' => '可预览的图片', 'id' => ''],
    ['label' => '文件数量', 'value' => number_format($fileCount), 'icon' => 'fa-file-archive-o',
     'color' => '#ffb800', 'sub' => '非图片附件', 'id' => ''],
    ['label' => '占用空间', 'value' => $formatSize($totalSize), 'icon' => 'fa-hdd-o',
     'color' => '#16baaa', 'sub' => '磁盘用量', 'id' => ''],
];

/** 首屏 where：带着查询串打开（/admin/attachments?type=image）时表格也要按它查 */
$initialWhere = array_filter([
    'search' => trim($search),
    'type'   => trim($type),
], static fn($v): bool => $v !== '');

// 内联脚本里的数据一律走 json_encode；HEX 系列保证 </script> 之类不会截断脚本
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<!-- 统计概览（原来是一排 Bootstrap 卡片） -->
<div class="layui-row layui-col-space10" style="margin-bottom:2px">
  <?php foreach ($cards as $card): ?>
    <div class="layui-col-md3 layui-col-xs6">
      <div class="layui-card">
        <div class="layui-card-body" style="padding:12px 14px">
          <p class="admin-muted" style="font-size:12px">
            <i class="fa <?= htmlspecialchars($card['icon'], ENT_QUOTES, 'UTF-8') ?>"
               style="color:<?= htmlspecialchars($card['color'], ENT_QUOTES, 'UTF-8') ?>"></i>
            <?= htmlspecialchars($card['label'], ENT_QUOTES, 'UTF-8') ?>
          </p>
          <p<?= $card['id'] !== '' ? ' id="' . htmlspecialchars($card['id'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>
             style="font-size:22px;font-weight:600;line-height:1.5;color:<?= htmlspecialchars($card['color'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($card['value'], ENT_QUOTES, 'UTF-8') ?>
          </p>
          <p class="admin-muted" style="font-size:12px"><?= htmlspecialchars($card['sub'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- 搜索（只放接口真正读的字段） -->
<fieldset class="table-search-fieldset">
  <legend>搜索信息</legend>
  <div style="margin:10px 10px 10px 10px">
    <form class="layui-form layui-form-pane" lay-filter="attachSearchForm" onsubmit="return false">
      <div class="layui-form-item">
        <div class="layui-inline">
          <label class="layui-form-label">文件名</label>
          <div class="layui-input-inline">
            <input type="text" name="search" class="layui-input" placeholder="搜索文件名..."
                   value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>

        <div class="layui-inline">
          <label class="layui-form-label">类型</label>
          <div class="layui-input-inline">
            <select name="type">
              <option value="" <?= $type === '' ? 'selected' : '' ?>>全部类型</option>
              <option value="image" <?= $type === 'image' ? 'selected' : '' ?>>图片</option>
              <option value="file" <?= $type === 'file' ? 'selected' : '' ?>>文件</option>
            </select>
          </div>
        </div>

        <div class="layui-inline">
          <button class="layui-btn layui-btn-primary" lay-submit lay-filter="attach-search">
            <i class="layui-icon layui-icon-search"></i> 搜索
          </button>
          <button type="button" class="layui-btn layui-btn-primary" id="attachSearchReset">重置</button>
        </div>
      </div>
    </form>
  </div>
</fieldset>

<!-- 工具栏：接口没有批量删除，所以这里只放一个刷新 -->
<script type="text/html" id="attachToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm layui-btn-primary" lay-event="refresh">
      <i class="fa fa-refresh"></i> 刷新
    </button>
  </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="attachRowBar">
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<table class="layui-hide" id="attachTable" lay-filter="attachTable"></table>

<script>
layui.use(['table', 'form'], function () {
  var table = layui.table;
  var form  = layui.form;
  var $     = layui.jquery;

  var TABLE_ID = 'attachTable';

  /** 当前查询条件；这一页接口不认 sort/dir，where 里只有筛选 */
  var lastWhere = <?= json_encode((object)$initialWhere, $jsonFlags) ?>;

  /** HTML 转义：templet 里的内容都来自用户输入 */
  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /** 字节数 → 人类可读（和 PHP 里那份 $formatSize 等价） */
  function fmtSize(size) {
    size = parseInt(size, 10) || 0;
    if (size < 1024) { return size + 'B'; }
    if (size < 1048576) { return Math.round(size / 1024 * 10) / 10 + 'KB'; }

    return Math.round(size / 1048576 * 10) / 10 + 'MB';
  }

  /** Unix 秒 → YYYY-MM-DD HH:mm（原来是 PHP 的 date()） */
  function fmtTime(ts) {
    ts = parseInt(ts, 10) || 0;
    if (!ts) { return '<span class="admin-muted">-</span>'; }
    var d = new Date(ts * 1000);
    var p = function (n) { return (n < 10 ? '0' : '') + n; };

    return '<span class="admin-muted">'
         + d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
         + ' ' + p(d.getHours()) + ':' + p(d.getMinutes())
         + '</span>';
  }

  table.render({
    elem: '#attachTable',
    url: '/admin/api/attachments',
    toolbar: '#attachToolbar',
    defaultToolbar: ['filter', 'print'],
    where: lastWhere,
    cols: [[
      { field: 'id', width: 80, title: 'ID', templet: function (d) {
          return '<span class="admin-muted admin-num">' + (parseInt(d.id, 10) || 0) + '</span>';
      } },
      { field: 'filepath', width: 90, title: '预览', align: 'center', templet: function (d) {
          var path = String(d.filepath === null || d.filepath === undefined ? '' : d.filepath);

          if (parseInt(d.is_image, 10) === 1 && path !== '') {
            return '<img src="' + esc(path) + '" alt="" loading="lazy"'
                 + ' style="max-width:60px;max-height:40px;border-radius:4px">';
          }

          // 非图片：用一个文件图标代替原来的内联 SVG
          return '<i class="layui-icon layui-icon-file" style="font-size:20px;color:#94a3b8"></i>';
      } },
      { field: 'filename', minWidth: 220, title: '文件名', templet: function (d) {
          var name = String(d.filename === null || d.filename === undefined ? '' : d.filename);

          return '<span class="admin-ellipsis" title="' + esc(name) + '">' + esc(name) + '</span>';
      } },
      { field: 'username', width: 130, title: '上传者', templet: function (d) {
          var uid = parseInt(d.user_id, 10) || 0;
          if (uid <= 0) { return '<span class="admin-muted">-</span>'; }

          var name = (d.username || '') !== '' ? d.username : '未知';

          return '<a href="/user/' + uid + '">' + esc(name) + '</a>';
      } },
      { field: 'filesize', width: 100, title: '大小', align: 'right', templet: function (d) {
          return '<span class="admin-muted admin-num">' + fmtSize(d.filesize) + '</span>';
      } },
      { field: 'is_image', width: 90, title: '类型', align: 'center', templet: function (d) {
          return parseInt(d.is_image, 10) === 1
              ? '<span class="layui-badge layui-bg-green">图片</span>'
              : '<span class="layui-badge layui-bg-gray">文件</span>';
      } },
      { field: 'created_at', width: 170, title: '上传时间', templet: function (d) {
          return fmtTime(d.created_at);
      } },
      { title: '操作', minWidth: 110, toolbar: '#attachRowBar', align: 'center' }
    ]],
    page: true,
    limit: 20,
    limits: [10, 20, 30, 50],
    skin: 'line',
    text: { none: '没有匹配的附件，换个筛选条件试试' },
    done: function (res) {
      // 「附件总数」跟着当前筛选走（原来每次 htmx 重渲染整页时也是这个值）；
      // 图片/文件/占用空间是全局统计，服务端算好就行
      $('#attachStatTotal').text((parseInt(res.count, 10) || 0).toLocaleString('en-US'));
    }
  });

  // ---------------- 工具栏 / 行内操作 ----------------
  table.on('toolbar(attachTable)', function (obj) {
    if (obj.event === 'refresh') {
      table.reload(TABLE_ID);
    }
  });

  table.on('tool(attachTable)', function (obj) {
    if (obj.event !== 'delete') { return; }

    var id = parseInt(obj.data.id, 10) || 0;

    AdminUi.confirmPost('确定要删除这个附件吗？删除后不可恢复。',
      '/admin/attachments/delete', { id: id }, function () {
        table.reload(TABLE_ID);
      });
  });

  // ---------------- 搜索 / 重置 ----------------
  form.on('submit(attach-search)', function (data) {
    lastWhere = data.field;
    table.reload(TABLE_ID, { where: lastWhere, page: { curr: 1 } });

    return false;   // 阻止 layui 默认的表单提交
  });

  $('#attachSearchReset').on('click', function () {
    var formEl = $('form[lay-filter="attachSearchForm"]')[0];
    if (formEl) { formEl.reset(); }
    form.render('select', 'attachSearchForm');   // 让 layui 渲染的下拉也回到「全部类型」

    lastWhere = {};
    table.reload(TABLE_ID, { where: lastWhere, page: { curr: 1 } });
  });
});
</script>
