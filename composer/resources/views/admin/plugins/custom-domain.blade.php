{{-- Details panel of the Custom Domain & HTTPS plugin. Expects $domainView. --}}
@php
    $dv = $domainView;
    $dvSeen = $dv['proxy_seen'];
    $dvStatus = isset($dvSeen['builtin'])
        ? 'Built-in proxy detected (' . strtoupper($dvSeen['builtin']['scheme']) . ')'
        : (isset($dvSeen['platform']) ? 'Platform proxy detected (' . strtoupper($dvSeen['platform']['scheme']) . ')' : 'Not detected yet. Open the console through the proxy once to confirm.');
@endphp
<p style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 10px;">
    Open the console on your own domain, e.g. <code>https://atglance.acme.com</code>. Nothing changes on the server until you follow the steps below.
    <code>http://server-IP:8000</code> keeps working at all times.
</p>

@if(!$dv['plugin_enabled'])
    <p style="font-size: 13px; color: var(--ag-muted);">Enable the plugin to see the setup steps and the proxy status.</p>
@else
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
@endif
