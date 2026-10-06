<?php

namespace App\Services\Sso;

use App\Support\SsoProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * GitHub (and GitHub Enterprise Server) OAuth app sign-in with state and PKCE.
 * Signs in with the account's verified primary email, else another verified email.
 */
class GithubClient
{
    public function authorizationUrl(Request $request, array $flow): string
    {
        $verifier = Str::random(64);
        $flow += ['state' => Str::random(40), 'verifier' => $verifier];
        $request->session()->put('sso_flow', $flow);

        return $this->webBase() . '/login/oauth/authorize?' . http_build_query([
            'client_id' => SsoProviders::clientId('github'),
            'redirect_uri' => SsoProviders::callbackUrl('github'),
            'scope' => 'read:user user:email',
            'state' => $flow['state'],
            'code_challenge' => OidcClient::challenge($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{email: string, name: string} */
    public function user(Request $request, array $flow): array
    {
        $code = (string) $request->query('code', '');
        if ($code === '') {
            throw new SsoException('GitHub did not return an authorization code.');
        }

        try {
            $tokenResponse = Http::asForm()->acceptJson()->timeout(15)->post($this->webBase() . '/login/oauth/access_token', [
                'client_id' => SsoProviders::clientId('github'),
                'client_secret' => SsoProviders::clientSecret('github'),
                'code' => $code,
                'redirect_uri' => SsoProviders::callbackUrl('github'),
                'code_verifier' => $flow['verifier'],
            ]);
        } catch (Throwable) {
            throw new SsoException('Could not reach GitHub to finish sign-in.');
        }

        $accessToken = (string) $tokenResponse->json('access_token', '');
        if (!$tokenResponse->ok() || $accessToken === '') {
            $reason = trim((string) ($tokenResponse->json('error_description') ?? ''));
            throw new SsoException('GitHub refused the sign-in' . ($reason !== '' ? ': ' . $reason : '. Check the client ID, secret and callback URL.'));
        }

        $api = Http::withToken($accessToken)->acceptJson()->timeout(15)->withHeaders([
            'User-Agent' => 'AtGlance-SSO',
            'Accept' => 'application/vnd.github+json',
        ]);
        try {
            $profile = $api->get($this->apiBase() . '/user');
            $emails = $api->get($this->apiBase() . '/user/emails');
        } catch (Throwable) {
            throw new SsoException('Could not read your GitHub profile.');
        }
        if (!$profile->ok() || !$emails->ok()) {
            throw new SsoException('Could not read your GitHub profile and email addresses.');
        }

        $verified = collect($emails->json())->filter(fn ($item) => is_array($item) && ($item['verified'] ?? false) === true);
        $chosen = $verified->first(fn ($item) => ($item['primary'] ?? false) === true) ?? $verified->first();
        $email = strtolower(trim((string) ($chosen['email'] ?? '')));
        if ($email === '') {
            throw new SsoException('Your GitHub account has no verified email address. Verify one on GitHub, then try again.');
        }

        $name = trim((string) (($profile->json('name') ?: $profile->json('login')) ?? '')) ?: 'GitHub User';

        return ['email' => $email, 'name' => $name];
    }

    private function webBase(): string
    {
        $enterprise = rtrim(SsoProviders::value('github', 'enterprise_url'), '/');

        return $enterprise !== '' ? $enterprise : 'https://github.com';
    }

    private function apiBase(): string
    {
        $enterprise = rtrim(SsoProviders::value('github', 'enterprise_url'), '/');

        return $enterprise !== '' ? $enterprise . '/api/v3' : 'https://api.github.com';
    }
}
