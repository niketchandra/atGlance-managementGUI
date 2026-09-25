<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AdminSetting;
use App\Models\User;
use App\Services\BackupService;
use App\Services\RestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();

        config([
            'filesystems.disks.s3.key' => 'test-key',
            'filesystems.disks.s3.secret' => 'test-secret',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'test-bucket',
        ]);
        Storage::fake('s3');
        Storage::fake('local');

        AdminSetting::putValue('storage', 's3_enabled', 'true');
        AdminSetting::putValue('storage', 'backup_restore_enabled', 'true');
        AdminSetting::putValue('storage', 'backup_config_to_s3', 'true');
        AdminSetting::putValue('storage', 'backup_portal_to_s3', 'true');
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function seedConfigFile(int $id, string $content): void
    {
        // Parent user/system rows are irrelevant here; the test transaction rolls back before FKs are checked.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('configuration_files')->insert([
            'id' => $id,
            'user_id' => 1,
            'system_register_id' => 1,
            'file_name' => "site{$id}.conf",
            'service_name' => 'nginx',
            'storage_disk' => 'local',
            'file_location' => "config_files/1/site{$id}.conf",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('raw_data')->insert([
            'file_id' => $id,
            'user_id' => 1,
            'system_register_id' => 1,
            'file_name' => "site{$id}.conf",
            'service_name' => 'nginx',
            'file_data' => $content,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Storage::disk('local')->put("config_files/1/site{$id}.conf", $content);
    }

    private function runBackup(string $type): string
    {
        $run = app(BackupService::class)->run($type);
        $this->assertSame('success', $run['status'], $run['message']);

        return $run['object_key'];
    }

    private function restore(string $path, string $source = 's3', string $password = 'secret-pass')
    {
        return $this->post(route('admin.settings.restore'), [
            'source' => $source,
            'path' => $path,
            'password' => $password,
            'confirm_overwrite' => '1',
        ]);
    }

    public function test_lists_s3_backups_and_local_snapshots_only(): void
    {
        $this->actingAsRole(101);
        Storage::disk('s3')->put('backups/config/2026/09/config-backup-20260901-000000.json.gz', 'x');
        Storage::disk('s3')->put('backups/portal/2026/09/portal-backup-20260901-000000.zip', 'x');
        Storage::disk('s3')->put('config_files/1/not-a-backup.conf', 'x');
        Storage::disk('local')->put('backups/snapshots/config/pre-restore-config-backup-20260902-000000.json.gz', 'x');

        $response = $this->getJson(route('admin.settings.backups'))->assertOk();

        $paths = collect($response->json('backups'))->pluck('path')->sort()->values()->all();
        $this->assertSame([
            'backups/config/2026/09/config-backup-20260901-000000.json.gz',
            'backups/portal/2026/09/portal-backup-20260901-000000.zip',
            'backups/snapshots/config/pre-restore-config-backup-20260902-000000.json.gz',
        ], $paths);
        $this->assertFalse($response->json('can_restore_portal'));
    }

    public function test_rejects_paths_outside_backups(): void
    {
        $restores = app(RestoreService::class);

        $this->assertNull($restores->typeFor('s3', 'config_files/1/site.conf'));
        $this->assertNull($restores->typeFor('s3', 'backups/config/../../.env'));
        $this->assertNull($restores->typeFor('local', 'backups/config/2026/09/x.json.gz'));
        $this->assertSame('config', $restores->typeFor('s3', 'backups/config/2026/09/config-backup-20260901-000000.json.gz'));
        $this->assertSame('portal', $restores->typeFor('local', 'backups/snapshots/portal/pre-restore-portal-backup-20260901-000000.zip'));
    }

    public function test_config_restore_brings_back_records_and_files(): void
    {
        $admin = $this->actingAsRole(101);
        $this->seedConfigFile(3001, "server {\n  listen 80;\n}\n");
        $this->seedConfigFile(3002, 'server { listen 81; }');
        $backupKey = $this->runBackup(BackupService::TYPE_CONFIG);

        // Lose one file completely and change another after the backup.
        DB::table('raw_data')->where('file_id', 3001)->delete();
        DB::table('configuration_files')->where('id', 3001)->delete();
        Storage::disk('local')->delete('config_files/1/site3001.conf');
        DB::table('configuration_files')->where('id', 3002)->update(['file_name' => 'renamed.conf']);
        $this->seedConfigFile(3003, 'created after backup');

        $this->restore($backupKey)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('site3001.conf', DB::table('configuration_files')->where('id', 3001)->value('file_name'));
        $this->assertSame('site3002.conf', DB::table('configuration_files')->where('id', 3002)->value('file_name'));
        $this->assertTrue(DB::table('configuration_files')->where('id', 3003)->exists());
        $this->assertSame("server {\n  listen 80;\n}\n", Storage::disk('local')->get('config_files/1/site3001.conf'));

        $snapshots = Storage::disk('local')->files('backups/snapshots/config');
        $this->assertCount(1, $snapshots);

        $log = ActivityLog::query()->latest('id')->first();
        $this->assertSame($admin->id, (int) $log->user_id);
        $this->assertSame(200, (int) $log->status_code);
        $this->assertSame($backupKey, json_decode($log->request_payload, true)['backup']);
    }

    public function test_wrong_password_blocks_restore(): void
    {
        $this->actingAsRole(101);
        $this->seedConfigFile(3001, 'listen 80;');
        $backupKey = $this->runBackup(BackupService::TYPE_CONFIG);
        DB::table('configuration_files')->where('id', 3001)->update(['file_name' => 'renamed.conf']);

        $this->restore($backupKey, 's3', 'wrong-pass')->assertSessionHasErrors(['restore' => 'Password is incorrect.']);

        $this->assertSame('renamed.conf', DB::table('configuration_files')->where('id', 3001)->value('file_name'));
        $this->assertSame([], Storage::disk('local')->allFiles('backups/snapshots'));
    }

    public function test_restore_requires_overwrite_confirmation(): void
    {
        $this->actingAsRole(101);
        $backupKey = $this->runBackup(BackupService::TYPE_CONFIG);

        $this->post(route('admin.settings.restore'), [
            'source' => 's3',
            'path' => $backupKey,
            'password' => 'secret-pass',
        ])->assertSessionHasErrors('confirm_overwrite');
    }

    public function test_admin_cannot_restore_portal_backup(): void
    {
        $this->actingAsRole(101);
        $backupKey = $this->runBackup(BackupService::TYPE_PORTAL);

        $this->restore($backupKey)->assertSessionHasErrors(['restore' => 'Only the super admin can restore a portal backup.']);
    }

    public function test_super_admin_restores_portal_database_and_env(): void
    {
        $superAdmin = $this->actingAsRole(100);
        $this->seedConfigFile(3001, "server {\n  listen 80; # it's; quoted\n}\n");
        file_put_contents(base_path('.env'), "APP_NAME=FromBackup\n");
        $backupKey = $this->runBackup(BackupService::TYPE_PORTAL);

        User::query()->whereKeyNot($superAdmin->id)->delete();
        $extra = new User();
        $extra->forceFill(['name' => 'Later', 'email' => 'later@example.test', 'password_hash' => 'x', 'rbac_id' => 102, 'org_id' => 200])->save();
        DB::table('raw_data')->where('file_id', 3001)->update(['file_data' => 'changed']);
        file_put_contents(base_path('.env'), "APP_NAME=Changed\n");

        $this->restore($backupKey)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertFalse(User::query()->where('email', 'later@example.test')->exists());
        $this->assertTrue(User::query()->whereKey($superAdmin->id)->exists());
        $this->assertSame("server {\n  listen 80; # it's; quoted\n}\n", DB::table('raw_data')->where('file_id', 3001)->value('file_data'));
        $this->assertSame("APP_NAME=FromBackup\n", file_get_contents(base_path('.env')));
        $this->assertCount(1, Storage::disk('local')->files('backups/snapshots/portal'));
        $this->assertSame(200, (int) ActivityLog::query()->latest('id')->value('status_code'));
    }
}
