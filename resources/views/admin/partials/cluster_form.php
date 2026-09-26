<?php
/**
 * 集群节点表单（layuimini 子页面，由 layer 的 iframe 弹层打开）
 *
 * 变量：$node（null 表示新增）, $isEdit
 *
 * 说明：
 *   - 编辑时节点类型只读（与旧版一致，后端也不允许改类型），类型用隐藏域显式回传；
 *   - 认证信息只在 MySQL / Redis 时显示，且编辑时密码留空表示「不修改」；
 *   - 「测试连接」只回传表单字段（不含页面上的密码泄漏风险：本页是表单原文，
 *     行内的测试按钮走的是 id，这里保留表单试连能力）；
 *   - 保存成功后 AdminUi.closeLayerAndReload('clusterTable-<类型>')：
 *     先刷新外层集群页对应类型的表格，再关掉弹层。
 */

$isEdit = $isEdit ?? false;
$n      = is_array($node ?? null) ? $node : [];
$config = is_array($n['config_data'] ?? null) ? $n['config_data'] : [];

$currentType = (string)($n['type'] ?? 'web');

$v = static function (string $key, string $default = '') use ($n): string {
    return htmlspecialchars((string)($n[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};

$typeLabels = ['web' => 'Web 节点', 'mysql' => 'MySQL 节点', 'redis' => 'Redis 节点'];
$defaultPorts = ['web' => 80, 'mysql' => 3306, 'redis' => 6379];

$action = $isEdit ? '/admin/cluster/update' : '/admin/cluster/create';
$reloadTableId = 'clusterTable-' . $currentType;
?>

<form class="layui-form" id="clusterForm" lay-filter="clusterForm" action="">

  <input type="hidden" name="id" value="<?= (int)($n['id'] ?? 0) ?>">

  <?php if ($isEdit): ?>
    <input type="hidden" name="type" value="<?= htmlspecialchars($currentType, ENT_QUOTES, 'UTF-8') ?>">

    <div class="layui-form-item">
      <label class="layui-form-label">节点类型</label>
      <div class="layui-input-block">
        <input type="text" class="layui-input layui-disabled" readonly
               value="<?= htmlspecialchars($typeLabels[$currentType] ?? $currentType, ENT_QUOTES, 'UTF-8') ?>">
        <div class="layui-word-aux" style="padding-left:0">类型创建后不可修改。</div>
      </div>
    </div>
  <?php else: ?>
    <div class="layui-form-item">
      <label class="layui-form-label required">节点类型</label>
      <div class="layui-input-block">
        <select name="type" id="clusterNodeType" lay-verify="required">
          <?php foreach ($typeLabels as $val => $label): ?>
            <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= $currentType === $val ? 'selected' : '' ?>>
              <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  <?php endif; ?>

  <div class="layui-form-item">
    <label class="layui-form-label required">节点名称</label>
    <div class="layui-input-block">
      <input type="text" name="name" class="layui-input" maxlength="50"
             lay-verify="required" placeholder="例如：Web节点1" value="<?= $v('name') ?>">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label required">主机地址</label>
    <div class="layui-input-block">
      <input type="text" name="host" class="layui-input" maxlength="255"
             lay-verify="required" placeholder="例如：203.0.113.10" value="<?= $v('host') ?>">
    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-inline">
      <label class="layui-form-label required">端口</label>
      <div class="layui-input-inline" style="width:120px">
        <input type="number" name="port" id="clusterNodePort" class="layui-input"
               min="1" max="65535" lay-verify="required|number"
               value="<?= (int)($n['port'] ?? $defaultPorts[$currentType] ?? 80) ?>">
      </div>
    </div>

    <div class="layui-inline">
      <label class="layui-form-label">权重</label>
      <div class="layui-input-inline" style="width:120px">
        <input type="number" name="weight" class="layui-input"
               min="1" max="10" value="<?= (int)($n['weight'] ?? 1) ?>">
      </div>
    </div>
  </div>

  <!-- MySQL 认证 -->
  <div class="form-item-auth-mysql" style="display:none">
    <div class="layui-form-item">
      <label class="layui-form-label">用户名</label>
      <div class="layui-input-inline" style="width:200px">
        <input type="text" name="config_username" class="layui-input" maxlength="64" placeholder="默认 root"
               value="<?= htmlspecialchars((string)($config['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>
    </div>

    <div class="layui-form-item">
      <label class="layui-form-label">密码</label>
      <div class="layui-input-inline" style="width:200px">
        <input type="password" name="config_password" class="layui-input" lay-ignore
               autocomplete="new-password"
               placeholder="<?= $isEdit ? '留空则不修改' : '留空则无密码' ?>">
      </div>
    </div>
  </div>

  <!-- Redis 认证 -->
  <div class="form-item-auth-redis" style="display:none">
    <div class="layui-form-item">
      <label class="layui-form-label">密码</label>
      <div class="layui-input-inline" style="width:200px">
        <input type="password" name="config_redis_password" class="layui-input" lay-ignore
               autocomplete="new-password"
               placeholder="<?= $isEdit ? '留空则不修改' : '留空则无密码' ?>">
      </div>
    </div>
  </div>

  <blockquote class="layui-elem-quote layui-quote-nm" style="margin-top:15px">
    内网/保留地址会被拒绝（防 SSRF）；主机名需可解析。
  </blockquote>

  <div class="layui-form-item">
    <div class="layui-input-block" style="margin-left:0">
      <button class="layui-btn" lay-submit lay-filter="clusterFormSubmit">保存</button>
      <button type="button" class="layui-btn layui-btn-primary" id="clusterFormTest">测试连接</button>
      <button type="button" class="layui-btn layui-btn-primary" id="clusterFormCancel">取消</button>
    </div>
  </div>

</form>

<script>
layui.use(['form'], function () {
  var form = layui.form;
  var $ = layui.jquery;

  var isEdit = <?= $isEdit ? 'true' : 'false' ?>;
  var currentType = <?= json_encode($currentType) ?>;
  var defaultPorts = { web: 80, mysql: 3306, redis: 6379 };

  // 按节点类型显示对应的认证字段，并在切换类型时给出默认端口
  function syncAuth() {
    var type = $('#clusterNodeType').val() || currentType;

    $('.form-item-auth-mysql').toggle(type === 'mysql');
    $('.form-item-auth-redis').toggle(type === 'redis');

    if (!isEdit && defaultPorts[type]) {
      $('#clusterNodePort').val(defaultPorts[type]);
    }
  }

  form.on('select(clusterNodeType)', syncAuth);
  syncAuth();

  // 保存
  form.on('submit(clusterFormSubmit)', function () {
    AdminUi.post('<?= $action ?>', $('#clusterForm').serialize(), function () {
      // 先刷新外层的集群节点表，再关掉这个弹层
      AdminUi.closeLayerAndReload('<?= $reloadTableId ?>');
    });
    return false;
  });

  // 测试连接：只探测、不改数据，成功与否都只弹一条提示
  $('#clusterFormTest').on('click', function () {
    AdminUi.post('/admin/cluster/test', $('#clusterForm').serialize());
  });

  $('#clusterFormCancel').on('click', function () {
    try {
      window.parent.layer.close(window.parent.layer.getFrameIndex(window.name));
    } catch (e) {
      history.back();
    }
  });
});
</script>
