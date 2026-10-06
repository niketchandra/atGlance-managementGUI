<?php

namespace App\Notifications\Channels;

use App\Models\NotificationGroup;
use App\Notifications\Message;

interface NotificationChannel
{
    /**
     * Sends the message to the group's target. Throws on failure.
     */
    public function send(NotificationGroup $group, Message $message): void;
}
