<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SecretMask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

/**
 * The web console never shows a system's full key (validation_hash): only a mask
 * and the last 4 characters, and no copy-to-clipboard of the key.
 */
class SystemKeyMaskingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private const KEY = 'secretkey-0123456789abcdef0123456789-WXYZ';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        $this->activateLicense();

        $this->owner = new User();
        $this->owner->forceFill([
            'name' => 'owner', 'email' => 'owner@example.test', 'password' => 'secret-pass',
            'rbac_id' => 102, 'org_id' => 200, 'status' => 'active', 'dob' => '1990-01-01', 'pin' => Hash::make('12345'),
        ])->save();

        DB::table('system_register')->insert([
            'id' => 910001, 'pat_token_id' => 1, 'user_id' => $this->owner->id, 'org_id' => 200, 'workspace_id' => 0,
            'system_name' => 'mask-host', 'os_type' => 'Linux', 'ip_address' => '10.0.0.7',
            'validation_hash' => self::KEY, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('services')->insert([
            'service_id' => 500, 'service_name' => 'ssh', 'system_id' => 910001, 'system_hash' => self::KEY,
            'user_id' => $this->owner->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('configuration_files')->insert([
            'id' => 7001, 'user_id' => $this->owner->id, 'system_register_id' => 910001, 'service_id' => 500,
            'service_name' => 'ssh', 'file_name' => 'sshd_config', 'file_location' => '',
            'validation_hash' => self::KEY, 'version' => 'v1', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    public function test_mask_shows_only_the_last_four_characters(): void
    {
        $this->assertSame('••••••••WXYZ', SecretMask::key(self::KEY));
        $this->assertSame('••••••••', SecretMask::key('short'));
        $this->assertSame('', SecretMask::key(null));
        $this->assertSame('', SecretMask::key(''));
    }

    public function test_system_edit_page_masks_the_key(): void
    {
        $this->actingAs($this->owner)->get(route('systems-registered.edit', ['systemId' => 910001]))
            ->assertOk()
            ->assertDontSee(self::KEY)
            ->assertSee('••••••••WXYZ');
    }

    public function test_configuration_page_masks_the_key(): void
    {
        $html = $this->actingAs($this->owner)->get(route('configuration-backups.view', ['id' => 7001]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(self::KEY, $html);
        $this->assertStringContainsString('WXYZ', $html);
    }

    public function test_service_versions_page_masks_the_key_and_has_no_copy(): void
    {
        $html = $this->actingAs($this->owner)->get(route('configuration-backups.service-versions', ['serviceId' => 500]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(self::KEY, $html);
        $this->assertStringNotContainsString(substr(self::KEY, 0, 10), $html);
        $this->assertStringContainsString('••••••••WXYZ', $html);
        $this->assertStringNotContainsString('copyHashById', $html);
    }
}
