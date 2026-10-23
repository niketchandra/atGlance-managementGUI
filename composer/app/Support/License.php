<?php

namespace App\Support;

use App\Models\User;
use App\Support\Licensing\LicenseData;
use App\Support\Licensing\LicenseException;
use App\Support\Licensing\LicenseStore;
use App\Support\Licensing\Verifier;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The instance licence: an offline .atgl file from atglance.live (docs/LICENSING.md).
 *
 * The file is verified with the public keys built into the console; nothing is sent over
 * the network. It is uploaded in the installer or in Admin Settings > Licence (both within
 * 3 hours of download), then re-verified on every request without the 3-hour rule.
 *
 * Without an active licence the console is locked: no user or API key can be created, and
 * every write except the licence upload is refused (EnforceLicense middleware).
 */
class License
{
    public const REQUIRED_MESSAGE = 'A valid AtGlance licence is required. An admin must upload the licence file in Admin Settings > Licence.';

    public const NONE = 'none';
    public const ACTIVE = 'active';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    /** @var array{state: string, license: ?LicenseData, error: ?string, activated_at: ?int, clock_warning: bool}|null */
    private static ?array $status = null;

    private static int $statusAt = 0;

    /**
     * Current licence state, recomputed at most once a minute (reset() clears it).
     *
     * @return array{state: string, license: ?LicenseData, error: ?string, activated_at: ?int, clock_warning: bool}
     */
    public static function status(): array
    {
        $now = self::now();
        if (self::$status !== null && abs($now->getTimestamp() - self::$statusAt) < 60) {
            return self::$status;
        }

        $store = LicenseStore::make();
        $clockWarning = $store->clockRolledBack($now->getTimestamp(), (int) config('licensing.clock_rollback_seconds', 86400));
        if (!$clockWarning) {
            try {
                $store->touchClock($now->getTimestamp());
            } catch (\Throwable) {
                // A read-only storage volume must not break the request.
            }
        }

        $status = ['state' => self::NONE, 'license' => null, 'error' => null, 'activated_at' => null, 'clock_warning' => $clockWarning];
        try {
            $stored = $store->load();
            if ($stored !== null) {
                $status['activated_at'] = $stored['activated_at'];
                $status['license'] = self::verifier()->verify($stored['bytes'], Verifier::MODE_STARTUP, $now, self::appVersion(), self::instanceId());
                $status['state'] = self::ACTIVE;
            }
        } catch (LicenseException $e) {
            $status['state'] = $e->errorCode === LicenseException::EXPIRED ? self::EXPIRED : self::INVALID;
            $status['error'] = $e->errorCode;
        }

        self::$statusAt = $now->getTimestamp();

        return self::$status = $status;
    }

    public static function reset(): void
    {
        self::$status = null;
    }

    public static function isActive(): bool
    {
        try {
            return self::status()['state'] === self::ACTIVE;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Seats = user accounts of any role (service accounts are separate and do not count).
     * Users created by the installer count too.
     */
    public static function seatsUsed(): int
    {
        try {
            return User::query()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Seats in the active licence; null without an active licence. */
    public static function seatsTotal(): ?int
    {
        $status = self::status();

        return $status['state'] === self::ACTIVE ? $status['license']->seats : null;
    }

    /** Another user may be created: the licence is active and not every seat is taken. */
    public static function hasFreeSeat(): bool
    {
        $total = self::seatsTotal();

        return $total !== null && self::seatsUsed() < $total;
    }

    /** Message when user creation is refused: no licence, or every seat taken. */
    public static function seatMessage(): string
    {
        $total = self::seatsTotal();
        if ($total === null) {
            return self::REQUIRED_MESSAGE;
        }

        return 'All ' . $total . ' ' . ($total === 1 ? 'seat' : 'seats') . ' of the AtGlance licence are in use. '
            . 'Delete a user, or get a licence with more seats at atglance.live.';
    }

    /** A licence file is stored (active or not); uploading another one is then a replacement. */
    public static function hasStoredKey(): bool
    {
        return LicenseStore::make()->hasFile();
    }

    /**
     * Checks an uploaded file without saving it (installer preview).
     *
     * @throws LicenseException
     */
    public static function preview(string $bytes): LicenseData
    {
        $now = self::now();
        if (LicenseStore::make()->clockRolledBack($now->getTimestamp(), (int) config('licensing.clock_rollback_seconds', 86400))) {
            throw new LicenseException(LicenseException::CLOCK);
        }

        return self::verifier()->verify($bytes, Verifier::MODE_ACTIVATION, $now, self::appVersion(), self::instanceId());
    }

    /**
     * Verifies an uploaded file (with the 3-hour rule) and saves it as this console's licence.
     *
     * @throws LicenseException
     */
    public static function activate(string $bytes): LicenseData
    {
        $license = self::preview($bytes);
        LicenseStore::make()->save($bytes, $license, self::now()->getTimestamp());
        self::reset();

        return $license;
    }

    /**
     * Expiry banner for the layout: null, or the level (info, warning, danger) and text.
     * Shown from 30, 7 and 1 days before expiry (config licensing.warn_days), and once locked.
     *
     * @return array{level: string, text: string}|null
     */
    public static function banner(): ?array
    {
        $status = self::status();
        if ($status['state'] === self::EXPIRED) {
            return ['level' => 'danger', 'text' => 'The AtGlance licence has expired. The console is read-only until a new licence file is uploaded in Admin Settings > Licence.'];
        }
        if ($status['state'] === self::INVALID) {
            return ['level' => 'danger', 'text' => 'The AtGlance licence is not valid (' . $status['error'] . '). The console is read-only until a licence file is uploaded in Admin Settings > Licence.'];
        }
        if ($status['state'] !== self::ACTIVE) {
            return null;
        }

        $days = $status['license']->daysLeft(self::now());
        $warn = config('licensing.warn_days', [30, 7, 1]);
        sort($warn);
        foreach ($warn as $i => $threshold) {
            if ($days <= $threshold) {
                $level = ['danger', 'warning', 'info'][min($i, 2)];
                $when = $days <= 0 ? 'today' : 'in ' . $days . ' ' . ($days === 1 ? 'day' : 'days');

                return ['level' => $level, 'text' => 'The AtGlance licence expires ' . $when . ' (' . $status['license']->expiresAt->format('M j, Y') . '). Renew it at atglance.live and upload the new file in Admin Settings > Licence.'];
            }
        }

        return null;
    }

    /**
     * @return array{active: bool, state: string, error: ?string, error_message: string, clock_warning: bool, name: string, customer: string, email: string, plan: string, seats: ?int, seats_used: int, features: list<string>, license_id: string, issued_at: string, expires_at: string, activated_at: string, days_left: ?int, install_id: ?string}
     */
    public static function summary(): array
    {
        $status = self::status();
        $license = $status['license'];

        return [
            'active' => $status['state'] === self::ACTIVE,
            'state' => $status['state'],
            'error' => $status['error'],
            'error_message' => $status['error'] !== null ? (LicenseException::MESSAGES[$status['error']] ?? '') : '',
            'clock_warning' => $status['clock_warning'],
            'name' => $license?->customer ?? '',
            'customer' => $license?->customer ?? '',
            'email' => $license?->email ?? '',
            'plan' => $license?->plan ?? '',
            'seats' => $license?->seats,
            'seats_used' => self::seatsUsed(),
            'features' => $license?->features ?? [],
            'license_id' => $license?->licenseId ?? '',
            'issued_at' => $license ? $license->issuedAt->format('Y-m-d H:i:s') : '',
            'expires_at' => $license ? $license->expiresAt->format('Y-m-d H:i:s') : '',
            'activated_at' => $status['activated_at'] ? date('Y-m-d H:i:s', $status['activated_at']) : '',
            'days_left' => $license?->daysLeft(self::now()),
            'install_id' => $license?->installId,
        ];
    }

    /**
     * Sidebar expiry line: "Expires On <date> (N days left)", "Expired On <date>", or "No expiry date".
     *
     * @return array{text: string, days_left: int|null}
     */
    public static function expiry(string $value): array
    {
        if (trim($value) === '') {
            return ['text' => 'No expiry date', 'days_left' => null];
        }

        try {
            $expires = Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return ['text' => 'Expires On ' . $value, 'days_left' => null];
        }

        $daysLeft = (int) now()->startOfDay()->diffInDays($expires, false);
        if ($daysLeft < 0) {
            return ['text' => 'Expired On ' . $expires->format('M j, Y'), 'days_left' => $daysLeft];
        }

        return [
            'text' => 'Expires On ' . $expires->format('M j, Y') . ' (' . ($daysLeft === 0 ? 'today' : $daysLeft . ' ' . ($daysLeft === 1 ? 'day' : 'days') . ' left') . ')',
            'days_left' => $daysLeft,
        ];
    }

    /** A stored date/time as a date only ("Sep 30, 2026"); '' when empty. */
    public static function date(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('M j, Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * This console's Installation ID: a UUID generated once and kept in storage (not the
     * DB, which does not exist yet when the installer runs). A licence file generated with
     * this ID works on this console only.
     */
    public static function instanceId(): string
    {
        $path = storage_path('app/installer/instance_id');
        $existing = is_file($path) ? strtolower(trim((string) @file_get_contents($path))) : '';
        if ($existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid();
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
        @file_put_contents($path, $id);

        return $id;
    }

    public static function portalUrl(): string
    {
        return (string) config('licensing.portal_url', 'https://atglance.live');
    }

    private static function verifier(): Verifier
    {
        return new Verifier(Verifier::keyring((array) config('licensing.keyring', [])));
    }

    private static function now(): DateTimeImmutable
    {
        return Carbon::now('UTC')->toDateTimeImmutable();
    }

    private static function appVersion(): string
    {
        return (string) config('app.version');
    }
}
