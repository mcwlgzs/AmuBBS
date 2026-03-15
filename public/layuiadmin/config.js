/**
 * AMuBBS 后台 layuiAdmin 配置
 */
layui.define(['laytpl', 'layer', 'element', 'util'], function(exports){
  exports('setter', {
    container: 'LAY_app'
    ,base: layui.cache.base
    ,views: layui.cache.base + 'tpl/'
    ,entry: 'index'
    ,engine: '.html'
    ,pageTabs: true

    ,name: 'AMuBBS'
    ,tableName: 'amubbs_admin'
    ,MOD_NAME: 'admin'

    ,debug: false

    ,request: {
      tokenName: false // AMuBBS 使用自己的 CSRF，不需要 layuiAdmin 的 token
    }

    ,response: {
      statusName: 'code'
      ,statusCode: {
        ok: 0
        ,logout: 1001
      }
      ,msgName: 'msg'
      ,dataName: 'data'
    }

    ,extend: ['echarts']

    ,theme: {
      color: [{
        main: '#20222A'
        ,selected: '#009688'
        ,alias: 'default'
      },{
        main: '#03152A'
        ,selected: '#3B91FF'
        ,alias: 'dark-blue'
      },{
        main: '#2E241B'
        ,selected: '#A48566'
        ,alias: 'coffee'
      },{
        main: '#50314F'
        ,selected: '#7A4D7B'
        ,alias: 'purple-red'
      },{
        main: '#344058'
        ,logo: '#1E9FFF'
        ,selected: '#1E9FFF'
        ,alias: 'ocean'
      },{
        main: '#3A3D49'
        ,logo: '#2F9688'
        ,selected: '#5FB878'
        ,alias: 'green'
      },{
        main: '#20222A'
        ,logo: '#F78400'
        ,selected: '#F78400'
        ,alias: 'red'
      },{
        main: '#28333E'
        ,logo: '#AA3130'
        ,selected: '#AA3130'
        ,alias: 'fashion-red'
      },{
        main: '#24262F'
        ,logo: '#3A3D49'
        ,selected: '#009688'
        ,alias: 'classic-black'
      }]
      ,initColorIndex: 0
    }
  });
});
