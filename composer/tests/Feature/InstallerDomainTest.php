<?php

namespace Tests\Feature;

use App\Support\InstallationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallerDomainTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;
    private ?string $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = storage_path('app/installer/installed.json');
        if (is_file($this->marker)) {
            $this->saved = file_get_contents($this->marker);
            unlink($this->marker);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        if ($this->saved !== null) {
            file_put_contents($this->marker, $this->saved);
        }
        parent::tearDown();
    }

    public function test_install_form_has_no_domain_or_https_fields(): void
    {
        $this->get(route('install.show'))
            ->assertOk()
            ->assertSee('name="app_ip"', false)
            ->assertDontSee('name="app_domain"', false)
            ->assertDontSee('name="use_https"', false);
    }

    public function test_info_page_points_to_plugins(): void
    {
        @mkdir(dirname($this->marker), 0755, true);
        InstallationState::markInstalled(['app_ip' => '10.0.0.5', 'app_url' => 'http://10.0.0.5']);

        $this->get(route('install.info'))
            ->assertOk()
            ->assertSee('Custom Domain &amp; HTTPS', false)
            ->assertDontSee('HTTPS Enabled');
    }
}
