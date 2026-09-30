<?php

namespace Tests\Feature;

use App\Support\EnvFile;
use Tests\TestCase;

class EnvFileTest extends TestCase
{
    public function test_replaces_existing_and_appends_new_keys(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "APP_NAME=AtGlance\nAPP_URL=http://old\n");

        EnvFile::set(['APP_URL' => 'https://atglance.internal', 'NEW_KEY' => 'x'], $path);

        $this->assertSame("APP_NAME=AtGlance\nAPP_URL=https://atglance.internal\nNEW_KEY=x\n", file_get_contents($path));
        unlink($path);
    }

    public function test_key_names_are_not_treated_as_regex(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "APPXURL=keep\n");

        EnvFile::set(['APP.URL' => 'v'], $path);

        $this->assertStringContainsString("APPXURL=keep\n", file_get_contents($path));
        unlink($path);
    }
}
