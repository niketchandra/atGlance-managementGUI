<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PatToken;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Task 5b: /api/v1 checks system keys by hash (system_key_hash), and still accepts a
 * system that has no hash yet by its plaintext validation_hash.
 */
class SystemKeyHashV1Test extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'k1-0123456789abcdef0123456789abcdef01234567';
    private const NEW_KEY = 'k2-nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnWXYZ';

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);

        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Ops']);
        $this->workspace = Workspace::create(['org_id' => 200, 'name' => 'ansible', 'status' => 'active']);
    }

    private function user(string $email, int $rbacId = 102): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $email, 'email' => $email,
            'password_hash' => Hash::make('secret-pass'), 'password' => Hash::make('secret-pass'),
            'pin' => Hash::make('1234'), 'dob' => '1990-01-01',
            'rbac_id' => $rbacId, 'org_id' => 200, 'status' => 'active',
        ])->save();

        return $user;
    }

    /** @return array<string, string> */
    private function pat(User $user): array
    {
        $plain = PatToken::generateCustomToken();
        PatToken::create([
            'tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'cli',
            'token' => hash('sha256', $plain), 'abilities' => ['*'], 'status' => 'active',
        ]);

        return ['Authorization' => 'Bearer ' . $plain];
    }

    private function system(User $owner, array $overrides = []): SystemRegister
    {
        return SystemRegister::create(array_merge([
            'pat_token_id' => 1, 'user_id' => $owner->id, 'org_id' => 200, 'workspace_id' => $this->workspace->id,
            'system_name' => 'web-01', 'os_type' => 'linux', 'ip_address' => '10.0.0.5',
            'status' => 'active', 'validation_hash' => self::KEY,
        ], $overrides));
    }

    /** A row as written before 5a (or by a path that bypasses the model): no hash. */
    private function unhashedSystem(User $owner, int $id = 777001): SystemRegister
    {
        DB::table('system_register')->insert([
            'id' => $id, 'pat_token_id' => 1, 'user_id' => $owner->id, 'org_id' => 200, 'workspace_id' => $this->workspace->id,
            'system_name' => 'old-01', 'os_type' => 'linux', 'ip_address' => '10.0.0.8',
            'validation_hash' => self::KEY, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return SystemRegister::findOrFail($id);
    }

    /** Simulates 5e for one row: the plaintext copy is gone, only the hash is left. */
    private function dropPlaintext(SystemRegister $system): void
    {
        DB::table('system_register')->where('id', $system->id)->update(['validation_hash' => null]);
    }

    /** @return array<string, string> */
    private function systemHeaders(SystemRegister $system, string $key = self::KEY): array
    {
        return ['X-Atglance-System-Id' => (string) $system->id, 'X-Atglance-System-Key' => $key];
    }

    private function registerBody(array $overrides = []): array
    {
        return $overrides + [
            'system_name' => 'web-02', 'os_type' => 'linux', 'ip_address' => '10.0.0.6',
            'workspace_id' => $this->workspace->id, 'system_key' => self::KEY,
        ];
    }

    public function test_system_routes_check_the_hash_not_the_plaintext(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $this->dropPlaintext($system);
        $pat = $this->pat($owner);

        $this->getJson('/api/v1/systems/self', $pat + $this->systemHeaders($system))->assertOk()->assertJsonPath('system.id', $system->id);
        $this->getJson('/api/v1/systems/self', $pat + $this->systemHeaders($system, self::NEW_KEY))
            ->assertNotFound()->assertExactJson(['message' => 'System not found']);
    }

    public function test_system_without_a_hash_still_authenticates_by_plaintext(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->unhashedSystem($owner);
        $pat = $this->pat($owner);

        $this->getJson('/api/v1/systems/self', $pat + $this->systemHeaders($system))->assertOk()->assertJsonPath('system.id', $system->id);
        $this->getJson('/api/v1/systems/self', $pat + $this->systemHeaders($system, self::NEW_KEY))->assertNotFound();
    }

    public function test_rotate_key_updates_the_hash_and_the_old_key_is_not_found(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $pat = $this->pat($owner);

        $this->postJson('/api/v1/systems/self/rotate-key', ['password' => 'secret-pass', 'new_key' => self::NEW_KEY], $pat + $this->systemHeaders($system))
            ->assertOk()->assertExactJson(['system_id' => $system->id, 'rotated' => true]);

        $row = DB::table('system_register')->where('id', $system->id)->first();
        $this->assertSame(hash('sha256', self::NEW_KEY), $row->system_key_hash);
        $this->assertSame('WXYZ', $row->system_key_last_four);
        $this->assertNull($row->validation_hash, 'Task 5e: the plaintext key is not kept');

        $this->getJson('/api/v1/systems/self', $pat + $this->systemHeaders($system))->assertNotFound();
        $this->getJson('/api/v1/systems/self', $pat + $this->systemHeaders($system, self::NEW_KEY))->assertOk();
    }

    public function test_rotate_key_of_an_unhashed_system_writes_the_hash(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->unhashedSystem($owner);
        $pat = $this->pat($owner);

        $this->postJson('/api/v1/systems/self/rotate-key', ['password' => 'secret-pass', 'new_key' => self::NEW_KEY], $pat + $this->systemHeaders($system))
            ->assertOk();

        $this->assertSame(hash('sha256', self::NEW_KEY), $system->fresh()->system_key_hash);
        $this->getJson('/api/v1/systems/self', $pat + $this->systemHeaders($system))->assertNotFound();
    }

    public function test_rotate_key_to_the_same_key_is_refused_by_hash(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $this->dropPlaintext($system);

        $this->postJson('/api/v1/systems/self/rotate-key', ['password' => 'secret-pass', 'new_key' => self::KEY], $this->pat($owner) + $this->systemHeaders($system))
            ->assertStatus(422)->assertJsonValidationErrors('new_key');
    }

    public function test_register_writes_hash_and_last_four_with_the_plaintext(): void
    {
        $user = $this->user('me@example.test');
        $this->workspace->addUser($user->id, false);

        $id = $this->postJson('/api/v1/systems', $this->registerBody(), $this->pat($user))->assertCreated()->json('system.id');

        $row = DB::table('system_register')->where('id', $id)->first();
        $this->assertNull($row->validation_hash);
        $this->assertSame(hash('sha256', self::KEY), $row->system_key_hash);
        $this->assertSame('4567', $row->system_key_last_four);
    }

    public function test_idempotent_register_finds_a_legacy_registered_system(): void
    {
        $user = $this->user('me@example.test');
        $this->workspace->addUser($user->id, false);
        $pat = $this->pat($user);

        // Registered by a 1.x host on the legacy route with the same key.
        $legacyId = $this->postJson('/api/system-register', [
            'system_name' => 'web-02', 'os_type' => 'linux', 'ip_address' => '10.0.0.6',
            'workspace_id' => $this->workspace->id, 'validation_hash' => self::KEY,
        ], $pat)->assertCreated()->json('system.id');

        $this->postJson('/api/v1/systems', $this->registerBody(), $pat)
            ->assertOk()->assertJsonPath('system.id', $legacyId);
        $this->assertSame(1, SystemRegister::count());
    }

    public function test_idempotent_register_finds_a_system_without_a_hash(): void
    {
        $user = $this->user('me@example.test');
        $this->workspace->addUser($user->id, false);
        $system = $this->unhashedSystem($user);

        $this->postJson('/api/v1/systems', $this->registerBody(), $this->pat($user))
            ->assertOk()->assertJsonPath('system.id', $system->id);
        $this->assertSame(1, SystemRegister::count());
    }

    public function test_idempotent_register_finds_a_system_by_hash_alone(): void
    {
        $user = $this->user('me@example.test');
        $this->workspace->addUser($user->id, false);
        $system = $this->system($user);
        $this->dropPlaintext($system);

        $this->postJson('/api/v1/systems', $this->registerBody(), $this->pat($user))
            ->assertOk()->assertJsonPath('system.id', $system->id);
    }

    public function test_replaces_proves_the_old_system_by_hash_or_plaintext(): void
    {
        $user = $this->user('me@example.test');
        $this->workspace->addUser($user->id, false);
        $pat = $this->pat($user);
        $hashed = $this->system($user, ['system_name' => 'hashed']);
        $this->dropPlaintext($hashed);
        $unhashed = $this->unhashedSystem($user);

        $this->postJson('/api/v1/systems', $this->registerBody(['system_key' => str_repeat('a', 40), 'replaces_system_id' => $hashed->id, 'replaces_system_key' => self::NEW_KEY]), $pat)
            ->assertStatus(422)->assertJsonValidationErrors('replaces_system_id');

        $this->postJson('/api/v1/systems', $this->registerBody(['system_key' => str_repeat('a', 40), 'replaces_system_id' => $hashed->id, 'replaces_system_key' => self::KEY]), $pat)
            ->assertCreated()->assertJsonPath('system.replaced_system_id', $hashed->id);
        $this->postJson('/api/v1/systems', $this->registerBody(['system_key' => str_repeat('b', 40), 'replaces_system_id' => $unhashed->id, 'replaces_system_key' => self::KEY]), $pat)
            ->assertCreated()->assertJsonPath('system.replaced_system_id', $unhashed->id);

        $this->assertSame('inactive', $hashed->fresh()->status);
        $this->assertSame('inactive', $unhashed->fresh()->status);
    }

    public function test_credentials_status_checks_the_system_key_by_hash(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $this->dropPlaintext($system);
        $pat = $this->pat($owner);

        $this->getJson('/api/v1/credentials/status', $pat + $this->systemHeaders($system))
            ->assertOk()->assertJsonPath('system.id', $system->id);
        $this->getJson('/api/v1/credentials/status', $pat + $this->systemHeaders($system, self::NEW_KEY))
            ->assertNotFound()->assertExactJson(['message' => 'System not found']);
    }

    public function test_v1_upload_keeps_the_sent_key_and_rotation_moves_the_files_to_the_new_key(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $pat = $this->pat($owner);

        $fileId = $this->post('/api/v1/config-files', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('sshd_config', "PermitRootLogin no\n"), 'service_name' => 'ssh',
        ], $pat + $this->systemHeaders($system))->assertCreated()->json('file.id');
        $this->assertSame(self::KEY, DB::table('configuration_files')->where('id', $fileId)->value('validation_hash'));
        $this->getJson('/api/config-files/filter?' . http_build_query(['system_id' => $system->id, 'validation_hash' => self::KEY]), $pat)
            ->assertOk()->assertJsonPath('files.0.id', $fileId);

        $this->postJson('/api/v1/systems/self/rotate-key', ['password' => 'secret-pass', 'new_key' => self::NEW_KEY], $pat + $this->systemHeaders($system))
            ->assertOk();

        $this->assertSame(self::NEW_KEY, DB::table('configuration_files')->where('id', $fileId)->value('validation_hash'));
        $this->getJson('/api/config-files/filter?' . http_build_query(['system_id' => $system->id, 'validation_hash' => self::NEW_KEY]), $pat)
            ->assertOk()->assertJsonPath('files.0.id', $fileId);
    }

    public function test_migration_clears_plaintext_keys_and_hashes_rows_without_a_hash(): void
    {
        $owner = $this->user('owner@example.test');
        $hashed = $this->system($owner);
        DB::table('system_register')->where('id', $hashed->id)->update(['validation_hash' => self::KEY]); // left from before 5e
        $this->unhashedSystem($owner);

        (require database_path('migrations/2026_10_24_000001_clear_plaintext_system_keys.php'))->up();

        $this->assertSame(0, DB::table('system_register')->whereNotNull('validation_hash')->count());
        $this->assertSame(hash('sha256', self::KEY), DB::table('system_register')->where('id', $hashed->id)->value('system_key_hash'));
        $this->assertSame(hash('sha256', self::KEY), DB::table('system_register')->where('id', 777001)->value('system_key_hash'));
    }
}
