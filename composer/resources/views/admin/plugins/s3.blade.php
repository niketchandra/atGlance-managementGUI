{{-- Details panel of the S3 Storage plugin. --}}
@php
    $s3PluginOn = \App\Support\S3Settings::pluginEnabled();
    $s3InUse = \App\Support\S3Settings::enabled();
    $s3Creds = $s3InUse ? \App\Support\S3Settings::credentials() : [];
@endphp
<p style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 10px; line-height: 1.6;">
    Stores configuration files and scheduled backups in an Amazon S3 bucket. Everything stays in local storage until S3 is set up;
    existing files move with the Migration tab. Settings, .env and images always stay local.
</p>

@if(!$s3PluginOn)
    <p style="font-size: 13px; color: var(--ag-muted);">Enable the plugin, then enter the bucket and access keys on the S3 Configuration tab.</p>
@elseif(!$s3InUse)
    <div class="ag-alert ag-alert--warning" style="margin-bottom: 12px;">
        <span>Not in use yet: enter the bucket and access keys on the <a href="{{ route('admin.settings', ['tab' => 's3']) }}" style="color: var(--ag-teal); text-decoration: underline;">S3 Configuration</a> tab.</span>
    </div>
@else
    <div style="display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px 14px; font-size: 13px; margin-bottom: 12px;">
        <span style="color: var(--ag-muted);">Status</span><span><span class="ag-badge ag-badge--success">In use</span></span>
        <span style="color: var(--ag-muted);">Bucket</span><span>{{ $s3Creds['bucket'] ?? '' }}</span>
        <span style="color: var(--ag-muted);">Region</span><span>{{ $s3Creds['region'] ?? '' }}</span>
    </div>
    <p style="font-size: 12px; color: var(--ag-muted); line-height: 1.6;">
        Disabling S3 Storage is only possible once no files are left in S3: move them back with the Migration tab first.
    </p>
@endif
