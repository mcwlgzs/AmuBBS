<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
  <div class="layui-card-header">
    <span>用户管理</span>
  </div>
  <div class="layui-card-body">

    <!-- 搜索表单 -->
    <form class="layui-form" lay-filter="userSearch" style="margin-bottom:15px;">
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:9px 10px;">用户名/昵称/邮箱</label>
        <div class="layui-input-inline" style="width:160px;">
          <input type="text" name="search" placeholder="搜索" class="layui-input">
        </div>
      </div>
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:9px 10px;">UID</label>
        <div class="layui-input-inline" style="width:80px;">
          <input type="number" name="uid" placeholder="用户ID" class="layui-input">
        </div>
      </div>
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:9px 10px;">用户组</label>
        <div class="layui-input-inline" style="width:120px;">
          <select name="group_id">
            <option value="">全部</option>
            <?php foreach ($groups as $g): ?>
            <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:9px 10px;">IP</label>
        <div class="layui-input-inline" style="width:130px;">
          <input type="text" name="ip" placeholder="登录/注册IP" class="layui-input">
        </div>
      </div>
      <div class="layui-inline">
        <button class="layui-btn layui-btn-sm" lay-submit lay-filter="doSearch">搜索</button>
        <button type="reset" class="layui-btn layui-btn-sm layui-btn-primary" id="btnReset">清除</button>
      </div>
    </form>

    <table id="userTable" lay-filter="userTable"></table>
  </div>
</div>

<!-- 表格工具栏 -->
<script type="text/html" id="userToolbarTpl">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="addUser"><i class="layui-icon layui-icon-add-1"></i> 添加用户</button>
    <button class="layui-btn layui-btn-warm layui-btn-sm" lay-event="batchBan"><i class="layui-icon layui-icon-close-fill"></i> 批量封禁</button>
    <button class="layui-btn layui-btn-normal layui-btn-sm" lay-event="batchUnban"><i class="layui-icon layui-icon-ok-circle"></i> 批量解封</button>
    <button class="layui-btn layui-btn-sm" lay-event="batchGroup"><i class="layui-icon layui-icon-group"></i> 批量改组</button>
    <button class="layui-btn layui-btn-danger layui-btn-sm" lay-event="batchDelete"><i class="layui-icon layui-icon-delete"></i> 批量删除</button>
  </div>
</script>

<script>
var PAGE_GROUPS = <?= json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var ADMIN_GROUP_ID = <?= (int)\App\Services\PermissionSvc::ADMIN_GROUP_ID ?>;
var CURRENT_USER_ID = <?= (int)$_SESSION['user_id'] ?>;
</script>

<script>
layui.use(['table', 'layer', 'form'], function(){
  var table = layui.table, layer = layui.layer, form = layui.form, $ = layui.$;
  var currentWhere = {};

  function fmtDate(ts){ if(!ts) return '-'; var d=new Date(ts*1000),p=function(n){return n<10?'0'+n:n;}; return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); }

  // API 驱动表格
  table.render({
    elem: '#userTable', id: 'userTable', url: '/admin/api/users',
    toolbar: '#userToolbarTpl', defaultToolbar: [], page: true, limit: 20, limits: [10,20,30,50], skin: 'line', even: true, size: 'sm',
    autoSort: false,
    cols: [[
      {type:'checkbox', fixed:'left', width:50},
      {field:'id', title:'ID', width:80, sort:true},
      {field:'username', title:'用户名', width:130, templet:function(d){return layui.util.escape(d.username);}},
      {field:'nickname', title:'昵称', width:120, templet:function(d){
        return d.nickname ? layui.util.escape(d.nickname) : '<span style="color:#ccc;">未设置</span>';
      }},
      {field:'email', title:'邮箱', minWidth:180, templet:function(d){return layui.util.escape(d.email);}},
      {field:'group_name', title:'用户组', width:110, templet:function(d){
        if(d.group_id==ADMIN_GROUP_ID) return '<span class="layui-badge">管理员</span>';
        if(d.group_id==2) return '<span class="layui-badge layui-bg-blue">版主</span>';
        return '<span class="layui-badge layui-bg-gray">'+layui.util.escape(d.group_name||'普通用户')+'</span>';
      }},
      {field:'credits', title:'积分', width:90, sort:true, templet:function(d){return (d.credits||0).toLocaleString();}},
      {field:'thread_count', title:'帖子/回复', width:110, templet:function(d){return (d.thread_count||0).toLocaleString()+' / '+(d.post_count||0).toLocaleString();}},
      {field:'created_at', title:'注册时间', width:120, sort:true, templet:function(d){return fmtDate(d.created_at);}},
      {title:'操作', width:90, fixed:'right', align:'center', templet:function(d){
        return '<button class="layui-btn layui-btn-xs" lay-event="manage">管理</button>';
      }}
    ]],
    parseData: function(res){ return {code:res.code, msg:res.msg, count:res.count, data:res.data}; },
    done: function(){ form.render('select'); }
  });

  // 搜索
  form.on('submit(doSearch)', function(data){
    currentWhere = {};
    if(data.field.search) currentWhere.search = data.field.search;
    if(data.field.uid) currentWhere.uid = data.field.uid;
    if(data.field.group_id) currentWhere.group_id = data.field.group_id;
    if(data.field.ip) currentWhere.ip = data.field.ip;
    table.reload('userTable', {where:currentWhere, page:{curr:1}});
    return false;
  });
  $('#btnReset').on('click', function(){ currentWhere = {}; table.reload('userTable', {where:{}, page:{curr:1}}); });

  // 服务端排序
  table.on('sort(userTable)', function(obj){
    table.reload('userTable', {
      initSort: obj,
      where: $.extend({}, currentWhere, {sort: obj.field, dir: obj.type})
    });
  });

  // POST helper
  function postJson(url, data, onSuccess) {
    var l = layer.load(1);
    $.ajax({ url:url, method:'POST', contentType:'application/json', data:JSON.stringify(data),
      success:function(res){ layer.close(l);
        if(res.code===0||res.success){ layer.msg(res.msg||res.message||'操作成功',{icon:1},function(){ if(onSuccess) onSuccess(res); else table.reload('userTable'); }); }
        else { layer.msg(res.msg||res.message||'操作失败',{icon:2}); }
      }, error:function(){ layer.close(l); layer.msg('请求失败',{icon:2}); }
    });
  }

  // 管理按钮
  table.on('tool(userTable)', function(obj){
    if(obj.event==='manage') openManagePanel(obj.data);
  });

  var manageIdx = -1;

  function openManagePanel(user){
    var l = layer.load(1);
    $.ajax({url:'/admin/users/update', method:'POST', contentType:'application/json',
      data: JSON.stringify({user_id:user.id, action:'get_detail'}),
      success: function(res){
        layer.close(l);
        if(!res.data||!res.data.user){ layer.msg(res.msg||'获取失败',{icon:2}); return; }
        var u = res.data.user;
        var isSelf = (u.id == CURRENT_USER_ID);
        var esc = layui.util.escape;
        var displayName = esc(u.nickname || u.username);

        // 用户组选项
        var groupRadios = '';
        PAGE_GROUPS.forEach(function(g){
          var chk = (u.group_id == g.id) ? ' checked' : '';
          groupRadios += '<input type="radio" name="mGroupId" value="'+g.id+'" title="'+esc(g.name)+'"'+chk+'>';
        });

        var html = '<form class="layui-form" lay-filter="userManageForm"><div class="layui-tab layui-tab-brief" lay-filter="userManageTab" style="margin:0;">'
          +'<ul class="layui-tab-title">'
          +'  <li class="layui-this">基本资料</li>'
          +'  <li>安全设置</li>'
          +'  <li>用户组</li>'
          + (isSelf ? '' : '  <li>危险操作</li>')
          +'</ul>'
          +'<div class="layui-tab-content" style="padding:15px 20px;">'
          // Tab 1: 基本资料
          +'<div class="layui-tab-item layui-show">'
          +'  <div class="layui-form-item"><label class="layui-form-label">用户名</label><div class="layui-input-block"><input type="text" id="mUsername" class="layui-input" value="'+esc(u.username)+'"></div></div>'
          +'  <div class="layui-form-item"><label class="layui-form-label">昵称</label><div class="layui-input-block"><input type="text" id="mNickname" class="layui-input" value="'+esc(u.nickname||'')+'" placeholder="可选"></div></div>'
          +'  <div class="layui-form-item"><label class="layui-form-label">邮箱</label><div class="layui-input-block"><input type="email" id="mEmail" class="layui-input" value="'+esc(u.email)+'"></div></div>'
          +'  <div class="layui-form-item"><label class="layui-form-label">签名</label><div class="layui-input-block"><input type="text" id="mSignature" class="layui-input" value="'+esc(u.signature||'')+'"></div></div>'
          +'  <div class="layui-form-item"><label class="layui-form-label">昵称颜色</label><div class="layui-input-block"><div style="display:flex;align-items:center;gap:10px;"><input type="color" id="mNicknameColor" value="'+(u.nickname_color||'#000000')+'" style="width:40px;height:32px;padding:2px;border:1px solid #e6e6e6;border-radius:4px;cursor:pointer;"><input type="text" id="mNicknameColorText" class="layui-input" value="'+(u.nickname_color||'')+'" placeholder="如 #FF0000，留空为默认" style="width:140px;"><button type="button" class="layui-btn layui-btn-xs layui-btn-primary" id="mColorClear">清除</button></div></div></div>'
          +'  <div class="layui-form-item"><label class="layui-form-label">积分</label><div class="layui-input-block"><div class="layui-input-inline" style="width:120px;"><input type="text" class="layui-input" value="'+(u.credits||0).toLocaleString()+'" disabled></div>'
          +'    <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="mCreditBtn">调整</button></div></div>'
          +'  <div id="mCreditBox" style="display:none;margin:-5px 0 10px 110px;padding:12px;background:#f8f9fa;border-radius:4px;">'
          +'    <div class="layui-form-item" style="margin-bottom:10px;"><label class="layui-form-label" style="width:70px;">变动数量</label><div class="layui-input-inline" style="width:150px;"><input type="number" id="mCreditAmt" class="layui-input" placeholder="正数加/负数减"></div></div>'
          +'    <div class="layui-form-item" style="margin-bottom:10px;"><label class="layui-form-label" style="width:70px;">原因</label><div class="layui-input-inline" style="width:200px;"><input type="text" id="mCreditReason" class="layui-input" value="管理员调整"></div></div>'
          +'    <button type="button" class="layui-btn layui-btn-xs" id="mCreditSubmit">确认调整</button>'
          +'  </div>'
          +'  <div class="layui-form-item" style="text-align:right;margin-bottom:0;"><button type="button" class="layui-btn" id="mSaveProfile">保存资料</button></div>'
          +'</div>'
          // Tab 2: 安全设置
          +'<div class="layui-tab-item">'
          +'  <div style="margin-bottom:15px;color:#666;font-size:13px;"><i class="layui-icon layui-icon-about" style="margin-right:4px;"></i>重置后用户需要重新登录</div>'
          +'  <div class="layui-form-item"><label class="layui-form-label">新密码</label><div class="layui-input-block"><input type="password" id="mNewPwd" class="layui-input" placeholder="至少6位"></div></div>'
          +'  <div class="layui-form-item" style="text-align:right;margin-bottom:0;"><button type="button" class="layui-btn layui-btn-warm" id="mResetPwd">重置密码</button></div>'
          +'</div>'
          // Tab 3: 用户组
          +'<div class="layui-tab-item">'
          +'  <div style="margin-bottom:15px;color:#666;font-size:13px;"><i class="layui-icon layui-icon-about" style="margin-right:4px;"></i>选择用户组后点击保存</div>'
          +'  <div class="layui-form-item"><div class="layui-input-block" style="margin-left:0;">'+groupRadios+'</div></div>'
          +'  <div class="layui-form-item" style="text-align:right;margin-bottom:0;"><button type="button" class="layui-btn" id="mSaveGroup">保存用户组</button></div>'
          +'</div>';

        if(!isSelf){
          html += '<div class="layui-tab-item">'
            +'  <div style="margin-bottom:15px;color:#ff5722;font-size:13px;"><i class="layui-icon layui-icon-about" style="margin-right:4px;"></i>以下操作请谨慎执行</div>'
            +'  <div style="display:flex;gap:10px;flex-wrap:wrap;">'
            +'    <button type="button" class="layui-btn layui-btn-warm" id="mBanBtn"><i class="layui-icon layui-icon-close-fill"></i> 封禁用户</button>'
            +'    <button type="button" class="layui-btn layui-btn-normal" id="mUnbanBtn"><i class="layui-icon layui-icon-ok-circle"></i> 解封用户</button>'
            +'    <button type="button" class="layui-btn layui-btn-danger" id="mDeleteBtn"><i class="layui-icon layui-icon-delete"></i> 删除用户</button>'
            +'  </div>'
            +'</div>';
        }

        html += '</div></div></form>';

        manageIdx = layer.open({
          type:1, title:'管理用户 - '+displayName, area:['560px','480px'],
          content:html, shadeClose:true,
          success: function(layero){
            form.render(null);
            var uid = u.id, uname = esc(u.username);

            // 颜色选择器同步
            $(layero).find('#mNicknameColor').on('input', function(){
              $(layero).find('#mNicknameColorText').val(this.value);
            });
            $(layero).find('#mNicknameColorText').on('input', function(){
              var v = this.value.trim();
              if(/^#[0-9a-fA-F]{6}$/.test(v)) $(layero).find('#mNicknameColor').val(v);
            });
            $(layero).find('#mColorClear').on('click', function(){
              $(layero).find('#mNicknameColorText').val('');
              $(layero).find('#mNicknameColor').val('#000000');
            });

            // 积分调整展开
            $(layero).find('#mCreditBtn').on('click', function(){
              $(layero).find('#mCreditBox').toggle();
            });
            $(layero).find('#mCreditSubmit').on('click', function(){
              var amt = parseInt($(layero).find('#mCreditAmt').val());
              if(isNaN(amt)||amt===0){ layer.msg('请输入有效数量',{icon:2}); return; }
              postJson('/admin/users/update',{user_id:uid,action:'adjust_credits',amount:amt,reason:$(layero).find('#mCreditReason').val()||'管理员调整'},function(){
                layer.close(manageIdx); table.reload('userTable');
              });
            });

            // 保存资料
            $(layero).find('#mSaveProfile').on('click', function(){
              postJson('/admin/users/update',{
                user_id:uid, action:'edit_profile',
                username:$(layero).find('#mUsername').val(),
                nickname:$(layero).find('#mNickname').val(),
                email:$(layero).find('#mEmail').val(),
                signature:$(layero).find('#mSignature').val(),
                nickname_color:$(layero).find('#mNicknameColorText').val().trim()
              }, function(){ layer.close(manageIdx); table.reload('userTable'); });
            });

            // 重置密码
            $(layero).find('#mResetPwd').on('click', function(){
              var pwd = $(layero).find('#mNewPwd').val();
              if(!pwd||pwd.length<6){ layer.msg('密码至少6位',{icon:2}); return; }
              layer.confirm('确定重置密码？', {icon:3}, function(ci){
                layer.close(ci);
                postJson('/admin/users/update',{user_id:uid,action:'reset_password',new_password:pwd},function(){ layer.close(manageIdx); });
              });
            });

            // 保存用户组
            $(layero).find('#mSaveGroup').on('click', function(){
              var gid = $(layero).find('input[name="mGroupId"]:checked').val();
              postJson('/admin/users/update',{user_id:uid,action:'change_group',group_id:parseInt(gid)},function(){ layer.close(manageIdx); table.reload('userTable'); });
            });

            // 危险操作
            $(layero).find('#mBanBtn').on('click', function(){
              layer.confirm('确定封禁 '+uname+'？',{icon:3},function(ci){ layer.close(ci); postJson('/admin/users/update',{user_id:uid,action:'ban'},function(){ layer.close(manageIdx); table.reload('userTable'); }); });
            });
            $(layero).find('#mUnbanBtn').on('click', function(){
              layer.confirm('确定解封 '+uname+'？',{icon:3},function(ci){ layer.close(ci); postJson('/admin/users/update',{user_id:uid,action:'unban'},function(){ layer.close(manageIdx); table.reload('userTable'); }); });
            });
            $(layero).find('#mDeleteBtn').on('click', function(){
              layer.confirm('确定删除 '+uname+'？此操作不可恢复！',{icon:2,title:'危险操作'},function(ci){ layer.close(ci); postJson('/admin/users/update',{user_id:uid,action:'delete'},function(){ layer.close(manageIdx); table.reload('userTable'); }); });
            });
          }
        });
      },
      error: function(){ layer.close(l); layer.msg('请求失败',{icon:2}); }
    });
  }

  function openAddUser(){
    var opts=''; PAGE_GROUPS.forEach(function(g){opts+='<option value="'+g.id+'">'+layui.util.escape(g.name)+'</option>';});
    var html='<div style="padding:20px;">'
      +'<div class="layui-form-item"><label class="layui-form-label">用户名</label><div class="layui-input-block"><input type="text" id="addUsername" class="layui-input"></div></div>'
      +'<div class="layui-form-item"><label class="layui-form-label">昵称</label><div class="layui-input-block"><input type="text" id="addNickname" class="layui-input" placeholder="可选"></div></div>'
      +'<div class="layui-form-item"><label class="layui-form-label">邮箱</label><div class="layui-input-block"><input type="email" id="addEmail" class="layui-input"></div></div>'
      +'<div class="layui-form-item"><label class="layui-form-label">密码</label><div class="layui-input-block"><input type="password" id="addPassword" class="layui-input" placeholder="至少6位"></div></div>'
      +'<div class="layui-form-item"><label class="layui-form-label">用户组</label><div class="layui-input-block"><select id="addGroupId" class="layui-input">'+opts+'</select></div></div>'
      +'<div class="layui-form-item" style="text-align:right;margin-bottom:0;"><button class="layui-btn" id="addSubmitBtn">创建</button><button class="layui-btn layui-btn-primary" id="addCancelBtn">取消</button></div></div>';
    var idx=layer.open({type:1,title:'添加用户',area:['480px'],content:html,shadeClose:true,success:function(){
      $('#addCancelBtn').on('click',function(){layer.close(idx);});
      $('#addSubmitBtn').on('click',function(){
        var d={action:'add_user',username:$('#addUsername').val(),nickname:$('#addNickname').val(),email:$('#addEmail').val(),password:$('#addPassword').val(),group_id:$('#addGroupId').val()};
        if(!d.username||!d.email||!d.password){layer.msg('请填写必填项',{icon:2});return;}
        postJson('/admin/users/update',d,function(){layer.close(idx);table.reload('userTable');});
      });
    }});
  }

  // ========== 批量操作（toolbar 事件） ==========
  function getCheckedIds(){
    var cs = table.checkStatus('userTable');
    return (cs.data||[]).map(function(d){ return d.id; });
  }

  function batchReq(params){
    var l = layer.load(1);
    $.ajax({url:'/admin/users/batch', type:'POST', contentType:'application/json', data:JSON.stringify(params),
      success:function(r){ layer.close(l);
        if(r.code===0||r.success){ layer.msg(r.msg||r.message||'操作成功',{icon:1}); table.reload('userTable'); }
        else { layer.msg(r.msg||r.message||'操作失败',{icon:2}); }
      }, error:function(){ layer.close(l); layer.msg('请求失败',{icon:2}); }
    });
  }

  table.on('toolbar(userTable)', function(obj){
    var ev = obj.event;

    if(ev === 'addUser'){
      openAddUser();
      return;
    }

    var ids = getCheckedIds();
    if(!ids.length){ layer.msg('请先选择用户',{icon:0}); return; }

    if(ev === 'batchBan'){
      layer.confirm('确定批量封禁 '+ids.length+' 个用户？',{icon:3,title:'批量封禁'},function(ci){
        layer.close(ci); batchReq({action:'ban', ids:ids});
      });
    } else if(ev === 'batchUnban'){
      layer.confirm('确定批量解封 '+ids.length+' 个用户？',{icon:3,title:'批量解封'},function(ci){
        layer.close(ci); batchReq({action:'unban', ids:ids});
      });
    } else if(ev === 'batchGroup'){
      var opts='';
      PAGE_GROUPS.forEach(function(g){ opts+='<option value="'+g.id+'">'+layui.util.escape(g.name)+'</option>'; });
      var html='<div style="padding:20px;">'
        +'<div class="layui-form-item"><label class="layui-form-label">用户组</label><div class="layui-input-block"><select id="batchGroupSel" class="layui-input">'+opts+'</select></div></div>'
        +'<div style="text-align:right;"><button class="layui-btn" id="batchGroupSubmit">确定</button></div></div>';
      var idx=layer.open({type:1,title:'批量修改用户组（'+ids.length+'人）',area:['400px'],content:html,success:function(){
        $('#batchGroupSubmit').on('click',function(){
          var gid=$('#batchGroupSel').val();
          layer.close(idx);
          batchReq({action:'change_group', ids:ids, group_id:parseInt(gid)});
        });
      }});
    } else if(ev === 'batchDelete'){
      layer.confirm('确定批量删除 '+ids.length+' 个用户？此操作不可恢复！',{icon:2,title:'批量删除'},function(ci){
        layer.close(ci); batchReq({action:'delete', ids:ids});
      });
    }
  });
});
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
