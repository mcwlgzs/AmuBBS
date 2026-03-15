<?php
/**
 * 回复创建事件
 */

namespace App\Events;

class PostCreatedEvent
{
    public int $postId;
    public int $threadId;
    public int $userId;

    public function __construct(int $postId, int $threadId, int $userId)
    {
        $this->postId = $postId;
        $this->threadId = $threadId;
        $this->userId = $userId;
    }
}
