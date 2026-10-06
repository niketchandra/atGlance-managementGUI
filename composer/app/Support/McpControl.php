<?php

namespace App\Support;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Starts and stops the ce-atglance-mcp container through the controller sidecar (ce-atglance-controller)
 * (docker-compose.yml), which only forwards start, stop and inspect of that container.
 */
class McpControl
{
    public const RUNNING = 'running';
    public const STOPPED = 'stopped';
    public const UNKNOWN = 'unknown';

    public static function enabled(): bool
    {
        return AdminSetting::getValue('mcp_enabled', 'false') === 'true';
    }

    public static function setEnabled(bool $enabled): void
    {
        AdminSetting::putValue('mcp', 'mcp_enabled', $enabled ? 'true' : 'false');
    }

    /** running, stopped, or unknown when the controller is not reachable (e.g. no Docker socket). */
    public static function status(): string
    {
        try {
            $response = Http::timeout(2)->get(self::url('/mcp/status'));
        } catch (Throwable) {
            return self::UNKNOWN;
        }

        if (!$response->successful()) {
            return self::UNKNOWN;
        }

        return $response->json('State.Running') === true ? self::RUNNING : self::STOPPED;
    }

    /** Starts or stops the container to match the setting. Returns null on success, else an error message. */
    public static function apply(?bool $enabled = null): ?string
    {
        $enabled ??= self::enabled();

        try {
            $response = Http::timeout(20)->post(self::url($enabled ? '/mcp/start' : '/mcp/stop'));
        } catch (Throwable $e) {
            return 'MCP control is not reachable: ' . $e->getMessage();
        }

        // 204 = done, 304 = already in that state.
        if (in_array($response->status(), [204, 304], true)) {
            return null;
        }

        return 'MCP control answered ' . $response->status() . ': ' . trim((string) $response->json('message', $response->body()));
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('services.controller.url'), '/') . $path;
    }
}
