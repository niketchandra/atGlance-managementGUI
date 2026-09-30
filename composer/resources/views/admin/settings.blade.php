@extends('app')

@section('title', 'Site Setting - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="font-size: 30px; color: var(--ag-text);">Site Setting</h1>
        <a href="{{ route('admin.dashboard') }}" style="text-decoration: none; color: var(--ag-text);">← Back to Dashboard</a>
    </div>

    @if(session('success'))
        <div style="padding: 12px; border-radius: 12px; background: var(--ag-success-soft); color: var(--ag-success); margin-bottom: 16px;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="padding: 12px; border-radius: 12px; background: var(--ag-danger-soft); color: var(--ag-danger); margin-bottom: 16px;">
            <ul style="margin-left: 16px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $activeTab = request('tab', 'info');
    @endphp

    <div class="ag-side-layout">
    <nav class="ag-tabs ag-tabs--vertical" aria-label="Settings sections">
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 'info' ? 'active' : '' }}" data-tab="info"><i class="fas fa-circle-info"></i> Info</button>
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 'site' ? 'active' : '' }}" data-tab="site"><i class="fas fa-globe"></i> Site Configuration</button>
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 'email' ? 'active' : '' }}" data-tab="email"><i class="fas fa-envelope"></i> Email Configuration</button>
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 'sso' ? 'active' : '' }}" data-tab="sso"><i class="fas fa-right-to-bracket"></i> SSO Configuration</button>
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 's3' ? 'active' : '' }}" data-tab="s3"><i class="fas fa-cloud"></i> S3 Configuration</button>
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 'migration' ? 'active' : '' }}" data-tab="migration"><i class="fas fa-right-left"></i> Migration</button>
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 'backup-restore' ? 'active' : '' }}" data-tab="backup-restore"><i class="fas fa-clock-rotate-left"></i> Backup &amp; Restore</button>
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 'plugins' ? 'active' : '' }}" data-tab="plugins"><i class="fas fa-puzzle-piece"></i> Plugins</button>
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 'crons' ? 'active' : '' }}" data-tab="crons"><i class="fas fa-stopwatch"></i> Crons</button>
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 'ai-connect' ? 'active' : '' }}" data-tab="ai-connect"><i class="fas fa-robot"></i> AI Connect</button>
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 'notification' ? 'active' : '' }}" data-tab="notification"><i class="fas fa-bell"></i> Notification</button>
        <button type="button" class="ag-tab settings-tab-btn {{ $activeTab === 'licence' ? 'active' : '' }}" data-tab="licence"><i class="fas fa-key"></i> Licence</button>
    </nav>
    <div class="ag-side-content">

    <div id="tab-info" class="settings-tab-content ag-card" style="display:{{ $activeTab === 'info' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 12px;">Info</h2>
        <div style="display: grid; grid-template-columns: 1fr; gap: 10px;">
            <div>
                <div style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 4px;">Domain</div>
                <div style="font-size: 14px; color: var(--ag-text); font-weight: 600;">{{ $siteDomain }}</div>
            </div>
            <div>
                <div style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 4px;">Organization Name</div>
                <div style="font-size: 14px; color: var(--ag-text); font-weight: 600;">{{ $organizationName }}</div>
            </div>
            <div>
                <div style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 4px;">Local Storage Base URL</div>
                <div style="font-size: 14px; color: var(--ag-text); font-weight: 600;">{{ $localStorageBaseUrl }}</div>
            </div>
            <div>
                <div style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 4px;">Access URL</div>
                <div style="font-size: 14px; color: var(--ag-text); font-weight: 600;">{{ $domainView['plugin_enabled'] && $domainView['access_url'] !== '' ? $domainView['access_url'] : 'Not configured' }}</div>
            </div>
            <div>
                <div style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 4px;">Server IP</div>
                <div style="font-size: 14px; color: var(--ag-text); font-weight: 600;">{{ $domainView['server_address'] !== '' ? $domainView['server_address'] : 'Not configured' }}</div>
            </div>
            <div>
                <div style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 4px;">HTTPS</div>
                <div style="font-size: 14px; color: var(--ag-text); font-weight: 600;">{{ ['off' => 'Off', 'builtin' => 'Automatic certificate', 'custom' => 'Own certificate', 'platform' => 'Handled by platform'][$domainView['https_mode']] }}</div>
            </div>
        </div>
    </div>

    <div id="tab-site" class="settings-tab-content ag-card" style="display:{{ $activeTab === 'site' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 12px;">Site Configuration</h2>
        @php
            $dv = $domainView;
            $dvCanEdit = (int) auth()->user()->rbac_id === 100;
            $dvLocked = !$dv['plugin_enabled'] || !$dvCanEdit;
            $dvMode = old('https_mode', $dv['https_mode']);
            // A selected option that is also disabled is not submitted; fall back to Off.
            if (in_array($dvMode, ['builtin', 'custom'], true) && !$dv['builtin_seen']) {
                $dvMode = 'off';
            }
        @endphp
        <div class="ag-card ag-card--flat" style="margin-bottom: 18px;">
            <h3 style="font-size: 15px; font-weight: 500; margin-bottom: 6px;">Access URL</h3>
            <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 10px;">
                Open the console on your own domain or subdomain, with or without HTTPS.
                <strong>http://{{ $dv['server_ip'] !== '' ? $dv['server_ip'] : 'server-IP' }}:8000</strong> and
                <strong>atglance.internal</strong> always keep working, so a wrong setting never locks you out.
                Each address has its own login session.
            </p>
            @if(!$dv['plugin_enabled'])
                <div class="ag-alert ag-alert--warning" style="margin-bottom: 10px;">
                    <i class="fas fa-lock"></i>
                    <span>Enable Custom Domain &amp; HTTPS in the Plugins tab to set a domain. <a href="{{ route('admin.settings', ['tab' => 'plugins']) }}" style="text-decoration: underline;">Open Plugins</a></span>
                </div>
            @elseif(!$dvCanEdit)
                <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 10px;">Only the super admin can change this.</p>
            @endif

            <form method="POST" action="{{ route('admin.settings.domain') }}" enctype="multipart/form-data">
                @csrf
                <fieldset id="domain-fieldset" {{ $dvLocked ? 'disabled' : '' }} style="border: 0; padding: 0; margin: 0; {{ $dvLocked ? 'opacity: .55;' : '' }}">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 10px;">
                        <div>
                            <label class="ag-label" for="dv-domain">Domain</label>
                            <input class="ag-input" id="dv-domain" type="text" name="domain" value="{{ old('domain', $dv['domain']) }}" placeholder="atglance.internal" autocomplete="off" spellcheck="false">
                            <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;">Any domain or subdomain, e.g. atglance.acme.com. No http:// and no port.</div>
                            @if($dv['local_warning'])
                                <div style="font-size: 12px; color: var(--ag-warning); margin-top: 4px;"><code>.local</code> is reserved for mDNS and can resolve slowly on macOS and Linux. Use <code>.internal</code> or <code>.lan</code> instead.</div>
                            @endif
                        </div>
                        <div>
                            <label class="ag-label" for="dv-ip">Server IP</label>
                            <input class="ag-input" id="dv-ip" type="text" name="server_ip" value="{{ old('server_ip', $dv['server_address']) }}" placeholder="192.168.1.10">
                            <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;">The address users' machines reach this server on. Used for the DNS record.</div>
                        </div>
                    </div>

                    <label class="ag-label" for="dv-mode">HTTPS</label>
                    <select class="ag-select" id="dv-mode" name="https_mode" style="margin-bottom: 6px;">
                        <option value="off" {{ $dvMode === 'off' ? 'selected' : '' }}>Off (plain http://)</option>
                        <option value="builtin" {{ $dvMode === 'builtin' ? 'selected' : '' }} {{ $dv['builtin_seen'] ? '' : 'disabled' }}>Automatic certificate (built-in proxy)</option>
                        <option value="custom" {{ $dvMode === 'custom' ? 'selected' : '' }} {{ $dv['builtin_seen'] ? '' : 'disabled' }}>Use my own certificate (built-in proxy)</option>
                        <option value="platform" {{ $dvMode === 'platform' ? 'selected' : '' }}>Handled by my platform (load balancer / ingress)</option>
                    </select>
                    @unless($dv['builtin_seen'])
                        <div style="font-size: 12px; color: var(--ag-muted); margin-bottom: 10px;">The built-in options unlock after the built-in proxy is detected (Plugins tab).</div>
                    @endunless

                    <div id="dv-custom" style="{{ $dvMode === 'custom' ? '' : 'display:none;' }} margin: 10px 0; padding: 12px; border-radius: 12px; background: var(--ag-card);">
                        @if($dv['certificate'])
                            @php $dvCert = $dv['certificate']; @endphp
                            <div style="font-size: 13px; margin-bottom: 8px;">
                                <strong>Current certificate:</strong> {{ implode(', ', $dvCert['names']) }} &middot; issuer {{ $dvCert['issuer'] }} &middot; expires {{ $dvCert['not_after'] }}
                                <div style="font-size: 11px; color: var(--ag-muted); word-break: break-all;">SHA-256 {{ $dvCert['fingerprint'] }}</div>
                            </div>
                            @if($dvCert['days_left'] < 0)
                                <div class="ag-alert ag-alert--error" style="margin-bottom: 8px;">The certificate has expired. Browsers show a warning until you upload a new one.</div>
                            @elseif($dvCert['days_left'] <= 30)
                                <div class="ag-alert ag-alert--warning" style="margin-bottom: 8px;">The certificate expires in {{ $dvCert['days_left'] }} days. Upload the renewed certificate.</div>
                            @endif
                        @endif
                        <div style="font-size: 13px; margin-bottom: 6px;">Upload a PEM certificate (with its chain) and private key, <strong>or</strong> a .pfx/.p12 file.</div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div><label class="ag-label">Certificate (.crt/.pem)</label><input type="file" name="cert_file" accept=".crt,.pem,.cer"></div>
                            <div><label class="ag-label">Private key (.key/.pem)</label><input type="file" name="key_file" accept=".key,.pem"></div>
                            <div><label class="ag-label">Key passphrase (if any)</label><input class="ag-input" type="password" name="key_passphrase" autocomplete="off"></div>
                            <div></div>
                            <div><label class="ag-label">Or .pfx / .p12</label><input type="file" name="pfx_file" accept=".pfx,.p12"></div>
                            <div><label class="ag-label">.pfx password</label><input class="ag-input" type="password" name="pfx_password" autocomplete="off"></div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;">
                        <button class="ag-btn" type="submit">Save Access URL</button>
                        <button class="ag-btn ag-btn--ghost" type="button" id="dv-check">Check DNS</button>
                    </div>
                    <p id="dv-check-result" class="hidden" style="font-size: 13px; margin-top: 8px;" role="status"></p>
                </fieldset>
            </form>

            @if($dv['certificate'] && $dvCanEdit)
                <form method="POST" action="{{ route('admin.settings.domain.certificate.remove') }}" style="margin-top: 8px;" onsubmit="return confirm('Remove the uploaded certificate? HTTPS for the domain turns off.');">
                    @csrf
                    @method('DELETE')
                    <button class="ag-btn ag-btn--danger ag-btn--sm" type="submit">Remove certificate</button>
                </form>
            @endif

            @if($dv['server_ip'] !== '')
                <div style="margin-top: 14px; padding: 12px; border-radius: 12px; background: var(--ag-card); font-size: 13px; line-height: 1.6;">
                    @if($dv['access_url'] !== '')
                        <div><strong>Access URL:</strong> {{ $dv['access_url'] }}</div>
                        <div><strong>DNS record</strong> (ask your DNS admin): <code>{{ $dv['dns_record'] }}</code></div>
                    @endif
                    <div><strong>Test on one machine</strong>: add <code>{{ $dv['hosts_line'] }}</code> to the hosts file
                        (Windows <code>C:\Windows\System32\drivers\etc\hosts</code>, macOS/Linux <code>/etc/hosts</code>).</div>
                    @if($dv['https_mode'] === 'builtin')
                        <div style="margin-top: 6px;">
                            <strong>Private names</strong> (like <code>.internal</code>) get a certificate from the built-in CA. Trust it once on each machine:
                            @if($dv['ca_available'])
                                <a href="{{ route('admin.settings.domain.ca') }}" style="text-decoration: underline;">Download CA certificate</a>.
                            @else
                                it appears here after the first HTTPS visit.
                            @endif
                            Windows: double-click &rsaquo; Install &rsaquo; Local Machine &rsaquo; "Trusted Root Certification Authorities".
                            macOS: open in Keychain Access &rsaquo; System &rsaquo; set to "Always Trust".
                            Linux: copy to <code>/usr/local/share/ca-certificates/</code> and run <code>sudo update-ca-certificates</code>.
                            Firefox: Settings &rsaquo; Certificates &rsaquo; Import.
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.settings.site', ['tab' => 'site']) }}" enctype="multipart/form-data">
            @csrf
            <h3 style="font-size: 15px; font-weight: 500; margin-bottom: 8px;">Organization</h3>
            <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 10px;">The organization name and logo replace the AtGlance name and logo across the application.</p>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                <div>
                    <label class="ag-label">Organization Name</label>
                    <input class="ag-input" type="text" name="organization_name" value="{{ old('organization_name', $organizationName) }}" required maxlength="255" style="width: 100%;">
                </div>
                <div>
                    <label class="ag-label">Organization Logo</label>
                    <input type="file" name="site_logo" accept=".jpg,.jpeg,.png,.webp,.svg" style="width: 100%; border-radius: 12px; padding: 10px; background: var(--ag-card);">
                    <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;">JPG, PNG, WebP or SVG, up to 2 MB.</div>
                    @if(!empty($organizationLogoUrl))
                        <div style="margin-top: 8px; display: flex; align-items: center; gap: 12px;">
                            <img src="{{ $organizationLogoUrl }}" alt="{{ $organizationName }} logo" style="max-height: 48px; border-radius: 12px; padding: 4px; background: var(--ag-card);">
                            <label style="font-size: 13px; color: var(--ag-subtle); display: flex; gap: 6px; align-items: center;">
                                <input type="checkbox" name="remove_site_logo" value="1"> Remove logo
                            </label>
                        </div>
                    @endif
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label class="ag-label">Site Description</label>
                <textarea class="ag-textarea" name="site_description" rows="3" style="width: 100%;">{{ old('site_description', $siteDescription) }}</textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                <div>
                    <label class="ag-label">Site Tags (comma separated)</label>
                    <input class="ag-input" type="text" name="site_tags" value="{{ old('site_tags', $siteTagsText) }}" placeholder="security, api-gateway, monitoring" style="width: 100%;">
                </div>
                <div>
                    <label class="ag-label">Features (one per line, "Title: description")</label>
                    <textarea class="ag-textarea" name="site_features" rows="4" style="width: 100%;">{{ old('site_features', $siteFeaturesText) }}</textarea>
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label class="ag-label">Metadata (JSON)</label>
                <textarea class="ag-textarea" name="site_metadata" rows="6" style="width: 100%;">{{ old('site_metadata', $siteMetadataText) }}</textarea>
            </div>

            <h3 style="font-size: 15px; font-weight: 500; margin: 18px 0 8px; padding-top: 14px; border-top: 1px solid var(--ag-line);">Public pages</h3>
            <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 10px;">These pages are linked from the home page Quick Links. A page with no content is hidden. Text fields accept Markdown.</p>

            <div style="margin-bottom: 12px;">
                <label class="ag-label">About page</label>
                <textarea class="ag-textarea" name="site_about" rows="6" placeholder="## Who we are" style="width: 100%;">{{ old('site_about', $siteAbout) }}</textarea>
            </div>

            <div style="margin-bottom: 12px;">
                <label class="ag-label">FAQ page</label>
                @php
                    $faqRows = old('faq_question') !== null
                        ? collect(old('faq_question'))->map(fn ($question, $index) => ['question' => $question, 'answer' => old('faq_answer')[$index] ?? ''])->all()
                        : $siteFaq;
                    if (empty($faqRows)) {
                        $faqRows = [['question' => '', 'answer' => '']];
                    }
                @endphp
                <div id="faq-rows">
                    @foreach($faqRows as $faqRow)
                        <div class="faq-row" style="border-radius: 12px; padding: 10px; margin-bottom: 8px; background: var(--ag-surface);">
                            <input class="ag-input" type="text" name="faq_question[]" value="{{ $faqRow['question'] }}" placeholder="Question" maxlength="500" style="width: 100%; margin-bottom: 6px;">
                            <textarea class="ag-textarea" name="faq_answer[]" rows="2" placeholder="Answer (Markdown)" style="width: 100%;">{{ $faqRow['answer'] }}</textarea>
                            <button type="button" class="ag-btn ag-btn--ghost faq-remove" style="margin-top: 6px;">Remove</button>
                        </div>
                    @endforeach
                </div>
                <button class="ag-btn ag-btn--ghost" type="button" id="faq-add">Add question</button>
            </div>

            <div style="margin-bottom: 12px;">
                <label class="ag-label">Support page</label>
                <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 8px;">Who your users contact for support, and how they raise a request.</p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                    <input class="ag-input" type="text" name="site_support_contact_name" value="{{ old('site_support_contact_name', $siteSupport['contact_name']) }}" placeholder="Support contact person" maxlength="255" style="width: 100%;">
                    <input class="ag-input" type="email" name="site_support_contact_email" value="{{ old('site_support_contact_email', $siteSupport['contact_email']) }}" placeholder="Support email" maxlength="255" style="width: 100%;">
                    <input class="ag-input" type="text" name="site_support_contact_phone" value="{{ old('site_support_contact_phone', $siteSupport['contact_phone']) }}" placeholder="Support phone" maxlength="50" style="width: 100%;">
                    <input class="ag-input" type="text" name="site_support_hours" value="{{ old('site_support_hours', $siteSupport['hours']) }}" placeholder="Support hours, e.g. Mon-Fri 09:00-18:00 IST" maxlength="255" style="width: 100%;">
                </div>
                <input class="ag-input" type="url" name="site_support_request_url" value="{{ old('site_support_request_url', $siteSupport['request_url']) }}" placeholder="Link to the guide or portal for raising a request (https://...)" maxlength="2048" style="width: 100%; margin-bottom: 10px;">
                <textarea class="ag-textarea" name="site_support_details" rows="5" placeholder="Steps to raise a support request (Markdown)" style="width: 100%;">{{ old('site_support_details', $siteSupport['details']) }}</textarea>
            </div>

            <div style="margin-bottom: 14px;">
                <label class="ag-label">Contact page</label>
                <input type="hidden" name="site_contact_enabled" value="0">
                <label style="display: flex; gap: 8px; align-items: center; font-size: 13px; color: var(--ag-subtle); margin-bottom: 8px;">
                    <input type="checkbox" name="site_contact_enabled" value="1" {{ old('site_contact_enabled', $siteContactEnabled ? '1' : '0') === '1' ? 'checked' : '' }}>
                    Show the contact form. Messages are listed below.
                </label>
                <textarea class="ag-textarea" name="site_contact_intro" rows="3" placeholder="Text above the contact form (Markdown)" style="width: 100%;">{{ old('site_contact_intro', $siteContactIntro) }}</textarea>
            </div>

            <button class="ag-btn" type="submit">Save Site Settings</button>
        </form>

        <h3 style="font-size: 15px; font-weight: 500; margin: 18px 0 8px; padding-top: 14px; border-top: 1px solid var(--ag-line);">Contact messages</h3>
        @if(($contactSubmissions ?? collect())->isEmpty())
            <p style="font-size: 13px; color: var(--ag-muted);">No messages yet.</p>
        @else
            <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 8px;">Latest 50 messages sent from the Contact page.</p>
            @foreach($contactSubmissions as $submission)
                <div class="ag-card" style="padding: 10px; margin-bottom: 8px;">
                    <div style="display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                        <div style="font-weight: 600; color: var(--ag-text);">{{ $submission->subject }}</div>
                        <div style="font-size: 12px; color: var(--ag-muted);">{{ \App\Support\UserPreferences::datetime($submission->created_at) }}</div>
                    </div>
                    <div style="font-size: 13px; color: var(--ag-subtle); margin: 2px 0 6px;">{{ $submission->name }} &lt;<a href="mailto:{{ $submission->email }}" style="color: var(--ag-teal);">{{ $submission->email }}</a>&gt;</div>
                    <div style="font-size: 13px; color: var(--ag-text); white-space: pre-wrap; word-break: break-word;">{{ $submission->message }}</div>
                    <form method="POST" action="{{ route('admin.settings.contact-submissions.delete', ['submissionId' => $submission->id]) }}" onsubmit="return confirm('Delete this message?');" style="margin-top: 6px;">
                        @csrf
                        @method('DELETE')
                        <button class="ag-btn ag-btn--danger" type="submit">Delete</button>
                    </form>
                </div>
            @endforeach
        @endif
    </div>

    <div id="tab-s3" class="settings-tab-content ag-card" style="display:{{ $activeTab === 's3' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 8px;">S3 Configuration</h2>
        @php
            $s3EnabledState = (string) old('s3_enabled', ($useS3Storage ?? false) ? '1' : '0');
        @endphp
        <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">Local storage is default. Enable S3 only when you want backups/migration to target S3.</p>
        <div style="margin-bottom: 14px; padding: 10px; border-radius: 12px; background: var(--ag-surface); color: var(--ag-subtle); font-size: 13px; line-height: 1.5;">
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
            <div style="margin-bottom: 12px;">
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="s3_enabled" value="1" {{ $s3EnabledState === '1' ? 'checked' : '' }}>
                    <span>Enable S3 configuration</span>
                </label>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                <div>
                    <label class="ag-label">AWS Access Key</label>
                    <input class="ag-input" type="text" name="s3_access_key" value="{{ old('s3_access_key', $s3AccessKey) }}" required style="width: 100%;">
                </div>
                <div>
                    <label class="ag-label">AWS Secret Key {{ $hasS3Secret ? '(leave blank to keep existing)' : '' }}</label>
                    <input class="ag-input" type="password" name="s3_secret_key" {{ $hasS3Secret ? '' : 'required' }} style="width: 100%;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                <div>
                    <label class="ag-label">AWS Region</label>
                    <input class="ag-input" type="text" name="s3_region" value="{{ old('s3_region', $s3Region) }}" required placeholder="ap-south-1" style="width: 100%;">
                </div>
                <div>
                    <label class="ag-label">AWS Bucket</label>
                    <input class="ag-input" type="text" name="s3_bucket" value="{{ old('s3_bucket', $s3Bucket) }}" required placeholder="my-bucket" style="width: 100%;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px;">
                <div>
                    <div style="font-size: 12px; color: var(--ag-subtle); margin-bottom: 4px;">Local Storage Base URL</div>
                    <div style="font-size: 13px; color: var(--ag-text); font-weight: 600;">{{ $localStorageBaseUrl }}</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--ag-subtle); margin-bottom: 4px;">S3 Storage Base URL</div>
                    <div style="font-size: 13px; color: var(--ag-text); font-weight: 600;">{{ $s3StorageBaseUrl !== '' ? $s3StorageBaseUrl : 'Will auto-generate after save' }}</div>
                </div>
            </div>

            <button class="ag-btn" type="submit">Save S3 Settings</button>
        </form>
    </div>

    <div id="tab-email" class="settings-tab-content ag-card" style="display:{{ $activeTab === 'email' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 12px;">Email Configuration</h2>
        <form method="POST" action="{{ route('admin.settings.mail', ['tab' => 'email']) }}">
            @csrf
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                <div>
                    <label class="ag-label">SMTP Host</label>
                    <input class="ag-input" type="text" name="mail_host" value="{{ old('mail_host', $mailHost) }}" required style="width: 100%;">
                </div>
                <div>
                    <label class="ag-label">SMTP Port</label>
                    <input class="ag-input" type="number" name="mail_port" value="{{ old('mail_port', $mailPort) }}" required style="width: 100%;">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                <div>
                    <label class="ag-label">SMTP Username</label>
                    <input class="ag-input" type="text" name="mail_username" value="{{ old('mail_username', $mailUsername) }}" required style="width: 100%;">
                </div>
                <div>
                    <label class="ag-label">SMTP Password {{ $hasMailPassword ? '(leave blank to keep existing)' : '' }}</label>
                    <input class="ag-input" type="password" name="mail_password" {{ $hasMailPassword ? '' : 'required' }} style="width: 100%;">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                <div>
                    <label class="ag-label">Encryption</label>
                    <select class="ag-select" name="mail_encryption" style="width: 100%;">
                        <option value="" {{ old('mail_encryption', $mailEncryption) === '' ? 'selected' : '' }}>None</option>
                        <option value="tls" {{ old('mail_encryption', $mailEncryption) === 'tls' ? 'selected' : '' }}>TLS</option>
                        <option value="ssl" {{ old('mail_encryption', $mailEncryption) === 'ssl' ? 'selected' : '' }}>SSL</option>
                        <option value="starttls" {{ old('mail_encryption', $mailEncryption) === 'starttls' ? 'selected' : '' }}>STARTTLS</option>
                    </select>
                </div>
                <div>
                    <label class="ag-label">From Name</label>
                    <input class="ag-input" type="text" name="mail_from_name" value="{{ old('mail_from_name', $mailFromName) }}" required style="width: 100%;">
                </div>
            </div>
            <div style="margin-bottom: 12px;">
                <label class="ag-label">From Address</label>
                <input class="ag-input" type="email" name="mail_from_address" value="{{ old('mail_from_address', $mailFromAddress) }}" required style="width: 100%;">
            </div>
            <div style="margin-bottom: 12px;">
                <label class="ag-label">Alert Recipients (comma separated)</label>
                <textarea class="ag-textarea" name="mail_recipients" rows="3" required style="width: 100%;">{{ old('mail_recipients', $mailRecipientsText) }}</textarea>
            </div>
            <button class="ag-btn" type="submit">Save Email Settings</button>
        </form>
    </div>

    <div id="tab-migration" class="settings-tab-content ag-card" style="display:{{ $activeTab === 'migration' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 8px;">Migration</h2>
        <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">One-click migration supports Local to S3 and S3 to Local for tracked configuration files while preserving path structure.</p>
        <div style="margin-bottom: 14px; padding: 10px; border-radius: 12px; background: var(--ag-surface); color: var(--ag-subtle); font-size: 13px; line-height: 1.5;">
            <strong>Note:</strong>
            <br>
            Migration is an on-demand mechanism used to move data between local storage and S3.
            <br>
            It enables immediate transfer of data either to S3 or back to local storage, as required.
            <br>
            The default behavior of the system remains unchanged - all data is stored and accessed from local storage unless explicitly migrated.
        </div>

        <form method="POST" action="{{ route('admin.settings.migration.config', ['tab' => 'migration']) }}" style="margin-bottom: 14px;">
            @csrf
            <input type="hidden" name="migration_enabled" value="0">
            <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                <input type="checkbox" name="migration_enabled" value="1" {{ old('migration_enabled', ($migrationEnabled ?? false) ? '1' : '0') === '1' ? 'checked' : '' }}>
                <span>Enable Migration</span>
            </label>
            <button class="ag-btn" type="submit">Save Migration Setting</button>
        </form>

        @if($migrationEnabled)
            <div style="border-radius: 12px; padding: 14px; margin-bottom: 12px; background: var(--ag-surface);">
                <form method="POST" action="{{ route('admin.settings.migration.analyze', ['tab' => 'migration']) }}" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: end; margin-bottom: 10px;">
                    @csrf
                    <div>
                        <label class="ag-label">Migration Direction</label>
                        <select class="ag-select" name="direction">
                            <option value="local_to_s3" {{ old('direction', $migrationDirection) === 'local_to_s3' ? 'selected' : '' }}>Local to S3</option>
                            <option value="s3_to_local" {{ old('direction', $migrationDirection) === 's3_to_local' ? 'selected' : '' }}>S3 to Local</option>
                        </select>
                    </div>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="keep_source" value="1" {{ old('keep_source', $migrationKeepSource ? '1' : '0') === '1' ? 'checked' : '' }}>
                        <span style="font-size: 13px; color: var(--ag-text);">Keep source files after migration</span>
                    </label>
                    <button type="submit" style="background: #5b626b; color: white; border: none; border-radius: 12px; padding: 8px 12px; font-weight: 600; cursor: pointer;">Analyze</button>
                </form>

                <form method="POST" action="{{ route('admin.settings.migration.start', ['tab' => 'migration']) }}" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                    @csrf
                    <input type="hidden" name="direction" value="{{ old('direction', $migrationDirection) }}">
                    <input type="hidden" name="keep_source" value="{{ old('keep_source', $migrationKeepSource ? '1' : '0') }}">
                    <button class="ag-btn" type="submit">Start One-Click Migration</button>
                </form>
            </div>

            @if(!empty($migrationNotice))
                <div style="margin-bottom: 10px; padding: 10px; border-radius: 12px; background: #e4f6ff; color: #1f7fb8;">{{ $migrationNotice }}</div>
            @endif

            @if(is_array($migrationAnalysis))
                <div style="margin-bottom: 10px; padding: 10px; border-radius: 12px; background: var(--ag-surface);">
                    <div style="font-weight: 600; margin-bottom: 6px;">Migration Analysis</div>
                    <div style="font-size: 13px; color: var(--ag-subtle);">Pending files: {{ $migrationAnalysis['files_pending_migration'] ?? 0 }}</div>
                    <div style="font-size: 13px; color: var(--ag-subtle);">Source files found: {{ $migrationAnalysis['source_files_found'] ?? 0 }}</div>
                    <div style="font-size: 13px; color: var(--ag-subtle);">Missing source files: {{ $migrationAnalysis['missing_source_files'] ?? 0 }}</div>
                </div>
            @endif

            @if(is_array($migrationResult))
                <div style="padding: 10px; border-radius: 12px; background: var(--ag-success-soft); border: 1px solid #bdf3e0;">
                    <div style="font-weight: 600; margin-bottom: 6px;">Migration Result</div>
                    <div style="font-size: 13px; color: var(--ag-success);">Migrated files: {{ $migrationResult['migrated_files'] ?? 0 }}</div>
                    <div style="font-size: 13px; color: var(--ag-success);">Verified files: {{ $migrationResult['verified_files'] ?? 0 }}</div>
                    <div style="font-size: 13px; color: var(--ag-success);">Progress: {{ $migrationResult['progress_percent'] ?? 0 }}%</div>
                </div>
            @endif
        @else
            <p style="color: var(--ag-muted);">Migration is disabled. Enable it above to use one-click local to S3 or S3 to local migration.</p>
        @endif
    </div>

    <div id="tab-plugins" class="settings-tab-content ag-card" style="display:{{ $activeTab === 'plugins' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 8px;">Plugins</h2>
        <p style="font-size: 13px; color: var(--ag-muted);">Optional features. Each one is off until you enable it.</p>
        @php
            $dv = $domainView;
            $dvCanEdit = (int) auth()->user()->rbac_id === 100;
            $dvSeen = $dv['proxy_seen'];
            $dvStatus = isset($dvSeen['builtin'])
                ? 'Built-in proxy detected (' . strtoupper($dvSeen['builtin']['scheme']) . ')'
                : (isset($dvSeen['platform']) ? 'Platform proxy detected (' . strtoupper($dvSeen['platform']['scheme']) . ')' : 'Not detected yet. Open the console through the proxy once to confirm.');
        @endphp
        <div class="ag-card" style="margin-top: 12px;">
            <div class="ag-card-header">
                <span class="ag-card-icon"><i class="fas fa-globe"></i></span>
                <h3 class="ag-card-title">Custom Domain &amp; HTTPS</h3>
                <span class="ag-badge {{ $dv['plugin_enabled'] ? 'ag-badge--success' : '' }}" style="margin-left: auto;">{{ $dv['plugin_enabled'] ? 'Enabled' : 'Disabled' }}</span>
            </div>
            <p style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 10px;">
                Open the console on your own domain, e.g. <code>https://atglance.acme.com</code>. Nothing changes on the server until you follow the steps below.
                <code>http://server-IP:8000</code> keeps working at all times.
            </p>
            @if($dvCanEdit)
                <form method="POST" action="{{ route('admin.settings.domain.plugin') }}" style="margin-bottom: 12px;">
                    @csrf
                    <input type="hidden" name="enabled" value="{{ $dv['plugin_enabled'] ? '0' : '1' }}">
                    <button class="ag-btn {{ $dv['plugin_enabled'] ? 'ag-btn--ghost' : '' }}" type="submit">{{ $dv['plugin_enabled'] ? 'Disable' : 'Enable' }}</button>
                </form>
            @else
                <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 12px;">Only the super admin can change this.</p>
            @endif

            @if($dv['plugin_enabled'])
                <div style="font-size: 13px; margin-bottom: 12px;"><strong>Status:</strong> {{ $dvStatus }}</div>
                @if(isset($dvSeen['untrusted']))
                    <div class="ag-alert ag-alert--warning" style="margin-bottom: 12px;">
                        <span>A proxy at <code>{{ $dvSeen['untrusted']['address'] }}</code> sends X-Forwarded headers, but it is not trusted, so the console ignores them (no HTTPS detection, no redirect).
                        If it is your load balancer, add its address or subnet to <code>ATGLANCE_TRUSTED_PROXIES</code> in the app environment (for example <code>127.0.0.1,::1,10.0.0.0/8</code>) and restart the app.</span>
                    </div>
                @endif
                @if($dv['https_mode'] === 'custom' && $dv['certificate'] && $dv['certificate']['days_left'] <= 30)
                    <div class="ag-alert {{ $dv['certificate']['days_left'] < 0 ? 'ag-alert--error' : 'ag-alert--warning' }}" style="margin-bottom: 12px;">
                        {{ $dv['certificate']['days_left'] < 0 ? 'Your certificate has expired.' : 'Your certificate expires in ' . $dv['certificate']['days_left'] . ' days.' }}
                        Upload the renewed one on the Site tab.
                    </div>
                @endif
                <div style="font-size: 13px; line-height: 1.7;">
                    <strong>VM / Docker Compose</strong> (built-in proxy on ports 80 and 443):
                    <ol style="margin: 4px 0 10px 18px;">
                        <li>Check ports 80 and 443 are free: <code>sudo ss -ltn '( sport = :80 or sport = :443 )'</code> (no output = free).</li>
                        <li>In the install folder (default <code>/opt/atglance</code>) run: <code>docker compose -f docker-compose.yml -f docker-compose.domain.yml up -d</code>. If a port is taken, set <code>HTTP_PORT</code> / <code>HTTPS_PORT</code> in <code>.env</code> first.</li>
                        <li>Open <code>http://server-IP</code> (port 80) once. The status above changes to "Built-in proxy detected".</li>
                        <li>To undo: <code>docker compose up -d</code> (without the second file).</li>
                    </ol>
                    <strong>AWS ECS:</strong> ALB listener on 443 with an ACM certificate, forwarding to container port 8000 (route 8002 separately for the CLI). Then choose "Handled by my platform".<br>
                    <strong>Azure Container Apps:</strong> ingress target port 8000, plus a custom domain with a managed certificate. Then choose "Handled by my platform".<br>
                    <strong>Kubernetes:</strong> an Ingress to service port 8000, with cert-manager for TLS. Then choose "Handled by my platform".<br>
                    On these platforms also set <code>ATGLANCE_TRUSTED_PROXIES</code> in the app environment to the load balancer's subnet (for example <code>127.0.0.1,::1,10.0.0.0/8</code>), so the console trusts its X-Forwarded headers.
                </div>
                <a class="ag-btn ag-btn--sm" style="margin-top: 12px;" href="{{ route('admin.settings', ['tab' => 'site']) }}">Set the domain on the Site tab</a>
            @endif
        </div>
    </div>

    <div id="tab-backup-restore" class="settings-tab-content ag-card" style="display:{{ $activeTab === 'backup-restore' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 8px;">Backup &amp; Restore</h2>
        <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">Default mode is local-only. Enable S3 backup options only when needed.</p>
        <div style="margin-bottom: 14px; padding: 10px; border-radius: 12px; background: var(--ag-surface); color: var(--ag-subtle); font-size: 13px; line-height: 1.5;">
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
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                <div>
                    <label class="ag-label">Enable Backup and Restore</label>
                    <select class="ag-select" name="backup_restore_enabled" id="backup-restore-enabled" style="width: 100%;">
                        <option value="0" {{ $backupRestoreEnabledState ? '' : 'selected' }}>No</option>
                        <option value="1" {{ $backupRestoreEnabledState ? 'selected' : '' }}>Yes</option>
                    </select>
                </div>
            </div>

            <div id="backup-restore-details" style="display:{{ $backupRestoreEnabledState ? 'block' : 'none' }};">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div>
                        <label class="ag-label">Save configuration files backup on S3 (Yes/No)</label>
                        <select class="ag-select" name="backup_config_to_s3" id="backup-config-to-s3" style="width: 100%;">
                            <option value="0" {{ $backupConfigToS3State ? '' : 'selected' }}>No</option>
                            <option value="1" {{ $backupConfigToS3State ? 'selected' : '' }}>Yes</option>
                        </select>
                    </div>
                    <div id="backup-config-cron-wrap" style="display:{{ $backupConfigToS3State ? 'block' : 'none' }};">
                        <label class="ag-label">Configuration backup cron</label>
                        <select class="ag-select" name="backup_config_cron" style="width: 100%;">
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

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px;">
                    <div>
                        <label class="ag-label">Save portal regular (.env file, settings, DB) backup on S3 (Yes/No)</label>
                        <select class="ag-select" name="backup_portal_to_s3" id="backup-portal-to-s3" style="width: 100%;">
                            <option value="0" {{ $backupPortalToS3State ? '' : 'selected' }}>No</option>
                            <option value="1" {{ $backupPortalToS3State ? 'selected' : '' }}>Yes</option>
                        </select>
                    </div>
                    <div id="backup-portal-cron-wrap" style="display:{{ $backupPortalToS3State ? 'block' : 'none' }};">
                        <label class="ag-label">Portal backup cron</label>
                        <select class="ag-select" name="backup_portal_cron" style="width: 100%;">
                            <option value="" {{ $backupPortalCronValue === '' ? 'selected' : '' }}>Select frequency</option>
                            <option value="daily" {{ $backupPortalCronValue === 'daily' ? 'selected' : '' }}>Every day</option>
                            <option value="weekly" {{ $backupPortalCronValue === 'weekly' ? 'selected' : '' }}>Every week</option>
                            <option value="monthly" {{ $backupPortalCronValue === 'monthly' ? 'selected' : '' }}>Every month</option>
                        </select>
                    </div>
                </div>
            </div>

            <button class="ag-btn" type="submit">Save Backup Settings</button>
        </form>

        <div id="restore-section" data-url="{{ route('admin.settings.backups') }}" style="margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--ag-line);">
            <h3 style="font-size: 16px; margin-bottom: 6px;">Restore</h3>
            <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 10px;">
                Backups from S3 and pre-restore snapshots on this server. A snapshot of the current state is saved before every restore.
            </p>
            <div style="margin-bottom: 12px; padding: 10px; border-radius: 12px; background: var(--ag-danger-soft); border: 1px solid #f9d6d6; color: var(--ag-danger); font-size: 13px; line-height: 1.5;">
                <strong>Warning:</strong> a restore overwrites current data.
                <br>
                Configuration files backup: adds missing records back and resets changed records to their backed-up values. Records created after the backup are kept.
                <br>
                Portal backup: replaces the whole database and the .env file. Only the super admin can run it. Restart the app containers afterwards.
            </div>

            <div id="restore-errors" style="display: none; margin-bottom: 10px; font-size: 13px; color: var(--ag-danger);"></div>
            <div style="overflow-x: auto; margin-bottom: 12px;">
                <table class="ag-table" style="width: 100%;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid var(--ag-line); color: var(--ag-subtle);">
                            <th style="padding: 6px;">Type</th>
                            <th style="padding: 6px;">Source</th>
                            <th style="padding: 6px;">Backup</th>
                            <th style="padding: 6px;">Created</th>
                            <th style="padding: 6px;">Size</th>
                            <th style="padding: 6px;"></th>
                        </tr>
                    </thead>
                    <tbody id="restore-backup-rows">
                        <tr><td colspan="6" style="padding: 8px;">Loading backups...</td></tr>
                    </tbody>
                </table>
            </div>

            <form method="POST" action="{{ route('admin.settings.restore') }}" id="restore-form" style="display: none; padding: 12px; border-radius: 12px; background: var(--ag-surface);">
                @csrf
                <input type="hidden" name="source" id="restore-source">
                <input type="hidden" name="path" id="restore-path">
                <div style="font-size: 13px; color: var(--ag-text); margin-bottom: 10px;">Selected backup: <strong id="restore-selected-name"></strong></div>
                <div style="margin-bottom: 10px; max-width: 320px;">
                    <label class="ag-label">Confirm your password</label>
                    <input class="ag-input" type="password" name="password" required autocomplete="current-password" style="width: 100%;">
                </div>
                <label style="display: flex; gap: 8px; align-items: center; font-size: 13px; color: var(--ag-subtle); margin-bottom: 12px;">
                    <input type="checkbox" name="confirm_overwrite" value="1" required>
                    I understand that current data will be overwritten.
                </label>
                <button class="ag-btn ag-btn--danger" type="submit">Restore Backup</button>
                <button class="ag-btn ag-btn--ghost" type="button" id="restore-cancel" style="margin-left: 6px;">Cancel</button>
            </form>
        </div>
    </div>

    <div id="tab-crons" class="settings-tab-content ag-card" style="display:{{ $activeTab === 'crons' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 8px;">Crons</h2>
        <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 10px;">Shows only what is currently configured.</p>

        @if(!empty($configuredCronSetups ?? []))
            <div style="display: grid; grid-template-columns: 1fr; gap: 10px;">
                @foreach(($configuredCronSetups ?? []) as $cronSetup)
                    <div style="padding: 10px; border-radius: 12px; background: var(--ag-surface);">
                        <div style="font-weight: 600; color: var(--ag-text);">{{ $cronSetup['name'] ?? 'Cron' }}</div>
                        <div style="font-size: 13px; color: var(--ag-subtle);">{{ $cronSetup['frequency'] ?? 'Not configured' }}</div>
                        <div style="font-size: 13px; color: var(--ag-subtle); margin-top: 4px;">CRON: <span>{{ $cronSetup['expression'] ?? '* * * * * *' }}</span></div>
                        @php
                            $lastRun = $cronSetup['last_run'] ?? [];
                            $lastRunStatus = $lastRun['status'] ?? '';
                            $lastRunColor = match ($lastRunStatus) {
                                'success' => '#15803d',
                                'failed' => '#b91c1c',
                                default => '#92400e',
                            };
                        @endphp
                        <div style="font-size: 13px; color: var(--ag-subtle); margin-top: 4px;">
                            Last run:
                            @if(!empty($lastRun))
                                <span style="font-weight:600; color:{{ $lastRunColor }};">{{ ucfirst($lastRunStatus) }}</span>
                                at {{ \App\Support\UserPreferences::datetime($lastRun['finished_at'] ?? $lastRun['started_at']) }}
                                @if(!empty($lastRun['message']))
                                    <div style="font-size: 12px; color: var(--ag-muted); margin-top: 2px; word-break: break-all;">{{ $lastRun['message'] }}</div>
                                @endif
                            @else
                                <span style="color: var(--ag-muted);">Never</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p style="color: var(--ag-muted);">No cron schedules configured yet.</p>
        @endif
    </div>

    <div id="tab-licence" class="settings-tab-content ag-card" style="display:{{ $activeTab === 'licence' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 8px;">Licence</h2>
        @php
            $licence = \App\Support\License::summary();
        @endphp

        @if($licence['active'])
            <div style="margin-bottom: 14px; padding: 10px; border-radius: 12px; background: var(--ag-success-soft); color: var(--ag-success); font-size: 13px; font-weight: 600;">Licence active</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 16px; font-size: 14px;">
                <div><div style="font-size: 12px; color: var(--ag-muted);">Licence name</div><div>{{ $licence['name'] !== '' ? $licence['name'] : '-' }}</div></div>
                <div><div style="font-size: 12px; color: var(--ag-muted);">Plan</div><div>{{ $licence['plan'] !== '' ? $licence['plan'] : '-' }}</div></div>
                <div><div style="font-size: 12px; color: var(--ag-muted);">Expires</div><div>{{ $licence['expires_at'] !== '' ? $licence['expires_at'] : '-' }}</div></div>
                <div><div style="font-size: 12px; color: var(--ag-muted);">Licence key</div><div>{{ $licence['masked_key'] }}</div></div>
                <div><div style="font-size: 12px; color: var(--ag-muted);">Activated on</div><div>{{ \App\Support\License::date($licence['activated_at']) ?: '-' }}</div></div>
                <div><div style="font-size: 12px; color: var(--ag-muted);">Validated on</div><div>{{ \App\Support\License::date($licence['verified_at']) ?: '-' }}</div></div>
            </div>
            @php
                $licenceExtra = collect($licence['details'])->except(['license.name', 'plan', 'license.expires_at']);
            @endphp
            @if($licenceExtra->isNotEmpty())
                <details style="margin-bottom: 16px; font-size: 13px;">
                    <summary style="cursor: pointer; color: var(--ag-subtle);">All licence details</summary>
                    <table class="ag-table" style="margin-top: 8px;">
                        @foreach($licenceExtra as $field => $value)
                            <tr>
                                <td style="padding: 4px 12px 4px 0;">{{ $field }}</td>
                                <td style="padding: 4px 0;">{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}</td>
                            </tr>
                        @endforeach
                    </table>
                </details>
            @endif
        @else
            <div style="margin-bottom: 14px; padding: 12px; border-radius: 12px; background: var(--ag-warning-soft); border: 1px solid #fcd34d; color: var(--ag-warning); font-size: 13px;">
                <strong>No active licence.</strong> Until a licence is added, nobody can create or register users and no API keys can be created.
                @if($licence['check_message'] !== '')
                    <div style="margin-top: 6px;">Last check ({{ \App\Support\License::date($licence['verified_at']) }}): {{ $licence['check_message'] }}</div>
                @endif
            </div>
        @endif

        <div style="border-radius: 12px; padding: 12px; background: var(--ag-surface); margin-bottom: 14px;">
            <strong style="font-size: 13px;">Get a licence</strong>
            <ol style="margin: 6px 0 0 18px; padding: 0; font-size: 13px; color: var(--ag-subtle); line-height: 1.6;">
                <li>Log in to <a href="{{ \App\Support\License::portalUrl() }}" target="_blank" rel="noopener" style="color: var(--ag-teal); text-decoration: underline;">atglance.live</a>.</li>
                <li>Generate a licence.</li>
                <li>Copy the licence key and paste it below.</li>
            </ol>
        </div>

        <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 10px;">
            A licence works on one console only. This console's ID is <span>{{ \App\Support\License::instanceId() }}</span>.
        </p>

        <form method="POST" action="{{ route('admin.settings.licence') }}">
            @csrf
            <label class="ag-label" for="license-key">{{ $licence['active'] ? 'Replace licence key' : 'Licence key' }}</label>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <input class="ag-input" id="license-key" type="text" name="license_key" required autocomplete="off" spellcheck="false" placeholder="Paste your licence key" style="flex: 1; min-width: 240px;">
                <button class="ag-btn" type="submit">Verify &amp; Save</button>
            </div>
        </form>
    </div>

    <div id="tab-ai-connect" class="settings-tab-content ag-card" style="display:{{ $activeTab === 'ai-connect' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 8px;">AI Connect</h2>
        @php
            $aiCanEdit = (int) auth()->user()->rbac_id === 100;
            $aiProviders = \App\Support\AiSettings::PROVIDERS;
            $aiProvider = old('ai_provider', \App\Support\AiSettings::provider());
            $aiProvider = isset($aiProviders[$aiProvider]) ? $aiProvider : 'anthropic';
            $aiHasKey = \App\Support\AiSettings::apiKey() !== '';
            $aiLastTest = \App\Support\AiSettings::lastTest();
        @endphp
        <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 10px;">
            Connect an AI provider to {{ $brandName ?? 'AtGlance' }}. Cloud providers need an API key; self-hosted models (Ollama, LM Studio, OpenClaw) need a base URL the server can reach.
        </p>
        @unless($aiCanEdit)
            <div style="margin-bottom: 12px; padding: 10px; border-radius: 12px; background: var(--ag-surface); color: var(--ag-subtle); font-size: 13px;">Only the super admin can change these settings.</div>
        @endunless

        <form id="ai-connect-form" method="POST" action="{{ route('admin.settings.ai') }}" data-test-url="{{ route('admin.settings.ai.test') }}" data-models-url="{{ route('admin.settings.ai.models') }}">
            @csrf
            <fieldset {{ $aiCanEdit ? '' : 'disabled' }} style="border: none; padding: 0; margin: 0;">
                <label style="display: flex; gap: 8px; align-items: center; font-size: 14px; margin-bottom: 14px;">
                    <input type="checkbox" name="ai_enabled" value="1" {{ old('ai_enabled', \App\Support\AiSettings::enabled()) ? 'checked' : '' }}>
                    Enable AI features
                </label>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label class="ag-label" for="ai-provider">Provider</label>
                        <select class="ag-select" id="ai-provider" name="ai_provider" style="width: 100%;">
                            @foreach($aiProviders as $aiKey => $aiMeta)
                                <option value="{{ $aiKey }}" {{ $aiProvider === $aiKey ? 'selected' : '' }}>{{ $aiMeta['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="ag-label" for="ai-base-url">Base URL <span id="ai-base-url-note" style="color: var(--ag-muted);"></span></label>
                        <input class="ag-input" id="ai-base-url" type="text" name="ai_base_url" value="{{ old('ai_base_url', \App\Support\AiSettings::baseUrl()) }}" style="width: 100%;">
                    </div>
                    <div id="ai-key-wrap">
                        <label class="ag-label" for="ai-api-key">API key <span id="ai-key-note" style="color: var(--ag-muted);"></span></label>
                        <input class="ag-input" id="ai-api-key" type="password" name="ai_api_key" autocomplete="new-password" data-saved="{{ $aiHasKey ? '1' : '0' }}" placeholder="{{ $aiHasKey ? 'Saved; leave blank to keep' : 'Not set' }}" style="width: 100%;">
                        @if($aiHasKey)
                            <label style="display: flex; gap: 6px; align-items: center; font-size: 12px; color: var(--ag-muted); margin-top: 4px;">
                                <input type="checkbox" name="ai_clear_api_key" value="1"> Remove saved key
                            </label>
                        @endif
                    </div>
                    <div>
                        <label class="ag-label" for="ai-model">Model</label>
                        <div style="display: flex; gap: 6px;">
                            <input class="ag-input" id="ai-model" type="text" name="ai_model" list="ai-model-options" value="{{ old('ai_model', \App\Support\AiSettings::model()) }}" style="flex: 1; min-width: 0;">
                            @if($aiCanEdit)
                                <button class="ag-btn ag-btn--ghost" type="button" id="ai-load-models" style="white-space: nowrap;">Load models</button>
                            @endif
                        </div>
                        <datalist id="ai-model-options"></datalist>
                        <div id="ai-model-hint" style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;"></div>
                    </div>
                </div>

                <div style="border-radius: 12px; padding: 12px; background: var(--ag-surface); margin-bottom: 14px;">
                    <strong id="ai-steps-title" style="font-size: 13px;">Setup</strong>
                    <ol id="ai-steps" style="margin: 6px 0 0 18px; padding: 0; font-size: 13px; color: var(--ag-subtle); line-height: 1.6;"></ol>
                </div>

                <div id="ai-result" role="status" style="display: none; margin-bottom: 12px; padding: 10px; border-radius: 12px; font-size: 13px; word-break: break-word;"></div>

                @if($aiLastTest)
                    <div id="ai-last-test" style="font-size: 12px; color: var(--ag-muted); margin-bottom: 12px;">
                        Last test: <span style="color:{{ $aiLastTest['ok'] ? '#137a54' : '#b91c1c' }}; font-weight:600;">{{ $aiLastTest['ok'] ? 'Success' : 'Failed' }}</span>
                        ({{ $aiProviders[$aiLastTest['provider']]['label'] ?? $aiLastTest['provider'] }}{{ $aiLastTest['model'] ? ', ' . $aiLastTest['model'] : '' }})
                        {{ \Illuminate\Support\Carbon::parse($aiLastTest['at'])->diffForHumans() }} - {{ $aiLastTest['message'] }}
                    </div>
                @endif

                @if($aiCanEdit)
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button class="ag-btn ag-btn--ghost" type="button" id="ai-test">Test connection</button>
                        <button class="ag-btn" type="submit">Save AI Connection</button>
                    </div>
                @endif
            </fieldset>
        </form>
        <script type="application/json" id="ai-provider-catalog">@json(\App\Support\AiSettings::catalogForView())</script>
    </div>

    <div id="tab-notification" class="settings-tab-content ag-card" style="display:{{ $activeTab === 'notification' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 8px;">Notification</h2>
        @php
            $notifyCanEdit = (int) auth()->user()->rbac_id === 100;
        @endphp
        <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 10px;">
            Allow the channels that workspace admins can use, and set the organization-level connection for each one.
            Workspace admins then add their own groups (email lists, channels, chats) on the
            <a href="{{ route('admin.notifications') }}" style="color: var(--ag-teal); text-decoration: underline;">Notifications</a> page.
        </p>
        @unless($notifyCanEdit)
            <div style="margin-bottom: 12px; padding: 10px; border-radius: 12px; background: var(--ag-surface); color: var(--ag-subtle); font-size: 13px;">Only the super admin can change these settings.</div>
        @endunless

        <form method="POST" action="{{ route('admin.settings.notifications') }}">
            @csrf
            <fieldset {{ $notifyCanEdit ? '' : 'disabled' }} style="border: none; padding: 0; margin: 0;">
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-bottom: 14px;">
                    @foreach(\App\Support\NotificationSettings::CHANNELS as $notifyChannel => $notifyMeta)
                        @php
                            $notifyAllowed = \App\Support\NotificationSettings::isAllowed($notifyChannel);
                            $notifyMissing = $notifyAllowed ? \App\Support\NotificationSettings::missingSetup($notifyChannel) : null;
                        @endphp
                        <div style="border:1px solid #e5e7eb; border-radius:8px; padding:12px; background:{{ $notifyMeta['available'] ? '#ffffff' : '#f9fafb' }};">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 6px;">
                                <strong style="font-size: 14px;">{{ $notifyMeta['label'] }}</strong>
                                @if(!$notifyMeta['available'])
                                    <span style="font-size: 11px; color: var(--ag-warning); background: var(--ag-warning-soft); border-radius: 999px; padding: 2px 8px;">Waiting for provider API details</span>
                                @elseif($notifyAllowed && $notifyMissing === null)
                                    <span style="font-size: 11px; color: #137a54; background: var(--ag-success-soft); border-radius: 999px; padding: 2px 8px;">Ready</span>
                                @elseif($notifyAllowed)
                                    <span style="font-size: 11px; color: var(--ag-danger); background: var(--ag-danger-soft); border-radius: 999px; padding: 2px 8px;">Setup needed</span>
                                @endif
                            </div>
                            <div style="font-size: 12px; color: var(--ag-muted); margin-bottom: 8px;">Groups enter: {{ $notifyMeta['target'] }}</div>

                            @if($notifyMeta['available'])
                                <label style="display: flex; gap: 8px; align-items: center; font-size: 13px; color: var(--ag-subtle); margin-bottom: 8px;">
                                    <input type="hidden" name="allowed[{{ $notifyChannel }}]" value="0">
                                    <input type="checkbox" name="allowed[{{ $notifyChannel }}]" value="1" {{ $notifyAllowed ? 'checked' : '' }}>
                                    Allow workspace admins to use this channel
                                </label>
                            @endif

                            @if($notifyChannel === 'email')
                                <div style="font-size: 12px; color: var(--ag-subtle);">
                                    SMTP server: {{ \App\Support\NotificationSettings::mailConfigured() ? 'configured' : 'not configured' }} on the
                                    <a href="{{ route('admin.settings', ['tab' => 'email']) }}" style="color: var(--ag-teal); text-decoration: underline;">Email Configuration</a> tab.
                                </div>
                            @endif

                            @foreach(\App\Support\NotificationSettings::CREDENTIALS[$notifyChannel] ?? [] as $notifyKey => $notifyCredential)
                                @php
                                    $notifySaved = \App\Support\NotificationSettings::credential($notifyKey);
                                @endphp
                                <div style="margin-top: 8px;">
                                    <label class="ag-label">{{ $notifyCredential['label'] }}</label>
                                    @if($notifyCredential['secret'])
                                        <input class="ag-input" type="password" name="{{ $notifyKey }}" autocomplete="new-password" placeholder="{{ $notifySaved !== '' ? 'Saved; leave blank to keep' : 'Not set' }}" style="width: 100%;">
                                        @if($notifySaved !== '')
                                            <label style="display: flex; gap: 6px; align-items: center; font-size: 12px; color: var(--ag-muted); margin-top: 4px;">
                                                <input type="checkbox" name="clear[{{ $notifyKey }}]" value="1"> Remove saved value
                                            </label>
                                        @endif
                                    @else
                                        <input class="ag-input" type="text" name="{{ $notifyKey }}" value="{{ old($notifyKey, $notifySaved) }}" style="width: 100%;">
                                    @endif
                                </div>
                            @endforeach

                            @if($notifyMissing)
                                <div style="font-size: 12px; color: var(--ag-danger); margin-top: 8px;">{{ $notifyMissing }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if($notifyCanEdit)
                    <button class="ag-btn" type="submit">Save Notification Channels</button>
                @endif
            </fieldset>
        </form>
    </div>

    <div id="tab-sso" class="settings-tab-content ag-card" style="display:{{ $activeTab === 'sso' ? 'block' : 'none' }}; padding:24px;">
        <h2 style="font-size: 18px; margin-bottom: 12px;">SSO Configuration</h2>
        <form method="POST" action="{{ route('admin.settings.sso', ['tab' => 'sso']) }}">
            @csrf
            <div style="margin-bottom: 12px;">
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input id="sso-enabled-toggle" type="checkbox" name="sso_enabled" value="1" {{ old('sso_enabled', $ssoEnabled) ? 'checked' : '' }}>
                    <span>Enable SSO login</span>
                </label>
            </div>

            <div id="disable-email-registration-wrap" style="margin-bottom:12px; {{ old('sso_enabled', $ssoEnabled) ? '' : 'display:none;' }}">
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input id="disable-email-registration-toggle" type="checkbox" name="disable_email_registration" value="1" {{ old('disable_email_registration', $disableEmailRegistration ?? false) ? 'checked' : '' }}>
                    <span>Disable user registration with email/password</span>
                </label>
                <p style="margin: 6px 0 0 26px; font-size: 12px; color: var(--ag-muted);">When enabled, registration and forgot-password by email are disabled on the login page.</p>
            </div>

            @php
                $selectedProviders = old('sso_enabled_providers', $ssoEnabledProviders ?? []);
                $providerUrls = old('sso_provider_urls', $ssoProviderUrls ?? []);
                $providerClientIds = old('sso_provider_client_ids', $ssoProviderClientIds ?? []);
                $hasProviderClientSecrets = $hasSsoProviderClientSecrets ?? [];
                $providerTenantIds = old('sso_provider_tenant_ids', $ssoProviderTenantIds ?? []);
            @endphp

            <div style="margin-bottom: 14px;">
                <label class="ag-label">Enable Providers</label>
                <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px;">
                    @foreach(($ssoProviderOptions ?? []) as $providerKey => $providerMeta)
                        <label style="display: flex; align-items: center; gap: 8px; border-radius: 12px; padding: 8px 10px; cursor: pointer; background: var(--ag-surface);">
                            <input class="sso-provider-checkbox" type="checkbox" name="sso_enabled_providers[]" value="{{ $providerKey }}" {{ in_array($providerKey, $selectedProviders, true) ? 'checked' : '' }}>
                            <span>{{ $providerMeta['label'] ?? ucfirst($providerKey) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('sso_enabled_providers')
                    <div style="margin-top: 8px; font-size: 12px; color: var(--ag-danger);">{{ $message }}</div>
                @enderror
            </div>

            <div style="margin-bottom: 14px;">
                <label class="ag-label">Provider Login URLs</label>
                <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 10px;">Only selected providers are shown below. Configure URL and Client ID/Secret per provider. Tenant/Domain is optional and not required for GitHub.</p>
                <p style="font-size: 12px; color: var(--ag-subtle); margin-bottom: 10px;">Configure callback/redirect URL on your identity platform, not here. App callback format: <strong>{{ url('/auth/sso/{provider}/callback') }}</strong></p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    @foreach(($ssoProviderOptions ?? []) as $providerKey => $providerMeta)
                        <div class="provider-url-group" data-provider="{{ $providerKey }}" style="display:{{ in_array($providerKey, $selectedProviders, true) ? 'block' : 'none' }};">
                            <div style="font-size: 12px; color: var(--ag-text); margin-bottom: 6px;">Callback URL for {{ $providerMeta['label'] ?? ucfirst($providerKey) }}: <strong>{{ url('/auth/sso/' . $providerKey . '/callback') }}</strong></div>
                            <label class="ag-label">{{ $providerMeta['label'] ?? ucfirst($providerKey) }} URL</label>
                            <input
                                class="ag-input provider-config-input"
                                type="url"
                                name="sso_provider_urls[{{ $providerKey }}]"
                                value="{{ $providerUrls[$providerKey] ?? '' }}"
                                placeholder="https://..."
                                style="width: 100%;"
                            >

                            <label class="ag-label" style="margin: 8px 0 5px;">{{ $providerMeta['label'] ?? ucfirst($providerKey) }} Client ID</label>
                            <input
                                class="ag-input provider-config-input"
                                type="text"
                                name="sso_provider_client_ids[{{ $providerKey }}]"
                                value="{{ $providerClientIds[$providerKey] ?? '' }}"
                                placeholder="Client ID"
                                style="width: 100%;"
                            >

                            <label class="ag-label" style="margin: 8px 0 5px;">{{ $providerMeta['label'] ?? ucfirst($providerKey) }} Client Secret {{ !empty($hasProviderClientSecrets[$providerKey] ?? false) ? '(leave blank to keep existing)' : '' }}</label>
                            <input
                                class="ag-input provider-config-input"
                                type="password"
                                name="sso_provider_client_secrets[{{ $providerKey }}]"
                                placeholder="Client Secret"
                                style="width: 100%;"
                            >

                            @if($providerKey !== 'github')
                                <label class="ag-label" style="margin: 8px 0 5px;">{{ $providerMeta['label'] ?? ucfirst($providerKey) }} Tenant ID / Domain (optional)</label>
                                <input
                                    class="ag-input provider-config-input"
                                    type="text"
                                    name="sso_provider_tenant_ids[{{ $providerKey }}]"
                                    value="{{ $providerTenantIds[$providerKey] ?? '' }}"
                                    placeholder="Tenant ID or Domain"
                                    style="width: 100%;"
                                >
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <button class="ag-btn" type="submit">Save SSO Settings</button>
        </form>
    </div>
    </div>
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
            button.classList.toggle('active', button.dataset.tab === tabName);
        });

        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url.toString());
    }

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => activateTab(button.dataset.tab));
    });

    // FAQ rows on the Site Configuration tab.
    const faqRows = document.getElementById('faq-rows');
    const faqAdd = document.getElementById('faq-add');
    if (faqRows && faqAdd) {
        faqAdd.addEventListener('click', () => {
            const row = faqRows.querySelector('.faq-row').cloneNode(true);
            row.querySelectorAll('input, textarea').forEach((field) => { field.value = ''; });
            faqRows.appendChild(row);
        });
        faqRows.addEventListener('click', (event) => {
            if (!event.target.classList.contains('faq-remove')) return;
            const row = event.target.closest('.faq-row');
            if (faqRows.querySelectorAll('.faq-row').length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input, textarea').forEach((field) => { field.value = ''; });
            }
        });
    }

    // Restore: the backup list is fetched only when the tab is first opened,
    // so a slow S3 never delays the settings page.
    const restoreSection = document.getElementById('restore-section');
    const restoreRows = document.getElementById('restore-backup-rows');
    const restoreErrors = document.getElementById('restore-errors');
    const restoreForm = document.getElementById('restore-form');
    let restoreLoaded = false;

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function restoreCell(text) {
        const cell = document.createElement('td');
        cell.style.padding = '6px';
        cell.textContent = text;
        return cell;
    }

    function selectBackup(backup) {
        document.getElementById('restore-source').value = backup.source;
        document.getElementById('restore-path').value = backup.path;
        document.getElementById('restore-selected-name').textContent = backup.name + ' (' + (backup.type === 'portal' ? 'portal' : 'configuration files') + ', ' + backup.source + ')';
        restoreForm.style.display = 'block';
        restoreForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function loadBackups() {
        if (!restoreSection || restoreLoaded) return;
        restoreLoaded = true;

        fetch(restoreSection.dataset.url, { headers: { 'Accept': 'application/json' } })
            .then((response) => response.json())
            .then((data) => {
                restoreRows.innerHTML = '';

                if (data.errors && data.errors.length) {
                    restoreErrors.textContent = data.errors.join(' ');
                    restoreErrors.style.display = 'block';
                }

                if (!data.backups || !data.backups.length) {
                    const row = document.createElement('tr');
                    const cell = restoreCell('No backups found.');
                    cell.colSpan = 6;
                    cell.style.color = '#8a9099';
                    row.appendChild(cell);
                    restoreRows.appendChild(row);
                    return;
                }

                data.backups.forEach((backup) => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid #f3f4f6';
                    row.appendChild(restoreCell(backup.type === 'portal' ? 'Portal' : 'Configuration files'));
                    row.appendChild(restoreCell(backup.source === 's3' ? 'S3' : 'Local snapshot'));
                    row.appendChild(restoreCell(backup.name));
                    row.appendChild(restoreCell(new Date(backup.last_modified * 1000).toLocaleString()));
                    row.appendChild(restoreCell(formatBytes(backup.size)));

                    const actionCell = restoreCell('');
                    const allowed = backup.type !== 'portal' || data.can_restore_portal;
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = allowed ? 'Restore' : 'Super admin only';
                    button.disabled = !allowed;
                    button.style.cssText = 'border:0; border-radius:999px; padding:5px 12px; background:#f4f6f8; cursor:' + (allowed ? 'pointer' : 'not-allowed') + '; color:' + (allowed ? '#14171b' : '#8a9099') + ';';
                    button.addEventListener('click', () => selectBackup(backup));
                    actionCell.appendChild(button);
                    row.appendChild(actionCell);

                    restoreRows.appendChild(row);
                });
            })
            .catch(() => {
                restoreRows.innerHTML = '';
                restoreErrors.textContent = 'Could not load backups.';
                restoreErrors.style.display = 'block';
                restoreLoaded = false;
            });
    }

    const restoreCancel = document.getElementById('restore-cancel');
    if (restoreCancel) {
        restoreCancel.addEventListener('click', () => { restoreForm.style.display = 'none'; });
    }

    tabButtons.forEach((button) => {
        if (button.dataset.tab === 'backup-restore') {
            button.addEventListener('click', loadBackups);
        }
    });

    if (@json($activeTab === 'backup-restore')) {
        loadBackups();
    }

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

// AI Connect tab: per-provider hints, "Load models" and "Test connection".
(function () {
    const form = document.getElementById('ai-connect-form');
    const catalogEl = document.getElementById('ai-provider-catalog');
    if (!form || !catalogEl) {
        return;
    }

    const catalog = JSON.parse(catalogEl.textContent);
    const providerSelect = document.getElementById('ai-provider');
    const baseUrlInput = document.getElementById('ai-base-url');
    const baseUrlNote = document.getElementById('ai-base-url-note');
    const keyWrap = document.getElementById('ai-key-wrap');
    const keyInput = document.getElementById('ai-api-key');
    const keyNote = document.getElementById('ai-key-note');
    const modelHint = document.getElementById('ai-model-hint');
    const modelOptions = document.getElementById('ai-model-options');
    const stepsTitle = document.getElementById('ai-steps-title');
    const stepsList = document.getElementById('ai-steps');
    const result = document.getElementById('ai-result');
    const savedProvider = providerSelect.value;
    const savedKeyPlaceholder = keyInput.placeholder;

    function updateProviderHints() {
        const meta = catalog[providerSelect.value];
        baseUrlInput.placeholder = meta.base_url_hint;
        baseUrlNote.textContent = meta.base_url ? '(optional, default shown)' : '(required)';
        keyWrap.style.display = meta.key === 'none' ? 'none' : 'block';
        keyNote.textContent = meta.key === 'required' ? '(required)' : '(optional)';
        // A saved key is only reused for the provider it was saved with.
        keyInput.placeholder = providerSelect.value === savedProvider ? savedKeyPlaceholder : 'Not set';
        modelHint.textContent = meta.model_hint;
        stepsTitle.textContent = 'Setup: ' + meta.label;
        stepsList.replaceChildren(...meta.steps.map((step) => {
            const item = document.createElement('li');
            item.textContent = step;
            return item;
        }));
        modelOptions.replaceChildren();
    }

    function showResult(ok, message) {
        result.style.display = 'block';
        result.style.background = ok ? '#ecfdf5' : '#fef2f2';
        result.style.border = '1px solid ' + (ok ? '#a7f3d0' : '#fecaca');
        result.style.color = ok ? '#065f46' : '#991b1b';
        result.textContent = message;
    }

    async function post(url) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: new FormData(form),
        });
        const data = await response.json().catch(() => ({}));
        if (response.status === 422 && data.errors) {
            return { ok: false, message: Object.values(data.errors).flat().join(' ') };
        }
        if (!response.ok && !data.message) {
            return { ok: false, message: 'Request failed (HTTP ' + response.status + ').' };
        }
        return data;
    }

    async function run(button, busyText, url, onDone) {
        const label = button.textContent;
        button.disabled = true;
        button.textContent = busyText;
        try {
            onDone(await post(url));
        } catch (error) {
            showResult(false, 'Request failed: ' + error.message);
        } finally {
            button.disabled = false;
            button.textContent = label;
        }
    }

    const testButton = document.getElementById('ai-test');
    if (testButton) {
        testButton.addEventListener('click', () => run(testButton, 'Testing...', form.dataset.testUrl, (data) => {
            showResult(data.ok, data.message + (data.ok && data.reply ? ' Reply: "' + data.reply + '"' : ''));
        }));
    }

    const loadModelsButton = document.getElementById('ai-load-models');
    if (loadModelsButton) {
        loadModelsButton.addEventListener('click', () => run(loadModelsButton, 'Loading...', form.dataset.modelsUrl, (data) => {
            showResult(data.ok, data.message);
            modelOptions.replaceChildren(...(data.models || []).map((id) => {
                const option = document.createElement('option');
                option.value = id;
                return option;
            }));
        }));
    }

    providerSelect.addEventListener('change', updateProviderHints);
    updateProviderHints();
})();

// Access URL: show the certificate fields for "own certificate", and run the DNS check.
(function () {
    var mode = document.getElementById('dv-mode');
    var custom = document.getElementById('dv-custom');
    if (mode && custom) {
        mode.addEventListener('change', function () { custom.style.display = mode.value === 'custom' ? '' : 'none'; });
    }
    var check = document.getElementById('dv-check');
    var result = document.getElementById('dv-check-result');
    if (check && result) {
        check.addEventListener('click', function () {
            result.classList.remove('hidden');
            result.textContent = 'Checking...';
            fetch(@json(route('admin.settings.domain.check')), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                body: JSON.stringify({ domain: document.getElementById('dv-domain').value, server_ip: document.getElementById('dv-ip').value })
            }).then(function (r) { return r.json(); }).then(function (data) {
                result.textContent = data.message + ' (This is the server\'s DNS view; other networks may differ.)';
                result.style.color = data.ok ? 'var(--ag-success)' : 'var(--ag-warning)';
            }).catch(function () { result.textContent = 'Could not run the check.'; });
        });
    }
})();
</script>
@endsection
