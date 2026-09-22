<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Complete</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 px-5 py-8 text-slate-900">
    <main class="mx-auto max-w-4xl rounded-3xl bg-white p-6 shadow-2xl sm:p-10">
        <h1 class="text-2xl font-extrabold text-emerald-700 sm:text-3xl">Installation Completed</h1>
        <p class="mt-3 text-sm text-slate-600 sm:text-base">Your AtGlance application is ready. Sign in with the administrator account created by the database seeder.</p>
        <div class="mt-6 grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
            <div class="rounded-xl border border-slate-200 p-4"><strong>Organization</strong><p class="mt-1">{{ $installation['organization_name'] ?? 'Default Organization' }}</p></div>
            <div class="rounded-xl border border-slate-200 p-4"><strong>Domain</strong><p class="mt-1">{{ $installation['app_domain'] ?? '' }}</p></div>
            <div class="rounded-xl border border-slate-200 p-4"><strong>HTTPS Enabled</strong><p class="mt-1">{{ ($installation['https_enabled'] ?? false) ? 'Yes' : 'No' }}</p></div>
            <div class="rounded-xl border border-slate-200 p-4"><strong>Administrator</strong><p class="mt-1">{{ $installation['admin_email'] ?? 'Created by the seeder' }}</p></div>
        </div>
        <a href="{{ route('home') }}" class="mt-6 inline-flex rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold uppercase tracking-wider text-white">Go to Login</a>
    </main>
</body>
</html>