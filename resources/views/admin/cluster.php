<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span>集群节点</span>
        <button class="layui-btn layui-btn-sm" id="btnAddNode">添加节点</button>
    </div>
    <div class="layui-card-body">
        <?php
        $types = ['web' => 'Web 节点', 'mysql' => 'MySQL 节点', 'redis' => 'Redis 节点'];
        foreach ($types as $type => $label):
            $typeNodes = array_filter($nodes, fn($n) => $n['type'] === $type);
            if (empty($typeNodes)) continue;
        ?>
        <h4 style="margin:16px 0 8px;font-size:14px;color:#666;"><?= $label ?></h4>
        <table class="layui-table">
            <thead><tr><th>名称</th><th>地址</th><th>权重</th><th>状态</th><th>响应</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($typeNodes as $node): ?>
            <tr>
                <td><?= htmlspecialchars($node['name']) ?></td>
                <td><?= htmlspecialchars($node['host']) ?>:<?= (int)$node['port'] ?></td>
                <td><?= (int)$node['weight'] ?></td>
                <td>
                    <?php if ($node['status_info']['online']): ?>
                        <span style="color:#009688;font-weight:600;">在线</span>
                    <?php else: ?>
                        <span style="color:#FF5722;font-weight:600;">离线</span>
                    <?php endif; ?>
                    <?php if ($node['status'] == 0): ?>
                        <span style="color:#FFB800;font-size:12px;">(已禁用)</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($node['status_info']['response_time'] ?? '-') ?> ms</td>
                <td>
                    <button class="layui-btn layui-btn-normal layui-btn-xs js-test-node" data-type="<?= htmlspecialchars($node['type']) ?>" data-host="<?= htmlspecialchars($node['host']) ?>" data-port="<?= (int)$node['port'] ?>" data-config="<?= htmlspecialchars(json_encode($node['config_data'])) ?>">测试</button>
                    <button class="layui-btn layui-btn-warm layui-btn-xs js-edit-node" data-id="<?= (int)$node['id'] ?>" data-type="<?= htmlspecialchars($node['type']) ?>" data-name="<?= htmlspecialchars($node['name']) ?>" data-host="<?= htmlspecialchars($node['host']) ?>" data-port="<?= (int)$node['port'] ?>" data-weight="<?= (int)$node['weight'] ?>" data-config="<?= htmlspecialchars(json_encode($node['config_data'])) ?>">编辑</button>
                    <button class="layui-btn layui-btn-xs js-toggle-node" data-id="<?= (int)$node['id'] ?>"><?= $node['status'] == 1 ? '禁用' : '启用' ?></button>
                    <button class="layui-btn layui-btn-danger layui-btn-xs js-delete-node" data-id="<?= (int)$node['id'] ?>">删除</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endforeach; ?>

        <?php if (empty($nodes)): ?>
        <div style="text-align:center;padding:40px;color:#999;">暂无节点，点击"添加节点"开始配置集群</div>
        <?php endif; ?>
    </div>
</div>

<!-- 添加节点表单模板（隐藏） -->
<div id="addNodeForm" style="display:none;padding:20px;">
    <form class="layui-form" lay-filter="addNodeForm">
        <div class="layui-form-item">
            <label class="layui-form-label">节点类型</label>
            <div class="layui-input-block">
                <select name="type" lay-filter="nodeType" lay-verify="required">
                    <option value="">请选择</option>
                    <option value="web">Web 节点</option>
                    <option value="mysql">MySQL 节点</option>
                    <option value="redis">Redis 节点</option>
                </select>
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">节点名称</label>
            <div class="layui-input-block">
                <input type="text" name="name" class="layui-input" placeholder="例如：Web节点1" lay-verify="required">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">主机地址</label>
            <div class="layui-input-block">
                <input type="text" name="host" class="layui-input" placeholder="例如：192.168.1.101" lay-verify="required">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">端口</label>
            <div class="layui-input-block">
                <input type="number" name="port" class="layui-input" value="80">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">权重</label>
            <div class="layui-input-block">
                <input type="number" name="weight" class="layui-input" value="1" min="1" max="10">
            </div>
        </div>
        <!-- MySQL/Redis 认证信息（按类型显示） -->
        <div class="layui-form-item node-config-mysql" style="display:none;">
            <label class="layui-form-label">用户名</label>
            <div class="layui-input-block">
                <input type="text" name="config_username" class="layui-input" placeholder="默认 root">
            </div>
        </div>
        <div class="layui-form-item node-config-mysql" style="display:none;">
            <label class="layui-form-label">密码</label>
            <div class="layui-input-block">
                <input type="password" name="config_password" class="layui-input" placeholder="留空则无密码">
            </div>
        </div>
        <div class="layui-form-item node-config-redis" style="display:none;">
            <label class="layui-form-label">密码</label>
            <div class="layui-input-block">
                <input type="password" name="config_redis_password" class="layui-input" placeholder="留空则无密码">
            </div>
        </div>
        <div class="layui-form-item">
            <div class="layui-input-block">
                <button type="button" class="layui-btn layui-btn-normal" id="btnTestConnect">测试连接</button>
                <button type="button" class="layui-btn" lay-submit lay-filter="submitNode">保存</button>
            </div>
        </div>
    </form>
</div>

<!-- 编辑节点表单模板（隐藏） -->
<div id="editNodeForm" style="display:none;padding:20px;">
    <form class="layui-form" lay-filter="editNodeForm">
        <input type="hidden" name="id">
        <input type="hidden" name="type">
        <div class="layui-form-item">
            <label class="layui-form-label">节点类型</label>
            <div class="layui-input-block">
                <input type="text" name="type_label" class="layui-input" readonly style="background:#f2f2f2;">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">节点名称</label>
            <div class="layui-input-block">
                <input type="text" name="name" class="layui-input" lay-verify="required">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">主机地址</label>
            <div class="layui-input-block">
                <input type="text" name="host" class="layui-input" lay-verify="required">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">端口</label>
            <div class="layui-input-block">
                <input type="number" name="port" class="layui-input">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">权重</label>
            <div class="layui-input-block">
                <input type="number" name="weight" class="layui-input" min="1" max="10">
            </div>
        </div>
        <div class="layui-form-item edit-config-mysql" style="display:none;">
            <label class="layui-form-label">用户名</label>
            <div class="layui-input-block">
                <input type="text" name="config_username" class="layui-input" placeholder="默认 root">
            </div>
        </div>
        <div class="layui-form-item edit-config-mysql" style="display:none;">
            <label class="layui-form-label">密码</label>
            <div class="layui-input-block">
                <input type="password" name="config_password" class="layui-input" placeholder="留空则不修改">
            </div>
        </div>
        <div class="layui-form-item edit-config-redis" style="display:none;">
            <label class="layui-form-label">密码</label>
            <div class="layui-input-block">
                <input type="password" name="config_redis_password" class="layui-input" placeholder="留空则不修改">
            </div>
        </div>
        <div class="layui-form-item">
            <div class="layui-input-block">
                <button type="button" class="layui-btn layui-btn-normal" id="btnEditTestConnect">测试连接</button>
                <button type="button" class="layui-btn" lay-submit lay-filter="submitEditNode">保存</button>
            </div>
        </div>
    </form>
</div>

<script>
layui.use(['form', 'layer'], function(){
    var $ = layui.$, layer = layui.layer, form = layui.form;
    var addLayerIdx = null;

    // 节点类型切换自动填充端口 + 显示认证字段
    form.on('select(nodeType)', function(data){
        var portMap = { web: 80, mysql: 3306, redis: 6379 };
        if (portMap[data.value]) {
            $('input[name="port"]').val(portMap[data.value]);
        }
        $('.node-config-mysql, .node-config-redis').hide();
        if (data.value === 'mysql') $('.node-config-mysql').show();
        if (data.value === 'redis') $('.node-config-redis').show();
    });

    // 添加节点弹窗
    $('#btnAddNode').on('click', function(){
        addLayerIdx = layer.open({
            type: 1,
            title: '添加节点',
            area: ['450px'],
            content: $('#addNodeForm').html()
        });
        form.render(null, 'addNodeForm');
    });

    // 提交添加节点
    form.on('submit(submitNode)', function(data){
        var payload = {
            type: data.field.type,
            name: data.field.name,
            host: data.field.host,
            port: data.field.port,
            weight: data.field.weight,
            config: {}
        };
        if (data.field.type === 'mysql') {
            if (data.field.config_username) payload.config.username = data.field.config_username;
            if (data.field.config_password) payload.config.password = data.field.config_password;
        } else if (data.field.type === 'redis') {
            if (data.field.config_redis_password) payload.config.password = data.field.config_redis_password;
        }
        $.ajax({
            url: '/admin/cluster/create',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            dataType: 'json',
            success: function(res){
                if (res.success) {
                    layer.msg('添加成功', {icon: 1});
                    setTimeout(function(){ location.reload(); }, 1000);
                } else {
                    layer.msg(res.message || '操作失败', {icon: 2});
                }
            },
            error: function(){ layer.msg('请求失败', {icon: 2}); }
        });
        return false;
    });

    // 测试连接（通用函数）
    function testNodeConnect(payload, btn){
        var $btn = $(btn);
        var origText = $btn.text();
        $btn.text('测试中...').prop('disabled', true);
        $.ajax({
            url: '/admin/cluster/test',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            dataType: 'json',
            success: function(res){
                if (res.success) {
                    layer.msg(res.message, {icon: 1, time: 3000});
                } else {
                    layer.msg(res.message || '连接失败', {icon: 2, time: 3000});
                }
            },
            error: function(){ layer.msg('请求失败', {icon: 2}); },
            complete: function(){ $btn.text(origText).prop('disabled', false); }
        });
    }

    // 已有节点的测试按钮
    $(document).on('click', '.js-test-node', function(){
        var payload = {
            type: $(this).data('type'),
            host: $(this).data('host'),
            port: $(this).data('port'),
            config: $(this).data('config') || {}
        };
        testNodeConnect(payload, this);
    });

    // 添加弹窗中的测试按钮
    $(document).on('click', '#btnTestConnect', function(){
        var $form = $(this).closest('.layui-form');
        var type = $form.find('select[name="type"]').val();
        var host = $form.find('input[name="host"]').val();
        var port = $form.find('input[name="port"]').val();
        if (!type || !host || !port) {
            layer.msg('请先填写类型、主机和端口', {icon: 0});
            return;
        }
        var config = {};
        if (type === 'mysql') {
            var u = $form.find('input[name="config_username"]').val();
            var p = $form.find('input[name="config_password"]').val();
            if (u) config.username = u;
            if (p) config.password = p;
        } else if (type === 'redis') {
            var rp = $form.find('input[name="config_redis_password"]').val();
            if (rp) config.password = rp;
        }
        testNodeConnect({ type: type, host: host, port: port, config: config }, this);
    });

    // 编辑节点弹窗
    $(document).on('click', '.js-edit-node', function(){
        var $btn = $(this);
        var typeLabels = { web: 'Web 节点', mysql: 'MySQL 节点', redis: 'Redis 节点' };
        var nodeType = $btn.data('type');
        var config = $btn.data('config') || {};

        var editLayerIdx = layer.open({
            type: 1,
            title: '编辑节点',
            area: ['450px'],
            content: $('#editNodeForm').html(),
            success: function(layero){
                var $f = layero.find('.layui-form');
                $f.find('input[name="id"]').val($btn.data('id'));
                $f.find('input[name="type"]').val(nodeType);
                $f.find('input[name="type_label"]').val(typeLabels[nodeType] || nodeType);
                $f.find('input[name="name"]').val($btn.data('name'));
                $f.find('input[name="host"]').val($btn.data('host'));
                $f.find('input[name="port"]').val($btn.data('port'));
                $f.find('input[name="weight"]').val($btn.data('weight'));
                if (nodeType === 'mysql') {
                    $f.find('.edit-config-mysql').show();
                    $f.find('input[name="config_username"]').val(config.username || '');
                } else if (nodeType === 'redis') {
                    $f.find('.edit-config-redis').show();
                }
                form.render(null, 'editNodeForm');
            }
        });
    });

    // 提交编辑节点
    form.on('submit(submitEditNode)', function(data){
        var nodeType = data.field.type;
        var config = {};
        if (nodeType === 'mysql') {
            if (data.field.config_username) config.username = data.field.config_username;
            if (data.field.config_password) config.password = data.field.config_password;
        } else if (nodeType === 'redis') {
            if (data.field.config_redis_password) config.password = data.field.config_redis_password;
        }
        $.ajax({
            url: '/admin/cluster/update',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                id: parseInt(data.field.id),
                name: data.field.name,
                host: data.field.host,
                port: data.field.port,
                weight: data.field.weight,
                config: config
            }),
            dataType: 'json',
            success: function(res){
                if (res.success) {
                    layer.msg('更新成功', {icon: 1});
                    setTimeout(function(){ location.reload(); }, 1000);
                } else {
                    layer.msg(res.message || '操作失败', {icon: 2});
                }
            },
            error: function(){ layer.msg('请求失败', {icon: 2}); }
        });
        return false;
    });

    // 编辑弹窗中的测试按钮
    $(document).on('click', '#btnEditTestConnect', function(){
        var $form = $(this).closest('.layui-form');
        var type = $form.find('input[name="type"]').val();
        var host = $form.find('input[name="host"]').val();
        var port = $form.find('input[name="port"]').val();
        if (!host || !port) {
            layer.msg('请先填写主机和端口', {icon: 0});
            return;
        }
        var config = {};
        if (type === 'mysql') {
            var u = $form.find('input[name="config_username"]').val();
            var p = $form.find('input[name="config_password"]').val();
            if (u) config.username = u;
            if (p) config.password = p;
        } else if (type === 'redis') {
            var rp = $form.find('input[name="config_redis_password"]').val();
            if (rp) config.password = rp;
        }
        testNodeConnect({ type: type, host: host, port: port, config: config }, this);
    });

    // 切换节点状态
    $(document).on('click', '.js-toggle-node', function(){
        var id = $(this).data('id');
        layer.confirm('确定切换节点状态？', function(index){
            layer.close(index);
            $.ajax({
                url: '/admin/cluster/toggle',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ id: id }),
                dataType: 'json',
                success: function(res){
                    if (res.success) {
                        layer.msg('操作成功', {icon: 1});
                        setTimeout(function(){ location.reload(); }, 1000);
                    } else {
                        layer.msg(res.message || '操作失败', {icon: 2});
                    }
                },
                error: function(){ layer.msg('请求失败', {icon: 2}); }
            });
        });
    });

    // 删除节点
    $(document).on('click', '.js-delete-node', function(){
        var id = $(this).data('id');
        layer.confirm('确定删除此节点？', {icon: 3}, function(index){
            layer.close(index);
            $.ajax({
                url: '/admin/cluster/delete',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ id: id }),
                dataType: 'json',
                success: function(res){
                    if (res.success) {
                        layer.msg('删除成功', {icon: 1});
                        setTimeout(function(){ location.reload(); }, 1000);
                    } else {
                        layer.msg(res.message || '操作失败', {icon: 2});
                    }
                },
                error: function(){ layer.msg('请求失败', {icon: 2}); }
            });
        });
    });
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
