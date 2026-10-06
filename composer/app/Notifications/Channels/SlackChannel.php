<?php

namespace App\Notifications\Channels;

use App\Models\NotificationGroup;
use App\Notifications\Message;

/**
 * Slack incoming webhook: https://api.slack.com/messaging/webhooks
 */
class SlackChannel extends HttpChannel
{
    public function send(NotificationGroup $group, Message $message): void
    {
        $lines = ['*' . $message->title . '*'];
        foreach ($message->facts as $label => $value) {
            $lines[] = '• ' . $label . ': ' . $value;
        }

        $this->ensureSuccessful($this->http()->post($group->target, ['text' => implode("\n", $lines)]), 'Slack');
    }
}
