<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
  <div class="layui-card-header" style="overflow:visible;height:auto;min-height:42px;line-height:42px;">
    通知管理
    <button class="layui-btn layui-btn-sm" style="float:right;margin-top:4px;" id="btnSendNotify">发送系统通知</button>
  </div>
  <div class="layui-card-body">
    <!-- 搜索 -->
    <div style="margin-bottom:15px;">
      <form class="layui-form" lay-filter="searchNotifyForm" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div class="layui-inline">
          <label class="layui-form-label">关键词</label>
          <div class="layui-input-inline" style="width:180px;">
            <input type="text" name="search" placeholder="搜索标题或内容" class="layui-input">
          </div>
        </div>
        <div class="layui-inline">
          <label class="layui-form-label">类型</label>
          <div class="layui-input-inline" style="width:120px;">
            <select name="type" lay-ignore style="height:38px;border:1px solid #e6e6e6;border-radius:2px;padding:0 10px;width:100%;">
              <option value="">全部</option>
              <option value="system">系统</option>
              <option value="reply">回复</option>
              <option value="mention">提及</option>
            </select>
          </div>
        </div>
        <div class="layui-inline">
          <button type="button" class="layui-btn layui-btn-sm" id="btnSearchNotify"><i class="layui-icon layui-icon-search"></i></button>
        </div>
        <div class="layui-inline">
          <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="btnClearNotify">清除</button>
        </div>
      </form>
    </div>

    <table id="notifyTable" lay-filter="notifyTable"></table>
  </div>
</div>

<!-- 发送通知表单模板 -->
<script type="text/html" id="sendNotifyTpl">
<form class="layui-form" lay-filter="sendNotifyForm" style="padding:20px;">
  <div class="layui-form-item">
    <label class="layui-form-label">标题 <span style="color:red;">*</span></label>
    <div class="layui-input-block"><input type="text" name="title" lay-verify="required" class="layui-input" placeholder="通知标题"></div>
  </div>
  <div class="layui-form-item layui-form-text">
    <label class="layui-form-label">内容</label>
    <div class="layui-input-block"><textarea name="content" class="layui-textarea" rows="3" placeholder="通知内容（可选）"></textarea></div>
  </div>
  <div class="layui-form-item">
    <label class="layui-form-label">发送目标</label>
    <div class="layui-input-block">
      <input type="radio" name="target" value="all" title="全部用户" checked lay-filter="notifyTarget">
      <input type="radio" name="target" value="user" title="指定用户" lay-filter="notifyTarget">
      <input type="radio" name="target" value="group" title="指定用户组" lay-filter="notifyTarget">
    </div>
  </div>
  <div class="layui-form-item" id="targetUserRow" style="display:none;">
    <label class="layui-form-label">用户名</label>
    <div class="layui-input-block"><input type="text" name="username" class="layui-input" placeholder="目标用户名"></div>
  </div>
  <div class="layui-form-item" id="targetGroupRow" style="display:none;">
    <label class="layui-form-label">用户组</label>
    <div class="layui-input-block">
      <select name="group_id"><option value="1">普通用户</option><option value="2">版主</option><option value="3">管理员</option></select>
    </div>
  </div>
  <div class="layui-form-item" style="text-align:right;margin-bottom:0;">
    <button type="button" class="layui-btn" lay-submit lay-filter="doSendNotify">发送</button>
  </div>
</form>
</script>

<script type="text/html" id="notifyBarTpl">
  <button class="layui-btn layui-btn-sm layui-btn-danger" lay-event="delete">删除</button>
</script>

<script>
layui.use(['form', 'layer', 'table'], function(){
  var $ = layui.$, layer = layui.layer, form = layui.form, table = layui.table;
  var sendLayerIdx = -1;

  function formatTime(ts){if(!ts)return'-';var d=new Date(ts*1000),p=function(n){return n<10?'0'+n:n};return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());}

  table.render({
    elem: '#notifyTable',
    id: 'notifyTable',
    url: '/admin/api/notifications',
    page: true,
    limit: 20,
    cols: [[
      {field:'id', title:'ID', width:80, sort:true},
      {field:'title', title:'标题', minWidth:180, templet:function(d){
        var t = layui.util.escape(d.title||'');
        return '<span style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;">'+t+'</span>';
      }},
      {field:'recipient_username', title:'接收用户', width:120, templet:function(d){return d.recipient_username||'-';}},
      {field:'sender_username', title:'发送者', width:120, templet:function(d){return d.sender_username||'系统';}},
      {field:'type', title:'类型', width:90, templet:function(d){
        var m = {system:'<span class="layui-badge layui-bg-blue">系统</span>',reply:'<span class="layui-badge layui-bg-green">回复</span>',mention:'<span class="layui-badge layui-bg-orange">提及</span>'};
        return m[d.type]||'<span class="layui-badge layui-bg-gray">'+(d.type||'未知')+'</span>';
      }},
      {field:'is_read', title:'已读', width:70, templet:function(d){
        return d.is_read ? '<span style="color:#009688;">已读</span>' : '<span style="color:#999;">未读</span>';
      }},
      {field:'created_at', title:'时间', width:160, templet:function(d){return '<span style="color:#999;font-size:12px;">'+formatTime(d.created_at)+'</span>';}},
      {title:'操作', width:100, align:'center', toolbar:'#notifyBarTpl'}
    ]],
    text: {none: '暂无通知记录'}
  });

  // 搜索
  $('#btnSearchNotify').on('click', function(){
    var searchVal = $('input[name="search"]').val();
    var typeVal = $('select[name="type"]').val();
    table.reload('notifyTable', {
      where: {search: searchVal, type: typeVal},
      page: {curr: 1}
    });
  });

  // 清除搜索
  $('#btnClearNotify').on('click', function(){
    $('input[name="search"]').val('');
    $('select[name="type"]').val('');
    table.reload('notifyTable', {
      where: {search: '', type: ''},
      page: {curr: 1}
    });
  });

  // 发送通知弹窗
  $('#btnSendNotify').on('click', function(){
    sendLayerIdx = layer.open({
      type: 1, title: '发送系统通知', area: ['520px', '460px'],
      content: $('#sendNotifyTpl').html(),
      success: function(layero){
        form.render(null, 'sendNotifyForm');
        form.on('radio(notifyTarget)', function(data){
          layero.find('#targetUserRow').toggle(data.value === 'user');
          layero.find('#targetGroupRow').toggle(data.value === 'group');
        });
      }
    });
  });

  form.on('submit(doSendNotify)', function(data){
    var field = data.field;
    if(!field.title || !field.title.trim()){ layer.msg('请输入通知标题', {icon:2}); return false; }
    if(field.target === 'user' && !field.username.trim()){ layer.msg('请输入目标用户名', {icon:2}); return false; }
    $.ajax({
      url: '/admin/notifications/send', type: 'POST',
      contentType: 'application/json', data: JSON.stringify(field),
      success: function(res){
        if(res.code===0||res.success){ layer.msg('发送成功', {icon:1}); layer.close(sendLayerIdx); table.reload('notifyTable'); }
        else { layer.msg(res.msg||res.message||'发送失败', {icon:2}); }
      },
      error: function(){ layer.msg('请求失败', {icon:2}); }
    });
    return false;
  });

  // 行工具栏事件
  table.on('tool(notifyTable)', function(obj){
    var data = obj.data;
    if(obj.event === 'delete'){
      layer.confirm('确定要删除该通知吗？', {icon:3, title:'确认'}, function(index){
        $.ajax({
          url: '/admin/notifications/delete', type: 'POST',
          contentType: 'application/json', data: JSON.stringify({id: data.id}),
          success: function(res){
            if(res.code===0||res.success){ layer.msg('删除成功', {icon:1}); table.reload('notifyTable'); }
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
