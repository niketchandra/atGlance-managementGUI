<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class ConsoleListsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

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

    private function makeUser(string $email, int $rbac = 102): User
    {
        $user = new User();
        $user->forceFill([
            'name' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'secret-pass',
            'rbac_id' => $rbac,
            'org_id' => 200,
            'status' => 'active',
            'dob' => '1990-01-01',
            'pin' => Hash::make('12345'),
        ])->save();

        return $user;
    }

    private function makeSystem(int $id, User $owner, string $name, int $workspaceId = 0, string $status = 'active'): void
    {
        DB::table('system_register')->insert([
            'id' => $id, 'pat_token_id' => 1, 'user_id' => $owner->id, 'org_id' => 200, 'workspace_id' => $workspaceId,
            'system_name' => $name, 'os_type' => 'Linux', 'ip_address' => '10.0.0.' . ($id % 250),
            'tags' => 'e2e', 'validation_hash' => 'hash-' . $name, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeConfig(User $owner, int $systemId, string $service, string $version, string $createdAt = null): void
    {
        DB::table('configuration_files')->insert([
            'user_id' => $owner->id, 'system_register_id' => $systemId, 'service_name' => $service,
            'file_name' => $service . '.service', 'file_location' => 'config_files/' . $service . '-' . $systemId . '-' . $version,
            'validation_hash' => 'hash-' . $systemId, 'version' => $version, 'status' => 'active',
            'created_at' => $createdAt ?? now(), 'updated_at' => now(),
        ]);
    }

    public function test_users_see_their_own_systems_that_are_not_in_a_workspace(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $other = $this->makeUser('other@example.test');
        $this->makeSystem(900001, $owner, 'own-unassigned-host');
        $this->makeSystem(900002, $other, 'someone-elses-host');

        $this->actingAs($owner)->get(route('systems-registered'))
            ->assertOk()
            ->assertSee('own-unassigned-host')
            ->assertDontSee('someone-elses-host');
    }

    public function test_systems_registered_filters_work(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $this->makeSystem(900001, $owner, 'pi-alpha');
        $this->makeSystem(900002, $owner, 'wsl-beta', 0, 'inactive');

        $this->actingAs($owner)->get(route('systems-registered', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('wsl-beta')
            ->assertDontSee('pi-alpha');

        $this->actingAs($owner)->get(route('systems-registered', ['name' => 'alpha']))
            ->assertOk()
            ->assertSee('pi-alpha')
            ->assertDontSee('wsl-beta');
    }

    public function test_configuration_backups_list_every_system_and_service(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $this->makeSystem(900001, $owner, 'pi-alpha');
        $this->makeSystem(900002, $owner, 'pi-beta');
        $this->makeConfig($owner, 900001, 'ssh', 'v1', now()->subHour()->toDateTimeString());
        $this->makeConfig($owner, 900001, 'ssh', 'v2');
        $this->makeConfig($owner, 900002, 'ssh', 'v1');

        $response = $this->actingAs($owner)->get(route('configuration-backups'))
            ->assertOk()
            ->assertSee('pi-alpha')
            ->assertSee('pi-beta');

        // One row per service and system, with its version count.
        $counts = collect($response->viewData('items')->items())
            ->mapWithKeys(fn ($item) => [$item->system_register_id => (int) $item->version_count]);
        $this->assertSame([900001 => 2, 900002 => 1], $counts->sortKeys()->all());
    }

    public function test_configuration_backups_filters_work(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $this->makeSystem(900001, $owner, 'pi-alpha');
        $this->makeSystem(900002, $owner, 'pi-beta');
        $this->makeConfig($owner, 900001, 'ssh', 'v1');
        $this->makeConfig($owner, 900002, 'cron', 'v1');

        $this->actingAs($owner)->get(route('configuration-backups', ['service_name' => 'cron']))
            ->assertOk()
            ->assertSee('pi-beta')
            ->assertDontSee('pi-alpha');

        $this->actingAs($owner)->get(route('configuration-backups', ['system_id' => 900001]))
            ->assertOk()
            ->assertSee('pi-alpha')
            ->assertDontSee('pi-beta');
    }

    public function test_settings_page_has_no_billing_tab(): void
    {
        $this->actingAsRole(102);
        $this->get(route('settings'))
            ->assertOk()
            ->assertDontSee('Billing');
    }
}
