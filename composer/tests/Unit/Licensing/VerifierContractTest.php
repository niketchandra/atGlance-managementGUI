<?php

namespace Tests\Unit\Licensing;

use App\Support\Licensing\LicenseException;
use App\Support\Licensing\Verifier;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared test vectors (workspace contracts/fixtures, copied to tests/Fixtures/licensing).
 * atglance.live runs the same files. Set ATGLANCE_CONTRACTS_DIR to the workspace's
 * contracts/ folder to also check that the copy is up to date.
 */
class VerifierContractTest extends TestCase
{
    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/licensing';
    }

    private static function verifier(): Verifier
    {
        $keys = json_decode((string) file_get_contents(self::dir() . '/keys.json'), true)['keys'];
        $test = $keys[0];

        return new Verifier(Verifier::keyring([
            $test['key_id'] => ['public_key' => $test['ed25519_public_b64'], 'enc_key' => $test['aes_key_hex']],
        ]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        foreach (json_decode((string) file_get_contents(self::dir() . '/cases.json'), true) as $case) {
            yield $case['name'] => [$case];
        }
    }

    #[DataProvider('cases')]
    public function test_contract_case(array $case): void
    {
        try {
            self::verifier()->verify(
                (string) file_get_contents(self::dir() . '/' . $case['file']),
                $case['mode'],
                new DateTimeImmutable($case['now']),
                $case['app_version'],
                $case['install_id'],
            );
            $result = 'ok';
        } catch (LicenseException $e) {
            $result = $e->errorCode;
        }

        $this->assertSame($case['expect'], $result);
    }

    public function test_valid_file_decodes_to_the_expected_payload(): void
    {
        $license = self::verifier()->verify(
            (string) file_get_contents(self::dir() . '/valid.atgl'),
            Verifier::MODE_ACTIVATION,
            new DateTimeImmutable('2026-01-01T01:00:00Z'),
            '2.3.0',
            null,
        );
        $expected = json_decode((string) file_get_contents(self::dir() . '/valid.payload.json'), true);

        $this->assertSame($expected['license_id'], $license->licenseId);
        $this->assertSame($expected['customer'], $license->customer);
        $this->assertSame($expected['seats'], $license->seats);
        $this->assertSame($expected['features'], $license->features);
        $this->assertSame($expected['expires_at'], Verifier::formatDate($license->expiresAt));
    }

    public function test_file_does_not_reveal_the_payload(): void
    {
        $bytes = (string) file_get_contents(self::dir() . '/valid.atgl');

        $this->assertStringNotContainsString('Example Org', $bytes);
        $this->assertStringNotContainsString('license_id', $bytes);
        $this->assertStringNotContainsString('owner@example.com', $bytes);
    }

    public function test_fixture_copy_matches_the_workspace_contract(): void
    {
        $contracts = getenv('ATGLANCE_CONTRACTS_DIR');
        if (!$contracts) {
            $this->markTestSkipped('ATGLANCE_CONTRACTS_DIR is not set; using the copy in tests/Fixtures/licensing.');
        }

        $source = rtrim($contracts, '/\\') . '/fixtures';
        $this->assertDirectoryExists($source);
        $names = fn (string $d) => array_map('basename', glob($d . '/*') ?: []);
        $this->assertSame($names($source), $names(self::dir()), 'Run contracts/tools/sync-fixtures.php');
        foreach ($names($source) as $name) {
            $this->assertSame(hash_file('sha256', "$source/$name"), hash_file('sha256', self::dir() . "/$name"), "$name differs: run contracts/tools/sync-fixtures.php");
        }
    }
}
