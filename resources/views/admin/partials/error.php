<?php
/**
 * 后台提示页（「参数错误 / 不存在」这类分支）
 * ==========================================================================
 * 这些分支以前直接 echo 一段 Bootstrap 的
 *   <div class="alert alert-danger mb-0">…</div>
 * 但后台已经不用 Bootstrap 了，那段 HTML 会退化成没有任何样式的裸文字。
 * 现在统一走 renderAdmin()，拿到的是和其它子页面一样的完整文档
 * （layout_child.php 包一层 + layui.css + 字体图标），所以：
 *
 *   - HTTP 状态码仍由调用方 http_response_code(400/404) 决定，语义不变；
 *   - 页面本身是 layui 原生外观，不是「半截 HTML」。
 *
 * 变量：$message（提示文案）、$pageTitle（由 renderAdmin 兜底）
 */

$e       = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$message = (string)($message ?? '请求有误');
?>
<div class="admin-notice">
  <i class="fa fa-exclamation-circle admin-notice-icon" aria-hidden="true"></i>
  <p class="admin-notice-msg"><?= $e($message) ?></p>
  <button type="button" class="layui-btn layui-btn-sm" id="adminNoticeClose">关闭</button>
</div>

<script>
// 正常的用法是这个页面被 layer.open({type:2}) 弹层打开，点「关闭」收起自己；
// 万一有人直接把 URL 贴进地址栏（没有父窗口），就退回上一页。
layui.use(['layer'], function () {
  document.getElementById('adminNoticeClose').addEventListener('click', function () {
    var parentLayer = window.parent && window.parent.layer;
    if (parentLayer && typeof parentLayer.getFrameIndex === 'function') {
      var index = parentLayer.getFrameIndex(window.name);
      if (index !== undefined && index !== null) {
        parentLayer.close(index);
        return;
      }
    }
    history.back();
  });
});
</script>
