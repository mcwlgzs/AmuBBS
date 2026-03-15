<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
  <div class="layui-card-header">
    IP 黑名单
    <button class="layui-btn layui-btn-sm" style="float:right;margin-top:4px;" id="btnAddIp">添加 IP</button>
  </div>

  <div class="layui-card-body" id="addIpPanel" style="display:none;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
    <form class="layui-form" lay-filter="addIpForm" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">IP 地址</label>
        <div class="layui-input-inline" style="width:180px;">
          <input type="text" name="ip" class="layui-input" lay-verify="required" placeholder="如 192.168.1.1">
        </div>
      </div>
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">原因</label>
        <div class="layui-input-inline" style="width:180px;">
          <input type="text" name="reason" class="layui-input" placeholder="封禁原因">
        </div>
      </div>
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">过期时间</label>
        <div class="layui-input-inline" style="width:140px;">
          <select name="duration">
            <option value="0">永久</option>
            <option value="3600">1 小时</option>
            <option value="86400">1 天</option>
            <option value="604800">7 天</option>
            <option value="2592000">30 天</option>
          </select>
        </div>
      </div>
      <div class="layui-inline">
        <button type="button" class="layui-btn layui-btn-sm" lay-submit lay-filter="submitIp">添加</button>
      </div>
    </form>
  </div>

  <div class="layui-card-body">
    <table id="ipTable" lay-filter="ipTable"></table>
  </div>
</div>

<script type="text/html" id="ipBarTpl">
  <button class="layui-btn layui-btn-sm layui-btn-danger" lay-event="delete">删除</button>
</script>

<script>
layui.use(['form', 'layer', 'table'], function(){
  var $ = layui.$, layer = layui.layer, form = layui.form, table = layui.table;

  function formatTime(ts){if(!ts)return'-';var d=new Date(ts*1000),p=function(n){return n<10?'0'+n:n};return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());}

  table.render({
    elem: '#ipTable',
    id: 'ipTable',
    url: '/admin/api/ip-blacklist',
    page: false,
    cols: [[
      {field:'id', title:'ID', width:80, sort:true},
      {field:'ip', title:'IP 地址', minWidth:150, templet:function(d){return '<code>'+layui.util.escape(d.ip)+'</code>';}},
      {field:'reason', title:'原因', minWidth:150, templet:function(d){return d.reason||'-';}},
      {field:'expire_at', title:'过期时间', width:160, templet:function(d){return d.expire_at?formatTime(d.expire_at):'永久';}},
      {field:'created_at', title:'添加时间', width:160, templet:function(d){return formatTime(d.created_at);}},
      {title:'操作', width:100, align:'center', toolbar:'#ipBarTpl'}
    ]],
    text: {none: '暂无记录'}
  });

  $('#btnAddIp').on('click', function(){ $('#addIpPanel').toggle(); });

  form.on('submit(submitIp)', function(data){
    var field = data.field;
    if(!field.ip || !field.ip.trim()){ layer.msg('请输入 IP 地址', {icon:2}); return false; }
    $.ajax({
      url: '/admin/ip-blacklist/create',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(field),
      success: function(res){
        if(res.code===0||res.success){ layer.msg('添加成功', {icon:1}); table.reload('ipTable'); }
        else { layer.msg(res.msg||res.message||'操作失败', {icon:2}); }
      },
      error: function(){ layer.msg('请求失败', {icon:2}); }
    });
    return false;
  });

  table.on('tool(ipTable)', function(obj){
    var data = obj.data;
    if(obj.event === 'delete'){
      layer.confirm('确定要删除该 IP 封禁记录吗？', {icon:3, title:'确认删除'}, function(index){
        $.ajax({
          url: '/admin/ip-blacklist/delete',
          type: 'POST',
          contentType: 'application/json',
          data: JSON.stringify({id: data.id}),
          success: function(res){
            if(res.code===0||res.success){ layer.msg('删除成功', {icon:1}); table.reload('ipTable'); }
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
