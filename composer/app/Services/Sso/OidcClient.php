<?php

namespace App\Services\Sso;

use App\Support\SsoProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * OpenID Connect authorization code flow with state, nonce and PKCE (S256),
 * shared by every provider with type 'oidc' in config/sso.php.
 *
 * The ID token comes straight from the provider's token endpoint over TLS, so its
 * signature is not checked (OpenID Connect Core 1.0, 3.1.3.7 step 6); issuer,
 * audience, expiry and nonce are.
 */
class OidcClient
{
    private const CLOCK_SKEW = 120;

    public function authorizationUrl(string $provider, Request $request, array $flow): string
    {
        $discovery = $this->discover($provider);
        $verifier = Str::random(64);

        $flow += [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => $verifier,
        ];
        $request->session()->put('sso_flow', $flow);

        $params = [
            'response_type' => 'code',
            'client_id' => SsoProviders::clientId($provider),
            'redirect_uri' => SsoProviders::callbackUrl($provider),
            'scope' => 'openid email profile',
            'state' => $flow['state'],
            'nonce' => $flow['nonce'],
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ];
        if ($provider === 'google') {
            $params['prompt'] = 'select_account';
            if (SsoProviders::value('google', 'allowed_domain') !== '') {
                $params['hd'] = SsoProviders::value('google', 'allowed_domain');
            }
        }
        if ($provider === 'microsoft') {
            $params['prompt'] = 'select_account';
        }

        $endpoint = $discovery['authorization_endpoint'];

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{email: string, name: string} */
    public function user(string $provider, Request $request, array $flow): array
    {
        $discovery = $this->discover($provider);
        $clientId = SsoProviders::clientId($provider);
        $tokens = $this->exchangeCode($provider, $discovery, (string) $request->query('code'), $flow['verifier']);

        $idToken = (string) ($tokens['id_token'] ?? '');
        if ($idToken === '') {
            throw new SsoException('The provider did not return an ID token. Check that the openid scope is allowed.');
        }
        $claims = self::decodeJwtPayload($idToken);
        $this->validateIdToken($provider, $claims, $discovery, $clientId, $flow['nonce']);

        if (($claims['email'] ?? '') === '' && !empty($discovery['userinfo_endpoint']) && !empty($tokens['access_token'])) {
            $claims = array_merge($this->userInfo($discovery['userinfo_endpoint'], $tokens['access_token'], (string) $claims['sub']), $claims);
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SsoException('Your ' . SsoProviders::label($provider) . ' account has no email address to sign in with.');
        }
        $this->checkEmailVerified($provider, $claims);

        if ($provider === 'google' && SsoProviders::value('google', 'allowed_domain') !== ''
            && strcasecmp((string) ($claims['hd'] ?? ''), SsoProviders::value('google', 'allowed_domain')) !== 0) {
            throw new SsoException('Only ' . SsoProviders::value('google', 'allowed_domain') . ' Google accounts can sign in.');
        }

        $name = trim((string) ($claims['name'] ?? ''));
        if ($name === '') {
            $name = trim(($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? '')) ?: (string) ($claims['preferred_username'] ?? strstr($email, '@', true));
        }

        return ['email' => $email, 'name' => $name];
    }

    /** Fetches and caches the discovery document; also used by the admin "Test" check. */
    public function discover(string $provider, bool $fresh = false): array
    {
        $issuer = SsoProviders::issuer($provider);
        if ($issuer === '' || !str_starts_with(strtolower($issuer), 'https://')) {
            throw new SsoException(SsoProviders::label($provider) . ' sign-in is not set up (missing or non-HTTPS issuer).');
        }

        $cacheKey = 'sso:oidc:' . sha1($issuer);
        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3600, function () use ($issuer, $provider) {
            try {
                $response = Http::acceptJson()->timeout(10)->get(rtrim($issuer, '/') . '/.well-known/openid-configuration');
            } catch (Throwable $e) {
                throw new SsoException('Could not reach ' . SsoProviders::label($provider) . ' (' . $e->getMessage() . ').');
            }
            $document = $response->json();
            if (!$response->ok() || !is_array($document) || empty($document['authorization_endpoint']) || empty($document['token_endpoint']) || empty($document['issuer'])) {
                throw new SsoException('Could not read the OpenID configuration of ' . SsoProviders::label($provider) . ' at ' . rtrim($issuer, '/') . '/.well-known/openid-configuration.');
            }
            if (!self::sameIssuer($document['issuer'], $issuer) && $provider !== 'microsoft') {
                throw new SsoException('The provider reports issuer ' . $document['issuer'] . ', which does not match the configured ' . $issuer . '.');
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $endpoint) {
                if (!empty($document[$endpoint]) && !str_starts_with(strtolower((string) $document[$endpoint]), 'https://')) {
                    throw new SsoException('The provider\'s ' . $endpoint . ' is not HTTPS.');
                }
            }

            return $document;
        });
    }

    private function exchangeCode(string $provider, array $discovery, string $code, string $verifier): array
    {
        if ($code === '') {
            throw new SsoException('The provider did not return an authorization code.');
        }

        $clientId = SsoProviders::clientId($provider);
        $clientSecret = SsoProviders::clientSecret($provider);
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => SsoProviders::callbackUrl($provider),
            'code_verifier' => $verifier,
        ];

        // client_secret_basic is the default when the provider does not list its methods.
        $methods = $discovery['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        $request = Http::asForm()->acceptJson()->timeout(15);
        if (in_array('client_secret_basic', $methods, true)) {
            $request = $request->withBasicAuth(rawurlencode($clientId), rawurlencode($clientSecret));
        } else {
            $form += ['client_id' => $clientId, 'client_secret' => $clientSecret];
        }

        try {
            $response = $request->post($discovery['token_endpoint'], $form);
        } catch (Throwable $e) {
            throw new SsoException('Could not reach ' . SsoProviders::label($provider) . ' to finish sign-in.');
        }
        if (!$response->ok() || !is_array($response->json())) {
            $reason = trim((string) ($response->json('error_description') ?? $response->json('error') ?? ''));
            throw new SsoException(SsoProviders::label($provider) . ' refused the sign-in' . ($reason !== '' ? ': ' . $reason : '. Check the client ID, secret and redirect URI.'));
        }

        return $response->json();
    }

    private function validateIdToken(string $provider, array $claims, array $discovery, string $clientId, string $nonce): void
    {
        $issuer = (string) ($claims['iss'] ?? '');
        $issuerOk = self::sameIssuer($issuer, (string) $discovery['issuer'])
            || ($provider === 'google' && $issuer === 'accounts.google.com');
        if ($provider === 'microsoft') {
            // A tenant-specific authority's discovery issuer contains that tenant's ID.
            $issuerOk = $issuer === $discovery['issuer'] && str_contains($issuer, '/' . ($claims['tid'] ?? '-') . '/');
        }
        if (!$issuerOk) {
            throw new SsoException('The sign-in token was issued by an unexpected party.');
        }

        $audience = (array) ($claims['aud'] ?? []);
        if (!in_array($clientId, $audience, true) || (count($audience) > 1 && ($claims['azp'] ?? '') !== $clientId)) {
            throw new SsoException('The sign-in token was issued for a different application.');
        }

        $now = time();
        if ((int) ($claims['exp'] ?? 0) < $now - self::CLOCK_SKEW || (int) ($claims['iat'] ?? $now) > $now + self::CLOCK_SKEW) {
            throw new SsoException('The sign-in token has expired. Check that the server clock is correct, then try again.');
        }
        if (!hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new SsoException('The sign-in token does not belong to this sign-in attempt. Try again.');
        }
        if (($claims['sub'] ?? '') === '') {
            throw new SsoException('The sign-in token has no subject.');
        }
    }

    private function checkEmailVerified(string $provider, array $claims): void
    {
        // Entra ID has no email_verified claim; the single, configured tenant controls its users' addresses.
        if ($provider === 'microsoft') {
            return;
        }
        $verified = $claims['email_verified'] ?? null;
        if ($verified === true || $verified === 'true') {
            return;
        }
        if (SsoProviders::flag($provider, 'trust_email')) {
            return;
        }

        throw new SsoException('Your ' . SsoProviders::label($provider) . ' email address is not verified, so it cannot be used to sign in.');
    }

    private function userInfo(string $endpoint, string $accessToken, string $subject): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(15)->get($endpoint);
        } catch (Throwable) {
            return [];
        }
        $claims = $response->ok() && is_array($response->json()) ? $response->json() : [];

        // UserInfo claims only count if they describe the same user as the ID token.
        return ($claims['sub'] ?? null) === $subject ? $claims : [];
    }

    public static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new SsoException('The provider returned an invalid ID token.');
        }
        $segment = strtr($parts[1], '-_', '+/');
        $payload = json_decode((string) base64_decode(str_pad($segment, (int) ceil(strlen($segment) / 4) * 4, '=')), true);
        if (!is_array($payload)) {
            throw new SsoException('The provider returned an invalid ID token.');
        }

        return $payload;
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function sameIssuer(string $a, string $b): bool
    {
        return rtrim($a, '/') === rtrim($b, '/');
    }
}
