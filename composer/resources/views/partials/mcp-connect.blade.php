{{-- Client setup for the MCP server. Needs $mcp = \App\Support\McpConnect::info(request()->getHost()). --}}
@php
    $mcpPre = 'margin: 0; padding: 12px 14px; background: var(--ag-surface); border: 1px solid var(--ag-line); border-radius: 12px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; line-height: 1.55; color: var(--ag-text); white-space: pre; overflow-x: auto;';
    $mcpStep = 'font-size: 13px; color: var(--ag-subtle); line-height: 1.6;';
    $mcpH = 'font-size: 13px; font-weight: 600; margin-bottom: 6px;';
    $mcpSection = 'font-size: 16px; font-weight: 600; margin: 22px 0 10px;';
    $mcpKey = $mcp['key'];
    $mcpIsSuperAdmin = (int) (auth()->user()->rbac_id ?? 0) === 100;
@endphp

<div id="mcp-connect" data-primary="{{ $mcp['primary'] }}">
    <h3 style="{{ $mcpSection }} margin-top: 0;">1. Create an API key</h3>
    <p style="{{ $mcpStep }}">
        In <a href="{{ route('settings') }}" style="color: var(--ag-teal); text-decoration: underline;">Settings &gt; API Keys</a>, create a key. It starts with <code>atgla-</code>.
        Use your own key: the AI client sees exactly what you see in this console, no more.
        In the examples below, replace <code>{{ $mcpKey }}</code> with your key.
    </p>

    <h3 style="{{ $mcpSection }}">2. Server address</h3>
    <div style="display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 8px 14px; align-items: center; font-size: 13px;">
        <span style="color: var(--ag-muted);">MCP URL</span>
        <div style="display: flex; gap: 8px; align-items: center; min-width: 0; flex-wrap: wrap;">
            <code id="mcp-url" class="mcp-u" style="overflow-wrap: anywhere;">{{ $mcp['primary'] }}</code>
            <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm mcp-copy" data-copy="mcp-url">Copy</button>
        </div>
        <span style="color: var(--ag-muted);">Header</span>
        <code style="overflow-wrap: anywhere;">Authorization: Bearer {{ $mcpKey }}</code>
        <span style="color: var(--ag-muted);">Transport</span>
        <span>Streamable HTTP</span>
        <span style="color: var(--ag-muted);">Access</span>
        <span>Read-only. Secret-looking values in config files are shown as <code>********</code>.</span>
    </div>

    <div style="margin-top: 14px; padding: 14px; border-radius: 12px; background: var(--ag-surface); font-size: 13px; line-height: 1.6;">
        <strong>If {{ $mcp['domain'] !== '' ? $mcp['domain'] : 'this address' }} does not work, use the server's private IP:</strong>
        <div style="margin: 6px 0;">
            @if($mcp['fallback'])
                <code id="mcp-url-fallback" style="overflow-wrap: anywhere;">{{ $mcp['fallback'] }}</code>
                <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm mcp-copy" data-copy="mcp-url-fallback">Copy</button>
            @else
                <code>http://&lt;private_ip&gt;:8002/mcp</code>
            @endif
        </div>
        @if($mcp['fallback'])
            <div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: center; margin: 4px 0 8px;" role="radiogroup" aria-label="Address used in the examples">
                <span style="color: var(--ag-muted);">Examples below use:</span>
                <label style="display: flex; gap: 6px; align-items: center; cursor: pointer;"><input type="radio" name="mcp-address" class="mcp-address" value="{{ $mcp['primary'] }}" checked> {{ $mcp['primary_host'] }}</label>
                <label style="display: flex; gap: 6px; align-items: center; cursor: pointer;"><input type="radio" name="mcp-address" class="mcp-address" value="{{ $mcp['fallback'] }}"> {{ $mcp['private_ip'] }}</label>
            </div>
        @endif
        <ul style="margin: 0 0 0 18px; padding: 0; color: var(--ag-subtle);">
            @unless($mcp['fallback'])
                <li>
                    Find the private IP on the console server: Linux <code>hostname -I</code> (first address), Windows <code>ipconfig</code> (IPv4 Address), macOS <code>ipconfig getifaddr en0</code>.
                    @if($mcpIsSuperAdmin)
                        Save it as the server IP in <a href="{{ route('admin.settings', ['tab' => 'site']) }}" style="color: var(--ag-teal); text-decoration: underline;">Site Configuration</a> and this page shows the full address.
                    @else
                        Or ask your administrator.
                    @endif
                </li>
            @endunless
            <li>The private IP only works on the same network as the console, or over your VPN.</li>
            <li>On the console server itself you can use <code>{{ $mcp['local'] }}</code>.</li>
            <li>Port 8002 is always plain <code>http://</code>. <code>https://</code> and the console's web address without <code>:8002</code> do not serve MCP.</li>
        </ul>
    </div>

    <h3 style="{{ $mcpSection }}">3. Connect your client</h3>
    <nav class="ag-tabs" aria-label="MCP clients" style="margin-bottom: 14px;">
        @foreach($mcp['clients'] as $mcpClient => $mcpLabel)
            <button type="button" class="ag-tab mcp-client-btn {{ $loop->first ? 'active' : '' }}" data-client="{{ $mcpClient }}">{{ $mcpLabel }}</button>
        @endforeach
    </nav>

    <div class="mcp-client-panel" data-client="claude-code">
        <p style="{{ $mcpStep }} margin-bottom: 8px;">Run in a terminal. Add <code>--scope user</code> to use it in every project. Check with <code>claude mcp list</code>, or <code>/mcp</code> inside Claude Code.</p>
        <pre id="mcp-snippet-claude-code" class="mcp-u" style="{{ $mcpPre }}">{{ $mcp['snippets']['claude-code'] }}</pre>
        <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm mcp-copy" data-copy="mcp-snippet-claude-code" style="margin-top: 8px;">Copy</button>
    </div>

    <div class="mcp-client-panel" data-client="claude-desktop" style="display: none;">
        <p style="{{ $mcpStep }} margin-bottom: 8px;">
            Needs Node.js. In Claude Desktop open <strong>Settings &gt; Developer &gt; Edit Config</strong>, add this to <code>claude_desktop_config.json</code>, then restart Claude Desktop.
            <code>mcp-remote</code> connects Claude Desktop to this server; <code>--allow-http</code> is needed because port 8002 is plain HTTP.
        </p>
        <pre id="mcp-snippet-claude-desktop" class="mcp-u" style="{{ $mcpPre }}">{{ $mcp['snippets']['claude-desktop'] }}</pre>
        <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm mcp-copy" data-copy="mcp-snippet-claude-desktop" style="margin-top: 8px;">Copy</button>
    </div>

    <div class="mcp-client-panel" data-client="vscode" style="display: none;">
        <p style="{{ $mcpStep }} margin-bottom: 8px;">Save as <code>.vscode/mcp.json</code> in your project, or run <strong>MCP: Open User Configuration</strong> to use it in every project. VS Code asks for the API key the first time and stores it securely. GitHub Copilot Chat in <strong>Agent</strong> mode then lists the AtGlance tools.</p>
        <pre id="mcp-snippet-vscode" class="mcp-u" style="{{ $mcpPre }}">{{ $mcp['snippets']['vscode'] }}</pre>
        <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm mcp-copy" data-copy="mcp-snippet-vscode" style="margin-top: 8px;">Copy</button>
    </div>

    <div class="mcp-client-panel" data-client="copilot" style="display: none;">
        <p style="{{ $mcpStep }} margin-bottom: 12px;">Copilot uses MCP tools in <strong>Agent</strong> mode. Pick where you use Copilot:</p>

        <h4 style="{{ $mcpH }}">VS Code</h4>
        <p style="{{ $mcpStep }} margin-bottom: 14px;">Use the <strong>VS Code</strong> tab: Copilot Chat reads the same <code>.vscode/mcp.json</code>.</p>

        <h4 style="{{ $mcpH }}">Visual Studio or JetBrains IDEs</h4>
        <p style="{{ $mcpStep }} margin-bottom: 8px;">
            Visual Studio 2022 (17.14 or newer): save as <code>.mcp.json</code> in the solution folder, or <code>%USERPROFILE%\.mcp.json</code> for all solutions.
            JetBrains: in the Copilot Chat window choose <strong>Agent</strong>, open the tools menu, then <strong>Add more tools</strong> to edit <code>mcp.json</code>.
        </p>
        <pre id="mcp-snippet-copilot-ide" class="mcp-u" style="{{ $mcpPre }}">{{ $mcp['snippets']['copilot-ide'] }}</pre>
        <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm mcp-copy" data-copy="mcp-snippet-copilot-ide" style="margin: 8px 0 14px;">Copy</button>

        <h4 style="{{ $mcpH }}">Copilot CLI</h4>
        <p style="{{ $mcpStep }} margin-bottom: 8px;">Add to <code>~/.copilot/mcp-config.json</code>, or run <code>/mcp add</code> inside <code>copilot</code> and enter the same URL and header.</p>
        <pre id="mcp-snippet-copilot-cli" class="mcp-u" style="{{ $mcpPre }}">{{ $mcp['snippets']['copilot-cli'] }}</pre>
        <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm mcp-copy" data-copy="mcp-snippet-copilot-cli" style="margin: 8px 0 14px;">Copy</button>

        <h4 style="{{ $mcpH }}">Copilot coding agent on GitHub.com</h4>
        <p style="{{ $mcpStep }}">
            Runs on GitHub's servers, so it can only reach a console with a public HTTPS address, not a private IP or a name that only works on your network.
            With one: repository <strong>Settings &gt; Copilot &gt; Coding agent &gt; MCP configuration</strong>, the same JSON as Copilot CLI, with the key stored as an environment secret instead of in the file.
        </p>
    </div>

    <div class="mcp-client-panel" data-client="n8n" style="display: none;">
        <ol style="margin: 0 0 0 18px; padding: 0; {{ $mcpStep }}">
            <li>Add an <strong>AI Agent</strong> node, then attach an <strong>MCP Client Tool</strong> to its Tool input.</li>
            <li><strong>Endpoint:</strong> <code class="mcp-u">{{ $mcp['primary'] }}</code></li>
            <li><strong>Server Transport:</strong> HTTP Streamable</li>
            <li><strong>Authentication:</strong> Bearer Auth. Create a credential and paste the API key, without <code>Bearer</code>.</li>
            <li><strong>Tools to Include:</strong> All</li>
        </ol>
        <p style="font-size: 12px; color: var(--ag-muted); margin-top: 10px;">
            If n8n runs in Docker, <code>localhost</code> and names from your own hosts file do not work inside the n8n container. Use the private IP address.
        </p>
    </div>

    <div class="mcp-client-panel" data-client="apps" style="display: none;">
        <p style="{{ $mcpStep }} margin-bottom: 12px;">
            Any product that supports MCP (agent platforms, chatbots, ITSM or monitoring tools, your own services) connects the same way. Give it these details:
        </p>
        <div style="display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px 14px; font-size: 13px; margin-bottom: 14px;">
            <span style="color: var(--ag-muted);">Protocol</span><span>Model Context Protocol, Streamable HTTP transport</span>
            <span style="color: var(--ag-muted);">URL</span><code class="mcp-u" style="overflow-wrap: anywhere;">{{ $mcp['primary'] }}</code>
            <span style="color: var(--ag-muted);">Auth</span><code style="overflow-wrap: anywhere;">Authorization: Bearer {{ $mcpKey }}</code>
            <span style="color: var(--ag-muted);">Access</span><span>Read-only. Nothing can be changed through MCP.</span>
            <span style="color: var(--ag-muted);">Limit</span><span>About 120 tool calls a minute per user.</span>
        </div>

        <h4 style="{{ $mcpH }}">Apps with an MCP settings file</h4>
        <p style="{{ $mcpStep }} margin-bottom: 8px;">Most apps take an <code>mcpServers</code> entry like this. Some call the URL field <code>serverUrl</code> or ask for the transport (<code>http</code> or <code>streamable-http</code>).</p>
        <pre id="mcp-snippet-generic" class="mcp-u" style="{{ $mcpPre }}">{{ $mcp['snippets']['generic'] }}</pre>
        <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm mcp-copy" data-copy="mcp-snippet-generic" style="margin: 8px 0 14px;">Copy</button>

        <h4 style="{{ $mcpH }}">Your own code: Python</h4>
        <pre id="mcp-snippet-python" class="mcp-u" style="{{ $mcpPre }}">{{ $mcp['snippets']['python'] }}</pre>
        <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm mcp-copy" data-copy="mcp-snippet-python" style="margin: 8px 0 14px;">Copy</button>

        <h4 style="{{ $mcpH }}">Your own code: TypeScript / Node.js</h4>
        <pre id="mcp-snippet-typescript" class="mcp-u" style="{{ $mcpPre }}">{{ $mcp['snippets']['typescript'] }}</pre>
        <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm mcp-copy" data-copy="mcp-snippet-typescript" style="margin: 8px 0 14px;">Copy</button>

        <h4 style="{{ $mcpH }}">For a business integration</h4>
        <ul style="margin: 0 0 0 18px; padding: 0; {{ $mcpStep }}">
            <li>Create a dedicated user for the integration (for example <code>svc-helpdesk</code>) and add it only to the workspaces it needs. The integration sees exactly what that user sees.</li>
            <li>Give each integration its own API key, so you can revoke one without breaking the others.</li>
            <li>Store the key in the app's secret store, not in code or chat messages.</li>
            <li>An app outside your network, or any cloud AI service, needs a public HTTPS address in front of port 8002. Plain HTTP sends the key unencrypted.</li>
        </ul>
    </div>

    <div class="mcp-client-panel" data-client="curl" style="display: none;">
        <p style="{{ $mcpStep }} margin-bottom: 8px;">Checks that the server is reachable from your machine. A working server answers with <code>event: message</code> and a line that names <code>atglance</code>. The API key is checked later, when a tool runs.</p>
        <pre id="mcp-snippet-curl" class="mcp-u" style="{{ $mcpPre }}">{{ $mcp['snippets']['curl'] }}</pre>
        <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm mcp-copy" data-copy="mcp-snippet-curl" style="margin-top: 8px;">Copy</button>
        <p style="font-size: 12px; color: var(--ag-muted); margin-top: 10px;">Opening the URL in a browser shows <code>: ping</code> lines every 15 seconds. That is normal: the server keeps the stream open.</p>
    </div>

    <h3 style="{{ $mcpSection }}">What you can ask</h3>
    <p style="{{ $mcpStep }} margin-bottom: 8px;">For example: "Which servers have AI errors?", "Show the sshd config on web-01 and its review", "What changed in nginx.conf on pi-01?", "Summarise this week's vulnerabilities by workspace."</p>
    <div class="ag-table-wrap">
        <table class="ag-table" style="font-size: 13px;">
            <thead><tr><th>Tool</th><th>Returns</th></tr></thead>
            <tbody>
                @foreach($mcp['tools'] as $mcpTool => $mcpToolInfo)
                    <tr><td><code>{{ $mcpTool }}</code></td><td>{{ $mcpToolInfo }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h3 style="{{ $mcpSection }}">Troubleshooting</h3>
    <div class="ag-table-wrap">
        <table class="ag-table" style="font-size: 13px;">
            <thead><tr><th>You see</th><th>What to do</th></tr></thead>
            <tbody>
                <tr>
                    <td>"Could not resolve host", "ENOTFOUND", or the name does not open</td>
                    <td>
                        Your machine does not know the name {{ $mcp['domain'] !== '' ? $mcp['domain'] : '' }}. Use the private IP address above.
                        Or add a line <code>&lt;private_ip&gt; {{ $mcp['domain'] !== '' ? $mcp['domain'] : 'console-name' }}</code> to your hosts file
                        (Windows <code>C:\Windows\System32\drivers\etc\hosts</code>, Linux and macOS <code>/etc/hosts</code>), or ask your administrator for a DNS record.
                    </td>
                </tr>
                <tr>
                    <td>"Connection refused", "ECONNREFUSED", or a timeout</td>
                    <td>
                        Port 8002 is not reachable. Check you are on the same network or VPN as the console.
                        On the console server, allow TCP port 8002 in the firewall (for example <code>sudo ufw allow 8002/tcp</code>).
                    </td>
                </tr>
                <tr>
                    <td>HTTP 500, 502 or 503</td>
                    <td>The MCP server is not running. It may have just been turned on: wait 5 seconds and try again. Otherwise ask your administrator to enable the MCP Server plugin in Site Setting &gt; Plugins.</td>
                </tr>
                <tr>
                    <td>"The console rejected the API key"</td>
                    <td>The key is wrong, revoked or expired, or the header is missing <code>Bearer </code>. Create a new key in Settings &gt; API Keys.</td>
                </tr>
                <tr>
                    <td>Tools work but return nothing</td>
                    <td>Your user is not a member of the workspace. MCP shows only the workspaces you can open in this console.</td>
                </tr>
                <tr>
                    <td>Works on your PC but not from n8n or another container</td>
                    <td><code>localhost</code> and hosts-file names do not work inside containers. Use the private IP address.</td>
                </tr>
                <tr>
                    <td>Works on your network but not from a cloud service</td>
                    <td>Cloud services (GitHub Copilot coding agent, hosted AI platforms) cannot reach a private address. The console needs a public HTTPS address in front of port 8002.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 18px; padding: 12px; border-radius: 12px; background: var(--ag-warning-soft); color: var(--ag-warning); font-size: 12px; line-height: 1.6;">
        Port 8002 is plain HTTP and the API key is sent with every request. Use it on your own network only, or put HTTPS in front before reaching it from the internet. If a key leaks, revoke it in Settings &gt; API Keys.
    </div>
</div>

<script>
(function () {
    var root = document.getElementById('mcp-connect');
    if (!root) { return; }
    root.querySelectorAll('.mcp-client-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            root.querySelectorAll('.mcp-client-btn').forEach(function (b) { b.classList.toggle('active', b === btn); });
            root.querySelectorAll('.mcp-client-panel').forEach(function (p) { p.style.display = p.dataset.client === btn.dataset.client ? 'block' : 'none'; });
        });
    });
    var primary = root.dataset.primary;
    root.querySelectorAll('.mcp-u').forEach(function (el) { el.dataset.original = el.textContent; });
    root.querySelectorAll('.mcp-address').forEach(function (radio) {
        radio.addEventListener('change', function () {
            root.querySelectorAll('.mcp-u').forEach(function (el) { el.textContent = el.dataset.original.split(primary).join(radio.value); });
        });
    });
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(text); }
        var area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = document.execCommand('copy');
        document.body.removeChild(area);
        return ok ? Promise.resolve() : Promise.reject();
    }
    root.querySelectorAll('.mcp-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var source = document.getElementById(btn.dataset.copy);
            copyText(source ? source.textContent : '').then(function () {
                btn.textContent = 'Copied';
            }, function () {
                btn.textContent = 'Copy failed';
            }).then(function () {
                setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
            });
        });
    });
})();
</script>
