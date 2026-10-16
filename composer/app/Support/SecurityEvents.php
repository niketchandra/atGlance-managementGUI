<?php

namespace App\Support;

use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Helper for recording security events.
 * Writing never breaks the request: failures are logged and swallowed.
 */
class SecurityEvents
{
    /**
     * Record a security event.
     *
     * @param string $type Event type (one of SecurityEvent::TYPE_* constants)
     * @param int $orgId Organization ID
     * @param array $data Event data including: user_id, email_hash, ip, details
     */
    public static function record(string $type, int $orgId, array $data = []): void
    {
        try {
            SecurityEvent::create([
                'type' => $type,
                'org_id' => $orgId,
                'user_id' => $data['user_id'] ?? null,
                'email_hash' => $data['email_hash'] ?? null,
                'ip' => $data['ip'] ?? null,
                'details' => $data['details'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Security event not recorded', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Record a force action failure.
     */
    public static function recordForceFailure(int $orgId, ?int $userId, ?string $emailHash, string $ip): void
    {
        self::record(SecurityEvent::TYPE_FORCE_FAILED, $orgId, [
            'user_id' => $userId,
            'email_hash' => $emailHash,
            'ip' => $ip,
        ]);
    }

    /**
     * Record a force action lockout.
     */
    public static function recordForceLocked(int $orgId, int $userId, string $ip, array $details = []): void
    {
        self::record(SecurityEvent::TYPE_FORCE_LOCKED, $orgId, [
            'user_id' => $userId,
            'ip' => $ip,
            'details' => $details,
        ]);
    }

    /**
     * Record a force action unlock.
     */
    public static function recordForceUnlocked(int $orgId, int $userId, string $ip, array $details = []): void
    {
        self::record(SecurityEvent::TYPE_FORCE_UNLOCKED, $orgId, [
            'user_id' => $userId,
            'ip' => $ip,
            'details' => $details,
        ]);
    }

    /**
     * Record a successful force action.
     */
    public static function recordForceSucceeded(int $orgId, int $userId, string $ip): void
    {
        self::record(SecurityEvent::TYPE_FORCE_SUCCEEDED, $orgId, [
            'user_id' => $userId,
            'ip' => $ip,
        ]);
    }

    /**
     * Record a key expiry being used.
     */
    public static function recordKeyExpiredUsed(int $orgId, ?int $userId, string $ip, array $details = []): void
    {
        self::record(SecurityEvent::TYPE_KEY_EXPIRED_USED, $orgId, [
            'user_id' => $userId,
            'ip' => $ip,
            'details' => $details,
        ]);
    }

    /**
     * Record system key rotation.
     */
    public static function recordSystemKeyRotated(int $orgId, int $userId, int $systemId, string $ip): void
    {
        self::record(SecurityEvent::TYPE_SYSTEM_KEY_ROTATED, $orgId, [
            'user_id' => $userId,
            'ip' => $ip,
            'details' => ['system_id' => $systemId],
        ]);
    }

    /**
     * Record that a system's service account was attached, replaced or detached.
     * Ids only: never a key.
     */
    public static function recordSystemServiceAccountChanged(int $orgId, int $userId, int $systemId, ?int $oldAccountId, ?int $newAccountId, ?string $ip): void
    {
        self::record(SecurityEvent::TYPE_SYSTEM_SERVICE_ACCOUNT_CHANGED, $orgId, [
            'user_id' => $userId,
            'ip' => $ip,
            'details' => [
                'system_id' => $systemId,
                'old_service_account_id' => $oldAccountId,
                'new_service_account_id' => $newAccountId,
            ],
        ]);
    }

    /**
     * Record a legacy API usage event. The security_events.org_id column is not nullable,
     * so a call whose organization cannot be resolved is not recorded.
     */
    public static function recordLegacyApiUsed(?int $orgId, ?int $userId, ?string $ip, array $details = []): void
    {
        if ($orgId === null) {
            return;
        }

        self::record(SecurityEvent::TYPE_LEGACY_API_USED, $orgId, [
            'user_id' => $userId,
            'ip' => $ip,
            'details' => $details,
        ]);
    }
}
