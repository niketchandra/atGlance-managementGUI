<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\ConfigAiValidation;
use App\Models\User;
use App\Services\ConfigAiValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class ConfigAiValidationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private const CONFIG = "[Service]\nExecStart=/usr/sbin/sshd -D\nPasswordAuthentication no\ndb_password = hunter2\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        $this->activateLicense();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function makeUser(string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'secret-pass',
            'rbac_id' => 102,
            'org_id' => 200,
            'status' => 'active',
            'dob' => '1990-01-01',
            'pin' => Hash::make('12345'),
        ])->save();

        return $user;
    }

    private function enableOllama(): void
    {
        AdminSetting::putValue('ai', 'ai_enabled', 'true');
        AdminSetting::putValue('ai', 'ai_provider', 'ollama');
        AdminSetting::putValue('ai', 'ai_base_url', 'http://ollama.test:11434/v1');
        AdminSetting::putValue('ai', 'ai_model', 'llama3.2');
    }

    private function makeConfig(User $owner): int
    {
        $id = DB::table('configuration_files')->insertGetId([
            'user_id' => $owner->id, 'system_register_id' => null, 'service_name' => 'ssh',
            'file_name' => 'ssh.service', 'file_location' => 'config_files/missing-on-disk.service',
            'version' => 'v1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('raw_data')->insert([
            'file_id' => $id, 'user_id' => $owner->id, 'file_data' => self::CONFIG, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function fakeReply(string $content): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $content]]]])]);
    }

    public function test_button_is_hidden_when_ai_connect_is_off(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);

        $this->actingAs($owner)->get(route('configuration-backups.view', $id))
            ->assertOk()
            ->assertDontSee('Validate with AI');
    }

    public function test_button_is_shown_when_ai_connect_is_on(): void
    {
        $this->enableOllama();
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);

        $this->actingAs($owner)->get(route('configuration-backups.view', $id))
            ->assertOk()
            ->assertSee('Validate with AI')
            ->assertSee('Ollama (self-hosted)');
    }

    public function test_validation_returns_findings_and_masks_secrets(): void
    {
        $this->enableOllama();
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);
        $this->fakeReply("```json\n{\"status\":\"warning\",\"summary\":\"One issue.\",\"findings\":[{\"severity\":\"warning\",\"line\":2,\"standard\":\"systemd.service(5)\",\"issue\":\"Missing Restart=\",\"suggestion\":\"Restart the service after a crash.\",\"fix\":\"Restart=on-failure\"}]}\n```");

        $this->actingAs($owner)->postJson(route('configuration-backups.ai-validate', $id))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'warning',
                'summary' => 'One issue.',
                'masked' => 1,
                'findings' => [['severity' => 'warning', 'line' => 2, 'standard' => 'systemd.service(5)', 'issue' => 'Missing Restart=', 'fix' => 'Restart=on-failure']],
            ]);

        Http::assertSent(function (HttpRequest $request) {
            $prompt = $request['messages'][1]['content'] ?? '';

            return str_contains($prompt, 'PasswordAuthentication no')
                && !str_contains($prompt, 'hunter2')
                && str_contains($prompt, 'db_password = ********');
        });
    }

    public function test_description_threats_and_detailed_findings_are_returned(): void
    {
        $this->enableOllama();
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);
        $this->fakeReply(json_encode([
            'status' => 'warning',
            'summary' => 'Hardening missing.',
            'description' => [
                'overview' => 'Starts the OpenSSH server.',
                'settings' => [['setting' => 'ExecStart=/usr/sbin/sshd -D', 'meaning' => 'Runs sshd in the foreground.'], ['meaning' => 'no setting name, dropped']],
            ],
            'threats' => [
                ['threat' => 'Brute force', 'impact' => 'Account takeover', 'mitigation' => 'Key-only login', 'mitigated_here' => true],
                ['threat' => 'Privilege escalation', 'impact' => 'Root access', 'mitigation' => 'Sandboxing', 'mitigated_here' => 'unknown'],
            ],
            'findings' => [[
                'severity' => 'info', 'line' => null, 'standard' => 'systemd.exec(5)', 'issue' => 'No private /tmp',
                'details' => 'sshd shares /tmp.', 'impact' => 'Temp file attacks.', 'suggestion' => 'Add a drop-in.',
                'fix' => 'PrivateTmp=yes', 'steps' => ['1) sudo systemctl edit ssh', "2) sudo sshd -t\n3) sudo systemctl restart ssh"],
            ]],
        ]));

        $this->actingAs($owner)->postJson(route('configuration-backups.ai-validate', $id))
            ->assertOk()
            ->assertJson([
                'description' => [
                    'overview' => 'Starts the OpenSSH server.',
                    'settings' => [['setting' => 'ExecStart=/usr/sbin/sshd -D', 'meaning' => 'Runs sshd in the foreground.']],
                ],
                'threats' => [
                    ['threat' => 'Brute force', 'mitigated_here' => true],
                    ['threat' => 'Privilege escalation', 'mitigated_here' => null],
                ],
                'findings' => [[
                    'details' => 'sshd shares /tmp.',
                    'impact' => 'Temp file attacks.',
                    'steps' => ['sudo systemctl edit ssh', 'sudo sshd -t', 'sudo systemctl restart ssh'],
                ]],
            ])
            ->assertJsonCount(1, 'description.settings');
    }

    public function test_json_with_a_missing_closing_brace_is_repaired(): void
    {
        $this->enableOllama();
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);
        // Real gpt-oss reply: the finding object is never closed before "]".
        $this->fakeReply('{"status":"warning","summary":"Duplicated ExecReload.","findings":[{"severity":"warning","line":11,"issue":"Multiple ExecReload directives","suggestion":"Keep one ExecReload line, e.g. `ExecReload=/bin/sh -c \'a; b\'`."]}');

        $this->actingAs($owner)->postJson(route('configuration-backups.ai-validate', $id))
            ->assertOk()
            ->assertJson([
                'status' => 'warning',
                'summary' => 'Duplicated ExecReload.',
                'findings' => [['severity' => 'warning', 'line' => 11, 'issue' => 'Multiple ExecReload directives']],
            ]);
    }

    public function test_a_reply_that_is_not_json_is_shown_as_text(): void
    {
        $this->enableOllama();
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);
        $this->fakeReply('Looks fine to me.');

        $this->actingAs($owner)->postJson(route('configuration-backups.ai-validate', $id))
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'unknown', 'summary' => 'Looks fine to me.', 'findings' => []]);
    }

    public function test_provider_errors_are_reported(): void
    {
        $this->enableOllama();
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);
        Http::fake(['*' => Http::response(['error' => 'model not found'], 404)]);

        $this->actingAs($owner)->postJson(route('configuration-backups.ai-validate', $id))
            ->assertStatus(502)
            ->assertJson(['success' => false]);
    }

    public function test_validation_is_refused_when_ai_connect_is_off(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);
        Http::fake();

        $this->actingAs($owner)->postJson(route('configuration-backups.ai-validate', $id))
            ->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_users_cannot_validate_someone_elses_config(): void
    {
        $this->enableOllama();
        $owner = $this->makeUser('owner@example.test');
        $other = $this->makeUser('other@example.test');
        $id = $this->makeConfig($owner);
        Http::fake();

        $this->actingAs($other)->postJson(route('configuration-backups.ai-validate', $id))
            ->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_each_run_is_saved_and_listed_in_the_history(): void
    {
        $this->enableOllama();
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);
        $this->fakeReply('{"status":"warning","summary":"Saved summary.","findings":[]}');

        $response = $this->actingAs($owner)->postJson(route('configuration-backups.ai-validate', $id))
            ->assertOk()
            ->assertJson(['status' => 'warning', 'created_by' => 'owner', 'model' => 'llama3.2']);

        $validation = ConfigAiValidation::query()->sole();
        $this->assertSame($id, (int) $validation->configuration_file_id);
        $this->assertSame('Saved summary.', $validation->result['summary']);
        $response->assertJson([
            'id' => $validation->id,
            'share_url' => route('configuration-backups.view', ['id' => $id, 'validation' => $validation->id]),
        ]);

        $this->actingAs($owner)->get(route('configuration-backups.view', $id))
            ->assertOk()
            ->assertSee('AI validation history')
            ->assertSee(route('configuration-backups.ai-validations.show', ['id' => $id, 'validationId' => $validation->id]), false);
    }

    public function test_failed_runs_are_not_saved(): void
    {
        $this->enableOllama();
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

        $this->actingAs($owner)->postJson(route('configuration-backups.ai-validate', $id))->assertStatus(502);
        $this->assertSame(0, ConfigAiValidation::query()->count());
    }

    public function test_saved_result_opens_without_calling_the_ai_and_stays_readable_when_ai_is_off(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);
        $validation = ConfigAiValidation::create([
            'configuration_file_id' => $id, 'user_id' => $owner->id, 'provider' => 'Ollama (self-hosted)', 'model' => 'llama3.2',
            'status' => 'ok', 'summary' => 'Earlier review.',
            'result' => ['status' => 'ok', 'summary' => 'Earlier review.', 'findings' => [], 'description' => ['overview' => 'Runs sshd.', 'settings' => []], 'threats' => [], 'truncated' => false, 'masked' => 0],
        ]);
        Http::fake();

        $this->actingAs($owner)->getJson(route('configuration-backups.ai-validations.show', ['id' => $id, 'validationId' => $validation->id]))
            ->assertOk()
            ->assertJson(['id' => $validation->id, 'summary' => 'Earlier review.', 'description' => ['overview' => 'Runs sshd.']]);

        // Shared link: the page opens with that result, even though AI Connect is off.
        $this->actingAs($owner)->get(route('configuration-backups.view', ['id' => $id, 'validation' => $validation->id]))
            ->assertOk()
            ->assertSee('AI validation history')
            ->assertSee('Earlier review.')
            ->assertDontSee('id="aiValidateBtn"', false);

        Http::assertNothingSent();
    }

    public function test_saved_results_follow_the_configuration_access_rules(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $other = $this->makeUser('other@example.test');
        $id = $this->makeConfig($owner);
        $otherId = $this->makeConfig($other);
        $validation = ConfigAiValidation::create([
            'configuration_file_id' => $id, 'user_id' => $owner->id, 'provider' => 'Ollama (self-hosted)', 'model' => 'llama3.2',
            'status' => 'ok', 'summary' => 'Private.', 'result' => ['status' => 'ok', 'summary' => 'Private.', 'findings' => []],
        ]);

        $this->actingAs($other)->getJson(route('configuration-backups.ai-validations.show', ['id' => $id, 'validationId' => $validation->id]))
            ->assertStatus(403);

        // A validation id from another file is not found under this file.
        $this->actingAs($other)->getJson(route('configuration-backups.ai-validations.show', ['id' => $otherId, 'validationId' => $validation->id]))
            ->assertStatus(404);
    }

    private function makeSavedValidation(int $configId, User $runner): ConfigAiValidation
    {
        return ConfigAiValidation::create([
            'configuration_file_id' => $configId, 'user_id' => $runner->id, 'provider' => 'Ollama (self-hosted)', 'model' => 'llama3.2',
            'status' => 'ok', 'summary' => 'Saved.', 'result' => ['status' => 'ok', 'summary' => 'Saved.', 'findings' => []],
        ]);
    }

    public function test_the_runner_can_delete_a_saved_validation(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $id = $this->makeConfig($owner);
        $validation = $this->makeSavedValidation($id, $owner);

        $this->actingAs($owner)->get(route('configuration-backups.view', $id))
            ->assertSee('class="ag-ai-history-delete"', false);

        $this->actingAs($owner)->deleteJson(route('configuration-backups.ai-validations.delete', ['id' => $id, 'validationId' => $validation->id]))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNull(ConfigAiValidation::find($validation->id));
        $this->assertDatabaseHas('activity_logs', ['user_id' => $owner->id, 'event' => 'config.ai_validation_deleted']);
    }

    public function test_only_the_runner_or_an_admin_can_delete(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $teammate = $this->makeUser('teammate@example.test');
        $workspace = \App\Models\Workspace::query()->create(['name' => 'Ops', 'org_id' => 200, 'status' => 'active']);
        $owner->workspaces()->attach($workspace->id, ['is_admin' => false]);
        $teammate->workspaces()->attach($workspace->id, ['is_admin' => false]);
        DB::table('system_register')->insert([
            'id' => 910001, 'pat_token_id' => 1, 'user_id' => $owner->id, 'org_id' => 200, 'workspace_id' => $workspace->id,
            'system_name' => 'shared-host', 'os_type' => 'Linux', 'ip_address' => '10.0.0.9', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = $this->makeConfig($owner);
        DB::table('configuration_files')->where('id', $id)->update(['system_register_id' => 910001]);
        $validation = $this->makeSavedValidation($id, $owner);
        $url = route('configuration-backups.ai-validations.delete', ['id' => $id, 'validationId' => $validation->id]);

        // The teammate can read the file and its history, but not delete someone else's run.
        $this->actingAs($teammate)->get(route('configuration-backups.view', $id))
            ->assertOk()
            ->assertSee('AI validation history')
            ->assertDontSee('class="ag-ai-history-delete"', false);
        $this->actingAs($teammate)->deleteJson($url)->assertStatus(403);
        $this->assertNotNull(ConfigAiValidation::find($validation->id));

        $this->actingAsRole(100);
        $this->deleteJson($url)->assertOk();
        $this->assertNull(ConfigAiValidation::find($validation->id));
    }

    public function test_users_who_cannot_open_the_file_cannot_delete(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $other = $this->makeUser('other@example.test');
        $id = $this->makeConfig($owner);
        $validation = $this->makeSavedValidation($id, $owner);

        $this->actingAs($other)->deleteJson(route('configuration-backups.ai-validations.delete', ['id' => $id, 'validationId' => $validation->id]))
            ->assertStatus(403);
        $this->assertNotNull(ConfigAiValidation::find($validation->id));
    }

    public function test_masking_keeps_yes_no_settings(): void
    {
        [$masked, $count] = app(ConfigAiValidator::class)->maskSecrets("PasswordAuthentication no\napi_key: abc123\nToken \"xyz\"\n");

        $this->assertStringContainsString('PasswordAuthentication no', $masked);
        $this->assertStringContainsString('api_key: ********', $masked);
        $this->assertStringContainsString('Token ********', $masked);
        $this->assertSame(2, $count);
    }
}
