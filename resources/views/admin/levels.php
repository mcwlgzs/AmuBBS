<?php include __DIR__ . '/layout_child.php'; ?>

<div class="layui-card">
    <div class="layui-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>等级设置</h3>
        <button class="layui-btn layui-btn-sm" id="btnAddLevel">添加等级</button>
    </div>
    <div class="layui-card-body">
        <table id="levelsTable" lay-filter="levelsTable"></table>
    </div>
</div>

<!-- 行操作模板 -->
<script type="text/html" id="levelBar">
    <button class="layui-btn layui-btn-xs layui-btn-primary" lay-event="edit">编辑</button>
    <button class="layui-btn layui-btn-xs layui-btn-danger" lay-event="del">删除</button>
</script>

<script>
layui.use(['table', 'form', 'layer'], function(){
    var $ = layui.$, table = layui.table, form = layui.form, layer = layui.layer;

    table.render({
        elem: '#levelsTable',
        id: 'levelsTable',
        url: '/admin/api/levels',
        page: false,
        cols: [[
            {field:'id', title:'ID', width:60, sort:true},
            {field:'level', title:'等级', width:70, templet: function(d){ return 'Lv'+d.level; }},
            {field:'name', title:'名称', width:120},
            {field:'min_credits', title:'所需积分', width:110, templet: function(d){
                return Number(d.min_credits).toLocaleString();
            }},
            {field:'color', title:'颜色', width:120, templet: function(d){
                var c = d.color || '#999';
                return '<span style="display:inline-block;width:16px;height:16px;border-radius:3px;background:'+layui.util.escape(c)+';vertical-align:middle;"></span> '+layui.util.escape(c);
            }},
            {field:'icon', title:'图标', width:60, templet: function(d){ return d.icon ? '<i class="layui-icon '+layui.util.escape(d.icon)+'" style="font-size:18px;"></i>' : '-'; }},
            {title:'预览', width:150, templet: function(d){
                var c = d.color || '#999';
                return '<span style="background:'+c+'22;color:'+c+';border:1px solid '+c+';padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;opacity:1;">Lv'+d.level+' '+layui.util.escape(d.name)+'</span>';
            }},
            {title:'操作', width:130, align:'center', toolbar:'#levelBar'}
        ]],
        text: {none: '暂无等级，请添加'}
    });

    // 构建表单HTML
    function buildFormHtml(data){
        var isEdit = data && data.id;
        return '<div style="padding:20px;">'
            + '<form class="layui-form" lay-filter="levelForm">'
            + '<input type="hidden" name="id" value="'+(isEdit ? data.id : 0)+'">'
            + '<div class="layui-form-item">'
            + '  <label class="layui-form-label">等级序号</label>'
            + '  <div class="layui-input-block">'
            + '    <input type="number" name="level" value="'+(isEdit ? data.level : '')+'" placeholder="如 1, 2, 3..." min="0" class="layui-input">'
            + '  </div>'
            + '</div>'
            + '<div class="layui-form-item">'
            + '  <label class="layui-form-label">等级名称</label>'
            + '  <div class="layui-input-block">'
            + '    <input type="text" name="name" value="'+(isEdit ? layui.util.escape(data.name) : '')+'" placeholder="如 新手、初级、中级..." class="layui-input">'
            + '  </div>'
            + '</div>'
            + '<div class="layui-form-item">'
            + '  <label class="layui-form-label">所需积分</label>'
            + '  <div class="layui-input-block">'
            + '    <input type="number" name="min_credits" value="'+(isEdit ? data.min_credits : '')+'" placeholder="达到此积分自动升级" min="0" class="layui-input">'
            + '  </div>'
            + '</div>'
            + '<div class="layui-form-item">'
            + '  <label class="layui-form-label">颜色</label>'
            + '  <div class="layui-input-block">'
            + '    <div style="display:flex;gap:8px;align-items:center;">'
            + '      <input type="color" name="color_picker" value="'+(isEdit && data.color ? data.color : '#999999')+'" style="width:40px;height:30px;border:1px solid #ebedf0;border-radius:4px;cursor:pointer;" onchange="$(this).next().val(this.value)">'
            + '      <input type="text" name="color" value="'+(isEdit && data.color ? data.color : '#999999')+'" placeholder="#999999" class="layui-input" style="flex:1;">'
            + '    </div>'
            + '  </div>'
            + '</div>'
            + '<div class="layui-form-item">'
            + '  <label class="layui-form-label">图标</label>'
            + '  <div class="layui-input-block">'
            + '    <div style="display:flex;align-items:center;gap:8px;">'
            + '      <span class="level-icon-preview" style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid #e6e6e6;border-radius:4px;font-size:20px;cursor:pointer;" onclick="toggleIconPanel(this)" title="点击选择图标">'
            + (isEdit && data.icon ? '<i class="layui-icon '+layui.util.escape(data.icon)+'"></i>' : '<span style="color:#ccc;">+</span>')
            + '      </span>'
            + '      <input type="hidden" name="icon" value="'+(isEdit && data.icon ? layui.util.escape(data.icon) : '')+'">'
            + '      <span style="font-size:12px;color:#999;" class="level-icon-name">'+(isEdit && data.icon ? layui.util.escape(data.icon) : '未选择')+'</span>'
            + '      <button type="button" class="layui-btn layui-btn-xs layui-btn-primary" onclick="clearLevelIcon(this)">清除</button>'
            + '    </div>'
            + '    <div class="level-icon-panel" style="display:none;margin-top:8px;max-height:200px;overflow-y:auto;border:1px solid #e6e6e6;border-radius:4px;padding:8px;background:#fff;">'
            + '    </div>'
            + '  </div>'
            + '</div>'
            + '<div class="layui-form-item">'
            + '  <div class="layui-input-block">'
            + '    <button type="button" class="layui-btn" lay-submit lay-filter="saveLevel">保存</button>'
            + '    <button type="button" class="layui-btn layui-btn-primary" id="cancelLevelBtn">取消</button>'
            + '  </div>'
            + '</div>'
            + '</form></div>';
    }

    // 当前弹窗索引
    var currentLevelIdx = -1;

    // 打开添加/编辑弹窗
    function openLevelForm(data){
        var isEdit = data && data.id;
        currentLevelIdx = layer.open({
            type: 1,
            title: isEdit ? '编辑等级' : '添加等级',
            area: ['500px'],
            content: buildFormHtml(data),
            success: function(layero){
                form.render(null, 'levelForm');
                // 构建 layui 图标面板
                var panel = layero.find('.level-icon-panel');
                if(panel.length && !panel.data('built')){
                    panel.data('built', true);
                    var icons = _layuiIcons();
                    var html = '<div style="display:grid;grid-template-columns:repeat(8,1fr);gap:2px;">';
                    for(var i=0;i<icons.length;i++){
                        html += '<span style="display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:4px;cursor:pointer;font-size:18px;" title="'+icons[i]+'" onclick="selectLevelIcon(this,\''+icons[i]+'\')" onmouseover="this.style.background=\'#f0f0f0\'" onmouseout="this.style.background=\'\'"><i class="layui-icon '+icons[i]+'"></i></span>';
                    }
                    html += '</div>';
                    panel.html(html);
                }
                $(layero).find('#cancelLevelBtn').on('click', function(){ layer.close(currentLevelIdx); });
            }
        });
    }

    // 表单提交事件（只注册一次）
    form.on('submit(saveLevel)', function(obj){
        var field = obj.field;
        delete field.color_picker;
        if(!field.name){ layer.msg('请填写等级名称', {icon:2}); return false; }
        $.ajax({
            url: '/admin/levels/save',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(field),
            dataType: 'json',
            success: function(res){
                if(res.code===0||res.success){
                    layer.msg('保存成功', {icon:1});
                    layer.close(currentLevelIdx);
                    table.reload('levelsTable');
                } else {
                    layer.msg(res.msg||res.message||'操作失败', {icon:2});
                }
            },
            error: function(){ layer.msg('请求失败', {icon:2}); }
        });
        return false;
    });

    // 添加按钮
    $('#btnAddLevel').on('click', function(){ openLevelForm(null); });

    // 行操作
    table.on('tool(levelsTable)', function(obj){
        var d = obj.data;
        if(obj.event === 'edit'){
            openLevelForm(d);
        } else if(obj.event === 'del'){
            layer.confirm('确定删除此等级？', {icon:3, title:'确认删除'}, function(index){
                $.ajax({
                    url: '/admin/levels/delete',
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({id: d.id}),
                    dataType: 'json',
                    success: function(res){
                        if(res.code===0||res.success){
                            layer.msg('删除成功', {icon:1});
                            table.reload('levelsTable');
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

// layui 内置图标列表
function _layuiIcons(){
    return [
        'layui-icon-heart-fill','layui-icon-heart','layui-icon-light','layui-icon-time','layui-icon-bluetooth',
        'layui-icon-at','layui-icon-mute','layui-icon-mike','layui-icon-key','layui-icon-gift',
        'layui-icon-email','layui-icon-rss','layui-icon-wifi','layui-icon-logout','layui-icon-android',
        'layui-icon-ios','layui-icon-windows','layui-icon-transfer','layui-icon-service','layui-icon-subtraction',
        'layui-icon-addition','layui-icon-slider','layui-icon-print','layui-icon-export','layui-icon-cols',
        'layui-icon-screen-restore','layui-icon-screen-full','layui-icon-rate-half','layui-icon-rate',
        'layui-icon-rate-solid','layui-icon-cellphone','layui-icon-vercode','layui-icon-login-weibo',
        'layui-icon-login-qq','layui-icon-login-wechat','layui-icon-username','layui-icon-password',
        'layui-icon-refresh-3','layui-icon-auz','layui-icon-spread-left','layui-icon-shrink-right',
        'layui-icon-snowflake','layui-icon-tips','layui-icon-note','layui-icon-home','layui-icon-senior',
        'layui-icon-refresh','layui-icon-refresh-1','layui-icon-flag','layui-icon-theme',
        'layui-icon-notice','layui-icon-website','layui-icon-console','layui-icon-face-surprised',
        'layui-icon-set','layui-icon-template-1','layui-icon-template','layui-icon-text',
        'layui-icon-unlink','layui-icon-picture','layui-icon-link','layui-icon-face-smile',
        'layui-icon-align-center','layui-icon-align-right','layui-icon-align-left',
        'layui-icon-upload-drag','layui-icon-upload','layui-icon-download-circle',
        'layui-icon-component','layui-icon-file','layui-icon-find-fill','layui-icon-loading',
        'layui-icon-loading-1','layui-icon-add-1','layui-icon-play','layui-icon-pause',
        'layui-icon-headset','layui-icon-video','layui-icon-voice','layui-icon-speaker',
        'layui-icon-fonts-del','layui-icon-fonts-code','layui-icon-fonts-html','layui-icon-fonts-strong',
        'layui-icon-unordered-list','layui-icon-ordered-list','layui-icon-fonts-u',
        'layui-icon-fonts-i','layui-icon-tabs','layui-icon-radio','layui-icon-circle',
        'layui-icon-edit','layui-icon-delete','layui-icon-engine','layui-icon-chart',
        'layui-icon-chart-screen','layui-icon-list','layui-icon-form','layui-icon-diamond',
        'layui-icon-layer','layui-icon-table','layui-icon-date','layui-icon-water',
        'layui-icon-code-circle','layui-icon-carousel','layui-icon-prev','layui-icon-next',
        'layui-icon-upload-circle','layui-icon-tree','layui-icon-record','layui-icon-camera',
        'layui-icon-camera-fill','layui-icon-chat','layui-icon-location','layui-icon-read',
        'layui-icon-survey','layui-icon-face-cry','layui-icon-cart-simple','layui-icon-app',
        'layui-icon-user','layui-icon-female','layui-icon-male','layui-icon-top',
        'layui-icon-star','layui-icon-star-fill','layui-icon-close-fill','layui-icon-close',
        'layui-icon-ok-circle','layui-icon-ok','layui-icon-help','layui-icon-about',
        'layui-icon-up','layui-icon-down','layui-icon-left','layui-icon-right',
        'layui-icon-circle-dot','layui-icon-search','layui-icon-set-sm','layui-icon-group',
        'layui-icon-friends','layui-icon-reply-fill','layui-icon-menu-fill','layui-icon-log',
        'layui-icon-picture-fine','layui-icon-dialogue','layui-icon-rmb','layui-icon-fire',
        'layui-icon-return','layui-icon-more','layui-icon-more-vertical','layui-icon-release',
        'layui-icon-share','layui-icon-fonts-clear','layui-icon-eye','layui-icon-eye-invisible'
    ];
}

function toggleIconPanel(el){
    var panel = layui.$(el).closest('.layui-form-item').find('.level-icon-panel');
    panel.toggle();
}

function selectLevelIcon(el, iconClass){
    var $item = layui.$(el).closest('.layui-form-item');
    $item.find('input[name="icon"]').val(iconClass);
    $item.find('.level-icon-preview').html('<i class="layui-icon '+iconClass+'"></i>');
    $item.find('.level-icon-name').text(iconClass);
    $item.find('.level-icon-panel').hide();
}

function clearLevelIcon(el){
    var $item = layui.$(el).closest('.layui-form-item');
    $item.find('input[name="icon"]').val('');
    $item.find('.level-icon-preview').html('<span style="color:#ccc;">+</span>');
    $item.find('.level-icon-name').text('未选择');
}
</script>

<?php include __DIR__ . '/layout_child_footer.php'; ?>
