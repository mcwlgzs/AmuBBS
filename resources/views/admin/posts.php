<?php $pageTitle = '回帖管理'; $currentPage = 'posts'; include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header">回帖管理</div>
    <div class="layui-card-body">

        <!-- 搜索表单 -->
        <form class="layui-form layui-form-item" lay-filter="searchForm" style="margin-bottom:10px;">
            <div class="layui-inline">
                <input type="text" name="search" placeholder="搜索内容" class="layui-input" style="width:160px;">
            </div>
            <div class="layui-inline">
                <input type="text" name="username" placeholder="用户名" class="layui-input" style="width:120px;">
            </div>
            <div class="layui-inline">
                <input type="text" name="thread_id" placeholder="帖子ID" class="layui-input" style="width:100px;">
            </div>
            <div class="layui-inline">
                <input type="text" name="ip" placeholder="IP地址" class="layui-input" style="width:120px;">
            </div>
            <div class="layui-inline">
                <button type="submit" class="layui-btn layui-btn-sm" lay-submit lay-filter="doSearch">搜索</button>
                <button type="reset" class="layui-btn layui-btn-sm layui-btn-primary" id="resetBtn">重置</button>
            </div>
        </form>

        <!-- 工具栏模板 -->
        <script type="text/html" id="postsToolbar">
            <div class="layui-btn-container">
                <button class="layui-btn layui-btn-sm layui-btn-danger" lay-event="batchDelete">批量删除</button>
            </div>
        </script>

        <!-- 行操作模板 -->
        <script type="text/html" id="postsRowBar">
            <a class="layui-btn layui-btn-danger layui-btn-xs" lay-event="delete">删除</a>
        </script>

        <!-- 内容列模板 -->
        <script type="text/html" id="postContentTpl">
            {{# var brief = layui.util.escape((d.content||'').replace(/<[^>]+>/g,'').substring(0,80)); }}
            <span title="{{ brief }}">{{ brief }}</span>
        </script>

        <!-- 所属帖子列模板 -->
        <script type="text/html" id="postThreadTpl">
            <a href="/thread/{{d.thread_id}}" target="_blank">#{{d.thread_id}}</a>
        </script>

        <table id="postsTable" lay-filter="postsTable"></table>

    </div>
</div>

<script>
layui.use(['table', 'form', 'layer'], function(){
    var table = layui.table;
    var form = layui.form;
    var layer = layui.layer;
    var $ = layui.$;
    var currentWhere = {};

    // 格式化 unix 时间戳
    function formatTime(ts){
        if(!ts) return '-';
        var d = new Date(ts * 1000);
        var pad = function(n){ return n < 10 ? '0'+n : n; };
        return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())
            +' '+pad(d.getHours())+':'+pad(d.getMinutes());
    }

    table.render({
        elem: '#postsTable',
        id: 'postsTable',
        toolbar: '#postsToolbar',
        defaultToolbar: [],
        url: '/admin/api/posts',
        page: true,
        limit: 20,
        autoSort: false,
        cols: [[
            {type: 'checkbox', fixed: 'left', width: 50},
            {field: 'id', title: 'ID', width: 80, sort: true},
            {field: 'content', title: '内容', minWidth: 250, templet: '#postContentTpl'},
            {field: 'thread_title', title: '所属帖子', width: 160, templet: '#postThreadTpl'},
            {field: 'username', title: '作者', width: 100, templet: function(d){
                return d.nickname || d.username || '-';
            }},
            {field: 'user_ip', title: 'IP', width: 130, templet: function(d){ return d.user_ip || '-'; }},
            {field: 'floor', title: '楼层', width: 70, templet: function(d){ return d.floor || '-'; }},
            {field: 'created_at', title: '时间', width: 150, sort: true, templet: function(d){ return formatTime(d.created_at); }},
            {fixed: 'right', title: '操作', width: 90, toolbar: '#postsRowBar'}
        ]],
        text: {none: '暂无回帖'}
    });

    // 搜索
    form.on('submit(doSearch)', function(data){
        currentWhere = {
            search: data.field.search,
            username: data.field.username,
            thread_id: data.field.thread_id,
            ip: data.field.ip
        };
        table.reload('postsTable', {
            page: {curr: 1},
            where: currentWhere
        });
        return false;
    });

    // 重置
    $('#resetBtn').on('click', function(){
        $('form[lay-filter="searchForm"]')[0].reset();
        currentWhere = {};
        table.reload('postsTable', {
            page: {curr: 1},
            where: {}
        });
    });

    // 服务端排序
    table.on('sort(postsTable)', function(obj){
        table.reload('postsTable', {
            initSort: obj,
            where: $.extend({}, currentWhere, {sort: obj.field, dir: obj.type})
        });
    });

    // 头部工具栏事件（批量删除）
    table.on('toolbar(postsTable)', function(obj){
        if(obj.event === 'batchDelete'){
            var checkStatus = table.checkStatus('postsTable');
            var data = checkStatus.data;
            if(data.length === 0){
                layer.msg('请先选择要删除的回帖', {icon: 0});
                return;
            }
            layer.confirm('确定要批量删除 '+data.length+' 条回帖吗？', {icon: 3, title: '确认'}, function(index){
                var ids = data.map(function(item){ return item.id; });
                $.ajax({
                    url: '/admin/posts/batch',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ids: ids, action: 'delete'}),
                    success: function(res){
                        if(res.code === 0 || res.success){
                            layer.msg('删除成功', {icon: 1});
                            table.reload('postsTable');
                        } else {
                            layer.msg(res.message || res.msg || '操作失败', {icon: 2});
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

    // 行工具栏事件（单行删除）
    table.on('tool(postsTable)', function(obj){
        if(obj.event === 'delete'){
            layer.confirm('确定要删除这条回帖吗？', {icon: 3, title: '确认'}, function(index){
                $.ajax({
                    url: '/admin/posts/delete',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({post_id: obj.data.id}),
                    success: function(res){
                        if(res.code === 0 || res.success){
                            layer.msg('删除成功', {icon: 1});
                            table.reload('postsTable');
                        } else {
                            layer.msg(res.message || res.msg || '操作失败', {icon: 2});
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
