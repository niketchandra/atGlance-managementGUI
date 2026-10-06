<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class WorkspaceActivityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private Workspace $workspace;
    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Ops']);
        $this->workspace = Workspace::create(['org_id' => 200, 'name' => 'development', 'status' => 'active']);
        $this->other = Workspace::create(['org_id' => 200, 'name' => 'production', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
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

    private function config(SystemRegister $system, string $fileName): int
    {
        return DB::table('configuration_files')->insertGetId([
            'user_id' => 1, 'system_register_id' => $system->id, 'service_id' => 1, 'file_name' => $fileName,
            'service_name' => 'ssh', 'file_location' => '', 'version' => 'v3', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $email, 'email' => $email, 'password_hash' => Hash::make('x'), 'password' => Hash::make('x'),
            'rbac_id' => 102, 'org_id' => 200, 'status' => 'active', 'dob' => '1990-01-01', 'pin' => Hash::make('12345'),
        ])->save();

        return $user;
    }

    private function seedActivity(): SystemRegister
    {
        $web = $this->system($this->workspace, 'dev-web-01');
        $configId = $this->config($web, 'sshd_config');
        DB::table('config_ai_validations')->insert([
            'configuration_file_id' => $configId, 'user_id' => 1, 'trigger' => 'upload', 'provider' => 'ollama',
            'model' => 'llama3.2', 'status' => 'error', 'summary' => 'Root login', 'result' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspace_backup_runs')->insert([
            'workspace_id' => $this->workspace->id, 'status' => 'success', 'message' => 'Saved 1 file',
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $prod = $this->system($this->other, 'prod-db-01');
        $this->config($prod, 'my.cnf');

        return $web;
    }

    public function test_tab_shows_everything_that_happened_in_this_workspace_only(): void
    {
        $web = $this->seedActivity();
        $this->actingAsRole(100);

        // Actions done through the console are recorded with the workspace.
        $this->post(route('workspace.users.add', $this->workspace->id), ['user_id' => $this->user('dev@example.test')->id]);
        $this->put(route('admin.workspaces.settings.tags', $this->workspace->id), ['tags' => [['key' => 'env', 'value' => 'dev']]]);
        $web->update(['status' => 'inactive']);

        $this->get(route('workspace.detail', ['workspaceId' => $this->workspace->id, 'tab' => 'activity']))
            ->assertOk()
            ->assertSee('Recent Activity')
            ->assertSee('System registered: dev-web-01')
            ->assertSee('Config backed up: sshd_config')
            ->assertSee('AI review of sshd_config: issues found (high)')
            ->assertSee('Workspace backup finished')
            ->assertSee('Added dev@example.test as a member')
            ->assertSee('Updated the workspace tags')
            ->assertSee('Deregistered system dev-web-01')
            ->assertDontSee('prod-db-01')
            ->assertDontSee('my.cnf');
    }

    public function test_filters_by_type_and_system(): void
    {
        $web = $this->seedActivity();
        $api = $this->system($this->workspace, 'dev-api-01');
        $this->config($api, 'nginx.conf');

        $systemsOnly = WorkspaceActivity::paginate($this->workspace->id, ['type' => 'systems'])->pluck('title')->all();
        $this->assertContains('System registered: dev-web-01', $systemsOnly);
        $this->assertNotContains('Config backed up: sshd_config', $systemsOnly);

        $oneSystem = WorkspaceActivity::paginate($this->workspace->id, ['system' => $web->id])->pluck('title')->all();
        $this->assertContains('Config backed up: sshd_config', $oneSystem);
        $this->assertNotContains('Config backed up: nginx.conf', $oneSystem);
        $this->assertNotContains('Workspace backup finished', $oneSystem);
    }

    public function test_period_filter_hides_older_entries(): void
    {
        $web = $this->seedActivity();
        DB::table('configuration_files')->where('system_register_id', $web->id)->update(['created_at' => now()->subDays(40)]);

        $recent = WorkspaceActivity::paginate($this->workspace->id, ['type' => 'configs', 'period' => '30'])->pluck('title')->all();
        $all = WorkspaceActivity::paginate($this->workspace->id, ['type' => 'configs', 'period' => 'all'])->pluck('title')->all();

        $this->assertNotContains('Config backed up: sshd_config', $recent);
        $this->assertContains('Config backed up: sshd_config', $all);
    }

    public function test_feed_is_only_built_when_its_tab_is_open(): void
    {
        $this->seedActivity();
        $this->actingAsRole(100);

        $this->get(route('workspace.detail', ['workspaceId' => $this->workspace->id, 'tab' => 'general']))
            ->assertOk()
            ->assertSee('data-activity-lazy', false)
            ->assertDontSee('Config backed up: sshd_config');
    }
}
