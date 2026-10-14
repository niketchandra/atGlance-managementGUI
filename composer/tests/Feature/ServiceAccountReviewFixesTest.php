<?php

namespace Tests\Feature;

use App\Models\ConfigurationFile;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

/**
 * Findings of the final 2.1.2 review: service version history, reactivation notice
 * subscriptions, the expiry-notice rate limit and the new key in the session.
 */
class ServiceAccountReviewFixesTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        Storage::fake('local');
    }

    private function upload(array $headers)
    {
        return $this->post('/api/v1/config-files', [
            'service_name' => 'sshd',
            'file' => UploadedFile::fake()->createWithContent('sshd_config', "PermitRootLogin no\n"),
        ], $headers + ['Accept' => 'application/json']);
    }

    public function test_person_upload_continues_service_created_by_service_account(): void
    {
        $person = $this->makeUser('person@example.test');
        $this->workspace->addUser($person->id);
        $account = $this->makeAccount();
        [$accountKey] = $this->accountKey($account);
        [$personKey] = $this->userKey($person);
        $system = $this->makeSystem($person, ['service_account_id' => $account->id]);

        $this->upload(['Authorization' => 'Bearer ' . $accountKey] + $this->systemHeaders($system))->assertCreated();
        $second = $this->upload(['Authorization' => 'Bearer ' . $personKey] + $this->systemHeaders($system))->assertCreated();

        $this->assertSame(1, Service::where('system_id', $system->id)->count());
        $this->assertSame('v2', $second->json('file.version'));
    }

    public function test_service_account_history_survives_deleting_the_person(): void
    {
        $person = $this->makeUser('person@example.test');
        $admin = $this->makeUser('admin@example.test', 101);
        $system = $this->makeSystem($person);
        [$personKey] = $this->userKey($person);
        $this->upload(['Authorization' => 'Bearer ' . $personKey] + $this->systemHeaders($system))->assertCreated();

        $account = $this->makeAccount();
        app(ServiceAccounts::class)->assignSystem($system, $account, $admin);
        [$accountKey] = $this->accountKey($account);
        $headers = ['Authorization' => 'Bearer ' . $accountKey] + $this->systemHeaders($system);
        $this->upload($headers)->assertCreated()->assertJsonPath('file.version', 'v2');

        $person->delete();

        $this->assertSame(1, Service::where('system_id', $system->id)->count());
        $this->upload($headers)->assertCreated()->assertJsonPath('file.version', 'v3');
        $this->assertSame(2, ConfigurationFile::where('system_register_id', $system->id)->whereNotNull('service_id')->count());
    }

    public function test_reactivated_event_added_wherever_registered_was_chosen(): void
    {
        $member = $this->makeUser('member@example.test');
        DB::table('workspace_settings')->insert([
            'workspace_id' => $this->workspace->id,
            'settings' => json_encode(['events' => ['system.registered'], 'member_email_default_events' => ['system.registered']]),
        ]);
        DB::table('workspace_notification_preferences')->insert([
            'workspace_id' => $this->workspace->id,
            'user_id' => $member->id,
            'events' => json_encode(['system.registered']),
        ]);

        $migration = require database_path('migrations/2026_10_13_010000_subscribe_reactivated_event.php');
        $migration->up();
        $migration->up();

        $settings = json_decode(DB::table('workspace_settings')->where('workspace_id', $this->workspace->id)->value('settings'), true);
        $this->assertSame(['system.registered', 'system.reactivated'], $settings['events']);
        $this->assertSame(['system.registered', 'system.reactivated'], $settings['member_email_default_events']);
        $this->assertSame(['system.registered', 'system.reactivated'], json_decode(DB::table('workspace_notification_preferences')->value('events'), true));
    }

    public function test_expiry_notice_limit_is_per_key_not_per_ip(): void
    {
        $account = $this->makeAccount();
        [$first] = $this->accountKey($account, ['expires_at' => now()->addDays(60)]);
        [$second] = $this->accountKey($account, ['expires_at' => now()->addDays(60)]);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/credentials/expiry-notice', [], ['Authorization' => 'Bearer ' . $first])->assertOk();
        }
        $this->postJson('/api/v1/credentials/expiry-notice', [], ['Authorization' => 'Bearer ' . $first])->assertStatus(429);
        $this->postJson('/api/v1/credentials/expiry-notice', [], ['Authorization' => 'Bearer ' . $second])->assertOk();
    }

    public function test_new_key_is_not_kept_in_plain_text_in_the_session(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\EnsureApplicationInstalled::class, \App\Http\Middleware\EnsureProfileSetupComplete::class]);
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);

        $this->actingAs($admin)->post(route('service-accounts.keys.store', $account->id), ['name' => 'fleet'])->assertRedirect();

        $flashed = (string) session('new_service_account_key');
        $this->assertStringNotContainsString('atgsa-', $flashed);
        $this->actingAs($admin)->get(route('service-accounts.show', $account->id))->assertOk()->assertSee('atgsa-');
    }
}
