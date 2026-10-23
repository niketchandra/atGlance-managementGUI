<?php

namespace App\Http\Middleware;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Support\ApiIdentity;
use App\Support\Licensing\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API key authentication. atgla- keys belong to a person; atgsa- keys to a service
 * account and are accepted only where the route opts in with auth.pat:service. A
 * failed check never falls back to another identity.
 */
class AuthenticatePatToken
{
    private const USER_PREFIX = 'atgla-';
    private const SERVICE_PREFIX = 'atgsa-';

    public function handle(Request $request, Closure $next, string $accepts = 'user'): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'PAT token required'], 401);
        }

        $isService = str_starts_with($token, self::SERVICE_PREFIX);
        if (!$isService && !str_starts_with($token, self::USER_PREFIX)) {
            return response()->json(['message' => 'Invalid token format. PAT token required (atgla-...)'], 403);
        }

        if ($isService && $accepts !== 'service') {
            return response()->json(['message' => 'Service account keys work only with the atglance CLI API (/api/v1)'], 401);
        }

        if ($isService && !Entitlements::isFeatureEnabled('service_accounts')) {
            return response()->json([
                'code' => Entitlements::ERROR_CODE,
                'message' => Entitlements::notLicensedMessage('service_accounts'),
            ], 403);
        }

        $patToken = PatToken::where('token', hash('sha256', $token))->first();
        $expectedType = $isService ? ServiceAccount::class : User::class;

        if (!$patToken || $patToken->tokenable_type !== $expectedType) {
            return response()->json(['message' => 'Invalid key'], 401);
        }

        if (($patToken->status ?? 'active') !== 'active' || $patToken->revoked_at !== null) {
            return response()->json(['message' => 'Key is revoked'], 401);
        }

        if ($patToken->expires_at && $patToken->expires_at->isPast()) {
            return response()->json(['message' => 'Key has expired'], 401);
        }

        $user = $isService ? null : User::query()->find($patToken->tokenable_id);
        $account = $isService ? ServiceAccount::query()->find($patToken->tokenable_id) : null;

        $active = $isService
            ? ($account?->isActive() ?? false)
            : ($user !== null && strtolower((string) ($user->status ?? 'active')) === 'active');
        if (!$active) {
            return response()->json(['message' => 'Identity is disabled'], 401);
        }

        $patToken->forceFill(['last_used_at' => now()])->save();

        // A service account has no user: routes that need a person refuse it.
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('patToken', $patToken);
        $request->attributes->set('identity', new ApiIdentity($user, $account, $patToken));

        return $next($request);
    }
}
