<?php

namespace App\Notifications\Channels;

use App\Models\NotificationGroup;
use App\Notifications\Message;
use App\Support\NotificationSettings;

/**
 * Generic webhook. The JSON body is signed with the organization's signing
 * secret: X-AtGlance-Signature: sha256=<hex HMAC of the raw body>.
 */
class WebhookChannel extends HttpChannel
{
    public function send(NotificationGroup $group, Message $message): void
    {
        $body = json_encode($message->payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['X-AtGlance-Event' => $message->event];

        $secret = NotificationSettings::credential('notify_webhook_signing_secret');
        if ($secret !== '') {
            $headers['X-AtGlance-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $response = $this->http()
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($group->target);

        $this->ensureSuccessful($response, 'Webhook');
    }
}
