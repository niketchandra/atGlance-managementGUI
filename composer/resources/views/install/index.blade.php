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
    <style>body { font-family: 'Sora', ui-sans-serif, system-ui; }</style>
</head>
<body class="min-h-screen overflow-x-hidden bg-slate-950">
    <div class="fixed inset-0 -z-10 bg-gradient-to-br from-slate-950 via-teal-950 to-slate-900"></div>
    <div class="mx-auto flex min-h-screen w-full max-w-5xl items-center justify-center px-5 py-8 sm:px-8">
        <div class="w-full max-w-3xl rounded-3xl border border-white/20 bg-white/90 p-6 shadow-2xl backdrop-blur-xl sm:p-10">
            <div class="mb-6 flex items-center gap-4">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500 to-cyan-500 text-lg font-extrabold text-white">AG</div>
                <h1 class="text-2xl font-extrabold text-slate-900 sm:text-3xl">AtGlance Project Installer</h1>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-relaxed text-slate-700 sm:p-5">
                <span class="font-semibold text-slate-900">AtGlance</span> is a configuration files backup platform for Linux administrators.
            </div>
            <p class="mt-5 text-sm font-medium text-slate-600 sm:text-base">Set up the application for your organization.</p>
            @if ($errors->any())
                <div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"><ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <form action="{{ route('install.run') }}" method="POST" class="mt-6 space-y-5">
                @csrf
                <div>
                    <label for="organization_name" class="block text-sm font-semibold text-slate-800">Organization Name</label>
                    <input id="organization_name" name="organization_name" type="text" required value="{{ old('organization_name', 'Default Organization') }}" placeholder="Acme Corp" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                </div>
                <div>
                    <label for="app_url" class="block text-sm font-semibold text-slate-800">Domain or IP</label>
                    <input id="app_url" name="app_url" type="text" required value="{{ old('app_url', $defaultDomain ?? request()->getHttpHost()) }}" placeholder="example.com or 192.168.1.50:8000" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                    <p class="mt-2 text-xs text-slate-500">Do not include http:// or https://</p>
                </div>
                <div>
                    <label for="use_https" class="block text-sm font-semibold text-slate-800">Use HTTPS</label>
                    <select id="use_https" name="use_https" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                        <option value="1" {{ old('use_https', '1') === '1' ? 'selected' : '' }}>Yes</option>
                        <option value="0" {{ old('use_https') === '0' ? 'selected' : '' }}>No</option>
                    </select>
                </div>
                <div class="border-t border-slate-200 pt-5">
                    <h2 class="text-lg font-bold text-slate-900">Administrator account</h2>
                    <p class="mt-1 text-sm text-slate-500">Create the account you will use to manage this organization.</p>
                </div>
                <div>
                    <label for="admin_name" class="block text-sm font-semibold text-slate-800">Administrator name</label>
                    <input id="admin_name" name="admin_name" type="text" required value="{{ old('admin_name') }}" placeholder="Jane Smith" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                </div>
                <div>
                    <label for="admin_email" class="block text-sm font-semibold text-slate-800">Administrator email</label>
                    <input id="admin_email" name="admin_email" type="email" required value="{{ old('admin_email') }}" placeholder="admin@example.com" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="admin_password" class="block text-sm font-semibold text-slate-800">Administrator password</label>
                        <input id="admin_password" name="admin_password" type="password" minlength="8" required class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                    </div>
                    <div>
                        <label for="admin_password_confirmation" class="block text-sm font-semibold text-slate-800">Confirm password</label>
                        <input id="admin_password_confirmation" name="admin_password_confirmation" type="password" minlength="8" required class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                    </div>
                </div>
                <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-emerald-600 to-cyan-600 px-4 py-3 text-sm font-bold uppercase tracking-wider text-white">Next - Install Application</button>
            </form>
        </div>
    </div>
</body>
</html>