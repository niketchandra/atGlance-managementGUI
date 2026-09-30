<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Support\CustomCertificate;
use App\Support\InvalidCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\Feature\Concerns\MakesCertificates;
use Tests\TestCase;

class CustomCertificateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;
    use MakesCertificates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    public function test_reads_pem_pair_with_passphrase(): void
    {
        $pair = $this->makeCertificate(['ops.acme.com'], 365, 'secret');

        $bundle = CustomCertificate::fromPem($pair['cert'], $pair['key'], 'secret');

        $this->assertSame(['ops.acme.com'], $bundle['sans']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $bundle['cert_pem']);
        $this->assertStringNotContainsString('ENCRYPTED', $bundle['key_pem']);
        CustomCertificate::assertUsableFor($bundle, 'ops.acme.com');
    }

    public function test_wrong_passphrase_is_refused(): void
    {
        $pair = $this->makeCertificate(['ops.acme.com'], 365, 'secret');

        $this->expectException(InvalidCertificate::class);
        CustomCertificate::fromPem($pair['cert'], $pair['key'], 'wrong');
    }

    public function test_mismatched_key_is_refused(): void
    {
        $a = $this->makeCertificate(['ops.acme.com']);
        $b = $this->makeCertificate(['ops.acme.com']);

        $this->expectExceptionMessage('does not belong');
        CustomCertificate::fromPem($a['cert'], $b['key']);
    }

    public function test_reads_pkcs12(): void
    {
        $bundle = CustomCertificate::fromPkcs12($this->makePkcs12(['ops.acme.com'], 'pw'), 'pw');

        $this->assertSame(['ops.acme.com'], $bundle['sans']);
    }

    public function test_unreadable_pkcs12_gives_reason(): void
    {
        $this->expectExceptionMessage('could not be read');
        CustomCertificate::fromPkcs12('not a pfx', 'pw');
    }

    public function test_wildcard_covers_one_label_only(): void
    {
        $bundle = CustomCertificate::fromPem(...array_values($this->makeCertificate(['*.acme.com'])));

        $this->assertTrue(CustomCertificate::covers($bundle, 'ops.acme.com'));
        $this->assertFalse(CustomCertificate::covers($bundle, 'a.ops.acme.com'));
        $this->assertFalse(CustomCertificate::covers($bundle, 'acme.com'));
    }

    public function test_other_domain_and_expired_are_refused(): void
    {
        $bundle = CustomCertificate::fromPem(...array_values($this->makeCertificate(['ops.acme.com'], 30)));

        try {
            CustomCertificate::assertUsableFor($bundle, 'other.acme.com');
            $this->fail('expected refusal');
        } catch (InvalidCertificate $e) {
            $this->assertStringContainsString('does not cover other.acme.com', $e->getMessage());
        }

        $this->expectExceptionMessage('expired');
        CustomCertificate::assertUsableFor($bundle, 'ops.acme.com', time() + 40 * 86400);
    }

    public function test_store_is_encrypted_and_round_trips(): void
    {
        $bundle = CustomCertificate::fromPem(...array_values($this->makeCertificate(['ops.acme.com'])));
        CustomCertificate::store($bundle);

        $raw = AdminSetting::query()->where('setting_key', 'custom_cert')->value('setting_value');
        $this->assertStringNotContainsString('PRIVATE KEY', $raw);
        $this->assertSame($bundle['fingerprint'], CustomCertificate::current()['fingerprint']);
        $this->assertArrayNotHasKey('key_pem', CustomCertificate::summary($bundle));

        CustomCertificate::remove();
        $this->assertNull(CustomCertificate::current());
    }
}
