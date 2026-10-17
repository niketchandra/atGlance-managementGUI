<?php

namespace App\Services;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Models\User;
use App\Support\ActivityRecorder;
use App\Support\ServiceAccountNotices;
use App\Support\ServiceAccountSettings;

/**
 * Expiry notices for person and service account keys on each notice day (default 15,
 * 7, 3, 2, 1 and 0 days before, in calendar days), and one "expired" notice per
 * service account key. The daily sweep and the CLI's expiry-notice call share
 * notifyIfDue(), and the stored stage makes every notice go out once.
 */
class CredentialSweep
{
    /**
     * @return array{expiring: int, expired: int}
     */
    public function run(): array
    {
        $expiring = 0;
        $windows = ServiceAccountSettings::noticeDays();

        if ($windows !== []) {
            $keys = PatToken::query()
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->startOfDay()->addDays($windows[0] + 1))
                ->get();

            foreach ($keys as $key) {
                if ($this->notifyIfDue($key)['sent']) {
                    $expiring++;
                }
            }
        }

        $expired = 0;
        $done = PatToken::query()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereNull('expired_notified_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($done as $key) {
            if ($key->isServiceAccountKey() && ($account = ServiceAccount::find($key->tokenable_id))) {
                ServiceAccountNotices::keyEvent($account, $key, 'expired');
                ActivityRecorder::record(null, 'service_account.key_expired', 'Key "' . $key->name . '" (…' . $key->last_four . ') of ' . $account->name . ' expired');
            }
            $key->forceFill(['expired_notified_at' => now()])->save();
            $expired++;
        }

        return ['expiring' => $expiring, 'expired' => $expired];
    }

    /**
     * Calendar days until the key expires: 0 on the expiry day itself.
     */
    public static function daysLeft(PatToken $key): ?int
    {
        if ($key->expires_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($key->expires_at->copy()->startOfDay(), false);
    }

    /**
     * The notice day the key is in (smallest notice day >= days left), or null when it is
     * not expiring yet.
     */
    public static function stageFor(PatToken $key): ?int
    {
        $daysLeft = self::daysLeft($key);
        if ($daysLeft === null || $daysLeft < 0) {
            return null;
        }

        $covering = array_filter(ServiceAccountSettings::noticeDays(), fn (int $window) => $daysLeft <= $window);

        return $covering === [] ? null : min($covering);
    }

    /**
     * Sends the notices for the key's current notice day unless already sent.
     *
     * @return array{sent: bool, stage: ?int, reason: ?string}
     */
    public function notifyIfDue(PatToken $key, ?SystemRegister $system = null): array
    {
        $stage = self::stageFor($key);
        if ($stage === null) {
            return ['sent' => false, 'stage' => null, 'reason' => 'not_expiring'];
        }

        // Atomic claim of the stage, so the sweep and a CLI call cannot both send it.
        $claimed = PatToken::query()
            ->whereKey($key->id)
            ->where(fn ($query) => $query->whereNull('expiry_notified_stage')->orWhere('expiry_notified_stage', '>', $stage))
            ->update(['expiry_notified_stage' => $stage]);
        if ($claimed === 0) {
            return ['sent' => false, 'stage' => $stage, 'reason' => 'already_sent'];
        }
        $key->expiry_notified_stage = $stage;

        if ($key->isServiceAccountKey()) {
            if ($account = ServiceAccount::find($key->tokenable_id)) {
                ServiceAccountNotices::keyEvent($account, $key, 'expiring', $system);
            }
        } elseif ($user = User::find($key->tokenable_id)) {
            ServiceAccountNotices::userKeyExpiring($user, $key, $system);
        }

        return ['sent' => true, 'stage' => $stage, 'reason' => null];
    }
}
