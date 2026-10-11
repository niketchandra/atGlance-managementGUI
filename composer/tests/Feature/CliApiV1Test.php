<?php

namespace Tests\Feature;

use App\Models\ConfigurationFile;
use App\Models\Organization;
use App\Models\PatToken;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The /api/v1 API for the atglance CLI v2, and the two security fixes in the
 * shared legacy code it reuses (force actions, config upload).
 */
class CliApiV1Test extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'k1-0123456789abcdef0123456789abcdef01234567';

    private Workspace $workspace;
    private Workspace $foreignWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        Storage::fake('local');

        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Ops']);
        Organization::query()->updateOrCreate(['id' => 300], ['name' => 'Other Org']);
        $this->workspace = Workspace::create(['org_id' => 200, 'name' => 'ansible', 'status' => 'active']);
        $this->foreignWorkspace = Workspace::create(['org_id' => 300, 'name' => 'theirs', 'status' => 'active']);
    }

    private function user(string $email, int $rbacId = 102, int $orgId = 200, string $status = 'active'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $email,
            'email' => $email,
            'password_hash' => Hash::make('secret-pass'),
            'password' => Hash::make('secret-pass'),
            'pin' => Hash::make('1234'),
            'dob' => '1990-01-01',
            'rbac_id' => $rbacId,
            'org_id' => $orgId,
            'status' => $status,
        ])->save();

        return $user;
    }

    /**
     * @return array<string, string> Authorization header for a new PAT of $user
     */
    private function pat(User $user, ?PatToken &$token = null): array
    {
        $plain = PatToken::generateCustomToken();
        $token = PatToken::create([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'cli',
            'token' => hash('sha256', $plain),
            'abilities' => ['*'],
            'status' => 'active',
        ]);

        return ['Authorization' => 'Bearer ' . $plain];
    }

    private function system(User $owner, array $overrides = []): SystemRegister
    {
        return SystemRegister::create(array_merge([
            'pat_token_id' => 1,
            'user_id' => $owner->id,
            'org_id' => $owner->org_id,
            'workspace_id' => $this->workspace->id,
            'system_name' => 'web-01',
            'os_type' => 'linux',
            'distro' => 'ubuntu',
            'version' => '24.04',
            'tags' => 'prod',
            'ip_address' => '10.0.0.5',
            'status' => 'active',
            'validation_hash' => self::KEY,
        ], $overrides));
    }

    /**
     * @return array<string, string>
     */
    private function systemHeaders(SystemRegister $system, string $key = self::KEY): array
    {
        return ['X-Atglance-System-Id' => (string) $system->id, 'X-Atglance-System-Key' => $key];
    }

    private function configFile(string $name = 'sshd_config', string $content = "PermitRootLogin no\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    // ---------------------------------------------------------------- legacy security fixes

    public function test_legacy_force_deregister_by_admin_of_same_org(): void
    {
        $owner = $this->user('owner@example.test');
        $admin = $this->user('admin@example.test', 101);
        $system = $this->system($owner);

        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $admin->email, 'password' => 'secret-pass'])
            ->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertSame('inactive', $system->fresh()->status);
    }

    public function test_legacy_force_reactivate_by_super_admin_of_other_org(): void
    {
        $owner = $this->user('owner@example.test');
        $super = $this->user('super@example.test', 100, 300);
        $system = $this->system($owner, ['status' => 'inactive']);

        $this->postJson('/api/system-reactivate-force', ['system_id' => $system->id, 'email' => $super->email, 'pin' => '1234'])
            ->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_legacy_force_rejects_non_admin_and_admin_of_other_org(): void
    {
        $owner = $this->user('owner@example.test');
        $teammate = $this->user('mate@example.test');
        $foreignAdmin = $this->user('fadmin@example.test', 101, 300);
        $system = $this->system($owner);
        $message = "Only an admin of the system's organisation can force this action";

        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $owner->email, 'password' => 'secret-pass'])
            ->assertForbidden()->assertJsonPath('message', $message);
        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $teammate->email, 'password' => 'secret-pass'])
            ->assertForbidden();
        // Non-admins get 403 even for an unknown id.
        $this->postJson('/api/system-deregister-force', ['system_id' => 99999999, 'email' => $teammate->email, 'password' => 'secret-pass'])
            ->assertForbidden()->assertJsonPath('message', $message);
        // An admin of another org cannot tell its system from an unknown one.
        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $foreignAdmin->email, 'password' => 'secret-pass'])
            ->assertNotFound()->assertExactJson(['message' => 'System not found']);
        $this->postJson('/api/system-reactivate-force', ['system_id' => 99999999, 'email' => $foreignAdmin->email, 'password' => 'secret-pass'])
            ->assertNotFound()->assertExactJson(['message' => 'System not found']);
        $this->assertSame('active', $system->fresh()->status);
    }

    public function test_inactive_admin_cannot_force_on_legacy_or_v1(): void
    {
        $owner = $this->user('owner@example.test');
        $admin = $this->user('gone-admin@example.test', 101, 200, 'inactive');
        $super = $this->user('gone-super@example.test', 100, 200, 'inactive');
        $system = $this->system($owner);

        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $admin->email, 'password' => 'secret-pass'])
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials']);
        $this->postJson('/api/system-reactivate-force', ['system_id' => $system->id, 'email' => $super->email, 'pin' => '1234'])
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials']);
        $this->postJson('/api/v1/systems/' . $system->id . '/deregister-force', ['email' => $admin->email, 'password' => 'secret-pass'])
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials']);
        $this->postJson('/api/v1/systems/' . $system->id . '/reactivate-force', ['email' => $super->email, 'password' => 'secret-pass'])
            ->assertUnauthorized();
        $this->assertSame('active', $system->fresh()->status);
    }

    public function test_force_with_unknown_email_is_invalid_credentials(): void
    {
        $system = $this->system($this->user('owner@example.test'));

        $this->postJson('/api/v1/systems/' . $system->id . '/deregister-force', ['email' => 'nobody@example.test', 'password' => 'secret-pass'])
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials']);
    }

    public function test_legacy_force_keeps_credential_errors(): void
    {
        $owner = $this->user('owner@example.test');
        $admin = $this->user('admin@example.test', 101);
        $system = $this->system($owner);

        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $admin->email, 'password' => 'wrong'])
            ->assertUnauthorized();
        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $admin->email])
            ->assertStatus(422);
    }

    public function test_legacy_upload_to_system_of_other_org_is_not_found(): void
    {
        $foreignOwner = $this->user('them@example.test', 102, 300);
        $foreignSystem = $this->system($foreignOwner, ['workspace_id' => $this->foreignWorkspace->id]);
        $me = $this->user('me@example.test');

        $this->post('/api/config-files/upload', [
            'file' => $this->configFile(),
            'system_id' => $foreignSystem->id,
            'service_name' => 'ssh',
        ], $this->pat($me) + ['Accept' => 'application/json'])
            ->assertNotFound()->assertJsonPath('message', 'System not found');

        $this->assertSame(0, ConfigurationFile::count());
    }

    public function test_legacy_upload_by_same_org_teammate_still_works(): void
    {
        $owner = $this->user('owner@example.test');
        $teammate = $this->user('mate@example.test');
        $system = $this->system($owner);

        $this->post('/api/config-files/upload', [
            'file' => $this->configFile(),
            'system_id' => $system->id,
            'service_name' => 'ssh',
        ], $this->pat($teammate) + ['Accept' => 'application/json'])
            ->assertCreated();
    }

    public function test_legacy_register_deregister_and_reactivate_are_unchanged(): void
    {
        $owner = $this->user('owner@example.test');
        $headers = $this->pat($owner);

        $created = $this->postJson('/api/system-register', [
            'system_name' => 'web-09', 'os_type' => 'linux', 'ip_address' => '10.0.0.9',
            'workspace_id' => $this->foreignWorkspace->id, 'validation_hash' => 'legacy-hash',
        ], $headers)->assertCreated()
            ->assertJsonPath('message', 'System registered successfully')
            ->assertJsonPath('system.validation_hash', 'legacy-hash')
            ->assertJsonPath('system.workspace_id', $this->foreignWorkspace->id);
        $systemId = $created->json('system.id');

        $this->postJson('/api/system-deregister', ['system_id' => $systemId, 'password' => 'secret-pass'], $headers)
            ->assertOk()->assertExactJson(['message' => 'System deregistered successfully', 'system_id' => $systemId, 'status' => 'inactive']);
        $this->postJson('/api/system-reactive', ['system_id' => $systemId, 'pin' => '1234'], $headers)
            ->assertOk()->assertExactJson(['message' => 'System reactivated successfully', 'system_id' => $systemId, 'status' => 'active']);
        $this->postJson('/api/system-reactive', ['system_id' => $systemId, 'pin' => '1234'], $this->pat($this->user('mate@example.test')))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- meta, whoami

    public function test_meta_needs_no_auth(): void
    {
        config(['app.version' => '9.8.7']);

        $this->getJson('/api/v1/meta')
            ->assertOk()
            ->assertExactJson(['api' => 'atglance', 'api_version' => 1, 'console_version' => '9.8.7']);
    }

    public function test_whoami_returns_only_allowed_fields(): void
    {
        $user = $this->user('me@example.test');
        $this->workspace->addUser($user->id, false);
        $headers = $this->pat($user, $token);

        $response = $this->getJson('/api/v1/whoami', $headers)->assertOk();

        $response->assertExactJson([
            'user' => ['id' => $user->id, 'name' => $user->name, 'status' => 'active', 'org_id' => 200],
            'token' => ['id' => $token->id, 'name' => 'cli', 'expires_at' => $token->fresh()->expires_at?->toIso8601String()],
        ]);
        $body = $response->getContent();
        $this->assertStringNotContainsString('"email"', $body);
        $this->assertStringNotContainsString('dob', $body);
        $this->assertStringNotContainsString('workspaces', $body);
    }

    public function test_whoami_needs_a_valid_pat_and_an_active_user(): void
    {
        $this->getJson('/api/v1/whoami')->assertUnauthorized();
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer atgla-not-a-real-token'])->assertUnauthorized();

        $inactive = $this->user('gone@example.test', 102, 200, 'inactive');
        $this->getJson('/api/v1/whoami', $this->pat($inactive))
            ->assertForbidden()->assertJsonPath('message', 'User is inactive');
    }

    // ---------------------------------------------------------------- register

    public function test_register_stores_the_key_and_never_echoes_it(): void
    {
        $user = $this->user('me@example.test');

        $response = $this->postJson('/api/v1/systems', [
            'system_name' => 'web-02',
            'os_type' => 'linux',
            'ip_address' => '10.0.0.6',
            'distro' => 'debian',
            'workspace_id' => $this->workspace->id,
            'system_key' => self::KEY,
        ], $this->pat($user))->assertCreated();

        $this->assertSame(['id', 'system_name', 'status', 'workspace_id', 'created_at'], array_keys($response->json('system')));
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $response->assertJsonPath('system.status', 'active')->assertJsonPath('system.workspace_id', $this->workspace->id);

        $system = SystemRegister::find($response->json('system.id'));
        $this->assertSame(self::KEY, $system->validation_hash);
        $this->assertSame(200, (int) $system->org_id);
        $this->assertSame($user->id, (int) $system->user_id);
    }

    public function test_register_rejects_workspace_of_other_org_and_missing_key(): void
    {
        $headers = $this->pat($this->user('me@example.test'));
        $body = ['system_name' => 'web-02', 'os_type' => 'linux', 'ip_address' => '10.0.0.6'];

        $this->postJson('/api/v1/systems', $body + ['system_key' => self::KEY, 'workspace_id' => $this->foreignWorkspace->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('workspace_id');
        $this->postJson('/api/v1/systems', $body, $headers)
            ->assertStatus(422)->assertJsonValidationErrors('system_key');
        $this->postJson('/api/v1/systems', $body + ['system_key' => 'short'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('system_key');
        $this->assertSame(0, SystemRegister::count());
    }

    public function test_validation_errors_are_json_without_an_accept_header(): void
    {
        $this->post('/api/v1/systems', [], $this->pat($this->user('me@example.test')))
            ->assertStatus(422)->assertJsonValidationErrors('system_key');
    }

    // ---------------------------------------------------------------- system identity middleware

    public function test_system_headers_are_required(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);

        $this->getJson('/api/v1/systems/self', $this->pat($owner))
            ->assertStatus(400)
            ->assertExactJson(['message' => 'X-Atglance-System-Id and X-Atglance-System-Key headers are required']);
        $this->getJson('/api/v1/systems/self', $this->pat($owner) + ['X-Atglance-System-Id' => (string) $system->id])
            ->assertStatus(400);
    }

    public function test_system_identity_failures_all_look_like_not_found(): void
    {
        $owner = $this->user('owner@example.test');
        $headers = $this->pat($owner);
        $system = $this->system($owner);
        $noKey = $this->system($owner, ['validation_hash' => null]);
        $emptyKey = $this->system($owner, ['validation_hash' => '']);
        $foreignOwner = $this->user('them@example.test', 102, 300);
        $foreign = $this->system($foreignOwner, ['workspace_id' => $this->foreignWorkspace->id]);

        foreach ([
            $this->systemHeaders($system, 'wrong-key'),
            $this->systemHeaders($noKey, 'anything'),
            $this->systemHeaders($emptyKey, 'anything'),
            $this->systemHeaders($foreign),
            ['X-Atglance-System-Id' => '99999999', 'X-Atglance-System-Key' => self::KEY],
            ['X-Atglance-System-Id' => 'abc', 'X-Atglance-System-Key' => self::KEY],
        ] as $systemHeaders) {
            $this->getJson('/api/v1/systems/self', $headers + $systemHeaders)
                ->assertNotFound()->assertExactJson(['message' => 'System not found']);
        }
    }

    public function test_systems_self_returns_the_system(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);

        $response = $this->getJson('/api/v1/systems/self', $this->pat($owner) + $this->systemHeaders($system))->assertOk();

        $this->assertSame(
            ['id', 'system_name', 'status', 'workspace_id', 'workspace_name', 'ip_address', 'os_type', 'distro', 'version', 'tags', 'created_at', 'updated_at'],
            array_keys($response->json('system'))
        );
        $response->assertJsonPath('system.id', $system->id)
            ->assertJsonPath('system.workspace_name', 'ansible')
            ->assertJsonPath('system.distro', 'ubuntu');
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
    }

    // ---------------------------------------------------------------- deregister / reactivate

    public function test_deregister_needs_password_or_pin(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $headers = $this->pat($owner) + $this->systemHeaders($system);

        $this->postJson('/api/v1/systems/self/deregister', [], $headers)->assertStatus(422);
        $this->postJson('/api/v1/systems/self/deregister', ['password' => 'wrong'], $headers)->assertStatus(422);
        $this->assertSame('active', $system->fresh()->status);
    }

    public function test_owner_deregisters_and_reactivates_an_inactive_system(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $headers = $this->pat($owner) + $this->systemHeaders($system);

        $this->postJson('/api/v1/systems/self/deregister', ['password' => 'secret-pass'], $headers)
            ->assertOk()->assertJson(['system_id' => $system->id, 'status' => 'inactive']);
        $this->assertSame('inactive', $system->fresh()->status);

        $this->postJson('/api/v1/systems/self/reactivate', ['pin' => '1234'], $headers)
            ->assertOk()->assertJson(['system_id' => $system->id, 'status' => 'active']);
        $this->assertSame('active', $system->fresh()->status);

        $this->postJson('/api/v1/systems/self/reactivate', ['pin' => '1234'], $headers)
            ->assertOk()->assertJsonPath('message', 'System is already active');
    }

    public function test_same_org_admin_may_deregister_but_teammate_may_not(): void
    {
        $owner = $this->user('owner@example.test');
        $admin = $this->user('admin@example.test', 101);
        $teammate = $this->user('mate@example.test');
        $system = $this->system($owner);

        $this->postJson('/api/v1/systems/self/deregister', ['password' => 'secret-pass'], $this->pat($teammate) + $this->systemHeaders($system))
            ->assertForbidden();
        $this->postJson('/api/v1/systems/self/reactivate', ['password' => 'secret-pass'], $this->pat($teammate) + $this->systemHeaders($system))
            ->assertForbidden();
        $this->assertSame('active', $system->fresh()->status);

        $this->postJson('/api/v1/systems/self/deregister', ['password' => 'secret-pass'], $this->pat($admin) + $this->systemHeaders($system))
            ->assertOk()->assertJsonPath('status', 'inactive');
    }

    // ---------------------------------------------------------------- rotate key

    public function test_rotate_key_replaces_the_key(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $pat = $this->pat($owner);
        $newKey = str_repeat('n', 40);

        $this->postJson('/api/v1/systems/self/rotate-key', ['password' => 'secret-pass', 'new_key' => $newKey], $pat + $this->systemHeaders($system))
            ->assertOk()->assertExactJson(['system_id' => $system->id, 'rotated' => true]);

        $this->assertSame($newKey, $system->fresh()->validation_hash);
        $this->getJson('/api/v1/systems/self', $pat + $this->systemHeaders($system))->assertNotFound();
        $this->getJson('/api/v1/systems/self', $pat + $this->systemHeaders($system, $newKey))->assertOk();
        $this->assertDatabaseHas('activity_logs', ['user_id' => $owner->id, 'event' => 'system.key_rotated']);
    }

    public function test_rotate_key_rejects_same_key_bad_password_and_teammate(): void
    {
        $owner = $this->user('owner@example.test');
        $teammate = $this->user('mate@example.test');
        $system = $this->system($owner);
        $headers = $this->pat($owner) + $this->systemHeaders($system);

        $this->postJson('/api/v1/systems/self/rotate-key', ['password' => 'secret-pass', 'new_key' => self::KEY], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('new_key');
        $this->postJson('/api/v1/systems/self/rotate-key', ['password' => 'wrong', 'new_key' => str_repeat('n', 40)], $headers)
            ->assertStatus(422);
        $this->postJson('/api/v1/systems/self/rotate-key', ['password' => 'secret-pass', 'new_key' => 'short'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('new_key');
        $this->postJson('/api/v1/systems/self/rotate-key', ['password' => 'secret-pass', 'new_key' => str_repeat('n', 40)], $this->pat($teammate) + $this->systemHeaders($system))
            ->assertForbidden();

        $this->assertSame(self::KEY, $system->fresh()->validation_hash);
    }

    // ---------------------------------------------------------------- force (v1)

    public function test_v1_force_rules(): void
    {
        $owner = $this->user('owner@example.test');
        $admin = $this->user('admin@example.test', 101);
        $super = $this->user('super@example.test', 100, 300);
        $foreignAdmin = $this->user('fadmin@example.test', 101, 300);
        $system = $this->system($owner);
        $url = fn (string $action) => '/api/v1/systems/' . $system->id . '/' . $action;

        $this->postJson($url('deregister-force'), ['email' => $owner->email, 'password' => 'secret-pass'])->assertForbidden();
        $this->postJson($url('deregister-force'), ['email' => $foreignAdmin->email, 'password' => 'secret-pass'])
            ->assertNotFound()->assertExactJson(['message' => 'System not found']);
        $this->postJson($url('deregister-force'), ['email' => $admin->email, 'password' => 'wrong'])->assertUnauthorized();
        $this->assertSame('active', $system->fresh()->status);

        $this->postJson($url('deregister-force'), ['email' => $admin->email, 'password' => 'secret-pass'])
            ->assertOk()->assertJsonPath('status', 'inactive');
        $this->postJson($url('reactivate-force'), ['email' => $foreignAdmin->email, 'pin' => '1234'])->assertNotFound();
        $this->postJson($url('reactivate-force'), ['email' => $super->email, 'pin' => '1234'])
            ->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_v1_force_uses_the_path_id_not_the_body(): void
    {
        $owner = $this->user('owner@example.test');
        $admin = $this->user('admin@example.test', 101);
        $target = $this->system($owner);
        $other = $this->system($owner, ['system_name' => 'web-02']);

        $this->postJson('/api/v1/systems/' . $target->id . '/deregister-force', ['system_id' => $other->id, 'email' => $admin->email, 'password' => 'secret-pass'])
            ->assertOk()->assertJsonPath('system_id', $target->id);
        $this->assertSame('active', $other->fresh()->status);
    }

    // ---------------------------------------------------------------- config files

    public function test_upload_list_filter_and_download(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $headers = $this->pat($owner) + $this->systemHeaders($system) + ['Accept' => 'application/json'];
        $content = "PermitRootLogin no\nPasswordAuthentication no\n";

        $response = $this->post('/api/v1/config-files', ['file' => $this->configFile('sshd_config', $content), 'service_name' => 'ssh'], $headers)
            ->assertCreated();
        $this->assertSame(['id', 'file_name', 'service_name', 'version', 'created_at'], array_keys($response->json('file')));
        $response->assertJsonPath('file.file_name', 'sshd_config')->assertJsonPath('file.version', 'v1');
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertStringNotContainsString('config_files', $response->getContent());
        $fileId = $response->json('file.id');

        $stored = ConfigurationFile::find($fileId);
        $this->assertSame((int) $system->id, (int) $stored->system_register_id);
        $this->assertSame(self::KEY, $stored->validation_hash);

        $this->travel(1)->seconds();
        $this->post('/api/v1/config-files', ['file' => $this->configFile('nginx.conf', "worker_processes 1;\n"), 'service_name' => 'nginx'], $headers)
            ->assertCreated();

        $list = $this->getJson('/api/v1/config-files', $headers)->assertOk();
        $this->assertCount(2, $list->json('files'));
        $this->assertSame('nginx.conf', $list->json('files.0.file_name'));
        $this->assertSame(['id' => $owner->id, 'name' => $owner->name], $list->json('files.1.created_by'));
        $this->assertSame(['id', 'file_name', 'service_name', 'version', 'created_at', 'created_by'], array_keys($list->json('files.0')));

        $filtered = $this->getJson('/api/v1/config-files?service_name=ssh', $headers)->assertOk();
        $this->assertCount(1, $filtered->json('files'));
        $this->assertSame($fileId, $filtered->json('files.0.id'));

        $download = $this->get('/api/v1/config-files/' . $fileId, $headers)->assertOk();
        $this->assertSame($content, $download->getContent());
        $download->assertHeader('Content-Type', 'application/octet-stream');
        $download->assertHeader('Content-Disposition', 'attachment; filename="sshd_config"');
    }

    public function test_upload_ignores_a_spoofed_system_id(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $other = $this->system($owner, ['system_name' => 'web-02', 'validation_hash' => str_repeat('o', 40)]);

        $response = $this->post('/api/v1/config-files', [
            'file' => $this->configFile(),
            'service_name' => 'ssh',
            'system_id' => $other->id,
            'system_register_id' => $other->id,
        ], $this->pat($owner) + $this->systemHeaders($system) + ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame((int) $system->id, (int) ConfigurationFile::find($response->json('file.id'))->system_register_id);
    }

    public function test_upload_passes_through_legacy_errors(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $headers = $this->pat($owner) + $this->systemHeaders($system) + ['Accept' => 'application/json'];

        $this->post('/api/v1/config-files', ['service_name' => 'ssh'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post('/api/v1/config-files', ['file' => $this->configFile()], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'service_name and system_id (or system_register_id) are required when service_id is not provided.');
    }

    public function test_teammate_upload_is_listed_for_the_system(): void
    {
        $owner = $this->user('owner@example.test');
        $teammate = $this->user('mate@example.test');
        $system = $this->system($owner);

        $this->post('/api/v1/config-files', ['file' => $this->configFile(), 'service_name' => 'ssh'], $this->pat($teammate) + $this->systemHeaders($system) + ['Accept' => 'application/json'])
            ->assertCreated();

        $list = $this->getJson('/api/v1/config-files', $this->pat($owner) + $this->systemHeaders($system))->assertOk();
        $this->assertCount(1, $list->json('files'));
        $this->assertSame($teammate->id, $list->json('files.0.created_by.id'));
    }

    public function test_download_of_another_systems_file_is_not_found(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $otherKey = str_repeat('o', 40);
        $other = $this->system($owner, ['system_name' => 'web-02', 'validation_hash' => $otherKey]);
        $pat = $this->pat($owner);

        $fileId = $this->post('/api/v1/config-files', ['file' => $this->configFile(), 'service_name' => 'ssh'], $pat + $this->systemHeaders($other, $otherKey) + ['Accept' => 'application/json'])
            ->assertCreated()->json('file.id');

        $this->get('/api/v1/config-files/' . $fileId, $pat + $this->systemHeaders($system))
            ->assertNotFound()->assertExactJson(['message' => 'Configuration file not found']);
        $this->get('/api/v1/config-files/999999', $pat + $this->systemHeaders($system))
            ->assertNotFound()->assertExactJson(['message' => 'Configuration file not found']);
        $this->assertCount(0, $this->getJson('/api/v1/config-files', $pat + $this->systemHeaders($system))->json('files'));

        ConfigurationFile::whereKey($fileId)->update(['status' => 'inactive']);
        $this->get('/api/v1/config-files/' . $fileId, $pat + $this->systemHeaders($other, $otherKey))->assertNotFound();
    }

    // ---------------------------------------------------------------- fix round: rate limits

    public function test_meta_calls_do_not_consume_the_force_limit(): void
    {
        $owner = $this->user('owner@example.test');
        $admin = $this->user('admin@example.test', 101);
        $system = $this->system($owner);

        for ($i = 0; $i < 12; $i++) {
            $this->getJson('/api/v1/meta')->assertOk();
        }

        $this->postJson('/api/v1/systems/' . $system->id . '/deregister-force', ['email' => $admin->email, 'password' => 'secret-pass'])
            ->assertOk();
    }

    public function test_force_limit_is_per_ip_and_email_and_covers_legacy_routes(): void
    {
        $owner = $this->user('owner@example.test');
        $admin = $this->user('admin@example.test', 101);
        $system = $this->system($owner);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/systems/' . $system->id . '/deregister-force', ['email' => 'Admin@Example.test', 'password' => 'wrong'])
                ->assertUnauthorized();
            $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => 'admin@example.test', 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        // 11th attempt for the same email (any case, legacy or v1 route) is limited.
        $this->postJson('/api/system-reactivate-force', ['system_id' => $system->id, 'email' => 'ADMIN@example.test', 'password' => 'secret-pass'])
            ->assertStatus(429);
        $this->postJson('/api/v1/systems/' . $system->id . '/reactivate-force', ['email' => $admin->email, 'password' => 'secret-pass'])
            ->assertStatus(429)->assertJsonStructure(['message']);

        // Another email from the same IP is not.
        $this->user('admin2@example.test', 101);
        $this->postJson('/api/v1/systems/' . $system->id . '/deregister-force', ['email' => 'admin2@example.test', 'password' => 'secret-pass'])
            ->assertOk();
    }

    // ---------------------------------------------------------------- fix round: inactive users on reads, long ids

    public function test_inactive_user_cannot_read_system_or_config_files(): void
    {
        $owner = $this->user('owner@example.test');
        $system = $this->system($owner);
        $fileId = $this->post('/api/v1/config-files', ['file' => $this->configFile(), 'service_name' => 'ssh'], $this->pat($owner) + $this->systemHeaders($system) + ['Accept' => 'application/json'])
            ->assertCreated()->json('file.id');

        $gone = $this->user('gone@example.test', 102, 200, 'inactive');
        $headers = $this->pat($gone) + $this->systemHeaders($system);

        $this->getJson('/api/v1/systems/self', $headers)->assertForbidden()->assertExactJson(['message' => 'User is inactive']);
        $this->getJson('/api/v1/config-files', $headers)->assertForbidden()->assertExactJson(['message' => 'User is inactive']);
        $this->get('/api/v1/config-files/' . $fileId, $headers)->assertForbidden()->assertExactJson(['message' => 'User is inactive']);
    }

    public function test_ids_longer_than_18_digits_are_not_found(): void
    {
        $owner = $this->user('owner@example.test');
        $admin = $this->user('admin@example.test', 101);
        $system = $this->system($owner);
        $long = str_repeat('9', 25);

        $this->postJson('/api/v1/systems/' . $long . '/deregister-force', ['email' => $admin->email, 'password' => 'secret-pass'])
            ->assertNotFound()->assertJsonStructure(['message']);
        $this->post('/api/v1/systems/' . $long . '/reactivate-force', ['email' => $admin->email, 'password' => 'secret-pass'])
            ->assertNotFound()->assertJsonStructure(['message']);
        $this->get('/api/v1/config-files/' . $long, $this->pat($owner) + $this->systemHeaders($system))
            ->assertNotFound()->assertJsonStructure(['message']);
        $this->getJson('/api/v1/systems/self', $this->pat($owner) + ['X-Atglance-System-Id' => $long, 'X-Atglance-System-Key' => self::KEY])
            ->assertNotFound()->assertExactJson(['message' => 'System not found']);
    }
}
