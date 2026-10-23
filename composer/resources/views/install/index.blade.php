<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AtGlance Installer</title>
    <link rel="icon" href="{{ asset('branding/favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('branding/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('branding/apple-touch-icon.png') }}">
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
                    <img src="{{ asset('branding/atglance-icon.png') }}" alt="" class="h-14 w-14 shrink-0 rounded-2xl shadow-lg">
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

                <form id="installerForm" action="{{ route('install.run') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-5">
                    @csrf

                    @php($licenseMode = old('license_mode', $errors->has('license_key') ? 'key' : 'file') === 'key' ? 'key' : 'file')
                    <section class="rounded-2xl border border-slate-300 bg-slate-50 p-4 sm:p-5" data-license-mode="{{ $licenseMode }}" id="licenseStep">
                        <h2 class="text-base font-extrabold text-slate-900">Step 1 &mdash; Licence</h2>
                        <p class="mt-1 text-xs text-slate-700">
                            <span class="font-semibold">If this Management Console has internet access</span>, use your <span class="font-semibold">licence key</span>.
                            <span class="font-semibold">Without internet access</span>, a <span class="font-semibold">licence file</span> is required.
                            Both come from the same licence on <a href="{{ $licensePortalUrl }}" target="_blank" rel="noopener" class="font-semibold text-black underline">atglance.live</a> &rsaquo; Licences.
                        </p>

                        <input type="hidden" name="license_mode" id="license_mode" value="{{ $licenseMode }}">
                        <div class="mt-3 grid grid-cols-2 gap-2" role="tablist" aria-label="How to add the licence">
                            <button type="button" role="tab" data-mode="key" aria-selected="{{ $licenseMode === 'key' ? 'true' : 'false' }}"
                                class="license-tab rounded-xl border px-3 py-2 text-sm font-bold transition">Licence key <span class="block text-[11px] font-medium">internet access</span></button>
                            <button type="button" role="tab" data-mode="file" aria-selected="{{ $licenseMode === 'file' ? 'true' : 'false' }}"
                                class="license-tab rounded-xl border px-3 py-2 text-sm font-bold transition">Licence file <span class="block text-[11px] font-medium">no internet access</span></button>
                        </div>

                        {{-- Licence key: activated online once; atglance.live answers with the licence file. --}}
                        <div data-panel="key" class="mt-4 {{ $licenseMode === 'key' ? '' : 'hidden' }}">
                            <ol class="list-decimal space-y-1 pl-5 text-xs text-slate-700">
                                <li>On atglance.live &rsaquo; Licences, create a licence and confirm it with the emailed code.</li>
                                <li>Copy the licence key and paste it below. The console activates it online once; after that it works offline.</li>
                            </ol>
                            <label for="license_key" class="mt-3 block text-sm font-semibold text-slate-800">Licence Key</label>
                            <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                                <input id="license_key" name="license_key" type="text" autocomplete="off" spellcheck="false" value="{{ old('license_key', '') }}" placeholder="Paste your licence key (atg_...)"
                                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-mono text-sm outline-none transition focus:border-black focus:ring-4 focus:ring-slate-200">
                                <button id="licenseVerifyBtn" type="button"
                                    class="shrink-0 rounded-xl border border-black bg-white px-4 py-3 text-sm font-bold text-black transition hover:bg-slate-100 disabled:border-slate-300 disabled:text-slate-400">Verify</button>
                            </div>
                            <p id="licenseVerifyResult" class="mt-2 hidden text-xs font-semibold" role="status"></p>
                        </div>

                        {{-- Licence file: checked on this server only. --}}
                        <div data-panel="file" class="mt-4 {{ $licenseMode === 'file' ? '' : 'hidden' }}">
                            <ol class="list-decimal space-y-1 pl-5 text-xs text-slate-700">
                                <li>On atglance.live &rsaquo; Licences, click <span class="font-semibold">Licence file</span> on a confirmed licence.</li>
                                <li>Enter this console's Installation ID: <span class="font-mono font-semibold text-black">{{ $installId }}</span></li>
                                <li>Download the .atgl file and upload it below <span class="font-semibold">within the time shown on atglance.live</span>.</li>
                            </ol>
                            <label for="license_file" class="mt-3 block text-sm font-semibold text-slate-800">Licence File</label>
                            <input id="license_file" name="license_file" type="file" accept=".atgl"
                                class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-black file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-white">
                            <p id="licensePreview" class="mt-2 hidden text-xs font-semibold" role="status"></p>
                            <dl id="licenseDetails" class="mt-3 hidden grid grid-cols-2 gap-x-4 gap-y-1 rounded-xl border border-green-200 bg-green-50 p-3 text-xs text-slate-800">
                                <dt class="text-slate-500">Customer</dt><dd data-field="customer"></dd>
                                <dt class="text-slate-500">Plan</dt><dd data-field="plan"></dd>
                                <dt class="text-slate-500">Policy</dt><dd data-field="policy"></dd>
                                <dt class="text-slate-500">Node limit</dt><dd data-field="nodes"></dd>
                                <dt class="text-slate-500">User limit</dt><dd data-field="users"></dd>
                                <dt class="text-slate-500">Features</dt><dd data-field="features"></dd>
                                <dt class="text-slate-500">Issued</dt><dd data-field="issued_at"></dd>
                                <dt class="text-slate-500">Expires</dt><dd data-field="expires_at"></dd>
                            </dl>
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

                    <div class="grid grid-cols-1 gap-4">
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
                    </div>
                    <p class="text-xs text-slate-600">The address people use to reach this server, without http://. You can add your own domain and HTTPS after installation, in Admin Settings &rsaquo; Plugins.</p>

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
                    src="{{ asset('branding/atglance-logo.png') }}"
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
                    <p>Version {{ config('app.version') }}</p>
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
            var step = document.getElementById('licenseStep');
            var modeInput = document.getElementById('license_mode');
            var fileInput = document.getElementById('license_file');
            var keyInput = document.getElementById('license_key');
            var verifyBtn = document.getElementById('licenseVerifyBtn');
            var verifyResult = document.getElementById('licenseVerifyResult');
            var result = document.getElementById('licensePreview');
            var details = document.getElementById('licenseDetails');
            var submitBtn = document.querySelector('#installerForm [type="submit"]');
            var token = document.querySelector('#installerForm [name="_token"]').value;
            var fileValid = false;

            function show(el, ok, message) {
                el.textContent = message;
                el.classList.remove('hidden', 'text-green-700', 'text-red-700');
                el.classList.add(ok ? 'text-green-700' : 'text-red-700');
            }

            // A key is activated on submit; a file must pass the preview first.
            function refreshSubmit() {
                var mode = modeInput.value;
                if (submitBtn) {
                    submitBtn.disabled = mode === 'file' ? !fileValid : keyInput.value.trim() === '';
                }
            }

            function setMode(mode) {
                modeInput.value = mode;
                step.setAttribute('data-license-mode', mode);
                step.querySelectorAll('[data-panel]').forEach(function (panel) {
                    panel.classList.toggle('hidden', panel.getAttribute('data-panel') !== mode);
                });
                step.querySelectorAll('.license-tab').forEach(function (tab) {
                    var active = tab.getAttribute('data-mode') === mode;
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    tab.classList.toggle('border-black', active);
                    tab.classList.toggle('bg-black', active);
                    tab.classList.toggle('text-white', active);
                    tab.classList.toggle('border-slate-300', !active);
                    tab.classList.toggle('bg-white', !active);
                    tab.classList.toggle('text-slate-800', !active);
                });
                refreshSubmit();
            }

            step.querySelectorAll('.license-tab').forEach(function (tab) {
                tab.addEventListener('click', function () { setMode(tab.getAttribute('data-mode')); });
            });
            keyInput.addEventListener('input', refreshSubmit);
            setMode(modeInput.value);

            function readJson(response) {
                // An expired session (419) is answered with a redirect to an HTML page, not JSON.
                if (response.redirected || (response.headers.get('Content-Type') || '').indexOf('application/json') === -1) {
                    throw new Error('session');
                }
                return response.json();
            }

            function failed(el, error, fallback) {
                show(el, false, error && error.message === 'session'
                    ? 'Your session expired. Reload this page and try again.'
                    : fallback);
            }

            verifyBtn.addEventListener('click', function () {
                if (keyInput.value.trim() === '') {
                    show(verifyResult, false, 'Enter a licence key first.');
                    return;
                }
                verifyBtn.disabled = true;
                verifyBtn.textContent = 'Verifying...';
                fetch(@json(route('install.license.verify')), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ license_key: keyInput.value.trim() })
                })
                    .then(readJson)
                    .then(function (data) {
                        var parts = [];
                        if (data.ok && data.name) { parts.push('Licence: ' + data.name); }
                        if (data.ok && data.plan) { parts.push('Plan: ' + data.plan); }
                        show(verifyResult, !!data.ok, (data.message || (data.ok ? 'Licence key is valid.' : 'The licence key was not accepted.')) + (parts.length ? ' ' + parts.join(' · ') : ''));
                    })
                    .catch(function (error) { failed(verifyResult, error, 'Could not check the licence key. Without internet access, use a licence file.'); })
                    .finally(function () { verifyBtn.disabled = false; verifyBtn.textContent = 'Verify'; });
            });

            fileInput.addEventListener('change', function () {
                fileValid = false;
                details.classList.add('hidden');
                refreshSubmit();
                if (!fileInput.files.length) {
                    result.classList.add('hidden');
                    return;
                }
                if (!/\.atgl$/i.test(fileInput.files[0].name)) {
                    show(result, false, 'Choose an .atgl licence file.');
                    return;
                }

                var body = new FormData();
                body.append('license_file', fileInput.files[0]);
                show(result, true, 'Checking the licence file...');

                fetch(@json(route('install.license.preview')), {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: body
                })
                    .then(readJson)
                    .then(function (data) {
                        if (!data.ok) {
                            show(result, false, data.message || 'The licence file is not valid.');
                            return;
                        }
                        details.querySelectorAll('[data-field]').forEach(function (el) {
                            el.textContent = data.license[el.getAttribute('data-field')] || '-';
                        });
                        show(result, true, 'Licence file is valid. Check the details, then install.');
                        details.classList.remove('hidden');
                        fileValid = true;
                        refreshSubmit();
                    })
                    .catch(function (error) { failed(result, error, 'Could not check the licence file. Try again.'); });
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