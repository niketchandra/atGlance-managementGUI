<?php

namespace App\Support;

/**
 * Addresses and copy-paste client setup for the MCP server (<host>:8002/mcp),
 * shown on the MCP setup page (/connect-ai) and the Site Setting > MCP tab.
 */
class McpConnect
{
    public const PORT = 8002;

    public const KEY_PLACEHOLDER = 'atgla-YOUR_API_KEY';

    public const TOOLS = [
        'whoami' => 'Your user, role and visible workspaces',
        'get_dashboard' => 'Totals, AI errors and warnings, 7-day trend',
        'list_workspaces' => 'Workspaces with counts and schedules',
        'list_systems' => 'Registered servers (search by name, IP or tag)',
        'list_config_files' => 'Config backups, latest versions by default',
        'get_config_file' => 'One file with masked content and its AI review',
        'list_config_versions' => 'Version history of one file',
        'list_vulnerabilities' => 'Latest AI reviews with errors or warnings',
        'get_ai_check_progress' => 'Progress of a workspace Vulnerability Check',
    ];

    public const CLIENTS = [
        'claude-code' => 'Claude Code',
        'claude-desktop' => 'Claude Desktop',
        'vscode' => 'VS Code',
        'copilot' => 'GitHub Copilot',
        'n8n' => 'n8n',
        'apps' => 'Other apps (B2B)',
        'curl' => 'Test with curl',
    ];

    /**
     * primary: the URL to use (domain, else private IP, else the address the page was opened with).
     * fallback: the private-IP URL to try when the domain does not resolve, or null if unknown or the same.
     */
    public static function info(?string $requestHost): array
    {
        $domain = DomainSettings::domain();
        $ip = self::privateIp(DomainSettings::serverIp()) ?? self::privateIp((string) $requestHost);
        $requestHost = strtolower(trim((string) $requestHost));

        $primaryHost = $domain !== '' ? $domain : ($ip ?? ($requestHost !== '' ? $requestHost : 'localhost'));
        $primary = self::url($primaryHost);
        $fallback = $ip !== null && $ip !== $primaryHost ? self::url($ip) : null;

        return [
            'primary' => $primary,
            'primary_host' => $primaryHost,
            'domain' => $domain,
            'private_ip' => $ip,
            'fallback' => $fallback,
            'local' => self::url('localhost'),
            'key' => self::KEY_PLACEHOLDER,
            'snippets' => self::snippets($primary),
            'tools' => self::TOOLS,
            'clients' => self::CLIENTS,
        ];
    }

    public static function url(string $host): string
    {
        return 'http://' . $host . ':' . self::PORT . '/mcp';
    }

    /** Returns the address if it is a private (LAN) IPv4/IPv6 address, else null. */
    public static function privateIp(string $address): ?string
    {
        $ip = DomainSettings::stripPort($address);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        // Valid but rejected with NO_PRIV_RANGE = private range. Loopback is not reachable from other machines.
        $isPrivate = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;

        return $isPrivate ? $ip : null;
    }

    private static function snippets(string $url): array
    {
        $key = self::KEY_PLACEHOLDER;
        $json = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        $httpServer = ['type' => 'http', 'url' => $url, 'headers' => ['Authorization' => 'Bearer ' . $key]];
        $init = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}';
        $replace = ['__URL__' => $url, '__KEY__' => $key];

        return [
            'claude-code' => "claude mcp add --transport http atglance {$url} \\\n  --header \"Authorization: Bearer {$key}\"",
            'vscode' => json_encode([
                'servers' => ['atglance' => [
                    'type' => 'http',
                    'url' => $url,
                    'headers' => ['Authorization' => 'Bearer ${input:atglance-key}'],
                ]],
                'inputs' => [[
                    'type' => 'promptString',
                    'id' => 'atglance-key',
                    'description' => 'AtGlance API key (atgla-...)',
                    'password' => true,
                ]],
            ], $json),
            'claude-desktop' => json_encode([
                'mcpServers' => ['atglance' => [
                    'command' => 'npx',
                    'args' => ['-y', 'mcp-remote', $url, '--allow-http', '--header', 'Authorization:${AUTH_HEADER}'],
                    'env' => ['AUTH_HEADER' => 'Bearer ' . $key],
                ]],
            ], $json),
            'copilot-ide' => json_encode(['servers' => ['atglance' => $httpServer]], $json),
            'copilot-cli' => json_encode(['mcpServers' => ['atglance' => $httpServer + ['tools' => ['*']]]], $json),
            'generic' => json_encode(['mcpServers' => ['atglance' => ['url' => $url, 'headers' => ['Authorization' => 'Bearer ' . $key]]]], $json),
            'curl' => "curl -X POST {$url} \\\n  -H \"Authorization: Bearer {$key}\" \\\n  -H \"Content-Type: application/json\" \\\n  -H \"Accept: application/json, text/event-stream\" \\\n  -d '{$init}'",
            'python' => strtr(<<<'PY'
                # pip install "mcp>=2.3"
                import asyncio

                import httpx2
                from mcp import ClientSession
                from mcp.client.streamable_http import streamable_http_client

                URL = "__URL__"
                API_KEY = "__KEY__"


                async def main():
                    async with httpx2.AsyncClient(headers={"Authorization": f"Bearer {API_KEY}"}, timeout=60) as http:
                        async with streamable_http_client(URL, http_client=http) as (read, write):
                            async with ClientSession(read, write) as session:
                                await session.initialize()
                                tools = await session.list_tools()
                                print([tool.name for tool in tools.tools])
                                result = await session.call_tool("list_systems", {"limit": 10})
                                print(result.content[0].text)


                asyncio.run(main())
                PY, $replace),
            'typescript' => strtr(<<<'TS'
                // npm install @modelcontextprotocol/sdk   (save as client.mjs, run: node client.mjs)
                import { Client } from "@modelcontextprotocol/sdk/client/index.js";
                import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";

                const transport = new StreamableHTTPClientTransport(new URL("__URL__"), {
                  requestInit: { headers: { Authorization: "Bearer __KEY__" } },
                });
                const client = new Client({ name: "my-app", version: "1.0.0" });

                await client.connect(transport);
                const { tools } = await client.listTools();
                console.log(tools.map((tool) => tool.name));
                const result = await client.callTool({ name: "list_systems", arguments: { limit: 10 } });
                console.log(result.content[0].text);
                await client.close();
                TS, $replace),
        ];
    }
}
