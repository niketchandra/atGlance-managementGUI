<?php

namespace App\Services;

use App\Models\NotificationGroup;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\N8nChannel;
use App\Notifications\Channels\NotificationChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\TeamsChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\Message;
use App\Notifications\NotificationEvents;
use App\Support\NotificationSettings;
use App\Support\SiteProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Sends an event to every notification group subscribed to it. Sending is
 * synchronous; each group is tried on its own and a failure is recorded on
 * the group, never thrown to the caller.
 */
class Notifier
{
    private const DRIVERS = [
        'email' => EmailChannel::class,
        'teams' => TeamsChannel::class,
        'slack' => SlackChannel::class,
        'n8n' => N8nChannel::class,
        'telegram' => TelegramChannel::class,
        'webhook' => WebhookChannel::class,
        'sms' => SmsChannel::class,
    ];

    /**
     * @param array<string, string> $facts shown to people (label => value)
     * @param array<string, mixed> $data extra payload for webhook and n8n
     */
    public function notify(string $event, ?int $workspaceId, string $title, array $facts = [], array $data = []): void
    {
        try {
            $scope = NotificationEvents::scope($event);
            if ($scope === null || !Schema::hasTable('notification_groups')) {
                return;
            }

            $query = NotificationGroup::query()->where('enabled', true);
            $scope === NotificationEvents::SCOPE_ORGANIZATION
                ? $query->whereNull('workspace_id')
                : $query->where('workspace_id', $workspaceId);

            $message = new Message($event, $title, ['Organization' => SiteProfile::current()->name()] + $facts, $data + ['workspace_id' => $workspaceId]);

            foreach ($query->get() as $group) {
                if ($group->subscribesTo($event) && NotificationSettings::isAllowed($group->channel)) {
                    $this->deliver($group, $message);
                }
            }
        } catch (Throwable $e) {
            Log::error('Notification dispatch failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Sends a test message to one group. Returns null on success, or the error.
     */
    public function test(NotificationGroup $group): ?string
    {
        $message = new Message(
            'notification.test',
            'Test notification from ' . SiteProfile::current()->name(),
            ['Group' => $group->name, 'Channel' => NotificationSettings::CHANNELS[$group->channel]['label'] ?? $group->channel],
            ['group_id' => $group->id],
        );

        return $this->deliver($group, $message);
    }

    private function deliver(NotificationGroup $group, Message $message): ?string
    {
        $error = null;

        try {
            $this->driver($group->channel)->send($group, $message);
        } catch (Throwable $e) {
            $error = $this->redact($e->getMessage(), $group);
            Log::warning('Notification not delivered', ['group_id' => $group->id, 'channel' => $group->channel, 'error' => $error]);
        }

        $group->forceFill([
            'last_sent_at' => now(),
            'last_status' => $error === null ? 'sent' : 'failed',
            'last_error' => $error === null ? null : mb_substr($error, 0, 1000),
        ])->saveQuietly();

        return $error;
    }

    private function driver(string $channel): NotificationChannel
    {
        $class = self::DRIVERS[$channel] ?? null;
        if ($class === null) {
            throw new RuntimeException('Channel is not available yet.');
        }

        return app($class);
    }

    /**
     * Error messages can contain URLs with tokens (Telegram) or webhook URLs.
     */
    private function redact(string $error, NotificationGroup $group): string
    {
        $secrets = [(string) $group->target];
        foreach (NotificationSettings::CREDENTIALS as $credentials) {
            foreach ($credentials as $key => $meta) {
                if ($meta['secret']) {
                    $secrets[] = NotificationSettings::credential($key);
                }
            }
        }

        foreach (array_filter($secrets, fn (string $secret) => strlen($secret) >= 6) as $secret) {
            $error = str_replace($secret, '***', $error);
        }

        return $error;
    }
}
