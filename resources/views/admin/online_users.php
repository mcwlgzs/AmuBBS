<?php include __DIR__ . '/layout_child.php'; ?>

<div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
    <div style="flex:1;min-width:140px;background:#f0f9ff;border-radius:8px;padding:12px 16px;">
        <div style="font-size:12px;color:#64748b;">在线会员</div>
        <div style="font-size:20px;font-weight:600;color:#0369a1;" id="statMember">-</div>
    </div>
    <div style="flex:1;min-width:140px;background:#f0fdf4;border-radius:8px;padding:12px 16px;">
        <div style="font-size:12px;color:#64748b;">在线游客</div>
        <div style="font-size:20px;font-weight:600;color:#15803d;" id="statGuest">-</div>
    </div>
    <div style="flex:1;min-width:140px;background:#fefce8;border-radius:8px;padding:12px 16px;">
        <div style="font-size:12px;color:#64748b;">总在线</div>
        <div style="font-size:20px;font-weight:600;color:#a16207;" id="statTotal">-</div>
    </div>
</div>

<div class="layui-card">
    <div class="layui-card-header"><h3>在线用户列表（15分钟内活跃）</h3></div>
    <div class="layui-card-body">
        <table id="onlineTable" lay-filter="onlineTable"></table>
    </div>
</div>

<script>
layui.use(['table', 'layer'], function(){
    var $ = layui.$, table = layui.table;

    function formatTime(timestamp){
        if(!timestamp) return '-';
        var d = new Date(timestamp * 1000);
        var h = d.getHours(), m = d.getMinutes(), s = d.getSeconds();
        return (h<10?'0'+h:h)+':'+(m<10?'0'+m:m)+':'+(s<10?'0'+s:s);
    }

    table.render({
        elem: '#onlineTable',
        id: 'onlineTable',
        url: '/admin/api/online-users',
        page: false,
        cols: [[
            {field:'user_id', title:'用户ID', width:80},
            {field:'username', title:'用户', minWidth:150, templet: function(d){
                if(d.user_id > 0) return '<a href="/user/'+d.user_id+'">'+layui.util.escape(d.username||'未知')+'</a>';
                return '<span style="color:#999;">游客</span>';
            }},
            {field:'ip', title:'IP', width:160, templet: function(d){
                return '<span style="font-family:monospace;font-size:13px;">'+layui.util.escape(d.ip)+'</span>';
            }},
            {field:'last_activity', title:'最后活跃', width:120, templet: function(d){ return formatTime(d.last_activity); }}
        ]],
        done: function(res){
            // 从返回数据中计算会员/游客数量
            var data = res.data || [];
            var memberCount = 0, guestCount = 0;
            for(var i = 0; i < data.length; i++){
                if(data[i].user_id > 0) memberCount++;
                else guestCount++;
            }
            $('#statMember').text(memberCount);
            $('#statGuest').text(guestCount);
            $('#statTotal').text(memberCount + guestCount);
        },
        text: {none: '暂无在线用户数据'}
    });
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
