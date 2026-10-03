<?php

namespace Tests\Feature;

use App\Jobs\ValidateConfigWithAi;
use App\Models\AdminSetting;
use App\Models\NotificationGroup;
use App\Models\Organization;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNotificationPreference;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Message;
use App\Notifications\NotificationEvents;
use App\Services\BackupService;
use App\Services\Notifier;
use App\Services\WorkspaceAiSweep;
use App\Support\WorkspaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;
use ZipArchive;

class WorkspaceSettingsTest extends TestCase
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
        $this->workspace = Workspace::create(['org_id' => 200, 'name' => 'ansible', 'status' => 'active']);
        $this->other = Workspace::create(['org_id' => 200, 'name' => 'kubernetes', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function member(string $email, bool $isAdmin, int $rbacId = 102): User
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
            'dob' => '1990-01-01',
            'pin' => Hash::make('12345'),
        ])->save();
        $this->workspace->addUser($user->id, $isAdmin);

        return $user;
    }

    private function system(Workspace $workspace): SystemRegister
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');

        return SystemRegister::create([
            'id' => random_int(100000, 999999),
            'pat_token_id' => 1,
            'user_id' => 1,
            'org_id' => 200,
            'workspace_id' => $workspace->id,
            'system_name' => 'web-' . $workspace->id,
            'os_type' => 'linux',
            'distro' => 'ubuntu',
            'ip_address' => '10.0.0.5',
            'validation_hash' => 'hash-' . uniqid(),
        ]);
    }

    private function config(SystemRegister $system, string $fileName, string $content = "PermitRootLogin no\n"): int
    {
        $id = DB::table('configuration_files')->insertGetId([
            'user_id' => 1,
            'system_register_id' => $system->id,
            'service_id' => 1,
            'file_name' => $fileName,
            'service_name' => 'ssh',
            'file_location' => '',
            'version' => 'v1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('raw_data')->insert([
            'file_id' => $id,
            'user_id' => 1,
            'system_register_id' => $system->id,
            'service_id' => 1,
            'file_name' => $fileName,
            'service_name' => 'ssh',
            'file_data' => $content,
            'version' => 'v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_workspace_admin_of_any_role_edits_general_and_tags(): void
    {
        $admin = $this->member('lead@example.test', true, 102);
        $this->actingAs($admin);

        $this->put(route('admin.workspaces.settings.general', $this->workspace->id), [
            'name' => 'ansible',
            'description' => 'Config automation',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Config automation', $this->workspace->fresh()->description);

        $this->put(route('admin.workspaces.settings.tags', $this->workspace->id), ['tags' => [
            ['key' => 'env', 'value' => 'prod'],
            ['key' => ' team ', 'value' => 'platform'],
            ['key' => 'ENV', 'value' => 'stage'],
            ['key' => '', 'value' => 'ignored'],
        ]])->assertSessionHasNoErrors();

        $tags = WorkspaceSettings::get($this->workspace->id)['tags'];
        $this->assertSame([['key' => 'ENV', 'value' => 'stage'], ['key' => 'team', 'value' => 'platform']], $tags);
        $this->assertSame(['ENV=stage', 'team=platform'], WorkspaceSettings::tagLabels($tags));

        // Status stays super-admin only.
        $this->put(route('admin.workspaces.settings.general', $this->workspace->id), ['name' => 'ansible', 'status' => 'inactive'])
            ->assertSessionHasErrors('status');

        $this->put(route('admin.workspaces.settings.tags', $this->other->id), ['tags' => []])->assertForbidden();

        $this->actingAs($this->member('plain@example.test', false));
        $this->put(route('admin.workspaces.settings.tags', $this->workspace->id), ['tags' => []])->assertForbidden();
        $this->get(route('admin.workspaces.show', $this->workspace->id))->assertForbidden();
    }

    public function test_user_role_workspace_admin_gets_default_access_until_changed(): void
    {
        $this->actingAs($this->member('lead@example.test', true, 102));

        $this->get(route('admin.workspaces.show', $this->workspace->id))
            ->assertOk()
            ->assertSee('You do not have access to change these settings.');
        $this->put(route('admin.workspaces.settings.ai', $this->workspace->id), ['ai_sweep_frequency' => 'daily'])->assertForbidden();
        $this->post(route('admin.workspaces.backups.run', $this->workspace->id))->assertForbidden();
        $this->put(route('admin.workspaces.settings.notifications', $this->workspace->id), ['events' => []])->assertForbidden();
        $this->assertSame([], $this->get(route('admin.notifications'))->viewData('scopes'));

        $this->actingAs($this->member('boss@example.test', true, 101));
        $this->get(route('admin.workspaces.show', $this->workspace->id))
            ->assertOk()
            ->assertDontSee('You do not have access to change these settings.');
        $this->put(route('admin.workspaces.settings.notifications', $this->workspace->id), ['events' => []])->assertSessionHasNoErrors();
    }

    public function test_admin_role_workspace_admin_chooses_access_for_user_role_admin(): void
    {
        $boss = $this->member('boss@example.test', true, 101);
        $lead = $this->member('lead@example.test', false, 102);
        $plain = $this->member('plain@example.test', false);

        // Promote with only Notifications and Backups.
        $this->actingAs($boss);
        $this->post(route('admin.workspaces.admins.add', $this->workspace->id), [
            'admin_id' => $lead->id,
            'permissions' => ['notifications', 'backups'],
        ])->assertSessionHasNoErrors();

        $access = $this->workspace->fresh()->permissionsFor($lead);
        $this->assertTrue($access['notifications'] && $access['backups']);
        $this->assertFalse($access['general'] || $access['members'] || $access['admins'] || $access['vulnerability_checks']);

        $this->actingAs($lead);
        $this->put(route('admin.workspaces.settings.notifications', $this->workspace->id), ['events' => []])->assertSessionHasNoErrors();
        $this->put(route('admin.workspaces.settings.general', $this->workspace->id), ['name' => 'x'])->assertForbidden();
        $this->delete(route('admin.workspaces.users.remove', [$this->workspace->id, $plain->id]))->assertSessionHasErrors('authorization');
        $this->put(route('admin.workspaces.settings.tags', $this->workspace->id), ['tags' => []])->assertSessionHasNoErrors();
        // A User-role admin cannot change anyone's access, not even their own.
        $this->put(route('admin.workspaces.users.permissions', [$this->workspace->id, $lead->id]), ['permissions' => ['general']])->assertForbidden();

        // Later the Admin-role admin grants member management.
        $this->actingAs($boss);
        $this->put(route('admin.workspaces.users.permissions', [$this->workspace->id, $lead->id]), ['permissions' => ['members']])
            ->assertSessionHasNoErrors();
        $this->actingAs($lead);
        $this->delete(route('admin.workspaces.users.remove', [$this->workspace->id, $plain->id]))->assertSessionHasNoErrors();
        $this->put(route('admin.workspaces.settings.notifications', $this->workspace->id), ['events' => []])->assertForbidden();
    }

    public function test_check_queue_reports_progress(): void
    {
        Queue::fake();
        AdminSetting::putValue('ai', 'ai_enabled', 'true');
        $system = $this->system($this->workspace);
        $this->config($system, 'a.conf');
        $this->config($system, 'b.conf');

        $this->actingAs($this->member('boss@example.test', true, 101));
        $this->post(route('admin.workspaces.ai.run', $this->workspace->id))->assertSessionHasNoErrors();

        $run = \App\Models\WorkspaceAiRun::where('workspace_id', $this->workspace->id)->sole();
        $this->getJson(route('admin.workspaces.ai.progress', $this->workspace->id))
            ->assertJson(['total' => 2, 'processed' => 0, 'percent' => 0, 'finished' => false]);

        \App\Models\WorkspaceAiRun::recordJob($run->id, 'done');
        $this->getJson(route('admin.workspaces.ai.progress', $this->workspace->id))->assertJson(['processed' => 1, 'percent' => 50]);

        \App\Models\WorkspaceAiRun::recordJob($run->id, 'failed');
        $this->getJson(route('admin.workspaces.ai.progress', $this->workspace->id))
            ->assertJson(['processed' => 2, 'done' => 1, 'failed' => 1, 'percent' => 100, 'finished' => true]);
    }

    public function test_reset_queue_stops_the_run_and_its_jobs(): void
    {
        Queue::fake();
        AdminSetting::putValue('ai', 'ai_enabled', 'true');
        $system = $this->system($this->workspace);
        $configId = $this->config($system, 'a.conf');
        $this->config($system, 'b.conf');

        $this->actingAs($this->member('boss@example.test', true, 101));
        $this->post(route('admin.workspaces.ai.run', $this->workspace->id));
        $this->get(route('admin.workspaces.show', ['workspaceId' => $this->workspace->id, 'tab' => 'vulnerability-checks']))->assertSee('Reset queue');

        $this->post(route('admin.workspaces.ai.reset', $this->workspace->id))->assertSessionHasNoErrors();
        $this->getJson(route('admin.workspaces.ai.progress', $this->workspace->id))->assertJson(['cancelled' => true, 'finished' => true]);

        // A job still in the queue ends without calling the AI.
        $reviewer = $this->mock(\App\Services\ConfigAiReviewer::class);
        $reviewer->shouldNotReceive('review');
        $runId = \App\Models\WorkspaceAiRun::sole()->id;
        (new ValidateConfigWithAi($configId, 'schedule', $runId))->handle($reviewer);

        // A User-role workspace admin without Vulnerability Checks access cannot reset.
        $this->actingAs($this->member('lead@example.test', true, 102));
        $this->post(route('admin.workspaces.ai.reset', $this->workspace->id))->assertForbidden();
    }

    public function test_user_role_workspace_admin_cannot_remove_admin_role_workspace_admin(): void
    {
        $userRoleAdmin = $this->member('lead@example.test', true, 102);
        $adminRoleAdmin = $this->member('boss@example.test', true, 101);
        $plain = $this->member('plain@example.test', false);

        $this->actingAs($userRoleAdmin);
        $this->delete(route('admin.workspaces.users.remove', [$this->workspace->id, $adminRoleAdmin->id]))->assertSessionHasErrors('authorization');
        $this->assertTrue($this->workspace->hasUserAsAdmin($adminRoleAdmin->id));

        $this->delete(route('admin.workspaces.users.remove', [$this->workspace->id, $plain->id]))->assertSessionHasNoErrors();
        $this->assertFalse($this->workspace->users()->where('users.id', $plain->id)->exists());

        // An Admin-role workspace admin can remove the User-role one.
        $this->actingAs($adminRoleAdmin);
        $this->delete(route('admin.workspaces.users.remove', [$this->workspace->id, $userRoleAdmin->id]))->assertSessionHasNoErrors();
        $this->assertFalse($this->workspace->hasUserAsAdmin($userRoleAdmin->id));
    }

    public function test_settings_page_shows_every_tab(): void
    {
        $this->actingAs($this->member('lead@example.test', true, 101));

        $this->get(route('admin.workspaces.show', $this->workspace->id))
            ->assertOk()
            ->assertSee('Workspace Tags')
            ->assertSee('Vulnerability Checks')
            ->assertSee('Backup History')
            ->assertSee('Member Email Choices');
    }

    public function test_custom_cron_must_be_valid(): void
    {
        $this->actingAs($this->member('lead@example.test', true, 101));

        $this->put(route('admin.workspaces.settings.ai', $this->workspace->id), [
            'ai_sweep_enabled' => 1,
            'ai_sweep_frequency' => 'custom',
            'ai_sweep_cron' => 'every day',
        ])->assertSessionHasErrors('ai_sweep_cron');

        $this->put(route('admin.workspaces.settings.ai', $this->workspace->id), [
            'ai_on_upload' => 1,
            'ai_sweep_enabled' => 1,
            'ai_sweep_frequency' => 'custom',
            'ai_sweep_cron' => '*/30 * * * *',
        ])->assertSessionHasNoErrors();

        $settings = WorkspaceSettings::get($this->workspace->id);
        $this->assertTrue($settings['ai_on_upload']);
        $this->assertSame('*/30 * * * *', WorkspaceSettings::expression($settings, 'ai_sweep'));
    }

    public function test_sweep_queues_latest_versions_only_and_skips_reviewed(): void
    {
        Queue::fake();
        AdminSetting::putValue('ai', 'ai_enabled', 'true');

        $system = $this->system($this->workspace);
        $old = $this->config($system, 'sshd_config');
        $latest = $this->config($system, 'sshd_config');
        $reviewed = $this->config($system, 'nginx.conf');
        DB::table('config_ai_validations')->insert([
            'configuration_file_id' => $reviewed, 'user_id' => null, 'trigger' => 'manual',
            'provider' => 'x', 'model' => 'y', 'status' => 'ok', 'result' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->config($this->system($this->other), 'other.conf');

        $run = app(WorkspaceAiSweep::class)->run($this->workspace->id);

        $this->assertSame(1, $run['queued']);
        Queue::assertPushed(ValidateConfigWithAi::class, fn ($job) => $job->configurationFileId === $latest);
        Queue::assertNotPushed(ValidateConfigWithAi::class, fn ($job) => in_array($job->configurationFileId, [$old, $reviewed], true));
        $this->assertFalse(ValidateConfigWithAi::isLatestVersion($old));
    }

    public function test_workspace_backup_contains_only_that_workspace(): void
    {
        Storage::fake('local');
        $system = $this->system($this->workspace);
        $this->config($system, 'sshd_config');
        $this->config($this->system($this->other), 'other.conf');
        WorkspaceSettings::save($this->workspace->id, ['backup_to_local' => true, 'backup_notes' => 'Before upgrade']);

        $run = app(BackupService::class)->runWorkspace($this->workspace->id);

        $this->assertSame(BackupService::STATUS_SUCCESS, $run->status, (string) $run->message);
        $this->assertStringStartsWith('backups/workspace-' . $this->workspace->id . '/', $run->object_key);

        $zip = new ZipArchive();
        $zip->open(Storage::disk('local')->path($run->object_key));
        $tables = json_decode($zip->getFromName('config.json'), true)['tables'];
        $this->assertCount(1, $tables['configuration_files']);
        $this->assertSame('sshd_config', $tables['configuration_files'][0]['file_name']);
        $this->assertSame("Before upgrade\n", $zip->getFromName('notes.txt'));
        $zip->close();
    }

    public function test_notifier_respects_workspace_events_and_member_choices(): void
    {
        AdminSetting::putValue('notification', 'notify_email_allowed', 'true');
        $sent = new \ArrayObject();
        $this->app->instance(EmailChannel::class, new class($sent) extends EmailChannel {
            public function __construct(private \ArrayObject $sent)
            {
            }

            public function send(NotificationGroup $group, Message $message): void
            {
                $this->sent[] = ['group', $message->event, $group->targetList()];
            }

            public function sendToMembers(array $addresses, Message $message): void
            {
                $this->sent[] = ['members', $message->event, $addresses];
            }
        });

        $this->member('a@example.test', false);
        $optedOut = $this->member('b@example.test', false);
        $this->member('dl@example.test', false);
        NotificationGroup::create([
            'workspace_id' => $this->workspace->id, 'channel' => 'email', 'name' => 'DL',
            'target' => 'dl@example.test', 'events' => [NotificationEvents::CONFIG_UPLOADED], 'enabled' => true,
        ]);
        WorkspaceSettings::save($this->workspace->id, [
            'events' => [NotificationEvents::CONFIG_UPLOADED],
            'member_email_default_events' => [NotificationEvents::CONFIG_UPLOADED],
        ]);
        WorkspaceNotificationPreference::create(['workspace_id' => $this->workspace->id, 'user_id' => $optedOut->id, 'events' => [], 'email_enabled' => true]);

        $notifier = app(Notifier::class);
        $notifier->notify(NotificationEvents::CONFIG_UPLOADED, $this->workspace->id, 'Uploaded');
        $notifier->notify(NotificationEvents::SYSTEM_REGISTERED, $this->workspace->id, 'Registered');

        $this->assertEquals([
            ['group', NotificationEvents::CONFIG_UPLOADED, ['dl@example.test']],
            // b opted out; dl@ already got it through the group.
            ['members', NotificationEvents::CONFIG_UPLOADED, ['a@example.test']],
        ], $sent->getArrayCopy());
    }

    public function test_member_saves_own_notification_choice(): void
    {
        $user = $this->member('a@example.test', false);
        WorkspaceSettings::save($this->workspace->id, ['events' => [NotificationEvents::AI_ISSUES_FOUND]]);
        $this->actingAs($user);

        $this->post(route('settings.notifications'), [
            'workspace_id' => $this->workspace->id,
            'events' => [NotificationEvents::AI_ISSUES_FOUND, NotificationEvents::SYSTEM_REGISTERED],
            'email_enabled' => 1,
        ])->assertRedirect();

        // Events the workspace does not send are dropped.
        $this->assertSame(
            [NotificationEvents::AI_ISSUES_FOUND],
            WorkspaceNotificationPreference::where('user_id', $user->id)->sole()->events
        );

        $this->post(route('settings.notifications'), ['workspace_id' => $this->other->id, 'events' => []])->assertForbidden();
    }
}
