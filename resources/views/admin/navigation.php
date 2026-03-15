<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>导航管理</h3>
        <button class="layui-btn layui-btn-sm" id="btnAddCat">添加分类</button>
    </div>
    <div class="layui-card-body">
        <?php if (empty($categories)): ?>
        <p style="text-align:center;color:#94a3b8;padding:24px 0;">暂无导航分类</p>
        <?php else: ?>
        <?php foreach ($categories as $cat): ?>
        <div class="layui-card" style="margin-bottom:16px;">
            <div class="layui-card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <span style="font-size:18px;margin-right:6px;"><?= htmlspecialchars($cat['icon'] ?? '') ?></span>
                    <strong><?= htmlspecialchars($cat['name']) ?></strong>
                    <span style="color:#94a3b8;font-size:12px;margin-left:8px;">排序: <?= (int)($cat['rank'] ?? 0) ?></span>
                </div>
                <div style="display:flex;gap:6px;">
                    <button class="layui-btn layui-btn-sm" onclick="openLinkModal(<?= (int)$cat['id'] ?>)">添加链接</button>
                    <button class="layui-btn layui-btn-sm layui-btn-primary" onclick="openCatModal(<?= htmlspecialchars(json_encode($cat, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>)">编辑</button>
                    <button class="layui-btn layui-btn-sm layui-btn-danger" onclick="deleteCat(<?= (int)$cat['id'] ?>)">删除</button>
                </div>
            </div>
            <div class="layui-card-body" style="padding:0;">
                <table class="layui-table" style="margin:0;">
                    <thead>
                        <tr>
                            <th>名称</th>
                            <th>URL</th>
                            <th>描述</th>
                            <th>点击量</th>
                            <th>排序</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cat['links'])): ?>
                        <tr><td colspan="6" style="text-align:center;color:#94a3b8;">暂无链接</td></tr>
                        <?php else: ?>
                        <?php foreach ($cat['links'] as $link): ?>
                        <tr>
                            <td>
                                <?php if (!empty($link['icon'])): ?>
                                <img src="<?= htmlspecialchars($link['icon']) ?>" alt="" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;">
                                <?php endif; ?>
                                <?= htmlspecialchars($link['name']) ?>
                            </td>
                            <td><a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener" style="color:#3b82f6;"><?= htmlspecialchars(mb_substr($link['url'], 0, 40)) ?></a></td>
                            <td style="color:#64748b;font-size:13px;"><?= htmlspecialchars($link['description'] ?? '') ?></td>
                            <td><?= (int)($link['clicks'] ?? 0) ?></td>
                            <td><?= (int)($link['rank'] ?? 0) ?></td>
                            <td>
                                <button class="layui-btn layui-btn-sm layui-btn-primary" onclick="openLinkModal(<?= (int)$cat['id'] ?>, <?= htmlspecialchars(json_encode($link, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>)">编辑</button>
                                <button class="layui-btn layui-btn-sm layui-btn-danger" onclick="deleteLink(<?= (int)$link['id'] ?>)">删除</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- 分类表单模板 -->
<script type="text/html" id="catFormTpl">
<form class="layui-form" lay-filter="catForm" style="padding:20px;">
    <input type="hidden" name="id" value="">
    <div class="layui-form-item">
        <label class="layui-form-label">分类名称</label>
        <div class="layui-input-block">
            <input type="text" name="name" lay-verify="required" class="layui-input" placeholder="请输入分类名称">
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">图标</label>
        <div class="layui-input-block">
            <input type="text" name="icon" class="layui-input emoji-picker-target" placeholder="点击选择图标">
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">排序</label>
        <div class="layui-input-block">
            <input type="number" name="rank" class="layui-input" min="0" value="0" placeholder="越大越靠前">
        </div>
    </div>
    <div class="layui-form-item" style="text-align:right;margin-bottom:0;">
        <button type="button" class="layui-btn layui-btn-primary" id="catCancelBtn">取消</button>
        <button type="button" class="layui-btn" lay-submit lay-filter="submitCat">保存</button>
    </div>
</form>
</script>

<!-- 链接表单模板 -->
<script type="text/html" id="linkFormTpl">
<form class="layui-form" lay-filter="linkForm" style="padding:20px;">
    <input type="hidden" name="id" value="">
    <div class="layui-form-item">
        <label class="layui-form-label">所属分类</label>
        <div class="layui-input-block">
            <select name="category_id" lay-ignore style="width:100%;height:38px;border:1px solid #e6e6e6;border-radius:2px;padding:0 10px;">
                <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">链接名称</label>
        <div class="layui-input-block">
            <input type="text" name="name" lay-verify="required" class="layui-input" placeholder="请输入链接名称">
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">URL</label>
        <div class="layui-input-block">
            <input type="url" name="url" lay-verify="required|url" class="layui-input" placeholder="https://">
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">描述</label>
        <div class="layui-input-block">
            <input type="text" name="description" class="layui-input" placeholder="简短描述（可选）">
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">图标 URL</label>
        <div class="layui-input-block">
            <input type="text" name="icon" class="layui-input" placeholder="图标图片地址（可选）">
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">排序</label>
        <div class="layui-input-block">
            <input type="number" name="rank" class="layui-input" min="0" value="0" placeholder="越大越靠前">
        </div>
    </div>
    <div class="layui-form-item" style="text-align:right;margin-bottom:0;">
        <button type="button" class="layui-btn layui-btn-primary" id="linkCancelBtn">取消</button>
        <button type="button" class="layui-btn" lay-submit lay-filter="submitLink">保存</button>
    </div>
</form>
</script>

<script>
layui.use(['form', 'layer'], function(){
    var $ = layui.$, layer = layui.layer, form = layui.form;
    var currentLayerIndex = -1;

    // 打开分类模态框（新增或编辑）
    $('#btnAddCat').on('click', function(){
        openCatModal();
    });

    window.openCatModal = function(cat){
        var isEdit = cat && cat.id;
        currentLayerIndex = layer.open({
            type: 1,
            title: isEdit ? '编辑分类' : '添加分类',
            area: ['460px', '320px'],
            content: $('#catFormTpl').html(),
            success: function(layero){
                if(isEdit){
                    layero.find('input[name="id"]').val(cat.id);
                    layero.find('input[name="name"]').val(cat.name);
                    layero.find('input[name="icon"]').val(cat.icon || '');
                    layero.find('input[name="rank"]').val(cat.rank || 0);
                }
                form.render(null, 'catForm');
                // 初始化 Emoji 选择器
                var iconInput = layero.find('input[name="icon"]')[0];
                if(iconInput && !iconInput._emojiPicker) {
                    iconInput._emojiPicker = new EmojiPicker(iconInput);
                }
                layero.find('#catCancelBtn').on('click', function(){
                    layer.close(currentLayerIndex);
                });
            }
        });
    };

    // 提交分类表单
    form.on('submit(submitCat)', function(data){
        var field = data.field;
        var url = field.id ? '/admin/nav-categories/update' : '/admin/nav-categories/create';
        $.ajax({
            url: url,
            type: 'POST',
            data: JSON.stringify(field),
            contentType: 'application/json',
            dataType: 'json',
            success: function(res){
                if(res.code === 0 || res.success){
                    layer.msg('保存成功', {icon: 1});
                    setTimeout(function(){ location.reload(); }, 1000);
                } else {
                    layer.msg(res.msg || res.message || '操作失败', {icon: 2});
                }
            },
            error: function(){
                layer.msg('请求失败', {icon: 2});
            }
        });
        return false;
    });

    // 删除分类
    window.deleteCat = function(id){
        layer.confirm('删除分类将同时删除该分类下的所有链接，确定？', {icon: 3, title: '确认删除'}, function(index){
            $.ajax({
                url: '/admin/nav-categories/delete',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({id: id}),
                dataType: 'json',
                success: function(res){
                    if(res.code === 0 || res.success){
                        layer.msg('删除成功', {icon: 1});
                        setTimeout(function(){ location.reload(); }, 1000);
                    } else {
                        layer.msg(res.msg || res.message || '操作失败', {icon: 2});
                    }
                },
                error: function(){
                    layer.msg('请求失败', {icon: 2});
                }
            });
            layer.close(index);
        });
    };

    // 打开链接模态框（新增或编辑）
    window.openLinkModal = function(catId, link){
        var isEdit = link && link.id;
        currentLayerIndex = layer.open({
            type: 1,
            title: isEdit ? '编辑链接' : '添加链接',
            area: ['500px', '480px'],
            content: $('#linkFormTpl').html(),
            success: function(layero){
                if(isEdit){
                    layero.find('input[name="id"]').val(link.id);
                    layero.find('select[name="category_id"]').val(link.category_id || catId);
                    layero.find('input[name="name"]').val(link.name);
                    layero.find('input[name="url"]').val(link.url);
                    layero.find('input[name="description"]').val(link.description || '');
                    layero.find('input[name="icon"]').val(link.icon || '');
                    layero.find('input[name="rank"]').val(link.rank || 0);
                } else {
                    layero.find('select[name="category_id"]').val(catId);
                }
                form.render(null, 'linkForm');
                layero.find('#linkCancelBtn').on('click', function(){
                    layer.close(currentLayerIndex);
                });
            }
        });
    };

    // 提交链接表单
    form.on('submit(submitLink)', function(data){
        var field = data.field;
        var url = field.id ? '/admin/nav-links/update' : '/admin/nav-links/create';
        $.ajax({
            url: url,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(field),
            dataType: 'json',
            success: function(res){
                if(res.code === 0 || res.success){
                    layer.msg('保存成功', {icon: 1});
                    setTimeout(function(){ location.reload(); }, 1000);
                } else {
                    layer.msg(res.msg || res.message || '操作失败', {icon: 2});
                }
            },
            error: function(){
                layer.msg('请求失败', {icon: 2});
            }
        });
        return false;
    });

    // 删除链接
    window.deleteLink = function(id){
        layer.confirm('确定删除该链接？', {icon: 3, title: '确认删除'}, function(index){
            $.ajax({
                url: '/admin/nav-links/delete',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({id: id}),
                dataType: 'json',
                success: function(res){
                    if(res.code === 0 || res.success){
                        layer.msg('删除成功', {icon: 1});
                        setTimeout(function(){ location.reload(); }, 1000);
                    } else {
                        layer.msg(res.msg || res.message || '操作失败', {icon: 2});
                    }
                },
                error: function(){
                    layer.msg('请求失败', {icon: 2});
                }
            });
            layer.close(index);
        });
    };
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
