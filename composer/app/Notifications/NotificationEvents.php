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
    public const CONFIG_UPLOADED = 'config.uploaded';
    public const CONFIG_UPLOAD_FAILED = 'config.upload_failed';
    public const AI_ISSUES_FOUND = 'ai.issues_found';
    public const WORKSPACE_BACKUP_SUCCEEDED = 'workspace.backup_succeeded';
    public const WORKSPACE_BACKUP_FAILED = 'workspace.backup_failed';
    public const WORKSPACE_MEMBER_ADDED = 'workspace.member_added';
    public const WORKSPACE_MEMBER_REMOVED = 'workspace.member_removed';

    private const EVENTS = [
        self::SYSTEM_REGISTERED => ['label' => 'System registered', 'scope' => self::SCOPE_WORKSPACE],
        self::SYSTEM_DEREGISTERED => ['label' => 'System deregistered', 'scope' => self::SCOPE_WORKSPACE],
        self::CONFIG_UPLOADED => ['label' => 'Config backup uploaded', 'scope' => self::SCOPE_WORKSPACE],
        self::CONFIG_UPLOAD_FAILED => ['label' => 'Config backup upload failed', 'scope' => self::SCOPE_WORKSPACE],
        self::AI_ISSUES_FOUND => ['label' => 'AI check found errors or warnings', 'scope' => self::SCOPE_WORKSPACE],
        self::WORKSPACE_BACKUP_SUCCEEDED => ['label' => 'Workspace backup succeeded', 'scope' => self::SCOPE_WORKSPACE],
        self::WORKSPACE_BACKUP_FAILED => ['label' => 'Workspace backup failed', 'scope' => self::SCOPE_WORKSPACE],
        self::WORKSPACE_MEMBER_ADDED => ['label' => 'Member added to workspace', 'scope' => self::SCOPE_WORKSPACE],
        self::WORKSPACE_MEMBER_REMOVED => ['label' => 'Member removed from workspace', 'scope' => self::SCOPE_WORKSPACE],
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
