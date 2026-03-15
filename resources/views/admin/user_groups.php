<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>用户组列表</h3>
        <button class="layui-btn layui-btn-sm" id="btnAddGroup">新增用户组</button>
    </div>
    <div class="layui-card-body">
        <table id="groupsTable" lay-filter="groupsTable"></table>
    </div>
</div>

<!-- 行操作模板 -->
<script type="text/html" id="groupBar">
    <button class="layui-btn layui-btn-xs layui-btn-normal" lay-event="edit">编辑</button>
    {{# if(d.id > 3){ }}
    <button class="layui-btn layui-btn-xs layui-btn-danger" lay-event="del">删除</button>
    {{# } }}
</script>

<!-- 弹窗表单模板 -->
<script type="text/html" id="groupFormTpl">
<div style="padding:20px;">
    <form class="layui-form" lay-filter="groupForm">
        <input type="hidden" name="id" id="gf_id" value="">
        <div class="layui-form-item">
            <label class="layui-form-label">组名</label>
            <div class="layui-input-block">
                <input type="text" name="name" id="gf_name" class="layui-input" lay-verify="required" placeholder="请输入组名">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">管理员权限</label>
            <div class="layui-input-block" style="padding-top:9px;">
                <input type="checkbox" name="is_admin" id="gf_is_admin" lay-skin="switch" lay-text="是|否">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">权限设置</label>
            <div class="layui-input-block" style="padding-top:6px;">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px 0;">
                    <input type="checkbox" name="allow_read" title="浏览" lay-skin="primary">
                    <input type="checkbox" name="allow_thread" title="发帖" lay-skin="primary">
                    <input type="checkbox" name="allow_post" title="回复" lay-skin="primary">
                    <input type="checkbox" name="allow_attach" title="附件" lay-skin="primary">
                    <input type="checkbox" name="allow_down" title="下载" lay-skin="primary">
                    <input type="checkbox" name="allow_top" title="置顶" lay-skin="primary">
                    <input type="checkbox" name="allow_update" title="编辑" lay-skin="primary">
                    <input type="checkbox" name="allow_delete" title="删除" lay-skin="primary">
                    <input type="checkbox" name="allow_move" title="移动" lay-skin="primary">
                    <input type="checkbox" name="allow_ban_user" title="封禁" lay-skin="primary">
                    <input type="checkbox" name="allow_delete_user" title="删用户" lay-skin="primary">
                    <input type="checkbox" name="allow_view_ip" title="查IP" lay-skin="primary">
                </div>
            </div>
        </div>
        <div class="layui-form-item layui-form-text">
            <label class="layui-form-label">扩展权限（JSON）</label>
            <div class="layui-input-block">
                <textarea name="permStr" id="gf_permStr" class="layui-textarea" rows="3" placeholder='{"thread.create":true}'></textarea>
            </div>
        </div>
    </form>
</div>
</script>

<script>
layui.use(['table', 'form', 'layer'], function(){
    var $ = layui.$, table = layui.table, form = layui.form, layer = layui.layer;

    var permFields = ['allow_read','allow_thread','allow_post','allow_attach','allow_down','allow_top',
                      'allow_update','allow_delete','allow_move','allow_ban_user','allow_delete_user','allow_view_ip'];
    var permLabels = {
        'allow_read':'浏览','allow_thread':'发帖','allow_post':'回复',
        'allow_attach':'附件','allow_down':'下载','allow_top':'置顶',
        'allow_update':'编辑','allow_delete':'删除','allow_move':'移动',
        'allow_ban_user':'封禁','allow_delete_user':'删用户','allow_view_ip':'查IP'
    };
    var defaultChecked = ['allow_read','allow_thread','allow_post','allow_attach','allow_down'];

    table.render({
        elem: '#groupsTable',
        id: 'groupsTable',
        url: '/admin/api/user-groups',
        page: false,
        cols: [[
            {field:'id', title:'ID', width:60, sort:true},
            {field:'name', title:'组名', width:140},
            {field:'is_admin', title:'管理员', width:80, templet: function(d){
                return d.is_admin ? '<span style="color:#009688;">是</span>' : '否';
            }},
            {field:'user_count', title:'用户数', width:80, templet: function(d){
                return Number(d.user_count || 0).toLocaleString();
            }},
            {field:'permissions', title:'核心权限', minWidth:250, templet: function(d){
                var tags = [];
                for(var i = 0; i < permFields.length; i++){
                    if(d[permFields[i]]) tags.push('<span class="layui-badge layui-bg-green" style="margin:1px;">'+permLabels[permFields[i]]+'</span>');
                }
                return tags.length ? tags.join('') : '<span style="color:#999;">无</span>';
            }},
            {title:'操作', width:140, align:'center', toolbar:'#groupBar'}
        ]],
        text: {none: '暂无用户组'}
    });

    // 打开弹窗
    function openModal(title, data){
        var idx = layer.open({
            type: 1,
            title: title,
            area: ['560px', '480px'],
            content: $('#groupFormTpl').html(),
            btn: ['保存', '取消'],
            yes: function(index){
                saveGroup(index);
            },
            success: function(layero){
                if(data){
                    layero.find('#gf_id').val(data.id);
                    layero.find('#gf_name').val(data.name);
                    layero.find('#gf_permStr').val(data.permissions || '{}');
                    if(data.is_admin == 1) layero.find('#gf_is_admin').prop('checked', true);
                    for(var i = 0; i < permFields.length; i++){
                        if(data[permFields[i]] == 1) layero.find('input[name="'+permFields[i]+'"]').prop('checked', true);
                    }
                } else {
                    layero.find('#gf_permStr').val('{}');
                    for(var j = 0; j < defaultChecked.length; j++){
                        layero.find('input[name="'+defaultChecked[j]+'"]').prop('checked', true);
                    }
                }
                form.render(null, 'groupForm');
            }
        });
    }

    // 保存
    function saveGroup(layerIndex){
        var $layer = $('.layui-layer-content:visible');
        var name = $layer.find('#gf_name').val();
        if(!name || !name.trim()){
            layer.msg('请输入组名', {icon:2});
            return;
        }
        var permStr = $layer.find('#gf_permStr').val() || '{}';
        var perms = {};
        try { perms = JSON.parse(permStr); } catch(e){
            layer.msg('扩展权限 JSON 格式错误', {icon:2});
            return;
        }
        var body = {
            id: parseInt($layer.find('#gf_id').val()) || 0,
            name: name.trim(),
            is_admin: $layer.find('#gf_is_admin').is(':checked') ? 1 : 0,
            permissions: perms
        };
        for(var i = 0; i < permFields.length; i++){
            body[permFields[i]] = $layer.find('input[name="'+permFields[i]+'"]').is(':checked') ? 1 : 0;
        }
        $.ajax({
            url: '/admin/user-groups/save',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(body),
            success: function(res){
                if(res.code===0||res.success){
                    layer.msg('保存成功', {icon:1});
                    layer.close(layerIndex);
                    table.reload('groupsTable');
                } else {
                    layer.msg(res.msg||res.message||'保存失败', {icon:2});
                }
            },
            error: function(){ layer.msg('请求失败', {icon:2}); }
        });
    }

    // 新增
    $('#btnAddGroup').on('click', function(){ openModal('新增用户组', null); });

    // 行操作
    table.on('tool(groupsTable)', function(obj){
        var d = obj.data;
        if(obj.event === 'edit'){
            openModal('编辑用户组', d);
        } else if(obj.event === 'del'){
            if(d.id <= 3){
                layer.msg('系统内置用户组不可删除', {icon:2});
                return;
            }
            layer.confirm('确定删除此用户组？', {icon:3, title:'确认删除'}, function(index){
                $.ajax({
                    url: '/admin/user-groups/delete',
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({id: d.id}),
                    success: function(res){
                        if(res.code===0||res.success){
                            layer.msg('删除成功', {icon:1});
                            table.reload('groupsTable');
                        } else {
                            layer.msg(res.msg||res.message||'删除失败', {icon:2});
                        }
                    },
                    error: function(){ layer.msg('请求失败', {icon:2}); }
                });
                layer.close(index);
            });
        }
    });
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
