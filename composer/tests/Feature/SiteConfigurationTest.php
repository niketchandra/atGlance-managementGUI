<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\ContactSubmission;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class SiteConfigurationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    // 1x1 PNG; the test image has no GD extension to generate one.
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function saveSite(array $overrides = [])
    {
        return $this->post(route('admin.settings.site'), array_merge([
            'organization_name' => 'Acme Ops',
            'site_description' => 'Backups for Acme servers.',
            'site_features' => "Encrypted: AES-256 at rest\nAlerts",
            'site_about' => "## Who we are\nAcme **operations** team.<script>alert(1)</script>",
            'faq_question' => ['How do I enroll a host?', ''],
            'faq_answer' => ['Install the `atglance` package.', 'ignored'],
            'site_support_contact_name' => 'Priya Sharma',
            'site_support_contact_email' => 'support@acme.test',
            'site_support_contact_phone' => '+91 98765 43210',
            'site_support_hours' => 'Mon-Fri 09:00-18:00 IST',
            'site_support_request_url' => 'https://help.acme.test/raise-request',
            'site_support_details' => '1. Collect the host name.',
            'site_contact_enabled' => '1',
            'site_contact_intro' => 'We reply within a day.',
        ], $overrides));
    }

    public function test_admin_saves_organization_profile_and_pages(): void
    {
        $this->actingAsRole(101);

        $this->saveSite()->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Acme Ops', Organization::query()->whereKey(200)->value('name'));
        $this->assertSame([['question' => 'How do I enroll a host?', 'answer' => 'Install the `atglance` package.']], json_decode(AdminSetting::getValue('site_faq'), true));
        $this->assertSame('Priya Sharma', AdminSetting::getValue('site_support_contact_name'));
        $this->assertSame('true', AdminSetting::getValue('site_contact_enabled'));
    }

    public function test_request_url_must_be_http(): void
    {
        $this->actingAsRole(101);

        $this->saveSite(['site_support_request_url' => 'javascript:alert(1)'])->assertSessionHasErrors('site_support_request_url');
    }

    public function test_logo_upload_is_served_and_used_in_layout(): void
    {
        $this->actingAsRole(101);

        $this->saveSite(['site_logo' => UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::PNG_BASE64))])->assertSessionHasNoErrors();

        $path = AdminSetting::getValue('site_logo_path');
        Storage::disk('public')->assertExists($path);

        $this->get(route('site.logo', ['path' => $path]))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('admin.settings'))->assertSee('/site-logo/' . $path, false);

        $this->saveSite(['remove_site_logo' => '1'])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($path);
        $this->assertSame('', (string) AdminSetting::getValue('site_logo_path'));
    }

    public function test_guest_home_page_is_white_labelled_with_quick_links(): void
    {
        $this->actingAsRole(101);
        $this->saveSite();
        auth()->logout();

        $response = $this->get(route('home'))->assertOk();

        $response->assertSee('<title>Acme Ops</title>', false);
        $response->assertSee('Welcome to Acme Ops');
        $response->assertDontSee('AtGlance - Configuration Backup Service');
        $response->assertSee('Backups for Acme servers.');
        foreach (['about', 'features', 'faq', 'support', 'contact'] as $page) {
            $response->assertSee(route('public.page', ['page' => $page]), false);
        }
    }

    public function test_pages_without_content_are_hidden(): void
    {
        $this->actingAsRole(101);
        $this->saveSite(['site_about' => '', 'faq_question' => [''], 'faq_answer' => [''], 'site_contact_enabled' => '0']);
        auth()->logout();

        $this->get(route('home'))
            ->assertDontSee(route('public.page', ['page' => 'about']), false)
            ->assertSee(route('public.page', ['page' => 'support']), false);
        $this->get('/about')->assertNotFound();
        $this->get('/faq')->assertNotFound();
        $this->get('/contact')->assertNotFound();
        $this->post(route('contact'), [])->assertNotFound();
    }

    public function test_public_pages_render_markdown_safely(): void
    {
        $this->actingAsRole(101);
        $this->saveSite();
        auth()->logout();

        $this->get('/about')->assertOk()
            ->assertSee('<title>About - Acme Ops</title>', false)
            ->assertSee('<strong>operations</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        $this->get('/faq')->assertOk()
            ->assertSee('How do I enroll a host?')
            ->assertSee('<code>atglance</code>', false);

        $this->get('/features')->assertOk()->assertSee('Encrypted')->assertSee('AES-256 at rest');

        $this->get('/support')->assertOk()
            ->assertSee('Priya Sharma')
            ->assertSee('mailto:support@acme.test', false)
            ->assertSee('tel:+919876543210', false)
            ->assertSee('https://help.acme.test/raise-request', false);
    }

    public function test_contact_form_stores_messages_for_admins(): void
    {
        $admin = $this->actingAsRole(101);
        $this->saveSite();
        auth()->logout();

        $this->post(route('contact'), [
            'name' => 'Ravi',
            'email' => 'ravi@example.test',
            'subject' => 'Access request',
            'message' => 'Please add my new server.',
        ])->assertRedirect(route('public.page', ['page' => 'contact']))->assertSessionHas('success');

        $submission = ContactSubmission::query()->sole();
        $this->assertSame('Access request', $submission->subject);

        $this->actingAs($admin);
        $this->get(route('admin.settings', ['tab' => 'site']))->assertSee('Access request')->assertSee('Please add my new server.');

        $this->delete(route('admin.settings.contact-submissions.delete', ['submissionId' => $submission->id]))->assertRedirect();
        $this->assertSame(0, ContactSubmission::query()->count());
    }

    public function test_contact_form_is_rate_limited(): void
    {
        $this->actingAsRole(101);
        $this->saveSite();
        auth()->logout();

        $message = ['name' => 'Bot', 'email' => 'bot@example.test', 'subject' => 'Hi', 'message' => 'Repeated message text.'];
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('contact'), $message)->assertRedirect();
        }

        $this->post(route('contact'), $message)->assertStatus(429);
        $this->assertSame(5, ContactSubmission::query()->count());
    }
}
