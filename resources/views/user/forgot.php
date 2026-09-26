<?php $pageTitle = '找回密码'; $pageCss = ['auth']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="auth-page">
    <div class="auth-card">
        <?php include __DIR__ . '/_forgot_card.php'; ?>
    </div>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
