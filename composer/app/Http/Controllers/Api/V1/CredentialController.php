<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSystemKey;
use App\Models\SystemRegister;
use App\Services\CredentialStatus;
use App\Services\CredentialSweep;
use App\Support\ApiIdentity;
use App\Support\ServiceAccountSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/credentials for the atglance CLI: how long the calling key (person or service
 * account) has left, and a way to send its expiry notices now. The system headers are
 * optional and, when sent, name the machine in the notices.
 */
class CredentialController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        [$system, $denied] = $this->optionalSystem($request);
        if ($denied) {
            return $denied;
        }

        $identity = ApiIdentity::current($request);
        $key = $identity->token;
        $daysLeft = CredentialSweep::daysLeft($key);
        $windows = ServiceAccountSettings::noticeDays();

        return response()->json([
            'identity_type' => $identity->isServiceAccount() ? 'service_account' : 'user',
            'key' => [
                'id' => $key->id,
                'name' => $key->name,
                'last_four' => $key->last_four,
                'expires_at' => $key->expires_at?->toIso8601String(),
                'days_left' => $daysLeft,
                'state' => $daysLeft !== null && $windows !== [] && $daysLeft <= $windows[0] ? 'expiring' : 'active',
            ],
            'notice_days' => $windows,
            'system' => $system ? [
                'id' => $system->id,
                'name' => $system->system_name,
                'workspace_id' => (int) $system->workspace_id,
            ] : null,
        ]);
    }

    public function expiryNotice(Request $request, CredentialStatus $status): JsonResponse
    {
        [$system, $denied] = $this->optionalSystem($request);
        if ($denied) {
            return $denied;
        }

        $token = ApiIdentity::current($request)->token;
        $result = $status->createMessage($token);

        return response()->json($result);
    }

    public function messages(Request $request): JsonResponse
    {
        $identity = ApiIdentity::current($request);
        $messages = \DB::table('temporary_credential_messages')
            ->where(function ($q) use ($identity) {
                if ($identity->isServiceAccount()) {
                    $q->where('service_account_id', $identity->serviceAccount->id);
                } else {
                    $q->where('user_id', $identity->user->id);
                }
            })
            ->where('expires_at', '>', now())
            ->where('status', '!=', 'read')
            ->orderByDesc('created_at')
            ->get(['id', 'days_left', 'notice_stage', 'message', 'severity', 'credential_type', 'owner', 'created_at', 'expires_at']);

        return response()->json([
            'messages' => $messages,
            'count' => $messages->count(),
        ]);
    }

    public function markMessageRead(Request $request, int $messageId): JsonResponse
    {
        $identity = ApiIdentity::current($request);
        $message = \DB::table('temporary_credential_messages')->find($messageId);

        if (!$message) {
            return response()->json(['message' => 'Message not found'], 404);
        }

        $authorized = ($message->user_id && $message->user_id === $identity->user?->id)
            || ($message->service_account_id && $message->service_account_id === $identity->serviceAccount?->id);

        if (!$authorized) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        \DB::table('temporary_credential_messages')->where('id', $messageId)->update(['status' => 'read']);

        return response()->json(['marked_read' => true]);
    }

    /**
     * The calling system when X-Atglance-System-Id/-Key are sent: same rules as the
     * atglance.system middleware, and the same 404 when they do not match.
     *
     * @return array{0: ?SystemRegister, 1: ?JsonResponse}
     */
    private function optionalSystem(Request $request): array
    {
        $systemId = trim((string) $request->header('X-Atglance-System-Id', ''));
        $key = (string) $request->header('X-Atglance-System-Key', '');
        if ($systemId === '' && $key === '') {
            return [null, null];
        }

        $identity = ApiIdentity::current($request);
        $system = preg_match('/^[0-9]{1,18}$/', $systemId) ? SystemRegister::find((int) $systemId) : null;

        $allowed = $system !== null
            && !empty($system->validation_hash)
            && hash_equals((string) $system->validation_hash, $key)
            && (int) ($system->org_id ?? 200) === $identity->orgId()
            && ($identity->isServiceAccount()
                ? AuthenticateSystemKey::accountMayUseSystem($identity->serviceAccount, $system)
                : AuthenticateSystemKey::userMayUseSystem($identity->user, $system));

        return $allowed ? [$system, null] : [null, response()->json(['message' => 'System not found'], 404)];
    }
}
