<?php

namespace App\Http\Controllers;

use App\Support\AccountAlerts;
use App\Support\ActivityRecorder;
use App\Services\Sso\GithubClient;
use App\Services\Sso\OidcClient;
use App\Services\Sso\SsoException;
use App\Support\License;
use App\Support\SsoProviders;
use App\Support\SsoSettings;
use App\Support\UserAgent;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class AuthController extends Controller
{
    public function redirectToSso(Request $request, string $provider)
    {
        $provider = SsoProviders::normalizeKey($provider);
        $intent = strtolower(trim((string) $request->query('intent', '')));

        if (!SsoProviders::exists($provider)) {
            return redirect()->route('home')->withErrors(['login' => 'Unsupported SSO provider selected.']);
        }
        if (!SsoSettings::enabled()) {
            return redirect()->route('home')->withErrors(['login' => 'SSO is currently disabled by the administrator.']);
        }
        if (!in_array($provider, SsoSettings::enabledProviders(), true)) {
            return redirect()->route('home')->withErrors(['login' => 'This SSO provider is not enabled for your organization.']);
        }
        if (SsoProviders::missingFields($provider) !== []) {
            return redirect()->route('home')->withErrors(['login' => SsoProviders::label($provider) . ' sign-in is not fully set up. Ask your administrator.']);
        }

        $flow = [
            'provider' => $provider,
            'intent' => $intent === 'pin_reset' ? 'pin_reset' : '',
            'started_at' => time(),
        ];

        try {
            $url = SsoProviders::definition($provider)['type'] === 'github'
                ? app(GithubClient::class)->authorizationUrl($request, $flow)
                : app(OidcClient::class)->authorizationUrl($provider, $request, $flow);
        } catch (SsoException $e) {
            Log::warning('SSO sign-in could not start', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect()->route('home')->withErrors(['login' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    public function handleSsoCallback(Request $request, string $provider)
    {
        $provider = SsoProviders::normalizeKey($provider);
        $flow = (array) $request->session()->pull('sso_flow', []);
        $label = SsoProviders::label($provider);

        // The state ties this callback to the sign-in this browser started (login CSRF protection).
        $returnedState = (string) $request->query('state', '');
        if (($flow['provider'] ?? null) !== $provider || $returnedState === '' || !hash_equals((string) ($flow['state'] ?? ''), $returnedState)
            || time() - (int) ($flow['started_at'] ?? 0) > 600) {
            return redirect()->route('home')->withErrors(['login' => 'The sign-in link is invalid or expired. Please try again.']);
        }
        if ($request->filled('error')) {
            return redirect()->route('home')->withErrors(['login' => $label . ' sign-in was cancelled or denied.']);
        }
        if (!SsoSettings::enabled() || !in_array($provider, SsoSettings::enabledProviders(), true)) {
            return redirect()->route('home')->withErrors(['login' => 'This SSO provider is not enabled for your organization.']);
        }

        try {
            $identity = SsoProviders::definition($provider)['type'] === 'github'
                ? app(GithubClient::class)->user($request, $flow)
                : app(OidcClient::class)->user($provider, $request, $flow);
        } catch (SsoException $e) {
            Log::warning('SSO sign-in failed', ['provider' => $provider, 'error' => $e->getMessage()]);
            ActivityRecorder::record(null, 'auth.login_failed', 'Sign-in with ' . $label . ' failed: ' . $e->getMessage(), ActivityRecorder::FAILURE, $request);

            return redirect()->route('home')->withErrors(['login' => $e->getMessage()]);
        }

        return $this->completeSsoLogin($request, $provider, $identity['email'], $identity['name'], (string) ($flow['intent'] ?? ''));
    }

    private function completeSsoLogin(Request $request, string $provider, string $email, string $displayName, string $intent)
    {
        $label = SsoProviders::label($provider);
        $user = User::where('email', $email)->first();
        if (!$user) {
            if (!License::isActive()) {
                return redirect()->route('home')->withErrors([
                    'login' => 'New accounts cannot be created until an admin adds a licence.',
                ]);
            }

            $user = User::create([
                'name' => $displayName,
                'email' => $email,
                'password' => Str::random(32),
            ]);
            // The random password is never shown, so the account has no password the user chose.
            $user->forceFill(['password_changed_at' => null])->saveQuietly();
        }

        if (strtolower((string) ($user->status ?? 'active')) !== 'active') {
            ActivityRecorder::record($user->id, 'auth.login_blocked', 'Sign-in with ' . $label . ' blocked: account inactive', ActivityRecorder::FAILURE, $request);

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
                ActivityRecorder::record($currentUser->id, 'pin.reset', 'Reset PIN (verified with ' . $label . ')', ActivityRecorder::SUCCESS, $request);

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
        AccountAlerts::signedIn($user, $request);
        ActivityRecorder::record($user->id, 'auth.login', 'Signed in with ' . $label . ' from ' . UserAgent::describe($request->userAgent()), ActivityRecorder::SUCCESS, $request);
        $user->recordLogin($request->ip());
        $request->session()->put('auth_method', 'sso');
        $request->session()->put('auth_sso_provider', $provider);
        $targetRoute = in_array((int) $user->rbac_id, [100, 101], true) ? 'admin.dashboard' : 'dashboard';

        return redirect()->route($targetRoute)->with('success', 'Logged in with ' . $label . ' successfully.');
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

        if (!License::isActive()) {
            return back()->withErrors(['register' => 'Registration is unavailable until an admin adds a licence.']);
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

            ActivityRecorder::record($user->id, 'account.registered', 'Created account', ActivityRecorder::SUCCESS, $request);

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
                ActivityRecorder::record($user->id, 'auth.login_blocked', 'Sign-in blocked: account inactive', ActivityRecorder::FAILURE, $request);

                return back()
                    ->withInput($request->only('email'))
                    ->with('inactive_user', 'User is Inactive please reachout to the Administrator');
            }

            // Log the user in
            Auth::login($user, $request->boolean('remember'));
            $request->session()->regenerate();
            AccountAlerts::signedIn($user, $request);
            ActivityRecorder::record($user->id, 'auth.login', 'Signed in from ' . UserAgent::describe($request->userAgent()), ActivityRecorder::SUCCESS, $request);
            $user->recordLogin($request->ip());
            $request->session()->put('auth_method', 'password');
            $request->session()->forget(['auth_sso_provider', 'sso_pin_verified_at', 'sso_intent']);
            $targetRoute = in_array((int) $user->rbac_id, [100, 101], true) ? 'admin.dashboard' : 'dashboard';

            return redirect()->route($targetRoute)->with('success', 'Welcome back!');
        }

        // Unknown emails are kept with no user, so they appear in no one's activity.
        ActivityRecorder::record($user?->id, 'auth.login_failed', 'Failed sign-in attempt from ' . UserAgent::describe($request->userAgent()), ActivityRecorder::FAILURE, $request);

        return back()->withErrors([
            'login' => 'The provided credentials do not match our records.',
        ]);
    }

    /**
     * Handle user logout
     */
    public function logout(Request $request)
    {
        if (Auth::check()) {
            ActivityRecorder::record((int) Auth::id(), 'auth.logout', 'Signed out', ActivityRecorder::SUCCESS, $request);
        }

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

    private function isEmailRegistrationDisabled(): bool
    {
        $value = (string) AdminSetting::getValue('disable_email_registration', 'false');

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
