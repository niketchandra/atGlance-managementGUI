<?php

namespace App\Services;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;

class CredentialStatus
{
    private const NOTICE_DAYS = [15, 7, 3, 2, 1, 0];

    public function getMessage(PatToken $token): array
    {
        $daysLeft = $this->daysUntilExpiry($token);
        $noticeStage = $this->getNoticeStage($daysLeft);

        if ($daysLeft === null || $daysLeft > 15) {
            return [
                'status' => 'active',
                'days_left' => $daysLeft,
                'message' => "Your credential is valid.",
                'severity' => 'info',
                'credential_type' => $this->credentialType($token),
                'owner' => $this->ownerName($token),
            ];
        }

        if ($daysLeft < 0) {
            return [
                'status' => 'expired',
                'days_left' => 0,
                'message' => "Your credential '{$token->name}' (…{$token->last_four}) has expired.",
                'severity' => 'error',
                'credential_type' => $this->credentialType($token),
                'owner' => $this->ownerName($token),
            ];
        }

        return [
            'status' => 'expiring',
            'days_left' => $daysLeft,
            'notice_stage' => $noticeStage,
            'message' => "Your credential '{$token->name}' (…{$token->last_four}) expires in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . ".",
            'severity' => $this->severityForDaysLeft($daysLeft),
            'credential_type' => $this->credentialType($token),
            'owner' => $this->ownerName($token),
        ];
    }

    private function daysUntilExpiry(PatToken $token): ?int
    {
        if (!$token->expires_at) {
            return null;
        }

        $now = now();
        if ($token->expires_at < $now) {
            return -1;
        }

        return (int) $now->diffInDays($token->expires_at);
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
