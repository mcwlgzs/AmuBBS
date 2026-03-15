<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
  <div class="layui-card-header">
    友情链接
    <button class="layui-btn layui-btn-sm" style="float:right;margin-top:4px;" id="btnAddLink">添加链接</button>
  </div>

  <!-- 添加表单 -->
  <div class="layui-card-body" id="addLinkPanel" style="display:none;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
    <form class="layui-form" lay-filter="addLinkForm" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">名称</label>
        <div class="layui-input-inline" style="width:150px;">
          <input type="text" name="name" class="layui-input" lay-verify="required" placeholder="链接名称">
        </div>
      </div>
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">链接</label>
        <div class="layui-input-inline" style="width:220px;">
          <input type="text" name="url" class="layui-input" lay-verify="required|url" placeholder="https://">
        </div>
      </div>
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">Logo</label>
        <div class="layui-input-inline" style="width:180px;">
          <input type="text" name="logo" class="layui-input" placeholder="图片URL（可选）">
        </div>
      </div>
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">排序</label>
        <div class="layui-input-inline" style="width:80px;">
          <input type="number" name="sort_order" class="layui-input" value="0">
        </div>
      </div>
      <div class="layui-inline">
        <button type="button" class="layui-btn layui-btn-sm" lay-submit lay-filter="submitLink">添加</button>
      </div>
    </form>
  </div>

  <div class="layui-card-body">
    <table id="linksTable" lay-filter="linksTable"></table>
  </div>
</div>

<script type="text/html" id="linksBarTpl">
  <button class="layui-btn layui-btn-sm layui-btn-danger" lay-event="delete">删除</button>
</script>

<script>
layui.use(['form', 'layer', 'table'], function(){
  var $ = layui.$, layer = layui.layer, form = layui.form, table = layui.table;

  function formatTime(ts){if(!ts)return'-';var d=new Date(ts*1000),p=function(n){return n<10?'0'+n:n};return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());}

  table.render({
    elem: '#linksTable',
    id: 'linksTable',
    url: '/admin/api/friend-links',
    page: false,
    cols: [[
      {field:'id', title:'ID', width:80, sort:true},
      {field:'name', title:'名称', minWidth:120},
      {field:'url', title:'链接', minWidth:200, templet:function(d){
        var u = layui.util.escape(d.url||'');
        var short = u.length>50 ? u.substring(0,50)+'...' : u;
        return '<a href="'+u+'" target="_blank" rel="noopener" style="color:#1E9FFF;">'+short+'</a>';
      }},
      {field:'logo', title:'Logo', width:100, templet:function(d){
        if(!d.logo) return '-';
        return '<img src="'+layui.util.escape(d.logo)+'" style="max-height:28px;max-width:80px;" onerror="this.style.display=\'none\'">';
      }},
      {field:'sort_order', title:'排序', width:80},
      {field:'status', title:'状态', width:80, templet:function(d){
        if(d.status===0) return '<span style="color:#999;">禁用</span>';
        return '<span style="color:#009688;">启用</span>';
      }},
      {field:'created_at', title:'创建时间', width:160, templet:function(d){return formatTime(d.created_at);}},
      {title:'操作', width:100, align:'center', toolbar:'#linksBarTpl'}
    ]],
    text: {none: '暂无友情链接'}
  });

  $('#btnAddLink').on('click', function(){ $('#addLinkPanel').toggle(); });

  form.on('submit(submitLink)', function(data){
    var field = data.field;
    if(!field.name || !field.name.trim()){ layer.msg('请输入链接名称', {icon:2}); return false; }
    if(!field.url || !field.url.trim()){ layer.msg('请输入链接地址', {icon:2}); return false; }
    $.ajax({
      url: '/admin/friend-links/create',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(field),
      success: function(res){
        if(res.code===0||res.success){ layer.msg('添加成功', {icon:1}); table.reload('linksTable'); }
        else { layer.msg(res.msg||res.message||'操作失败', {icon:2}); }
      },
      error: function(){ layer.msg('请求失败', {icon:2}); }
    });
    return false;
  });

  table.on('tool(linksTable)', function(obj){
    var data = obj.data;
    if(obj.event === 'delete'){
      layer.confirm('确定要删除该友情链接吗？', {icon:3, title:'确认删除'}, function(index){
        $.ajax({
          url: '/admin/friend-links/delete',
          type: 'POST',
          contentType: 'application/json',
          data: JSON.stringify({id: data.id}),
          success: function(res){
            if(res.code===0||res.success){ layer.msg('删除成功', {icon:1}); table.reload('linksTable'); }
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
