<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
  <div class="layui-card-header">
    标签分类
    <button class="layui-btn layui-btn-sm" style="float:right;margin-top:4px;" id="btnAddCate">添加分类</button>
  </div>

  <!-- 添加表单 -->
  <div class="layui-card-body" id="addCatePanel" style="display:none;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
    <form class="layui-form" lay-filter="addCateForm" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">分类名称</label>
        <div class="layui-input-inline" style="width:180px;">
          <input type="text" name="name" class="layui-input" lay-verify="required" placeholder="分类名称">
        </div>
      </div>
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">关联板块</label>
        <div class="layui-input-inline" style="width:160px;">
          <select name="forum_id">
            <option value="0">全局（所有板块可用）</option>
            <?php foreach ($forums as $f): ?>
            <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="layui-inline">
        <label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">排序</label>
        <div class="layui-input-inline" style="width:80px;">
          <input type="number" name="sort_order" class="layui-input" value="0">
        </div>
      </div>
      <div class="layui-inline">
        <button type="button" class="layui-btn layui-btn-sm" lay-submit lay-filter="submitCate">添加</button>
      </div>
    </form>
  </div>

  <div class="layui-card-body">
    <p style="font-size:12px;color:#999;margin-bottom:12px;">标签分类可关联到板块，发帖时会显示该板块关联的标签供选择。forum_id=0 表示全局分类。</p>
    <table id="tagCatTable" lay-filter="tagCatTable"></table>
  </div>
</div>

<script type="text/html" id="tagCatBarTpl">
  <button class="layui-btn layui-btn-sm layui-btn-danger" lay-event="delete">删除</button>
</script>

<script>
layui.use(['form', 'layer', 'table'], function(){
  var $ = layui.$, layer = layui.layer, form = layui.form, table = layui.table;

  table.render({
    elem: '#tagCatTable',
    id: 'tagCatTable',
    url: '/admin/api/tag-categories',
    page: false,
    cols: [[
      {field:'id', title:'ID', width:80, sort:true},
      {field:'name', title:'名称', minWidth:150},
      {field:'forum_name', title:'关联板块', minWidth:120, templet:function(d){
        if(!d.forum_id || d.forum_id==0) return '<span style="color:#999;">全局</span>';
        return d.forum_name || ('板块#'+d.forum_id);
      }},
      {field:'tag_count', title:'标签数', width:90, templet:function(d){return d.tag_count||0;}},
      {field:'sort_order', title:'排序', width:80},
      {title:'操作', width:100, align:'center', toolbar:'#tagCatBarTpl'}
    ]],
    text: {none: '暂无标签分类'}
  });

  $('#btnAddCate').on('click', function(){ $('#addCatePanel').toggle(); });

  form.on('submit(submitCate)', function(data){
    var field = data.field;
    if(!field.name || !field.name.trim()){ layer.msg('请输入分类名称', {icon:2}); return false; }
    $.ajax({
      url: '/admin/tag-categories/create',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(field),
      success: function(res){
        if(res.code===0||res.success){ layer.msg('添加成功', {icon:1}); table.reload('tagCatTable'); }
        else { layer.msg(res.msg||res.message||'操作失败', {icon:2}); }
      },
      error: function(){ layer.msg('请求失败', {icon:2}); }
    });
    return false;
  });

  table.on('tool(tagCatTable)', function(obj){
    var data = obj.data;
    if(obj.event === 'delete'){
      layer.confirm('删除分类后，该分类下的标签将变为未分类。确定？', {icon:3, title:'确认删除'}, function(index){
        $.ajax({
          url: '/admin/tag-categories/delete',
          type: 'POST',
          contentType: 'application/json',
          data: JSON.stringify({id: data.id}),
          success: function(res){
            if(res.code===0||res.success){ layer.msg('删除成功', {icon:1}); table.reload('tagCatTable'); }
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
