{{-- Notification tab body: channels on the left (tick to allow, click to open), the open channel's guide and connection on the right.
     Guides: NotificationSettings::GUIDES; fields: NotificationSettings::CREDENTIALS. --}}
@php
    $notifyCanEdit = (int) auth()->user()->rbac_id === 100;
    $notifyChannels = \App\Support\NotificationSettings::CHANNELS;
    $notifyGuides = \App\Support\NotificationSettings::GUIDES;
    $notifyAllowedNow = collect(array_keys($notifyChannels))->filter(fn ($channel) => \App\Support\NotificationSettings::isAllowed($channel))->values();
    $notifyOpen = isset($notifyChannels[request('channel')]) ? request('channel') : ($notifyAllowedNow->first() ?? array_key_first($notifyChannels));
    $notifyStep = 'margin: 0 0 4px 18px; padding: 0; font-size: 12px; color: var(--ag-subtle); line-height: 1.7;';
@endphp

<form method="POST" action="{{ route('admin.settings.notifications') }}">
    @csrf
    <fieldset {{ $notifyCanEdit ? '' : 'disabled' }} style="border: none; padding: 0; margin: 0; min-width: 0;">
        <div class="ag-split" data-split="channel">
            <nav class="ag-split-nav" aria-label="Notification channels">
                <div class="ag-split-hint">Tick to let workspace admins use a channel. Click a name to set it up.</div>
                @foreach($notifyChannels as $notifyChannel => $notifyMeta)
                    @php
                        $notifyAllowed = \App\Support\NotificationSettings::isAllowed($notifyChannel);
                        $notifyMissing = $notifyAllowed ? \App\Support\NotificationSettings::missingSetup($notifyChannel) : null;
                        [$stateText, $stateClass] = !$notifyMeta['available'] ? ['Coming soon', '']
                            : (!$notifyAllowed ? ['Off', ''] : ($notifyMissing === null ? ['Ready', 'is-on'] : ['Setup needed', 'is-bad']));
                    @endphp
                    <div class="ag-split-item {{ $notifyChannel === $notifyOpen ? 'is-open' : '' }}" data-key="{{ $notifyChannel }}">
                        @if($notifyMeta['available'])
                            <input type="hidden" name="allowed[{{ $notifyChannel }}]" value="0">
                            <input type="checkbox" name="allowed[{{ $notifyChannel }}]" value="1" {{ $notifyAllowed ? 'checked' : '' }} aria-label="Allow {{ $notifyMeta['label'] }}">
                        @else
                            <input type="checkbox" disabled aria-label="{{ $notifyMeta['label'] }} is not available yet">
                        @endif
                        <button type="button" class="ag-split-open" data-key="{{ $notifyChannel }}" aria-controls="notify-panel-{{ $notifyChannel }}" aria-expanded="{{ $notifyChannel === $notifyOpen ? 'true' : 'false' }}">
                            <span class="ag-split-name">{{ $notifyMeta['label'] }}</span>
                            <span class="ag-split-state {{ $stateClass }}">{{ $stateText }}</span>
                        </button>
                    </div>
                @endforeach
            </nav>

            <div style="min-width: 0;">
                @foreach($notifyChannels as $notifyChannel => $notifyMeta)
                    @php
                        $notifyAllowed = \App\Support\NotificationSettings::isAllowed($notifyChannel);
                        $notifyMissing = $notifyAllowed ? \App\Support\NotificationSettings::missingSetup($notifyChannel) : null;
                        $notifyGuide = $notifyGuides[$notifyChannel] ?? ['docs' => null, 'org' => [], 'group' => [], 'example' => '', 'notes' => []];
                        $notifyCredentials = \App\Support\NotificationSettings::CREDENTIALS[$notifyChannel] ?? [];
                    @endphp
                    <section class="ag-split-panel" id="notify-panel-{{ $notifyChannel }}" data-key="{{ $notifyChannel }}" style="display: {{ $notifyChannel === $notifyOpen ? 'block' : 'none' }};">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                            <h3 style="font-size: 16px; font-weight: 600;">{{ $notifyMeta['label'] }}</h3>
                            @if($notifyGuide['docs'])
                                <a href="{{ $notifyGuide['docs'] }}" target="_blank" rel="noopener" style="font-size: 12px; color: var(--ag-teal); text-decoration: underline;">Official guide <i class="fas fa-arrow-up-right-from-square" style="font-size: 10px;"></i></a>
                            @endif
                        </div>

                        @if(!$notifyMeta['available'])
                            <div class="ag-alert ag-alert--warning" style="margin-bottom: 12px;"><span>Not available yet: waiting for the provider's API details.</span></div>
                        @else
                            @if(!$notifyAllowed)
                                <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 10px;">Not allowed yet. Tick {{ $notifyMeta['label'] }} on the left so workspace admins can add groups for it. You can fill in the settings now.</p>
                            @endif

                            <div style="background: var(--ag-surface); border-radius: 12px; padding: 12px 14px; margin-bottom: 12px;">
                                <div style="font-size: 12px; font-weight: 600; margin-bottom: 6px;">1. Organization setup</div>
                                <ol style="{{ $notifyStep }}">
                                    @foreach($notifyGuide['org'] as $notifyLine)
                                        <li>{{ $notifyLine }}</li>
                                    @endforeach
                                </ol>
                            </div>

                            @if($notifyChannel === 'email')
                                <div style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 12px;">
                                    SMTP server: <strong>{{ \App\Support\NotificationSettings::mailConfigured() ? 'configured' : 'not configured' }}</strong>, on the
                                    <a href="{{ route('admin.settings', ['tab' => 'email']) }}" style="color: var(--ag-teal); text-decoration: underline;">Email Configuration</a> tab.
                                </div>
                            @endif

                            @if($notifyCredentials !== [])
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-bottom: 12px;">
                                    @foreach($notifyCredentials as $notifyKey => $notifyCredential)
                                        @php
                                            $notifyType = $notifyCredential['type'] ?? 'text';
                                            $notifySaved = \App\Support\NotificationSettings::credential($notifyKey);
                                        @endphp
                                        <div style="{{ $notifyType === 'checkbox' ? 'grid-column: 1 / -1;' : '' }}">
                                            @if($notifyType === 'checkbox')
                                                <label style="display: flex; gap: 8px; align-items: flex-start; font-size: 13px;">
                                                    <input type="checkbox" name="{{ $notifyKey }}" value="1" {{ filter_var(old($notifyKey, $notifySaved), FILTER_VALIDATE_BOOL) ? 'checked' : '' }} style="margin-top: 3px;">
                                                    <span>{{ $notifyCredential['label'] }}</span>
                                                </label>
                                            @else
                                                <label class="ag-label" for="notify-{{ $notifyKey }}">{{ $notifyCredential['label'] }}</label>
                                                @if($notifyType === 'select')
                                                    <select class="ag-select" id="notify-{{ $notifyKey }}" name="{{ $notifyKey }}" style="width: 100%;">
                                                        @foreach($notifyCredential['options'] as $notifyOption => $notifyOptionLabel)
                                                            <option value="{{ $notifyOption }}" {{ old($notifyKey, $notifySaved) === $notifyOption ? 'selected' : '' }}>{{ $notifyOptionLabel }}</option>
                                                        @endforeach
                                                    </select>
                                                @elseif($notifyCredential['secret'])
                                                    <input class="ag-input" id="notify-{{ $notifyKey }}" type="password" name="{{ $notifyKey }}" autocomplete="new-password" placeholder="{{ $notifySaved !== '' ? 'Saved. Leave blank to keep it.' : ($notifyCredential['placeholder'] ?? 'Not set') }}" style="width: 100%;">
                                                    @if($notifySaved !== '')
                                                        <label style="display: flex; gap: 6px; align-items: center; font-size: 12px; color: var(--ag-muted); margin-top: 4px;">
                                                            <input type="checkbox" name="clear[{{ $notifyKey }}]" value="1"> Remove saved value
                                                        </label>
                                                    @endif
                                                @else
                                                    <input class="ag-input" id="notify-{{ $notifyKey }}" type="text" name="{{ $notifyKey }}" value="{{ old($notifyKey, $notifySaved) }}" placeholder="{{ $notifyCredential['placeholder'] ?? '' }}" style="width: 100%;">
                                                @endif
                                            @endif
                                            @if(!empty($notifyCredential['help']))
                                                <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px; {{ $notifyType === 'checkbox' ? 'margin-left: 22px;' : '' }}">{{ $notifyCredential['help'] }}</div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if($notifyMissing)
                                <div class="ag-alert ag-alert--error" style="margin-bottom: 12px;"><span>{{ $notifyMissing }}</span></div>
                            @endif

                            <div style="border: 1px dashed var(--ag-line); border-radius: 12px; padding: 12px 14px; margin-bottom: 8px;">
                                <div style="font-size: 12px; font-weight: 600; margin-bottom: 6px;">2. Each workspace group</div>
                                <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 6px;">
                                    Workspace admins add groups on the <a href="{{ route('admin.notifications') }}" style="color: var(--ag-teal); text-decoration: underline;">Notifications</a> page. A {{ $notifyMeta['label'] }} group's target is: {{ $notifyMeta['target'] }}.
                                </p>
                                @if($notifyGuide['group'] !== [])
                                    <ol style="{{ $notifyStep }}">
                                        @foreach($notifyGuide['group'] as $notifyLine)
                                            <li>{{ $notifyLine }}</li>
                                        @endforeach
                                    </ol>
                                @endif
                                @if($notifyGuide['example'] !== '')
                                    <div style="font-size: 12px; margin-top: 6px;"><span style="color: var(--ag-muted);">Example:</span> <code style="overflow-wrap: anywhere;">{{ $notifyGuide['example'] }}</code></div>
                                @endif
                            </div>

                            @if($notifyGuide['notes'] !== [])
                                <ul style="margin: 8px 0 0 18px; padding: 0; font-size: 12px; color: var(--ag-muted); line-height: 1.6;">
                                    @foreach($notifyGuide['notes'] as $notifyLine)
                                        <li>{{ $notifyLine }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                    </section>
                @endforeach

                @if($notifyCanEdit)
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 14px;">
                        <button class="ag-btn" type="submit">Save Notification Channels</button>
                        <span style="font-size: 12px; color: var(--ag-muted);">Saves every channel, including the ticks on the left.</span>
                    </div>
                @endif
            </div>
        </div>
    </fieldset>
</form>
