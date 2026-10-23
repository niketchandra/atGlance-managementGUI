<?php

namespace App\Support\Licensing;

/**
 * Files in config('licensing.path') (the app-storage volume, so they survive updates):
 *
 * - license.atgl     the uploaded file, byte for byte
 * - activation.json  {license_id, activated_at, file_sha256, mac}
 * - clock.json       {last_seen_at, mac}: the latest time seen, to detect a clock moved back
 *
 * The two JSON records carry an HMAC keyed from APP_KEY, so an edit by hand is detected.
 */
class LicenseStore
{
    private const FILE = 'license.atgl';
    private const ACTIVATION = 'activation.json';
    private const CLOCK = 'clock.json';

    /** last_seen_at is rewritten at most this often. */
    private const CLOCK_WRITE_INTERVAL = 300;

    public function __construct(private readonly string $dir, private readonly string $appKey)
    {
    }

    public static function make(): self
    {
        return new self((string) config('licensing.path'), (string) config('app.key'));
    }

    public function hasFile(): bool
    {
        return is_file($this->path(self::FILE));
    }

    /**
     * The stored file, after checking the activation record against it.
     *
     * @return array{bytes: string, license_id: string, activated_at: int}|null null when no licence is stored
     *
     * @throws LicenseException E_RECORD when the record is missing, edited, or names another file
     */
    public function load(): ?array
    {
        if (!$this->hasFile()) {
            return null;
        }

        $bytes = (string) file_get_contents($this->path(self::FILE));
        $record = $this->readRecord(self::ACTIVATION);
        if ($record === null
            || !is_string($record['file_sha256'] ?? null)
            || !hash_equals($record['file_sha256'], hash('sha256', $bytes))) {
            throw new LicenseException(LicenseException::RECORD);
        }

        return [
            'bytes' => $bytes,
            'license_id' => (string) ($record['license_id'] ?? ''),
            'activated_at' => (int) ($record['activated_at'] ?? 0),
        ];
    }

    public function save(string $bytes, LicenseData $license, int $now): void
    {
        $this->ensureDir();
        // Record first, then the file: a crash in between leaves the old file with a record
        // that no longer matches, which shows as E_RECORD instead of a silently wrong licence.
        $this->writeRecord(self::ACTIVATION, [
            'license_id' => $license->licenseId,
            'activated_at' => $now,
            'file_sha256' => hash('sha256', $bytes),
        ]);
        $this->writeAtomic(self::FILE, $bytes);
        $this->touchClock($now);
    }

    public function forget(): void
    {
        foreach ([self::FILE, self::ACTIVATION] as $name) {
            @unlink($this->path($name));
        }
    }

    /** Latest time recorded (unix), or null when none or the record was edited. */
    public function lastSeen(): ?int
    {
        $record = $this->readRecord(self::CLOCK);

        return isset($record['last_seen_at']) && is_int($record['last_seen_at']) ? $record['last_seen_at'] : null;
    }

    /** True when $now is more than $tolerance seconds behind the latest recorded time. */
    public function clockRolledBack(int $now, int $tolerance): bool
    {
        $last = $this->lastSeen();

        return $last !== null && $last - $now > $tolerance;
    }

    /** Records $now when it is later than the recorded time (written at most every 5 minutes). */
    public function touchClock(int $now): void
    {
        $last = $this->lastSeen();
        if ($last !== null && $now - $last < self::CLOCK_WRITE_INTERVAL) {
            return;
        }
        if (!is_file($this->path(self::CLOCK)) && !is_dir($this->dir) && !@mkdir($this->dir, 0750, true)) {
            return;
        }
        $this->writeRecord(self::CLOCK, ['last_seen_at' => max($now, $last ?? 0)]);
    }

    /**
     * @return array<string, mixed>|null null when missing or the MAC does not match
     */
    private function readRecord(string $name): ?array
    {
        $raw = @file_get_contents($this->path($name));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_string($data['mac'] ?? null)) {
            return null;
        }
        $mac = $data['mac'];
        unset($data['mac']);

        return hash_equals($this->mac($name, $data), $mac) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeRecord(string $name, array $data): void
    {
        $this->ensureDir();
        $data['mac'] = $this->mac($name, $data);
        $this->writeAtomic($name, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mac(string $name, array $data): string
    {
        ksort($data);
        $key = hash_hmac('sha256', 'atglance-license-store-v1', $this->appKey, true);

        return hash_hmac('sha256', $name . "\n" . json_encode($data, JSON_UNESCAPED_SLASHES), $key);
    }

    private function writeAtomic(string $name, string $contents): void
    {
        $tmp = $this->path($name . '.' . bin2hex(random_bytes(4)) . '.tmp');
        if (file_put_contents($tmp, $contents) === false || !rename($tmp, $this->path($name))) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write ' . $name . ' in ' . $this->dir);
        }
        @chmod($this->path($name), 0640);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Could not create ' . $this->dir);
        }
    }

    private function path(string $name): string
    {
        return rtrim($this->dir, '/\\') . DIRECTORY_SEPARATOR . $name;
    }
}
