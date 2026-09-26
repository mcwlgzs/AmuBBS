<?php
/**
 * 后台 - 操作日志（layuimini 子页面）
 *
 * 两种模式（沿用原来的 filter 参数，只是切换方式换成 layui 按钮）：
 *   - 文件日志（默认，$filter === ''）：服务端读 storage/logs/<date>.log，
 *     纯文本输出，这里放在 layui 面板的 <pre> 里（保持每一行原样，含空白）；
 *     日期用 laydate 选，提交后整页跳转 /admin/logs?date=YYYY-MM-DD。
 *   - 版主操作日志（$filter === 'mod'）：layui table 打 /admin/api/logs，
 *     筛选 action / target_type（这两个是 Log::modWhere() 真正读取的参数）。
 *
 * 表格 id：logTable。分页由 layui 传 page / limit（api 的 limit 被夹在 10~50）。
 * 排序：api 不认 sort / dir（Log::modPage() 写死 ORDER BY l.created_at DESC），
 * 所以表格不放排序箭头。
 *
 * 详情列的 key: value 格式化：原来在 PHP 里对 JSON 详情做展开（$formatDetail），
 * 现在数据来自 JSON API，等价逻辑在前端 formatDetail() 里做。
 *
 * 变量：$filter, $logs（文本行）, $date, $rows, $total, $page, $pages,
 *       $search（action / target_type）, $actionTypes
 */

$filter      = (string)($filter ?? '');
$logs        = is_array($logs ?? null) ? $logs : [];
$date        = (string)($date ?? date('Y-m-d'));
$search      = is_array($search ?? null) ? $search : [];
$actionTypes = is_array($actionTypes ?? null) ? $actionTypes : [];
$page        = max(1, (int)($page ?? 1));

$isMod    = $filter === 'mod';
$curAction = (string)($search['action'] ?? '');
$curTarget = (string)($search['target_type'] ?? '');

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

/**
 * 操作类型下拉项。
 *
 * Log::modActionTypes() 返回的是行数组（每行形如 ['action' => 'mod_delete_thread']），
 * 这里统一取 action 字段；旧视图直接把行数组丢给 htmlspecialchars()，
 * 在 PHP 8 下会抛 TypeError（只要库里有一条版主日志就 500）。
 */
$actionOptions = [];
foreach ($actionTypes as $at) {
    $value = is_array($at) ? (string)($at['action'] ?? '') : (string)$at;
    if ($value !== '') {
        $actionOptions[] = $value;
    }
}

/** 文件日志全文：一次性转义后放进 <pre>，保证换行与缩进不被折叠 */
$logText = htmlspecialchars(implode("\n", array_map(static fn($line) => (string)$line, $logs)), ENT_QUOTES, 'UTF-8');
?>

<!-- 模式切换 -->
<div style="margin-bottom:10px">
  <a class="layui-btn layui-btn-sm <?= $isMod ? 'layui-btn-primary' : '' ?>" href="/admin/logs">
    <i class="fa fa-history"></i> 文件日志
  </a>
  <a class="layui-btn layui-btn-sm <?= $isMod ? '' : 'layui-btn-primary' ?>" href="/admin/logs?filter=mod">
    <i class="fa fa-list-alt"></i> 版主操作
  </a>
  <span class="admin-muted" style="margin-left:8px">
    <?= $isMod ? '版主在版块内的管理操作记录' : '按天记录的后台操作日志' ?>
  </span>
</div>

<?php if ($isMod): ?>

  <!-- 搜索区 -->
  <fieldset class="layui-elem-field layui-field-title table-search-fieldset">
    <legend>搜索</legend>
    <div style="margin: 5px 0 10px">
      <form class="layui-form" id="logSearch" lay-filter="logSearch" action="">
        <div class="layui-form-item">
          <div class="layui-inline">
            <label class="layui-form-label">操作类型</label>
            <div class="layui-input-inline" style="width:200px">
              <select name="action">
                <option value="">全部操作</option>
                <?php foreach ($actionOptions as $at): ?>
                  <option value="<?= htmlspecialchars($at, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $curAction === $at ? 'selected' : '' ?>>
                    <?= htmlspecialchars($at, ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="layui-inline">
            <label class="layui-form-label">目标类型</label>
            <div class="layui-input-inline">
              <input type="text" name="target_type" class="layui-input" autocomplete="off"
                     placeholder="目标类型" value="<?= htmlspecialchars($curTarget, ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>
          <div class="layui-inline">
            <button class="layui-btn layui-btn-sm" lay-submit lay-filter="logSearchSubmit">搜索</button>
            <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="logSearchReset">重置</button>
          </div>
        </div>
      </form>
    </div>
  </fieldset>

  <table class="layui-hide" id="logTable" lay-filter="logTable"></table>

  <script>
  layui.use(['table', 'form', 'util'], function () {
    var table = layui.table;
    var form = layui.form;
    var $ = layui.jquery;
    var util = layui.util;

    /** 当前筛选条件：重载表格时始终带上，避免「翻页 / 搜索」把筛选丢掉 */
    var lastWhere = {
      action: <?= json_encode($curAction, $jsonFlags) ?>,
      target_type: <?= json_encode($curTarget, $jsonFlags) ?>
    };

    /** HTML 转义 */
    function esc(v) {
      return util.escape(v == null ? '' : String(v));
    }

    /** 时间戳（秒）→ yyyy-MM-dd HH:mm:ss */
    function fmtTime(ts) {
      ts = parseInt(ts, 10) || 0;
      return ts > 0 ? util.toDateString(ts * 1000, 'yyyy-MM-dd HH:mm:ss') : '-';
    }

    /**
     * 详情列：原来是前端 JSON.parse 后拼 key: value，等价逻辑搬到这里。
     * 与旧 PHP 实现保持一致：布尔转 true/false、数组转 JSON、解析失败原样显示。
     */
    function formatDetail(detail) {
      if (typeof detail !== 'string' || detail === '') { return ''; }

      var trimmed = detail.replace(/^\s+/, '');
      if (trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[') {
        try {
          var decoded = JSON.parse(detail);
          if (decoded && typeof decoded === 'object') {
            var parts = [];
            for (var k in decoded) {
              if (!Object.prototype.hasOwnProperty.call(decoded, k)) { continue; }
              var v = decoded[k];
              if (v !== null && typeof v === 'object') {
                v = JSON.stringify(v);
              } else if (typeof v === 'boolean') {
                v = v ? 'true' : 'false';
              } else if (v === null || v === undefined) {
                v = '';
              }
              parts.push(k + ': ' + String(v));
            }
            return parts.join('　');
          }
        } catch (e) { /* 不是 JSON 就原样显示 */ }
      }

      return detail;
    }

    table.render({
      elem: '#logTable',
      url: '/admin/api/logs',
      where: lastWhere,
      defaultToolbar: ['filter', 'print'],
      cols: [[
        { field: 'created_at', width: 170, title: '时间', templet: function (d) {
            return '<span class="admin-muted">' + esc(fmtTime(d.created_at)) + '</span>';
          } },
        { field: 'operator_name', width: 120, title: '操作者', templet: function (d) {
            var name = String(d.operator_name == null ? '' : d.operator_name);
            if (name === '' && (parseInt(d.user_id, 10) || 0) > 0) {
              name = 'ID:' + (parseInt(d.user_id, 10) || 0);
            }
            return esc(name !== '' ? name : '-');
          } },
        { field: 'action', width: 130, title: '操作', templet: function (d) {
            var action = String(d.action == null ? '' : d.action);
            return action !== ''
              ? '<span class="layui-badge layui-bg-blue">' + esc(action) + '</span>'
              : '-';
          } },
        { field: 'target_type', width: 150, title: '目标', templet: function (d) {
            var targetType = String(d.target_type == null ? '' : d.target_type);
            var targetId = parseInt(d.target_id, 10) || 0;
            return esc(targetType !== '' ? targetType : '-') + (targetId > 0 ? '#' + targetId : '');
          } },
        { field: 'detail', minWidth: 220, title: '详情', templet: function (d) {
            var text = formatDetail(d.detail);
            if (text === '') { return '-'; }
            return '<span class="admin-muted admin-ellipsis" title="' + esc(text) + '">' + esc(text) + '</span>';
          } },
        { field: 'ip', width: 130, title: 'IP', templet: function (d) {
            var ip = String(d.ip == null ? '' : d.ip);
            return '<span class="admin-muted">' + esc(ip !== '' ? ip : '-') + '</span>';
          } }
      ]],
      page: { curr: <?= $page ?>, limit: 30, limits: [10, 20, 30, 50] },
      text: {
        none: '<div>暂无操作日志</div><div class="admin-muted">版主在版块内的管理操作会记录在这里</div>'
      },
      skin: 'line'
    });

    // 搜索 / 重置
    form.on('submit(logSearchSubmit)', function (data) {
      lastWhere = {
        action: data.field.action || '',
        target_type: data.field.target_type || ''
      };
      table.reload('logTable', { where: lastWhere, page: { curr: 1 } });
      return false;
    });

    $('#logSearchReset').on('click', function () {
      $('select[name="action"]', '#logSearch').val('');
      $('input[name="target_type"]', '#logSearch').val('');
      form.render('select', 'logSearch');
      lastWhere = { action: '', target_type: '' };
      table.reload('logTable', { where: lastWhere, page: { curr: 1 } });
    });
  });
  </script>

<?php else: ?>

  <div class="layui-card">
    <div class="layui-card-header">
      <i class="fa fa-history"></i> 日志文件 <?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?>

      <div style="float:right">
        <form class="layui-form" id="logDateForm" lay-filter="logDateForm" action="" style="display:inline-block">
          <div class="layui-inline" style="margin:0 6px 0 0">
            <input type="text" name="date" id="logDate" class="layui-input" autocomplete="off"
                   placeholder="选择日期" value="<?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?>"
                   style="height:30px;line-height:30px;width:150px">
          </div>
          <button class="layui-btn layui-btn-sm layui-btn-primary" lay-submit lay-filter="logDateSubmit">查看</button>
        </form>
      </div>
    </div>
    <div class="layui-card-body">
      <?php if ($logText === ''): ?>
        <div style="padding:30px 0;text-align:center">
          <div>该日期暂无日志</div>
          <div class="admin-muted">换一个日期，或确认服务器日志目录已写入</div>
        </div>
      <?php else: ?>
        <pre style="margin:0;max-height:600px;overflow:auto;font-size:12px;line-height:1.7;white-space:pre-wrap;word-break:break-all;background:#fafafa;border:1px solid #eee;border-radius:2px;padding:10px"><?= $logText ?></pre>
      <?php endif; ?>
    </div>
  </div>

  <script>
  layui.use(['form', 'laydate'], function () {
    var form = layui.form;
    var laydate = layui.laydate;

    laydate.render({ elem: '#logDate', type: 'date' });

    form.on('submit(logDateSubmit)', function (data) {
      var date = data.field.date || '';
      window.location.href = '/admin/logs' + (date !== '' ? '?date=' + encodeURIComponent(date) : '');
      return false;
    });
  });
  </script>

<?php endif; ?>
