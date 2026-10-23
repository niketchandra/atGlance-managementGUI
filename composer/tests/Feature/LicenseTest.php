<?php

namespace Tests\Feature;

use App\Models\PatToken;
use App\Models\User;
use App\Support\License;
use App\Support\Licensing\LicenseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\Support\TestLicense;
use Tests\TestCase;

/**
 * Offline licence files in the console: Admin Settings > Licence, the read-only lock,
 * banners, the licence gates and `license:verify`. Installer: LicenseInstallerTest.
 */
class LicenseTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    protected bool $withLicense = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        // Licences are checked offline: any HTTP call fails the test.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function upload(string $bytes, string $name = 'atglance-licence.atgl'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function newUserPayload(): array
    {
        return [
            'username' => 'newbie',
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => 'newbie@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'user',
        ];
    }

    public function test_first_licence_upload_activates_without_password(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'licence']))
            ->assertOk()
            ->assertSee('No licence.')
            ->assertDontSee('id="license-password"', false);

        $this->post(route('admin.settings.licence'), ['license_file' => $this->upload(TestLicense::issue(['customer' => 'Acme Corp']))])
            ->assertSessionHasNoErrors();

        $this->assertTrue(License::isActive());
        $this->assertSame('Acme Corp', License::summary()['customer']);
    }

    public function test_upload_after_the_activation_window_is_refused_with_the_exact_message(): void
    {
        $this->actingAsRole(100);
        $bytes = TestLicense::issue([], now()->subHours(3)->subMinute());

        $this->post(route('admin.settings.licence'), ['license_file' => $this->upload($bytes)])
            ->assertSessionHasErrors(['license_file' => 'E_ACTIVATION_WINDOW: This license file has expired. Licence files must be installed within 3 hours of download. Please generate a new one at atglance.live.']);

        $this->assertFalse(License::isActive());
    }

    public function test_only_atgl_files_are_accepted(): void
    {
        $this->actingAsRole(100);

        $this->post(route('admin.settings.licence'), ['license_file' => $this->upload(TestLicense::issue(), 'licence.json')])
            ->assertSessionHasErrors('license_file');

        $this->assertFalse(License::isActive());
    }

    public function test_replacing_a_licence_needs_the_password_and_keeps_the_old_one_on_failure(): void
    {
        $this->actingAsRole(100);
        TestLicense::install(['customer' => 'Old Org']);

        $this->get(route('admin.settings', ['tab' => 'licence']))
            ->assertOk()
            ->assertSee('Renew or replace the licence')
            ->assertSee('name="password"', false);

        $renewal = TestLicense::issue(['customer' => 'New Org']);
        $this->post(route('admin.settings.licence'), ['license_file' => $this->upload($renewal)])
            ->assertSessionHasErrors('password');
        $this->post(route('admin.settings.licence'), ['license_file' => $this->upload($renewal), 'password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $tampered = $renewal;
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
        $this->post(route('admin.settings.licence'), ['license_file' => $this->upload($tampered), 'password' => 'secret-pass'])
            ->assertSessionHasErrors(['license_file' => 'E_DECRYPT: ' . LicenseException::MESSAGES['E_DECRYPT']]);
        License::reset();
        $this->assertSame('Old Org', License::summary()['customer']);

        $this->post(route('admin.settings.licence'), ['license_file' => $this->upload($renewal), 'password' => 'secret-pass'])
            ->assertSessionHasNoErrors();
        License::reset();
        $this->assertSame('New Org', License::summary()['customer']);
    }

    public function test_licence_tab_shows_details(): void
    {
        $this->actingAsRole(100);
        TestLicense::install(['customer' => 'Acme Corp', 'plan' => 'enterprise', 'max_users' => 25, 'features' => ['sso', 'mcp_server']]);

        $this->get(route('admin.settings', ['tab' => 'licence']))
            ->assertOk()
            ->assertSee('Licence active')
            ->assertSee('Acme Corp')
            ->assertSee('Enterprise')
            ->assertSee('1 / 25')
            ->assertSee('SSO Login')
            ->assertSee('MCP Server')
            ->assertSee('Days remaining');
    }

    public function test_edited_activation_record_makes_the_licence_invalid(): void
    {
        TestLicense::install();
        $record = $this->licenseDir . '/activation.json';
        $data = json_decode((string) file_get_contents($record), true);
        $data['activated_at'] = 1;
        file_put_contents($record, json_encode($data));
        License::reset();

        $this->assertFalse(License::isActive());
        $this->assertSame('E_RECORD', License::status()['error']);
    }

    public function test_a_file_copied_in_without_activation_is_not_accepted(): void
    {
        @mkdir($this->licenseDir, 0750, true);
        file_put_contents($this->licenseDir . '/license.atgl', TestLicense::issue());
        License::reset();

        $this->assertFalse(License::isActive());
        $this->assertSame('E_RECORD', License::status()['error']);
    }

    public function test_startup_check_ignores_the_activation_window_but_enforces_expiry(): void
    {
        TestLicense::install();

        $this->travel(30)->days();
        License::reset();
        $this->assertTrue(License::isActive());

        $this->travel(400)->days();
        License::reset();
        $this->assertFalse(License::isActive());
        $this->assertSame(License::EXPIRED, License::status()['state']);
    }

    public function test_clock_moved_back_more_than_a_day_refuses_activation(): void
    {
        $this->actingAsRole(100);
        TestLicense::install();

        $this->travel(-2)->days();
        License::reset();

        $this->post(route('admin.settings.licence'), ['license_file' => $this->upload(TestLicense::issue()), 'password' => 'secret-pass'])
            ->assertSessionHasErrors(['license_file' => 'E_CLOCK: ' . LicenseException::MESSAGES['E_CLOCK']]);
        $this->assertTrue(License::status()['clock_warning']);
    }

    public function test_clock_moved_back_less_than_a_day_is_fine(): void
    {
        TestLicense::install();

        $this->travel(-23)->hours();
        License::reset();

        $this->assertFalse(License::status()['clock_warning']);
        License::activate(TestLicense::issue());
        $this->assertTrue(License::isActive());
    }

    public function test_install_id_error_explains_how_to_fix_it(): void
    {
        $this->actingAsRole(100);
        $bytes = TestLicense::issue(['install_id' => '11111111-2222-4333-8444-555555555555']);

        $this->followingRedirects()
            ->post(route('admin.settings.licence'), ['license_file' => $this->upload($bytes)])
            ->assertOk()
            ->assertSee('data-licence-error="E_INSTALL_ID"', false)
            ->assertSee('Licence not accepted (E_INSTALL_ID)')
            ->assertSee('This licence file was generated for another installation.')
            ->assertSee(License::instanceId())
            ->assertSee('Enter the Installation ID above');

        $this->assertFalse(License::isActive());
    }

    public function test_install_bound_licence_is_checked_against_this_console(): void
    {
        $this->expectExceptionObject(new LicenseException(LicenseException::INSTALL_ID));
        License::activate(TestLicense::issue(['install_id' => '11111111-2222-4333-8444-555555555555']));
    }

    public function test_install_bound_licence_for_this_console_is_accepted(): void
    {
        License::activate(TestLicense::issue(['install_id' => License::instanceId()]));

        $this->assertTrue(License::isActive());
    }

    public function test_banners_at_30_7_and_1_days(): void
    {
        TestLicense::install(['expires_at' => now('UTC')->addDays(60)->format('Y-m-d\TH:i:s\Z')]);
        License::reset();
        $this->assertNull(License::banner());

        foreach ([[30, 'info'], [7, 'warning'], [1, 'danger']] as [$days, $level]) {
            TestLicense::install(['expires_at' => now('UTC')->addDays($days)->addHour()->format('Y-m-d\TH:i:s\Z')]);
            License::reset();
            $banner = License::banner();
            $this->assertSame($level, $banner['level'], "$days days");
            $this->assertStringContainsString("in $days day", $banner['text']);
        }

        $this->actingAsRole(100);
        $this->get(route('profile'))->assertOk()->assertSee('ag-notice--danger', false)->assertSee('in 1 day');
    }

    public function test_without_licence_writes_are_blocked_but_reads_and_licence_upload_work(): void
    {
        $this->actingAsRole(100);

        $this->post(route('admin.users.store'), $this->newUserPayload())->assertSessionHasErrors('licence');
        $this->assertDatabaseMissing('users', ['email' => 'newbie@example.test']);

        $this->postJson(route('admin.settings.site'), [])
            ->assertStatus(403)
            ->assertJson(['code' => 'E_NO_LICENSE']);

        $this->get(route('admin.settings', ['tab' => 'licence']))->assertOk();
        $this->post(route('admin.settings.licence'), ['license_file' => $this->upload(TestLicense::issue())])
            ->assertSessionHasNoErrors();

        $this->post(route('admin.users.store'), $this->newUserPayload())->assertSessionDoesntHaveErrors('licence');
        $this->assertDatabaseHas('users', ['email' => 'newbie@example.test']);
    }

    public function test_expired_licence_locks_writes_with_the_expiry_message(): void
    {
        TestLicense::install();
        $this->travel(366)->days();
        License::reset();
        $this->actingAsRole(100);

        $this->postJson(route('settings.api-keys.create'), ['name' => 'prod'])
            ->assertStatus(403)
            ->assertJson(['code' => 'E_EXPIRED']);
        $this->get(route('profile'))->assertOk()->assertSee('The AtGlance licence has expired.');
    }

    public function test_api_key_creation_blocked_without_licence(): void
    {
        $user = $this->actingAsRole(102);

        $this->postJson(route('settings.api-keys.create'), ['name' => 'prod'])
            ->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->assertSame(0, PatToken::query()->where('user_id', $user->id)->count());
    }

    public function test_api_key_creation_allowed_with_licence(): void
    {
        TestLicense::install();
        $this->actingAsRole(102);

        $this->post(route('settings.api-keys.create'), ['name' => 'prod', 'expiration_date' => now()->addMonth()->toDateString()])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_web_registration_blocked_without_licence(): void
    {
        $this->post(route('register'), [
            'name' => 'Someone',
            'email' => 'someone@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors();

        $this->assertDatabaseMissing('users', ['email' => 'someone@example.test']);
    }

    public function test_api_registration_blocked_without_licence(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Someone',
            'email' => 'someone@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(403);

        $this->assertSame(0, User::query()->where('email', 'someone@example.test')->count());
    }

    public function test_sidebar_licence_card_shows_plan_to_the_super_admin(): void
    {
        TestLicense::install(['plan' => 'free']);

        $this->actingAsRole(100);
        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('ag-licence-card', false)
            ->assertSee('Free')
            ->assertSee('Upgrade')
            ->assertSee('Expires On ' . now()->addDays(365)->format('M j, Y'));
    }

    public function test_sidebar_licence_card_asks_the_super_admin_to_add_a_missing_licence(): void
    {
        $this->actingAsRole(100);
        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('Not licensed')
            ->assertSee('Add licence')
            ->assertSee(route('admin.settings', ['tab' => 'licence']), false);
    }

    public function test_admins_and_users_do_not_see_the_sidebar_licence_card(): void
    {
        TestLicense::install();

        foreach ([101, 102] as $rbacId) {
            $this->actingAsRole($rbacId);
            $this->get(route('profile'))
                ->assertOk()
                ->assertDontSee('ag-licence-card', false)
                ->assertDontSee('Add licence');
        }
    }

    public function test_expiry_line_wording(): void
    {
        $this->assertSame('No expiry date', License::expiry('')['text']);
        $this->assertSame('Expires On ' . now()->addDay()->format('M j, Y') . ' (1 day left)', License::expiry(now()->addDay()->toDateString())['text']);
        $this->assertSame('Expired On ' . now()->subDays(3)->format('M j, Y'), License::expiry(now()->subDays(3)->toDateString())['text']);
    }

    public function test_verify_command_reports_status(): void
    {
        $this->artisan('license:verify')->assertExitCode(1);

        TestLicense::install(['customer' => 'Acme Corp']);
        $this->artisan('license:verify')->expectsOutputToContain('Licence active: Acme Corp')->assertExitCode(0);
    }

    public function test_user_limit_counts_users_of_any_role(): void
    {
        $this->actingAsRole(100);
        TestLicense::install(['max_users' => 2]);

        $this->post(route('admin.users.store'), $this->newUserPayload())->assertSessionDoesntHaveErrors('licence');
        $this->assertSame(2, User::query()->count());

        $payload = array_merge($this->newUserPayload(), ['username' => 'third', 'email' => 'third@example.test']);
        $this->post(route('admin.users.store'), $payload)
            ->assertSessionHasErrors(['licence' => 'User limit reached (2/2). Upload a license with a higher user limit.']);
        $this->assertDatabaseMissing('users', ['email' => 'third@example.test']);

        $this->get(route('admin.settings', ['tab' => 'licence']))->assertOk()->assertSee('2 / 2');
    }

    public function test_registration_is_closed_at_the_user_limit(): void
    {
        $this->actingAsRole(102);
        TestLicense::install(['max_users' => 1]);
        auth()->logout();

        $this->postJson('/api/auth/register', [
            'name' => 'Someone',
            'email' => 'someone@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(403)->assertJsonFragment(['message' => License::userLimitMessage()]);

        $this->post(route('register'), [
            'name' => 'Someone',
            'email' => 'someone@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors();

        $this->assertDatabaseMissing('users', ['email' => 'someone@example.test']);
    }

    public function test_instance_id_is_stable(): void
    {
        $this->assertSame(License::instanceId(), License::instanceId());
    }
}
