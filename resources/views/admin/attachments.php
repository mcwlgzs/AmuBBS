<?php include __DIR__ . '/layout_child.php'; ?>

<!-- 统计概览（PHP 变量） -->
<div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
    <div style="flex:1;min-width:140px;background:#f0f9ff;border-radius:8px;padding:12px 16px;">
        <div style="font-size:12px;color:#64748b;">附件总数</div>
        <div style="font-size:20px;font-weight:600;color:#0369a1;"><?= number_format($total ?? 0) ?></div>
    </div>
    <div style="flex:1;min-width:140px;background:#f0fdf4;border-radius:8px;padding:12px 16px;">
        <div style="font-size:12px;color:#64748b;">图片数量</div>
        <div style="font-size:20px;font-weight:600;color:#15803d;"><?= number_format($imageCount ?? 0) ?></div>
    </div>
    <div style="flex:1;min-width:140px;background:#fefce8;border-radius:8px;padding:12px 16px;">
        <div style="font-size:12px;color:#64748b;">文件数量</div>
        <div style="font-size:20px;font-weight:600;color:#a16207;"><?= number_format($fileCount ?? 0) ?></div>
    </div>
    <div style="flex:1;min-width:140px;background:#fdf2f8;border-radius:8px;padding:12px 16px;">
        <div style="font-size:12px;color:#64748b;">占用空间</div>
        <div style="font-size:20px;font-weight:600;color:#be185d;"><?= isset($totalSize) ? ($totalSize < 1024 ? $totalSize.'B' : ($totalSize < 1048576 ? round($totalSize/1024,1).'KB' : round($totalSize/1048576,1).'MB')) : '0B' ?></div>
    </div>
</div>

<div class="layui-card">
    <div class="layui-card-header"><h3>附件管理</h3></div>
    <div class="layui-card-body">

        <!-- 搜索与筛选 -->
        <div class="layui-form" style="margin-bottom:15px;">
            <div class="layui-inline">
                <input type="text" id="searchInput" placeholder="搜索文件名..." class="layui-input" style="width:200px;height:38px;">
            </div>
            <div class="layui-inline">
                <select id="typeSelect" lay-ignore style="height:38px;border:1px solid #e6e6e6;border-radius:2px;padding:0 10px;">
                    <option value="">全部类型</option>
                    <option value="image">图片</option>
                    <option value="file">文件</option>
                </select>
            </div>
            <div class="layui-inline">
                <button class="layui-btn layui-btn-sm" id="btnSearch">搜索</button>
                <button class="layui-btn layui-btn-sm layui-btn-primary" id="btnClear">清除</button>
            </div>
        </div>

        <table id="attachTable" lay-filter="attachTable"></table>
    </div>
</div>

<script>
layui.use(['table', 'layer'], function(){
    var $ = layui.$, table = layui.table, layer = layui.layer;

    // 格式化时间戳
    function formatTime(timestamp){
        if(!timestamp) return '-';
        var d = new Date(timestamp * 1000);
        var M = d.getMonth()+1, D = d.getDate(), h = d.getHours(), m = d.getMinutes();
        return d.getFullYear()+'-'+(M<10?'0'+M:M)+'-'+(D<10?'0'+D:D)+' '+(h<10?'0'+h:h)+':'+(m<10?'0'+m:m);
    }

    // 格式化文件大小
    function formatSize(size){
        size = parseInt(size) || 0;
        if(size < 1024) return size + 'B';
        if(size < 1048576) return (size/1024).toFixed(1) + 'KB';
        return (size/1048576).toFixed(1) + 'MB';
    }

    table.render({
        elem: '#attachTable',
        id: 'attachTable',
        url: '/admin/api/attachments',
        page: true,
        limit: 20,
        limits: [10, 20, 50],
        cols: [[
            {field:'id', title:'ID', width:70, sort:true},
            {field:'filepath', title:'预览', width:80, templet: function(d){
                if(d.is_image){
                    return '<img src="'+layui.util.escape(d.filepath)+'" style="max-width:60px;max-height:40px;border-radius:4px;" loading="lazy">';
                }
                return '<i class="layui-icon layui-icon-file" style="font-size:24px;color:#999;"></i>';
            }},
            {field:'filename', title:'文件名', minWidth:180, templet: function(d){
                return '<span title="'+layui.util.escape(d.filename)+'" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;">'+layui.util.escape(d.filename)+'</span>';
            }},
            {field:'username', title:'上传者', width:100, templet: function(d){
                if(d.user_id) return '<a href="/user/'+d.user_id+'">'+layui.util.escape(d.username||'未知')+'</a>';
                return '<span style="color:#94a3b8;">-</span>';
            }},
            {field:'filesize', title:'大小', width:90, templet: function(d){ return formatSize(d.filesize); }},
            {field:'is_image', title:'类型', width:70, templet: function(d){
                return d.is_image ? '<span style="color:#15803d;">图片</span>' : '<span style="color:#64748b;">文件</span>';
            }},
            {field:'created_at', title:'上传时间', width:160, templet: function(d){ return formatTime(d.created_at); }},
            {title:'操作', width:80, align:'center', toolbar:'#attachBar'}
        ]],
        text: {none: '暂无附件'}
    });

    // 搜索
    $('#btnSearch').on('click', function(){
        table.reload('attachTable', {
            where: {search: $('#searchInput').val(), type: $('#typeSelect').val()},
            page: {curr: 1}
        });
    });
    $('#btnClear').on('click', function(){
        $('#searchInput').val('');
        $('#typeSelect').val('');
        table.reload('attachTable', {where: {search:'', type:''}, page: {curr: 1}});
    });

    // 行操作
    table.on('tool(attachTable)', function(obj){
        if(obj.event === 'del'){
            layer.confirm('确定要删除这个附件吗？删除后不可恢复。', {icon:3, title:'确认删除'}, function(index){
                $.ajax({
                    url: '/admin/attachments/delete',
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({id: obj.data.id}),
                    dataType: 'json',
                    success: function(res){
                        if(res.code===0||res.success){
                            layer.msg('删除成功', {icon:1});
                            table.reload('attachTable');
                        } else {
                            layer.msg(res.msg||res.message||'操作失败', {icon:2});
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
<script type="text/html" id="attachBar">
    <button class="layui-btn layui-btn-xs layui-btn-danger" lay-event="del">删除</button>
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
