<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AtGlance Installer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Sora', 'ui-sans-serif', 'system-ui']
                    },
                    colors: {
                        brand: {
                            50: '#effdf7',
                            100: '#d8f9e8',
                            500: '#444444',
                            600: '#555555',
                            700: '#666666'
                        }
                    },
                    boxShadow: {
                        glow: '0 20px 60px -30px #222222'
                    }
                }
            }
        };
    </script>
    <style>
        :root {
            --bg-1: #f5f5f5;
            --bg-2: #ffffff;
            --bg-3: #cccccc;
            --ink: #0f172a;
        }

        body {
            font-family: 'Sora', ui-sans-serif, system-ui;
            color: var(--ink);
        }
    </style>
</head>
<body class="min-h-screen overflow-x-hidden bg-white">
    <div class="fixed inset-0 -z-10">
        <div class="h-full w-full bg-white"></div>
    </div>

    <div class="mx-auto flex min-h-screen w-full max-w-7xl items-center justify-center px-5 py-8 sm:px-8">
        <div class="grid w-full gap-6 rounded-3xl border border-white/20 bg-white/90 p-4 shadow-glow backdrop-blur-xl sm:p-6 lg:grid-cols-2">
            <div class="order-2 rounded-2xl border border-slate-200 bg-white p-5 sm:p-7 lg:order-2">
                <div class="mb-6 flex items-center gap-4">
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-black text-lg font-extrabold text-white shadow-lg">
                        AT
                    </div>
                    <div>
                        <h1 class="text-2xl font-extrabold text-slate-900 sm:text-3xl">AtGlance Project Installer</h1>
                    </div>
                </div>

                <p class="mt-5 text-sm font-medium text-slate-600 sm:text-base">Please help set up the AtGlance application for your organization.</p>

                @if ($errors->any())
                    <div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                        <ul class="list-disc pl-5 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form id="installerForm" action="{{ route('install.run') }}" method="POST" class="mt-6 space-y-5">
                    @csrf

                    @php($licenseLater = old('license_later') === '1')
                    <section class="rounded-2xl border border-slate-300 bg-slate-50 p-4 sm:p-5">
                        <h2 class="text-base font-extrabold text-slate-900">Step 1 &mdash; Licence Key</h2>
                        <p class="mt-1 text-xs text-slate-600">Add your AtGlance licence key before you start the installation.</p>

                        <ol class="mt-3 list-decimal space-y-1 pl-5 text-xs text-slate-700">
                            <li>Log in to <a href="{{ $licensePortalUrl }}" target="_blank" rel="noopener" class="font-semibold text-black underline">atglance.live</a>.</li>
                            <li>Generate a licence for this installation.</li>
                            <li>Copy the licence key and paste it below.</li>
                        </ol>

                        <label for="license_key" class="mt-4 block text-sm font-semibold text-slate-800">Licence Key</label>
                        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                            <input
                                id="license_key"
                                name="license_key"
                                type="text"
                                autocomplete="off"
                                spellcheck="false"
                                value="{{ old('license_key', '') }}"
                                placeholder="Paste your licence key"
                                {{ $licenseLater ? 'disabled' : '' }}
                                class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-mono text-sm outline-none transition focus:border-black focus:ring-4 focus:ring-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                            >
                            <button
                                id="licenseVerifyBtn"
                                type="button"
                                {{ $licenseLater ? 'disabled' : '' }}
                                class="shrink-0 rounded-xl border border-black bg-white px-4 py-3 text-sm font-bold text-black transition hover:bg-slate-100 disabled:border-slate-300 disabled:text-slate-400"
                            >Verify</button>
                        </div>
                        <p id="licenseVerifyResult" class="mt-2 hidden text-xs font-semibold" role="status"></p>

                        <label class="mt-3 flex items-center gap-2 text-sm text-slate-800">
                            <input type="hidden" name="license_later" value="0">
                            <input id="license_later" type="checkbox" name="license_later" value="1" {{ $licenseLater ? 'checked' : '' }} class="h-4 w-4 rounded border-slate-400">
                            I'll add later
                        </label>

                        <div id="licenseLaterWarning" class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 {{ $licenseLater ? '' : 'hidden' }}">
                            <p class="font-bold">Warning: AtGlance will be installed without a licence.</p>
                            <p class="mt-1">Until a licence is added, nobody can create or register users and no API keys can be created, so no server can connect with the atglance CLI.</p>
                            <p class="mt-1">To add it later: log in as the super admin, open <span class="font-semibold">Admin Settings &rsaquo; Licence</span>, paste the licence key and click <span class="font-semibold">Verify &amp; Save</span>.</p>
                        </div>
                    </section>

                    <h2 class="text-base font-extrabold text-slate-900">Step 2 &mdash; Application Setup</h2>

                    <div>
                        <label for="organization_name" class="block text-sm font-semibold text-slate-800">Organization Name</label>
                        <input
                            id="organization_name"
                            name="organization_name"
                            type="text"
                            required
                            value="{{ old('organization_name', 'Default Organization') }}"
                            placeholder="Acme Corp"
                            class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-black focus:ring-4 focus:ring-slate-200"
                        >
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="app_ip" class="block text-sm font-semibold text-slate-800">IP Address</label>
                            <input
                                id="app_ip"
                                name="app_ip"
                                type="text"
                                required
                                value="{{ old('app_ip', $defaultIpAddress ?? '127.0.0.1') }}"
                                placeholder="192.168.1.2:8000"
                                class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-black focus:ring-4 focus:ring-slate-200"
                            >
                        </div>
                        <div>
                            <label for="app_domain" class="block text-sm font-semibold text-slate-800">Domain Alias (optional)</label>
                            <input
                                id="app_domain"
                                name="app_domain"
                                type="text"
                                value="{{ old('app_domain', '') }}"
                                placeholder="atglance.org_name.com"
                                class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-black focus:ring-4 focus:ring-slate-200"
                            >
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">Do not include http:// or https://</p>
                    <p class="text-xs text-slate-600">Note: The domain can also be configured via the Enterprise Console. Please ensure that a valid and appropriate IP address is provided during the application installation process.</p>

                    <div>
                        <label for="use_https" class="block text-sm font-semibold text-slate-800">Use HTTPS</label>
                        <select
                            id="use_https"
                            name="use_https"
                            class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-black focus:ring-4 focus:ring-slate-200"
                        >
                            <option value="1" {{ old('use_https', '0') === '1' ? 'selected' : '' }}>Yes</option>
                            <option value="0" {{ old('use_https', '0') === '0' ? 'selected' : '' }}>No</option>
                        </select>
                    </div>

                    <div>
                        <label for="superadmin_email" class="block text-sm font-semibold text-slate-800">Super Admin Email</label>
                        <input
                            id="superadmin_email"
                            name="superadmin_email"
                            type="email"
                            required
                            placeholder="username@org-name.com"
                            class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-black focus:ring-4 focus:ring-slate-200"
                        >
                    </div>

                    <div>
                        <label for="superadmin_password" class="block text-sm font-semibold text-slate-800">Super Admin Password</label>
                        <input
                            id="superadmin_password"
                            name="superadmin_password"
                            type="password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            placeholder="Minimum 8 characters"
                            class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-black focus:ring-4 focus:ring-slate-200"
                        >
                    </div>

                    <div>
                        <label for="superadmin_password_confirmation" class="block text-sm font-semibold text-slate-800">Confirm Password</label>
                        <input
                            id="superadmin_password_confirmation"
                            name="superadmin_password_confirmation"
                            type="password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            placeholder="Re-enter super admin password"
                            class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-black focus:ring-4 focus:ring-slate-200"
                        >
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-xl bg-black px-4 py-3 text-sm font-bold uppercase tracking-wider text-white transition"
                        onmouseover="this.style.background='#555555'"
                        onmouseout="this.style.background='#000000'"
                    >
                        Next - Install Application
                    </button>
                </form>
            </div>

            <aside class="order-1 flex h-full flex-col rounded-2xl border border-slate-200 bg-slate-50 p-5 sm:p-7 lg:order-1">
                <img
                    src="{{ asset('branding/atglance-logo.svg') }}"
                    alt="AtGlance logo"
                    class="h-14 w-auto object-contain sm:h-16"
                >

                <h2 class="mt-5 text-xl font-extrabold text-slate-900 sm:text-2xl">AtGlance Platform</h2>
                <p class="mt-3 text-sm leading-relaxed text-slate-700 sm:text-base">
                    AtGlance protects Linux configuration files and helps your team recover quickly with reliable backups and centralized monitoring.
                </p>

                <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-800">Get a Licence</h3>
                    <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-slate-700">
                        <li>Log in to <a href="{{ $licensePortalUrl }}" target="_blank" rel="noopener" class="font-medium text-black hover:underline">atglance.live</a>.</li>
                        <li>Generate a licence.</li>
                        <li>Copy the licence key and paste it in the Licence Key field.</li>
                    </ol>
                    <p class="mt-3 text-xs text-slate-500">No licence yet? Tick "I'll add later". You can add it afterwards from Admin Settings &rsaquo; Licence. User creation, registration and API keys stay locked until you do.</p>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-800">Public Links</h3>
                    <ul class="mt-3 space-y-2 text-sm text-slate-700">
                        <li><a href="https://atglance.live" target="_blank" rel="noopener" class="font-medium text-black hover:underline">Website</a></li>
                        <li><a href="https://atglance.live/docs" target="_blank" rel="noopener" class="font-medium text-black hover:underline">Documentation</a></li>
                    </ul>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-800">Contact</h3>
                    <p class="mt-2 text-sm text-slate-700">
                        <a href="mailto:support@atglance.live" class="font-medium text-black hover:underline">support@atglance.live</a>
                    </p>
                </div>

                <footer class="mt-auto pt-6 text-xs text-slate-500">
                    <p>Version {{ config('app.version', 'v1.0.0') }}</p>
                    <p class="mt-1">All rights reserved.</p>
                </footer>
            </aside>
        </div>
    </div>

    <div id="installOverlay" class="pointer-events-none fixed inset-0 z-40 hidden items-center justify-center bg-slate-950/70 px-5">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
            <p class="text-sm font-semibold uppercase tracking-widest text-black">AtGlance</p>
            <h2 class="mt-2 text-xl font-extrabold text-slate-900">We are setting up it for you...</h2>
            <p class="mt-2 text-sm text-slate-600">Please wait while we prepare your AtGlance application.</p>

            <div class="mt-5 h-3 w-full overflow-hidden rounded-full bg-slate-200">
                <div id="installProgressBar" class="h-full w-[8%] rounded-full bg-black transition-all duration-500"></div>
            </div>
            <p id="installProgressText" class="mt-2 text-right text-xs font-semibold text-slate-500">8%</p>
        </div>
    </div>

    <script>
        (function () {
            var keyInput = document.getElementById('license_key');
            var laterBox = document.getElementById('license_later');
            var verifyBtn = document.getElementById('licenseVerifyBtn');
            var result = document.getElementById('licenseVerifyResult');
            var warning = document.getElementById('licenseLaterWarning');

            function showResult(ok, message) {
                result.textContent = message;
                result.classList.remove('hidden', 'text-green-700', 'text-red-700');
                result.classList.add(ok ? 'text-green-700' : 'text-red-700');
            }

            laterBox.addEventListener('change', function () {
                var later = laterBox.checked;
                keyInput.disabled = later;
                verifyBtn.disabled = later;
                keyInput.required = !later;
                warning.classList.toggle('hidden', !later);
                if (later) {
                    result.classList.add('hidden');
                }
            });
            keyInput.required = !laterBox.checked;

            verifyBtn.addEventListener('click', function () {
                if (keyInput.value.trim() === '') {
                    showResult(false, 'Enter a licence key first.');
                    return;
                }

                verifyBtn.disabled = true;
                verifyBtn.textContent = 'Verifying...';

                fetch(@json(route('install.license.verify')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('#installerForm [name="_token"]').value
                    },
                    body: JSON.stringify({ license_key: keyInput.value.trim() })
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            var parts = [];
                            if (data.name) { parts.push('Licence: ' + data.name); }
                            if (data.plan) { parts.push('Plan: ' + data.plan); }
                            if (data.expires_at) { parts.push('Expires: ' + data.expires_at); }
                            showResult(true, 'Licence verified.' + (parts.length ? ' ' + parts.join(' · ') : ''));
                        } else {
                            showResult(false, data.message || 'Licence verification failed.');
                        }
                    })
                    .catch(function () { showResult(false, 'Could not verify the licence. Try again.'); })
                    .finally(function () {
                        verifyBtn.disabled = laterBox.checked;
                        verifyBtn.textContent = 'Verify';
                    });
            });
        })();

        (function () {
            var form = document.getElementById('installerForm');
            var overlay = document.getElementById('installOverlay');
            var bar = document.getElementById('installProgressBar');
            var text = document.getElementById('installProgressText');
            var progressTimer = null;

            if (!form || !overlay || !bar || !text) {
                return;
            }

            form.addEventListener('submit', function () {
                overlay.classList.remove('hidden');
                overlay.classList.add('flex');

                var progress = 8;
                bar.style.width = progress + '%';
                text.textContent = progress + '%';

                progressTimer = window.setInterval(function () {
                    if (progress >= 92) {
                        window.clearInterval(progressTimer);
                        return;
                    }

                    progress += Math.floor(Math.random() * 8) + 3;
                    if (progress > 92) {
                        progress = 92;
                    }

                    bar.style.width = progress + '%';
                    text.textContent = progress + '%';
                }, 420);
            });
        })();
    </script>
</body>
</html>