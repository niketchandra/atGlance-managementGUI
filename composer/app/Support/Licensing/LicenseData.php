<?php

namespace App\Support\Licensing;

use DateTimeImmutable;

/**
 * The verified payload of a licence file (LICENSE_FORMAT.md, "Payload").
 */
final class LicenseData
{
    /**
     * @param list<string> $features
     */
    public function __construct(
        public readonly string $licenseId,
        public readonly string $product,
        public readonly string $keyId,
        public readonly string $customer,
        public readonly string $email,
        public readonly string $plan,
        public readonly int $seats,
        public readonly array $features,
        public readonly DateTimeImmutable $issuedAt,
        public readonly DateTimeImmutable $activateBefore,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $minVersion,
        public readonly ?string $installId,
    ) {
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
            'license_id' => $this->licenseId,
            'customer' => $this->customer,
            'email' => $this->email,
            'plan' => $this->plan,
            'seats' => $this->seats,
            'features' => $this->features,
            'issued_at' => Verifier::formatDate($this->issuedAt),
            'activate_before' => Verifier::formatDate($this->activateBefore),
            'expires_at' => Verifier::formatDate($this->expiresAt),
            'min_version' => $this->minVersion,
            'install_id' => $this->installId,
        ];
    }
}
