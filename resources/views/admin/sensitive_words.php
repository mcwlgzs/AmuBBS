<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>敏感词管理</h3>
        <button class="layui-btn layui-btn-sm" id="btnToggleAdd">添加敏感词</button>
    </div>

    <!-- 添加表单 -->
    <div class="layui-card-body" id="addWordPanel" style="display:none;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
        <form class="layui-form" lay-filter="addWordForm" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <div>
                <label style="font-size:13px;color:#64748b;">敏感词</label>
                <input type="text" name="word" class="layui-input" placeholder="敏感词" required lay-verify="required" style="width:200px;height:38px;">
            </div>
            <div>
                <label style="font-size:13px;color:#64748b;">替换为</label>
                <input type="text" name="replacement" class="layui-input" placeholder="替换文本（留空则用***）" style="width:200px;height:38px;">
            </div>
            <div>
                <label style="font-size:13px;color:#64748b;">级别</label>
                <select name="level" lay-ignore style="width:120px;height:38px;border:1px solid #e6e6e6;border-radius:2px;padding:0 10px;">
                    <option value="1">替换</option>
                    <option value="2">禁止发布</option>
                </select>
            </div>
            <button type="button" class="layui-btn layui-btn-sm" lay-submit lay-filter="submitWord">添加</button>
        </form>
    </div>

    <div class="layui-card-body">
        <table id="wordsTable" lay-filter="wordsTable"></table>
    </div>
</div>

<script type="text/html" id="wordsBarTpl">
    <button class="layui-btn layui-btn-sm layui-btn-danger" lay-event="delete">删除</button>
</script>

<script>
layui.use(['form', 'layer', 'table'], function(){
    var $ = layui.$, layer = layui.layer, form = layui.form, table = layui.table;

    function formatTime(ts){if(!ts)return'-';var d=new Date(ts*1000),p=function(n){return n<10?'0'+n:n};return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());}

    table.render({
        elem: '#wordsTable',
        id: 'wordsTable',
        url: '/admin/api/sensitive-words',
        page: false,
        cols: [[
            {field:'id', title:'ID', width:80, sort:true},
            {field:'word', title:'敏感词', minWidth:150},
            {field:'replacement', title:'替换为', minWidth:150, templet:function(d){return d.replacement||'***';}},
            {field:'level', title:'级别', width:120, templet:function(d){
                if(d.level==2) return '<span class="tag tag-top">禁止</span>';
                return '<span class="tag">替换</span>';
            }},
            {field:'created_at', title:'添加时间', width:160, templet:function(d){return formatTime(d.created_at);}},
            {title:'操作', width:100, align:'center', toolbar:'#wordsBarTpl'}
        ]],
        text: {none: '暂无敏感词'}
    });

    // 切换添加面板显示
    $('#btnToggleAdd').on('click', function(){
        $('#addWordPanel').toggle();
    });

    // 提交添加敏感词
    form.on('submit(submitWord)', function(data){
        var field = data.field;
        if(!field.word || !field.word.trim()){
            layer.msg('请输入敏感词', {icon: 2});
            return false;
        }
        $.ajax({
            url: '/admin/sensitive-words/create',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(field),
            dataType: 'json',
            success: function(res){
                if(res.code === 0 || res.success){
                    layer.msg('添加成功', {icon: 1});
                    table.reload('wordsTable');
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

    // 行工具栏事件
    table.on('tool(wordsTable)', function(obj){
        var data = obj.data;
        if(obj.event === 'delete'){
            layer.confirm('确定要删除该敏感词吗？', {icon: 3, title: '确认删除'}, function(index){
                $.ajax({
                    url: '/admin/sensitive-words/delete',
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({id: data.id}),
                    dataType: 'json',
                    success: function(res){
                        if(res.code === 0 || res.success){
                            layer.msg('删除成功', {icon: 1});
                            table.reload('wordsTable');
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
        }
    });
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
