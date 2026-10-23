{{--
    Why a licence file was refused, with what to do next.
    $licenceError: "E_CODE: message" (from LicenseException::display()) or a plain message.
--}}
@php
    $licenceErrorCode = preg_match('/^(E_[A-Z_]+):\s*(.*)$/s', (string) $licenceError, $m) === 1 ? $m[1] : null;
    $licenceErrorText = $licenceErrorCode ? $m[2] : (string) $licenceError;
@endphp
<div role="alert" data-licence-error="{{ $licenceErrorCode ?? 'other' }}"
     style="margin-top: 10px; padding: 12px 14px; border-radius: 12px; background: var(--ag-danger-soft); color: var(--ag-danger); font-size: 13px; line-height: 1.5;">
    <strong><i class="fas fa-circle-exclamation"></i> Licence file not accepted{{ $licenceErrorCode ? ' (' . $licenceErrorCode . ')' : '' }}</strong>
    <div style="margin-top: 4px;">{{ $licenceErrorText }}</div>

    @if($licenceErrorCode === 'E_INSTALL_ID')
        @php $agInstallId = \App\Support\License::instanceId(); @endphp
        <div style="margin-top: 10px; color: var(--ag-text);">
            The file is bound to a different Installation ID than this console's. This console's Installation ID is:
            <div style="display: flex; gap: 8px; align-items: center; margin: 6px 0;">
                <code id="licence-install-id" style="padding: 4px 8px; border-radius: 8px; background: var(--ag-surface); font-size: 13px;">{{ $agInstallId }}</code>
                <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm"
                        onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('licence-install-id').textContent.trim()); this.textContent = 'Copied';">
                    <i class="fas fa-copy"></i> Copy
                </button>
            </div>
            To fix it:
            <ol style="margin: 4px 0 0 18px; padding: 0;">
                <li>Open <a href="{{ \App\Support\License::portalUrl() }}" target="_blank" rel="noopener" style="text-decoration: underline;">atglance.live</a> &rsaquo; Licences.</li>
                <li>Enter the Installation ID above (or leave the field empty for a file that works on any console) and generate a new licence file.</li>
                <li>Upload the new file here within the time shown on atglance.live.</li>
            </ol>
            If you reinstalled or moved this console, its Installation ID changed: files made for the old installation do not work here.
        </div>
    @elseif($licenceErrorCode === 'E_ACTIVATION_WINDOW')
        <div style="margin-top: 6px; color: var(--ag-text);">Generate a new file on atglance.live and upload it right away.</div>
    @elseif($licenceErrorCode === 'E_KEY_ID' || $licenceErrorCode === 'E_MIN_VERSION' || $licenceErrorCode === 'E_VERSION')
        <div style="margin-top: 6px; color: var(--ag-text);">Update this console to the latest version, then upload the file again.</div>
    @elseif($licenceErrorCode === 'E_CLOCK' || $licenceErrorCode === 'E_ISSUED_FUTURE')
        <div style="margin-top: 6px; color: var(--ag-text);">Correct this server's date, time and time zone (NTP), then upload the file again.</div>
    @endif
</div>
