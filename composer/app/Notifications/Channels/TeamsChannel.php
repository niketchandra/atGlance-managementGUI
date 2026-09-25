<?php

namespace App\Notifications\Channels;

use App\Models\NotificationGroup;
use App\Notifications\Message;

/**
 * Microsoft Teams channel webhook created with the Workflows template "Post to
 * a channel when a webhook request is received". It expects an Adaptive Card.
 */
class TeamsChannel extends HttpChannel
{
    public function send(NotificationGroup $group, Message $message): void
    {
        $facts = [];
        foreach ($message->facts as $label => $value) {
            $facts[] = ['title' => (string) $label, 'value' => (string) $value];
        }

        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => array_values(array_filter([
                ['type' => 'TextBlock', 'text' => $message->title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
                $facts !== [] ? ['type' => 'FactSet', 'facts' => $facts] : null,
            ])),
        ];

        $payload = [
            'type' => 'message',
            'attachments' => [
                ['contentType' => 'application/vnd.microsoft.card.adaptive', 'contentUrl' => null, 'content' => $card],
            ],
        ];

        $this->ensureSuccessful($this->http()->post($group->target, $payload), 'Teams');
    }
}
