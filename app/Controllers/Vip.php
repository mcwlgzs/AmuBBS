<?php
namespace App\Controllers;

class Vip extends Base
{
    /**
     * VIP 页面
     */
    public function index(): void
    {
        $vipEnabled = \App\Services\VipSvc::isEnabled();
        if (!$vipEnabled) {
            $this->render('user/vip', [
                'vipEnabled' => false,
                'levels' => [],
                'userVip' => null,
                'userCredits' => 0,
            ]);
            return;
        }

        $levels = \App\Services\VipSvc::getLevels();
        $userVip = null;
        $userCredits = 0;

        if ($this->isLoggedIn()) {
            $userVip = \App\Services\VipSvc::getUserVip($this->getCurrentUserId());
            $user = \Core\Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$this->getCurrentUserId()]);
            $userCredits = (int)($user['credits'] ?? 0);
        }

        $this->render('user/vip', [
            'vipEnabled' => true,
            'levels' => $levels,
            'userVip' => $userVip,
            'userCredits' => $userCredits,
        ]);
    }

    /**
     * 购买 VIP
     */
    public function purchase(): void
    {
        $this->requireLogin();

        if (!\App\Services\VipSvc::isEnabled()) {
            $this->error('VIP 功能暂未开放');
            return;
        }

        $level = (int)($_POST['level'] ?? 0);
        $months = (int)($_POST['months'] ?? 1);
        if ($months < 1 || $months > 12) {
            $this->error('购买月数必须在 1-12 之间');
            return;
        }

        try {
            $result = \App\Services\VipSvc::purchase($this->getCurrentUserId(), $level, $months);
            $this->success("成功开通{$result['name']}，花费 {$result['cost']} 积分");
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }
}
