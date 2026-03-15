<?php
/**
 * 用户注册事件
 */

namespace App\Events;

class UserRegisteredEvent
{
    public int $userId;
    public string $username;
    public string $email;

    public function __construct(int $userId, string $username, string $email)
    {
        $this->userId = $userId;
        $this->username = $username;
        $this->email = $email;
    }
}
