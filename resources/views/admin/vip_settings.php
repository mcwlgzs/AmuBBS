<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header">会员设置</div>
    <div class="layui-card-body">

        <form class="layui-form" lay-filter="vipSettingsForm" style="max-width:600px;">

            <div class="layui-form-item">
                <label class="layui-form-label">VIP 开关</label>
                <div class="layui-input-block">
                    <input type="checkbox" name="vip_enabled" lay-skin="switch" lay-text="开|关" lay-filter="vipEnabled" <?= $vipEnabled ? 'checked' : '' ?>>
                    <div class="layui-form-mid layui-word-aux">关闭后前台将隐藏所有 VIP 相关入口</div>
                </div>
            </div>

            <div id="vipLevelsFields" style="<?= !$vipEnabled ? 'display:none;' : '' ?>">
            <?php foreach ($vipLevels as $i => $lv): ?>
                <fieldset class="layui-elem-field" style="margin-bottom:15px;">
                    <legend>等级 <?= (int)$lv['level'] ?></legend>
                    <div class="layui-field-box">
                        <div class="layui-form-item">
                            <div class="layui-inline">
                                <label class="layui-form-label">名称</label>
                                <div class="layui-input-inline" style="width:150px;">
                                    <input type="text" name="vip_level_<?= $i ?>_name" class="layui-input" value="<?= htmlspecialchars($lv['name']) ?>">
                                </div>
                            </div>
                            <div class="layui-inline">
                                <label class="layui-form-label">图标</label>
                                <div class="layui-input-inline" style="width:80px;">
                                    <input type="text" name="vip_level_<?= $i ?>_icon" class="layui-input" value="<?= htmlspecialchars($lv['icon']) ?>">
                                </div>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <div class="layui-inline">
                                <label class="layui-form-label">颜色</label>
                                <div class="layui-input-inline" style="width:120px;">
                                    <?php
                                    // <input type="color"> 要求 #rrggbb 格式，将 #rgb 简写展开
                                    $_c = trim($lv['color']);
                                    if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $_c, $_m)) {
                                        $_c = '#' . $_m[1].$_m[1] . $_m[2].$_m[2] . $_m[3].$_m[3];
                                    }
                                    ?>
                                    <input type="color" name="vip_level_<?= $i ?>_color" value="<?= htmlspecialchars($_c) ?>" style="width:100%;height:38px;padding:2px;border:1px solid #e6e6e6;border-radius:2px;">
                                </div>
                            </div>
                            <div class="layui-inline">
                                <label class="layui-form-label">价格(积分/月)</label>
                                <div class="layui-input-inline" style="width:120px;">
                                    <input type="number" name="vip_level_<?= $i ?>_price" class="layui-input" value="<?= (int)$lv['price'] ?>" min="0">
                                </div>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">权益</label>
                            <div class="layui-input-block">
                                <textarea name="vip_level_<?= $i ?>_benefits" class="layui-textarea" style="height:80px;" placeholder="每行一条权益"><?= htmlspecialchars(implode("\n", $lv['benefits'] ?? [])) ?></textarea>
                            </div>
                        </div>
                    </div>
                </fieldset>
            <?php endforeach; ?>
            </div>

            <div class="layui-form-item">
                <div class="layui-input-block">
                    <button type="button" class="layui-btn" lay-submit lay-filter="saveVipSettings">保存设置</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
layui.use(['form', 'layer'], function(){
    var $ = layui.$, layer = layui.layer, form = layui.form;

    form.on('switch(vipEnabled)', function(data){ $('#vipLevelsFields').toggle(data.elem.checked); });

    form.on('submit(saveVipSettings)', function(data){
        var formData = {};
        formData.vip_enabled = data.field.vip_enabled ? '1' : '0';
        var vipLevels = [];
        for (var i = 0; i < 4; i++) {
            var benefitsRaw = data.field['vip_level_' + i + '_benefits'] || '';
            var benefits = benefitsRaw.split('\n').map(function(s){ return s.trim(); }).filter(function(s){ return s !== ''; });
            vipLevels.push({
                level: i + 1,
                name: data.field['vip_level_' + i + '_name'] || '',
                color: data.field['vip_level_' + i + '_color'] || '#999999',
                icon: data.field['vip_level_' + i + '_icon'] || '',
                price: parseInt(data.field['vip_level_' + i + '_price']) || 0,
                benefits: benefits
            });
        }
        formData.vip_levels = JSON.stringify(vipLevels);

        var loadIdx = layer.load(2);
        $.ajax({
            url: '/admin/vip-settings',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            dataType: 'json',
            success: function(res){
                layer.close(loadIdx);
                if (res.success) {
                    layer.msg(res.message || '保存成功', {icon: 1});
                } else {
                    layer.msg(res.message || '保存失败', {icon: 2});
                }
            },
            error: function(){
                layer.close(loadIdx);
                layer.msg('请求失败', {icon: 2});
            }
        });
        return false;
    });
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
