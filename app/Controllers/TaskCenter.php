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
        $taskKey = trim($_POST['task_key'] ?? '');
        try {
            $result = \App\Services\TaskCenterSvc::claim($this->getCurrentUserId(), $taskKey);
            $this->success('领取成功，获得 ' . $result['credits'] . ' 积分');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 一键领取所有
     */
    public function claimAll(): void
    {
        $this->requireLogin();
        try {
            $result = \App\Services\TaskCenterSvc::claimAll($this->getCurrentUserId());
            if ($result['credits'] > 0) {
                $this->success('领取成功，共获得 ' . $result['credits'] . ' 积分');
            } else {
                $this->error('没有可领取的任务奖励');
                return;
            }
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }
}
