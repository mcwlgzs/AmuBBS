<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
  <div class="layui-card-header">私信监控</div>
  <div class="layui-card-body">
    <!-- 搜索 -->
    <div style="margin-bottom:15px;">
      <form class="layui-form" lay-filter="searchMsgForm" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div class="layui-inline">
          <label class="layui-form-label">关键词</label>
          <div class="layui-input-inline" style="width:260px;">
            <input type="text" name="search" placeholder="搜索发送者/接收者用户名或内容" class="layui-input">
          </div>
        </div>
        <div class="layui-inline">
          <button type="button" class="layui-btn layui-btn-sm" id="btnSearchMsg"><i class="layui-icon layui-icon-search"></i></button>
        </div>
        <div class="layui-inline">
          <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="btnClearMsg">清除</button>
        </div>
      </form>
    </div>

    <table id="msgTable" lay-filter="msgTable"></table>
  </div>
</div>

<script type="text/html" id="msgBarTpl">
  <button class="layui-btn layui-btn-sm layui-btn-danger" lay-event="delete">删除</button>
</script>

<script>
layui.use(['form', 'layer', 'table'], function(){
  var $ = layui.$, layer = layui.layer, form = layui.form, table = layui.table;

  function formatTime(ts){if(!ts)return'-';var d=new Date(ts*1000),p=function(n){return n<10?'0'+n:n};return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());}

  table.render({
    elem: '#msgTable',
    id: 'msgTable',
    url: '/admin/api/messages',
    page: true,
    limit: 20,
    cols: [[
      {field:'id', title:'ID', width:80, sort:true},
      {field:'from_username', title:'发送者', width:120, templet:function(d){return d.from_username||'-';}},
      {field:'to_username', title:'接收者', width:120, templet:function(d){return d.to_username||'-';}},
      {field:'content', title:'内容', minWidth:200, templet:function(d){
        var c = layui.util.escape(d.content||'');
        var brief = c.length>60 ? c.substring(0,60)+'...' : c;
        return '<span title="'+c+'" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;">'+brief+'</span>';
      }},
      {field:'is_read', title:'阅读', width:70, templet:function(d){
        return d.is_read ? '<span class="layui-badge layui-bg-green">已读</span>' : '<span class="layui-badge layui-bg-gray">未读</span>';
      }},
      {field:'created_at', title:'时间', width:160, templet:function(d){return '<span style="color:#999;font-size:12px;">'+formatTime(d.created_at)+'</span>';}},
      {title:'操作', width:100, align:'center', toolbar:'#msgBarTpl'}
    ]],
    text: {none: '暂无私信记录'}
  });

  // 搜索
  $('#btnSearchMsg').on('click', function(){
    var searchVal = $('input[name="search"]').val();
    table.reload('msgTable', {
      where: {search: searchVal},
      page: {curr: 1}
    });
  });

  // 清除搜索
  $('#btnClearMsg').on('click', function(){
    $('input[name="search"]').val('');
    table.reload('msgTable', {
      where: {search: ''},
      page: {curr: 1}
    });
  });

  // 行工具栏事件
  table.on('tool(msgTable)', function(obj){
    var data = obj.data;
    if(obj.event === 'delete'){
      layer.confirm('确定要删除这条私信吗？', {icon:3, title:'确认'}, function(index){
        $.ajax({
          url: '/admin/messages/delete', type: 'POST',
          contentType: 'application/json', data: JSON.stringify({id: data.id}),
          success: function(res){
            if(res.code===0||res.success){ layer.msg('删除成功', {icon:1}); table.reload('msgTable'); }
            else { layer.msg(res.msg||res.message||'操作失败', {icon:2}); }
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
