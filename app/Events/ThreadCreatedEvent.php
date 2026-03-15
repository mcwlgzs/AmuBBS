<?php
/**
 * 帖子创建事件
 */

namespace App\Events;

class ThreadCreatedEvent
{
    public int $threadId;
    public int $userId;
    public array $data;

    public function __construct(int $threadId, int $userId, array $data = [])
    {
        $this->threadId = $threadId;
        $this->userId = $userId;
        $this->data = $data;
    }
}
