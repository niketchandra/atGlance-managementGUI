@extends('app')

@section('title', 'Activity - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
        <div>
            <h1 style="font-size: 30px; color: var(--ag-text);"><i class="fas fa-history"></i> Your activity</h1>
            <p style="color: var(--ag-muted); margin-top: 6px;">Sign-ins, account changes, API keys and backups on your account, newest first.</p>
        </div>
        <a href="{{ route('profile') }}" style="color: var(--ag-text); font-weight: 600; text-decoration: none;">&larr; Back to profile</a>
    </div>

    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
        @php
            $activityFilters = ['' => 'All'] + \App\Support\ActivityFeed::TYPES;
        @endphp
        @foreach($activityFilters as $filterKey => $filterLabel)
            @php
                $filterActive = ($activityType ?? '') === $filterKey;
            @endphp
            <a href="{{ route('profile.activity', $filterKey !== '' ? ['type' => $filterKey] : []) }}"
               style="padding: 8px 14px; border-radius: 999px; border: 1px solid #e6e9ee; text-decoration: none; font-size: 13px; font-weight: 600; background: {{ $filterActive ? '#111111' : '#ffffff' }}; color: {{ $filterActive ? '#ffffff' : '#333333' }};">{{ $filterLabel }}</a>
        @endforeach
    </div>

    <div class="ag-card" style="padding: 24px;">
        @include('partials.activity-list', [
            'activityItems' => $activityPage->getCollection(),
            'activityEmpty' => $activityType ? 'No activity of this type yet.' : 'No activity yet.',
        ])

        @if($activityPage->hasPages())
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; font-size: 13px; color: var(--ag-muted);">
                <span>Page {{ $activityPage->currentPage() }} of {{ $activityPage->lastPage() }}</span>
                <span style="display: flex; gap: 8px;">
                    @if($activityPage->previousPageUrl())
                        <a href="{{ $activityPage->previousPageUrl() }}" style="color: var(--ag-text); font-weight: 600;">&larr; Newer</a>
                    @endif
                    @if($activityPage->nextPageUrl())
                        <a href="{{ $activityPage->nextPageUrl() }}" style="color: var(--ag-text); font-weight: 600;">Older &rarr;</a>
                    @endif
                </span>
            </div>
        @endif
    </div>
</div>
@endsection
