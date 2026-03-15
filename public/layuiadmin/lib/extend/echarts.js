/**
 * ECharts layui 模块适配器
 * 实际 ECharts 库通过 <script> 标签在页面中加载
 * 此文件仅作为 layui.use('echarts') 的桥接
 */
layui.define(function(exports){
  exports('echarts', window.echarts || {});
});
