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
                    src="{{ asset('branding/atglance-logo.png') }}"
                    alt="AtGlance logo"
                    class="h-14 w-auto object-contain sm:h-16"
                >

                <h2 class="mt-5 text-xl font-extrabold text-slate-900 sm:text-2xl">AtGlance Platform</h2>
                <p class="mt-3 text-sm leading-relaxed text-slate-700 sm:text-base">
                    AtGlance protects Linux configuration files and helps your team recover quickly with reliable backups and centralized monitoring.
                </p>

                <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-800">Public Links</h3>
                    <ul class="mt-3 space-y-2 text-sm text-slate-700">
                        <li><a href="https://theatglance.com" target="_blank" class="font-medium text-black hover:underline">Website</a></li>
                        <li><a href="https://theatglance.com/docs" target="_blank" class="font-medium text-black hover:underline">Documentation</a></li>
                        <li><a href="https://theatglance.com/contact" target="_blank" class="font-medium text-black hover:underline">Contact Page</a></li>
                    </ul>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-800">Contact</h3>
                    <p class="mt-2 text-sm text-slate-700">
                        <a href="mailto:info@theatglance.com" class="font-medium text-black hover:underline">info@theatglance.com</a>
                    </p>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-800">Support Form</h3>
                    <form class="mt-3 space-y-3">
                        <div>
                            <label for="support_email" class="block text-xs font-semibold text-slate-700">Email</label>
                            <input
                                id="support_email"
                                type="email"
                                placeholder="you@company.com"
                                class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-black focus:ring-2 focus:ring-slate-200"
                            >
                        </div>
                        <div>
                            <label for="support_issue" class="block text-xs font-semibold text-slate-700">Issue</label>
                            <textarea
                                id="support_issue"
                                rows="4"
                                placeholder="Describe your issue"
                                class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-black focus:ring-2 focus:ring-slate-200"
                            ></textarea>
                        </div>
                        <button type="button" class="w-full rounded-lg bg-black px-3 py-2 text-sm font-semibold text-white">Submit Support Request</button>
                    </form>
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