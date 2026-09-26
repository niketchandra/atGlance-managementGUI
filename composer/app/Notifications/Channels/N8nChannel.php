<?php

namespace App\Notifications\Channels;

use App\Models\NotificationGroup;
use App\Notifications\Message;
use App\Support\NotificationSettings;

/**
 * n8n workflow started by a Webhook trigger node. Sends the JSON event
 * payload, with the organization's auth header when one is set (for the
 * Webhook node's "Header Auth" option).
 */
class N8nChannel extends HttpChannel
{
    public function send(NotificationGroup $group, Message $message): void
    {
        $request = $this->http();

        $headerName = NotificationSettings::credential('notify_n8n_auth_header_name');
        $headerValue = NotificationSettings::credential('notify_n8n_auth_header_value');
        if ($headerName !== '' && $headerValue !== '') {
            $request = $request->withHeaders([$headerName => $headerValue]);
        }

        $this->ensureSuccessful($request->post($group->target, $message->payload()), 'n8n');
    }
}
