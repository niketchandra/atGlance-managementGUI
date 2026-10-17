<?php

namespace App\Support;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The service account key sent next to a user key when registering a system
 * (X-Atglance-Service-Account-Key). Same checks as AuthenticatePatToken; any failure,
 * including an account of another organization, is the same 401.
 */
final class ServiceAccountKeyHeader
{
    public const HEADER = 'X-Atglance-Service-Account-Key';

    /**
     * @return array{0: ?ServiceAccount, 1: ?JsonResponse} [null, null] when the header is absent
     */
    public static function resolve(Request $request, int $orgId): array
    {
        $plain = trim((string) $request->header(self::HEADER, ''));
        if ($plain === '') {
            return [null, null];
        }

        $invalid = [null, response()->json(['message' => 'Invalid service account key'], 401)];
        if (!str_starts_with($plain, 'atgsa-')) {
            return $invalid;
        }

        $token = PatToken::query()->where('token', hash('sha256', $plain))->first();
        if (
            !$token
            || $token->tokenable_type !== ServiceAccount::class
            || $token->status !== 'active'
            || $token->revoked_at !== null
            || ($token->expires_at && $token->expires_at->isPast())
        ) {
            return $invalid;
        }

        $account = ServiceAccount::query()->find($token->tokenable_id);
        if (!$account || !$account->isActive() || (int) $account->org_id !== $orgId) {
            return $invalid;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return [$account, null];
    }
}
