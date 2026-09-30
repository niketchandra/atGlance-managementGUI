<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\InstallationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InstallInfoPageTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;
    private ?string $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->marker = storage_path('app/installer/installed.json');
        if (is_file($this->marker)) {
            $this->saved = file_get_contents($this->marker);
        }
        InstallationState::markInstalled([
            'app_url' => 'http://10.0.0.5',
            'superadmin_email' => 'owner@acme.test',
            'superadmin_password' => 'first-password',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        if ($this->saved !== null) {
            file_put_contents($this->marker, $this->saved);
        }
        parent::tearDown();
    }

    private function logIn(): void
    {
        $user = new User();
        $user->forceFill([
            'name' => 'owner',
            'email' => 'owner@acme.test',
            'password_hash' => Hash::make('first-password'),
            'password' => Hash::make('first-password'),
            'rbac_id' => 100,
            'org_id' => 200,
            'status' => 'active',
        ])->save();

        Auth::login($user);
        Auth::logout();
    }

    public function test_info_page_is_shown_until_first_login_with_a_note(): void
    {
        $this->get(route('install.info'))
            ->assertOk()
            ->assertSee('first-password')
            ->assertSee('This page is only available until the first login');
    }

    public function test_info_page_redirects_to_login_after_first_login(): void
    {
        $this->logIn();

        $this->get(route('install.info'))->assertRedirect(route('home'));
    }

    public function test_first_login_removes_the_stored_password(): void
    {
        $this->logIn();

        $this->assertArrayNotHasKey('superadmin_password', InstallationState::getData());
        $this->assertNotEmpty(InstallationState::getData()['first_login_at'] ?? null);
        $this->assertTrue(InstallationState::isInstalled());
    }
}
