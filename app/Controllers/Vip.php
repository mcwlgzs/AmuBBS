<?php
namespace App\Controllers;

use App\Models\User;
use App\Services\VipSvc;

class Vip extends Base
{
    /**
     * VIP 页面
     */
    public function index(): void
    {
        $vipEnabled = VipSvc::isEnabled();
        if (!$vipEnabled) {
            $this->render('user/vip', [
                'vipEnabled' => false,
                'levels' => [],
                'userVip' => null,
                'userCredits' => 0,
            ]);
            return;
        }

        $levels = VipSvc::getLevels();
        $userVip = null;
        $userCredits = 0;

        if ($this->isLoggedIn()) {
            $userVip = VipSvc::getUserVip($this->getCurrentUserId());
            $userCredits = User::getCredits($this->getCurrentUserId());
        }

        $this->render('user/vip', [
            'vipEnabled' => true,
            'levels' => $levels,
            'userVip' => $userVip,
            'userCredits' => $userCredits,
        ]);
    }

    /**
     * 报价片段（htmx）
     *
     * 选择等级 / 时长后实时显示需要多少积分，由服务端算，
     * 页面侧不用再塞一份价格表到 JS 里（原来的 vipApp().prices 就是这么干的）。
     */
    public function quote(): void
    {
        $level = (int)($_GET['level'] ?? 0);
        $months = max(1, min(12, (int)($_GET['months'] ?? 1)));

        $this->render('user/_vip_quote', [
            'level'  => $level,
            'months' => $months,
            'cost'   => VipSvc::quoteCost($level, $months),
        ]);
    }

    /**
     * 购买 VIP
     */
    public function purchase(): void
    {
        $this->requireLogin();

        if (!VipSvc::isEnabled()) {
            $this->respondRefresh(false, 'VIP 功能暂未开放');
            return;
        }

        $input = $this->input();
        $level = (int)($input['level'] ?? 0);
        $months = (int)($input['months'] ?? 1);
        if ($months < 1 || $months > 12) {
            $this->respondRefresh(false, '购买月数必须在 1-12 之间');
            return;
        }

        try {
            $result = VipSvc::purchase($this->getCurrentUserId(), $level, $months);
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
            return;
        }

        // 开通后积分、等级卡片、当前 VIP 状态都要跟着变，整页刷新最省事
        $this->respondRefresh(true, "成功开通{$result['name']}，花费 {$result['cost']} 积分");
    }
}
