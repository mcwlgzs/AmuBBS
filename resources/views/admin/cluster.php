<?php
/**
 * 后台 - 集群管理（layuimini 子页面）
 *
 * 变量：$nodes（每项含 status_info / config_data）
 *
 * 数据来源：控制器把节点连同在线状态一起传进来（没有集群 JSON API 可查），
 * 所以这里用 layui table 的「本地数据」模式渲染，按节点类型分组，一组一张表。
 *
 * 安全说明：旧版把节点的认证信息（含密码）json_encode 后塞进按钮的 data-config
 * 属性里，密码会直接出现在页面源码中。这里沿用了修复后的做法 ——
 * 页面不输出任何认证信息，「测试连接」只回传节点 id，由服务端查库取配置。
 */

$nodes = is_array($nodes ?? null) ? $nodes : [];

$typeLabels = ['web' => 'Web 节点', 'mysql' => 'MySQL 节点', 'redis' => 'Redis 节点'];
$typeBadge  = ['web' => 'layui-bg-blue', 'mysql' => 'layui-bg-green', 'redis' => 'layui-bg-red'];

/** 按类型分组，保持 web → mysql → redis 的顺序 */
$grouped = [];
foreach ($typeLabels as $type => $label) {
    $grouped[$type] = array_values(array_filter($nodes, static fn($n) => ($n['type'] ?? '') === $type));
}

$totalCount = count($nodes);
$hasAnyNode = $totalCount > 0;
?>

<blockquote class="layui-elem-quote layui-quote-nm">
  共 <span class="admin-num"><?= $totalCount ?></span> 个节点。
  为避免 SSRF，节点主机必须是可解析且非内网的地址；内网/保留地址会被拒绝。
</blockquote>

<?php if (!$hasAnyNode): ?>
  <blockquote class="layui-elem-quote layui-quote-nm">
    还没有集群节点，点击下方「添加节点」开始配置 Web / MySQL / Redis 节点。
  </blockquote>
<?php endif; ?>

<?php foreach ($typeLabels as $type => $label): ?>
  <?php
    $typeNodes = $grouped[$type];
    $tableId   = 'clusterTable-' . $type;
    $toolbarId = 'clusterToolbar-' . $type;
    $rowBarId  = 'clusterRowBar-' . $type;
  ?>

  <div class="layui-card">
    <div class="layui-card-header">
      <span class="layui-badge <?= $typeBadge[$type] ?? 'layui-bg-gray' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="admin-muted" style="margin-left:8px"><?= count($typeNodes) ?> 个</span>
    </div>
    <div class="layui-card-body">

      <script type="text/html" id="<?= $toolbarId ?>">
        <div class="layui-btn-container">
          <button class="layui-btn layui-btn-sm" lay-event="add">
            <i class="layui-icon layui-icon-add-1"></i> 添加<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
          </button>
        </div>
      </script>

      <script type="text/html" id="<?= $rowBarId ?>">
        <a class="layui-btn layui-btn-xs layui-btn-normal" lay-event="test">测试</a>
        <a class="layui-btn layui-btn-xs" lay-event="edit">编辑</a>
        <a class="layui-btn layui-btn-xs layui-btn-primary" lay-event="toggle">{{= d.status == 1 ? '禁用' : '启用' }}</a>
        <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
      </script>

      <table class="layui-hide" id="<?= $tableId ?>" lay-filter="<?= $tableId ?>"></table>

    </div>
  </div>
<?php endforeach; ?>

<script>
layui.use(['table', 'form'], function () {
  var table = layui.table;
  var $ = layui.jquery;

  var typeLabels = <?= json_encode($typeLabels, JSON_UNESCAPED_UNICODE) ?>;
  var typeNodes  = <?= json_encode($grouped, JSON_UNESCAPED_UNICODE) ?>;

  /** 行内模板要拼 HTML，主机名等字段先转义掉 & < > " ' */
  function esc(v) {
    return String(v === null || v === undefined ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /** 打开新增 / 编辑表单（layer 的 iframe 弹层，内容是 layout_child 渲染的完整页面） */
  function openForm(id) {
    layui.layer.open({
      title: id ? '编辑节点' : '添加节点',
      type: 2,
      shade: 0.2,
      shadeClose: false,
      maxmin: true,
      area: ['780px', '88%'],
      content: '/admin/cluster/form?id=' + (id || 0)
    });
  }

  /** 行内按钮与顶部工具栏共用的一套事件 */
  function handleEvent(tableId, obj) {
    var d = obj.data;

    if (obj.event === 'add') {
      openForm(0);
      return;
    }
    if (obj.event === 'edit') {
      openForm(d.id);
      return;
    }
    if (obj.event === 'test') {
      // 只探测、不改数据：只回传 id，认证信息由服务端查库取（页面里没有密码）
      AdminUi.post('/admin/cluster/test', { id: d.id });
      return;
    }
    if (obj.event === 'toggle') {
      AdminUi.confirmPost('确定要' + (d.status == 1 ? '禁用' : '启用') + '节点「' + d.name + '」吗？',
        '/admin/cluster/toggle', { id: d.id });
      return;
    }
    if (obj.event === 'delete') {
      AdminUi.confirmPost('确定要删除节点「' + d.name + '」吗？删除后不可恢复。',
        '/admin/cluster/delete', { id: d.id });
    }
  }

  Object.keys(typeLabels).forEach(function (type) {
    var tableId = 'clusterTable-' + type;

    table.render({
      elem: '#' + tableId,
      id: tableId,
      data: typeNodes[type] || [],
      toolbar: '#clusterToolbar-' + type,
      defaultToolbar: [],
      cols: [[
        { field: 'name', minWidth: 160, title: '名称' },
        { field: 'host', minWidth: 180, title: '地址', templet: function (d) {
            return '<span class="admin-muted">' + esc(d.host) + ':' + d.port + '</span>';
          } },
        { field: 'weight', width: 80, title: '权重', align: 'right', templet: function (d) {
            return '<span class="admin-muted admin-num">' + (d.weight || 1) + '</span>';
          } },
        { field: 'status', width: 170, title: '状态', templet: function (d) {
            var html = '';
            var info = d.status_info || {};
            if (info.online) {
              html += '<span class="layui-badge layui-bg-green">在线</span>';
            } else {
              html += '<span class="layui-badge">离线</span>';
            }
            if (d.status != 1) {
              html += ' <span class="layui-badge layui-bg-orange">已禁用</span>';
            }
            return html;
          } },
        { field: 'response_time', width: 110, title: '响应', align: 'right', templet: function (d) {
            var info = d.status_info || {};
            var rt = info.response_time;
            return (rt === null || rt === undefined)
              ? '<span class="admin-muted">-</span>'
              : '<span class="admin-muted admin-num">' + rt + ' ms</span>';
          } },
        { title: '操作', minWidth: 250, align: 'center', toolbar: '#clusterRowBar-' + type }
      ]],
      page: false,
      limit: 200,
      text: { none: '该类型下还没有节点，点上方按钮添加' },
      skin: 'line'
    });

    table.on('toolbar(' + tableId + ')', function (obj) {
      handleEvent(tableId, obj);
    });

    table.on('tool(' + tableId + ')', function (obj) {
      handleEvent(tableId, obj);
    });
  });
});
</script>
