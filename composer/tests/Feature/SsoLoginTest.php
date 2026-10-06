<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class SsoLoginTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private const ISSUER = 'https://id.example.com/realms/main';
    private const CLIENT = 'client-123';

    private string $nonce = '';

    /** @var callable */
    private $claimsFn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        $this->activateLicense();
        Http::preventStrayRequests();
        Cache::flush();
        AdminSetting::putValue('sso', 'sso_enabled', 'true');
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function configure(string $provider, array $config, string $secret = 'secret-xyz'): void
    {
        AdminSetting::putValue('sso', 'sso_enabled_providers', [$provider]);
        AdminSetting::putValue('sso', 'sso_provider_config', [$provider => $config + ['client_id' => self::CLIENT]]);
        AdminSetting::putValue('sso', 'sso_provider_client_secrets', [$provider => $secret], true);
    }

    private static function jwt(array $claims): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $encode($claims) . '.c2ln';
    }

    private function fakeOidc(string $issuer, callable $claims, array $discoveryExtra = []): void
    {
        // Later Http::fake calls do not replace earlier stubs, so the token stub reads the current claims.
        $this->claimsFn = $claims;
        Http::fake([
            rtrim($issuer, '/') . '/.well-known/openid-configuration' => Http::response([
                'issuer' => $issuer,
                'authorization_endpoint' => 'https://id.example.com/authorize',
                'token_endpoint' => 'https://id.example.com/token',
                'userinfo_endpoint' => 'https://id.example.com/userinfo',
            ] + $discoveryExtra),
            'https://id.example.com/token' => fn () => Http::response([
                'access_token' => 'at-1',
                'id_token' => self::jwt(($this->claimsFn)($this->nonce)),
            ]),
        ]);
    }

    /** Starts the sign-in, follows it back to the callback, returns the callback response. */
    private function signIn(string $provider, array $query = [])
    {
        $start = $this->get(route('auth.sso.redirect', ['provider' => $provider]));
        $start->assertRedirect();
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->nonce = (string) ($params['nonce'] ?? '');

        return $this->get(route('auth.sso.callback', ['provider' => $provider]) . '?' . http_build_query($query + ['code' => 'code-1', 'state' => $params['state'] ?? '']));
    }

    private function claims(array $extra = []): callable
    {
        return fn ($nonce) => $extra + [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT,
            'sub' => 'user-1',
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => $nonce,
            'email' => 'Alice@Example.com',
            'email_verified' => true,
            'name' => 'Alice',
        ];
    }

    public function test_oidc_redirect_uses_state_nonce_and_pkce(): void
    {
        $this->configure('oidc', ['issuer' => self::ISSUER]);
        $this->fakeOidc(self::ISSUER, $this->claims());

        $response = $this->get(route('auth.sso.redirect', ['provider' => 'oidc']));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://id.example.com/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame(self::CLIENT, $params['client_id']);
        $this->assertSame('code', $params['response_type']);
        $this->assertSame('openid email profile', $params['scope']);
        $this->assertSame(route('auth.sso.callback', ['provider' => 'oidc']), $params['redirect_uri']);
        $this->assertSame('S256', $params['code_challenge_method']);
        $this->assertNotEmpty($params['state']);
        $this->assertNotEmpty($params['nonce']);
    }

    public function test_oidc_sign_in_creates_user_and_logs_in(): void
    {
        $this->configure('oidc', ['issuer' => self::ISSUER]);
        $this->fakeOidc(self::ISSUER, $this->claims());

        $this->signIn('oidc')->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertSame('alice@example.com', Auth::user()->email);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://id.example.com/token'
            && $request['code_verifier'] !== null && $request['grant_type'] === 'authorization_code');
    }

    public function test_callback_without_or_with_wrong_state_is_rejected(): void
    {
        $this->configure('oidc', ['issuer' => self::ISSUER]);
        $this->fakeOidc(self::ISSUER, $this->claims());

        $this->get(route('auth.sso.callback', ['provider' => 'oidc']) . '?code=x')->assertSessionHasErrors('login');
        $this->get(route('auth.sso.redirect', ['provider' => 'oidc']));
        $this->get(route('auth.sso.callback', ['provider' => 'oidc']) . '?code=x&state=forged')->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_wrong_nonce_audience_issuer_or_expiry_is_rejected(): void
    {
        $this->configure('oidc', ['issuer' => self::ISSUER]);
        foreach ([
            fn ($nonce) => ['nonce' => 'other'],
            fn ($nonce) => ['aud' => 'someone-else'],
            fn ($nonce) => ['iss' => 'https://evil.example.com'],
            fn ($nonce) => ['exp' => time() - 3600],
        ] as $override) {
            Cache::flush();
            $base = $this->claims();
            $this->fakeOidc(self::ISSUER, fn ($nonce) => $override($nonce) + $base($nonce));
            $this->signIn('oidc')->assertSessionHasErrors('login');
            $this->assertGuest();
        }
    }

    public function test_unverified_email_is_rejected_unless_trusted(): void
    {
        $this->configure('authentik', ['issuer' => self::ISSUER]);
        $this->fakeOidc(self::ISSUER, $this->claims(['email_verified' => false]));

        $this->signIn('authentik')->assertSessionHasErrors('login');
        $this->assertGuest();

        $this->configure('authentik', ['issuer' => self::ISSUER, 'trust_email' => 'true']);
        $this->signIn('authentik')->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_existing_user_is_matched_by_email(): void
    {
        $user = $this->actingAsRole(102);
        Auth::logout();
        $this->configure('oidc', ['issuer' => self::ISSUER]);
        $this->fakeOidc(self::ISSUER, $this->claims(['email' => $user->email]));

        $this->signIn('oidc')->assertRedirect(route('dashboard'));

        $this->assertSame($user->id, Auth::id());
    }

    public function test_google_workspace_domain_is_enforced(): void
    {
        $this->configure('google', ['allowed_domain' => 'example.com']);
        $google = 'https://accounts.google.com';
        $base = $this->claims();
        $this->fakeOidc($google, fn ($nonce) => ['iss' => $google, 'hd' => 'other.com'] + $base($nonce));

        $start = $this->get(route('auth.sso.redirect', ['provider' => 'google']));
        $this->assertStringContainsString('hd=example.com', $start->headers->get('Location'));
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->nonce = $params['nonce'];
        $this->get(route('auth.sso.callback', ['provider' => 'google']) . '?code=c&state=' . $params['state'])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_microsoft_checks_tenant_and_needs_no_email_verified_claim(): void
    {
        $tenant = '11111111-2222-3333-4444-555555555555';
        $issuer = 'https://login.microsoftonline.com/' . $tenant . '/v2.0';
        $this->configure('microsoft', ['tenant_id' => $tenant]);
        $base = $this->claims();
        $this->fakeOidc($issuer, fn ($nonce) => array_diff_key(['iss' => $issuer, 'tid' => $tenant] + $base($nonce), ['email_verified' => 1]), ['token_endpoint_auth_methods_supported' => ['client_secret_post']]);

        $this->signIn('microsoft')->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://id.example.com/token' && $request['client_secret'] === 'secret-xyz');
    }

    public function test_microsoft_token_from_another_tenant_is_rejected(): void
    {
        $tenant = '11111111-2222-3333-4444-555555555555';
        $issuer = 'https://login.microsoftonline.com/' . $tenant . '/v2.0';
        $this->configure('microsoft', ['tenant_id' => $tenant]);
        $base = $this->claims();
        $this->fakeOidc($issuer, fn ($nonce) => ['iss' => 'https://login.microsoftonline.com/99999999-0000-0000-0000-000000000000/v2.0', 'tid' => '99999999-0000-0000-0000-000000000000'] + $base($nonce));

        $this->signIn('microsoft')->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_github_uses_verified_email_only(): void
    {
        $this->configure('github', []);
        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'gh-token']),
            'https://api.github.com/user' => Http::response(['login' => 'alice', 'name' => 'Alice']),
            'https://api.github.com/user/emails' => Http::sequence()
                ->push([['email' => 'victim@example.com', 'primary' => true, 'verified' => false]])
                ->push([
                    ['email' => 'victim@example.com', 'primary' => false, 'verified' => false],
                    ['email' => 'alice@example.com', 'primary' => true, 'verified' => true],
                ]),
        ]);

        $this->signIn('github')->assertSessionHasErrors('login');
        $this->assertGuest();

        $this->signIn('github')->assertRedirect(route('dashboard'));
        $this->assertSame('alice@example.com', Auth::user()->email);
    }

    public function test_github_redirect_has_state_and_pkce(): void
    {
        $this->configure('github', ['enterprise_url' => 'https://github.example.com']);

        $location = $this->get(route('auth.sso.redirect', ['provider' => 'github']))->headers->get('Location');

        $this->assertStringStartsWith('https://github.example.com/login/oauth/authorize?', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
        $this->assertStringContainsString('scope=read%3Auser%20user%3Aemail', $location);
    }

    public function test_provider_not_fully_set_up_has_no_button_and_cannot_start(): void
    {
        AdminSetting::putValue('sso', 'sso_enabled_providers', ['okta']);

        $this->get(route('auth.sso.redirect', ['provider' => 'okta']))->assertSessionHasErrors('login');
        $this->get(route('home'))->assertDontSee('Continue with Okta');
    }

    public function test_old_azure_ad_settings_map_to_microsoft(): void
    {
        AdminSetting::putValue('sso', 'sso_enabled_providers', ['azure-ad']);
        AdminSetting::putValue('sso', 'sso_provider_client_ids', ['azure-ad' => 'legacy-client']);
        AdminSetting::putValue('sso', 'sso_provider_tenant_ids', ['azure-ad' => 'contoso.onmicrosoft.com']);

        $this->assertSame(['microsoft'], \App\Support\SsoSettings::enabledProviders());
        $this->assertSame('legacy-client', \App\Support\SsoProviders::clientId('microsoft'));
        $this->assertSame('https://login.microsoftonline.com/contoso.onmicrosoft.com/v2.0', \App\Support\SsoProviders::issuer('microsoft'));
    }

    public function test_admin_form_validates_provider_fields(): void
    {
        $this->actingAsRole(100);

        $this->post(route('admin.settings.sso'), [
            'sso_enabled_providers' => ['microsoft'],
            'sso_config' => ['microsoft' => ['client_id' => 'abc', 'tenant_id' => 'common']],
            'sso_secret' => ['microsoft' => 's'],
        ])->assertSessionHasErrors('sso_config.microsoft');

        $this->post(route('admin.settings.sso'), [
            'sso_enabled_providers' => ['gitlab'],
            'sso_config' => ['gitlab' => ['client_id' => 'abc', 'base_url' => 'http://gitlab.local']],
            'sso_secret' => ['gitlab' => 's'],
        ])->assertSessionHasErrors('sso_config.gitlab');

        $this->post(route('admin.settings.sso'), [
            'sso_enabled_providers' => ['gitlab'],
            'sso_config' => ['gitlab' => ['client_id' => 'abc', 'base_url' => 'https://gitlab.example.com']],
            'sso_secret' => ['gitlab' => 's'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('https://gitlab.example.com', \App\Support\SsoProviders::issuer('gitlab'));
        $this->assertSame('s', \App\Support\SsoProviders::clientSecret('gitlab'));

        // A blank secret keeps the saved one.
        $this->post(route('admin.settings.sso'), [
            'sso_enabled_providers' => ['gitlab'],
            'sso_config' => ['gitlab' => ['client_id' => 'abc', 'base_url' => 'https://gitlab.example.com']],
            'sso_secret' => ['gitlab' => ''],
        ])->assertSessionHasNoErrors();
        $this->assertSame('s', \App\Support\SsoProviders::clientSecret('gitlab'));
    }

    public function test_a_provider_with_errors_does_not_lose_the_other_providers_secrets(): void
    {
        $this->actingAsRole(100);

        // Microsoft is complete, GitLab is ticked but missing its secret.
        $this->post(route('admin.settings.sso'), [
            'sso_enabled_providers' => ['microsoft', 'gitlab'],
            'sso_config' => [
                'microsoft' => ['client_id' => 'ms-app', 'tenant_id' => '11111111-2222-3333-4444-555555555555'],
                'gitlab' => ['client_id' => 'gl-app', 'base_url' => 'https://gitlab.com'],
            ],
            'sso_secret' => ['microsoft' => 'ms-secret', 'gitlab' => ''],
        ])->assertSessionHasErrors('sso_config.gitlab')->assertSessionDoesntHaveErrors('sso_config.microsoft');

        $this->assertSame('ms-secret', \App\Support\SsoProviders::clientSecret('microsoft'));
        $this->assertSame(['microsoft'], \App\Support\SsoSettings::enabledProviders());

        // Next save: the Microsoft secret field is blank (it is never sent back), GitLab is now complete.
        $this->post(route('admin.settings.sso'), [
            'sso_enabled_providers' => ['microsoft', 'gitlab'],
            'sso_config' => [
                'microsoft' => ['client_id' => 'ms-app', 'tenant_id' => '11111111-2222-3333-4444-555555555555'],
                'gitlab' => ['client_id' => 'gl-app', 'base_url' => 'https://gitlab.com'],
            ],
            'sso_secret' => ['microsoft' => '', 'gitlab' => 'gl-secret'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['microsoft', 'gitlab'], \App\Support\SsoSettings::enabledProviders());
        $this->assertSame('ms-secret', \App\Support\SsoProviders::clientSecret('microsoft'));
    }

    public function test_sso_tab_shows_setup_steps_and_callback_urls(): void
    {
        $this->actingAsRole(100);
        AdminSetting::putValue('sso', 'sso_enabled_providers', ['microsoft']);

        $this->get(route('admin.settings', ['tab' => 'sso']))
            ->assertOk()
            ->assertSee('Directory (tenant) ID')
            ->assertSee('Entra ID &gt; App registrations', false)
            ->assertSee(route('auth.sso.callback', ['provider' => 'microsoft']), false)
            ->assertSee('Official setup guide')
            ->assertSee('data-split="provider"', false)
            ->assertSee('Tick to enable. Click a name to set it up.')
            ->assertDontSee('Provider Login URLs');
    }
}
