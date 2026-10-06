# Custom Domain & HTTPS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an organisation open the console at any domain or subdomain,
with or without HTTPS, set up after installation from Admin Settings >
Plugins and > Site, while `http://IP:8000` and `atglance.internal` always keep
working.

**Architecture:** The app owns all domain logic: settings, validation,
redirects, cookies, `APP_URL`, and certificate approval. It uses the same code
on every platform. On a VM, an opt-in compose override starts a Caddy binary
that is already in the app image. Caddy asks the app, through localhost-only
endpoints, whether it may issue a certificate or which uploaded certificate to
serve. On ECS, ACA and Kubernetes, the platform's load balancer terminates TLS
and the app only trusts the forwarded headers. The default install does not
change.

**Tech Stack:** Laravel 12 (PHP 8.2), Blade, PHPUnit 11 (sqlite in-memory),
PHP `openssl_*`, Caddy 2 (binary copied from `caddy:2`), Docker Compose.

**Spec:** `docs/superpowers/specs/2026-09-30-custom-domain-https-design.md`.
Read it before starting. Issue: #22.

## Global Constraints

- The installation must not change and cannot fail because of this feature. The default `docker-compose.yml` publishes only `8000` and `8002`. There are no new `install.sh` flags or checks, and no domain or HTTPS fields in the installer.
- The app never controls Docker. No `docker.sock`.
- `http://<server IP>:8000` works in every mode and is never redirected.
- `atglance.internal` is the built-in fallback name. It is always accepted and never redirected unless it is the configured domain.
- The HTTPS redirect uses `302`, never `301`, and applies only to the configured domain.
- `SESSION_DOMAIN` stays `null`. `session.secure` follows `$request->isSecure()` unless `SESSION_SECURE_COOKIE` is set explicitly.
- Internal endpoints (`/api/internal/domain/*`) answer only local requests: `REMOTE_ADDR` is `127.0.0.1` or `::1`, **and** there is no `X-Forwarded-For` and no `X-AtGlance-Proxy` header.
- Only the super admin (`rbac_id` 100) changes domain settings. Admins (101) see them read-only.
- Test runner: all PHPUnit commands below run inside the app image, because the host has no PHP. `$RUN` is:

  ```bash
  MSYS_NO_PATHCONV=1 docker run --rm -v "D:/Projects/Personal/atGlance-managementGUI/composer:/src" -w /src --entrypoint sh -e VIEW_COMPILED_PATH=/tmp/views -e APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY= localhost:5000/atglance/ce-atglance-app:ui-test -c 'mkdir -p /tmp/views && php vendor/bin/phpunit --filter <FILTER>'
  ```

  The host `composer/vendor` has the dev dependencies.
- Docs and copy use British "licence/organisation", plain words, and sentence case.

## Review Focus

1. **Private key leak through the proxy.** A request for `/api/internal/domain/certificate` that arrives through the built-in Caddy (from `127.0.0.1`, with `X-Forwarded-For`) must get `204`, never the key. Pinned in Task 4, step 1 (`test_certificate_endpoint_refuses_proxied_requests`) and in the Caddyfile `respond 404` in Task 9.
2. **POST to the domain over HTTP when HTTPS is on.** A form posted to `http://domain` must not lose its body in a redirect. Only `GET` and `HEAD` are redirected; other methods pass through. Pinned in Task 5 (`test_post_to_domain_over_http_is_not_redirected`).
3. **A domain that was saved with a port or a scheme** (old data from the pre-feature Site tab, e.g. `api.example.com` or a value with a trailing dot). `DomainSettings::domain()` normalises it, and the Site tab still renders. Pinned in Task 2 (`test_stored_domain_is_normalised`).
4. **Server IP saved by the installer as `IP:port`** (e.g. `192.168.1.2:8000`). The DNS and hosts lines must use the bare IP, and `APP_URL` falls back to `http://IP:port`. Pinned in Task 2 (`test_server_ip_strips_port`) and Task 6 (`test_disabling_plugin_restores_ip_app_url`).
5. **PFX exported by older Windows tools** (RC2/3DES) fails under OpenSSL 3. The upload must show a readable reason, not a 500. Pinned in Task 3 (`test_unreadable_pkcs12_gives_reason`).

---

## Task 0: Branch

- [ ] **Step 1: Check the working tree.**

  Run: `git status --short`.

  If the licence work (`composer/app/Services/LicenseClient.php`, etc.) is
  still uncommitted, stop and ask the user to commit it. This feature must not
  mix with it.

- [ ] **Step 2: Create the branch from the current licence branch.**

  ```bash
  git checkout -b feat/custom-domain-https
  ```

---

## Task 1: `EnvFile` helper

**Files:**
- Create: `composer/app/Support/EnvFile.php`
- Modify: `composer/app/Http/Controllers/InstallerController.php`. Delete the private `updateEnv()` (~line 220) and call `EnvFile::set()` instead.
- Test: `composer/tests/Feature/EnvFileTest.php`

**Interfaces:**
- Produces: `App\Support\EnvFile::set(array $pairs, ?string $path = null): void`. It replaces `KEY=...` lines or appends them, and defaults to `base_path('.env')`.

- [ ] **Step 1: Write the failing test.**

```php
<?php

namespace Tests\Feature;

use App\Support\EnvFile;
use Tests\TestCase;

class EnvFileTest extends TestCase
{
    public function test_replaces_existing_and_appends_new_keys(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "APP_NAME=AtGlance\nAPP_URL=http://old\n");

        EnvFile::set(['APP_URL' => 'https://atglance.internal', 'NEW_KEY' => 'x'], $path);

        $this->assertSame("APP_NAME=AtGlance\nAPP_URL=https://atglance.internal\nNEW_KEY=x\n", file_get_contents($path));
        unlink($path);
    }

    public function test_key_names_are_not_treated_as_regex(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "APPXURL=keep\n");

        EnvFile::set(['APP.URL' => 'v'], $path);

        $this->assertStringContainsString("APPXURL=keep\n", file_get_contents($path));
        unlink($path);
    }
}
```

- [ ] **Step 2: Run the test and check it fails.**

  Run: `$RUN` with `--filter EnvFileTest`.

  Expected: FAIL, class `App\Support\EnvFile` not found.

- [ ] **Step 3: Implement.**

```php
<?php

namespace App\Support;

/**
 * Writes KEY=value pairs into the app .env (on the storage volume in
 * containers; /app/.env is a symlink to it).
 */
final class EnvFile
{
    public static function set(array $pairs, ?string $path = null): void
    {
        $path ??= base_path('.env');
        $content = is_file($path) ? (string) file_get_contents($path) : '';

        foreach ($pairs as $key => $value) {
            $pattern = '/^' . preg_quote((string) $key, '/') . '=.*/m';
            $line = $key . '=' . $value;

            if (preg_match($pattern, $content)) {
                $content = (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $content);
            } else {
                $content .= ($content === '' || str_ends_with($content, "\n") ? '' : "\n") . $line . "\n";
            }
        }

        file_put_contents($path, $content);
    }
}
```

  In `InstallerController`:
  - replace `$this->updateEnv([...])` with `EnvFile::set([...])`;
  - add `use App\Support\EnvFile;`;
  - delete the `updateEnv()` method.

- [ ] **Step 4: Run the tests.**

  Run: `$RUN` with `--filter EnvFileTest`, then the full suite (`--filter .`).

  Expected: PASS.

- [ ] **Step 5: Commit.**

```bash
git add composer/app/Support/EnvFile.php composer/tests/Feature/EnvFileTest.php composer/app/Http/Controllers/InstallerController.php
git commit -m "refactor: share .env writing in EnvFile"
```

---

## Task 2: `DomainSettings` and `HostResolver`

**Files:**
- Create: `composer/app/Support/DomainSettings.php`, `composer/app/Support/HostResolver.php`
- Test: `composer/tests/Feature/DomainSettingsTest.php`

**Interfaces:**
- Consumes: `EnvFile::set()` (Task 1); `AdminSetting::getValue/putValue`.
- Produces (all `public static` on `App\Support\DomainSettings`):
  - Constants: `FALLBACK_HOST = 'atglance.internal'`; `MODE_OFF|MODE_BUILTIN|MODE_CUSTOM|MODE_PLATFORM` (`'off'|'builtin'|'custom'|'platform'`); `MODES`; `PROXY_BUILTIN = 'builtin'`; `PROXY_PLATFORM = 'platform'`.
  - `pluginEnabled(): bool`, `setPluginEnabled(bool): void`
  - `domain(): string`, `httpsMode(): string`, `httpsOn(): bool`
  - `serverAddress(): string` (as stored, may include a port); `serverIp(): string` (no port); `stripPort(string): string`; `suggestedServerAddress(?string $requestHost): string`
  - `normalizeDomain(string): string`, `validateDomain(string): ?string` (the error message, or `null`)
  - `isFallbackHost(string $host): bool`
  - `accessUrl(): string`, `appUrl(): string`, `applyAppUrl(): void`
  - `save(string $domain, string $mode, string $serverAddress): void`
  - `recordProxy(string $type, string $scheme): void`, `proxySeen(): array` (`['builtin' => ['scheme','at']?, 'platform' => ...]`), `builtinProxySeen(): bool`
  - `caPath(): string`
  - `viewData(?string $requestHost): array` (keys listed in step 3)
- Produces: `App\Support\HostResolver::resolve(string $host): array` (list of IPv4 strings).

- [ ] **Step 1: Write the failing tests.**

```php
<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Support\DomainSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class DomainSettingsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    public function test_accepts_any_domain_or_subdomain(): void
    {
        foreach (['acme.io', 'atglance.acme.com', 'ops.eu.acme.com', 'atglance.internal', 'x-1.lan'] as $domain) {
            $this->assertNull(DomainSettings::validateDomain($domain), $domain);
        }
    }

    public function test_rejects_urls_paths_ports_ips_and_single_labels(): void
    {
        foreach (['http://x.com', 'x.com/path', 'x.com:443', '10.0.0.5', '::1', '-a.com', 'a-.com', 'intranet', str_repeat('a', 64) . '.com'] as $domain) {
            $this->assertNotNull(DomainSettings::validateDomain(DomainSettings::normalizeDomain($domain)), $domain);
        }
    }

    public function test_normalise_lowercases_and_drops_trailing_dot(): void
    {
        $this->assertSame('ops.acme.com', DomainSettings::normalizeDomain(' Ops.ACME.com. '));
    }

    public function test_stored_domain_is_normalised(): void
    {
        AdminSetting::putValue('site', 'site_domain_alias', 'API.Example.com.');

        $this->assertSame('api.example.com', DomainSettings::domain());
    }

    public function test_https_mode_derives_from_legacy_flag(): void
    {
        $this->assertSame('off', DomainSettings::httpsMode());
        AdminSetting::putValue('site', 'site_https_enabled', true);
        $this->assertSame('builtin', DomainSettings::httpsMode());
        AdminSetting::putValue('domain', 'site_https_mode', 'platform');
        $this->assertSame('platform', DomainSettings::httpsMode());
    }

    public function test_server_ip_strips_port(): void
    {
        AdminSetting::putValue('site', 'site_domain_alias_ip', '192.168.1.2:8000');
        $this->assertSame('192.168.1.2', DomainSettings::serverIp());
        $this->assertSame('::1', DomainSettings::stripPort('[::1]:8080'));
        $this->assertSame('::1', DomainSettings::stripPort('::1'));
    }

    public function test_suggested_server_address_prefers_stored_then_request_ip(): void
    {
        $this->assertSame('10.0.0.5', DomainSettings::suggestedServerAddress('10.0.0.5'));
        $this->assertSame('', DomainSettings::suggestedServerAddress('atglance.internal'));
        AdminSetting::putValue('site', 'site_domain_alias_ip', '10.0.0.9');
        $this->assertSame('10.0.0.9', DomainSettings::suggestedServerAddress('10.0.0.5'));
    }

    public function test_fallback_hosts(): void
    {
        foreach (['10.0.0.5', 'localhost', '::1', 'atglance.internal'] as $host) {
            $this->assertTrue(DomainSettings::isFallbackHost($host), $host);
        }
        $this->assertFalse(DomainSettings::isFallbackHost('ops.acme.com'));

        AdminSetting::putValue('site', 'site_domain_alias', 'atglance.internal');
        $this->assertFalse(DomainSettings::isFallbackHost('atglance.internal'));
    }

    public function test_save_sets_app_url_and_plugin_off_restores_ip(): void
    {
        DomainSettings::setPluginEnabled(true);
        DomainSettings::save('ops.acme.com', 'platform', '10.0.0.5:8000');

        $this->assertSame('https://ops.acme.com', config('app.url'));
        $this->assertStringContainsString("APP_URL=https://ops.acme.com\n", file_get_contents(base_path('.env')));
        $this->assertSame('true', AdminSetting::getValue('site_https_enabled'));

        DomainSettings::setPluginEnabled(false);
        $this->assertSame('http://10.0.0.5:8000', config('app.url'));
        $this->assertSame('ops.acme.com', DomainSettings::domain());
    }

    public function test_record_proxy_keeps_each_type(): void
    {
        DomainSettings::recordProxy('platform', 'https');
        DomainSettings::recordProxy('builtin', 'http');

        $seen = DomainSettings::proxySeen();
        $this->assertSame('https', $seen['platform']['scheme']);
        $this->assertSame('http', $seen['builtin']['scheme']);
        $this->assertTrue(DomainSettings::builtinProxySeen());
    }

    public function test_view_data_builds_dns_and_hosts_lines(): void
    {
        DomainSettings::setPluginEnabled(true);
        DomainSettings::save('atglance.lan', 'off', '10.0.0.5:8000');

        $data = DomainSettings::viewData('10.0.0.5');

        $this->assertSame('http://atglance.lan', $data['access_url']);
        $this->assertSame('atglance.lan  A  10.0.0.5', $data['dns_record']);
        $this->assertSame('10.0.0.5  atglance.lan', $data['hosts_line']);
        $this->assertFalse($data['local_warning']);
    }

    public function test_local_tld_warning_and_fallback_hosts_line(): void
    {
        DomainSettings::save('atglance.local', 'off', '10.0.0.5');
        $this->assertTrue(DomainSettings::viewData(null)['local_warning']);

        DomainSettings::save('', 'off', '10.0.0.5');
        $this->assertSame('10.0.0.5  atglance.internal', DomainSettings::viewData(null)['hosts_line']);
    }
}
```

- [ ] **Step 2: Run the tests and check they fail.**

  Run: `$RUN` with `--filter DomainSettingsTest`.

  Expected: FAIL, class not found.

- [ ] **Step 3: Implement `HostResolver`.**

```php
<?php

namespace App\Support;

/** DNS lookup, as a class so tests can swap it. */
class HostResolver
{
    /** @return list<string> IPv4 addresses, empty when the name does not resolve */
    public function resolve(string $host): array
    {
        $ips = @gethostbynamel($host);

        return $ips === false ? [] : array_values($ips);
    }
}
```

- [ ] **Step 4: Implement `DomainSettings`.**

```php
<?php

namespace App\Support;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Custom domain and HTTPS (Admin Settings > Plugins and > Site).
 * See docs/custom-domain.md and the design spec.
 */
final class DomainSettings
{
    public const FALLBACK_HOST = 'atglance.internal';

    public const MODE_OFF = 'off';
    public const MODE_BUILTIN = 'builtin';
    public const MODE_CUSTOM = 'custom';
    public const MODE_PLATFORM = 'platform';
    public const MODES = [self::MODE_OFF, self::MODE_BUILTIN, self::MODE_CUSTOM, self::MODE_PLATFORM];

    public const PROXY_BUILTIN = 'builtin';
    public const PROXY_PLATFORM = 'platform';

    private const HOSTNAME_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public static function pluginEnabled(): bool
    {
        return self::bool('domain_plugin_enabled');
    }

    public static function setPluginEnabled(bool $enabled): void
    {
        AdminSetting::putValue('domain', 'domain_plugin_enabled', $enabled ? 'true' : 'false');
        self::applyAppUrl();
    }

    public static function domain(): string
    {
        return self::normalizeDomain((string) self::get('site_domain_alias', ''));
    }

    public static function httpsMode(): string
    {
        $mode = (string) self::get('site_https_mode', '');
        if (in_array($mode, self::MODES, true)) {
            return $mode;
        }

        return self::bool('site_https_enabled') ? self::MODE_BUILTIN : self::MODE_OFF;
    }

    public static function httpsOn(): bool
    {
        return self::httpsMode() !== self::MODE_OFF;
    }

    public static function serverAddress(): string
    {
        return trim((string) self::get('site_domain_alias_ip', ''));
    }

    public static function serverIp(): string
    {
        return self::stripPort(self::serverAddress());
    }

    public static function stripPort(string $address): string
    {
        $address = trim($address);
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP)) {
            return $address;
        }
        if (preg_match('/^\[?([^\]]+?)\]?:\d{1,5}$/', $address, $match) && filter_var($match[1], FILTER_VALIDATE_IP)) {
            return $match[1];
        }

        return trim($address, '[]');
    }

    /** Server IP field pre-fill: stored value, then the request host or APP_URL host when it is an IP. */
    public static function suggestedServerAddress(?string $requestHost): string
    {
        if (self::serverAddress() !== '') {
            return self::serverAddress();
        }
        foreach ([(string) $requestHost, (string) parse_url((string) config('app.url'), PHP_URL_HOST)] as $host) {
            $host = trim($host, '[]');
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                return $host;
            }
        }

        return '';
    }

    public static function normalizeDomain(string $domain): string
    {
        return rtrim(strtolower(trim($domain)), '.');
    }

    public static function validateDomain(string $domain): ?string
    {
        if ($domain === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $domain)) {
            return 'Enter the domain only, without http:// or https://.';
        }
        if (str_contains($domain, '/')) {
            return 'The domain cannot include a path.';
        }
        if (filter_var(trim($domain, '[]'), FILTER_VALIDATE_IP)) {
            return 'Enter a domain name, not an IP address. The IP address always works on its own.';
        }
        if (str_contains($domain, ':')) {
            return 'The domain cannot include a port.';
        }
        if (!preg_match(self::HOSTNAME_PATTERN, $domain)) {
            return 'Enter a valid domain, for example atglance.internal or atglance.example.com.';
        }

        return null;
    }

    /** Hosts that are never redirected: IPs, localhost, and atglance.internal unless it is the configured domain. */
    public static function isFallbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return $host === self::FALLBACK_HOST && self::domain() !== self::FALLBACK_HOST;
    }

    public static function accessUrl(): string
    {
        $domain = self::domain();

        return $domain === '' ? '' : (self::httpsOn() ? 'https://' : 'http://') . $domain;
    }

    public static function appUrl(): string
    {
        if (self::pluginEnabled() && self::domain() !== '') {
            return self::accessUrl();
        }
        $address = self::serverAddress();

        return $address !== '' ? 'http://' . $address : (string) config('app.url');
    }

    public static function applyAppUrl(): void
    {
        $url = self::appUrl();
        EnvFile::set(['APP_URL' => $url]);
        config(['app.url' => $url]);
    }

    public static function save(string $domain, string $mode, string $serverAddress): void
    {
        AdminSetting::putValue('site', 'site_domain_alias', $domain);
        AdminSetting::putValue('site', 'site_domain_alias_ip', $serverAddress);
        AdminSetting::putValue('domain', 'site_https_mode', $mode);
        AdminSetting::putValue('site', 'site_https_enabled', $mode !== self::MODE_OFF ? 'true' : 'false');
        self::applyAppUrl();
    }

    /** Records that a request came through a proxy; writes at most once a minute per type and scheme. */
    public static function recordProxy(string $type, string $scheme): void
    {
        if (!Cache::add('domain.proxy_seen.' . $type . '.' . $scheme, true, 60)) {
            return;
        }
        $seen = self::proxySeen();
        $seen[$type] = ['scheme' => $scheme, 'at' => now()->toIso8601String()];
        AdminSetting::putValue('domain', 'proxy_seen', $seen);
    }

    /** @return array<string, array{scheme: string, at: string}> */
    public static function proxySeen(): array
    {
        $seen = json_decode((string) self::get('proxy_seen', '[]'), true);

        return is_array($seen) ? $seen : [];
    }

    public static function builtinProxySeen(): bool
    {
        return isset(self::proxySeen()[self::PROXY_BUILTIN]);
    }

    /** Root certificate of Caddy's internal CA (Caddy storage is /app/storage/caddy). */
    public static function caPath(): string
    {
        return storage_path('caddy/pki/authorities/local/root.crt');
    }

    public static function viewData(?string $requestHost): array
    {
        $domain = self::domain();
        $address = self::suggestedServerAddress($requestHost);
        $ip = self::stripPort($address);
        $certificate = CustomCertificate::current();

        return [
            'plugin_enabled' => self::pluginEnabled(),
            'domain' => $domain,
            'https_mode' => self::httpsMode(),
            'server_address' => $address,
            'server_ip' => $ip,
            'access_url' => self::accessUrl(),
            'proxy_seen' => self::proxySeen(),
            'builtin_seen' => self::builtinProxySeen(),
            'dns_record' => $domain !== '' && $ip !== '' ? $domain . '  A  ' . $ip : '',
            'hosts_line' => $ip !== '' ? $ip . '  ' . ($domain !== '' ? $domain : self::FALLBACK_HOST) : '',
            'local_warning' => str_ends_with($domain, '.local'),
            'certificate' => $certificate === null ? null : CustomCertificate::summary($certificate),
            'ca_available' => is_file(self::caPath()),
        ];
    }

    private static function get(string $key, mixed $default = null): mixed
    {
        try {
            return AdminSetting::getValue($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private static function bool(string $key): bool
    {
        return filter_var((string) self::get($key, 'false'), FILTER_VALIDATE_BOOL);
    }
}
```

  `viewData()` uses `CustomCertificate::current()` and `::summary()` from
  Task 3. Do Task 3's step 3 in the same session before running these tests,
  or temporarily stub `'certificate' => null` and restore it in Task 3.

- [ ] **Step 5: Run the tests.**

  Run: `$RUN` with `--filter DomainSettingsTest`.

  Expected: PASS once Task 3 exists. Otherwise run it after Task 3, step 3.

- [ ] **Step 6: Commit** (together with Task 3 if you stubbed).

```bash
git add composer/app/Support/DomainSettings.php composer/app/Support/HostResolver.php composer/tests/Feature/DomainSettingsTest.php
git commit -m "feat: domain settings model for custom domain and HTTPS"
```

---

## Task 3: `CustomCertificate`

**Files:**
- Create: `composer/app/Support/CustomCertificate.php`, `composer/app/Support/InvalidCertificate.php`, `composer/tests/Feature/Concerns/MakesCertificates.php`
- Test: `composer/tests/Feature/CustomCertificateTest.php`

**Interfaces:**
- Produces:
  - `CustomCertificate::fromPem(string $certPem, string $keyPem, string $passphrase = ''): array`
  - `CustomCertificate::fromPkcs12(string $data, string $password): array`
  - Both return a **bundle** array: `cert_pem`, `key_pem`, `subject`, `sans` (list), `issuer`, `not_before` (int), `not_after` (int), `fingerprint`.
  - `CustomCertificate::names(array): array`, `covers(array $bundle, string $host): bool`, `assertUsableFor(array $bundle, string $domain, ?int $now = null): void`
  - `CustomCertificate::store(array): void`, `current(): ?array`, `remove(): void`, `pemBundle(array): string`, `summary(array): array` (no key; adds `days_left`)
  - `App\Support\InvalidCertificate extends \RuntimeException`. The message is shown to the user.
  - Test trait `MakesCertificates::makeCertificate(array $names, int $days = 365, ?string $keyPassphrase = null): array{cert,key}`, `makePkcs12(array $names, string $password): string`

- [ ] **Step 1: Write the test trait.**

```php
<?php

namespace Tests\Feature\Concerns;

/** Self-signed certificates for tests (EC P-256, fast to generate). */
trait MakesCertificates
{
    protected function makeCertificate(array $names, int $days = 365, ?string $keyPassphrase = null): array
    {
        $config = tempnam(sys_get_temp_dir(), 'cnf');
        $san = implode(',', array_map(fn ($name) => 'DNS:' . $name, $names));
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName={$san}\nbasicConstraints=CA:FALSE\n");
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3', 'req_extensions' => 'v3'];

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $config]);
        $csr = openssl_csr_new(['commonName' => $names[0]], $key, $options);
        $cert = openssl_csr_sign($csr, null, $key, $days, $options);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, $keyPassphrase, ['config' => $config]);
        @unlink($config);

        return ['cert' => $certPem, 'key' => $keyPem];
    }

    protected function makePkcs12(array $names, string $password): string
    {
        $pair = $this->makeCertificate($names);
        openssl_pkcs12_export($pair['cert'], $out, $pair['key'], $password);

        return $out;
    }
}
```

- [ ] **Step 2: Write the failing tests.**

```php
<?php

namespace Tests\Feature;

use App\Support\CustomCertificate;
use App\Support\InvalidCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\Feature\Concerns\MakesCertificates;
use Tests\TestCase;

class CustomCertificateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;
    use MakesCertificates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    public function test_reads_pem_pair_with_passphrase(): void
    {
        $pair = $this->makeCertificate(['ops.acme.com'], 365, 'secret');

        $bundle = CustomCertificate::fromPem($pair['cert'], $pair['key'], 'secret');

        $this->assertSame(['ops.acme.com'], $bundle['sans']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $bundle['cert_pem']);
        $this->assertStringNotContainsString('ENCRYPTED', $bundle['key_pem']);
        CustomCertificate::assertUsableFor($bundle, 'ops.acme.com');
    }

    public function test_wrong_passphrase_is_refused(): void
    {
        $pair = $this->makeCertificate(['ops.acme.com'], 365, 'secret');

        $this->expectException(InvalidCertificate::class);
        CustomCertificate::fromPem($pair['cert'], $pair['key'], 'wrong');
    }

    public function test_mismatched_key_is_refused(): void
    {
        $a = $this->makeCertificate(['ops.acme.com']);
        $b = $this->makeCertificate(['ops.acme.com']);

        $this->expectExceptionMessage('does not belong');
        CustomCertificate::fromPem($a['cert'], $b['key']);
    }

    public function test_reads_pkcs12(): void
    {
        $bundle = CustomCertificate::fromPkcs12($this->makePkcs12(['ops.acme.com'], 'pw'), 'pw');

        $this->assertSame(['ops.acme.com'], $bundle['sans']);
    }

    public function test_unreadable_pkcs12_gives_reason(): void
    {
        $this->expectExceptionMessage('could not be read');
        CustomCertificate::fromPkcs12('not a pfx', 'pw');
    }

    public function test_wildcard_covers_one_label_only(): void
    {
        $bundle = CustomCertificate::fromPem(...array_values($this->makeCertificate(['*.acme.com'])));

        $this->assertTrue(CustomCertificate::covers($bundle, 'ops.acme.com'));
        $this->assertFalse(CustomCertificate::covers($bundle, 'a.ops.acme.com'));
        $this->assertFalse(CustomCertificate::covers($bundle, 'acme.com'));
    }

    public function test_other_domain_and_expired_are_refused(): void
    {
        $bundle = CustomCertificate::fromPem(...array_values($this->makeCertificate(['ops.acme.com'], 30)));

        try {
            CustomCertificate::assertUsableFor($bundle, 'other.acme.com');
            $this->fail('expected refusal');
        } catch (InvalidCertificate $e) {
            $this->assertStringContainsString('does not cover other.acme.com', $e->getMessage());
        }

        $this->expectExceptionMessage('expired');
        CustomCertificate::assertUsableFor($bundle, 'ops.acme.com', time() + 40 * 86400);
    }

    public function test_store_is_encrypted_and_round_trips(): void
    {
        $bundle = CustomCertificate::fromPem(...array_values($this->makeCertificate(['ops.acme.com'])));
        CustomCertificate::store($bundle);

        $raw = \App\Models\AdminSetting::query()->where('setting_key', 'custom_cert')->value('setting_value');
        $this->assertStringNotContainsString('PRIVATE KEY', $raw);
        $this->assertSame($bundle['fingerprint'], CustomCertificate::current()['fingerprint']);
        $this->assertArrayNotHasKey('key_pem', CustomCertificate::summary($bundle));

        CustomCertificate::remove();
        $this->assertNull(CustomCertificate::current());
    }
}
```

- [ ] **Step 3: Implement.**

`InvalidCertificate.php`:

```php
<?php

namespace App\Support;

/** A certificate upload that cannot be used; the message is shown to the admin. */
class InvalidCertificate extends \RuntimeException
{
}
```

`CustomCertificate.php`:

```php
<?php

namespace App\Support;

use App\Models\AdminSetting;

/**
 * The organisation's own TLS certificate for the built-in proxy (HTTPS mode
 * "custom"). Stored encrypted in admin_settings; served to Caddy by
 * DomainTlsController::certificate().
 */
final class CustomCertificate
{
    private const KEY = 'custom_cert';

    public static function fromPem(string $certPem, string $keyPem, string $passphrase = ''): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $certPem, $matches);
        if (empty($matches[0])) {
            throw new InvalidCertificate('The certificate file is not a PEM certificate.');
        }
        $leaf = openssl_x509_read($matches[0][0]);
        if ($leaf === false) {
            throw new InvalidCertificate('The certificate could not be read.');
        }
        $key = openssl_pkey_get_private($keyPem, $passphrase === '' ? null : $passphrase);
        if ($key === false) {
            throw new InvalidCertificate('The private key could not be read. Check the key file and its passphrase.');
        }

        return self::build($leaf, array_slice($matches[0], 1), $key);
    }

    public static function fromPkcs12(string $data, string $password): array
    {
        $parts = [];
        if (!@openssl_pkcs12_read($data, $parts, $password)) {
            throw new InvalidCertificate('The .pfx/.p12 file could not be read. Check the password. Files exported with old encryption (RC2 or 3DES) must be exported again with AES.');
        }
        $leaf = openssl_x509_read($parts['cert'] ?? '');
        $key = openssl_pkey_get_private($parts['pkey'] ?? '');
        if ($leaf === false || $key === false) {
            throw new InvalidCertificate('The .pfx/.p12 file has no certificate or no private key.');
        }

        return self::build($leaf, $parts['extracerts'] ?? [], $key);
    }

    /** @return list<string> host names the certificate covers */
    public static function names(array $bundle): array
    {
        return $bundle['sans'] !== [] ? $bundle['sans'] : array_values(array_filter([strtolower((string) $bundle['subject'])]));
    }

    public static function covers(array $bundle, string $host): bool
    {
        $host = strtolower($host);
        foreach (self::names($bundle) as $name) {
            if ($name === $host) {
                return true;
            }
            $dot = strpos($host, '.');
            if (str_starts_with($name, '*.') && $dot !== false && substr($host, $dot + 1) === substr($name, 2)) {
                return true;
            }
        }

        return false;
    }

    public static function assertUsableFor(array $bundle, string $domain, ?int $now = null): void
    {
        $now ??= time();
        if (!self::covers($bundle, $domain)) {
            throw new InvalidCertificate('The certificate does not cover ' . $domain . '. It covers: ' . implode(', ', self::names($bundle)) . '.');
        }
        if ($bundle['not_before'] > $now) {
            throw new InvalidCertificate('The certificate is not valid yet. It is valid from ' . date('Y-m-d', $bundle['not_before']) . '.');
        }
        if ($bundle['not_after'] < $now) {
            throw new InvalidCertificate('The certificate expired on ' . date('Y-m-d', $bundle['not_after']) . '.');
        }
    }

    public static function store(array $bundle): void
    {
        AdminSetting::putValue('domain', self::KEY, $bundle, true);
    }

    public static function current(): ?array
    {
        try {
            $bundle = json_decode((string) AdminSetting::getValue(self::KEY, ''), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($bundle) && isset($bundle['cert_pem'], $bundle['key_pem']) ? $bundle : null;
    }

    public static function remove(): void
    {
        AdminSetting::query()->where('setting_key', self::KEY)->delete();
    }

    /** Leaf, chain, then key: the format Caddy's get_certificate http expects. */
    public static function pemBundle(array $bundle): string
    {
        return trim($bundle['cert_pem']) . "\n" . trim($bundle['key_pem']) . "\n";
    }

    public static function summary(array $bundle): array
    {
        return [
            'subject' => $bundle['subject'],
            'names' => self::names($bundle),
            'issuer' => $bundle['issuer'],
            'not_after' => date('Y-m-d', $bundle['not_after']),
            'fingerprint' => $bundle['fingerprint'],
            'days_left' => (int) floor(($bundle['not_after'] - time()) / 86400),
        ];
    }

    private static function build(\OpenSSLCertificate $leaf, array $chain, \OpenSSLAsymmetricKey $key): array
    {
        if (!openssl_x509_check_private_key($leaf, $key)) {
            throw new InvalidCertificate('The private key does not belong to this certificate.');
        }
        openssl_x509_export($leaf, $leafPem);
        openssl_pkey_export($key, $keyPem);
        $info = openssl_x509_parse($leaf) ?: [];

        $chainPem = '';
        foreach ($chain as $extra) {
            $chainPem .= trim((string) $extra) . "\n";
        }

        $sans = [];
        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $sans[] = strtolower(substr($entry, 4));
            }
        }

        return [
            'cert_pem' => trim($leafPem) . "\n" . $chainPem,
            'key_pem' => trim($keyPem) . "\n",
            'subject' => (string) ($info['subject']['CN'] ?? ''),
            'sans' => $sans,
            'issuer' => (string) ($info['issuer']['CN'] ?? $info['issuer']['O'] ?? ''),
            'not_before' => (int) ($info['validFrom_time_t'] ?? 0),
            'not_after' => (int) ($info['validTo_time_t'] ?? 0),
            'fingerprint' => strtoupper(implode(':', str_split((string) openssl_x509_fingerprint($leaf, 'sha256'), 2))),
        ];
    }
}
```

- [ ] **Step 4: Run the tests.**

  Run: `$RUN` with `--filter "CustomCertificateTest|DomainSettingsTest"`.

  Expected: PASS.

- [ ] **Step 5: Commit.**

```bash
git add composer/app/Support/CustomCertificate.php composer/app/Support/InvalidCertificate.php composer/tests/Feature/Concerns/MakesCertificates.php composer/tests/Feature/CustomCertificateTest.php
git commit -m "feat: parse, validate and store an organisation certificate"
```

---

## Task 4: Internal TLS endpoints for Caddy

**Files:**
- Create: `composer/app/Http/Controllers/DomainTlsController.php`
- Modify: `composer/routes/api.php` (append the two routes)
- Test: `composer/tests/Feature/DomainTlsEndpointsTest.php`

**Interfaces:**
- Consumes: `DomainSettings` (Task 2) and `CustomCertificate` (Task 3).
- Produces:
  - `GET /api/internal/domain/tls-allowed?domain=` (200 or 404);
  - `GET /api/internal/domain/certificate?server_name=` (200 PEM or 204);
  - `DomainTlsController::isLocal(Request): bool`.

- [ ] **Step 1: Write the failing tests.**

```php
<?php

namespace Tests\Feature;

use App\Support\CustomCertificate;
use App\Support\DomainSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\Feature\Concerns\MakesCertificates;
use Tests\TestCase;

class DomainTlsEndpointsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;
    use MakesCertificates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        DomainSettings::setPluginEnabled(true);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function useCustomCertificate(): void
    {
        CustomCertificate::store(CustomCertificate::fromPem(...array_values($this->makeCertificate(['ops.acme.com']))));
        DomainSettings::save('ops.acme.com', 'custom', '10.0.0.5');
    }

    public function test_allows_configured_domain_in_builtin_mode(): void
    {
        DomainSettings::save('ops.acme.com', 'builtin', '10.0.0.5');

        $this->get('/api/internal/domain/tls-allowed?domain=ops.acme.com')->assertOk();
        $this->get('/api/internal/domain/tls-allowed?domain=evil.example')->assertNotFound();
    }

    public function test_fallback_name_is_allowed_in_every_mode(): void
    {
        DomainSettings::save('ops.acme.com', 'off', '10.0.0.5');

        $this->get('/api/internal/domain/tls-allowed?domain=atglance.internal')->assertOk();
    }

    public function test_nothing_allowed_when_plugin_off_or_mode_off_or_remote(): void
    {
        DomainSettings::save('ops.acme.com', 'off', '10.0.0.5');
        $this->get('/api/internal/domain/tls-allowed?domain=ops.acme.com')->assertNotFound();

        DomainSettings::save('ops.acme.com', 'builtin', '10.0.0.5');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->get('/api/internal/domain/tls-allowed?domain=ops.acme.com')->assertNotFound();

        DomainSettings::setPluginEnabled(false);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/api/internal/domain/tls-allowed?domain=ops.acme.com')->assertNotFound();
    }

    public function test_certificate_served_in_custom_mode(): void
    {
        $this->useCustomCertificate();

        $response = $this->get('/api/internal/domain/certificate?server_name=ops.acme.com');

        $response->assertOk();
        $this->assertStringContainsString('BEGIN CERTIFICATE', $response->getContent());
        $this->assertStringContainsString('PRIVATE KEY', $response->getContent());
        $this->get('/api/internal/domain/tls-allowed?domain=ops.acme.com')->assertOk();
    }

    public function test_certificate_not_served_for_other_names_modes_or_ips(): void
    {
        $this->useCustomCertificate();

        $this->get('/api/internal/domain/certificate?server_name=other.acme.com')->assertNoContent();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->get('/api/internal/domain/certificate?server_name=ops.acme.com')->assertNoContent();

        DomainSettings::save('ops.acme.com', 'builtin', '10.0.0.5');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/api/internal/domain/certificate?server_name=ops.acme.com')->assertNoContent();
    }

    public function test_certificate_endpoint_refuses_proxied_requests(): void
    {
        $this->useCustomCertificate();

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.7'])
            ->get('/api/internal/domain/certificate?server_name=ops.acme.com')->assertNoContent();
        $this->withHeaders(['X-AtGlance-Proxy' => 'builtin'])
            ->get('/api/internal/domain/certificate?server_name=ops.acme.com')->assertNoContent();
    }
}
```

  If the `withHeaders` calls leak into later requests, use a fresh
  `$this->call()` per request. Laravel test headers persist within one test.

- [ ] **Step 2: Run the tests and check they fail.**

  Run: `$RUN` with `--filter DomainTlsEndpointsTest`.

  Expected: FAIL (404 route / class not found).

- [ ] **Step 3: Implement the controller.**

```php
<?php

namespace App\Http\Controllers;

use App\Support\CustomCertificate;
use App\Support\DomainSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Endpoints for the built-in Caddy proxy (docker/Caddyfile). Only local
 * requests without proxy headers are answered: behind Caddy every proxied
 * request also comes from 127.0.0.1, so the headers are what tell Caddy's own
 * calls apart from a visitor's request.
 */
class DomainTlsController extends Controller
{
    /** on_demand_tls "ask": may Caddy get a certificate for this name? */
    public function allowed(Request $request): Response
    {
        if (!self::isLocal($request) || !DomainSettings::pluginEnabled()) {
            return response('', 404);
        }

        $name = DomainSettings::normalizeDomain((string) $request->query('domain', ''));
        if ($name === DomainSettings::FALLBACK_HOST) {
            return response('', 200);
        }

        $modeAllows = in_array(DomainSettings::httpsMode(), [DomainSettings::MODE_BUILTIN, DomainSettings::MODE_CUSTOM], true);

        return response('', $modeAllows && $name !== '' && $name === DomainSettings::domain() ? 200 : 404);
    }

    /** get_certificate http: the organisation's certificate in mode "custom", else 204. */
    public function certificate(Request $request): Response
    {
        $name = DomainSettings::normalizeDomain((string) $request->query('server_name', ''));
        $bundle = CustomCertificate::current();

        if (!self::isLocal($request)
            || !DomainSettings::pluginEnabled()
            || DomainSettings::httpsMode() !== DomainSettings::MODE_CUSTOM
            || $bundle === null
            || $name === ''
            || $name !== DomainSettings::domain()
            || !CustomCertificate::covers($bundle, $name)) {
            return response('', 204);
        }

        return response(CustomCertificate::pemBundle($bundle), 200, [
            'Content-Type' => 'application/x-pem-file',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function isLocal(Request $request): bool
    {
        return in_array((string) $request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            && !$request->headers->has('X-Forwarded-For')
            && !$request->headers->has('X-AtGlance-Proxy');
    }
}
```

  In `routes/api.php`, add `use App\Http\Controllers\DomainTlsController;`
  and append:

```php
// Built-in proxy (Caddy) only; see DomainTlsController::isLocal().
Route::get('/internal/domain/tls-allowed', [DomainTlsController::class, 'allowed']);
Route::get('/internal/domain/certificate', [DomainTlsController::class, 'certificate']);
```

- [ ] **Step 4: Run the tests.**

  Run: `$RUN` with `--filter DomainTlsEndpointsTest`.

  Expected: PASS.

- [ ] **Step 5: Commit.**

```bash
git add composer/app/Http/Controllers/DomainTlsController.php composer/routes/api.php composer/tests/Feature/DomainTlsEndpointsTest.php
git commit -m "feat: localhost-only certificate endpoints for the built-in proxy"
```

---

## Task 5: Trusted proxies, proxy detection, HTTPS redirect, cookies

**Files:**
- Create: `composer/app/Http/Middleware/DetectFrontProxy.php`, `composer/app/Http/Middleware/EnforceDomainHttps.php`
- Modify: `composer/bootstrap/app.php`
- Test: `composer/tests/Feature/DomainRequestHandlingTest.php`

**Interfaces:**
- Consumes: `DomainSettings` (Task 2).
- Produces: both middlewares in the `web` group, and trusted proxies from env `ATGLANCE_TRUSTED_PROXIES`.

- [ ] **Step 1: Write the failing tests.**

```php
<?php

namespace Tests\Feature;

use App\Support\DomainSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class DomainRequestHandlingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        DomainSettings::setPluginEnabled(true);
        DomainSettings::save('ops.eu.acme.com', 'platform', '10.0.0.5');
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    public function test_domain_over_http_redirects_with_302(): void
    {
        $this->get('http://ops.eu.acme.com/login')
            ->assertStatus(302)
            ->assertRedirect('https://ops.eu.acme.com/login');
    }

    public function test_fallback_hosts_are_never_redirected(): void
    {
        foreach (['http://10.0.0.5/', 'http://10.0.0.5:8000/', 'http://localhost/', 'http://atglance.internal/', 'http://atglance.internal:8000/'] as $url) {
            $location = (string) $this->get($url)->headers->get('Location');
            $this->assertStringNotContainsString('https://', $location, $url);
        }
    }

    public function test_post_to_domain_over_http_is_not_redirected(): void
    {
        $response = $this->post('http://ops.eu.acme.com/login', ['email' => 'x@y.z', 'password' => 'nope']);

        $this->assertStringStartsNotWith('https://ops.eu.acme.com', (string) $response->headers->get('Location'));
    }

    public function test_no_redirect_when_https_off_or_plugin_off(): void
    {
        DomainSettings::save('ops.eu.acme.com', 'off', '10.0.0.5');
        $this->assertStringStartsNotWith('https://', (string) $this->get('http://ops.eu.acme.com/login')->headers->get('Location'));

        DomainSettings::save('ops.eu.acme.com', 'platform', '10.0.0.5');
        DomainSettings::setPluginEnabled(false);
        $this->assertStringStartsNotWith('https://', (string) $this->get('http://ops.eu.acme.com/login')->headers->get('Location'));
    }

    public function test_forwarded_https_is_secure_and_not_redirected(): void
    {
        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('http://ops.eu.acme.com/login');

        $this->assertStringStartsNotWith('https://ops.eu.acme.com', (string) $response->headers->get('Location'));
        $this->assertTrue(config('session.secure'));
    }

    public function test_plain_http_fallback_gets_non_secure_cookie_and_own_links(): void
    {
        $response = $this->get('http://atglance.internal/');

        $this->assertFalse(config('session.secure'));
        $response->assertDontSee('https://ops.eu.acme.com', false);
    }

    public function test_detects_builtin_and_platform_proxies(): void
    {
        $this->withHeaders(['X-AtGlance-Proxy' => 'builtin', 'X-Forwarded-For' => '10.0.0.7'])->get('http://10.0.0.5/');
        $this->assertTrue(DomainSettings::builtinProxySeen());

        $this->withHeaders(['X-AtGlance-Proxy' => '', 'X-Forwarded-Proto' => 'https'])
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->get('http://10.0.0.5/');
        $this->assertSame('https', DomainSettings::proxySeen()['platform']['scheme']);
    }
}
```

- [ ] **Step 2: Run the tests and check they fail.**

  Run: `$RUN` with `--filter DomainRequestHandlingTest`.

  Expected: FAIL (no redirect, no detection).

- [ ] **Step 3: Implement the middlewares.**

`DetectFrontProxy.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Support\DomainSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Records whether requests reach the app through the built-in proxy or a platform proxy (Plugins card status). */
class DetectFrontProxy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DomainSettings::pluginEnabled()) {
            $type = null;
            if ($request->headers->get('X-AtGlance-Proxy') === 'builtin'
                && in_array((string) $request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)) {
                $type = DomainSettings::PROXY_BUILTIN;
            } elseif ($request->headers->has('X-Forwarded-Proto') || $request->headers->has('X-Forwarded-For')) {
                $type = DomainSettings::PROXY_PLATFORM;
            }

            if ($type !== null) {
                DomainSettings::recordProxy($type, $request->isSecure() ? 'https' : 'http');
            }
        }

        return $next($request);
    }
}
```

`EnforceDomainHttps.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Support\DomainSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session cookie follows the request scheme, and only the configured domain
 * is redirected to HTTPS (302, GET/HEAD only). IPs, localhost and
 * atglance.internal are never redirected, so they always work for recovery.
 */
class EnforceDomainHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (env('SESSION_SECURE_COOKIE') === null) {
            config(['session.secure' => $request->isSecure()]);
        }

        if (!$request->isSecure()
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && DomainSettings::pluginEnabled()
            && DomainSettings::httpsOn()) {
            $host = strtolower($request->getHost());
            $domain = DomainSettings::domain();

            if ($domain !== '' && $host === $domain && !DomainSettings::isFallbackHost($host)) {
                return redirect()->to('https://' . $domain . $request->getRequestUri(), 302);
            }
        }

        return $next($request);
    }
}
```

`bootstrap/app.php`: inside `withMiddleware(...)`, after `append(ActivityLogger)`:

```php
        // Built-in proxy (127.0.0.1), Docker networks and private load balancers.
        // Override with ATGLANCE_TRUSTED_PROXIES (comma-separated).
        $middleware->trustProxies(
            at: array_values(array_filter(array_map('trim', explode(',', (string) env(
                'ATGLANCE_TRUSTED_PROXIES',
                '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'
            ))))),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            \App\Http\Middleware\DetectFrontProxy::class,
            \App\Http\Middleware\EnforceDomainHttps::class,
        ]);
```

- [ ] **Step 4: Run the tests.**

  Run: `$RUN` with `--filter DomainRequestHandlingTest`, then the full suite.

  Expected: PASS, and no other test breaks.

  `/login` is a GET route (`login.form`) and a POST route (`login`), which is
  what the redirect and POST tests rely on.

- [ ] **Step 5: Commit.**

```bash
git add composer/app/Http/Middleware/DetectFrontProxy.php composer/app/Http/Middleware/EnforceDomainHttps.php composer/bootstrap/app.php composer/tests/Feature/DomainRequestHandlingTest.php
git commit -m "feat: trust proxies, detect front proxy, redirect domain to HTTPS"
```

---

## Task 6: `DomainSettingsController` (admin actions)

**Files:**
- Create: `composer/app/Http/Controllers/DomainSettingsController.php`
- Modify: `composer/routes/web.php`. Add routes in the `admin.role` group and `use App\Http\Controllers\DomainSettingsController;`.
- Test: `composer/tests/Feature/CustomDomainTest.php`

**Interfaces:**
- Consumes: Tasks 2 and 3.
- Produces these routes:
  - `admin.settings.domain.plugin` (POST, `enabled`);
  - `admin.settings.domain` (POST, `domain`, `https_mode`, `server_ip`, and for mode `custom` optionally `cert_file`, `key_file`, `key_passphrase` or `pfx_file`, `pfx_password`);
  - `admin.settings.domain.check` (POST JSON, `domain`, `server_ip`);
  - `admin.settings.domain.certificate.remove` (DELETE);
  - `admin.settings.domain.ca` (GET).

- [ ] **Step 1: Write the failing tests.**

```php
<?php

namespace Tests\Feature;

use App\Support\CustomCertificate;
use App\Support\DomainSettings;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\Feature\Concerns\MakesCertificates;
use Tests\TestCase;

class CustomDomainTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;
    use MakesCertificates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function enable(): void
    {
        $this->actingAsRole(100);
        $this->post(route('admin.settings.domain.plugin'), ['enabled' => '1'])->assertSessionHasNoErrors();
    }

    public function test_only_super_admin_changes_domain_settings(): void
    {
        $this->actingAsRole(101);

        $this->post(route('admin.settings.domain.plugin'), ['enabled' => '1'])->assertForbidden();
        $this->post(route('admin.settings.domain'), ['domain' => 'a.b', 'https_mode' => 'off'])->assertForbidden();
    }

    public function test_save_refused_while_plugin_off(): void
    {
        $this->actingAsRole(100);

        $this->post(route('admin.settings.domain'), ['domain' => 'atglance.lan', 'https_mode' => 'off'])
            ->assertSessionHasErrors('domain');
    }

    public function test_save_domain_without_https_sets_app_url(): void
    {
        $this->enable();

        $this->post(route('admin.settings.domain'), ['domain' => 'Ops.EU.acme.com.', 'https_mode' => 'off', 'server_ip' => '10.0.0.5'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ops.eu.acme.com', DomainSettings::domain());
        $this->assertSame('http://ops.eu.acme.com', config('app.url'));
    }

    public function test_invalid_domain_and_ip_are_refused(): void
    {
        $this->enable();

        $this->post(route('admin.settings.domain'), ['domain' => 'http://x.com', 'https_mode' => 'off'])->assertSessionHasErrors('domain');
        $this->post(route('admin.settings.domain'), ['domain' => 'x.com', 'https_mode' => 'off', 'server_ip' => 'not-an-ip'])->assertSessionHasErrors('server_ip');
        $this->post(route('admin.settings.domain'), ['domain' => 'x.com', 'https_mode' => 'off', 'server_ip' => '10.0.0.1:70000'])->assertSessionHasErrors('server_ip');
    }

    public function test_builtin_https_needs_detected_proxy(): void
    {
        $this->enable();

        $this->post(route('admin.settings.domain'), ['domain' => 'atglance.lan', 'https_mode' => 'builtin'])
            ->assertSessionHasErrors('https_mode');

        DomainSettings::recordProxy('builtin', 'http');
        $this->post(route('admin.settings.domain'), ['domain' => 'atglance.lan', 'https_mode' => 'builtin'])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://atglance.lan', config('app.url'));
    }

    public function test_custom_mode_uploads_pem_pair(): void
    {
        $this->enable();
        DomainSettings::recordProxy('builtin', 'http');
        $pair = $this->makeCertificate(['ops.acme.com']);

        $this->post(route('admin.settings.domain'), [
            'domain' => 'ops.acme.com',
            'https_mode' => 'custom',
            'cert_file' => UploadedFile::fake()->createWithContent('site.crt', $pair['cert']),
            'key_file' => UploadedFile::fake()->createWithContent('site.key', $pair['key']),
        ])->assertSessionHasNoErrors();

        $this->assertSame('custom', DomainSettings::httpsMode());
        $this->assertNotNull(CustomCertificate::current());
    }

    public function test_custom_mode_uploads_pfx_and_refuses_wrong_domain(): void
    {
        $this->enable();
        DomainSettings::recordProxy('builtin', 'http');

        $this->post(route('admin.settings.domain'), [
            'domain' => 'ops.acme.com',
            'https_mode' => 'custom',
            'pfx_file' => UploadedFile::fake()->createWithContent('site.pfx', $this->makePkcs12(['other.acme.com'], 'pw')),
            'pfx_password' => 'pw',
        ])->assertSessionHasErrors('certificate');
        $this->assertNull(CustomCertificate::current());

        $this->post(route('admin.settings.domain'), [
            'domain' => 'ops.acme.com',
            'https_mode' => 'custom',
            'pfx_file' => UploadedFile::fake()->createWithContent('site.pfx', $this->makePkcs12(['ops.acme.com'], 'pw')),
            'pfx_password' => 'pw',
        ])->assertSessionHasNoErrors();
    }

    public function test_custom_mode_without_certificate_is_refused_and_domain_change_checked(): void
    {
        $this->enable();
        DomainSettings::recordProxy('builtin', 'http');

        $this->post(route('admin.settings.domain'), ['domain' => 'ops.acme.com', 'https_mode' => 'custom'])
            ->assertSessionHasErrors('certificate');

        CustomCertificate::store(CustomCertificate::fromPem(...array_values($this->makeCertificate(['ops.acme.com']))));
        $this->post(route('admin.settings.domain'), ['domain' => 'new.acme.com', 'https_mode' => 'custom'])
            ->assertSessionHasErrors('certificate');
    }

    public function test_removing_certificate_turns_https_off(): void
    {
        $this->enable();
        CustomCertificate::store(CustomCertificate::fromPem(...array_values($this->makeCertificate(['ops.acme.com']))));
        DomainSettings::save('ops.acme.com', 'custom', '10.0.0.5');

        $this->delete(route('admin.settings.domain.certificate.remove'))->assertSessionHasNoErrors();

        $this->assertNull(CustomCertificate::current());
        $this->assertSame('off', DomainSettings::httpsMode());
    }

    public function test_disabling_plugin_restores_ip_app_url(): void
    {
        $this->enable();
        $this->post(route('admin.settings.domain'), ['domain' => 'atglance.lan', 'https_mode' => 'off', 'server_ip' => '192.168.1.2:8000']);

        $this->post(route('admin.settings.domain.plugin'), ['enabled' => '0']);

        $this->assertSame('http://192.168.1.2:8000', config('app.url'));
        $this->assertSame('atglance.lan', DomainSettings::domain());
    }

    public function test_check_reports_match_mismatch_and_unresolved(): void
    {
        $this->actingAsRole(101);
        $this->app->instance(HostResolver::class, new class extends HostResolver {
            public function resolve(string $host): array
            {
                return ['a.acme.com' => ['10.0.0.5'], 'b.acme.com' => ['10.0.0.9']][$host] ?? [];
            }
        });

        $this->postJson(route('admin.settings.domain.check'), ['domain' => 'a.acme.com', 'server_ip' => '10.0.0.5:8000'])
            ->assertJson(['ok' => true])->assertJsonFragment(['message' => 'a.acme.com resolves to 10.0.0.5 (matches).']);
        $this->postJson(route('admin.settings.domain.check'), ['domain' => 'b.acme.com', 'server_ip' => '10.0.0.5'])
            ->assertJson(['ok' => false])->assertJsonFragment(['message' => 'b.acme.com resolves to 10.0.0.9, not 10.0.0.5.']);
        $this->postJson(route('admin.settings.domain.check'), ['domain' => 'c.acme.com', 'server_ip' => '10.0.0.5'])
            ->assertJson(['ok' => false]);
    }

    public function test_ca_download_404_until_issued(): void
    {
        $this->actingAsRole(101);

        $this->get(route('admin.settings.domain.ca'))->assertNotFound();
    }
}
```

- [ ] **Step 2: Run the tests and check they fail.**

  Run: `$RUN` with `--filter CustomDomainTest`.

  Expected: FAIL (route not defined).

- [ ] **Step 3: Implement the controller.**

```php
<?php

namespace App\Http\Controllers;

use App\Rules\IpAddressWithOptionalPort;
use App\Support\CustomCertificate;
use App\Support\DomainSettings;
use App\Support\HostResolver;
use App\Support\InvalidCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Admin Settings > Plugins (Custom Domain & HTTPS) and > Site (Access URL). */
class DomainSettingsController extends Controller
{
    public function togglePlugin(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $enabled = $request->boolean('enabled');
        DomainSettings::setPluginEnabled($enabled);

        return redirect()->route('admin.settings', ['tab' => 'plugins'])->with('success', $enabled
            ? 'Custom Domain & HTTPS is enabled. Follow the setup steps for your platform below.'
            : 'Custom Domain & HTTPS is disabled. The console is reachable on its IP address.');
    }

    public function save(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $back = redirect()->route('admin.settings', ['tab' => 'site']);

        if (!DomainSettings::pluginEnabled()) {
            return $back->withErrors(['domain' => 'Enable Custom Domain & HTTPS in the Plugins tab first.']);
        }

        $validated = $request->validate([
            'domain' => ['nullable', 'string', 'max:253'],
            'https_mode' => ['required', Rule::in(DomainSettings::MODES)],
            'server_ip' => ['nullable', 'string', 'max:255', new IpAddressWithOptionalPort()],
            'cert_file' => ['nullable', 'file', 'max:64'],
            'key_file' => ['nullable', 'file', 'max:64'],
            'key_passphrase' => ['nullable', 'string', 'max:255'],
            'pfx_file' => ['nullable', 'file', 'max:64'],
            'pfx_password' => ['nullable', 'string', 'max:255'],
        ]);

        $domain = DomainSettings::normalizeDomain((string) ($validated['domain'] ?? ''));
        if (($error = DomainSettings::validateDomain($domain)) !== null) {
            return $back->withErrors(['domain' => $error])->withInput($request->except(['key_passphrase', 'pfx_password']));
        }

        $mode = $domain === '' ? DomainSettings::MODE_OFF : $validated['https_mode'];
        $fail = fn (string $field, string $message) => $back->withErrors([$field => $message])->withInput($request->except(['key_passphrase', 'pfx_password']));

        if (in_array($mode, [DomainSettings::MODE_BUILTIN, DomainSettings::MODE_CUSTOM], true) && !DomainSettings::builtinProxySeen()) {
            return $fail('https_mode', 'The built-in proxy has not been detected yet. Finish the setup steps in the Plugins tab, then open the console once on port 80.');
        }

        if ($mode === DomainSettings::MODE_CUSTOM) {
            try {
                $bundle = $this->uploadedCertificate($request) ?? CustomCertificate::current();
                if ($bundle === null) {
                    return $fail('certificate', 'Upload your certificate (PEM certificate and key, or a .pfx/.p12 file).');
                }
                if (!CustomCertificate::covers($bundle, $domain)) {
                    return $fail('certificate', 'The uploaded certificate does not cover ' . $domain . '. Upload a certificate for it, or choose another HTTPS option.');
                }
                CustomCertificate::assertUsableFor($bundle, $domain);
            } catch (InvalidCertificate $e) {
                return $fail('certificate', $e->getMessage());
            }
            CustomCertificate::store($bundle);
        }

        DomainSettings::save($domain, $mode, trim((string) ($validated['server_ip'] ?? '')));

        return $back->with('success', $domain === ''
            ? 'Domain cleared. The console is reachable on its IP address and on atglance.internal.'
            : 'Access URL saved. Open ' . DomainSettings::accessUrl() . ' once DNS points to the server.');
    }

    public function removeCertificate(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        CustomCertificate::remove();

        if (DomainSettings::httpsMode() === DomainSettings::MODE_CUSTOM) {
            DomainSettings::save(DomainSettings::domain(), DomainSettings::MODE_OFF, DomainSettings::serverAddress());
        }

        return redirect()->route('admin.settings', ['tab' => 'site'])
            ->with('success', 'Certificate removed. HTTPS is off for the domain until you choose another option.');
    }

    public function check(Request $request, HostResolver $resolver): JsonResponse
    {
        $domain = DomainSettings::normalizeDomain((string) $request->input('domain', DomainSettings::domain()));
        if ($domain === '' || DomainSettings::validateDomain($domain) !== null) {
            return response()->json(['ok' => false, 'message' => 'Enter a valid domain first.'], 422);
        }

        $ip = DomainSettings::stripPort((string) $request->input('server_ip', DomainSettings::serverAddress()));
        $resolved = $resolver->resolve($domain);

        if ($resolved === []) {
            return response()->json(['ok' => false, 'message' => $domain . ' does not resolve from the server yet. Create the DNS record, or wait for it to spread.']);
        }
        if ($ip !== '' && in_array($ip, $resolved, true)) {
            return response()->json(['ok' => true, 'message' => $domain . ' resolves to ' . $ip . ' (matches).']);
        }

        return response()->json(['ok' => false, 'message' => $domain . ' resolves to ' . implode(', ', $resolved) . ', not ' . ($ip !== '' ? $ip : 'the server IP') . '.']);
    }

    public function downloadCa(): Response
    {
        $path = DomainSettings::caPath();
        abort_unless(is_file($path), 404, 'No internal certificate has been issued yet. Open the HTTPS URL once.');

        return response()->download($path, 'atglance-internal-ca.crt', ['Content-Type' => 'application/x-x509-ca-cert']);
    }

    private function uploadedCertificate(Request $request): ?array
    {
        if ($request->hasFile('pfx_file')) {
            return CustomCertificate::fromPkcs12((string) $request->file('pfx_file')->get(), (string) $request->input('pfx_password', ''));
        }
        if ($request->hasFile('cert_file') || $request->hasFile('key_file')) {
            if (!$request->hasFile('cert_file') || !$request->hasFile('key_file')) {
                throw new InvalidCertificate('Upload both the certificate file and the private key file.');
            }

            return CustomCertificate::fromPem(
                (string) $request->file('cert_file')->get(),
                (string) $request->file('key_file')->get(),
                (string) $request->input('key_passphrase', ''),
            );
        }

        return null;
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless((int) $request->user()?->rbac_id === 100, 403);
    }
}
```

  Routes, inside the `Route::middleware('admin.role')->prefix('admin')`
  group, after `admin.settings.licence`:

```php
            Route::post('/settings/domain/plugin', [DomainSettingsController::class, 'togglePlugin'])->name('admin.settings.domain.plugin');
            Route::post('/settings/domain', [DomainSettingsController::class, 'save'])->name('admin.settings.domain');
            Route::post('/settings/domain/check', [DomainSettingsController::class, 'check'])->name('admin.settings.domain.check');
            Route::delete('/settings/domain/certificate', [DomainSettingsController::class, 'removeCertificate'])->name('admin.settings.domain.certificate.remove');
            Route::get('/settings/domain/ca.crt', [DomainSettingsController::class, 'downloadCa'])->name('admin.settings.domain.ca');
```

- [ ] **Step 4: Run the tests.**

  Run: `$RUN` with `--filter CustomDomainTest`.

  Expected: PASS.

- [ ] **Step 5: Commit.**

```bash
git add composer/app/Http/Controllers/DomainSettingsController.php composer/routes/web.php composer/tests/Feature/CustomDomainTest.php
git commit -m "feat: admin actions for custom domain, HTTPS mode and certificate"
```

---

## Task 7: Settings UI (Plugins card, Site tab Access URL, Info tab)

**Files:**
- Modify: `composer/resources/views/admin/settings.blade.php`:
  - Info tab rows (~lines 64-75);
  - remove the Domain Alias / IP / HTTPS / DNS box from the site form (~lines 110-144);
  - new Access URL card at the top of `#tab-site`;
  - Plugins tab body (~line 431).
- Modify: `composer/app/Http/Controllers/AdminDashboardController.php`:
  - `settings()`: drop `$siteDomainAlias*`/`$siteHttpsEnabled`, pass `domainView`;
  - `updateSiteSettings()`: remove the 3 validation rules, the `$domainAlias*` lines and the 3 `putValue` calls;
  - delete `resolveApplicationIpAddress()`.
- Modify: `composer/tests/Feature/SiteConfigurationTest.php`. Remove the alias fields from the two alias tests, keeping the logo assertion under `test_site_logo_is_saved`; delete `test_alias_ip_must_still_be_an_ip`, which is now covered in `CustomDomainTest`.
- Test: `composer/tests/Feature/CustomDomainViewTest.php`

**Interfaces:**
- Consumes: `DomainSettings::viewData()` (Task 2) and the routes from Task 6.
- Produces: the view variable `$domainView` (the array from `viewData()`).

- [ ] **Step 1: Write the failing view tests.**

```php
<?php

namespace Tests\Feature;

use App\Support\DomainSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class CustomDomainViewTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    public function test_site_tab_locked_until_plugin_enabled(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'site']))
            ->assertOk()
            ->assertSee('Enable Custom Domain &amp; HTTPS in the Plugins tab', false)
            ->assertSee('id="domain-fieldset" disabled', false)
            ->assertDontSee('name="site_domain_alias"', false);
    }

    public function test_site_tab_unlocked_shows_dns_and_hosts_lines(): void
    {
        $this->actingAsRole(100);
        DomainSettings::setPluginEnabled(true);
        DomainSettings::save('atglance.local', 'off', '10.0.0.5');

        $this->get(route('admin.settings', ['tab' => 'site']))
            ->assertOk()
            ->assertDontSee('id="domain-fieldset" disabled', false)
            ->assertSee('atglance.local  A  10.0.0.5')
            ->assertSee('10.0.0.5  atglance.local')
            ->assertSee('reserved for mDNS');
    }

    public function test_plugins_card_shows_status_and_steps(): void
    {
        $this->actingAsRole(100);
        DomainSettings::setPluginEnabled(true);

        $this->get(route('admin.settings', ['tab' => 'plugins']))
            ->assertOk()
            ->assertSee('Custom Domain &amp; HTTPS', false)
            ->assertSee('Not detected yet')
            ->assertSee('docker compose -f docker-compose.yml -f docker-compose.domain.yml up -d')
            ->assertSee('Azure Container Apps');

        DomainSettings::recordProxy('builtin', 'http');
        $this->get(route('admin.settings', ['tab' => 'plugins']))->assertSee('Built-in proxy detected (HTTP)');
    }

    public function test_admin_sees_read_only_controls(): void
    {
        $this->actingAsRole(101);

        $this->get(route('admin.settings', ['tab' => 'plugins']))
            ->assertOk()
            ->assertSee('Only the super admin can change this.');
    }

    public function test_builtin_options_disabled_until_proxy_seen(): void
    {
        $this->actingAsRole(100);
        DomainSettings::setPluginEnabled(true);

        $this->get(route('admin.settings', ['tab' => 'site']))
            ->assertSee('value="builtin" disabled', false)
            ->assertSee('value="custom" disabled', false);
    }
}
```

- [ ] **Step 2: Run the tests and check they fail.**

  Run: `$RUN` with `--filter CustomDomainViewTest`.

  Expected: FAIL.

- [ ] **Step 3: Update the controller.**

  In `AdminDashboardController::settings()`:
  - delete the `$siteDomainAlias`, `$siteDomainAliasIp` (and its `resolveApplicationIpAddress()` fallback) and `$siteHttpsEnabled` lines;
  - in the `view('admin.settings', [...])` array, replace the keys `'siteDomainAlias'`, `'siteDomainAliasIp'` and `'siteHttpsEnabled'` with:

```php
            'domainView' => \App\Support\DomainSettings::viewData(request()->getHost()),
```

  In `updateSiteSettings()`, delete:
  - the validation rules `site_domain_alias`, `site_domain_alias_ip` and `site_https_enabled`;
  - the block that computes `$domainAlias`, `$submittedAliasIp` and `$domainAliasIp`;
  - the three `AdminSetting::putValue('site', 'site_domain_alias'…)`, `…_ip…` and `…'site_https_enabled'…` lines.

  Delete `private function resolveApplicationIpAddress()`. Check nothing else
  calls it with `grep -n resolveApplicationIpAddress composer/app -r`.

- [ ] **Step 4: Update the Info tab rows.**

  Replace the "Domain Alias", "Alias IP Address" and "HTTPS" rows with:

```blade
            <div>
                <div style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 4px;">Access URL</div>
                <div style="font-size: 14px; color: var(--ag-text); font-weight: 600;">{{ $domainView['plugin_enabled'] && $domainView['access_url'] !== '' ? $domainView['access_url'] : 'Not configured' }}</div>
            </div>
            <div>
                <div style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 4px;">Server IP</div>
                <div style="font-size: 14px; color: var(--ag-text); font-weight: 600;">{{ $domainView['server_address'] !== '' ? $domainView['server_address'] : 'Not configured' }}</div>
            </div>
            <div>
                <div style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 4px;">HTTPS</div>
                <div style="font-size: 14px; color: var(--ag-text); font-weight: 600;">{{ ['off' => 'Off', 'builtin' => 'Automatic certificate', 'custom' => 'Own certificate', 'platform' => 'Handled by platform'][$domainView['https_mode']] }}</div>
            </div>
```

- [ ] **Step 5: Remove the old fields from the site form.**

  Inside the site `<form>`, delete:
  - the grid holding "Domain Alias" and "Application IP (auto-fetched)";
  - the "Enter only the alias domain" `<p>`;
  - the "Enable HTTPS" block;
  - the "DNS Instructions for Super Admin" box.

- [ ] **Step 6: Add the Access URL card** right after
  `<h2 …>Site Configuration</h2>` in `#tab-site`, before the site `<form>`:

```blade
        @php
            $dv = $domainView;
            $dvCanEdit = (int) auth()->user()->rbac_id === 100;
            $dvLocked = !$dv['plugin_enabled'] || !$dvCanEdit;
            $dvMode = old('https_mode', $dv['https_mode']);
        @endphp
        <div class="ag-card ag-card--flat" style="margin-bottom: 18px;">
            <h3 style="font-size: 15px; font-weight: 500; margin-bottom: 6px;">Access URL</h3>
            <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 10px;">
                Open the console on your own domain or subdomain, with or without HTTPS.
                <strong>http://{{ $dv['server_ip'] !== '' ? $dv['server_ip'] : 'server-IP' }}:8000</strong> and
                <strong>atglance.internal</strong> always keep working, so a wrong setting never locks you out.
                Each address has its own login session.
            </p>
            @if(!$dv['plugin_enabled'])
                <div class="ag-alert ag-alert--warning" style="margin-bottom: 10px;">
                    <i class="fas fa-lock"></i>
                    <span>Enable Custom Domain &amp; HTTPS in the Plugins tab to set a domain. <a href="{{ route('admin.settings', ['tab' => 'plugins']) }}" style="text-decoration: underline;">Open Plugins</a></span>
                </div>
            @elseif(!$dvCanEdit)
                <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 10px;">Only the super admin can change this.</p>
            @endif

            <form method="POST" action="{{ route('admin.settings.domain') }}" enctype="multipart/form-data">
                @csrf
                <fieldset id="domain-fieldset" {{ $dvLocked ? 'disabled' : '' }} style="border: 0; padding: 0; margin: 0; {{ $dvLocked ? 'opacity: .55;' : '' }}">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 10px;">
                        <div>
                            <label class="ag-label" for="dv-domain">Domain</label>
                            <input class="ag-input" id="dv-domain" type="text" name="domain" value="{{ old('domain', $dv['domain']) }}" placeholder="atglance.internal" autocomplete="off" spellcheck="false">
                            <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;">Any domain or subdomain, e.g. atglance.acme.com. No http:// and no port.</div>
                            @if($dv['local_warning'])
                                <div style="font-size: 12px; color: var(--ag-warning); margin-top: 4px;"><code>.local</code> is reserved for mDNS and can resolve slowly on macOS and Linux. Use <code>.internal</code> or <code>.lan</code> instead.</div>
                            @endif
                        </div>
                        <div>
                            <label class="ag-label" for="dv-ip">Server IP</label>
                            <input class="ag-input" id="dv-ip" type="text" name="server_ip" value="{{ old('server_ip', $dv['server_address']) }}" placeholder="192.168.1.10">
                            <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;">The address users' machines reach this server on. Used for the DNS record.</div>
                        </div>
                    </div>

                    <label class="ag-label" for="dv-mode">HTTPS</label>
                    <select class="ag-select" id="dv-mode" name="https_mode" style="margin-bottom: 6px;">
                        <option value="off" {{ $dvMode === 'off' ? 'selected' : '' }}>Off (plain http://)</option>
                        <option value="builtin" {{ $dvMode === 'builtin' ? 'selected' : '' }} {{ $dv['builtin_seen'] ? '' : 'disabled' }}>Automatic certificate (built-in proxy)</option>
                        <option value="custom" {{ $dvMode === 'custom' ? 'selected' : '' }} {{ $dv['builtin_seen'] ? '' : 'disabled' }}>Use my own certificate (built-in proxy)</option>
                        <option value="platform" {{ $dvMode === 'platform' ? 'selected' : '' }}>Handled by my platform (load balancer / ingress)</option>
                    </select>
                    @unless($dv['builtin_seen'])
                        <div style="font-size: 12px; color: var(--ag-muted); margin-bottom: 10px;">The built-in options unlock after the built-in proxy is detected (Plugins tab).</div>
                    @endunless

                    <div id="dv-custom" style="{{ $dvMode === 'custom' ? '' : 'display:none;' }} margin: 10px 0; padding: 12px; border-radius: 12px; background: var(--ag-card);">
                        @if($dv['certificate'])
                            @php $cert = $dv['certificate']; @endphp
                            <div style="font-size: 13px; margin-bottom: 8px;">
                                <strong>Current certificate:</strong> {{ implode(', ', $cert['names']) }} &middot; issuer {{ $cert['issuer'] }} &middot; expires {{ $cert['not_after'] }}
                                <div style="font-size: 11px; color: var(--ag-muted); word-break: break-all;">SHA-256 {{ $cert['fingerprint'] }}</div>
                            </div>
                            @if($cert['days_left'] < 0)
                                <div class="ag-alert ag-alert--error" style="margin-bottom: 8px;">The certificate has expired. Browsers show a warning until you upload a new one.</div>
                            @elseif($cert['days_left'] <= 30)
                                <div class="ag-alert ag-alert--warning" style="margin-bottom: 8px;">The certificate expires in {{ $cert['days_left'] }} days. Upload the renewed certificate.</div>
                            @endif
                        @endif
                        <div style="font-size: 13px; margin-bottom: 6px;">Upload a PEM certificate (with its chain) and private key, <strong>or</strong> a .pfx/.p12 file.</div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div><label class="ag-label">Certificate (.crt/.pem)</label><input type="file" name="cert_file" accept=".crt,.pem,.cer"></div>
                            <div><label class="ag-label">Private key (.key/.pem)</label><input type="file" name="key_file" accept=".key,.pem"></div>
                            <div><label class="ag-label">Key passphrase (if any)</label><input class="ag-input" type="password" name="key_passphrase" autocomplete="off"></div>
                            <div></div>
                            <div><label class="ag-label">Or .pfx / .p12</label><input type="file" name="pfx_file" accept=".pfx,.p12"></div>
                            <div><label class="ag-label">.pfx password</label><input class="ag-input" type="password" name="pfx_password" autocomplete="off"></div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;">
                        <button class="ag-btn" type="submit">Save Access URL</button>
                        <button class="ag-btn ag-btn--ghost" type="button" id="dv-check">Check DNS</button>
                    </div>
                    <p id="dv-check-result" class="hidden" style="font-size: 13px; margin-top: 8px;" role="status"></p>
                </fieldset>
            </form>

            @if($dv['certificate'] && $dvCanEdit)
                <form method="POST" action="{{ route('admin.settings.domain.certificate.remove') }}" style="margin-top: 8px;" onsubmit="return confirm('Remove the uploaded certificate? HTTPS for the domain turns off.');">
                    @csrf
                    @method('DELETE')
                    <button class="ag-btn ag-btn--danger ag-btn--sm" type="submit">Remove certificate</button>
                </form>
            @endif

            @if($dv['server_ip'] !== '')
                <div style="margin-top: 14px; padding: 12px; border-radius: 12px; background: var(--ag-card); font-size: 13px; line-height: 1.6;">
                    @if($dv['access_url'] !== '')
                        <div><strong>Access URL:</strong> {{ $dv['access_url'] }}</div>
                        <div><strong>DNS record</strong> (ask your DNS admin): <code>{{ $dv['dns_record'] }}</code></div>
                    @endif
                    <div><strong>Test on one machine</strong>: add <code>{{ $dv['hosts_line'] }}</code> to the hosts file
                        (Windows <code>C:\Windows\System32\drivers\etc\hosts</code>, macOS/Linux <code>/etc/hosts</code>).</div>
                    @if($dv['https_mode'] === 'builtin')
                        <div style="margin-top: 6px;">
                            <strong>Private names</strong> (like <code>.internal</code>) get a certificate from the built-in CA. Trust it once on each machine:
                            @if($dv['ca_available'])
                                <a href="{{ route('admin.settings.domain.ca') }}" style="text-decoration: underline;">Download CA certificate</a>.
                            @else
                                it appears here after the first HTTPS visit.
                            @endif
                            Windows: double-click &rsaquo; Install &rsaquo; Local Machine &rsaquo; "Trusted Root Certification Authorities".
                            macOS: open in Keychain Access &rsaquo; System &rsaquo; set to "Always Trust".
                            Linux: copy to <code>/usr/local/share/ca-certificates/</code> and run <code>sudo update-ca-certificates</code>.
                            Firefox: Settings &rsaquo; Certificates &rsaquo; Import.
                        </div>
                    @endif
                </div>
            @endif
        </div>
```

  Add this to the page's existing `<script>` block (it shows or hides the
  certificate fields and runs the DNS check):

```js
(function () {
    var mode = document.getElementById('dv-mode');
    var custom = document.getElementById('dv-custom');
    if (mode && custom) {
        mode.addEventListener('change', function () { custom.style.display = mode.value === 'custom' ? '' : 'none'; });
    }
    var check = document.getElementById('dv-check');
    var result = document.getElementById('dv-check-result');
    if (check && result) {
        check.addEventListener('click', function () {
            result.classList.remove('hidden');
            result.textContent = 'Checking...';
            fetch(@json(route('admin.settings.domain.check')), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                body: JSON.stringify({ domain: document.getElementById('dv-domain').value, server_ip: document.getElementById('dv-ip').value })
            }).then(function (r) { return r.json(); }).then(function (data) {
                result.textContent = data.message + ' (This is the server\'s DNS view; other networks may differ.)';
                result.style.color = data.ok ? 'var(--ag-success)' : 'var(--ag-warning)';
            }).catch(function () { result.textContent = 'Could not run the check.'; });
        });
    }
})();
```

- [ ] **Step 7: Build the Plugins tab card.**

  Replace `<p style="color: var(--ag-muted);">Coming Soon</p>` in
  `#tab-plugins` with:

```blade
        @php
            $dv = $domainView;
            $dvCanEdit = (int) auth()->user()->rbac_id === 100;
            $dvSeen = $dv['proxy_seen'];
            $dvStatus = isset($dvSeen['builtin'])
                ? 'Built-in proxy detected (' . strtoupper($dvSeen['builtin']['scheme']) . ')'
                : (isset($dvSeen['platform']) ? 'Platform proxy detected (' . strtoupper($dvSeen['platform']['scheme']) . ')' : 'Not detected yet. Open the console through the proxy once to confirm.');
        @endphp
        <div class="ag-card" style="margin-top: 12px;">
            <div class="ag-card-header">
                <span class="ag-card-icon"><i class="fas fa-globe"></i></span>
                <h3 class="ag-card-title">Custom Domain &amp; HTTPS</h3>
                <span class="ag-badge {{ $dv['plugin_enabled'] ? 'ag-badge--success' : '' }}" style="margin-left: auto;">{{ $dv['plugin_enabled'] ? 'Enabled' : 'Disabled' }}</span>
            </div>
            <p style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 10px;">
                Open the console on your own domain, e.g. <code>https://atglance.acme.com</code>. Nothing changes on the server until you follow the steps below.
                <code>http://server-IP:8000</code> keeps working at all times.
            </p>
            @if($dvCanEdit)
                <form method="POST" action="{{ route('admin.settings.domain.plugin') }}" style="margin-bottom: 12px;">
                    @csrf
                    <input type="hidden" name="enabled" value="{{ $dv['plugin_enabled'] ? '0' : '1' }}">
                    <button class="ag-btn {{ $dv['plugin_enabled'] ? 'ag-btn--ghost' : '' }}" type="submit">{{ $dv['plugin_enabled'] ? 'Disable' : 'Enable' }}</button>
                </form>
            @else
                <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 12px;">Only the super admin can change this.</p>
            @endif

            @if($dv['plugin_enabled'])
                <div style="font-size: 13px; margin-bottom: 12px;"><strong>Status:</strong> {{ $dvStatus }}</div>
                @if($dv['https_mode'] === 'custom' && $dv['certificate'] && $dv['certificate']['days_left'] <= 30)
                    <div class="ag-alert {{ $dv['certificate']['days_left'] < 0 ? 'ag-alert--error' : 'ag-alert--warning' }}" style="margin-bottom: 12px;">
                        {{ $dv['certificate']['days_left'] < 0 ? 'Your certificate has expired.' : 'Your certificate expires in ' . $dv['certificate']['days_left'] . ' days.' }}
                        Upload the renewed one on the Site tab.
                    </div>
                @endif
                <div style="font-size: 13px; line-height: 1.7;">
                    <strong>VM / Docker Compose</strong> (built-in proxy on ports 80 and 443):
                    <ol style="margin: 4px 0 10px 18px;">
                        <li>Check ports 80 and 443 are free: <code>sudo ss -ltn '( sport = :80 or sport = :443 )'</code> (no output = free).</li>
                        <li>In the install folder (default <code>/opt/atglance</code>) run: <code>docker compose -f docker-compose.yml -f docker-compose.domain.yml up -d</code>. If a port is taken, set <code>HTTP_PORT</code> / <code>HTTPS_PORT</code> in <code>.env</code> first.</li>
                        <li>Open <code>http://server-IP</code> (port 80) once. The status above changes to "Built-in proxy detected".</li>
                        <li>To undo: <code>docker compose up -d</code> (without the second file).</li>
                    </ol>
                    <strong>AWS ECS:</strong> ALB listener on 443 with an ACM certificate, forwarding to container port 8000 (route 8002 separately for the CLI). Then choose "Handled by my platform".<br>
                    <strong>Azure Container Apps:</strong> ingress target port 8000, plus a custom domain with a managed certificate. Then choose "Handled by my platform".<br>
                    <strong>Kubernetes:</strong> an Ingress to service port 8000, with cert-manager for TLS. Then choose "Handled by my platform".
                </div>
                <a class="ag-btn ag-btn--sm" style="margin-top: 12px;" href="{{ route('admin.settings', ['tab' => 'site']) }}">Set the domain on the Site tab</a>
            @endif
        </div>
```

  Keep the Plugins tab `<h2>` and add one intro line under it:
  `<p style="font-size: 13px; color: var(--ag-muted);">Optional features. Each one is off until you enable it.</p>`.

- [ ] **Step 8: Update `SiteConfigurationTest`.**

  - In `test_alias_ip_saved_by_the_installer_with_a_port_is_accepted`:
    - rename it to `test_site_logo_is_saved`;
    - remove `'site_domain_alias_ip' => '127.0.0.1:8000',`;
    - remove the `site_domain_alias_ip` assertion.
  - Delete `test_alias_ip_must_still_be_an_ip`. It is covered by `CustomDomainTest::test_invalid_domain_and_ip_are_refused`.

- [ ] **Step 9: Run the tests.**

  Run: `$RUN` with `--filter "CustomDomainViewTest|SiteConfigurationTest|CustomDomainTest"`, then the full suite.

  Expected: PASS.

- [ ] **Step 10: Commit.**

```bash
git add composer/resources/views/admin/settings.blade.php composer/app/Http/Controllers/AdminDashboardController.php composer/tests/Feature/SiteConfigurationTest.php composer/tests/Feature/CustomDomainViewTest.php
git commit -m "feat: Plugins card and Access URL section for custom domain"
```

---

## Task 8: Installer and info page

**Files:**
- Modify: `composer/app/Http/Controllers/InstallerController.php` (`install()`)
- Modify: `composer/resources/views/install/index.blade.php`. Delete the `app_domain` block and its help `<p>` lines (~lines 155-167), and the `use_https` block (~lines 170-180). The `app_ip` input becomes a single-column field.
- Modify: `composer/resources/views/install/info.blade.php`. Replace the "Domain Alias" and "HTTPS Enabled" cards (~lines 61-68) with a note.
- Test: `composer/tests/Feature/InstallerDomainTest.php`

- [ ] **Step 1: Write the failing tests.**

```php
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
```

- [ ] **Step 2: Run the tests and check they fail.**

  Run: `$RUN` with `--filter InstallerDomainTest`.

  Expected: FAIL (fields present).

- [ ] **Step 3: Update `InstallerController::install()`.**

  - Delete the `app_domain` and `use_https` validation rules.
  - Delete the `$appDomainAlias` and `$httpsEnabled` variables.
  - `$normalizedUrl = 'http://' . $appIpAddress;`
  - `EnvFile::set(['APP_URL' => $normalizedUrl]);` (no `APP_FORCE_HTTPS`).
  - In the `admin_settings` block, keep only
    `AdminSetting::putValue('site', 'site_domain_alias_ip', $appIpAddress);`.
    Delete the `site_domain_alias` and `site_https_enabled` puts.
  - In `markInstalled([...])`:
    - `'app_domain' => $appIpAddress`;
    - delete the `'app_alias_domain'` and `'https_enabled'` keys.

- [ ] **Step 4: Update the views.**

  - In `index.blade.php`, delete:
    - the `<div>` with `for="app_domain"`;
    - the "Do not include http://" and "The domain can also be configured…" `<p>` lines;
    - the whole `use_https` `<div>`.
  - Change the IP grid wrapper `class="grid grid-cols-1 gap-4 sm:grid-cols-2"` to `class="grid grid-cols-1 gap-4"`.
  - In `info.blade.php`, replace the two cards with:

```blade
                <div class="rounded-xl border border-cccccc bg-white p-4 md:col-span-2">
                    <p class="font-semibold text-slate-800">Your own domain or HTTPS</p>
                    <p class="mt-1 text-slate-700">After you log in, open <span class="font-medium">Admin Settings &rsaquo; Plugins &rsaquo; Custom Domain &amp; HTTPS</span>. Until then, use the Project URL below.</p>
                </div>
```

  The info grid is `grid-cols-1 md:grid-cols-2`, so the note spans both columns.

- [ ] **Step 5: Run the tests.**

  Run: `$RUN` with `--filter InstallerDomainTest`, then the full suite.

  Expected: PASS.

- [ ] **Step 6: Commit.**

```bash
git add composer/app/Http/Controllers/InstallerController.php composer/resources/views/install/index.blade.php composer/resources/views/install/info.blade.php composer/tests/Feature/InstallerDomainTest.php
git commit -m "feat: move domain and HTTPS setup out of the installer"
```

---

## Task 9: Built-in proxy in the image (opt-in)

**Files:**
- Create: `docker/Caddyfile`, `docker-compose.domain.yml`
- Modify: `Dockerfile`, `docker/entrypoint.sh`, `install.sh`

- [ ] **Step 1: Pin the Caddy version.**

  Run: `docker run --rm caddy:2 caddy version`.

  Note the version, e.g. `v2.10.x`, and use the matching image tag
  `caddy:2.10` below. It must be ≥ 2.7 for `get_certificate http`.

- [ ] **Step 2: Write `docker/Caddyfile`.**

```
# Built-in proxy for the AtGlance console (VM installs only; opt-in through
# docker-compose.domain.yml, which sets ATGLANCE_PROXY=builtin). The domain,
# HTTPS mode and certificate come from the app at request time:
#   ask             -> /api/internal/domain/tls-allowed
#   get_certificate -> /api/internal/domain/certificate (own certificate)
# See docs/custom-domain.md.
{
	admin off
	storage file_system /app/storage/caddy
	on_demand_tls {
		ask http://127.0.0.1:8000/api/internal/domain/tls-allowed
	}
}

(atglance_upstream) {
	# Internal endpoints are for Caddy only, never for visitors.
	@internal path /api/internal/*
	respond @internal 404
	reverse_proxy 127.0.0.1:8000 {
		header_up X-AtGlance-Proxy builtin
	}
}

:80 {
	import atglance_upstream
}

:443 {
	tls {
		get_certificate http http://127.0.0.1:8000/api/internal/domain/certificate
		on_demand
		issuer acme
		issuer internal
	}
	import atglance_upstream
}
```

- [ ] **Step 3: Validate it.**

  Run:

  ```bash
  MSYS_NO_PATHCONV=1 docker run --rm -v "D:/Projects/Personal/atGlance-managementGUI/docker/Caddyfile:/etc/caddy/Caddyfile:ro" caddy:2.10 caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
  ```

  Expected: `Valid configuration`. The `ask` warning about the storage path is
  fine.

- [ ] **Step 4: Update the `Dockerfile`.**

  Add these after the `install-php-extensions` RUN:

```dockerfile
# Built-in proxy for custom domains (docker-compose.domain.yml). Not started
# unless ATGLANCE_PROXY=builtin; default installs only serve :8000.
COPY --from=caddy:2.10 /usr/bin/caddy /usr/local/bin/caddy
COPY docker/Caddyfile /etc/caddy/Caddyfile
```

  Change `EXPOSE 8000` to `EXPOSE 8000 80 443`.

- [ ] **Step 5: Update `docker/entrypoint.sh`.**

  Add this before `chmod -R ug+rwX storage bootstrap/cache …`:

```sh
# Built-in proxy (opt-in, docker-compose.domain.yml). A failure here must not
# stop the console: it keeps serving on :8000.
if [ "${ATGLANCE_ROLE:-app}" = "app" ] && [ "${ATGLANCE_PROXY:-}" = "builtin" ]; then
    mkdir -p storage/caddy
    if caddy start --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; then
        echo "atglance: built-in proxy started on :80 and :443"
    else
        echo "atglance: built-in proxy failed to start; the console stays on :8000" >&2
    fi
fi
```

- [ ] **Step 6: Write `docker-compose.domain.yml`.**

```yaml
# Opt-in: custom domain and HTTPS through the built-in proxy.
#   docker compose -f docker-compose.yml -f docker-compose.domain.yml up -d
# Undo: docker compose up -d
# Set HTTP_PORT / HTTPS_PORT in .env if 80 or 443 is already in use.
# Then set the domain in Admin Settings > Site. See docs/custom-domain.md.
services:
  app:
    environment:
      ATGLANCE_PROXY: builtin
    ports:
      - "${HTTP_PORT:-80}:80"
      - "${HTTPS_PORT:-443}:443"
```

- [ ] **Step 7: Update `install.sh`.**

  After `ok "docker-compose.yml ready"`, download the override file without
  using it. A failure is a warning only:

```sh
# Opt-in override for custom domains (not used by the install).
if curl -fsSL "$REPO_RAW/docker-compose.domain.yml" -o docker-compose.domain.yml.new; then
    mv docker-compose.domain.yml.new docker-compose.domain.yml
else
    rm -f docker-compose.domain.yml.new
    warn "Could not download docker-compose.domain.yml (only needed for a custom domain later)."
fi
```

- [ ] **Step 8: Build and check that the default behaviour is unchanged.**

  Run:

  ```bash
  docker build -t localhost:5000/atglance/ce-atglance-app:ui-test .
  docker run --rm --entrypoint caddy localhost:5000/atglance/ce-atglance-app:ui-test version
  ```

  Then redeploy locally without the override (the command from the session
  notes: `docker compose -p atglance -f docker-compose.yml up -d --no-deps app worker scheduler` with `ATGLANCE_REGISTRY`/`ATGLANCE_VERSION`/`DB_PASSWORD`).

  Check:
  - `docker port ce-atglance-app` lists only 8000;
  - `docker logs ce-atglance-app` has no "built-in proxy" line.

- [ ] **Step 9: Test the proxy manually** (records the Caddy behaviour that the spec says to verify).

  1. Enable the plugin in the UI.
  2. Run the override (add `-f docker-compose.domain.yml`).
  3. Open `http://localhost/`. The Plugins status shows "Built-in proxy detected (HTTP)".
  4. Run `curl -s -o /dev/null -w '%{http_code}' http://localhost/api/internal/domain/certificate?server_name=x`. Expected `404` (Caddy blocks it).
  5. Set the domain to `atglance.internal` with an Automatic certificate, and add `127.0.0.1 atglance.internal` to the hosts file.
     - `curl -vk https://atglance.internal/ 2>&1 | grep issuer` shows the Caddy local CA.
     - `http://atglance.internal/login` gives `302` to https.
     - `http://localhost:8000/login` is not redirected.
  6. Generate a test CA and leaf for `atglance.internal` (OpenSSL), switch to "Use my own certificate", and upload the leaf and key.
     - `curl -vk https://atglance.internal/ 2>&1 | grep issuer` shows the test CA.
     - `docker exec ce-atglance-app ls /app/storage/caddy/certificates` has no new `atglance.internal` entry after the switch.
  7. Upload a second leaf with a different serial. Note after how long `curl` shows the new serial.
     - If it changes within about a minute, document "new connections use it within a minute".
     - If it does not change until Caddy restarts, implement the marker restart from spec section 11: write `storage/caddy/reload` on upload and remove, and add a watcher loop in the entrypoint that runs `caddy stop && caddy start …` when the file's mtime changes. Add that as a follow-up commit.
  8. Occupy port 80 (`python -m http.server 80`) and run the override. Docker reports "port is already allocated". `docker compose up -d` restores the default.

- [ ] **Step 10: Commit.**

```bash
git add docker/Caddyfile docker-compose.domain.yml Dockerfile docker/entrypoint.sh install.sh
git commit -m "feat: opt-in built-in Caddy proxy for custom domain and HTTPS"
```

---

## Task 10: Docs

**Files:**
- Create: `docs/custom-domain.md`
- Modify: `README.md`, `INSTALLATION.md`, `CLAUDE.md` (doc lists), `docs/web-console.md` (Settings tabs: Plugins and Site)

- [ ] **Step 1: Write `docs/custom-domain.md`.**

```markdown
# Custom domain and HTTPS

Open the console on your own domain or subdomain (for example
`https://atglance.acme.com`), with or without HTTPS. Set it up after
installation. The installer does not ask for a domain.

## Addresses that always work

| Address | Notes |
|---|---|
| `http://<server IP>:8000` | Always works and is never redirected. Use it to fix a wrong setting. |
| `http://atglance.internal:8000` | Works once the name points to the server (DNS or hosts file). |
| `http(s)://atglance.internal` | With the built-in proxy. HTTPS uses the built-in CA. |
| `http(s)://<your domain>` | After you set the domain on the Site tab. |

Each address has its own login session.

## 1. Enable the plugin

Admin Settings > Plugins > **Custom Domain & HTTPS** > Enable (super admin).
Nothing changes on the server yet.

## 2. Put a proxy in front

**VM / Docker Compose (built-in proxy):**

1. Check that ports 80 and 443 are free: `sudo ss -ltn '( sport = :80 or sport = :443 )'`.
2. In the install folder (default `/opt/atglance`):
   `docker compose -f docker-compose.yml -f docker-compose.domain.yml up -d`.
   If a port is taken, set `HTTP_PORT` / `HTTPS_PORT` in `.env` first.
3. Open `http://<server IP>` once. The plugin status changes to "Built-in proxy detected".
4. Undo: `docker compose up -d`.

**AWS ECS:** ALB listener on 443 with an ACM certificate, forwarding to
container port 8000. The atglance CLI still needs port 8002 (Kong); route it
separately.

**Azure Container Apps:** ingress target port 8000, plus a custom domain with a
managed certificate.

**Kubernetes:** an Ingress to service port 8000, with cert-manager.

## 3. Set the domain

Admin Settings > Site > **Access URL**:

- **Domain:** any domain or subdomain, without `http://` and without a port.
  Avoid `.local` (reserved for mDNS); use `.internal` or `.lan`.
- **Server IP:** the address users reach the server on.
- **HTTPS:**
  - **Off:** plain `http://`.
  - **Automatic certificate:** built-in proxy. Let's Encrypt for public names,
    otherwise the built-in CA. Download the CA certificate from the Site tab
    and trust it once on each machine.
  - **Use my own certificate:** built-in proxy. Upload a PEM certificate (with
    chain) and key, or a `.pfx`/`.p12`. Renew by uploading again. The console
    warns 30 days before expiry.
  - **Handled by my platform:** the load balancer does TLS.

Then create the DNS record the page shows (`<domain>  A  <server IP>`). Use
**Check DNS** to confirm it.

## How it works

- The app decides everything at request time. Changing the domain, HTTPS mode
  or certificate needs no restart.
- Only the configured domain is redirected to HTTPS (`302`, GET/HEAD only).
  IPs, `localhost` and `atglance.internal` are never redirected.
- Saving the domain sets `APP_URL` (used for emails and background jobs).
  Disabling the plugin sets it back to `http://<server IP>`.
- The built-in proxy asks `/api/internal/domain/tls-allowed` before it issues a
  certificate, and gets the uploaded certificate from
  `/api/internal/domain/certificate`. Both answer only Caddy itself: local
  requests without proxy headers. Caddy also blocks `/api/internal/*` from
  outside.
- Trusted proxy ranges: `127.0.0.1, ::1, 10.0.0.0/8, 172.16.0.0/12,
  192.168.0.0/16`. Override them with `ATGLANCE_TRUSTED_PROXIES`.
- Settings (`admin_settings`): `domain_plugin_enabled`, `site_domain_alias`,
  `site_domain_alias_ip`, `site_https_mode`, `custom_cert` (encrypted),
  `proxy_seen`. Certificates from the built-in proxy live in
  `storage/caddy/` on the app-storage volume.

Code: `App\Support\DomainSettings`, `App\Support\CustomCertificate`,
`App\Http\Controllers\DomainSettingsController`,
`App\Http\Controllers\DomainTlsController`,
`App\Http\Middleware\EnforceDomainHttps`,
`App\Http\Middleware\DetectFrontProxy`, `docker/Caddyfile`,
`docker-compose.domain.yml`.
```

  Replace the "new connections use it within …" wording in the certificate
  bullet with what Task 9, step 9.7 found.

- [ ] **Step 2: Link the doc.**

  - In `CLAUDE.md`, add `` `docs/custom-domain.md` (custom domain, HTTPS and the built-in proxy) `` to the living-docs list.
  - In `README.md` and `INSTALLATION.md`, add a short "Custom domain and HTTPS" line linking to it, in their doc lists.
  - In `INSTALLATION.md`, add one sentence under the compose section: `docker-compose.domain.yml` is an opt-in override, not used by the install.
  - In `docs/web-console.md`, describe the Plugins tab (Custom Domain & HTTPS card) and the Site tab's Access URL section.

- [ ] **Step 3: Commit.**

```bash
git add docs/custom-domain.md README.md INSTALLATION.md CLAUDE.md docs/web-console.md
git commit -m "docs: custom domain and HTTPS guide"
```

---

## Task 11: Final verification

- [ ] **Step 1: Run the full suite.**

  Run: `$RUN` with `--filter .`.

  Expected: all pass. Previously 119, plus the new tests.

- [ ] **Step 2: Fresh-install check** (constraint 1).

  Rebuild the image. Reset the local deploy to the install page (back up the
  DB and `installed.json` first). Install with the default compose file.

  Confirm:
  - the form has no domain or HTTPS fields;
  - the info page shows the Plugins note;
  - `docker port ce-atglance-app` shows only 8000;
  - the login works on `http://localhost:8000`.

- [ ] **Step 3: Check the CLI path.**

  Run `curl -s http://localhost:8002/api/auth/validate-token`. Expected: a JSON
  answer from Kong (unchanged).

- [ ] **Step 4: Multi-arch build smoke test.**

  Run:

  ```bash
  docker buildx build --platform linux/amd64,linux/arm64 -t atglance/ce-atglance-app:test .
  ```

  Don't push. Pushing is the user's call.

- [ ] **Step 5: Update GitHub issue #22.**

  Comment with a summary and the test results. Move the project item to
  "In review" when a PR is opened (`gh project item-edit`, Status option
  `In review`).
