<?php

namespace App\Services;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CredentialStatus
{
    private const NOTICE_DAYS = [15, 7, 3, 2, 1, 0];
    private const MESSAGE_TTL_HOURS = 12;

    public function createMessage(PatToken $token): array
    {
        $daysLeft = $this->daysUntilExpiry($token);
        $noticeStage = $this->getNoticeStage($daysLeft);
        $credentialType = $this->credentialType($token);
        $owner = $this->ownerName($token);

        if ($daysLeft === null || $daysLeft > 15) {
            $messageData = [
                'status' => 'active',
                'days_left' => $daysLeft,
                'message' => "Your credential is valid.",
                'severity' => 'info',
                'credential_type' => $credentialType,
                'owner' => $owner,
            ];

            return $this->storeMessage($token, $messageData);
        }

        if ($daysLeft < 0) {
            $messageData = [
                'status' => 'expired',
                'days_left' => 0,
                'message' => "Your credential '{$token->name}' (…{$token->last_four}) has expired.",
                'severity' => 'error',
                'credential_type' => $credentialType,
                'owner' => $owner,
            ];

            return $this->storeMessage($token, $messageData);
        }

        $messageData = [
            'status' => 'expiring',
            'days_left' => $daysLeft,
            'notice_stage' => $noticeStage,
            'message' => "Your credential '{$token->name}' (…{$token->last_four}) expires in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . ".",
            'severity' => $this->severityForDaysLeft($daysLeft),
            'credential_type' => $credentialType,
            'owner' => $owner,
        ];

        return $this->storeMessage($token, $messageData);
    }

    private function storeMessage(PatToken $token, array $messageData): array
    {
        $userId = null;
        $serviceAccountId = null;

        if ($token->tokenable_type === ServiceAccount::class) {
            $serviceAccountId = $token->tokenable_id;
        } else {
            $userId = $token->user_id;
        }

        try {
            $inserted = DB::table('temporary_credential_messages')->insert([
                'pat_token_id' => $token->id,
                'user_id' => $userId,
                'service_account_id' => $serviceAccountId,
                'status' => $messageData['status'] ?? 'active',
                'days_left' => $messageData['days_left'],
                'notice_stage' => $messageData['notice_stage'] ?? null,
                'message' => $messageData['message'],
                'severity' => $messageData['severity'],
                'credential_type' => $messageData['credential_type'],
                'owner' => $messageData['owner'],
                'expires_at' => now()->addHours(self::MESSAGE_TTL_HOURS),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!$inserted) {
                \Log::warning('CredentialStatus::storeMessage failed to insert', [
                    'pat_token_id' => $token->id,
                    'user_id' => $userId,
                    'service_account_id' => $serviceAccountId,
                    'message_data' => $messageData,
                ]);
            }

            return [
                'queued' => true,
                'expires_in_hours' => self::MESSAGE_TTL_HOURS,
            ];
        } catch (\Exception $e) {
            \Log::error('CredentialStatus::storeMessage exception', [
                'pat_token_id' => $token->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'queued' => false,
                'error' => $e->getMessage(),
                'expires_in_hours' => self::MESSAGE_TTL_HOURS,
            ];
        }
    }

    private function daysUntilExpiry(PatToken $token): ?int
    {
        if (!$token->expires_at) {
            return null;
        }

        $now = now()->startOfDay();
        $expiresAt = $token->expires_at->copy()->startOfDay();

        if ($expiresAt < $now) {
            return -1;
        }

        return (int) $now->diffInDays($expiresAt);
    }

    private function getNoticeStage(?int $daysLeft): ?int
    {
        if ($daysLeft === null || $daysLeft > 15) {
            return null;
        }

        foreach (self::NOTICE_DAYS as $stage) {
            if ($daysLeft <= $stage) {
                return $stage;
            }
        }

        return null;
    }

    private function credentialType(PatToken $token): string
    {
        return $token->tokenable_type === ServiceAccount::class ? 'service_account' : 'personal_access_token';
    }

    private function ownerName(PatToken $token): string
    {
        if ($token->tokenable_type === ServiceAccount::class) {
            return $token->tokenable->name;
        }

        return $token->tokenable?->name ?? 'Unknown';
    }

    private function severityForDaysLeft(int $daysLeft): string
    {
        return match (true) {
            $daysLeft <= 1 => 'error',
            $daysLeft <= 3 => 'error',
            $daysLeft <= 7 => 'warn',
            default => 'info',
        };
    }
}
