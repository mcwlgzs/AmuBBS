<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>板块管理</h3>
        <button class="layui-btn layui-btn-sm" id="btnCreateForum"><i class="layui-icon layui-icon-add-1"></i> 新增板块</button>
    </div>
    <div class="layui-card-body">
        <table id="forumTable" lay-filter="forumTable"></table>
    </div>
</div>

<!-- 权限表单模板 -->
<script type="text/html" id="accessFormTpl">
<div style="padding:20px;">
    <p style="font-size:13px;color:#999;margin-bottom:12px;">未勾选的权限将被禁止。默认全部允许。</p>
    <form class="layui-form" lay-filter="accessForm">
        <table class="layui-table" style="font-size:13px;">
            <thead><tr><th>用户组</th><th>浏览</th><th>发帖</th><th>回复</th><th>附件</th><th>下载</th></tr></thead>
            <tbody id="accessTbody"></tbody>
        </table>
        <div style="text-align:right;margin-top:12px;">
            <button type="button" class="layui-btn layui-btn-primary" id="accessCancelBtn">取消</button>
            <button type="button" class="layui-btn" id="accessSaveBtn">保存权限</button>
        </div>
    </form>
</div>
</script>

<script>
layui.use(['table', 'form', 'layer'], function(){
    var $ = layui.$, table = layui.table, layer = layui.layer, form = layui.form;
    var esc = layui.util.escape;
    var currentIdx = -1;

    // API 驱动表格
    table.render({
        elem: '#forumTable', id: 'forumTable', url: '/admin/api/forums',
        page: false, skin: 'line', even: true, size: 'sm',
        cols: [[
            {field:'id', title:'ID', width:60, sort:true},
            {field:'name', title:'板块名称', width:180, templet:function(d){
                var prefix = d.parent_id > 0 ? '<span style="color:#ccc;margin-right:4px;">└</span>' : '';
                return prefix + esc(d.name);
            }},
            {field:'description', title:'描述', minWidth:180, templet:function(d){
                return '<span style="color:#64748b;font-size:13px;">' + esc(d.description || '') + '</span>';
            }},
            {field:'parent_id', title:'上级', width:70, templet:function(d){ return d.parent_id || '-'; }},
            {field:'rank', title:'排序', width:70},
            {field:'moderators_display', title:'版主', width:120, templet:function(d){
                return d.moderators_display ? esc(d.moderators_display) : '<span style="color:#ccc;">-</span>';
            }},
            {field:'thread_count', title:'帖子/回复', width:110, templet:function(d){
                return (d.thread_count||0).toLocaleString() + ' / ' + (d.post_count||0).toLocaleString();
            }},
            {title:'操作', width:180, fixed:'right', align:'center', templet:function(d){
                return '<button class="layui-btn layui-btn-xs layui-btn-primary" lay-event="edit">编辑</button>'
                    + '<button class="layui-btn layui-btn-xs layui-btn-normal" lay-event="access">权限</button>'
                    + '<button class="layui-btn layui-btn-xs layui-btn-danger" lay-event="del">删除</button>';
            }}
        ]],
        parseData: function(res){ return {code:res.code, msg:res.msg, count:res.count, data:res.data}; },
        text: {none: '暂无板块，请添加'}
    });

    // POST helper
    function postJson(url, data, onSuccess){
        var l = layer.load(1);
        $.ajax({url:url, method:'POST', contentType:'application/json', data:JSON.stringify(data),
            success:function(res){ layer.close(l);
                if(res.code===0||res.success){ layer.msg(res.msg||res.message||'操作成功',{icon:1},function(){ if(onSuccess) onSuccess(res); else table.reload('forumTable'); }); }
                else { layer.msg(res.msg||res.message||'操作失败',{icon:2}); }
            }, error:function(){ layer.close(l); layer.msg('请求失败',{icon:2}); }
        });
    }

    // 构建板块表单 HTML
    function buildFormHtml(f){
        var isEdit = f && f.id;
        return '<form class="layui-form" lay-filter="forumForm" style="padding:20px;">'
            +'<div class="layui-form-item"><label class="layui-form-label">板块名称</label><div class="layui-input-block"><input type="text" name="name" class="layui-input" value="'+(isEdit?esc(f.name):'')+'" placeholder="请输入板块名称"></div></div>'
            +'<div class="layui-form-item"><label class="layui-form-label">描述</label><div class="layui-input-block"><input type="text" name="description" class="layui-input" value="'+(isEdit?esc(f.description||''):'')+'" placeholder="板块描述"></div></div>'
            +'<div class="layui-form-item"><label class="layui-form-label">上级板块ID</label><div class="layui-input-block"><input type="number" name="parent_id" class="layui-input" min="0" value="'+(isEdit?f.parent_id:0)+'" placeholder="0 为顶级"></div></div>'
            +'<div class="layui-form-item"><label class="layui-form-label">排序权重</label><div class="layui-input-block"><input type="number" name="rank" class="layui-input" min="0" value="'+(isEdit?f.rank:0)+'" placeholder="越大越靠前"></div></div>'
            +'<div class="layui-form-item"><label class="layui-form-label">版主</label><div class="layui-input-block"><input type="text" name="moderators" class="layui-input" value="'+(isEdit?esc(f.moderators_display||f.moderators||''):'')+'" placeholder="用户名，多个用逗号分隔"></div></div>'
            +'<div class="layui-form-item layui-form-text"><label class="layui-form-label">板块公告</label><div class="layui-input-block"><textarea name="announcement" class="layui-textarea" rows="3" placeholder="板块公告内容（可选）">'+(isEdit?esc(f.announcement||''):'')+'</textarea></div></div>'
            +'<div class="layui-form-item"><label class="layui-form-label">SEO 标题</label><div class="layui-input-block"><input type="text" name="seo_title" class="layui-input" value="'+(isEdit?esc(f.seo_title||''):'')+'" placeholder="留空则使用板块名称"></div></div>'
            +'<div class="layui-form-item"><label class="layui-form-label">SEO 关键词</label><div class="layui-input-block"><input type="text" name="seo_keywords" class="layui-input" value="'+(isEdit?esc(f.seo_keywords||''):'')+'" placeholder="关键词1,关键词2"></div></div>'
            +'<div class="layui-form-item" style="text-align:right;margin-bottom:0;"><button type="button" class="layui-btn layui-btn-primary" id="forumCancelBtn">取消</button> <button type="button" class="layui-btn" lay-submit lay-filter="submitForum">保存</button></div>'
            +'</form>';
    }

    var editId = 0;

    // 打开新增/编辑弹窗
    function openForumForm(f){
        var isEdit = f && f.id;
        editId = isEdit ? f.id : 0;
        currentIdx = layer.open({
            type:1, title: isEdit ? '编辑板块' : '新增板块', area:['560px','560px'],
            content: buildFormHtml(f), shadeClose:true,
            success: function(layero){
                form.render(null, 'forumForm');
                $(layero).find('#forumCancelBtn').on('click', function(){ layer.close(currentIdx); });
            }
        });
    }

    // 新增按钮
    $('#btnCreateForum').on('click', function(){ openForumForm(null); });

    // 行操作
    table.on('tool(forumTable)', function(obj){
        var d = obj.data;
        if(obj.event === 'edit'){
            openForumForm(d);
        } else if(obj.event === 'del'){
            layer.confirm('确定要删除板块「'+esc(d.name)+'」吗？', {icon:3, title:'确认删除'}, function(ci){
                layer.close(ci);
                postJson('/admin/forums/delete', {id: d.id});
            });
        } else if(obj.event === 'access'){
            openAccess(d.id, d.name);
        }
    });

    // 提交板块表单
    form.on('submit(submitForum)', function(data){
        var field = data.field;
        var url = editId ? '/admin/forums/update' : '/admin/forums/create';
        if(editId) field.id = editId;
        postJson(url, field, function(){ layer.close(currentIdx); table.reload('forumTable'); });
        return false;
    });

    // 权限弹窗
    function openAccess(forumId, forumName){
        var l = layer.load(1);
        $.ajax({url:'/admin/forums/access', method:'POST', contentType:'application/json',
            data: JSON.stringify({forum_id: forumId}),
            success: function(res){
                layer.close(l);
                if(!res.success && res.code !== 0){ layer.msg(res.msg||res.message||'加载失败',{icon:2}); return; }
                var groups = res.data.groups, access = res.data.access;
                var tbodyHtml = '';
                for(var i=0;i<groups.length;i++){
                    var g = groups[i], p = access[g.id]||{};
                    var ar=p.allow_read!==undefined?p.allow_read:1, at=p.allow_thread!==undefined?p.allow_thread:1;
                    var ap=p.allow_post!==undefined?p.allow_post:1, aa=p.allow_attach!==undefined?p.allow_attach:1;
                    var ad=p.allow_down!==undefined?p.allow_down:1;
                    tbodyHtml += '<tr><td>'+esc(g.name)+'</td>'
                        +'<td><input type="checkbox" data-gid="'+g.id+'" data-perm="allow_read"'+(ar?' checked':'')+'></td>'
                        +'<td><input type="checkbox" data-gid="'+g.id+'" data-perm="allow_thread"'+(at?' checked':'')+'></td>'
                        +'<td><input type="checkbox" data-gid="'+g.id+'" data-perm="allow_post"'+(ap?' checked':'')+'></td>'
                        +'<td><input type="checkbox" data-gid="'+g.id+'" data-perm="allow_attach"'+(aa?' checked':'')+'></td>'
                        +'<td><input type="checkbox" data-gid="'+g.id+'" data-perm="allow_down"'+(ad?' checked':'')+'></td></tr>';
                }
                currentIdx = layer.open({
                    type:1, title:'板块权限 - '+esc(forumName), area:['640px','460px'],
                    content: $('#accessFormTpl').html(), shadeClose:true,
                    success: function(layero){
                        $(layero).find('#accessTbody').html(tbodyHtml);
                        form.render(null, 'accessForm');
                        $(layero).find('#accessCancelBtn').on('click', function(){ layer.close(currentIdx); });
                        $(layero).find('#accessSaveBtn').on('click', function(){
                            var permissions = {};
                            $(layero).find('#accessTbody input[type="checkbox"]').each(function(){
                                var gid=$(this).data('gid'), perm=$(this).data('perm');
                                if(!permissions[gid]) permissions[gid]={};
                                permissions[gid][perm] = this.checked?1:0;
                            });
                            postJson('/admin/forums/access-save', {forum_id:forumId, permissions:permissions}, function(){ layer.close(currentIdx); });
                        });
                    }
                });
            },
            error: function(){ layer.close(l); layer.msg('网络错误',{icon:2}); }
        });
    }

    form.render();
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
