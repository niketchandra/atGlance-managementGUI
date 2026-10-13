<?php

namespace App\Support;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\NotificationEvents as E;
use App\Services\Notifier;

/**
 * Sends service account events to the right level: organization accounts to the
 * organization groups (and, for key events, every workspace they can act in);
 * workspace accounts to their workspace. Never includes a key, only its last 4.
 */
final class ServiceAccountNotices
{
    public static function accountChanged(ServiceAccount $account, string $what): void
    {
        $title = 'Service account ' . $what . ': ' . $account->name;
        $facts = self::facts($account);

        if ($account->isOrganizationScope()) {
            self::send($what === 'created' ? E::ORG_SERVICE_ACCOUNT_CREATED : E::ORG_SERVICE_ACCOUNT_DISABLED, null, $title, $facts);

            return;
        }

        self::send($what === 'created' ? E::SERVICE_ACCOUNT_CREATED : E::SERVICE_ACCOUNT_DISABLED, (int) $account->workspace_id, $title, $facts);
    }

    public static function keyEvent(ServiceAccount $account, PatToken $key, string $what): void
    {
        $title = match ($what) {
            'expiring' => 'Service account key expires soon: ' . $account->name,
            'expired' => 'Service account key expired: ' . $account->name,
            default => 'Service account key revoked: ' . $account->name,
        };
        $facts = self::facts($account) + [
            'Key' => $key->name . ' (…' . $key->last_four . ')',
            'Expires' => (string) $key->expires_at?->toDateString(),
        ];
        if ($what === 'revoked' && $key->revoke_reason) {
            $facts['Reason'] = (string) $key->revoke_reason;
        }

        [$orgEvent, $workspaceEvent] = match ($what) {
            'expiring' => [E::ORG_SERVICE_ACCOUNT_KEY_EXPIRING, E::SERVICE_ACCOUNT_KEY_EXPIRING],
            'expired' => [E::ORG_SERVICE_ACCOUNT_KEY_EXPIRED, E::SERVICE_ACCOUNT_KEY_EXPIRED],
            default => [E::ORG_SERVICE_ACCOUNT_KEY_REVOKED, E::SERVICE_ACCOUNT_KEY_REVOKED],
        };

        if ($account->isOrganizationScope()) {
            self::send($orgEvent, null, $title, $facts);
        }

        foreach ($account->grants()->where('status', 'active')->pluck('workspace_id') as $workspaceId) {
            self::send($workspaceEvent, (int) $workspaceId, $title, $facts);
        }

        if ($what === 'expiring' && $account->owner) {
            AccountAlerts::serviceAccountKeyExpiring($account->owner, $account, $key);
        }
    }

    public static function grantChanged(ServiceAccount $account, Workspace $workspace, bool $added): void
    {
        self::send(
            $added ? E::SERVICE_ACCOUNT_GRANT_ADDED : E::SERVICE_ACCOUNT_GRANT_REMOVED,
            (int) $workspace->id,
            ($added ? 'Service account given access: ' : 'Service account access removed: ') . $account->name,
            self::facts($account),
        );
    }

    public static function systemAssigned(SystemRegister $system, ServiceAccount $account): void
    {
        self::send(E::SYSTEM_ASSIGNED_TO_SERVICE_ACCOUNT, (int) $system->workspace_id, 'System moved to service account: ' . $system->system_name, [
            'System' => (string) $system->system_name,
            'Service account' => $account->name,
        ] + $system->actorFacts());
    }

    public static function ownerMissing(ServiceAccount $account, User $formerOwner): void
    {
        self::send(E::ORG_SERVICE_ACCOUNT_OWNER_MISSING, null, 'Service account has no owner: ' . $account->name, self::facts($account) + [
            'Former owner' => (string) $formerOwner->email,
        ]);
    }

    public static function userKeysRevoked(User $user, int $count, string $reason): void
    {
        self::send(E::ORG_USER_KEYS_REVOKED, null, 'API keys revoked for ' . $user->email, [
            'User' => (string) $user->email,
            'Keys revoked' => (string) $count,
            'Reason' => $reason,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function facts(ServiceAccount $account): array
    {
        return array_filter([
            'Service account' => $account->name,
            'Scope' => $account->scope,
            'Workspace' => (string) $account->workspace?->name,
            'Owner' => (string) ($account->owner?->email ?? 'none'),
            'Team' => (string) $account->team,
        ], fn (string $value) => $value !== '');
    }

    private static function send(string $event, ?int $workspaceId, string $title, array $facts): void
    {
        app(Notifier::class)->notify($event, $workspaceId, $title, $facts);
    }
}
