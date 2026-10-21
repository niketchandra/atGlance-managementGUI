<?php

namespace App\Support;

use App\Jobs\SendAccountAlert;
use App\Models\ActivityLog;
use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Decides whether an account event deserves an email to its owner, based on
 * the owner's alert preferences, and queues it.
 */
class AccountAlerts
{
    /**
     * Call before the sign-in is recorded, so "new device" compares against
     * earlier sign-ins only. The very first sign-in never alerts.
     */
    public static function signedIn(User $user, Request $request): void
    {
        if (!UserPreferences::wantsAlert($user, 'new_signin')) {
            return;
        }

        $device = UserAgent::describe($request->userAgent());
        $earlier = ActivityLog::query()
            ->where('user_id', $user->id)
            ->where('event', 'auth.login')
            ->where('outcome', ActivityRecorder::SUCCESS)
            ->where('created_at', '>=', now()->subDays(90))
            ->get(['ip_address', 'user_agent']);

        if ($earlier->isEmpty()) {
            return;
        }

        $known = $earlier->contains(fn (ActivityLog $row) => $row->ip_address === $request->ip()
            && UserAgent::describe($row->user_agent) === $device);

        if (!$known) {
            self::send($user, 'New sign-in to your account', 'Your account was signed in to from a browser or IP address we have not seen before.', [
                'Device' => $device,
                'IP address' => (string) $request->ip(),
                'Time' => now()->setTimezone(UserPreferences::timezone($user))->format('Y-m-d H:i T'),
            ]);
        }
    }

    public static function passwordChanged(User $user, Request $request): void
    {
        self::send($user, 'Your password was changed', 'The password for your account was just changed.', [
            'Device' => UserAgent::describe($request->userAgent()),
            'IP address' => (string) $request->ip(),
        ]);
    }

    public static function apiKey(User $user, string $action, string $keyName): void
    {
        if (UserPreferences::wantsAlert($user, 'api_keys')) {
            self::send($user, 'API key ' . $action, 'The API key "' . $keyName . '" was ' . $action . ' on your account.');
        }
    }

    public static function systemDeregistered(User $user, string $systemName): void
    {
        if (UserPreferences::wantsAlert($user, 'system_deregistered')) {
            self::send($user, 'System deregistered: ' . $systemName, 'Your system "' . $systemName . '" was deregistered and no longer sends backups.');
        }
    }

    public static function apiKeyExpiring(User $user, PatToken $key): void
    {
        if (UserPreferences::wantsAlert($user, 'api_keys')) {
            self::send($user, 'API key expires soon', 'Your API key "' . $key->name . '" expires on ' . $key->expires_at?->toDateString() . '. Create a new key and replace it before then.');
        }
    }

    public static function serviceAccountKeyExpiring(User $owner, ServiceAccount $account, PatToken $key): void
    {
        if (UserPreferences::wantsAlert($owner, 'api_keys')) {
            self::send($owner, 'Service account key expires soon: ' . $account->name, 'The key "' . $key->name . '" (…' . $key->last_four . ') of service account "' . $account->name . '", which you own, expires on ' . $key->expires_at?->toDateString() . '. Create a second key, move the servers to it, then revoke the old one.');
        }
    }

    public static function serviceAccountOwnerAssigned(User $owner, ServiceAccount $account): void
    {
        if (UserPreferences::wantsAlert($owner, 'api_keys')) {
            self::send($owner, 'You now own service account ' . $account->name, 'You are now the accountable owner of service account "' . $account->name . '". You will get its key expiry notices.');
        }
    }

    /**
     * Always sent: a security notice, like password changes.
     */
    public static function keysRevoked(User $user, int $count): void
    {
        self::send($user, 'Your API keys were revoked', "{$count} API key(s) on your account were revoked because your account was deactivated or moved. They will not work again; create new keys if your access is restored.");
    }

    private static function send(User $user, string $subject, string $message, array $details = []): void
    {
        if (!NotificationSettings::mailConfigured()) {
            return;
        }

        SendAccountAlert::dispatch((int) $user->id, $subject, $message, $details);
    }
}
