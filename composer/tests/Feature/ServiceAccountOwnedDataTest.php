<?php

namespace Tests\Feature;

use App\Models\ConfigurationFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

/**
 * Pages and the MCP API must still work for config files uploaded by a service account
 * (user_id null).
 */
class ServiceAccountOwnedDataTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    private ConfigurationFile $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        Storage::fake('local');
        $this->withoutMiddleware([\App\Http\Middleware\EnsureApplicationInstalled::class, \App\Http\Middleware\EnsureProfileSetupComplete::class]);

        $registrar = $this->makeUser('registrar@example.test');
        $this->workspace->addUser($registrar->id);
        $account = $this->makeAccount();
        [$plain] = $this->accountKey($account);
        $system = $this->makeSystem($registrar, ['service_account_id' => $account->id]);

        $id = $this->post('/api/v1/config-files', [
            'service_name' => 'sshd',
            'file' => UploadedFile::fake()->createWithContent('sshd_config', "PermitRootLogin no\n"),
        ], ['Authorization' => 'Bearer ' . $plain, 'Accept' => 'application/json'] + $this->systemHeaders($system))->assertCreated()->json('file.id');
        $this->file = ConfigurationFile::findOrFail($id);
    }

    public function test_pages_render_for_member_and_super_admin(): void
    {
        $member = User::where('email', 'registrar@example.test')->first();
        $admin = $this->makeUser('admin@example.test', 100);

        foreach ([$member, $admin] as $viewer) {
            // Admins are sent on to /admin/dashboard.
            $this->actingAs($viewer)->followingRedirects()->get(route('dashboard'))->assertOk();
            $this->actingAs($viewer)->get(route('configuration-backups'))->assertOk()->assertSee('sshd');
            $this->actingAs($viewer)->get(route('configuration-backups.view', $this->file->id))->assertOk();
            $this->actingAs($viewer)->get(route('configuration-backups.download', $this->file->id))->assertOk();
            $this->actingAs($viewer)->get(route('configuration-backups.service-versions', $this->file->service_id))->assertOk();
            $this->actingAs($viewer)->get(route('systems-registered'))->assertOk();
            $this->actingAs($viewer)->get(route('systems-registered.services', ['systemId' => $this->file->system_register_id]))->assertOk();
        }
    }

    public function test_mcp_lists_service_account_files(): void
    {
        $member = User::where('email', 'registrar@example.test')->first();
        [$plain] = $this->userKey($member);

        $this->getJson('/api/mcp/config-files', ['Authorization' => 'Bearer ' . $plain])->assertOk()->assertSee('sshd_config');
        $this->getJson('/api/mcp/config-files/' . $this->file->id, ['Authorization' => 'Bearer ' . $plain])->assertOk();
    }
}
