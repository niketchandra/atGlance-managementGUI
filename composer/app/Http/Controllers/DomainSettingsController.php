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

        $fail = fn (string $field, string $message) => $back
            ->withErrors([$field => $message])
            ->withInput($request->except(['key_passphrase', 'pfx_password']));

        $domain = DomainSettings::normalizeDomain((string) ($validated['domain'] ?? ''));
        if (($error = DomainSettings::validateDomain($domain)) !== null) {
            return $fail('domain', $error);
        }

        $mode = $domain === '' ? DomainSettings::MODE_OFF : $validated['https_mode'];

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
