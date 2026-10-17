<?php

namespace App\Services;

use App\Models\PatToken;
use App\Models\User;
use App\Support\ServiceAccountSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Issues and revokes a person's atgla- keys (console and CLI login). Hash only, an
 * expiry is required, at most patUserQuota() active keys; a new key with the name of
 * an active key replaces it, so a CLI logging in again from the same host never
 * reaches the limit.
 */
class UserKeys
{
    public const LIMIT = 409;
    public const INVALID = 422;

    /**
     * @return array{token: PatToken, plain: string}
     */
    public function issue(User $user, string $name, ?CarbonInterface $expiresAt): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ServiceAccountException('The key name must be 1 to 100 characters.', self::INVALID);
        }

        $max = ServiceAccountSettings::patMaxKeyDays();
        $expiresAt ??= now()->addDays(ServiceAccountSettings::patDefaultKeyDays())->endOfDay();
        if ($expiresAt->isPast() || $expiresAt->greaterThan(now()->addDays($max)->endOfDay())) {
            throw new ServiceAccountException("The expiry date must be within the next {$max} days.", self::INVALID);
        }

        return DB::transaction(function () use ($user, $name, $expiresAt) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $active = PatToken::query()
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->id)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));

            // Same name: the new key replaces the old one. Rolled back with the
            // transaction if the limit check below refuses the new key.
            foreach ((clone $active)->where('name', $name)->get() as $sameName) {
                $this->revoke($sameName, $user, 'replaced');
            }

            $quota = ServiceAccountSettings::patUserQuota();
            if ((clone $active)->count() >= $quota) {
                throw new ServiceAccountException("You have {$quota} active API keys. Revoke one or reuse a key name.", self::LIMIT);
            }

            $plain = PatToken::generateCustomToken();
            $token = PatToken::create([
                'tokenable_type' => User::class,
                'tokenable_id' => $user->id,
                'user_id' => $user->id,
                'name' => $name,
                'token' => hash('sha256', $plain),
                'token_encrypted' => null,
                'last_four' => substr($plain, -4),
                'abilities' => ['*'],
                'status' => 'active',
                'expires_at' => $expiresAt,
            ]);

            return ['token' => $token, 'plain' => $plain];
        });
    }

    public function revoke(PatToken $key, User $actor, string $reason): void
    {
        $key->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor->id,
            'revoke_reason' => mb_substr($reason, 0, 50),
        ])->save();
    }
}
