<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PatToken;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Task 5c: the legacy routes 1.x hosts use keep working with plaintext keys after keys
 * are hashed, and legacy registration writes the hash with the plaintext.
 */
class SystemKeyHashLegacyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'k1-0123456789abcdef0123456789abcdef01234567';
    private const LOCAL_KEY = 'k1-regenerated-on-the-host-0000000000000000';

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        Storage::fake('local');

        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Ops']);
        $this->workspace = Workspace::create(['org_id' => 200, 'name' => 'ansible', 'status' => 'active']);
    }

    private function user(string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $email, 'email' => $email,
            'password_hash' => Hash::make('secret-pass'), 'password' => Hash::make('secret-pass'),
            'pin' => Hash::make('1234'), 'dob' => '1990-01-01',
            'rbac_id' => 102, 'org_id' => 200, 'status' => 'active',
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

        return ['Authorization' => 'Bearer ' . $plain, 'Accept' => 'application/json'];
    }

    private function legacyRegister(array $headers, ?string $key): int
    {
        return (int) $this->postJson('/api/system-register', array_filter([
            'system_name' => 'web-09', 'os_type' => 'linux', 'ip_address' => '10.0.0.9',
            'workspace_id' => $this->workspace->id, 'validation_hash' => $key,
        ]), $headers)->assertCreated()->assertJsonPath('system.validation_hash', $key)->json('system.id');
    }

    private function legacyUpload(array $headers, int $systemId, string $key, string $service = 'ssh'): int
    {
        return (int) $this->post('/api/config-files/upload', [
            'file' => UploadedFile::fake()->createWithContent('sshd_config', "PermitRootLogin no\n"),
            'system_id' => $systemId, 'service_name' => $service, 'validation_hash' => $key,
        ], $headers)->assertCreated()->assertJsonPath('file.validation_hash', $key)->json('file.id');
    }

    private function configShow(array $headers, int $systemId, string $key, string $service = 'ssh')
    {
        return $this->getJson('/api/config-files/config-show?' . http_build_query(['system_id' => $systemId, 'validation_key' => $key, 'service_name' => $service]), $headers);
    }

    private function filter(array $headers, int $systemId, string $key)
    {
        return $this->getJson('/api/config-files/filter?' . http_build_query(['system_id' => $systemId, 'validation_hash' => $key]), $headers);
    }

    public function test_legacy_register_writes_hash_and_last_four_with_the_plaintext(): void
    {
        $headers = $this->pat($this->user('owner@example.test'));

        $row = DB::table('system_register')->where('id', $this->legacyRegister($headers, self::KEY))->first();
        $this->assertSame(self::KEY, $row->validation_hash);
        $this->assertSame(hash('sha256', self::KEY), $row->system_key_hash);
        $this->assertSame('4567', $row->system_key_last_four);

        // 1.x may register without a key (after a prompt): no hash either.
        $row = DB::table('system_register')->where('id', $this->legacyRegister($headers, null))->first();
        $this->assertNull($row->validation_hash);
        $this->assertNull($row->system_key_hash);
        $this->assertNull($row->system_key_last_four);
    }

    public function test_legacy_upload_filter_and_config_show_with_the_plaintext_key(): void
    {
        $headers = $this->pat($this->user('owner@example.test'));
        $systemId = $this->legacyRegister($headers, self::KEY);
        $fileId = $this->legacyUpload($headers, $systemId, self::KEY);

        $this->configShow($headers, $systemId, self::KEY)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('versions.0.id', $fileId)
            ->assertJsonPath('validation_key', self::KEY);
        $this->filter($headers, $systemId, self::KEY)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('files.0.id', $fileId);

        $this->configShow($headers, $systemId, 'k1-some-other-key-000000000000000000000000')->assertOk()->assertJsonPath('total', 0);
        $this->filter($headers, $systemId, 'k1-some-other-key-000000000000000000000000')->assertOk()->assertJsonPath('total', 0);

        $download = $this->get('/api/config-files/download/' . $fileId . '?system_id=' . $systemId, $headers)->assertOk();
        $this->assertStringContainsString('PermitRootLogin no', $download->getContent());
    }

    public function test_legacy_routes_work_for_a_system_without_a_hash(): void
    {
        $owner = $this->user('owner@example.test');
        $headers = $this->pat($owner);
        DB::table('system_register')->insert([
            'id' => 777001, 'pat_token_id' => 1, 'user_id' => $owner->id, 'org_id' => 200, 'workspace_id' => $this->workspace->id,
            'system_name' => 'old-01', 'os_type' => 'linux', 'ip_address' => '10.0.0.8',
            'validation_hash' => self::KEY, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $fileId = $this->legacyUpload($headers, 777001, self::KEY);
        $this->configShow($headers, 777001, self::KEY)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('versions.0.id', $fileId);
        $this->filter($headers, 777001, self::KEY)->assertOk()->assertJsonPath('total', 1);
    }

    /**
     * 1.x can regenerate its key on the host without telling the console (cli.py on main,
     * "regenerate validation hash"). Its uploads then carry the new key, and config-show
     * and filter with that key must still find them, as before Task 5.
     */
    public function test_files_uploaded_with_a_key_regenerated_on_the_host_are_still_found(): void
    {
        $headers = $this->pat($this->user('owner@example.test'));
        $systemId = $this->legacyRegister($headers, self::KEY);
        $old = $this->legacyUpload($headers, $systemId, self::KEY);
        $new = $this->legacyUpload($headers, $systemId, self::LOCAL_KEY);

        $this->configShow($headers, $systemId, self::LOCAL_KEY)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('versions.0.id', $new);
        $this->filter($headers, $systemId, self::LOCAL_KEY)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('files.0.id', $new);
        $this->configShow($headers, $systemId, self::KEY)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('versions.0.id', $old);
        $this->assertSame(hash('sha256', self::KEY), SystemRegister::find($systemId)->system_key_hash, 'the console key is unchanged');
    }

    public function test_legacy_deregister_and_reactivate_of_a_hashed_system_are_unchanged(): void
    {
        $headers = $this->pat($this->user('owner@example.test'));
        $systemId = $this->legacyRegister($headers, self::KEY);

        $this->postJson('/api/system-deregister', ['system_id' => $systemId, 'password' => 'secret-pass'], $headers)
            ->assertOk()->assertExactJson(['message' => 'System deregistered successfully', 'system_id' => $systemId, 'status' => 'inactive']);
        $this->postJson('/api/system-reactive', ['system_id' => $systemId, 'pin' => '1234'], $headers)
            ->assertOk()->assertExactJson(['message' => 'System reactivated successfully', 'system_id' => $systemId, 'status' => 'active']);
        $this->assertSame(hash('sha256', self::KEY), SystemRegister::find($systemId)->system_key_hash);
    }
}
