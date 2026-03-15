<?php
namespace App\Controllers;

class NavLink extends Base
{
    /**
     * 导航页面
     */
    public function index(): void
    {
        $categories = \App\Services\NavLinkSvc::getAll();
        $popular = \App\Services\NavLinkSvc::getPopular(10);

        $this->render('navlink/index', [
            'categories' => $categories,
            'popular' => $popular,
        ]);
    }

    /**
     * 记录点击并跳转
     */
    public function go(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            try {
                \App\Services\NavLinkSvc::recordClick($id);
                $link = \Core\Database::fetchOne("SELECT url FROM nav_links WHERE id = ?", [$id]);
                if ($link && preg_match('#^https?://#i', $link['url'])) {
                    // 过滤换行符防止 header injection
                    $url = str_replace(["\r", "\n"], '', $link['url']);
                    header('Location: ' . $url);
                    exit;
                }
            } catch (\Throwable $e) {
                error_log('[NavLink] go failed: ' . $e->getMessage());
            }
        }
        header('Location: /navigation');
        exit;
    }
}
