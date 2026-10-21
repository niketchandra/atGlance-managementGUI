<?php

namespace App\Services;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Support\AccountAlerts;
use App\Support\ActivityRecorder;
use App\Support\ServiceAccountNotices;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Revokes every active atgla- key of a person who is deactivated, deleted or moved to
 * another organization. Rows are kept for audit; a revoked key never comes back. The
 * service accounts they owned keep running and lose their owner.
 */
class UserKeyRevoker
{
    public const REASON_DEACTIVATED = 'user_deactivated';
    public const REASON_DELETED = 'user_deleted';
    public const REASON_ORG_CHANGED = 'user_org_changed';

    public function revokeAll(User $user, string $reason): int
    {
        $count = PatToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->where('status', 'active')
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_by_user_id' => Auth::id(),
                'revoke_reason' => $reason,
            ]);

        if ($count > 0) {
            ActivityRecorder::record(Auth::id() ?? $user->id, 'user.keys_revoked', "Revoked {$count} API key(s) of {$user->email}: {$reason}");
            ServiceAccountNotices::userKeysRevoked($user, $count, $reason);
            // A deleted user has no inbox row left for the queued alert to read.
            if ($reason !== self::REASON_DELETED) {
                AccountAlerts::keysRevoked($user, $count);
            }
        }

        return $count;
    }

    /**
     * @return Collection<int, ServiceAccount>
     */
    public function releaseOwnedAccounts(User $user): Collection
    {
        $accounts = ServiceAccount::query()->where('owner_user_id', $user->id)->get();

        foreach ($accounts as $account) {
            $account->update(['owner_user_id' => null]);
            ActivityRecorder::record(Auth::id() ?? $user->id, 'service_account.owner_missing', 'Service account ' . $account->name . ' has no owner (' . $user->email . ' left)');
            ServiceAccountNotices::ownerMissing($account, $user);
        }

        return $accounts;
    }
}
