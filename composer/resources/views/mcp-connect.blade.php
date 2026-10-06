@extends('app')

@section('title', 'MCP - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px; max-width: 1100px;">
    <div style="margin-bottom: 20px;">
        <h1 style="font-size: 30px; color: var(--ag-text); margin-bottom: 6px;">MCP: connect AI tools</h1>
        <p style="font-size: 14px; color: var(--ag-muted); line-height: 1.6;">
            Ask an AI assistant about your servers, config backups and vulnerabilities, using this console's data.
            This uses MCP (Model Context Protocol), which Claude, VS Code, GitHub Copilot, n8n and many other tools support.
            It is read-only: the assistant can look, but cannot change anything.
        </p>
    </div>

    <div class="ag-card" style="padding: 24px;">
        @include('partials.mcp-connect', ['mcp' => $mcp])
    </div>
</div>
@endsection
