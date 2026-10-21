<?php

namespace App\Support;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who is behind an API request: a person (atgla- key) or a service account (atgsa- key).
 * Set by AuthenticatePatToken as the request attribute "identity".
 */
final class ApiIdentity
{
    public function __construct(
        public readonly ?User $user,
        public readonly ?ServiceAccount $serviceAccount,
        public readonly PatToken $token,
    ) {
    }

    public static function current(?Request $request = null): ?self
    {
        $request ??= app()->bound('request') ? app('request') : null;
        $identity = $request?->attributes->get('identity');

        return $identity instanceof self ? $identity : null;
    }

    public function isServiceAccount(): bool
    {
        return $this->serviceAccount !== null;
    }

    public function orgId(): int
    {
        return (int) ($this->isServiceAccount() ? $this->serviceAccount->org_id : ($this->user->org_id ?? 200));
    }

    public function label(): string
    {
        return $this->isServiceAccount()
            ? 'service account ' . $this->serviceAccount->name
            : (string) ($this->user->email ?? ('user ' . $this->user?->id));
    }
}
