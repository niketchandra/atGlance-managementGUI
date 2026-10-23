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

    public function test_installer_asks_for_a_licence_file(): void
    {
        $this->get(route('install.show'))
            ->assertOk()
            ->assertSee('Step 1 &mdash; Licence File', false)
            ->assertDontSee('name="license_key"', false)
            ->assertSee('name="license_file"', false)
            ->assertSee('accept=".atgl"', false)
            ->assertSee(License::instanceId())
            ->assertDontSee('name="license_later"', false);
    }

    public function test_preview_shows_the_decoded_details(): void
    {
        $bytes = TestLicense::issue(['customer' => 'Acme Corp', 'plan' => 'enterprise', 'max_nodes' => 25, 'max_users' => 0, 'features' => ['sso']]);

        $this->post(route('install.license.preview'), ['license_file' => $this->upload($bytes)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['ok' => true, 'license' => ['customer' => 'Acme Corp', 'plan' => 'Enterprise', 'nodes' => '25', 'users' => 'Unlimited', 'policy' => 'Standard', 'features' => 'SSO Login']])
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

    public function test_install_is_blocked_without_a_licence_file(): void
    {
        $this->post(route('install.run'), $this->form())->assertSessionHasErrors('license_file');

        $this->assertFileDoesNotExist($this->marker);
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
