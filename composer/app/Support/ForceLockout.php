<?php

namespace App\Support;

use App\Models\ForceAttempt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Manages account lockout for force actions (deregister, reactivate).
 * Prevents brute force attacks by locking accounts after repeated failures.
 */
class ForceLockout
{
    private const FAILURE_THRESHOLD = 5;
    private const FAILURE_WINDOW_MINUTES = 15;
    private const INITIAL_LOCKOUT_MINUTES = 15;
    private const MAX_LOCKOUT_HOURS = 24;

    /**
     * Hash an email address for storage and lookups.
     */
    public static function hashEmail(string $email): string
    {
        return hash('sha256', strtolower($email));
    }

    /**
     * Check if an account is currently locked out.
     *
     * @return array{locked: bool, until: ?Carbon, retryAfter: ?string}
     */
    public static function checkLockout(?User $user, string $email): array
    {
        $emailHash = self::hashEmail($email);
        $now = now();

        // Check if user is explicitly locked
        if ($user && $user->force_locked_until && $user->force_locked_until->isFuture()) {
            $retryAfter = $user->force_locked_until->toRfc7231String();
            return [
                'locked' => true,
                'until' => $user->force_locked_until,
                'retryAfter' => $retryAfter,
            ];
        }

        // If user object exists but lock has expired, reset the level
        if ($user && $user->force_locked_until && $user->force_locked_until->isPast()) {
            if ($user->force_lock_level_at && $user->force_lock_level_at->addHours(24)->isPast()) {
                $user->update([
                    'force_lock_level' => 0,
                    'force_lock_level_at' => null,
                    'force_locked_until' => null,
                ]);
            }
        }

        // Count failures in the last 15 minutes for this email
        $recentFailures = ForceAttempt::where('email_hash', $emailHash)
            ->where('succeeded', false)
            ->where('created_at', '>=', $now->copy()->subMinutes(self::FAILURE_WINDOW_MINUTES))
            ->count();

        if ($recentFailures >= self::FAILURE_THRESHOLD) {
            // Trigger lockout if not already locked
            if (!$user || !$user->force_locked_until || $user->force_locked_until->isPast()) {
                self::applyLockout($user, $emailHash, $recentFailures);

                // Reload user to get updated lock times
                if ($user) {
                    $user = $user->fresh();
                }
            }

            // Return locked status (whether for known user or unknown email)
            if ($user && $user->force_locked_until && $user->force_locked_until->isFuture()) {
                $retryAfter = $user->force_locked_until->toRfc7231String();
                return [
                    'locked' => true,
                    'until' => $user->force_locked_until,
                    'retryAfter' => $retryAfter,
                ];
            } elseif (!$user) {
                // For unknown emails, lockout is enforced by the failure count alone
                // Calculate lockout duration based on attempt count
                $lockoutMinutes = self::INITIAL_LOCKOUT_MINUTES;
                $lockedUntil = $now->copy()->addMinutes($lockoutMinutes);
                $retryAfter = $lockedUntil->toRfc7231String();
                return [
                    'locked' => true,
                    'until' => $lockedUntil,
                    'retryAfter' => $retryAfter,
                ];
            }
        }

        return [
            'locked' => false,
            'until' => null,
            'retryAfter' => null,
        ];
    }

    /**
     * Record a failed attempt.
     */
    public static function recordFailure(?User $user, string $email, string $ip): void
    {
        $emailHash = self::hashEmail($email);

        ForceAttempt::create([
            'email_hash' => $emailHash,
            'user_id' => $user?->id,
            'ip' => $ip,
            'succeeded' => false,
        ]);

        // Record security event
        if ($user) {
            try {
                SecurityEvents::recordForceFailure($user->org_id, $user->id, $emailHash, $ip);
            } catch (\Throwable $e) {
                Log::warning('Failed to record security event for force failure', ['error' => $e->getMessage()]);
            }
        }

        // Check if this failure triggers a lockout
        $recentFailures = ForceAttempt::where('email_hash', $emailHash)
            ->where('succeeded', false)
            ->where('created_at', '>=', now()->subMinutes(self::FAILURE_WINDOW_MINUTES))
            ->count();

        if ($recentFailures >= self::FAILURE_THRESHOLD) {
            self::applyLockout($user, $emailHash, $recentFailures);
        }
    }

    /**
     * Record a successful attempt.
     */
    public static function recordSuccess(?User $user, string $email, string $ip): void
    {
        $emailHash = self::hashEmail($email);

        ForceAttempt::create([
            'email_hash' => $emailHash,
            'user_id' => $user?->id,
            'ip' => $ip,
            'succeeded' => true,
        ]);

        // Record security event
        if ($user) {
            try {
                SecurityEvents::recordForceSucceeded($user->org_id, $user->id, $ip);
            } catch (\Throwable $e) {
                Log::warning('Failed to record security event for force success', ['error' => $e->getMessage()]);
            }
        }

        // Clear the failure window for this email
        if ($user) {
            // We keep the lock level in case another failure happens, but we don't clear
            // it immediately - it will auto-reset after 24 hours of inactivity
        }
    }

    /**
     * Apply lockout to an account.
     */
    private static function applyLockout(?User $user, string $emailHash, int $attemptCount): void
    {
        if (!$user) {
            // For unknown emails, we track the lockout by email hash but can't store
            // it on a user record. The lockout is enforced by the failure count.
            return;
        }

        $now = now();

        // Calculate new lock level and duration
        $newLevel = ($user->force_lock_level ?? 0) + 1;
        $durationMinutes = self::INITIAL_LOCKOUT_MINUTES * (2 ** ($newLevel - 1));

        // Cap at 24 hours
        if ($durationMinutes > self::MAX_LOCKOUT_HOURS * 60) {
            $durationMinutes = self::MAX_LOCKOUT_HOURS * 60;
        }

        $lockedUntil = $now->copy()->addMinutes($durationMinutes);

        $user->update([
            'force_lock_level' => $newLevel,
            'force_lock_level_at' => $now,
            'force_locked_until' => $lockedUntil,
        ]);

        // Record the lock event
        ActivityRecorder::record(
            $user->id,
            'security.force_locked',
            sprintf(
                'Force action locked for %d minutes (level %d). %d failed attempts detected.',
                $durationMinutes,
                $newLevel,
                $attemptCount
            )
        );

        // Record security event
        try {
            SecurityEvents::recordForceLocked($user->org_id, $user->id, '', [
                'level' => $newLevel,
                'locked_for_minutes' => $durationMinutes,
                'attempt_count' => $attemptCount,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record security event for force lockout', ['error' => $e->getMessage()]);
        }

        // Notify org admins
        self::notifyOrgAdmins($user, $lockedUntil, $attemptCount);
    }

    /**
     * Unlock an account (admin action).
     */
    public static function unlock(User $user, ?User $actor = null): void
    {
        $wasLocked = $user->force_locked_until && $user->force_locked_until->isFuture();

        $user->update([
            'force_locked_until' => null,
        ]);

        if ($wasLocked) {
            // Record the unlock
            ActivityRecorder::record(
                $user->id,
                'security.force_unlocked',
                sprintf('Force action lock removed by %s', $actor?->name ?? 'system')
            );

            // Record security event
            try {
                SecurityEvents::recordForceUnlocked($user->org_id, $user->id, '', [
                    'actor_id' => $actor?->id,
                    'action' => 'admin_unlock',
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to record security event for force unlock', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Notify organization admins of a force lockout event.
     */
    private static function notifyOrgAdmins(User $user, Carbon $lockedUntil, int $attemptCount): void
    {
        try {
            $title = "Account locked: {$user->name}";
            $facts = [
                'User' => $user->name,
                'Email' => $user->email,
                'Locked until' => $lockedUntil->toDateTimeString(),
                'Failed attempts' => $attemptCount,
            ];

            app(\App\Services\Notifier::class)->notify(
                'security.force_lockout',
                null,
                $title,
                $facts,
                ['user_id' => $user->id, 'org_id' => $user->org_id]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send force lockout notification', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get the retry-after delay in seconds.
     */
    public static function getRetryAfterSeconds(?User $user, string $email): ?int
    {
        $lockout = self::checkLockout($user, $email);

        if ($lockout['locked'] && $lockout['until']) {
            return max(0, now()->diffInSeconds($lockout['until']));
        }

        return null;
    }
}
