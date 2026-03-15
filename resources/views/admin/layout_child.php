<?php
/**
 * 后台子页面布局头部（iframe 内的页面）
 * 子页面不需要侧边栏/顶栏，只需要 layui 基础样式
 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($pageTitle ?? '') ?> - AMuBBS 管理后台</title>
  <?= \App\Middlewares\Csrf::tokenMeta() ?>
  <link rel="stylesheet" href="/layui/css/layui.css">
  <link rel="stylesheet" href="/layuiadmin/style/admin.css">
  <link rel="stylesheet" href="/assets/css/admin-responsive.css">
  <link rel="stylesheet" href="/assets/css/emoji-picker.css">
  <script src="/layui/layui.js"></script>
  <script src="/assets/js/emoji-picker.js"></script>
  <script>
  layui.config({base: '/layuiadmin/'}).extend({index: 'lib/index'}).use(['index', 'common']);
  layui.$.ajaxSetup({headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''}});
  </script>
</head>
<body>
<div class="layui-fluid">
