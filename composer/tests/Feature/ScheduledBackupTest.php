<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ScheduledBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.s3.key' => 'test-key',
            'filesystems.disks.s3.secret' => 'test-secret',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'test-bucket',
        ]);

        Storage::fake('s3');
    }

    private function enableBackups(): void
    {
        AdminSetting::putValue('storage', 's3_enabled', 'true');
        AdminSetting::putValue('storage', 'backup_restore_enabled', 'true');
        AdminSetting::putValue('storage', 'backup_config_to_s3', 'true');
        AdminSetting::putValue('storage', 'backup_portal_to_s3', 'true');
        AdminSetting::putValue('storage', 'backup_config_cron', 'every_six_hours');
        AdminSetting::putValue('storage', 'backup_portal_cron', 'weekly');
    }

    public function test_config_backup_uploads_archive_and_records_success(): void
    {
        $this->enableBackups();
        // Parent user/system rows are irrelevant here; the test transaction rolls back before FKs are checked.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('services')->insert(['service_id' => 7, 'service_name' => 'nginx', 'system_id' => 1, 'user_id' => 1]);

        $this->artisan('backup:config')->assertExitCode(0);

        $run = app(BackupService::class)->lastRun(BackupService::TYPE_CONFIG);
        $this->assertSame('success', $run['status']);
        Storage::disk('s3')->assertExists($run['object_key']);

        $payload = json_decode(gzdecode(Storage::disk('s3')->get($run['object_key'])), true);
        $this->assertSame('config', $payload['type']);
        $this->assertSame('nginx', $payload['tables']['services'][0]['service_name']);
        $this->assertArrayHasKey('raw_data', $payload['tables']);
    }

    public function test_portal_backup_uploads_zip_with_database_dump(): void
    {
        $this->enableBackups();

        $this->artisan('backup:portal')->assertExitCode(0);

        $run = app(BackupService::class)->lastRun(BackupService::TYPE_PORTAL);
        $this->assertSame('success', $run['status']);

        $zipPath = tempnam(sys_get_temp_dir(), 'portal');
        file_put_contents($zipPath, Storage::disk('s3')->get($run['object_key']));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $sql = $zip->getFromName('database.sql');
        $this->assertNotFalse($zip->getFromName('admin_settings.json'));
        $zip->close();
        unlink($zipPath);

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('backup_portal_cron', $sql);
    }

    public function test_backup_is_skipped_when_backup_restore_disabled(): void
    {
        $this->enableBackups();
        AdminSetting::putValue('storage', 'backup_restore_enabled', 'false');

        $this->artisan('backup:config')->assertExitCode(0);

        $run = app(BackupService::class)->lastRun(BackupService::TYPE_CONFIG);
        $this->assertSame('skipped', $run['status']);
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $this->assertNull(app(BackupService::class)->scheduleExpression(BackupService::TYPE_CONFIG));
    }

    public function test_backup_is_skipped_when_s3_disabled(): void
    {
        $this->enableBackups();
        AdminSetting::putValue('storage', 's3_enabled', 'false');

        $this->artisan('backup:portal')->assertExitCode(0);

        $this->assertSame('skipped', app(BackupService::class)->lastRun(BackupService::TYPE_PORTAL)['status']);
    }

    public function test_failed_backup_is_recorded_and_exits_non_zero(): void
    {
        $this->enableBackups();
        config(['filesystems.disks.s3.secret' => '']);

        $this->artisan('backup:config')->assertExitCode(1);

        $run = app(BackupService::class)->lastRun(BackupService::TYPE_CONFIG);
        $this->assertSame('failed', $run['status']);
        $this->assertSame('S3 credentials are incomplete.', $run['message']);
    }

    public function test_schedule_expression_follows_configured_frequency(): void
    {
        $this->enableBackups();
        $backups = app(BackupService::class);

        $this->assertSame('0 */6 * * *', $backups->scheduleExpression(BackupService::TYPE_CONFIG));
        $this->assertSame('0 0 * * 0', $backups->scheduleExpression(BackupService::TYPE_PORTAL));
    }
}
