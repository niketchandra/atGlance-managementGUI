<?php

namespace Tests\Feature;

use App\Models\ConfigurationFile;
use App\Models\SystemRegister;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

/**
 * Task 10: sha256 of config versions (upload, list, latest=1, backfill command).
 */
class ConfigVersionHashTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    private User $owner;
    private SystemRegister $system;
    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        Storage::fake('local');

        $this->owner = $this->makeUser('owner@example.test');
        [$plain] = $this->userKey($this->owner);
        $this->system = $this->makeSystem($this->owner);
        $this->headers = ['Authorization' => 'Bearer ' . $plain, 'Accept' => 'application/json'] + $this->systemHeaders($this->system);
    }

    private function upload(string $service, string $content, ?array $headers = null)
    {
        return $this->post('/api/v1/config-files', [
            'service_name' => $service,
            'file' => UploadedFile::fake()->createWithContent($service . '.conf', $content),
        ], $headers ?? $this->headers);
    }

    /**
     * A version stored before hashes were kept: file on $disk, no content_sha256.
     */
    private function oldVersion(string $service, ?string $content, string $disk = 'local', ?SystemRegister $system = null): ConfigurationFile
    {
        $system ??= $this->system;
        $path = 'config_files/' . $this->owner->id . '/old-' . uniqid() . '.conf';
        if ($content !== null) {
            Storage::disk($disk)->put($path, $content);
        }

        return ConfigurationFile::create([
            'user_id' => $this->owner->id,
            'system_register_id' => $system->id,
            'file_name' => $service . '.conf',
            'service_name' => $service,
            'storage_disk' => $disk,
            'file_location' => $path,
            'version' => 'v1',
            'status' => 'active',
        ]);
    }

    public function test_upload_stores_sha256_of_the_stored_bytes(): void
    {
        $content = "PermitRootLogin no\r\nPort 22\n\t trailing \n";

        $response = $this->upload('ssh', $content)->assertCreated();

        $file = ConfigurationFile::findOrFail($response->json('file.id'));
        $stored = Storage::disk('local')->get($file->file_location);
        $this->assertSame($content, $stored);
        $this->assertSame(hash('sha256', $stored), $file->content_sha256);
        $response->assertExactJson(['file' => [
            'id' => $file->id,
            'file_name' => 'ssh.conf',
            'service_name' => 'ssh',
            'version' => 'v1',
            'created_at' => $file->created_at->toJSON(),
            'sha256' => hash('sha256', $content),
        ]]);
    }

    public function test_legacy_upload_answer_gains_sha256(): void
    {
        $this->post('/api/config-files/upload', [
            'system_id' => $this->system->id,
            'service_name' => 'nginx',
            'validation_hash' => self::SYSTEM_KEY,
            'file' => UploadedFile::fake()->createWithContent('nginx.conf', "worker_processes 2;\n"),
        ], ['Authorization' => $this->headers['Authorization'], 'Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('file.sha256', hash('sha256', "worker_processes 2;\n"))
            ->assertJsonPath('file.service_name', 'nginx')
            ->assertJsonStructure(['message', 'file' => ['id', 'file_name', 'original_name', 'file_location', 'storage_disk', 'file_size', 'validation_hash', 'version', 'created_at']]);
    }

    public function test_list_includes_sha256_and_null_for_old_versions(): void
    {
        $old = $this->oldVersion('ssh', "old\n");
        $newId = $this->upload('ssh', "new\n")->json('file.id');

        $files = $this->getJson('/api/v1/config-files?service_name=ssh', $this->headers)->assertOk()->json('files');

        $byId = collect($files)->keyBy('id');
        $this->assertSame(hash('sha256', "new\n"), $byId[$newId]['sha256']);
        $this->assertArrayHasKey('sha256', $byId[$old->id]);
        $this->assertNull($byId[$old->id]['sha256']);
        // Existing fields are still there.
        $this->assertSame(['id', 'file_name', 'service_name', 'version', 'created_at', 'created_by', 'sha256'], array_keys($byId[$newId]));
    }

    public function test_latest_returns_highest_id_per_service(): void
    {
        $ssh1 = $this->upload('ssh', "a\n")->json('file.id');
        $nginx1 = $this->upload('nginx', "b\n")->json('file.id');
        $ssh2 = $this->upload('ssh', "c\n")->json('file.id');
        $nginx2 = $this->upload('nginx', "d\n")->json('file.id');
        $ssh3 = $this->upload('ssh', "e\n")->json('file.id');
        // A deleted newest version is not "latest".
        $nginx3 = $this->upload('nginx', "f\n")->json('file.id');
        ConfigurationFile::whereKey($nginx3)->update(['status' => 'inactive']);

        // Another system of the same owner, with newer versions of the same services.
        $other = $this->makeSystem($this->owner, ['system_name' => 'web-02', 'validation_hash' => 'k2-' . str_repeat('9', 40)]);
        $otherHeaders = ['Authorization' => $this->headers['Authorization'], 'Accept' => 'application/json'] + $this->systemHeaders($other);
        $otherSsh = $this->upload('ssh', "z\n", $otherHeaders)->json('file.id');
        $this->upload('nginx', "y\n", $otherHeaders)->assertCreated();

        $all = $this->getJson('/api/v1/config-files?latest=1', $this->headers)->assertOk()->json('files');
        $this->assertEqualsCanonicalizing([$ssh3, $nginx2], array_column($all, 'id'));
        $this->assertSame(hash('sha256', "e\n"), collect($all)->firstWhere('id', $ssh3)['sha256']);

        $ssh = $this->getJson('/api/v1/config-files?service_name=ssh&latest=1', $this->headers)->assertOk()->json('files');
        $this->assertSame([$ssh3], array_column($ssh, 'id'));

        $this->getJson('/api/v1/config-files?service_name=ssh&latest=true', $this->headers)->assertOk()->assertJsonCount(1, 'files');

        // latest=0 or no latest: every version, as before.
        $this->assertSame([$ssh3, $ssh2, $ssh1], array_column($this->getJson('/api/v1/config-files?service_name=ssh&latest=0', $this->headers)->json('files'), 'id'));
        $this->assertCount(5, $this->getJson('/api/v1/config-files', $this->headers)->json('files'));
        $this->assertNotContains($nginx1, array_column($all, 'id'));

        // The other system sees only its own rows.
        $this->assertSame([$otherSsh], array_column($this->getJson('/api/v1/config-files?service_name=ssh&latest=1', $otherHeaders)->json('files'), 'id'));

        $this->getJson('/api/v1/config-files?latest=yes', $this->headers)->assertStatus(422)->assertJsonValidationErrors('latest');
    }

    public function test_backfill_fills_old_rows_and_is_idempotent(): void
    {
        Storage::fake('s3');
        Log::spy();

        $local = $this->oldVersion('ssh', "local bytes\n");
        $onS3 = $this->oldVersion('nginx', "s3 bytes\n", 's3');
        $missing = $this->oldVersion('cron', null);
        $uploaded = ConfigurationFile::findOrFail($this->upload('ssh', "fresh\n")->json('file.id'));
        $updatedAt = (string) $local->fresh()->getRawOriginal('updated_at');

        $this->artisan('atglance:config-hash-backfill', ['--chunk' => 1])
            ->expectsOutputToContain('Missing in storage: config file ' . $missing->id)
            ->expectsOutputToContain('Hashed 2 config version(s); 1 missing in storage.')
            ->assertSuccessful();

        $this->assertSame(hash('sha256', "local bytes\n"), $local->fresh()->content_sha256);
        $this->assertSame(hash('sha256', "s3 bytes\n"), $onS3->fresh()->content_sha256);
        $this->assertNull($missing->fresh()->content_sha256);
        $this->assertSame(hash('sha256', "fresh\n"), $uploaded->fresh()->content_sha256);
        $this->assertSame($updatedAt, (string) $local->fresh()->getRawOriginal('updated_at'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'missing in storage') && ($context['file_id'] ?? null) === $missing->id);

        // Rows with a hash are never read or changed again.
        Storage::disk('local')->put($local->file_location, 'changed later');
        $this->artisan('atglance:config-hash-backfill')
            ->expectsOutputToContain('Hashed 0 config version(s); 1 missing in storage.')
            ->assertSuccessful();
        $this->assertSame(hash('sha256', "local bytes\n"), $local->fresh()->content_sha256);

        // Once the missing file is back, the next run fills it.
        Storage::disk('local')->put($missing->file_location, "cron\n");
        $this->artisan('atglance:config-hash-backfill')->expectsOutputToContain('Hashed 1 config version(s); 0 missing in storage.')->assertSuccessful();
        $this->assertSame(hash('sha256', "cron\n"), $missing->fresh()->content_sha256);

        $this->getJson('/api/v1/config-files?service_name=nginx', $this->headers)->assertOk()
            ->assertJsonPath('files.0.sha256', hash('sha256', "s3 bytes\n"));
    }
}
