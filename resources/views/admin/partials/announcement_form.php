<?php
/**
 * 公告表单（layuimini 子页面，由 layer 的 iframe 弹层打开）
 *
 * 形状照 partials/forum_form.php（表单页参考实现）：
 *
 *   <form class="layui-form layuimini-form">   layui 表单
 *     <input type="hidden" name="id">          编辑时带上主键
 *     …字段，必填的加 lay-verify="required"…
 *     <button lay-submit lay-filter="xxxSubmit"> 保存
 *     <button id="xxxCancel">                    取消
 *   layui.use(['form']) → form.on('submit(xxxSubmit)') → AdminUi.post(...)
 *
 * 由 AnnounceController::announcementForm() 用 renderAdmin() 渲染，
 * 所以这里是 layout_child 里的「页面正文」，不再需要 modal 的 header/footer 结构。
 * 保存成功后调 AdminUi.closeLayerAndReload('announcementTable')：
 * 先刷新外层列表的表格，再关掉自己。
 *
 * 变量：$announcement（null = 新增）、$isEdit、$typeLabels
 */

$isEdit     = (bool)($isEdit ?? false);
$a          = is_array($announcement ?? null) ? $announcement : [];
$typeLabels = is_array($typeLabels ?? null) ? $typeLabels : [0 => '普通', 1 => '重要', 2 => '紧急'];

$action = $isEdit ? '/admin/announcements/update' : '/admin/announcements/create';

$v = static function (string $key, string $default = '') use ($a): string {
    return htmlspecialchars((string)($a[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};

/** 时间戳 → datetime-local 需要的 Y-m-d\TH:i */
$dt = static function (string $key) use ($a): string {
    $ts = (int)($a[$key] ?? 0);

    return $ts > 0 ? date('Y-m-d\TH:i', $ts) : '';
};

$currentType = (int)($a['type'] ?? 0);
?>
<form class="layui-form layuimini-form" lay-filter="announcementForm" action="">

  <input type="hidden" name="id" value="<?= (int)($a['id'] ?? 0) ?>">

  <div class="layui-form-item layui-form-text">
    <label class="layui-form-label required">公告内容</label>
    <div class="layui-input-block">
      <textarea name="content" class="layui-textarea" rows="6"
                lay-verify="required" placeholder="请输入公告内容，支持换行"><?= $v('content') ?></textarea>
      <div class="layui-word-aux">前台公告栏显示的就是这段文字，换行会按原样保留。</div>
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">跳转链接</label>
    <div class="layui-input-block">
      <input type="url" name="url" class="layui-input" maxlength="500"
             placeholder="https://example.com（可选）" value="<?= $v('url') ?>">
      <div class="layui-word-aux">留空则点击不跳转；必须以 http:// 或 https:// 开头。</div>
    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-inline">
      <label class="layui-form-label">类型</label>
      <div class="layui-input-inline">
        <select name="type">
          <?php foreach ($typeLabels as $val => $label): ?>
            <option value="<?= (int)$val ?>" <?= $currentType === (int)$val ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="layui-inline">
      <label class="layui-form-label">排序权重</label>
      <div class="layui-input-inline" style="width:100px">
        <input type="number" name="rank" class="layui-input" min="0" value="<?= (int)($a['rank'] ?? 0) ?>">
      </div>
      <div class="layui-form-mid layui-word-aux">数字越大越靠前。</div>
    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-inline">
      <label class="layui-form-label">生效时间</label>
      <div class="layui-input-inline">
        <input type="datetime-local" name="start_at" class="layui-input" value="<?= $dt('start_at') ?>">
      </div>
      <div class="layui-form-mid layui-word-aux">留空立即生效。</div>
    </div>

    <div class="layui-inline">
      <label class="layui-form-label">过期时间</label>
      <div class="layui-input-inline">
        <input type="datetime-local" name="end_at" class="layui-input" value="<?= $dt('end_at') ?>">
      </div>
      <div class="layui-form-mid layui-word-aux">留空永不过期。</div>
    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-input-block">
      <button class="layui-btn" lay-submit lay-filter="announcementFormSubmit">保存</button>
      <button type="button" class="layui-btn layui-btn-primary" id="announcementFormCancel">取消</button>
    </div>
  </div>
</form>

<script>
layui.use(['form', 'jquery'], function () {
  var form = layui.form;
  var $ = layui.jquery;

  form.on('submit(announcementFormSubmit)', function (data) {
    AdminUi.post('<?= $action ?>', data.field, function () {
      // 先刷新外层的公告列表，再关掉这个弹层
      AdminUi.closeLayerAndReload('announcementTable');
    });
    return false;   // 阻止 layui 默认的表单提交
  });

  $('#announcementFormCancel').on('click', function () {
    try {
      window.parent.layer.close(window.parent.layer.getFrameIndex(window.name));
    } catch (e) {
      history.back();
    }
  });
});
</script>
