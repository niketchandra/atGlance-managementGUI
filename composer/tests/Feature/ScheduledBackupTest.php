<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Services\BackupService;
use App\Support\BackupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;
use ZipArchive;

class ScheduledBackupTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    /**
     * Settings as saved before each backup had its own section.
     */
    private function enableLegacyBackups(): void
    {
        AdminSetting::putValue('storage', 's3_enabled', 'true');
        AdminSetting::putValue('storage', 'backup_restore_enabled', 'true');
        AdminSetting::putValue('storage', 'backup_config_to_s3', 'true');
        AdminSetting::putValue('storage', 'backup_portal_to_s3', 'true');
        AdminSetting::putValue('storage', 'backup_config_cron', 'every_six_hours');
        AdminSetting::putValue('storage', 'backup_portal_cron', 'weekly');
    }

    private function configure(string $type, array $values): void
    {
        AdminSetting::putValue('storage', 'backup_restore_enabled', 'true');
        BackupSettings::save($type, $values + [
            'enabled' => true, 'frequency' => 'daily', 'cron_expression' => '',
            'to_s3' => false, 'to_local' => true, 'keep_s3' => 7, 'keep_local' => 7,
        ]);
    }

    private function zipEntries(string $disk, string $path): array
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'backup');
        file_put_contents($zipPath, Storage::disk($disk)->get($path));
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($zipPath);

        return $entries;
    }

    public function test_config_backup_uploads_zip_with_tables_and_console_files(): void
    {
        $this->enableLegacyBackups();
        // Parent user/system rows are irrelevant here; the test transaction rolls back before FKs are checked.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('services')->insert(['service_id' => 7, 'service_name' => 'nginx', 'system_id' => 1, 'user_id' => 1]);
        File::ensureDirectoryExists(storage_path('app/public/branding'));
        File::put(storage_path('app/public/branding/test-logo.png'), 'logo-bytes');

        try {
            $this->artisan('backup:config')->assertExitCode(0);

            $run = app(BackupService::class)->lastRun(BackupService::TYPE_CONFIG);
            $this->assertSame('success', $run['status']);
            $this->assertMatchesRegularExpression('#^backups/config/\d{4}/\d{2}/config-backup-\d{8}-\d{6}\.zip$#', $run['object_key']);

            $entries = $this->zipEntries('s3', $run['object_key']);
            $payload = json_decode($entries['config.json'], true);
            $this->assertSame('config', $payload['type']);
            $this->assertSame('nginx', $payload['tables']['services'][0]['service_name']);
            $this->assertArrayHasKey('config_ai_validations', $payload['tables']);
            $this->assertSame('logo-bytes', $entries['files/public/branding/test-logo.png']);
        } finally {
            File::delete(storage_path('app/public/branding/test-logo.png'));
        }
    }

    public function test_database_backup_uploads_gzipped_sql_dump(): void
    {
        $this->enableLegacyBackups();

        $this->artisan('backup:database')->assertExitCode(0);

        $run = app(BackupService::class)->lastRun(BackupService::TYPE_DATABASE);
        $this->assertSame('success', $run['status']);
        $this->assertMatchesRegularExpression('#^backups/database/\d{4}/\d{2}/database-backup-\d{8}-\d{6}\.sql\.gz$#', $run['object_key']);

        $sql = gzdecode(Storage::disk('s3')->get($run['object_key']));
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('backup_portal_cron', $sql);
    }

    public function test_backup_portal_command_still_runs_the_database_backup(): void
    {
        $this->enableLegacyBackups();

        $this->artisan('backup:portal')->assertExitCode(0);

        $this->assertSame('success', app(BackupService::class)->lastRun(BackupService::TYPE_DATABASE)['status']);
    }

    public function test_local_copies_are_kept_and_old_ones_pruned(): void
    {
        $this->configure(BackupSettings::TYPE_DATABASE, ['to_local' => true, 'keep_local' => 2]);
        $backups = app(BackupService::class);

        foreach (['2026-10-01 01:00:00', '2026-10-01 02:00:00', '2026-10-01 03:00:00'] as $time) {
            Carbon::setTestNow($time);
            $this->assertSame('success', $backups->run(BackupService::TYPE_DATABASE)['status']);
        }

        $files = collect(Storage::disk('local')->allFiles('backups/database'))->map(fn ($p) => basename($p))->sort()->values()->all();
        $this->assertSame(['database-backup-20261001-020000.sql.gz', 'database-backup-20261001-030000.sql.gz'], $files);
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $this->assertStringContainsString('removed 1 older copy', $backups->lastRun(BackupService::TYPE_DATABASE)['message']);
    }

    public function test_s3_and_local_keep_their_own_number_of_copies(): void
    {
        AdminSetting::putValue('storage', 's3_enabled', 'true');
        $this->configure(BackupSettings::TYPE_CONFIG, ['to_local' => true, 'to_s3' => true, 'keep_local' => 1, 'keep_s3' => 3]);
        // Files that only look similar must never be pruned.
        Storage::disk('s3')->put('backups/config/2020/01/notes.txt', 'keep me');

        foreach (['2026-10-01 01:00:00', '2026-10-01 02:00:00', '2026-10-01 03:00:00', '2026-10-01 04:00:00'] as $time) {
            Carbon::setTestNow($time);
            app(BackupService::class)->run(BackupService::TYPE_CONFIG);
        }

        $this->assertCount(1, Storage::disk('local')->allFiles('backups/config'));
        $this->assertCount(4, Storage::disk('s3')->allFiles('backups/config'));
        Storage::disk('s3')->assertExists('backups/config/2020/01/notes.txt');
    }

    public function test_backup_is_skipped_when_backup_restore_disabled(): void
    {
        $this->enableLegacyBackups();
        AdminSetting::putValue('storage', 'backup_restore_enabled', 'false');

        $this->artisan('backup:config')->assertExitCode(0);

        $run = app(BackupService::class)->lastRun(BackupService::TYPE_CONFIG);
        $this->assertSame('skipped', $run['status']);
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $this->assertNull(app(BackupService::class)->scheduleExpression(BackupService::TYPE_CONFIG));
    }

    public function test_backup_is_skipped_when_s3_is_the_only_destination_and_disabled(): void
    {
        $this->enableLegacyBackups();
        AdminSetting::putValue('storage', 's3_enabled', 'false');

        $this->artisan('backup:database')->assertExitCode(0);

        $run = app(BackupService::class)->lastRun(BackupService::TYPE_DATABASE);
        $this->assertSame('skipped', $run['status']);
        $this->assertSame('S3 is disabled.', $run['message']);
    }

    public function test_run_now_ignores_the_schedule_switch_but_not_the_master_switch(): void
    {
        $this->configure(BackupSettings::TYPE_DATABASE, ['enabled' => false]);
        $this->actingAsRole(101);

        $this->post(route('admin.settings.backups.run'), ['type' => 'database'])
            ->assertRedirect(route('admin.settings', ['tab' => 'backup-restore']))
            ->assertSessionHas('success');
        $this->assertCount(1, Storage::disk('local')->allFiles('backups/database'));

        AdminSetting::putValue('storage', 'backup_restore_enabled', 'false');
        $this->post(route('admin.settings.backups.run'), ['type' => 'database'])
            ->assertSessionHasErrors('backup_run');
    }

    public function test_failed_backup_is_recorded_and_exits_non_zero(): void
    {
        $this->enableLegacyBackups();
        config(['filesystems.disks.s3.secret' => '']);

        $this->artisan('backup:config')->assertExitCode(1);

        $run = app(BackupService::class)->lastRun(BackupService::TYPE_CONFIG);
        $this->assertSame('failed', $run['status']);
        $this->assertSame('S3 credentials are incomplete.', $run['message']);
    }

    public function test_schedule_expression_follows_preset_or_custom_cron(): void
    {
        $this->enableLegacyBackups();
        $backups = app(BackupService::class);

        $this->assertSame('0 */6 * * *', $backups->scheduleExpression(BackupService::TYPE_CONFIG));
        $this->assertSame('0 0 * * 0', $backups->scheduleExpression(BackupService::TYPE_DATABASE));

        $this->configure(BackupSettings::TYPE_DATABASE, ['frequency' => 'custom', 'cron_expression' => '30 2 * * 1-5']);
        $this->assertSame('30 2 * * 1-5', $backups->scheduleExpression(BackupService::TYPE_DATABASE));

        $this->configure(BackupSettings::TYPE_DATABASE, ['enabled' => false]);
        $this->assertNull($backups->scheduleExpression(BackupService::TYPE_DATABASE));
    }

    public function test_settings_are_saved_per_section_and_validated(): void
    {
        $this->actingAsRole(101);
        $base = [
            'backup_restore_enabled' => '1',
            'backup_database_enabled' => '1', 'backup_database_frequency' => 'custom', 'backup_database_cron_expression' => '15 3 * * *',
            'backup_database_to_local' => '1', 'backup_database_to_s3' => '0', 'backup_database_keep_local' => '10', 'backup_database_keep_s3' => '7',
            'backup_config_enabled' => '1', 'backup_config_frequency' => 'hourly', 'backup_config_cron_expression' => '',
            'backup_config_to_local' => '1', 'backup_config_to_s3' => '0', 'backup_config_keep_local' => '3', 'backup_config_keep_s3' => '7',
        ];

        $this->post(route('admin.settings.backup-restore'), $base)->assertSessionHasNoErrors();

        $database = BackupSettings::get(BackupSettings::TYPE_DATABASE);
        $this->assertSame('15 3 * * *', $database['cron_expression']);
        $this->assertSame(10, $database['keep_local']);
        $this->assertTrue($database['to_local']);
        $this->assertFalse($database['to_s3']);
        $this->assertSame(3, BackupSettings::get(BackupSettings::TYPE_CONFIG)['keep_local']);

        $this->post(route('admin.settings.backup-restore'), ['backup_database_cron_expression' => 'every day'] + $base)
            ->assertSessionHasErrors('backup_database_cron_expression');

        $this->post(route('admin.settings.backup-restore'), ['backup_config_to_local' => '0'] + $base)
            ->assertSessionHasErrors('backup_config_to_local');

        $this->post(route('admin.settings.backup-restore'), ['backup_config_to_s3' => '1'] + $base)
            ->assertSessionHasErrors('backup_config_to_s3');
    }

    public function test_backup_tab_shows_both_sections(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'backup-restore']))
            ->assertOk()
            ->assertSee('Database backup &amp; restore', false)
            ->assertSee('Configuration &amp; console files backup &amp; restore', false)
            ->assertSee(route('admin.settings.backups', ['section' => 'database']), false)
            ->assertSee(route('admin.settings.backups', ['section' => 'files']), false);
    }
}
