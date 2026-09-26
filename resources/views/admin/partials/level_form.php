<?php
/**
 * 等级表单（layuimini 子页面，由 layer 的 iframe 弹层打开）
 *
 * 形状照 resources/views/admin/partials/forum_form.php：
 *   <form class="layui-form layuimini-form"> → 字段 → 保存 + 取消
 *
 * 提交到 POST /admin/levels/save（新增 / 编辑同一个端点，靠隐藏域 id 区分），
 * 成功后 AdminUi.closeLayerAndReload('levelTable')。
 *
 * 两个选择器都保留且都能用：
 *   - 颜色：layui 自带的 colorpicker（layui 2.6.3 有 colorpicker 模块）。
 *     picker 渲染在 #levelColorPickerBox 上，真正提交的字段仍然是 name="color" 的文本框，
 *     所以 #rgb / #rrggbb / var(--x) 都能手填；picker 只回写合法的 #rrggbb。
 *   - 图标：emoji 快捷选择（不再依赖任何图标字体，项目惯例本来就是 emoji）。
 *
 * 字段名与 LevelController::levelSave() 一一对应：level / name / min_credits / color / icon
 * 需要校验的只有 name（旧页面也只有它带 required）。
 *
 * 变量：$level（null 表示新增）, $isEdit, $pageTitle
 */

$isEdit = (bool)($isEdit ?? false);
$l      = is_array($level ?? null) ? $level : [];

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$v = static function (string $key, string $default = '') use ($l): string {
    return htmlspecialchars((string)($l[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};

$currentColor = (string)($l['color'] ?? '#999999');
$currentIcon  = (string)($l['icon'] ?? '');

/** picker 只认 hex，var(--x) 这类值直接不传给它，免得打开面板时解析失败 */
$pickerColor = preg_match('/^#[0-9a-fA-F]{3,6}$/', $currentColor) ? $currentColor : '';

/** 预置色板只用 hex（layui 默认色板里有 rgb()/rgba()，选中后控制器会判定为非法颜色） */
$predefineColors = [
    '#009688', '#16baaa', '#5FB878', '#1E9FFF', '#FF5722', '#FFB800', '#01AAED', '#999999',
    '#c00', '#ff8c00', '#ffd700', '#90ee90', '#00ced1', '#1e90ff', '#c71585', '#393D49',
];

$emojiChoices = ['⭐', '🌟', '🥉', '🥈', '🥇', '💎', '👑', '🔥', '🌱', '🌿', '🌳', '🎓', '🚀', '🏆', '🛡️', '✨'];
?>
<form class="layui-form layuimini-form" lay-filter="levelForm" action="">
  <input type="hidden" name="id" value="<?= (int)($l['id'] ?? 0) ?>">

  <div class="layui-form-item">
    <label class="layui-form-label required">等级序号</label>
    <div class="layui-input-inline" style="width:120px">
      <input type="number" name="level" class="layui-input" min="0" max="99"
             value="<?= $isEdit ? (int)($l['level'] ?? 0) : '' ?>" placeholder="如 1, 2, 3...">
    </div>
    <div class="layui-form-mid layui-word-aux">0-99</div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label required">所需积分</label>
    <div class="layui-input-inline" style="width:160px">
      <input type="number" name="min_credits" class="layui-input" min="0"
             value="<?= $isEdit ? (int)($l['min_credits'] ?? 0) : '' ?>" placeholder="达到此积分自动升级">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label required">等级名称</label>
    <div class="layui-input-block">
      <input type="text" name="name" class="layui-input" maxlength="50"
             lay-verify="required" value="<?= $v('name') ?>" placeholder="如 新手、初级、中级...">
    </div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">颜色</label>
    <div class="layui-input-inline" style="width:200px">
      <input type="text" name="color" id="levelColorText" class="layui-input" maxlength="30"
             value="<?= $e($currentColor) ?>" placeholder="#999999">
    </div>
    <div class="layui-input-inline" style="width:auto">
      <div id="levelColorPickerBox"></div>
    </div>
    <div class="layui-form-mid layui-word-aux">支持 #rgb / #rrggbb</div>
  </div>

  <div class="layui-form-item">
    <label class="layui-form-label">图标</label>
    <div class="layui-input-block">
      <div style="margin-bottom:8px">
        <div class="layui-input-inline" style="width:120px">
          <input type="text" name="icon" id="levelIconInput" class="layui-input" maxlength="8"
                 value="<?= $e($currentIcon) ?>" placeholder="如 🥈">
        </div>
        <button type="button" class="layui-btn layui-btn-primary layui-btn-sm" id="levelIconClear">清除</button>
        <span style="font-size:20px;vertical-align:middle" id="levelIconPreview"><?= $e($currentIcon) ?></span>
      </div>
      <div class="level-emoji-list">
        <?php foreach ($emojiChoices as $emoji): ?>
          <button type="button" class="layui-btn layui-btn-primary layui-btn-sm level-emoji-pick"
                  style="font-size:16px;line-height:1;padding:4px 7px"
                  data-emoji="<?= $e($emoji) ?>"><?= $e($emoji) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="layui-word-aux">emoji 可留空，最多 8 个字符。</div>
    </div>
  </div>

  <div class="layui-form-item">
    <div class="layui-input-block">
      <button class="layui-btn" lay-submit lay-filter="levelFormSubmit">保存</button>
      <button type="button" class="layui-btn layui-btn-primary" id="levelFormCancel">取消</button>
    </div>
  </div>
</form>

<script>
var LEVEL_PREDEFINE_COLORS = <?= json_encode($predefineColors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

layui.use(['form', 'colorpicker'], function () {
  var form        = layui.form;
  var colorpicker = layui.colorpicker;
  var $           = layui.jquery;

  var $colorText = $('#levelColorText');

  /** picker 回写文本框：只接受 #rgb / #rrggbb，其余（含选择器的「清空」）按空处理 */
  function applyColor(color) {
    if (!color) { $colorText.val(''); return; }
    if (/^#[0-9a-fA-F]{3}$/.test(color)) {
      color = '#' + color[1] + color[1] + color[2] + color[2] + color[3] + color[3];
    }
    if (/^#[0-9a-fA-F]{6}$/.test(color)) { $colorText.val(color); }
  }

  colorpicker.render({
    elem: '#levelColorPickerBox',
    color: <?= json_encode($pickerColor) ?>,
    predefine: true,
    colors: LEVEL_PREDEFINE_COLORS,
    change: applyColor,
    done: applyColor
  });

  // emoji 快捷选择 + 预览
  var $iconInput = $('#levelIconInput');
  var $preview   = $('#levelIconPreview');

  $('.level-emoji-pick').on('click', function () {
    $iconInput.val($(this).attr('data-emoji'));
    $preview.text($iconInput.val());
  });

  $('#levelIconClear').on('click', function () {
    $iconInput.val('');
    $preview.text('');
  });

  $iconInput.on('input', function () { $preview.text($iconInput.val()); });

  form.on('submit(levelFormSubmit)', function (data) {
    AdminUi.post('/admin/levels/save', data.field, function () {
      // 先刷新外层的等级列表，再关掉这个弹层
      AdminUi.closeLayerAndReload('levelTable');
    });
    return false;   // 阻止 layui 默认的表单提交
  });

  $('#levelFormCancel').on('click', function () {
    try {
      window.parent.layer.close(window.parent.layer.getFrameIndex(window.name));
    } catch (e) {
      history.back();
    }
  });
});
</script>
