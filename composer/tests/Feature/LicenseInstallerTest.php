<?php

namespace Tests\Feature;

use App\Support\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestLicense;
use Tests\TestCase;

/**
 * Installer licence step: an .atgl file is required, previewed, and activated on install.
 */
class LicenseInstallerTest extends TestCase
{
    use RefreshDatabase;

    protected bool $withLicense = false;

    private string $marker;
    private ?string $savedMarker = null;
    private ?string $savedEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->marker = storage_path('app/installer/installed.json');
        if (is_file($this->marker)) {
            $this->savedMarker = file_get_contents($this->marker);
            unlink($this->marker);
        }
        $env = base_path('.env');
        $this->savedEnv = is_file($env) ? file_get_contents($env) : null;
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        if ($this->savedMarker !== null) {
            file_put_contents($this->marker, $this->savedMarker);
        }
        $env = base_path('.env');
        if ($this->savedEnv !== null) {
            file_put_contents($env, $this->savedEnv);
        } elseif (is_file($env)) {
            unlink($env);
        }
        parent::tearDown();
    }

    private function upload(string $bytes, string $name = 'atglance-licence.atgl'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function form(array $extra = []): array
    {
        return array_merge([
            'organization_name' => 'Acme Corp',
            'app_ip' => '10.0.0.5',
            'superadmin_email' => 'owner@acme.test',
            'superadmin_password' => 'password123',
            'superadmin_password_confirmation' => 'password123',
        ], $extra);
    }

    public function test_installer_offers_licence_key_and_licence_file(): void
    {
        $this->get(route('install.show'))
            ->assertOk()
            ->assertSee('Step 1 &mdash; Licence', false)
            ->assertSee('This server can reach atglance.live')
            ->assertSee('No internet access')
            ->assertSee('name="license_key"', false)
            ->assertSee('name="license_file"', false)
            ->assertSee('accept=".atgl"', false)
            ->assertSee(License::instanceId())
            ->assertDontSee('name="license_later"', false);
    }

    public function test_install_with_a_licence_key_activates_it_online(): void
    {
        $file = TestLicense::issue(['customer' => 'Acme Corp', 'install_id' => License::instanceId()]);
        Http::fake(['*/api/licenses/activate' => Http::response([
            'valid' => true, 'status' => 'in_use', 'license_file' => base64_encode($file), 'license_file_id' => 'LIC-x',
        ], 201)]);

        $this->post(route('install.run'), $this->form(['license_mode' => 'key', 'license_key' => 'atg_test_key_123']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('install.info'));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/licenses/activate')
            && $request->hasHeader('Authorization', 'Bearer atg_test_key_123')
            && $request['license_file'] === true
            && $request['instance_id'] === License::instanceId()
            && $request['org_name'] === 'Acme Corp');
        License::reset();
        $this->assertTrue(License::isActive());
        $this->assertSame($file, file_get_contents($this->licenseDir . '/license.atgl'));
    }

    public function test_install_with_a_key_in_use_elsewhere_is_refused(): void
    {
        Http::fake(['*/api/licenses/activate' => Http::response(['valid' => false, 'status' => 'in_use_elsewhere'], 409)]);

        $this->post(route('install.run'), $this->form(['license_mode' => 'key', 'license_key' => 'atg_test_key_123']))
            ->assertSessionHasErrors(['license_key' => 'This licence key is already in use by another Management Console. Revoke it on atglance.live and create a new licence, or use another key.']);

        $this->assertFileDoesNotExist($this->marker);
    }

    public function test_install_with_a_key_without_internet_suggests_the_licence_file(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('offline')]);

        $this->post(route('install.run'), $this->form(['license_mode' => 'key', 'license_key' => 'atg_test_key_123']))
            ->assertSessionHasErrors(['license_key' => 'Could not reach atglance.live. If this Management Console has no internet access, use a licence file instead of the licence key.']);
    }

    public function test_verify_key_checks_it_online_without_changing_anything(): void
    {
        Http::fake(['*/api/licenses/verify' => Http::response(['valid' => true, 'status' => 'available', 'license' => ['name' => 'prod'], 'plan' => 'enterprise'])]);

        $this->postJson(route('install.license.verify'), ['license_key' => 'atg_test_key_123'])
            ->assertOk()
            ->assertJson(['ok' => true, 'name' => 'prod', 'plan' => 'enterprise']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/activate'));
    }

    public function test_preview_shows_the_decoded_details(): void
    {
        $bytes = TestLicense::issue(['customer' => 'Acme Corp', 'plan' => 'enterprise', 'max_nodes' => 25, 'max_users' => 0, 'features' => ['premium']]);

        $this->post(route('install.license.preview'), ['license_file' => $this->upload($bytes)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['ok' => true, 'license' => ['customer' => 'Acme Corp', 'plan' => 'Enterprise', 'nodes' => '25', 'users' => 'Unlimited', 'policy' => 'Standard', 'premium' => 'Enabled']])
            ->assertJsonStructure(['license' => ['issued_at', 'expires_at']]);

        $this->assertFalse(License::hasStoredKey(), 'Preview must not save the file');
    }

    public function test_preview_reports_the_error_code(): void
    {
        $bytes = TestLicense::issue([], now()->subHours(4));

        $this->post(route('install.license.preview'), ['license_file' => $this->upload($bytes)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'code' => 'E_ACTIVATION_WINDOW'])
            ->assertJsonFragment(['message' => 'E_ACTIVATION_WINDOW: This license file has expired. Licence files must be installed within 3 hours of download. Please generate a new one at atglance.live.']);
    }

    public function test_install_without_a_licence_completes_and_the_console_is_locked(): void
    {
        $this->post(route('install.run'), $this->form())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('install.info'));

        $this->assertFileExists($this->marker);
        $this->assertSame(License::NONE, License::status()['state']);
    }

    public function test_installer_explains_the_licence_is_optional_and_shows_the_installation_id(): void
    {
        $this->get(route('install.show'))
            ->assertOk()
            ->assertSee('(optional now)', false)
            ->assertSee('You can install without a licence.')
            ->assertSee("This console's Installation ID", false)
            ->assertSee('Purpose:')
            ->assertSee('id="installClientErrors"', false);
    }

    public function test_install_is_blocked_with_an_invalid_licence_file(): void
    {
        $this->post(route('install.run'), $this->form(['license_file' => $this->upload('ATGX' . str_repeat('x', 60))]))
            ->assertSessionHasErrors(['license_file' => 'E_MAGIC: This is not an AtGlance licence file (.atgl).']);

        $this->assertFileDoesNotExist($this->marker);
    }

    public function test_install_activates_the_licence(): void
    {
        $bytes = TestLicense::issue(['customer' => 'Acme Corp']);

        $this->post(route('install.run'), $this->form(['license_file' => $this->upload($bytes)]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('install.info'));

        $this->assertFileExists($this->marker);
        License::reset();
        $this->assertTrue(License::isActive());
        $this->assertSame('Acme Corp', License::summary()['customer']);
        $this->assertSame($bytes, file_get_contents($this->licenseDir . '/license.atgl'));
    }
}
