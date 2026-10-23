<?php

namespace Tests;

use App\Support\License;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestLicense;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test gets its own licence folder and the TEST keyring. With $withLicense (the
     * default) a licence valid from 2020 to 2100 is installed, so the read-only lock does not
     * get in the way, even in tests that move the clock.
     */
    protected bool $withLicense = true;

    protected string $licenseDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // The licence records are HMAC-signed with APP_KEY. Always this key (whatever a local
        // .env holds): the tests that set an app key set this same one.
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);

        $this->licenseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atglance-license-' . bin2hex(random_bytes(6));
        config([
            'licensing.path' => $this->licenseDir,
            'licensing.keyring' => TestLicense::keyring(),
        ]);
        License::reset();

        if ($this->withLicense) {
            TestLicense::installLongLived();
        }
    }

    protected function tearDown(): void
    {
        License::reset();
        if ($this->licenseDir !== '' && is_dir($this->licenseDir)) {
            foreach (glob($this->licenseDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->licenseDir);
        }

        parent::tearDown();
    }
}
