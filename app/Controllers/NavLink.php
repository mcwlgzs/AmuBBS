<?php
namespace App\Controllers;

use App\Models\NavLink as NavLinkModel;
use App\Services\NavLinkSvc;

class NavLink extends Base
{
    /**
     * 导航页面
     */
    public function index(): void
    {
        $categories = NavLinkSvc::getAll();
        $popular = NavLinkSvc::getPopular(10);

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
                NavLinkSvc::recordClick($id);
                $url = NavLinkModel::getUrl($id);
                if ($url !== null && preg_match('#^https?://#i', $url)) {
                    // 过滤换行符防止 header injection
                    header('Location: ' . str_replace(["\r", "\n"], '', $url));
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
