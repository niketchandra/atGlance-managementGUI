<?php

namespace App\Support\Licensing;

use DateTimeImmutable;

/**
 * The verified payload of a licence file (LICENSE_FORMAT.md, "Payload"), v1 or v2. For a v1
 * payload the limits and features hold the v1 defaults from the contract.
 */
final class LicenseData
{
    /**
     * @param list<string> $features enabled catalog keys this console knows, sorted
     */
    public function __construct(
        public readonly string $licenseId,
        public readonly string $product,
        public readonly string $keyId,
        public readonly string $customer,
        public readonly string $email,
        public readonly string $plan,
        public readonly array $features,
        public readonly DateTimeImmutable $issuedAt,
        public readonly DateTimeImmutable $activateBefore,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $minVersion,
        public readonly ?string $installId,
        public readonly int $formatVersion = 1,
        /** 0 = unlimited */
        public readonly int $maxNodes = 0,
        /** 0 = unlimited */
        public readonly int $maxUsers = 0,
        public readonly string $policyId = 'v1',
        public readonly string $policyType = 'default',
        public readonly ?int $validityMonths = null,
    ) {
    }

    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->features, true);
    }

    /** Whole days from $now until expiry (negative once expired). */
    public function daysLeft(DateTimeImmutable $now): int
    {
        return (int) floor(($this->expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format_version' => $this->formatVersion,
            'license_id' => $this->licenseId,
            'customer' => $this->customer,
            'email' => $this->email,
            'plan' => $this->plan,
            'max_nodes' => $this->maxNodes,
            'max_users' => $this->maxUsers,
            'policy_id' => $this->policyId,
            'policy_type' => $this->policyType,
            'validity_months' => $this->validityMonths,
            'features' => $this->features,
            'issued_at' => Verifier::formatDate($this->issuedAt),
            'activate_before' => Verifier::formatDate($this->activateBefore),
            'expires_at' => Verifier::formatDate($this->expiresAt),
            'min_version' => $this->minVersion,
            'install_id' => $this->installId,
        ];
    }
}
