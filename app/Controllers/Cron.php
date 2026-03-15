<?php
/**
 * 定时任务控制器
 */

namespace App\Controllers;

class Cron extends Base
{
    public function run(): void
    {
        // 验证 key（仅支持 Header 方式，避免 key 泄露到日志和 Referer）
        $key = $_SERVER['HTTP_X_CRON_KEY'] ?? '';
        $configKey = (string)\App\Services\SettingSvc::get('cron_key', '');

        if ($configKey === '' || !hash_equals($configKey, $key)) {
            $this->error('无效的 cron key', 403);
            exit;
        }

        $cronSvc = new \App\Services\CronSvc();
        $cronSvc->runAll();

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
