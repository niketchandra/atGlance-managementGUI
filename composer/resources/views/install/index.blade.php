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

                    <section class="rounded-2xl border border-slate-300 bg-slate-50 p-4 sm:p-5" id="licenseStep">
                        <h2 class="text-base font-extrabold text-slate-900">Step 1 &mdash; Licence File</h2>
                        <p class="mt-1 text-xs text-slate-600">Upload your AtGlance licence file (.atgl). It is checked on this server; nothing is sent over the internet.</p>

                        <ol class="mt-3 list-decimal space-y-1 pl-5 text-xs text-slate-700">
                            <li>Log in to <a href="{{ $licensePortalUrl }}" target="_blank" rel="noopener" class="font-semibold text-black underline">atglance.live</a> and open <span class="font-semibold">Licences</span>.</li>
                            <li>Enter this console's Installation ID if asked: <span class="font-mono font-semibold text-black">{{ $installId }}</span></li>
                            <li>Click <span class="font-semibold">Generate licence file</span> and download it.</li>
                            <li>Upload it below <span class="font-semibold">within the time shown on atglance.live</span>.</li>
                        </ol>

                        <label for="license_file" class="mt-4 block text-sm font-semibold text-slate-800">Licence File</label>
                        <input id="license_file" name="license_file" type="file" accept=".atgl" required
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
                        <li>Open Licences and click Generate licence file (enter this console's Installation ID if asked).</li>
                        <li>Upload the .atgl file in Step 1.</li>
                    </ol>
                    <p class="mt-3 text-xs text-slate-500">The installation needs a valid licence file. Renew it later in Admin Settings &rsaquo; Licence.</p>
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
            var fileInput = document.getElementById('license_file');
            var result = document.getElementById('licensePreview');
            var details = document.getElementById('licenseDetails');
            var submitBtn = document.querySelector('#installerForm [type="submit"]');

            function show(ok, message) {
                result.textContent = message;
                result.classList.remove('hidden', 'text-green-700', 'text-red-700');
                result.classList.add(ok ? 'text-green-700' : 'text-red-700');
            }

            function setValid(valid) {
                if (submitBtn) { submitBtn.disabled = !valid; }
                details.classList.toggle('hidden', !valid);
            }

            setValid(false);

            fileInput.addEventListener('change', function () {
                setValid(false);
                if (!fileInput.files.length) {
                    result.classList.add('hidden');
                    return;
                }
                if (!/\.atgl$/i.test(fileInput.files[0].name)) {
                    show(false, 'Choose an .atgl licence file.');
                    return;
                }

                var body = new FormData();
                body.append('license_file', fileInput.files[0]);
                show(true, 'Checking the licence file...');

                fetch(@json(route('install.license.preview')), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('#installerForm [name="_token"]').value
                    },
                    body: body
                })
                    .then(function (response) {
                        // An expired session (419) is answered with a redirect to an HTML page, not JSON.
                        if (response.redirected || (response.headers.get('Content-Type') || '').indexOf('application/json') === -1) {
                            throw new Error('session');
                        }
                        return response.json();
                    })
                    .then(function (data) {
                        if (!data.ok) {
                            show(false, data.message || 'The licence file is not valid.');
                            return;
                        }
                        details.querySelectorAll('[data-field]').forEach(function (el) {
                            el.textContent = data.license[el.getAttribute('data-field')] || '-';
                        });
                        show(true, 'Licence file is valid. Check the details, then install.');
                        setValid(true);
                    })
                    .catch(function (error) {
                        show(false, error && error.message === 'session'
                            ? 'Your session expired. Reload this page and choose the licence file again.'
                            : 'Could not check the licence file. Try again.');
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