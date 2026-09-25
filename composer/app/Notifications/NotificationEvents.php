<?php

namespace App\Notifications;

/**
 * Events that notification groups can subscribe to.
 *
 * Workspace events go to the groups of the workspace the event belongs to.
 * Organization events go to organization-level groups (super admin only).
 * To add an event (e.g. service heartbeat up/down), add an entry here and
 * call Notifier::notify() where it happens.
 */
final class NotificationEvents
{
    public const SCOPE_WORKSPACE = 'workspace';
    public const SCOPE_ORGANIZATION = 'organization';

    public const SYSTEM_REGISTERED = 'system.registered';
    public const SYSTEM_DEREGISTERED = 'system.deregistered';
    public const BACKUP_SUCCEEDED = 'backup.succeeded';
    public const BACKUP_FAILED = 'backup.failed';

    private const EVENTS = [
        self::SYSTEM_REGISTERED => ['label' => 'System registered', 'scope' => self::SCOPE_WORKSPACE],
        self::SYSTEM_DEREGISTERED => ['label' => 'System deregistered', 'scope' => self::SCOPE_WORKSPACE],
        self::BACKUP_SUCCEEDED => ['label' => 'Scheduled backup succeeded', 'scope' => self::SCOPE_ORGANIZATION],
        self::BACKUP_FAILED => ['label' => 'Scheduled backup failed', 'scope' => self::SCOPE_ORGANIZATION],
    ];

    /**
     * @return array<string, string> event key => label
     */
    public static function forScope(string $scope): array
    {
        return collect(self::EVENTS)
            ->filter(fn (array $event) => $event['scope'] === $scope)
            ->map(fn (array $event) => $event['label'])
            ->all();
    }

    public static function label(string $event): string
    {
        return self::EVENTS[$event]['label'] ?? $event;
    }

    public static function scope(string $event): ?string
    {
        return self::EVENTS[$event]['scope'] ?? null;
    }
}
