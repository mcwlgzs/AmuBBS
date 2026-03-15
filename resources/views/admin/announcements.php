<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>公告管理</h3>
        <button class="layui-btn layui-btn-sm" id="btnCreateAnn">新增公告</button>
    </div>
    <div class="layui-card-body">
        <table id="announceTable" lay-filter="announceTable"></table>
    </div>
</div>

<!-- 行操作模板 -->
<script type="text/html" id="annBar">
    <button class="layui-btn layui-btn-xs layui-btn-primary" lay-event="edit">编辑</button>
    <button class="layui-btn layui-btn-xs" lay-event="toggle">{{d.is_enabled ? '禁用' : '启用'}}</button>
    <button class="layui-btn layui-btn-xs layui-btn-danger" lay-event="del">删除</button>
</script>

<!-- 模态框表单模板 -->
<script type="text/html" id="annFormTpl">
<form class="layui-form" lay-filter="annForm" style="padding:20px;">
    <div class="layui-form-item">
        <label class="layui-form-label">公告标题</label>
        <div class="layui-input-block">
            <input type="text" name="title" lay-verify="required" maxlength="200" class="layui-input" placeholder="请输入公告标题">
        </div>
    </div>
    <div class="layui-form-item layui-form-text">
        <label class="layui-form-label">公告内容</label>
        <div class="layui-input-block">
            <textarea name="content" class="layui-textarea" rows="3" placeholder="详细内容，留空则只显示标题"></textarea>
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">跳转链接</label>
        <div class="layui-input-block">
            <input type="text" name="url" class="layui-input" placeholder="点击公告跳转的URL（可选）">
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">类型</label>
        <div class="layui-input-block">
            <select name="type" lay-ignore style="width:100%;height:38px;border:1px solid #e6e6e6;border-radius:2px;padding:0 10px;">
                <option value="0">普通</option>
                <option value="1">重要</option>
                <option value="2">紧急</option>
            </select>
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">排序权重</label>
        <div class="layui-input-block">
            <input type="number" name="rank" class="layui-input" min="0" value="0" placeholder="越大越靠前">
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">生效时间</label>
        <div class="layui-input-block">
            <input type="datetime-local" name="start_at" class="layui-input" placeholder="留空立即生效">
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">过期时间</label>
        <div class="layui-input-block">
            <input type="datetime-local" name="end_at" class="layui-input" placeholder="留空永不过期">
        </div>
    </div>
    <input type="hidden" name="id" value="">
    <div class="layui-form-item" style="text-align:right;margin-bottom:0;">
        <button type="button" class="layui-btn layui-btn-primary" id="annCancelBtn">取消</button>
        <button type="button" class="layui-btn" lay-submit lay-filter="submitAnn">保存</button>
    </div>
</form>
</script>

<script>
layui.use(['table', 'form', 'layer'], function(){
    var $ = layui.$, table = layui.table, form = layui.form, layer = layui.layer;
    var currentLayerIndex = -1, editId = 0;

    function formatTime(timestamp){
        if(!timestamp) return '-';
        var d = new Date(timestamp * 1000);
        var M = d.getMonth()+1, D = d.getDate(), h = d.getHours(), m = d.getMinutes();
        return d.getFullYear()+'-'+(M<10?'0'+M:M)+'-'+(D<10?'0'+D:D)+' '+(h<10?'0'+h:h)+':'+(m<10?'0'+m:m);
    }

    function formatShortTime(timestamp){
        if(!timestamp) return '';
        var d = new Date(timestamp * 1000);
        var M = d.getMonth()+1, D = d.getDate(), h = d.getHours(), m = d.getMinutes();
        return (M<10?'0'+M:M)+'-'+(D<10?'0'+D:D)+' '+(h<10?'0'+h:h)+':'+(m<10?'0'+m:m);
    }

    table.render({
        elem: '#announceTable',
        id: 'announceTable',
        url: '/admin/api/announcements',
        page: false,
        cols: [[
            {field:'id', title:'ID', width:60, sort:true},
            {field:'title', title:'标题', minWidth:150},
            {field:'content', title:'内容', minWidth:120, templet: function(d){
                var c = d.content || '';
                return '<span title="'+layui.util.escape(c)+'">'+ layui.util.escape(c.length > 30 ? c.substring(0,30)+'...' : c) +'</span>';
            }},
            {field:'url', title:'链接', width:100, templet: function(d){
                return d.url ? '<a href="'+layui.util.escape(d.url)+'" target="_blank" title="'+layui.util.escape(d.url)+'">查看</a>' : '-';
            }},
            {field:'type', title:'类型', width:70, templet: function(d){
                if(d.type==2) return '<span style="color:var(--danger,#e53e3e);font-weight:600;">紧急</span>';
                if(d.type==1) return '<span style="color:var(--warning,#d69e2e);font-weight:600;">重要</span>';
                return '普通';
            }},
            {field:'is_enabled', title:'状态', width:70, templet: function(d){
                return d.is_enabled ? '<span style="color:var(--success,#38a169);">启用</span>' : '<span style="color:var(--text-muted,#a0aec0);">禁用</span>';
            }},
            {field:'rank', title:'排序', width:60},
            {field:'start_at', title:'有效期', width:180, templet: function(d){
                return '<span style="font-size:12px;color:var(--text-muted,#a0aec0);">'+(d.start_at ? formatShortTime(d.start_at) : '即时')+' ~ '+(d.end_at ? formatShortTime(d.end_at) : '永久')+'</span>';
            }},
            {field:'created_at', title:'创建时间', width:160, templet: function(d){ return '<span style="font-size:12px;color:var(--text-muted,#a0aec0);">'+formatTime(d.created_at)+'</span>'; }},
            {title:'操作', width:180, align:'center', toolbar:'#annBar'}
        ]],
        text: {none: '暂无公告'}
    });

    // 获取模态框HTML
    function getFormHtml(){ return $('#annFormTpl').html(); }

    // 新增
    $('#btnCreateAnn').on('click', function(){
        editId = 0;
        currentLayerIndex = layer.open({
            type: 1, title: '新增公告', area: ['560px', '520px'],
            content: getFormHtml(),
            success: function(layero){
                form.render(null, 'annForm');
                layero.find('#annCancelBtn').on('click', function(){ layer.close(currentLayerIndex); });
            }
        });
    });

    // 行操作
    table.on('tool(announceTable)', function(obj){
        var d = obj.data;
        if(obj.event === 'edit'){
            editId = d.id;
            currentLayerIndex = layer.open({
                type: 1, title: '编辑公告', area: ['560px', '520px'],
                content: getFormHtml(),
                success: function(layero){
                    layero.find('input[name="title"]').val(d.title);
                    layero.find('textarea[name="content"]').val(d.content || '');
                    layero.find('input[name="url"]').val(d.url || '');
                    layero.find('select[name="type"]').val(String(d.type));
                    layero.find('input[name="rank"]').val(d.rank);
                    layero.find('input[name="id"]').val(d.id);
                    if(d.start_at) layero.find('input[name="start_at"]').val(new Date(d.start_at*1000).toISOString().slice(0,16));
                    if(d.end_at) layero.find('input[name="end_at"]').val(new Date(d.end_at*1000).toISOString().slice(0,16));
                    form.render(null, 'annForm');
                    layero.find('#annCancelBtn').on('click', function(){ layer.close(currentLayerIndex); });
                }
            });
        } else if(obj.event === 'toggle'){
            $.ajax({
                url: '/admin/announcements/toggle', type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({id: d.id, is_enabled: d.is_enabled ? 0 : 1}),
                dataType: 'json',
                success: function(res){
                    if(res.code===0||res.success){ layer.msg('操作成功', {icon:1}); table.reload('announceTable'); }
                    else { layer.msg(res.msg||res.message||'操作失败', {icon:2}); }
                },
                error: function(){ layer.msg('请求失败', {icon:2}); }
            });
        } else if(obj.event === 'del'){
            layer.confirm('确定要删除该公告吗？', {icon:3, title:'确认删除'}, function(index){
                $.ajax({
                    url: '/admin/announcements/delete', type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({id: d.id}),
                    dataType: 'json',
                    success: function(res){
                        if(res.code===0||res.success){ layer.msg('删除成功', {icon:1}); table.reload('announceTable'); }
                        else { layer.msg(res.msg||res.message||'操作失败', {icon:2}); }
                    },
                    error: function(){ layer.msg('请求失败', {icon:2}); }
                });
                layer.close(index);
            });
        }
    });

    // 提交表单
    form.on('submit(submitAnn)', function(data){
        var field = data.field;
        var url = editId ? '/admin/announcements/update' : '/admin/announcements/create';
        if(editId) field.id = editId;
        $.ajax({
            url: url, type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(field),
            dataType: 'json',
            success: function(res){
                if(res.code===0||res.success){
                    layer.msg('保存成功', {icon:1});
                    layer.close(currentLayerIndex);
                    table.reload('announceTable');
                } else {
                    layer.msg(res.msg||res.message||'操作失败', {icon:2});
                }
            },
            error: function(){ layer.msg('请求失败', {icon:2}); }
        });
        return false;
    });
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
