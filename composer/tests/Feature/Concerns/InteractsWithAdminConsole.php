<?php

namespace Tests\Feature\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Setup for tests that call /admin routes: an app key for encrypted settings,
 * the installer marker the routes require, and a saved copy of .env for
 * actions that rewrite it.
 */
trait InteractsWithAdminConsole
{
    private string $installedMarker;
    private bool $createdInstalledMarker = false;
    private ?string $originalEnv = null;

    protected function setUpAdminConsole(): void
    {
        // Saved secrets are encrypted; the test env has no APP_KEY of its own.
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);

        $this->installedMarker = storage_path('app/installer/installed.json');
        if (!is_file($this->installedMarker)) {
            @mkdir(dirname($this->installedMarker), 0755, true);
            file_put_contents($this->installedMarker, '{}');
            $this->createdInstalledMarker = true;
        }

        $envPath = base_path('.env');
        $this->originalEnv = is_file($envPath) ? file_get_contents($envPath) : null;
    }

    protected function tearDownAdminConsole(): void
    {
        if ($this->createdInstalledMarker) {
            @unlink($this->installedMarker);
        }

        $envPath = base_path('.env');
        if ($this->originalEnv !== null) {
            file_put_contents($envPath, $this->originalEnv);
        } elseif (is_file($envPath)) {
            unlink($envPath);
        }
    }

    protected function actingAsRole(int $rbacId, string $password = 'secret-pass'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Admin ' . $rbacId,
            'email' => 'admin' . $rbacId . '@example.test',
            'password_hash' => Hash::make($password),
            'password' => Hash::make($password),
            'rbac_id' => $rbacId,
            'org_id' => 200,
            'status' => 'active',
            'dob' => '1990-01-01',
            'pin' => Hash::make('12345'),
        ])->save();

        $this->actingAs($user);

        return $user;
    }
}
