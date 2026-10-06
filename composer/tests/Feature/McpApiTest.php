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

class McpApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ansible;
    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);

        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Ops']);
        $this->ansible = Workspace::create(['org_id' => 200, 'name' => 'ansible', 'status' => 'active']);
        $this->other = Workspace::create(['org_id' => 200, 'name' => 'kubernetes', 'status' => 'active']);
    }

    private function user(string $email, int $rbacId, ?Workspace $workspace = null): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $email,
            'email' => $email,
            'password_hash' => Hash::make('secret-pass'),
            'password' => Hash::make('secret-pass'),
            'rbac_id' => $rbacId,
            'org_id' => 200,
            'status' => 'active',
        ])->save();
        $workspace?->addUser($user->id, false);

        return $user;
    }

    /**
     * @return array<string, string> Authorization header for a new PAT of $user
     */
    private function pat(User $user): array
    {
        $plain = PatToken::generateCustomToken();
        PatToken::create([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'mcp',
            'token' => hash('sha256', $plain),
            'abilities' => ['*'],
            'status' => 'active',
        ]);

        return ['Authorization' => 'Bearer ' . $plain];
    }

    private function system(Workspace $workspace, string $name): SystemRegister
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');

        return SystemRegister::create([
            'id' => random_int(100000, 999999),
            'pat_token_id' => 1,
            'user_id' => 1,
            'org_id' => 200,
            'workspace_id' => $workspace->id,
            'system_name' => $name,
            'os_type' => 'linux',
            'distro' => 'ubuntu',
            'ip_address' => '10.0.0.5',
            'status' => 'active',
            'validation_hash' => 'hash-' . uniqid(),
        ]);
    }

    private function config(SystemRegister $system, string $fileName, string $content, ?string $aiStatus = null): int
    {
        $id = DB::table('configuration_files')->insertGetId([
            'user_id' => 1, 'system_register_id' => $system->id, 'service_id' => 1,
            'file_name' => $fileName, 'service_name' => 'ssh', 'file_location' => '',
            'version' => 'v1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('raw_data')->insert([
            'file_id' => $id, 'user_id' => 1, 'system_register_id' => $system->id, 'service_id' => 1,
            'file_name' => $fileName, 'service_name' => 'ssh', 'file_data' => $content,
            'version' => 'v1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($aiStatus !== null) {
            DB::table('config_ai_validations')->insert([
                'configuration_file_id' => $id, 'user_id' => null, 'trigger' => 'upload',
                'provider' => 'Ollama', 'model' => 'llama3', 'status' => $aiStatus, 'summary' => 'Root login allowed',
                'result' => json_encode(['findings' => [['severity' => $aiStatus, 'line' => 1, 'issue' => 'PermitRootLogin yes', 'standard' => 'CIS Benchmark', 'fix' => 'PermitRootLogin no', 'details' => 'long text']]]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    public function test_needs_a_pat(): void
    {
        $this->getJson('/api/mcp/me')->assertUnauthorized();
        $this->getJson('/api/mcp/me', ['Authorization' => 'Bearer atgla-not-a-real-token'])->assertUnauthorized();
    }

    public function test_user_sees_only_their_workspaces_data(): void
    {
        $user = $this->user('user@example.test', 102, $this->ansible);
        $mine = $this->system($this->ansible, 'web-01');
        $theirs = $this->system($this->other, 'k8s-01');
        $this->config($mine, 'sshd_config', "PermitRootLogin yes\n", 'error');
        $hidden = $this->config($theirs, 'other.conf', "x\n", 'warning');
        $headers = $this->pat($user);

        $this->getJson('/api/mcp/me', $headers)->assertOk()->assertJson(['role' => 'user', 'workspace_ids' => [$this->ansible->id]]);
        $this->getJson('/api/mcp/workspaces', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'ansible');
        $this->getJson('/api/mcp/systems', $headers)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'web-01')->assertJsonPath('data.0.ai_errors', 1);
        $this->getJson('/api/mcp/vulnerabilities', $headers)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.severity', 'error')
            ->assertJsonPath('data.0.findings.0.issue', 'PermitRootLogin yes')
            ->assertJsonMissingPath('data.0.findings.0.details');
        $this->getJson('/api/mcp/dashboard', $headers)->assertOk()
            ->assertJson(['systems' => 1, 'config_backups' => 1, 'vulnerabilities' => ['error' => ['total' => 1]]]);

        // Another workspace's file: same answer as a missing one.
        $this->getJson('/api/mcp/config-files/' . $hidden, $headers)->assertNotFound();
        $this->getJson('/api/mcp/workspaces/' . $this->other->id . '/ai-progress', $headers)->assertNotFound();
    }

    public function test_super_admin_sees_everything_and_can_narrow_by_workspace(): void
    {
        $admin = $this->user('root@example.test', 100);
        $this->system($this->ansible, 'web-01');
        $this->system($this->other, 'k8s-01');
        $headers = $this->pat($admin);

        $this->getJson('/api/mcp/systems', $headers)->assertJsonCount(2, 'data');
        $this->getJson('/api/mcp/systems?workspace_id=' . $this->other->id, $headers)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'k8s-01');
    }

    public function test_config_file_masks_secrets_and_lists_latest_versions_only(): void
    {
        $user = $this->user('user@example.test', 102, $this->ansible);
        $system = $this->system($this->ansible, 'db-01');
        $this->config($system, 'my.cnf', "user=app\n");
        $latest = $this->config($system, 'my.cnf', "user=app\npassword=Sup3rSecret!\nPasswordAuthentication no\n", 'warning');
        $headers = $this->pat($user);

        $this->getJson('/api/mcp/config-files', $headers)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $latest)->assertJsonPath('data.0.ai_status', 'warning');
        $this->getJson('/api/mcp/config-files?all_versions=1', $headers)->assertJsonCount(2, 'data');
        $this->getJson('/api/mcp/config-files?ai_status=not_checked', $headers)->assertJsonCount(0, 'data');

        $response = $this->getJson('/api/mcp/config-files/' . $latest, $headers)->assertOk()
            ->assertJsonPath('masked_values', 1)
            ->assertJsonPath('latest_review.status', 'warning');
        $this->assertStringNotContainsString('Sup3rSecret!', $response->json('content'));
        $this->assertStringContainsString('PasswordAuthentication no', $response->json('content'));

        $this->getJson('/api/mcp/config-files/' . $latest . '/versions', $headers)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.latest', true);
    }

    public function test_api_is_read_only(): void
    {
        $headers = $this->pat($this->user('root@example.test', 100));

        $this->postJson('/api/mcp/systems', [], $headers)->assertStatus(405);
        $this->deleteJson('/api/mcp/config-files/1', [], $headers)->assertStatus(405);
    }
}
