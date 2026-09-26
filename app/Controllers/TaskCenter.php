<?php
namespace App\Controllers;

class TaskCenter extends Base
{
    /**
     * 任务中心页面
     */
    public function index(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();
        $taskData = \App\Services\TaskCenterSvc::getTaskList($userId);

        $this->render('user/task_center', [
            'taskData' => $taskData,
        ]);
    }

    /**
     * 领取单个任务奖励
     */
    public function claim(): void
    {
        $this->requireLogin();
        $taskKey = trim((string)($this->input()['task_key'] ?? ''));
        try {
            $result = \App\Services\TaskCenterSvc::claim($this->getCurrentUserId(), $taskKey);
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
            return;
        }

        // 领取后积分、任务进度、按钮状态全变，让前端整页刷新一次
        $this->respondRefresh(true, '领取成功，获得 ' . $result['credits'] . ' 积分');
    }

    /**
     * 一键领取所有
     */
    public function claimAll(): void
    {
        $this->requireLogin();
        try {
            $result = \App\Services\TaskCenterSvc::claimAll($this->getCurrentUserId());
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
            return;
        }

        if ($result['credits'] <= 0) {
            $this->respondRefresh(false, '没有可领取的任务奖励');
            return;
        }

        $this->respondRefresh(true, '领取成功，共获得 ' . $result['credits'] . ' 积分');
    }
}
