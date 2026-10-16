<?php

namespace Tests\Feature;

use App\Models\SystemRegister;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

/**
 * Task 5d: the console shows system keys from system_key_last_four and searches them by
 * hash (full key) or by the last four; nothing shows or matches on part of a key.
 */
class SystemKeyDisplayTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private const KEY = 'secretkey-0123456789abcdef0123456789-WXYZ';
    private const OTHER_KEY = 'otherkey-fedcba9876543210fedcba98765-QRST';

    private User $owner;
    private SystemRegister $system;

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

        $this->system = $this->makeSystem(910001, 'key-host', self::KEY);
        $this->makeSystem(910002, 'other-host', self::OTHER_KEY);

        DB::table('services')->insert([
            'service_id' => 500, 'service_name' => 'ssh', 'system_id' => 910001, 'system_hash' => self::KEY,
            'user_id' => $this->owner->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->configFile(7001, self::KEY);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function makeSystem(int $id, string $name, ?string $key): SystemRegister
    {
        return SystemRegister::create([
            'id' => $id, 'pat_token_id' => 1, 'user_id' => $this->owner->id, 'org_id' => 200, 'workspace_id' => 0,
            'system_name' => $name, 'os_type' => 'Linux', 'ip_address' => '10.0.0.7',
            'validation_hash' => $key, 'status' => 'active',
        ]);
    }

    private function configFile(int $id, ?string $key, int $systemId = 910001): void
    {
        DB::table('configuration_files')->insert([
            'id' => $id, 'user_id' => $this->owner->id, 'system_register_id' => $systemId, 'service_id' => $systemId === 910001 ? 500 : null,
            'service_name' => $systemId === 910001 ? 'ssh' : 'nginx', 'file_name' => 'cfg-' . $id, 'file_location' => '',
            'validation_hash' => $key, 'version' => 'v' . $id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Simulates 5e for the system: only the hash and last four are left. */
    private function dropPlaintext(): void
    {
        DB::table('system_register')->where('id', 910001)->update(['validation_hash' => null]);
    }

    /** @return list<int> */
    private function systemsFound(string $search): array
    {
        $response = $this->actingAs($this->owner)->get(route('systems-registered', ['hash' => $search]))->assertOk();

        return collect($response->viewData('items')->items())->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** @return list<int> */
    private function backupsFound(string $search): array
    {
        $response = $this->actingAs($this->owner)->get(route('configuration-backups', ['hash' => $search]))->assertOk();

        return collect($response->viewData('items')->items())->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    public function test_masked_key_helpers(): void
    {
        $this->assertSame('••••••••WXYZ', SystemRegister::maskedKey(hash('sha256', self::KEY), 'WXYZ'));
        $this->assertSame('••••••••', SystemRegister::maskedKey(hash('sha256', 'short'), null), 'short keys show only the mask');
        $this->assertSame('••••••••WXYZ', SystemRegister::maskedKey(null, null, self::KEY), 'unhashed row falls back to the plaintext');
        $this->assertSame('', SystemRegister::maskedKey(null, null, null));

        $this->assertSame('••••••••WXYZ', SystemRegister::maskedFileKey(self::KEY, hash('sha256', self::KEY), 'WXYZ'));
        $this->assertSame('••••••••QRST', SystemRegister::maskedFileKey(self::OTHER_KEY, hash('sha256', self::KEY), 'WXYZ'));
        $this->assertSame('', SystemRegister::maskedFileKey(null, hash('sha256', self::KEY), 'WXYZ'));
    }

    public function test_system_edit_page_shows_the_last_four_from_the_hash_columns(): void
    {
        $this->dropPlaintext();

        $this->actingAs($this->owner)->get(route('systems-registered.edit', ['systemId' => 910001]))
            ->assertOk()->assertDontSee(self::KEY)->assertSee('••••••••WXYZ');
    }

    public function test_system_edit_page_without_a_key_shows_na(): void
    {
        $this->makeSystem(910003, 'no-key-host', null);

        $this->actingAs($this->owner)->get(route('systems-registered.edit', ['systemId' => 910003]))
            ->assertOk()->assertSee('N/A')->assertDontSee('••••••••');
    }

    public function test_configuration_and_versions_pages_show_the_last_four(): void
    {
        DB::table('system_register')->where('id', 910001)->update(['system_key_last_four' => 'LAST']);

        $this->actingAs($this->owner)->get(route('configuration-backups.view', ['id' => 7001]))
            ->assertOk()->assertDontSee(self::KEY)->assertSee('••••••••LAST');
        $this->actingAs($this->owner)->get(route('configuration-backups.service-versions', ['serviceId' => 500]))
            ->assertOk()->assertDontSee(self::KEY)->assertSee('••••••••LAST');
        $this->actingAs($this->owner)->get(route('configuration-backups.service-versions-by-name', ['serviceName' => 'ssh']))
            ->assertOk()->assertDontSee(self::KEY)->assertSee('••••••••LAST');
    }

    public function test_search_systems_by_last_four(): void
    {
        $this->assertSame([910001], $this->systemsFound('WXYZ'));
        $this->assertSame([910002], $this->systemsFound('QRST'));
        $this->assertSame([], $this->systemsFound('ABCD'));
    }

    public function test_search_systems_by_full_key_uses_the_hash(): void
    {
        $this->dropPlaintext();

        $this->assertSame([910001], $this->systemsFound(self::KEY));
        $this->assertSame([910001], $this->systemsFound('  ' . self::KEY . ' '));
    }

    public function test_search_never_matches_part_of_a_key(): void
    {
        $this->assertSame([], $this->systemsFound('XYZ'));
        $this->assertSame([], $this->systemsFound('-WXYZ'));
        $this->assertSame([], $this->systemsFound(substr(self::KEY, 0, 20)));
        $this->assertSame([], $this->systemsFound(substr(self::KEY, 5)));
    }

    public function test_search_finds_a_system_not_hashed_yet_by_full_key(): void
    {
        DB::table('system_register')->insert([
            'id' => 910004, 'pat_token_id' => 1, 'user_id' => $this->owner->id, 'org_id' => 200, 'workspace_id' => 0,
            'system_name' => 'old-host', 'os_type' => 'Linux', 'ip_address' => '10.0.0.9',
            'validation_hash' => 'unhashed-key-0000000000000000000000-OLDK', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([910004], $this->systemsFound('unhashed-key-0000000000000000000000-OLDK'));
    }

    public function test_search_backups_by_full_key_or_last_four(): void
    {
        $this->configFile(7002, self::OTHER_KEY, 910002);

        $this->assertSame([7001], $this->backupsFound(self::KEY));
        $this->assertSame([7001], $this->backupsFound('WXYZ'));
        $this->assertSame([7002], $this->backupsFound('QRST'));
        $this->assertSame([], $this->backupsFound(substr(self::KEY, 0, 20)));

        $this->dropPlaintext();
        $this->assertSame([7001], $this->backupsFound(self::KEY));
    }
}
