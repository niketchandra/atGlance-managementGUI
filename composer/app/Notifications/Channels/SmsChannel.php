<?php

namespace App\Notifications\Channels;

use App\Models\NotificationGroup;
use App\Notifications\Message;
use App\Support\NotificationSettings;
use RuntimeException;

/**
 * SMS through Mailchimp Transactional (Mandrill):
 * https://mailchimp.com/developer/transactional/api/messages/send-sms-message/
 */
class SmsChannel extends HttpChannel
{
    public const ENDPOINT = 'https://mandrillapp.com/api/1.0/messages/send-sms';

    public function send(NotificationGroup $group, Message $message): void
    {
        $apiKey = NotificationSettings::credential('notify_sms_mailchimp_api_key');
        $from = NotificationSettings::credential('notify_sms_from');
        if ($apiKey === '' || $from === '') {
            throw new RuntimeException('Mailchimp SMS API key or from number is not set.');
        }

        $failures = [];
        foreach ($group->targetList() as $phone) {
            $response = $this->http()->post(self::ENDPOINT, [
                'key' => $apiKey,
                'message' => ['to' => $phone, 'from' => $from, 'text' => mb_substr($message->text(), 0, 1600)],
            ]);

            $result = $response->json('0') ?? $response->json();
            $status = is_array($result) ? ($result['status'] ?? null) : null;
            if (!$response->successful() || in_array($status, ['rejected', 'invalid', 'error'], true)) {
                $reason = is_array($result) ? ($result['reject_reason'] ?? $result['message'] ?? $status) : null;
                $failures[] = $phone . ' (' . ($reason ?: 'HTTP ' . $response->status()) . ')';
            }
        }

        if ($failures !== []) {
            throw new RuntimeException('SMS not sent to: ' . implode(', ', $failures));
        }
    }
}
