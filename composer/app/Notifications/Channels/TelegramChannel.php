<?php

namespace App\Notifications\Channels;

use App\Models\NotificationGroup;
use App\Notifications\Message;
use App\Support\NotificationSettings;
use RuntimeException;

/**
 * Telegram Bot API sendMessage, with the organization's bot token and the
 * group's chat ID. The bot must be a member of the chat.
 */
class TelegramChannel extends HttpChannel
{
    public function send(NotificationGroup $group, Message $message): void
    {
        $token = NotificationSettings::credential('notify_telegram_bot_token');
        if ($token === '') {
            throw new RuntimeException('Telegram bot token is not set.');
        }

        $response = $this->http()->post('https://api.telegram.org/bot' . $token . '/sendMessage', [
            'chat_id' => $group->target,
            'text' => $message->text(),
            'disable_web_page_preview' => true,
        ]);

        // The URL holds the token, so only the status and Telegram's description are reported.
        if (!$response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException(sprintf('Telegram returned HTTP %d: %s', $response->status(), (string) $response->json('description', 'unknown error')));
        }
    }
}
