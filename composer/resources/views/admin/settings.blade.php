@extends('app')

@section('title', 'Site Setting - AtGlance')

@section('dashboard-content')
<div style="padding:40px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h1 style="font-size:28px; color:#111827;">Site Setting</h1>
        <a href="{{ route('admin.dashboard') }}" style="text-decoration:none; color:#111827;">← Back to Dashboard</a>
    </div>

    @if(session('success'))
        <div style="padding:12px; border-radius:8px; background:#dcfce7; color:#166534; margin-bottom:16px;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="padding:12px; border-radius:8px; background:#fee2e2; color:#991b1b; margin-bottom:16px;">
            <ul style="margin-left:16px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $activeTab = request('tab', 'info');
    @endphp

    <div style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px; border-bottom:2px solid #b3b3b3; padding-bottom:10px;">
        <button type="button" class="settings-tab-btn" data-tab="info" style="padding:10px 14px; border-radius:8px; border:1px solid #b3b3b3; background:{{ $activeTab === 'info' ? '#7a7a7a' : '#ffffff' }}; color:{{ $activeTab === 'info' ? '#ffffff' : '#111827' }}; cursor:pointer; font-weight:600;">Info</button>
        <button type="button" class="settings-tab-btn" data-tab="site" style="padding:10px 14px; border-radius:8px; border:1px solid #b3b3b3; background:{{ $activeTab === 'site' ? '#7a7a7a' : '#ffffff' }}; color:{{ $activeTab === 'site' ? '#ffffff' : '#111827' }}; cursor:pointer; font-weight:600;">Site Configuration</button>
        <button type="button" class="settings-tab-btn" data-tab="email" style="padding:10px 14px; border-radius:8px; border:1px solid #b3b3b3; background:{{ $activeTab === 'email' ? '#7a7a7a' : '#ffffff' }}; color:{{ $activeTab === 'email' ? '#ffffff' : '#111827' }}; cursor:pointer; font-weight:600;">Email Configuration</button>
        <button type="button" class="settings-tab-btn" data-tab="sso" style="padding:10px 14px; border-radius:8px; border:1px solid #b3b3b3; background:{{ $activeTab === 'sso' ? '#7a7a7a' : '#ffffff' }}; color:{{ $activeTab === 'sso' ? '#ffffff' : '#111827' }}; cursor:pointer; font-weight:600;">SSO Configuration</button>
        <button type="button" class="settings-tab-btn" data-tab="s3" style="padding:10px 14px; border-radius:8px; border:1px solid #b3b3b3; background:{{ $activeTab === 's3' ? '#7a7a7a' : '#ffffff' }}; color:{{ $activeTab === 's3' ? '#ffffff' : '#111827' }}; cursor:pointer; font-weight:600;">S3 Configuration</button>
        <button type="button" class="settings-tab-btn" data-tab="migration" style="padding:10px 14px; border-radius:8px; border:1px solid #b3b3b3; background:{{ $activeTab === 'migration' ? '#7a7a7a' : '#ffffff' }}; color:{{ $activeTab === 'migration' ? '#ffffff' : '#111827' }}; cursor:pointer; font-weight:600;">Migration</button>
        <button type="button" class="settings-tab-btn" data-tab="backup-restore" style="padding:10px 14px; border-radius:8px; border:1px solid #b3b3b3; background:{{ $activeTab === 'backup-restore' ? '#7a7a7a' : '#ffffff' }}; color:{{ $activeTab === 'backup-restore' ? '#ffffff' : '#111827' }}; cursor:pointer; font-weight:600;">Backup &amp; Restore</button>
        <button type="button" class="settings-tab-btn" data-tab="plugins" style="padding:10px 14px; border-radius:8px; border:1px solid #b3b3b3; background:{{ $activeTab === 'plugins' ? '#7a7a7a' : '#ffffff' }}; color:{{ $activeTab === 'plugins' ? '#ffffff' : '#111827' }}; cursor:pointer; font-weight:600;">Plugins</button>
        <button type="button" class="settings-tab-btn" data-tab="crons" style="padding:10px 14px; border-radius:8px; border:1px solid #b3b3b3; background:{{ $activeTab === 'crons' ? '#7a7a7a' : '#ffffff' }}; color:{{ $activeTab === 'crons' ? '#ffffff' : '#111827' }}; cursor:pointer; font-weight:600;">Crons</button>
    </div>

    <div id="tab-info" class="settings-tab-content" style="display:{{ $activeTab === 'info' ? 'block' : 'none' }}; background:white; border:1px solid #b3b3b3; border-radius:10px; padding:22px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <h2 style="font-size:18px; margin-bottom:12px;">Info</h2>
        <div style="display:grid; grid-template-columns:1fr; gap:10px;">
            <div>
                <div style="font-size:13px; color:#4b5563; margin-bottom:4px;">Domain</div>
                <div style="font-size:14px; color:#111827; font-weight:600;">{{ $siteDomain }}</div>
            </div>
            <div>
                <div style="font-size:13px; color:#4b5563; margin-bottom:4px;">Organization Name</div>
                <div style="font-size:14px; color:#111827; font-weight:600;">{{ $organizationName }}</div>
            </div>
            <div>
                <div style="font-size:13px; color:#4b5563; margin-bottom:4px;">Local Storage Base URL</div>
                <div style="font-size:14px; color:#111827; font-weight:600;">{{ $localStorageBaseUrl }}</div>
            </div>
            <div>
                <div style="font-size:13px; color:#4b5563; margin-bottom:4px;">Domain Alias</div>
                <div style="font-size:14px; color:#111827; font-weight:600;">{{ !empty($siteDomainAlias) ? $siteDomainAlias : 'Not configured' }}</div>
            </div>
            <div>
                <div style="font-size:13px; color:#4b5563; margin-bottom:4px;">Alias IP Address</div>
                <div style="font-size:14px; color:#111827; font-weight:600;">{{ !empty($siteDomainAliasIp) ? $siteDomainAliasIp : 'Not configured' }}</div>
            </div>
            <div>
                <div style="font-size:13px; color:#4b5563; margin-bottom:4px;">HTTPS</div>
                <div style="font-size:14px; color:#111827; font-weight:600;">{{ ($siteHttpsEnabled ?? false) ? 'Enabled' : 'Disabled' }}</div>
            </div>
        </div>
    </div>

    <div id="tab-site" class="settings-tab-content" style="display:{{ $activeTab === 'site' ? 'block' : 'none' }}; background:white; border:1px solid #b3b3b3; border-radius:10px; padding:22px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <h2 style="font-size:18px; margin-bottom:12px;">Site Configuration</h2>
        <form method="POST" action="{{ route('admin.settings.site', ['tab' => 'site']) }}" enctype="multipart/form-data">
            @csrf
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
                {{--
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Logo Image Upload</label>
                    <input type="file" name="site_logo" accept=".jpg,.jpeg,.png,.webp,.svg" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; background:white;">
                    @if(!empty($siteLogoUrl))
                        <div style="margin-top:8px;">
                            <img src="{{ $siteLogoUrl }}" alt="Site Logo" style="max-height:48px; border-radius:6px; border:1px solid #e5e7eb; padding:4px; background:white;">
                        </div>
                    @endif
                </div>
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Logo URL (optional override)</label>
                    <input type="url" name="site_logo_url" value="{{ old('site_logo_url', $siteLogoUrlOverride ?? '') }}" placeholder="https://example.com/logo.png" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
                --}}
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Site Description</label>
                <textarea name="site_description" rows="3" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">{{ old('site_description', $siteDescription) }}</textarea>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Domain Alias</label>
                    <input type="text" name="site_domain_alias" value="{{ old('site_domain_alias', $siteDomainAlias ?? '') }}" placeholder="api.example.com" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Application IP (auto-fetched)</label>
                    <input type="text" value="{{ old('site_domain_alias_ip', $siteDomainAliasIp ?? '') }}" readonly style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; background:#f9fafb; color:#374151;">
                    <input type="hidden" name="site_domain_alias_ip" value="{{ old('site_domain_alias_ip', $siteDomainAliasIp ?? '') }}">
                </div>
            </div>
            <p style="font-size:12px; color:#6b7280; margin:-4px 0 12px 0;">Enter only the alias domain. The application IP is detected automatically and used for mapping.</p>

            <div style="margin-bottom:12px;">
                <input type="hidden" name="site_https_enabled" value="0">
                <label style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="site_https_enabled" value="1" {{ old('site_https_enabled', ($siteHttpsEnabled ?? false) ? '1' : '0') === '1' ? 'checked' : '' }}>
                    <span>Enable HTTPS</span>
                </label>
                <div style="font-size:12px; color:#6b7280; margin-top:6px;">Use this toggle to mark whether this alias should be served over HTTPS.</div>
            </div>

            <div style="margin-bottom:14px; padding:10px; border-radius:8px; background:#f9fafb; border:1px solid #e5e7eb; color:#374151; font-size:13px; line-height:1.5;">
                <strong>DNS Instructions for Super Admin</strong>
                <br>
                1. Create an <strong>A record</strong> for the alias host (for example, <strong>api</strong>) and point it to the configured IP address.
                <br>
                2. If you need root domain mapping, set an <strong>A record</strong> for <strong>@</strong> to the same IP.
                <br>
                3. If needed, add a <strong>CNAME record</strong> for <strong>www</strong> that points to the alias host.
                <br>
                4. Keep TTL low during rollout (for example, 300 seconds), then increase after verification.
                <br>
                5. When HTTPS is enabled, ensure a valid TLS certificate is installed for the alias domain before switching traffic.
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Site Tags (comma separated)</label>
                    <input type="text" name="site_tags" value="{{ old('site_tags', $siteTagsText) }}" placeholder="security, api-gateway, monitoring" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Features (one per line)</label>
                    <textarea name="site_features" rows="4" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">{{ old('site_features', $siteFeaturesText) }}</textarea>
                </div>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Metadata (JSON)</label>
                <textarea name="site_metadata" rows="6" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; font-family:'Courier New', monospace;">{{ old('site_metadata', $siteMetadataText) }}</textarea>
            </div>

            <button type="submit" style="background:#000000; color:white; border:none; border-radius:8px; padding:10px 14px; font-weight:600; cursor:pointer;">Save Site Settings</button>
        </form>
    </div>

    <div id="tab-s3" class="settings-tab-content" style="display:{{ $activeTab === 's3' ? 'block' : 'none' }}; background:white; border:1px solid #b3b3b3; border-radius:10px; padding:22px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <h2 style="font-size:18px; margin-bottom:8px;">S3 Configuration</h2>
        @php
            $s3EnabledState = (string) old('s3_enabled', ($useS3Storage ?? false) ? '1' : '0');
        @endphp
        <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">Local storage is default. Enable S3 only when you want backups/migration to target S3.</p>
        <div style="margin-bottom:14px; padding:10px; border-radius:8px; background:#f9fafb; border:1px solid #e5e7eb; color:#374151; font-size:13px; line-height:1.5;">
            <strong>Note:</strong>
            <br>
            By default, all system data is stored in local storage.
            <br>
            Application settings, .env values, and configuration images will continue to be loaded from local storage.
            <br>
            Only configuration files are eligible to be migrated to and loaded from S3 after migration.
            <br>
            Any newly created configuration files are initially saved in local storage. A scheduled CRON job can later move these files to S3.
        </div>
        <form method="POST" action="{{ route('admin.settings.s3', ['tab' => 's3']) }}">
            @csrf
            <input type="hidden" name="s3_enabled" value="0">
            <div style="margin-bottom:12px;">
                <label style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="s3_enabled" value="1" {{ $s3EnabledState === '1' ? 'checked' : '' }}>
                    <span>Enable S3 configuration</span>
                </label>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">AWS Access Key</label>
                    <input type="text" name="s3_access_key" value="{{ old('s3_access_key', $s3AccessKey) }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">AWS Secret Key {{ $hasS3Secret ? '(leave blank to keep existing)' : '' }}</label>
                    <input type="password" name="s3_secret_key" {{ $hasS3Secret ? '' : 'required' }} style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">AWS Region</label>
                    <input type="text" name="s3_region" value="{{ old('s3_region', $s3Region) }}" required placeholder="ap-south-1" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">AWS Bucket</label>
                    <input type="text" name="s3_bucket" value="{{ old('s3_bucket', $s3Bucket) }}" required placeholder="my-bucket" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
                <div>
                    <div style="font-size:12px; color:#4b5563; margin-bottom:4px;">Local Storage Base URL</div>
                    <div style="font-size:13px; color:#111827; font-weight:600;">{{ $localStorageBaseUrl }}</div>
                </div>
                <div>
                    <div style="font-size:12px; color:#4b5563; margin-bottom:4px;">S3 Storage Base URL</div>
                    <div style="font-size:13px; color:#111827; font-weight:600;">{{ $s3StorageBaseUrl !== '' ? $s3StorageBaseUrl : 'Will auto-generate after save' }}</div>
                </div>
            </div>

            <button type="submit" style="background:#000000; color:white; border:none; border-radius:8px; padding:10px 14px; font-weight:600; cursor:pointer;">Save S3 Settings</button>
        </form>
    </div>

    <div id="tab-email" class="settings-tab-content" style="display:{{ $activeTab === 'email' ? 'block' : 'none' }}; background:white; border:1px solid #b3b3b3; border-radius:10px; padding:22px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <h2 style="font-size:18px; margin-bottom:12px;">Email Configuration</h2>
        <form method="POST" action="{{ route('admin.settings.mail', ['tab' => 'email']) }}">
            @csrf
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">SMTP Host</label>
                    <input type="text" name="mail_host" value="{{ old('mail_host', $mailHost) }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">SMTP Port</label>
                    <input type="number" name="mail_port" value="{{ old('mail_port', $mailPort) }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">SMTP Username</label>
                    <input type="text" name="mail_username" value="{{ old('mail_username', $mailUsername) }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">SMTP Password {{ $hasMailPassword ? '(leave blank to keep existing)' : '' }}</label>
                    <input type="password" name="mail_password" {{ $hasMailPassword ? '' : 'required' }} style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Encryption</label>
                    <select name="mail_encryption" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                        <option value="" {{ old('mail_encryption', $mailEncryption) === '' ? 'selected' : '' }}>None</option>
                        <option value="tls" {{ old('mail_encryption', $mailEncryption) === 'tls' ? 'selected' : '' }}>TLS</option>
                        <option value="ssl" {{ old('mail_encryption', $mailEncryption) === 'ssl' ? 'selected' : '' }}>SSL</option>
                        <option value="starttls" {{ old('mail_encryption', $mailEncryption) === 'starttls' ? 'selected' : '' }}>STARTTLS</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">From Name</label>
                    <input type="text" name="mail_from_name" value="{{ old('mail_from_name', $mailFromName) }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">From Address</label>
                <input type="email" name="mail_from_address" value="{{ old('mail_from_address', $mailFromAddress) }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Alert Recipients (comma separated)</label>
                <textarea name="mail_recipients" rows="3" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">{{ old('mail_recipients', $mailRecipientsText) }}</textarea>
            </div>
            <button type="submit" style="background:#000000; color:white; border:none; border-radius:8px; padding:10px 14px; font-weight:600; cursor:pointer;">Save Email Settings</button>
        </form>
    </div>

    <div id="tab-migration" class="settings-tab-content" style="display:{{ $activeTab === 'migration' ? 'block' : 'none' }}; background:white; border:1px solid #b3b3b3; border-radius:10px; padding:22px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <h2 style="font-size:18px; margin-bottom:8px;">Migration</h2>
        <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">One-click migration supports Local to S3 and S3 to Local for tracked configuration files while preserving path structure.</p>
        <div style="margin-bottom:14px; padding:10px; border-radius:8px; background:#f9fafb; border:1px solid #e5e7eb; color:#374151; font-size:13px; line-height:1.5;">
            <strong>Note:</strong>
            <br>
            Migration is an on-demand mechanism used to move data between local storage and S3.
            <br>
            It enables immediate transfer of data either to S3 or back to local storage, as required.
            <br>
            The default behavior of the system remains unchanged - all data is stored and accessed from local storage unless explicitly migrated.
        </div>

        <form method="POST" action="{{ route('admin.settings.migration.config', ['tab' => 'migration']) }}" style="margin-bottom:14px;">
            @csrf
            <input type="hidden" name="migration_enabled" value="0">
            <label style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                <input type="checkbox" name="migration_enabled" value="1" {{ old('migration_enabled', ($migrationEnabled ?? false) ? '1' : '0') === '1' ? 'checked' : '' }}>
                <span>Enable Migration</span>
            </label>
            <button type="submit" style="background:#000000; color:white; border:none; border-radius:8px; padding:8px 12px; font-weight:600; cursor:pointer;">Save Migration Setting</button>
        </form>

        @if($migrationEnabled)
            <div style="border:1px solid #e5e7eb; border-radius:8px; padding:14px; margin-bottom:12px; background:#fafafa;">
                <form method="POST" action="{{ route('admin.settings.migration.analyze', ['tab' => 'migration']) }}" style="display:flex; flex-wrap:wrap; gap:10px; align-items:end; margin-bottom:10px;">
                    @csrf
                    <div>
                        <label style="display:block; font-size:12px; color:#4b5563; margin-bottom:6px;">Migration Direction</label>
                        <select name="direction" style="border:1px solid #d1d5db; border-radius:8px; padding:8px 10px;">
                            <option value="local_to_s3" {{ old('direction', $migrationDirection) === 'local_to_s3' ? 'selected' : '' }}>Local to S3</option>
                            <option value="s3_to_local" {{ old('direction', $migrationDirection) === 's3_to_local' ? 'selected' : '' }}>S3 to Local</option>
                        </select>
                    </div>
                    <label style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="keep_source" value="1" {{ old('keep_source', $migrationKeepSource ? '1' : '0') === '1' ? 'checked' : '' }}>
                        <span style="font-size:13px; color:#111827;">Keep source files after migration</span>
                    </label>
                    <button type="submit" style="background:#374151; color:white; border:none; border-radius:8px; padding:8px 12px; font-weight:600; cursor:pointer;">Analyze</button>
                </form>

                <form method="POST" action="{{ route('admin.settings.migration.start', ['tab' => 'migration']) }}" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                    @csrf
                    <input type="hidden" name="direction" value="{{ old('direction', $migrationDirection) }}">
                    <input type="hidden" name="keep_source" value="{{ old('keep_source', $migrationKeepSource ? '1' : '0') }}">
                    <button type="submit" style="background:#000000; color:white; border:none; border-radius:8px; padding:8px 12px; font-weight:600; cursor:pointer;">Start One-Click Migration</button>
                </form>
            </div>

            @if(!empty($migrationNotice))
                <div style="margin-bottom:10px; padding:10px; border-radius:8px; background:#eff6ff; color:#1e3a8a;">{{ $migrationNotice }}</div>
            @endif

            @if(is_array($migrationAnalysis))
                <div style="margin-bottom:10px; padding:10px; border-radius:8px; background:#f9fafb; border:1px solid #e5e7eb;">
                    <div style="font-weight:600; margin-bottom:6px;">Migration Analysis</div>
                    <div style="font-size:13px; color:#374151;">Pending files: {{ $migrationAnalysis['files_pending_migration'] ?? 0 }}</div>
                    <div style="font-size:13px; color:#374151;">Source files found: {{ $migrationAnalysis['source_files_found'] ?? 0 }}</div>
                    <div style="font-size:13px; color:#374151;">Missing source files: {{ $migrationAnalysis['missing_source_files'] ?? 0 }}</div>
                </div>
            @endif

            @if(is_array($migrationResult))
                <div style="padding:10px; border-radius:8px; background:#f0fdf4; border:1px solid #bbf7d0;">
                    <div style="font-weight:600; margin-bottom:6px;">Migration Result</div>
                    <div style="font-size:13px; color:#166534;">Migrated files: {{ $migrationResult['migrated_files'] ?? 0 }}</div>
                    <div style="font-size:13px; color:#166534;">Verified files: {{ $migrationResult['verified_files'] ?? 0 }}</div>
                    <div style="font-size:13px; color:#166534;">Progress: {{ $migrationResult['progress_percent'] ?? 0 }}%</div>
                </div>
            @endif
        @else
            <p style="color:#6b7280;">Migration is disabled. Enable it above to use one-click local to S3 or S3 to local migration.</p>
        @endif
    </div>

    <div id="tab-plugins" class="settings-tab-content" style="display:{{ $activeTab === 'plugins' ? 'block' : 'none' }}; background:white; border:1px solid #b3b3b3; border-radius:10px; padding:22px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <h2 style="font-size:18px; margin-bottom:8px;">Plugins</h2>
        <p style="color:#6b7280;">Coming Soon</p>
    </div>

    <div id="tab-backup-restore" class="settings-tab-content" style="display:{{ $activeTab === 'backup-restore' ? 'block' : 'none' }}; background:white; border:1px solid #b3b3b3; border-radius:10px; padding:22px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <h2 style="font-size:18px; margin-bottom:8px;">Backup &amp; Restore</h2>
        <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">Default mode is local-only. Enable S3 backup options only when needed.</p>
        <div style="margin-bottom:14px; padding:10px; border-radius:8px; background:#f9fafb; border:1px solid #e5e7eb; color:#374151; font-size:13px; line-height:1.5;">
            <strong>Note:</strong>
            <br>
            By default, all system data is stored in local storage.
            <br>
            Application settings, .env values, and configuration images will continue to be loaded from local storage.
            <br>
            Only configuration files are eligible to be migrated to and loaded from S3 after migration.
            <br>
            Any newly created configuration files are initially saved in local storage. A scheduled CRON job can later move these files to S3.
        </div>

        @php
            $backupRestoreEnabledState = old('backup_restore_enabled', ($backupRestoreEnabled ?? false) ? '1' : '0') === '1';
            $backupConfigToS3State = old('backup_config_to_s3', ($backupConfigToS3 ?? false) ? '1' : '0') === '1';
            $backupPortalToS3State = old('backup_portal_to_s3', ($backupPortalToS3 ?? false) ? '1' : '0') === '1';
            $backupConfigCronValue = old('backup_config_cron', $backupConfigCron ?? '');
            $backupPortalCronValue = old('backup_portal_cron', $backupPortalCron ?? '');
        @endphp

        <form method="POST" action="{{ route('admin.settings.backup-restore', ['tab' => 'backup-restore']) }}">
            @csrf
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Enable Backup and Restore</label>
                    <select name="backup_restore_enabled" id="backup-restore-enabled" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                        <option value="0" {{ $backupRestoreEnabledState ? '' : 'selected' }}>No</option>
                        <option value="1" {{ $backupRestoreEnabledState ? 'selected' : '' }}>Yes</option>
                    </select>
                </div>
            </div>

            <div id="backup-restore-details" style="display:{{ $backupRestoreEnabledState ? 'block' : 'none' }};">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Save configuration files backup on S3 (Yes/No)</label>
                        <select name="backup_config_to_s3" id="backup-config-to-s3" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                            <option value="0" {{ $backupConfigToS3State ? '' : 'selected' }}>No</option>
                            <option value="1" {{ $backupConfigToS3State ? 'selected' : '' }}>Yes</option>
                        </select>
                    </div>
                    <div id="backup-config-cron-wrap" style="display:{{ $backupConfigToS3State ? 'block' : 'none' }};">
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Configuration backup cron</label>
                        <select name="backup_config_cron" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                            <option value="" {{ $backupConfigCronValue === '' ? 'selected' : '' }}>Select frequency</option>
                            <option value="hourly" {{ $backupConfigCronValue === 'hourly' ? 'selected' : '' }}>Every hour</option>
                            <option value="every_six_hours" {{ $backupConfigCronValue === 'every_six_hours' ? 'selected' : '' }}>Every six hours</option>
                            <option value="every_twelve_hours" {{ $backupConfigCronValue === 'every_twelve_hours' ? 'selected' : '' }}>Every 12 hours</option>
                            <option value="daily" {{ $backupConfigCronValue === 'daily' ? 'selected' : '' }}>Every day</option>
                            <option value="weekly" {{ $backupConfigCronValue === 'weekly' ? 'selected' : '' }}>Every week</option>
                            <option value="monthly" {{ $backupConfigCronValue === 'monthly' ? 'selected' : '' }}>Every month</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Save portal regular (.env file, settings, DB) backup on S3 (Yes/No)</label>
                        <select name="backup_portal_to_s3" id="backup-portal-to-s3" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                            <option value="0" {{ $backupPortalToS3State ? '' : 'selected' }}>No</option>
                            <option value="1" {{ $backupPortalToS3State ? 'selected' : '' }}>Yes</option>
                        </select>
                    </div>
                    <div id="backup-portal-cron-wrap" style="display:{{ $backupPortalToS3State ? 'block' : 'none' }};">
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Portal backup cron</label>
                        <select name="backup_portal_cron" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                            <option value="" {{ $backupPortalCronValue === '' ? 'selected' : '' }}>Select frequency</option>
                            <option value="daily" {{ $backupPortalCronValue === 'daily' ? 'selected' : '' }}>Every day</option>
                            <option value="weekly" {{ $backupPortalCronValue === 'weekly' ? 'selected' : '' }}>Every week</option>
                            <option value="monthly" {{ $backupPortalCronValue === 'monthly' ? 'selected' : '' }}>Every month</option>
                        </select>
                    </div>
                </div>
            </div>

            <button type="submit" style="background:#000000; color:white; border:none; border-radius:8px; padding:10px 14px; font-weight:600; cursor:pointer;">Save Backup Settings</button>
        </form>

        <div style="margin-top:14px; padding:10px; border-radius:8px; background:#f9fafb; border:1px solid #e5e7eb; color:#374151;">
            <strong>Restore:</strong> Coming Soon
        </div>
    </div>

    <div id="tab-crons" class="settings-tab-content" style="display:{{ $activeTab === 'crons' ? 'block' : 'none' }}; background:white; border:1px solid #b3b3b3; border-radius:10px; padding:22px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <h2 style="font-size:18px; margin-bottom:8px;">Crons</h2>
        <p style="font-size:13px; color:#6b7280; margin-bottom:10px;">Shows only what is currently configured.</p>

        @if(!empty($configuredCronSetups ?? []))
            <div style="display:grid; grid-template-columns:1fr; gap:10px;">
                @foreach(($configuredCronSetups ?? []) as $cronSetup)
                    <div style="padding:10px; border:1px solid #e5e7eb; border-radius:8px; background:#f9fafb;">
                        <div style="font-weight:600; color:#111827;">{{ $cronSetup['name'] ?? 'Cron' }}</div>
                        <div style="font-size:13px; color:#374151;">{{ $cronSetup['frequency'] ?? 'Not configured' }}</div>
                        <div style="font-size:13px; color:#374151; margin-top:4px;">CRON: <span style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;">{{ $cronSetup['expression'] ?? '* * * * * *' }}</span></div>
                    </div>
                @endforeach
            </div>
        @else
            <p style="color:#6b7280;">No cron schedules configured yet.</p>
        @endif
    </div>

    <div id="tab-sso" class="settings-tab-content" style="display:{{ $activeTab === 'sso' ? 'block' : 'none' }}; background:white; border:1px solid #b3b3b3; border-radius:10px; padding:22px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <h2 style="font-size:18px; margin-bottom:12px;">SSO Configuration</h2>
        <form method="POST" action="{{ route('admin.settings.sso', ['tab' => 'sso']) }}">
            @csrf
            <div style="margin-bottom:12px;">
                <label style="display:flex; align-items:center; gap:8px;">
                    <input id="sso-enabled-toggle" type="checkbox" name="sso_enabled" value="1" {{ old('sso_enabled', $ssoEnabled) ? 'checked' : '' }}>
                    <span>Enable SSO login</span>
                </label>
            </div>

            <div id="disable-email-registration-wrap" style="margin-bottom:12px; {{ old('sso_enabled', $ssoEnabled) ? '' : 'display:none;' }}">
                <label style="display:flex; align-items:center; gap:8px;">
                    <input id="disable-email-registration-toggle" type="checkbox" name="disable_email_registration" value="1" {{ old('disable_email_registration', $disableEmailRegistration ?? false) ? 'checked' : '' }}>
                    <span>Disable user registration with email/password</span>
                </label>
                <p style="margin:6px 0 0 26px; font-size:12px; color:#6b7280;">When enabled, registration and forgot-password by email are disabled on the login page.</p>
            </div>

            @php
                $selectedProviders = old('sso_enabled_providers', $ssoEnabledProviders ?? []);
                $providerUrls = old('sso_provider_urls', $ssoProviderUrls ?? []);
                $providerClientIds = old('sso_provider_client_ids', $ssoProviderClientIds ?? []);
                $hasProviderClientSecrets = $hasSsoProviderClientSecrets ?? [];
                $providerTenantIds = old('sso_provider_tenant_ids', $ssoProviderTenantIds ?? []);
            @endphp

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:8px; font-weight:600;">Enable Providers</label>
                <div style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:10px;">
                    @foreach(($ssoProviderOptions ?? []) as $providerKey => $providerMeta)
                        <label style="display:flex; align-items:center; gap:8px; border:1px solid #e5e7eb; border-radius:8px; padding:8px 10px; cursor:pointer;">
                            <input class="sso-provider-checkbox" type="checkbox" name="sso_enabled_providers[]" value="{{ $providerKey }}" {{ in_array($providerKey, $selectedProviders, true) ? 'checked' : '' }}>
                            <span>{{ $providerMeta['label'] ?? ucfirst($providerKey) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('sso_enabled_providers')
                    <div style="margin-top:8px; font-size:12px; color:#b91c1c;">{{ $message }}</div>
                @enderror
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:8px; font-weight:600;">Provider Login URLs</label>
                <p style="font-size:12px; color:#6b7280; margin-bottom:10px;">Only selected providers are shown below. Configure URL and Client ID/Secret per provider. Tenant/Domain is optional and not required for GitHub.</p>
                <p style="font-size:12px; color:#374151; margin-bottom:10px;">Configure callback/redirect URL on your identity platform, not here. App callback format: <strong>{{ url('/auth/sso/{provider}/callback') }}</strong></p>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    @foreach(($ssoProviderOptions ?? []) as $providerKey => $providerMeta)
                        <div class="provider-url-group" data-provider="{{ $providerKey }}" style="display:{{ in_array($providerKey, $selectedProviders, true) ? 'block' : 'none' }};">
                            <div style="font-size:12px; color:#111827; margin-bottom:6px;">Callback URL for {{ $providerMeta['label'] ?? ucfirst($providerKey) }}: <strong>{{ url('/auth/sso/' . $providerKey . '/callback') }}</strong></div>
                            <label style="display:block; font-size:12px; color:#4b5563; margin-bottom:5px;">{{ $providerMeta['label'] ?? ucfirst($providerKey) }} URL</label>
                            <input
                                class="provider-config-input"
                                type="url"
                                name="sso_provider_urls[{{ $providerKey }}]"
                                value="{{ $providerUrls[$providerKey] ?? '' }}"
                                placeholder="https://..."
                                style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;"
                            >

                            <label style="display:block; font-size:12px; color:#4b5563; margin:8px 0 5px;">{{ $providerMeta['label'] ?? ucfirst($providerKey) }} Client ID</label>
                            <input
                                class="provider-config-input"
                                type="text"
                                name="sso_provider_client_ids[{{ $providerKey }}]"
                                value="{{ $providerClientIds[$providerKey] ?? '' }}"
                                placeholder="Client ID"
                                style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;"
                            >

                            <label style="display:block; font-size:12px; color:#4b5563; margin:8px 0 5px;">{{ $providerMeta['label'] ?? ucfirst($providerKey) }} Client Secret {{ !empty($hasProviderClientSecrets[$providerKey] ?? false) ? '(leave blank to keep existing)' : '' }}</label>
                            <input
                                class="provider-config-input"
                                type="password"
                                name="sso_provider_client_secrets[{{ $providerKey }}]"
                                placeholder="Client Secret"
                                style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;"
                            >

                            @if($providerKey !== 'github')
                                <label style="display:block; font-size:12px; color:#4b5563; margin:8px 0 5px;">{{ $providerMeta['label'] ?? ucfirst($providerKey) }} Tenant ID / Domain (optional)</label>
                                <input
                                    class="provider-config-input"
                                    type="text"
                                    name="sso_provider_tenant_ids[{{ $providerKey }}]"
                                    value="{{ $providerTenantIds[$providerKey] ?? '' }}"
                                    placeholder="Tenant ID or Domain"
                                    style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;"
                                >
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <button type="submit" style="background:#000000; color:white; border:none; border-radius:8px; padding:10px 14px; font-weight:600; cursor:pointer;">Save SSO Settings</button>
        </form>
    </div>
</div>

<script>
(function () {
    const tabButtons = document.querySelectorAll('.settings-tab-btn');
    const tabContents = document.querySelectorAll('.settings-tab-content');
    const providerCheckboxes = document.querySelectorAll('.sso-provider-checkbox');
    const providerGroups = document.querySelectorAll('.provider-url-group');
    const backupRestoreEnabled = document.getElementById('backup-restore-enabled');
    const backupRestoreDetails = document.getElementById('backup-restore-details');
    const backupConfigToS3 = document.getElementById('backup-config-to-s3');
    const backupPortalToS3 = document.getElementById('backup-portal-to-s3');
    const backupConfigCronWrap = document.getElementById('backup-config-cron-wrap');
    const backupPortalCronWrap = document.getElementById('backup-portal-cron-wrap');
    const ssoEnabledToggle = document.getElementById('sso-enabled-toggle');
    const disableEmailRegistrationWrap = document.getElementById('disable-email-registration-wrap');
    const disableEmailRegistrationToggle = document.getElementById('disable-email-registration-toggle');

    function activateTab(tabName) {
        tabContents.forEach((content) => {
            content.style.display = content.id === `tab-${tabName}` ? 'block' : 'none';
        });

        tabButtons.forEach((button) => {
            if (button.dataset.tab === tabName) {
                button.style.background = '#7a7a7a';
                button.style.color = '#ffffff';
            } else {
                button.style.background = '#ffffff';
                button.style.color = '#111827';
            }
        });

        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url.toString());
    }

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => activateTab(button.dataset.tab));
    });

    function updateProviderConfigVisibility() {
        const selectedProviders = new Set(
            Array.from(providerCheckboxes)
                .filter((checkbox) => checkbox.checked)
                .map((checkbox) => checkbox.value)
        );

        providerGroups.forEach((group) => {
            const provider = group.dataset.provider;
            const inputs = group.querySelectorAll('.provider-config-input');
            const isSelected = selectedProviders.has(provider);

            group.style.display = isSelected ? 'block' : 'none';

            inputs.forEach((input) => {
                if (isSelected) {
                    input.removeAttribute('disabled');
                } else {
                    input.setAttribute('disabled', 'disabled');
                }
            });
        });
    }

    providerCheckboxes.forEach((checkbox) => {
        checkbox.addEventListener('change', updateProviderConfigVisibility);
    });

    function updateSsoDependentVisibility() {
        if (!ssoEnabledToggle || !disableEmailRegistrationWrap || !disableEmailRegistrationToggle) {
            return;
        }

        const ssoEnabled = ssoEnabledToggle.checked;
        disableEmailRegistrationWrap.style.display = ssoEnabled ? 'block' : 'none';
        disableEmailRegistrationToggle.disabled = !ssoEnabled;

        if (!ssoEnabled) {
            disableEmailRegistrationToggle.checked = false;
        }
    }

    if (ssoEnabledToggle) {
        ssoEnabledToggle.addEventListener('change', updateSsoDependentVisibility);
    }

    function updateBackupVisibility() {
        if (backupRestoreEnabled && backupRestoreDetails) {
            const enabled = backupRestoreEnabled.value === '1';
            backupRestoreDetails.style.display = enabled ? 'block' : 'none';
        }

        if (backupConfigToS3 && backupConfigCronWrap) {
            backupConfigCronWrap.style.display = backupConfigToS3.value === '1' ? 'block' : 'none';
        }

        if (backupPortalToS3 && backupPortalCronWrap) {
            backupPortalCronWrap.style.display = backupPortalToS3.value === '1' ? 'block' : 'none';
        }
    }

    if (backupRestoreEnabled) {
        backupRestoreEnabled.addEventListener('change', updateBackupVisibility);
    }

    if (backupConfigToS3) {
        backupConfigToS3.addEventListener('change', updateBackupVisibility);
    }

    if (backupPortalToS3) {
        backupPortalToS3.addEventListener('change', updateBackupVisibility);
    }

    updateProviderConfigVisibility();
    updateSsoDependentVisibility();
    updateBackupVisibility();
})();
</script>
@endsection
