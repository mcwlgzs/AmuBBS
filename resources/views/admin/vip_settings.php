<?php
/**
 * 后台 - 会员设置（layuimini 子页面 / layui 表单）
 *
 * 变量：$vipEnabled, $vipLevels
 *
 * 迁移自 Bootstrap + htmx，只重写外观与提交方式：
 *   1. 总开关改用 layui 的 lay-skin="switch"，hidden=0 伴生字段原样保留；
 *   2. 每个等级一块 layui 面板，等级数量仍完全由数据决定（不写死 4 个）；
 *   3. 等级的索引化字段名 levels[i][level|name|icon|color|price|benefits] 逐字保留，
 *      level 号依旧用隐藏域显式传递，不用「下标 + 1」推断。
 *
 * ⚠️ 字段契约：这些 name 直接决定 vip_levels JSON 的落库结构，一个都不能改。
 */

$vipEnabled = $vipEnabled ?? true;
$vipLevels  = is_array($vipLevels ?? null) ? $vipLevels : [];

/** <input type="color"> 只认 #rrggbb，把 #rgb 简写展开 */
$normalizeColor = static function (string $c): string {
    $c = trim($c);
    if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $c, $m)) {
        return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
    }
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#999999';
};
?>

<form class="layui-form" id="vipSettingsForm" lay-filter="vipSettingsForm" action="">

  <blockquote class="layui-elem-quote layui-quote-nm">共 <?= count($vipLevels) ?> 个会员等级；关闭总开关后前台将隐藏所有 VIP 相关入口。</blockquote>

  <div class="layui-form-item">
    <label class="layui-form-label">启用 VIP 会员</label>
    <div class="layui-input-block">
      <input type="hidden" name="vip_enabled" value="0">
      <input type="checkbox" name="vip_enabled" value="1" lay-skin="switch" lay-text="开启|关闭"
             <?= $vipEnabled ? 'checked' : '' ?>>
    </div>
  </div>

  <?php foreach ($vipLevels as $i => $lv): ?>
    <?php
      $lvl       = (int)($lv['level'] ?? ($i + 1));
      $benefits  = is_array($lv['benefits'] ?? null) ? $lv['benefits'] : [];
    ?>

    <fieldset class="layui-elem-field layui-field-title" style="margin-top:20px">
      <legend>
        等级 <?= $lvl ?>
        <?php if (!empty($lv['icon'])): ?>
          <?= htmlspecialchars((string)$lv['icon'], ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
      </legend>
    </fieldset>

    <input type="hidden" name="levels[<?= $i ?>][level]" value="<?= $lvl ?>">

    <div class="layui-form-item">
      <label class="layui-form-label">名称</label>
      <div class="layui-input-inline" style="width:200px">
        <input type="text" id="vipName<?= $i ?>" name="levels[<?= $i ?>][name]" class="layui-input"
               maxlength="32" value="<?= htmlspecialchars((string)($lv['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>

      <div class="layui-inline">
        <label class="layui-form-label">图标</label>
        <div class="layui-input-inline" style="width:100px">
          <input type="text" id="vipIcon<?= $i ?>" name="levels[<?= $i ?>][icon]" class="layui-input"
                 maxlength="8" placeholder="🥈" value="<?= htmlspecialchars((string)($lv['icon'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div class="layui-inline">
        <label class="layui-form-label">颜色</label>
        <div class="layui-input-inline" style="width:120px">
          <input type="color" id="vipColor<?= $i ?>" name="levels[<?= $i ?>][color]" class="layui-input"
                 lay-ignore
                 value="<?= htmlspecialchars($normalizeColor((string)($lv['color'] ?? '#999999')), ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div class="layui-inline">
        <label class="layui-form-label">价格</label>
        <div class="layui-input-inline" style="width:110px">
          <input type="number" id="vipPrice<?= $i ?>" name="levels[<?= $i ?>][price]" class="layui-input"
                 min="0" value="<?= (int)($lv['price'] ?? 0) ?>">
        </div>
        <div class="layui-form-mid layui-word-aux">积分/月</div>
      </div>
    </div>

    <div class="layui-form-item layui-form-text">
      <label class="layui-form-label">权益</label>
      <div class="layui-input-block">
        <textarea id="vipBenefits<?= $i ?>" name="levels[<?= $i ?>][benefits]" class="layui-textarea"
                  rows="3" placeholder="每行一条权益"><?= htmlspecialchars(implode("\n", $benefits), ENT_QUOTES, 'UTF-8') ?></textarea>
        <div class="layui-word-aux" style="padding-left:0">每行一条，空行会被忽略。</div>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="layui-form-item">
    <div class="layui-input-block" style="margin-left:0">
      <button class="layui-btn" lay-submit lay-filter="vipSettingsSubmit">保存设置</button>
      <span class="layui-word-aux">会员等级信息保存后立即生效</span>
    </div>
  </div>

</form>

<script>
layui.use(['form'], function () {
  var form = layui.form;
  var $ = layui.jquery;

  // 保存：serialize() 保留「同名 hidden + checkbox」以及 levels[i][...] 的嵌套结构
  form.on('submit(vipSettingsSubmit)', function () {
    AdminUi.post('/admin/vip-settings', $('#vipSettingsForm').serialize());
    return false;
  });
});
</script>
