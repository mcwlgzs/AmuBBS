<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>积分记录</h3>
    </div>
    <div class="layui-card-body">

        <!-- 搜索 -->
        <div class="layui-form" style="margin-bottom:15px;">
            <div class="layui-inline">
                <input type="text" id="searchInput" placeholder="搜索用户名..." class="layui-input" style="width:200px;height:38px;">
            </div>
            <div class="layui-inline">
                <button class="layui-btn layui-btn-sm" id="btnSearch">搜索</button>
                <button class="layui-btn layui-btn-sm layui-btn-primary" id="btnClear">清除</button>
            </div>
        </div>

        <table id="creditTable" lay-filter="creditTable"></table>
    </div>
</div>

<script>
layui.use(['table', 'layer'], function(){
    var $ = layui.$, table = layui.table, layer = layui.layer;

    function formatTime(timestamp){
        if(!timestamp) return '-';
        var d = new Date(timestamp * 1000);
        var M = d.getMonth()+1, D = d.getDate(), h = d.getHours(), m = d.getMinutes();
        return d.getFullYear()+'-'+(M<10?'0'+M:M)+'-'+(D<10?'0'+D:D)+' '+(h<10?'0'+h:h)+':'+(m<10?'0'+m:m);
    }

    var typeMap = {
        'checkin': ['签到', 'admin-badge-success'],
        'thread':  ['发帖', 'admin-badge-admin'],
        'post':    ['回复', 'admin-badge-admin'],
        'like':    ['点赞', 'admin-badge-mod'],
        'reward':  ['打赏', 'admin-badge-mod'],
        'transfer':['转账', 'admin-badge-user'],
        'consume': ['消费', 'admin-badge-danger'],
        'refund':  ['退款', 'admin-badge-user']
    };

    table.render({
        elem: '#creditTable',
        id: 'creditTable',
        url: '/admin/api/credit-logs',
        page: true,
        limit: 30,
        limits: [15, 30, 50, 100],
        cols: [[
            {field:'id', title:'ID', width:70, sort:true},
            {field:'username', title:'用户', width:120, templet: function(d){
                return '<a href="/user/'+d.user_id+'">'+layui.util.escape(d.username||'未知')+'</a>';
            }},
            {field:'amount', title:'变动', width:100, templet: function(d){
                if(d.amount > 0) return '<span style="color:#22c55e;font-weight:600;">+'+d.amount+'</span>';
                return '<span style="color:#ef4444;font-weight:600;">'+d.amount+'</span>';
            }},
            {field:'type', title:'类型', width:80, templet: function(d){
                var t = typeMap[d.type] || [d.type||'其他', 'admin-badge-user'];
                return '<span class="admin-badge '+t[1]+'">'+t[0]+'</span>';
            }},
            {field:'description', title:'描述', minWidth:180, templet: function(d){
                var r = d.description || d.reason || '';
                return '<span style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;" title="'+layui.util.escape(r)+'">'+layui.util.escape(r)+'</span>';
            }},
            {field:'created_at', title:'时间', width:160, templet: function(d){
                return '<span style="white-space:nowrap;color:#999;">'+formatTime(d.created_at)+'</span>';
            }}
        ]],
        text: {none: '暂无积分记录'}
    });

    // 搜索
    $('#btnSearch').on('click', function(){
        table.reload('creditTable', {
            where: {search: $('#searchInput').val()},
            page: {curr: 1}
        });
    });
    $('#btnClear').on('click', function(){
        $('#searchInput').val('');
        table.reload('creditTable', {where: {search:''}, page: {curr: 1}});
    });
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
