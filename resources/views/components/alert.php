<?php
/**
 * 消息提示组件（Alpine.js 版）
 * 用法: 在 x-data 中定义 errorMessage / successMessage，然后 include 此组件
 *
 * 变量:
 *   $alertError   - Alpine 变量名，默认 'errorMessage'
 *   $alertSuccess - Alpine 变量名，默认 'successMessage'
 */
$_err = $alertError ?? 'errorMessage';
$_suc = $alertSuccess ?? 'successMessage';
?>
<div class="msg-error" x-show="<?= $_err ?>" x-text="<?= $_err ?>" x-cloak></div>
<div class="msg-success" x-show="<?= $_suc ?>" x-text="<?= $_suc ?>" x-cloak></div>
