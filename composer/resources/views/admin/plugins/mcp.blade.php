{{-- Details panel of the MCP Server plugin. --}}
@php
    $mcpEnabled = \App\Support\McpControl::enabled();
    $mcpStatus = request('tab') === 'plugins' ? \App\Support\McpControl::status() : null;
    $mcpStatusLabel = ['running' => 'Server running', 'stopped' => 'Server stopped', 'unknown' => 'Server state unknown'][$mcpStatus] ?? null;
    $mcpStatusClass = ['running' => 'ag-badge--success', 'stopped' => '', 'unknown' => 'ag-badge--warning'][$mcpStatus] ?? '';
    $mcpMismatch = $mcpStatus !== null && $mcpStatus !== 'unknown' && ($mcpStatus === 'running') !== $mcpEnabled;
    $mcpInfo = \App\Support\McpConnect::info(request()->getHost());
@endphp
<p style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 10px; line-height: 1.6;">
    Runs a read-only MCP (Model Context Protocol) server at <code>http://&lt;host&gt;:8002/mcp</code>.
    AI tools such as Claude Code, Claude Desktop, VS Code, GitHub Copilot and n8n can then answer questions about systems, config backups, AI reviews and vulnerabilities.
    Each user connects with their own API key and sees only what they can see in this console. Secret-looking values are masked.
</p>

@if($mcpStatusLabel)
    <div style="font-size: 13px; margin-bottom: 12px;">
        <strong>Status:</strong> <span class="ag-badge {{ $mcpStatusClass }}">{{ $mcpStatusLabel }}</span>
    </div>
@endif

@if($mcpStatus === 'unknown')
    <div class="ag-alert ag-alert--warning" style="margin-bottom: 12px;">
        <span>The console cannot reach the controller (<code>ce-atglance-controller</code>), so it cannot start or stop the MCP server. On Docker Compose, run <code>docker compose up -d</code> in the install folder to add it. On other platforms, start or stop the MCP service there.</span>
    </div>
@elseif($mcpMismatch)
    <div class="ag-alert ag-alert--warning" style="margin-bottom: 12px;">
        <span>The MCP server is {{ $mcpStatus === 'running' ? 'running although the plugin is disabled' : 'stopped although the plugin is enabled' }}. The console corrects this within a minute.</span>
    </div>
@endif

@if(!$mcpEnabled)
    <p style="font-size: 13px; color: var(--ag-muted);">Enable the plugin to start the MCP server. Every user then gets an <strong>MCP</strong> link in the top bar with the setup steps for their AI tools.</p>
@else
    <div style="display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px 14px; font-size: 13px; margin-bottom: 12px;">
        <span style="color: var(--ag-muted);">MCP URL</span><code style="overflow-wrap: anywhere;">{{ $mcpInfo['primary'] }}</code>
        <span style="color: var(--ag-muted);">Private IP URL</span>
        @if($mcpInfo['fallback'])
            <code style="overflow-wrap: anywhere;">{{ $mcpInfo['fallback'] }}</code>
        @else
            <span style="color: var(--ag-warning);">Unknown. Set the server's private IP in <a href="{{ route('admin.settings', ['tab' => 'site']) }}" style="color: var(--ag-teal); text-decoration: underline;">Site Configuration</a> so users get a working fallback address.</span>
        @endif
        <span style="color: var(--ag-muted);">Users</span><span>See the <strong>MCP</strong> link in the top bar: <a href="{{ route('mcp.connect') }}" style="color: var(--ag-teal); text-decoration: underline;">setup page</a>.</span>
    </div>
    <p style="font-size: 12px; color: var(--ag-muted); line-height: 1.6;">
        Port 8002 is plain HTTP and the API key is sent with every request. Use it on your own network, or put HTTPS in front before reaching it from the internet.
        Disabling the plugin stops the MCP server right away; connected AI tools lose access.
    </p>
@endif
