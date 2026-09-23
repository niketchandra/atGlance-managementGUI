<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class AuthController extends Controller
{
    public function redirectToSso(Request $request, string $provider)
    {
        $provider = strtolower(trim($provider));
        $intent = strtolower(trim((string) $request->query('intent', '')));
        $providerCatalog = config('sso.providers', []);

        if (!isset($providerCatalog[$provider])) {
            return redirect()->route('home')->withErrors([
                'login' => 'Unsupported SSO provider selected.',
            ]);
        }

        $enabledProviders = $this->resolveSsoEnabledProviders(array_keys($providerCatalog));
        $ssoEnabledFlag = $this->resolveSsoEnabledFlag();
        $effectiveSsoEnabled = $ssoEnabledFlag || !empty($enabledProviders);

        if (!$effectiveSsoEnabled) {
            return redirect()->route('home')->withErrors([
                'login' => 'SSO is currently disabled by the administrator.',
            ]);
        }

        if (!in_array($provider, $enabledProviders, true)) {
            return redirect()->route('home')->withErrors([
                'login' => 'This SSO provider is not enabled for your organization.',
            ]);
        }

        $providerUrl = $this->resolveSsoProviderUrl($provider);

        if ($providerUrl === '') {
            return redirect()->route('home')->withErrors([
                'login' => 'SSO URL is not configured for the selected provider.',
            ]);
        }

        if ($provider === 'github') {
            $providerUrl = $this->buildGithubAuthorizeUrl($providerUrl, $request);
        }

        if ($intent === 'pin_reset') {
            $request->session()->put('sso_intent', 'pin_reset');
        } else {
            $request->session()->forget('sso_intent');
        }

        return redirect()->away($providerUrl);
    }

    public function handleSsoCallback(Request $request, string $provider)
    {
        $provider = strtolower(trim($provider));
        $intent = (string) $request->session()->pull('sso_intent', '');

        if ($provider !== 'github') {
            return redirect()->route('home')->withErrors([
                'login' => 'Callback for this SSO provider is not implemented yet.',
            ]);
        }

        if ($request->filled('error')) {
            return redirect()->route('home')->withErrors([
                'login' => 'GitHub login was cancelled or denied.',
            ]);
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->route('home')->withErrors([
                'login' => 'Missing authorization code from GitHub callback.',
            ]);
        }

        $expectedState = (string) $request->session()->pull('sso_state_github', '');
        $returnedState = (string) $request->query('state', '');
        if ($expectedState !== '' && $returnedState !== '' && !hash_equals($expectedState, $returnedState)) {
            return redirect()->route('home')->withErrors([
                'login' => 'Invalid OAuth state received from GitHub.',
            ]);
        }

        [$clientId, $clientSecret] = $this->getProviderClientCredentials('github');

        if ($clientId === '' || $clientSecret === '') {
            return redirect()->route('home')->withErrors([
                'login' => 'GitHub SSO is not fully configured. Set Client ID and Client Secret in admin settings.',
            ]);
        }

        $callbackUrl = route('auth.sso.callback', ['provider' => 'github']);

        $tokenResponse = Http::asForm()
            ->acceptJson()
            ->timeout(15)
            ->post('https://github.com/login/oauth/access_token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $callbackUrl,
            ]);

        if (!$tokenResponse->ok()) {
            return redirect()->route('home')->withErrors([
                'login' => 'Failed to get access token from GitHub.',
            ]);
        }

        $accessToken = (string) $tokenResponse->json('access_token', '');
        if ($accessToken === '') {
            return redirect()->route('home')->withErrors([
                'login' => 'GitHub did not return an access token.',
            ]);
        }

        $githubUserResponse = Http::withToken($accessToken)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'AtGlance-SSO',
                'Accept' => 'application/vnd.github+json',
            ])
            ->timeout(15)
            ->get('https://api.github.com/user');

        if (!$githubUserResponse->ok()) {
            return redirect()->route('home')->withErrors([
                'login' => 'Failed to fetch GitHub profile information.',
            ]);
        }

        $githubUser = $githubUserResponse->json();
        $email = trim((string) ($githubUser['email'] ?? ''));

        if ($email === '') {
            $emailsResponse = Http::withToken($accessToken)
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => 'AtGlance-SSO',
                    'Accept' => 'application/vnd.github+json',
                ])
                ->timeout(15)
                ->get('https://api.github.com/user/emails');

            if ($emailsResponse->ok()) {
                $emails = collect($emailsResponse->json())
                    ->filter(fn ($item) => is_array($item));

                $primaryVerified = $emails->first(fn ($item) => ($item['primary'] ?? false) && ($item['verified'] ?? false));
                $fallback = $emails->first();
                $email = trim((string) (($primaryVerified['email'] ?? null) ?? ($fallback['email'] ?? '')));
            }
        }

        if ($email === '') {
            return redirect()->route('home')->withErrors([
                'login' => 'GitHub account email is required to sign in.',
            ]);
        }

        $displayName = trim((string) (($githubUser['name'] ?? null) ?: ($githubUser['login'] ?? null) ?: 'GitHub User'));

        $user = User::where('email', $email)->first();
        if (!$user) {
            $user = User::create([
                'name' => $displayName,
                'email' => $email,
                'password' => Str::random(32),
            ]);
        }

        if (strtolower((string) ($user->status ?? 'active')) !== 'active') {
            return redirect()->route('home')->withErrors([
                'login' => 'Your account is inactive. Please contact your administrator.',
            ]);
        }

        if ($intent === 'pin_reset' && Auth::check()) {
            /** @var User $currentUser */
            $currentUser = Auth::user();
            if (strcasecmp((string) $currentUser->email, $email) !== 0) {
                $request->session()->forget('pending_pin_reset_pin');
                return redirect()->route('settings')->withErrors([
                    'current_password' => 'User not authenticated. SSO account does not match current user email.',
                ]);
            }

            $pendingPin = (string) $request->session()->pull('pending_pin_reset_pin', '');
            if (preg_match('/^\d{5}$/', $pendingPin) === 1) {
                $currentUser->pin = Hash::make($pendingPin);
                $currentUser->save();

                return redirect()->route('settings')->with('success', 'SSO authentication successful. PIN reset completed.');
            }

            $request->session()->put('sso_pin_verified_at', Carbon::now()->timestamp);
            $request->session()->put('auth_method', 'sso');
            $request->session()->put('auth_sso_provider', $provider);

            return redirect()->route('settings')->with('success', 'SSO re-authentication successful. You can now reset your PIN.');
        }

        if ($intent === 'pin_reset' && !Auth::check()) {
            return redirect()->route('home')->withErrors([
                'login' => 'User not authenticated. Please login first and retry PIN reset.',
            ]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->put('auth_method', 'sso');
        $request->session()->put('auth_sso_provider', $provider);
        $targetRoute = in_array((int) $user->rbac_id, [100, 101], true) ? 'admin.dashboard' : 'dashboard';

        return redirect()->route($targetRoute)->with('success', 'Logged in with GitHub successfully.');
    }

    /**
     * Handle user registration
     */
    public function register(Request $request)
    {
        if ($this->isEmailRegistrationDisabled()) {
            return back()->withErrors([
                'register' => 'Email registration is disabled. Please continue with SSO.',
            ]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // Log the user in
            Auth::login($user);
            $request->session()->put('auth_method', 'password');
            $request->session()->forget(['auth_sso_provider', 'sso_pin_verified_at', 'sso_intent']);
            $targetRoute = in_array((int) $user->rbac_id, [100, 101], true) ? 'admin.dashboard' : 'dashboard';

            return redirect()->route($targetRoute)->with('success', 'Registration successful! Welcome to AtGlance.');
        } catch (\Exception $e) {
            return back()->withErrors(['register' => 'Registration failed. Please try again.']);
        }
    }

    /**
     * Handle user login
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Find the user by email
        $user = User::where('email', $credentials['email'])->first();

        // Check if user exists and password matches
        $passwordMatches = $user ? Hash::check($credentials['password'], $user->password_hash) : false;

        Log::info('web_login_attempt', [
            'email' => $credentials['email'],
            'db_default' => config('database.default'),
            'db_name' => DB::connection()->getDatabaseName(),
            'db_host' => config('database.connections.mysql.host'),
            'user_found' => (bool) $user,
            'password_hash_length' => $user ? strlen((string) $user->password_hash) : 0,
            'password_matches' => $passwordMatches,
        ]);

        if ($user && $passwordMatches) {
            if (strtolower((string) ($user->status ?? 'active')) !== 'active') {
                return back()
                    ->withInput($request->only('email'))
                    ->with('inactive_user', 'User is Inactive please reachout to the Administrator');
            }

            // Log the user in
            Auth::login($user, $request->boolean('remember'));
            $request->session()->regenerate();
            $request->session()->put('auth_method', 'password');
            $request->session()->forget(['auth_sso_provider', 'sso_pin_verified_at', 'sso_intent']);
            $targetRoute = in_array((int) $user->rbac_id, [100, 101], true) ? 'admin.dashboard' : 'dashboard';

            return redirect()->route($targetRoute)->with('success', 'Welcome back!');
        }

        return back()->withErrors([
            'login' => 'The provided credentials do not match our records.',
        ]);
    }

    /**
     * Handle user logout
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'You have been logged out successfully.');
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetLink(Request $request)
    {
        if ($this->isEmailRegistrationDisabled()) {
            return back()->withErrors([
                'email' => 'Password reset is disabled while email registration is turned off. Use SSO sign-in.',
            ]);
        }

        $validated = $request->validate([
            'email' => 'required|email|exists:users',
        ]);

        // Generate a reset token (in a real app, use Laravel's password reset functionality)
        $token = Str::random(64);

        // Store the token in the database or cache
        // For now, just return a message
        return back()->with('status', 'If an account exists with this email, a password reset link will be sent shortly.');
    }

    /**
     * Store contact form submission
     */
    public function storeContact(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|min:10',
        ]);

        try {
            // Here you would typically save the contact message to database
            // and send it to your email
            
            // For now, just return success
            return back()->with('success', 'Thank you for reaching out! We will get back to you soon.');
        } catch (\Exception $e) {
            return back()->withErrors(['contact' => 'Failed to send message. Please try again later.']);
        }
    }

    private function buildGithubAuthorizeUrl(string $providerUrl, Request $request): string
    {
        [$clientId] = $this->getProviderClientCredentials('github');
        if ($clientId === '') {
            return $providerUrl;
        }

        $parts = parse_url($providerUrl);
        $baseUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'github.com') . ($parts['path'] ?? '/login/oauth/authorize');

        parse_str($parts['query'] ?? '', $query);

        $state = Str::random(40);
        $request->session()->put('sso_state_github', $state);

        $callbackUrl = route('auth.sso.callback', ['provider' => 'github']);

        $query = array_merge($query, [
            'client_id' => $query['client_id'] ?? $clientId,
            'redirect_uri' => $query['redirect_uri'] ?? $callbackUrl,
            'scope' => $query['scope'] ?? 'read:user user:email',
            'state' => $query['state'] ?? $state,
        ]);

        return $baseUrl . '?' . http_build_query($query);
    }

    private function getProviderClientCredentials(string $provider): array
    {
        $provider = strtolower(trim($provider));

        $prefix = $this->providerEnvKeyPrefix($provider);
        $clientId = $this->resolveSsoProviderValue('sso_provider_client_ids', $provider, $this->getEnvValue($prefix . '_CLIENT_ID', ''));
        $clientSecret = $this->resolveSsoProviderSecret($provider, $this->getSecretEnvValue($prefix . '_CLIENT_SECRET', ''));

        return [$clientId, $clientSecret];
    }

    private function getEnvValue(string $key, string $default = ''): string
    {
        $fileValue = $this->readEnvFileValue($key);
        if ($fileValue !== null) {
            return $fileValue;
        }

        $value = env($key);
        if ($value !== null && $value !== false) {
            return trim((string) $value);
        }

        $runtime = getenv($key);
        if ($runtime !== false) {
            return trim((string) $runtime);
        }

        return trim($default);
    }

    private function readEnvFileValue(string $key): ?string
    {
        $envPath = base_path('.env');
        if (!is_readable($envPath)) {
            return null;
        }

        $pattern = '/^' . preg_quote($key, '/') . '=(.*)$/m';
        $contents = @file_get_contents($envPath);
        if ($contents === false || preg_match($pattern, $contents, $matches) !== 1) {
            return null;
        }

        $raw = trim((string) ($matches[1] ?? ''));
        if (
            strlen($raw) >= 2
            && str_starts_with($raw, '"')
            && str_ends_with($raw, '"')
        ) {
            $raw = substr($raw, 1, -1);
            $raw = str_replace('\\"', '"', $raw);
        }

        return trim($raw);
    }

    private function getSecretEnvValue(string $key, string $default = ''): string
    {
        $value = $this->getEnvValue($key, $default);
        if ($value === '') {
            return '';
        }

        if (!str_starts_with($value, 'ENC:')) {
            return $value;
        }

        try {
            return trim(Crypt::decryptString(substr($value, 4)));
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveSsoProviderUrl(string $provider): string
    {
        return $this->resolveSsoProviderValue('sso_provider_urls', $provider, $this->getEnvValue($this->providerEnvKeyPrefix($provider) . '_URL', ''));
    }

    private function resolveSsoProviderSecret(string $provider, string $fallback): string
    {
        return $this->resolveSsoProviderValue('sso_provider_client_secrets', $provider, $fallback);
    }

    private function resolveSsoProviderValue(string $settingKey, string $provider, string $fallback): string
    {
        $storedValue = AdminSetting::getValue($settingKey, null);
        if ($storedValue === null || $storedValue === '') {
            return $fallback;
        }

        if (!is_array($storedValue)) {
            $decoded = json_decode((string) $storedValue, true);
            $storedValue = is_array($decoded) ? $decoded : [];
        }

        return trim((string) ($storedValue[$provider] ?? $fallback));
    }

    private function resolveEnabledSsoProvidersFromEnvironment(array $providerKeys): array
    {
        $raw = $this->getEnvValue('SSO_ENABLED_PROVIDERS', '');
        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn (string $provider) => strtolower(trim($provider)))
            ->filter(fn (string $provider) => in_array($provider, $providerKeys, true))
            ->unique()
            ->values()
            ->all();
    }

    private function resolveSsoEnabledFlag(): bool
    {
        $storedValue = AdminSetting::getValue('sso_enabled', null);
        $source = $storedValue !== null ? (string) $storedValue : $this->getEnvValue('SSO_ENABLED', 'false');

        return filter_var($source, FILTER_VALIDATE_BOOL);
    }

    private function resolveSsoEnabledProviders(array $providerKeys): array
    {
        $storedProviders = $this->resolveStoredSsoProviders($providerKeys);
        if (!empty($storedProviders)) {
            return $storedProviders;
        }

        return $this->resolveEnabledSsoProvidersFromEnvironment($providerKeys);
    }

    private function resolveStoredSsoProviders(array $providerKeys): array
    {
        $storedValue = AdminSetting::getValue('sso_enabled_providers', null);
        if ($storedValue === null || $storedValue === '') {
            return [];
        }

        if (is_array($storedValue)) {
            $providers = $storedValue;
        } else {
            $providers = json_decode((string) $storedValue, true);
            if (!is_array($providers)) {
                $providers = array_map('trim', explode(',', (string) $storedValue));
            }
        }

        return collect($providers)
            ->map(fn (string $provider) => strtolower(trim($provider)))
            ->filter(fn (string $provider) => in_array($provider, $providerKeys, true))
            ->unique()
            ->values()
            ->all();
    }

    private function providerEnvKeyPrefix(string $provider): string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', strtolower(trim($provider))));
        $normalized = trim($normalized, '_');

        return 'SSO_' . $normalized;
    }

    private function isEmailRegistrationDisabled(): bool
    {
        $value = (string) AdminSetting::getValue('disable_email_registration', 'false');

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
