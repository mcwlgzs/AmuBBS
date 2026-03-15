/**
 * AMuBBS 后台公共业务模块
 * - 全局 CSRF Token 注入
 * - 退出登录
 */
layui.define(function(exports){
  var $ = layui.$
  ,layer = layui.layer
  ,admin = layui.admin;

  // 全局 AJAX 注入 CSRF Token + loading 状态
  var csrfToken = $('meta[name="csrf-token"]').attr('content') || '';
  var activeRequests = 0;
  $.ajaxSetup({
    headers: {
      'X-CSRF-Token': csrfToken
    },
    beforeSend: function(){
      if(activeRequests === 0){
        // 顶部进度条
        if(!$('#globalProgress').length){
          $('body').append('<div id="globalProgress" style="position:fixed;top:0;left:0;width:0;height:2px;background:#009688;z-index:99999;transition:width .3s;"></div>');
        }
        $('#globalProgress').css('width', '60%');
      }
      activeRequests++;
    },
    complete: function(){
      activeRequests--;
      if(activeRequests <= 0){
        activeRequests = 0;
        $('#globalProgress').css('width', '100%');
        setTimeout(function(){ $('#globalProgress').css('width', '0'); }, 300);
      }
    }
  });

  // 退出登录
  admin.events.logout = function(){
    layer.confirm('确定退出登录？', {icon: 3, title: '提示'}, function(index){
      $.ajax({
        url: '/logout',
        type: 'POST',
        headers: {'X-CSRF-Token': csrfToken},
        success: function(){
          location.href = '/';
        },
        error: function(){
          location.href = '/';
        }
      });
      layer.close(index);
    });
  };

  exports('common', {});
});
