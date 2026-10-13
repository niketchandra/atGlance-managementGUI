<?php

namespace App\Services;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Support\AccountAlerts;
use App\Support\ActivityRecorder;
use App\Support\ServiceAccountNotices;
use App\Support\ServiceAccountSettings;

/**
 * Daily: expiry notices for person and service account keys at each notice day
 * (default 14 and 3 days before), and one "expired" notice per key. The stored
 * stage makes reruns send nothing new.
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
                ->where('expires_at', '<=', now()->addDays($windows[0]))
                ->get();

            foreach ($keys as $key) {
                $daysLeft = (int) ceil(now()->diffInHours($key->expires_at) / 24);
                $stage = $this->smallestWindowCovering($windows, $daysLeft);
                if ($stage === null || ($key->expiry_notified_stage !== null && (int) $key->expiry_notified_stage <= $stage)) {
                    continue;
                }

                $this->notifyExpiring($key);
                $key->forceFill(['expiry_notified_stage' => $stage])->save();
                $expiring++;
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
     * @param list<int> $windows largest first
     */
    private function smallestWindowCovering(array $windows, int $daysLeft): ?int
    {
        $covering = array_filter($windows, fn (int $window) => $daysLeft <= $window);

        return $covering === [] ? null : min($covering);
    }

    private function notifyExpiring(PatToken $key): void
    {
        if ($key->isServiceAccountKey()) {
            if ($account = ServiceAccount::find($key->tokenable_id)) {
                ServiceAccountNotices::keyEvent($account, $key, 'expiring');
            }

            return;
        }

        if ($user = User::find($key->tokenable_id)) {
            AccountAlerts::apiKeyExpiring($user, $key);
        }
    }
}
