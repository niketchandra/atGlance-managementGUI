<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\ConfigurationFile;
use App\Support\S3Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class S3StorageSettingsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function saveS3Settings(bool $enabled = true): void
    {
        AdminSetting::putValue('storage', 's3_enabled', $enabled ? 'true' : 'false');
        AdminSetting::putValue('storage', 's3_access_key', 'saved-key');
        AdminSetting::putValue('storage', 's3_secret_key', 'saved-secret', true);
        AdminSetting::putValue('storage', 's3_region', 'eu-west-1');
        AdminSetting::putValue('storage', 's3_bucket', 'saved-bucket');
    }

    public function test_saved_settings_win_over_stale_config(): void
    {
        config([
            'filesystems.disks.s3.key' => 'stale-key',
            'filesystems.disks.s3.secret' => 'stale-secret',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'stale-bucket',
        ]);
        $this->saveS3Settings();

        $this->assertSame('s3', S3Settings::activeDisk());
        $this->assertSame('saved-key', config('filesystems.disks.s3.key'));
        $this->assertSame('saved-secret', config('filesystems.disks.s3.secret'));
        $this->assertSame('saved-bucket', config('filesystems.disks.s3.bucket'));
    }

    public function test_encrypted_env_secret_is_decrypted(): void
    {
        config([
            'filesystems.disks.s3.key' => 'env-key',
            'filesystems.disks.s3.secret' => 'ENC:' . Crypt::encryptString('env-secret'),
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'env-bucket',
        ]);
        AdminSetting::putValue('storage', 's3_enabled', 'true');

        $this->assertSame('env-secret', S3Settings::credentials()['secret']);
        $this->assertSame('s3', S3Settings::activeDisk());
        $this->assertSame('env-secret', config('filesystems.disks.s3.secret'));
    }

    public function test_active_disk_is_local_when_s3_disabled_in_settings(): void
    {
        $this->saveS3Settings(false);

        $this->assertSame('local', S3Settings::activeDisk());
    }

    private function createConfigFileRecord(string $path, string $disk): void
    {
        // Parent user/system rows are irrelevant here; the test transaction rolls back before FKs are checked.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('configuration_files')->insert([
            'user_id' => 1,
            'system_register_id' => 1,
            'file_name' => basename($path),
            'service_name' => 'nginx',
            'storage_disk' => $disk,
            'file_location' => $path,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_local_to_s3_with_nothing_pending_still_switches_file_records(): void
    {
        $this->saveS3Settings();
        AdminSetting::putValue('storage', 'migration_enabled', 'true');
        Storage::fake('local');
        Storage::fake('s3');
        config([
            'filesystems.disks.s3.key' => 'saved-key',
            'filesystems.disks.s3.secret' => 'saved-secret',
            'filesystems.disks.s3.region' => 'eu-west-1',
            'filesystems.disks.s3.bucket' => 'saved-bucket',
        ]);

        // Kept source from an earlier migration: the file is on both disks.
        Storage::disk('local')->put('config_files/1/a.conf', 'listen 80;');
        Storage::disk('s3')->put('config_files/1/a.conf', 'listen 80;');
        $this->createConfigFileRecord('config_files/1/a.conf', 'local');

        $this->actingAsRole(101);
        $this->post(route('admin.settings.migration.start'), ['direction' => 'local_to_s3', 'keep_source' => 1])
            ->assertRedirect()
            ->assertSessionHas('success', 'No files pending migration. Source and destination are already synchronized.');

        $this->assertSame('s3', ConfigurationFile::query()->value('storage_disk'));
    }

    public function test_s3_to_local_turns_off_s3_in_admin_settings(): void
    {
        $this->saveS3Settings();
        AdminSetting::putValue('storage', 'migration_enabled', 'true');
        Storage::fake('local');
        Storage::fake('s3');
        config([
            'filesystems.disks.s3.key' => 'saved-key',
            'filesystems.disks.s3.secret' => 'saved-secret',
            'filesystems.disks.s3.region' => 'eu-west-1',
            'filesystems.disks.s3.bucket' => 'saved-bucket',
        ]);

        Storage::disk('s3')->put('config_files/1/b.conf', 'listen 81;');
        $this->createConfigFileRecord('config_files/1/b.conf', 's3');

        $this->actingAsRole(101);
        $this->post(route('admin.settings.migration.start'), ['direction' => 's3_to_local', 'keep_source' => 1])
            ->assertRedirect();

        Storage::disk('local')->assertExists('config_files/1/b.conf');
        $this->assertSame('local', ConfigurationFile::query()->value('storage_disk'));
        $this->assertSame('false', AdminSetting::getValue('s3_enabled'));
        $this->assertFalse(S3Settings::enabled());
    }
}
