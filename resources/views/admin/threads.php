<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
  <div class="layui-card-header">帖子管理</div>
  <div class="layui-card-body">

    <!-- 搜索表单 -->
    <form class="layui-form" lay-filter="threadSearch" style="margin-bottom:16px;">
      <div class="layui-form-item" style="margin-bottom:0;">
        <div class="layui-inline">
          <label class="layui-form-label" style="width:70px;">标题</label>
          <div class="layui-input-inline" style="width:150px;">
            <input type="text" name="search" placeholder="搜索标题" class="layui-input">
          </div>
        </div>
        <div class="layui-inline">
          <label class="layui-form-label" style="width:70px;">用户名</label>
          <div class="layui-input-inline" style="width:120px;">
            <input type="text" name="username" placeholder="用户名" class="layui-input">
          </div>
        </div>
        <div class="layui-inline">
          <label class="layui-form-label" style="width:50px;">IP</label>
          <div class="layui-input-inline" style="width:130px;">
            <input type="text" name="ip" placeholder="IP地址" class="layui-input">
          </div>
        </div>
        <div class="layui-inline">
          <label class="layui-form-label" style="width:70px;">板块</label>
          <div class="layui-input-inline" style="width:140px;">
            <select name="forum_id" lay-filter="forumSelect">
              <option value="0">全部板块</option>
              <?php foreach ($forums as $f): ?>
              <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="layui-inline">
          <label class="layui-form-label" style="width:70px;">状态</label>
          <div class="layui-input-inline" style="width:120px;">
            <select name="status" lay-filter="statusSelect">
              <option value="">全部状态</option>
              <option value="top">置顶</option>
              <option value="highlight">精华</option>
              <option value="locked">锁定</option>
            </select>
          </div>
        </div>
        <div class="layui-inline">
          <label class="layui-form-label" style="width:70px;">开始日期</label>
          <div class="layui-input-inline" style="width:140px;">
            <input type="text" name="date_from" id="dateFrom" placeholder="开始日期" class="layui-input" autocomplete="off">
          </div>
        </div>
        <div class="layui-inline">
          <label class="layui-form-label" style="width:70px;">结束日期</label>
          <div class="layui-input-inline" style="width:140px;">
            <input type="text" name="date_to" id="dateTo" placeholder="结束日期" class="layui-input" autocomplete="off">
          </div>
        </div>
        <div class="layui-inline">
          <button class="layui-btn" lay-submit lay-filter="doSearch">搜索</button>
          <button type="reset" class="layui-btn layui-btn-primary" id="btnReset">清除</button>
        </div>
      </div>
    </form>

    <table id="threadTable" lay-filter="threadTable"></table>
  </div>
</div>

<!-- 表格工具栏 -->
<script type="text/html" id="toolbarTpl">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-danger layui-btn-sm" lay-event="batchDelete">批量删除</button>
    <button class="layui-btn layui-btn-sm" lay-event="batchLock">批量锁定</button>
    <button class="layui-btn layui-btn-primary layui-btn-sm" lay-event="batchUnlock">批量解锁</button>
    <button class="layui-btn layui-btn-warm layui-btn-sm" lay-event="batchTop1">板块置顶</button>
    <button class="layui-btn layui-btn-warm layui-btn-sm" lay-event="batchTop2">全局置顶</button>
    <button class="layui-btn layui-btn-primary layui-btn-sm" lay-event="batchTop0">取消置顶</button>
    <button class="layui-btn layui-btn-normal layui-btn-sm" lay-event="batchHighlight">批量加精</button>
    <button class="layui-btn layui-btn-primary layui-btn-sm" lay-event="batchUnhighlight">取消加精</button>
    <button class="layui-btn layui-btn-sm" lay-event="batchMove">批量移动</button>
  </div>
</script>
<script type="text/html" id="rowBarTpl">
  <div class="layui-btn-group">
    <button class="layui-btn layui-btn-xs" lay-event="highlight">{{# if(d.is_highlight){ }}取消精{{# } else { }}加精{{# } }}</button>
    <button class="layui-btn layui-btn-normal layui-btn-xs" lay-event="lock">{{# if(d.is_locked){ }}解锁{{# } else { }}锁定{{# } }}</button>
    <button class="layui-btn layui-btn-danger layui-btn-xs" lay-event="delete">删除</button>
  </div>
</script>
<script type="text/html" id="statusTpl">
  {{# if(parseInt(d.is_top) === 2){ }}<span class="layui-badge layui-bg-red">全局顶</span> {{# } }}
  {{# if(parseInt(d.is_top) === 1){ }}<span class="layui-badge layui-bg-orange">置顶</span> {{# } }}
  {{# if(d.is_highlight){ }}<span class="layui-badge layui-bg-green">精华</span> {{# } }}
  {{# if(d.is_locked){ }}<span class="layui-badge layui-bg-gray">锁定</span> {{# } }}
  {{# if(!parseInt(d.is_top) && !d.is_highlight && !d.is_locked){ }}<span style="color:#999;">-</span>{{# } }}
</script>
<script type="text/html" id="titleTpl"><a href="/thread/{{d.id}}" target="_blank" style="color:#1E9FFF;">{{_esc(d.title)}}</a></script>
<script type="text/html" id="topTpl">
  <select lay-filter="topSelect" data-id="{{d.id}}">
    <option value="0" {{parseInt(d.is_top)===0?'selected':''}}>普通</option>
    <option value="1" {{parseInt(d.is_top)===1?'selected':''}}>板块顶</option>
    <option value="2" {{parseInt(d.is_top)===2?'selected':''}}>全局顶</option>
  </select>
</script>

<div id="moveModalContent" style="display:none;">
  <div style="padding:20px;">
    <form class="layui-form" lay-filter="moveForm">
      <div class="layui-form-item">
        <label class="layui-form-label">目标板块</label>
        <div class="layui-input-block">
          <select name="target_forum_id" lay-verify="required">
            <option value="">请选择...</option>
            <?php foreach ($forums as $f): ?>
            <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
function _esc(s){if(!s)return '';var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
layui.use(['table', 'form', 'layer', 'laydate'], function(){
  var table = layui.table, form = layui.form, layer = layui.layer, laydate = layui.laydate, $ = layui.$;
  var currentWhere = {};

  laydate.render({ elem: '#dateFrom', type: 'date' });
  laydate.render({ elem: '#dateTo', type: 'date' });

  function fmtTime(ts) {
    if (!ts) return '-';
    var d = new Date(ts * 1000), p = function(n){ return n < 10 ? '0'+n : n; };
    return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());
  }

  table.render({
    elem: '#threadTable', id: 'threadTable', url: '/admin/api/threads',
    toolbar: '#toolbarTpl', defaultToolbar: [], page: true, limit: 20, limits: [10,20,30,50],
    autoSort: false,
    cols: [[
      {type:'checkbox', fixed:'left'},
      {field:'id', title:'ID', width:80, sort:true},
      {field:'title', title:'标题', minWidth:200, templet:'#titleTpl'},
      {field:'forum_name', title:'板块', width:120, templet:function(d){return _esc(d.forum_name)||'-';}},
      {field:'username', title:'作者', width:100, templet:function(d){return _esc(d.nickname||d.username)||'-';}},
      {field:'views', title:'浏览', width:80, sort:true},
      {field:'reply_count', title:'回复', width:80, sort:true},
      {field:'status', title:'状态', width:180, templet:'#statusTpl'},
      {field:'created_at', title:'发布时间', width:160, sort:true, templet:function(d){return fmtTime(d.created_at);}},
      {field:'top_action', title:'置顶', width:120, templet:'#topTpl'},
      {fixed:'right', title:'操作', width:200, toolbar:'#rowBarTpl'}
    ]],
    parseData: function(res){ return {code:res.code, msg:res.msg, count:res.count, data:res.data}; },
    done: function(res, curr, count){
      // 延迟渲染以确保 DOM 已更新
      setTimeout(function(){
        form.render('select');
      }, 0);
    }
  });

  form.on('submit(doSearch)', function(data){
    currentWhere = {};
    if(data.field.search) currentWhere.search = data.field.search;
    if(data.field.username) currentWhere.username = data.field.username;
    if(data.field.ip) currentWhere.ip = data.field.ip;
    if(data.field.forum_id && data.field.forum_id!=='0') currentWhere.forum_id = data.field.forum_id;
    if(data.field.status) currentWhere.status = data.field.status;
    if(data.field.date_from) currentWhere.date_from = data.field.date_from;
    if(data.field.date_to) currentWhere.date_to = data.field.date_to;
    table.reload('threadTable', {where:currentWhere, page:{curr:1}});
    return false;
  });
  $('#btnReset').on('click', function(){ currentWhere = {}; table.reload('threadTable', {where:{}, page:{curr:1}}); });

  // 服务端排序
  table.on('sort(threadTable)', function(obj){
    table.reload('threadTable', {
      initSort: obj,
      where: $.extend({}, currentWhere, {sort: obj.field, dir: obj.type})
    });
  });

  form.on('select(topSelect)', function(data){
    var id = parseInt($(data.elem).attr('data-id')), level = parseInt(data.value);
    $.ajax({ url:'/admin/threads/toggle-top', type:'POST', contentType:'application/json',
      data: JSON.stringify({thread_id:id, level:level}),
      success: function(res){
        if(res.code===0||res.success){ layer.msg('操作成功',{icon:1}); table.reload('threadTable'); }
        else { layer.msg(res.msg||res.message||'操作失败',{icon:2}); }
      }, error: function(){ layer.msg('请求失败',{icon:2}); }
    });
  });

  table.on('toolbar(threadTable)', function(obj){
    var cs = table.checkStatus('threadTable'), data = cs.data;
    if(!data.length){ layer.msg('请先选择帖子',{icon:0}); return; }
    var ids = data.map(function(d){return d.id;}), ev = obj.event;
    if(ev==='batchDelete'){ layer.confirm('确定批量删除 '+ids.length+' 篇帖子？',{icon:3},function(i){layer.close(i);batchReq({ids:ids,action:'delete'});}); }
    else if(ev==='batchLock') batchReq({ids:ids,action:'lock'});
    else if(ev==='batchUnlock') batchReq({ids:ids,action:'unlock'});
    else if(ev==='batchTop0') batchReq({ids:ids,action:'top',level:0});
    else if(ev==='batchTop1') batchReq({ids:ids,action:'top',level:1});
    else if(ev==='batchTop2') batchReq({ids:ids,action:'top',level:2});
    else if(ev==='batchHighlight') batchReq({ids:ids,action:'highlight'});
    else if(ev==='batchUnhighlight') batchReq({ids:ids,action:'unhighlight'});
    else if(ev==='batchMove') openMoveModal(ids);
  });

  table.on('tool(threadTable)', function(obj){
    var d = obj.data, ev = obj.event;
    if(ev==='highlight'){
      $.ajax({url:'/admin/threads/toggle-highlight',type:'POST',contentType:'application/json',data:JSON.stringify({thread_id:d.id}),
        success:function(r){if(r.code===0||r.success){layer.msg('操作成功',{icon:1});table.reload('threadTable');}else{layer.msg(r.msg||r.message||'失败',{icon:2});}},
        error:function(){layer.msg('请求失败',{icon:2});}});
    } else if(ev==='lock'){
      var act = d.is_locked?'unlock':'lock';
      $.ajax({url:'/admin/threads/batch',type:'POST',contentType:'application/json',data:JSON.stringify({ids:[d.id],action:act}),
        success:function(r){if(r.code===0||r.success){layer.msg('操作成功',{icon:1});table.reload('threadTable');}else{layer.msg(r.msg||r.message||'失败',{icon:2});}},
        error:function(){layer.msg('请求失败',{icon:2});}});
    } else if(ev==='delete'){
      layer.confirm('确定删除？',{icon:3},function(i){layer.close(i);
        $.ajax({url:'/admin/threads/delete',type:'POST',contentType:'application/json',data:JSON.stringify({thread_id:d.id}),
          success:function(r){if(r.code===0||r.success){layer.msg('已删除',{icon:1});table.reload('threadTable');}else{layer.msg(r.msg||r.message||'失败',{icon:2});}},
          error:function(){layer.msg('请求失败',{icon:2});}});
      });
    }
  });

  function batchReq(params){
    var l=layer.load(1);
    $.ajax({url:'/admin/threads/batch',type:'POST',contentType:'application/json',data:JSON.stringify(params),
      success:function(r){layer.close(l);if(r.code===0||r.success){layer.msg(r.msg||r.message||'操作成功',{icon:1});table.reload('threadTable');}else{layer.msg(r.msg||r.message||'失败',{icon:2});}},
      error:function(){layer.close(l);layer.msg('请求失败',{icon:2});}});
  }

  var moveIds=[];
  function openMoveModal(ids){
    moveIds=ids;
    layer.open({type:1,title:'移动帖子到板块',area:['420px','220px'],content:$('#moveModalContent').html(),btn:['确认移动','取消'],
      success:function(o){form.render('select','moveForm');},
      yes:function(idx,o){
        var v=o.find('select[name="target_forum_id"]').val();
        if(!v){layer.msg('请选择目标板块',{icon:2});return;}
        var l=layer.load(1);
        $.ajax({url:'/admin/threads/batch',type:'POST',contentType:'application/json',data:JSON.stringify({ids:moveIds,action:'move',target_forum_id:parseInt(v)}),
          success:function(r){layer.close(l);if(r.code===0||r.success){layer.msg('移动成功',{icon:1});layer.close(idx);table.reload('threadTable');}else{layer.msg(r.msg||r.message||'失败',{icon:2});}},
          error:function(){layer.close(l);layer.msg('请求失败',{icon:2});}});
      }
    });
  }
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
