@extends('app')

@section('title', $featureName . ' - ' . ($brandName ?? 'AtGlance'))

@section('dashboard-content')
<div class="ag-card" style="max-width: 640px; margin: 40px auto; padding: 32px; text-align: center;">
    <div style="font-size: 32px; color: var(--ag-muted); margin-bottom: 12px;"><i class="fas fa-lock"></i></div>
    <h1 style="font-size: 20px; margin-bottom: 8px;">{{ $featureName }} is not included in your license</h1>
    <p style="font-size: 14px; color: var(--ag-subtle); margin-bottom: 20px;">
        {{ $message }} Your data for this feature is kept and becomes available again with a licence that includes it.
    </p>
    <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
        <a class="ag-btn" href="{{ \App\Support\License::portalUrl() }}" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-dots"></i> Upgrade at atglance.live</a>
        @auth
            <a class="ag-btn ag-btn--ghost" href="{{ route('admin.settings', ['tab' => 'licence']) }}"><i class="fas fa-key"></i> Licence settings</a>
        @endauth
    </div>
</div>
@endsection
