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
    public const SYSTEM_REACTIVATED = 'system.reactivated';
    public const SYSTEM_ASSIGNED_TO_SERVICE_ACCOUNT = 'system.assigned_to_service_account';
    public const SERVICE_ACCOUNT_CREATED = 'service_account.created';
    public const SERVICE_ACCOUNT_DISABLED = 'service_account.disabled';
    public const SERVICE_ACCOUNT_GRANT_ADDED = 'service_account.grant_added';
    public const SERVICE_ACCOUNT_GRANT_REMOVED = 'service_account.grant_removed';
    public const SERVICE_ACCOUNT_KEY_EXPIRING = 'service_account.key_expiring';
    public const SERVICE_ACCOUNT_KEY_EXPIRED = 'service_account.key_expired';
    public const SERVICE_ACCOUNT_KEY_REVOKED = 'service_account.key_revoked';
    public const USER_KEY_EXPIRING = 'api_key.expiring';
    // One key maps to one scope, so organization-level service account events get their own keys.
    public const ORG_SERVICE_ACCOUNT_CREATED = 'organization.service_account_created';
    public const ORG_SERVICE_ACCOUNT_DISABLED = 'organization.service_account_disabled';
    public const ORG_SERVICE_ACCOUNT_KEY_EXPIRING = 'organization.service_account_key_expiring';
    public const ORG_SERVICE_ACCOUNT_KEY_EXPIRED = 'organization.service_account_key_expired';
    public const ORG_SERVICE_ACCOUNT_KEY_REVOKED = 'organization.service_account_key_revoked';
    public const ORG_SERVICE_ACCOUNT_OWNER_MISSING = 'organization.service_account_owner_missing';
    public const ORG_USER_KEYS_REVOKED = 'organization.user_keys_revoked';
    public const ORG_USER_KEY_EXPIRING = 'organization.user_key_expiring';

    private const EVENTS = [
        self::SYSTEM_REGISTERED => ['label' => 'System registered', 'scope' => self::SCOPE_WORKSPACE],
        self::SYSTEM_DEREGISTERED => ['label' => 'System deregistered', 'scope' => self::SCOPE_WORKSPACE],
        self::SYSTEM_REACTIVATED => ['label' => 'System reactivated', 'scope' => self::SCOPE_WORKSPACE],
        self::SYSTEM_ASSIGNED_TO_SERVICE_ACCOUNT => ['label' => 'System moved to a service account', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_CREATED => ['label' => 'Service account created', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_DISABLED => ['label' => 'Service account disabled', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_GRANT_ADDED => ['label' => 'Organization service account given access', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_GRANT_REMOVED => ['label' => 'Organization service account access removed', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_KEY_EXPIRING => ['label' => 'Service account key expires soon', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_KEY_EXPIRED => ['label' => 'Service account key expired', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_KEY_REVOKED => ['label' => 'Service account key revoked', 'scope' => self::SCOPE_WORKSPACE],
        self::USER_KEY_EXPIRING => ['label' => "A user's API key used by systems here expires soon", 'scope' => self::SCOPE_WORKSPACE],
        self::CONFIG_UPLOADED => ['label' => 'Config backup uploaded', 'scope' => self::SCOPE_WORKSPACE],
        self::CONFIG_UPLOAD_FAILED => ['label' => 'Config backup upload failed', 'scope' => self::SCOPE_WORKSPACE],
        self::AI_ISSUES_FOUND => ['label' => 'AI check found errors or warnings', 'scope' => self::SCOPE_WORKSPACE],
        self::WORKSPACE_BACKUP_SUCCEEDED => ['label' => 'Workspace backup succeeded', 'scope' => self::SCOPE_WORKSPACE],
        self::WORKSPACE_BACKUP_FAILED => ['label' => 'Workspace backup failed', 'scope' => self::SCOPE_WORKSPACE],
        self::WORKSPACE_MEMBER_ADDED => ['label' => 'Member added to workspace', 'scope' => self::SCOPE_WORKSPACE],
        self::WORKSPACE_MEMBER_REMOVED => ['label' => 'Member removed from workspace', 'scope' => self::SCOPE_WORKSPACE],
        self::BACKUP_SUCCEEDED => ['label' => 'Scheduled backup succeeded', 'scope' => self::SCOPE_ORGANIZATION],
        self::BACKUP_FAILED => ['label' => 'Scheduled backup failed', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_SERVICE_ACCOUNT_CREATED => ['label' => 'Organization service account created', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_SERVICE_ACCOUNT_DISABLED => ['label' => 'Organization service account disabled', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_SERVICE_ACCOUNT_KEY_EXPIRING => ['label' => 'Organization service account key expires soon', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_SERVICE_ACCOUNT_KEY_EXPIRED => ['label' => 'Organization service account key expired', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_SERVICE_ACCOUNT_KEY_REVOKED => ['label' => 'Organization service account key revoked', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_SERVICE_ACCOUNT_OWNER_MISSING => ['label' => 'Service account has no owner', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_USER_KEYS_REVOKED => ['label' => "A user's API keys were revoked", 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_USER_KEY_EXPIRING => ['label' => "A user's API key expires soon", 'scope' => self::SCOPE_ORGANIZATION],
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
