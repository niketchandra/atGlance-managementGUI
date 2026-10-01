<?php

namespace App\Services;

use RuntimeException;

/**
 * Asks the configured AI provider to review one uploaded configuration file.
 *
 * Secret-looking values are masked before the file leaves the console, and the
 * file is cut at MAX_CHARS so a large file cannot run up a large bill.
 */
class ConfigAiValidator
{
    public const MAX_CHARS = 30000;
    private const MAX_TOKENS = 8192;
    private const TIMEOUT_SECONDS = 180;
    private const MASK = '********';

    private const SYSTEM_PROMPT = <<<'PROMPT'
You audit Linux service configuration files (systemd units, sshd, nginx, apache, cron, mysql, redis, etc.)
against widely accepted standards:
- CIS Benchmarks for the OS and the service
- DISA STIG
- NIST SP 800-53 / SP 800-123
- Mozilla Server Side TLS (for TLS settings)
- the vendor's own documentation and man pages (for syntax, deprecated and unknown directives)
Check syntax, values that stop the service from starting, deprecated directives, insecure defaults and missing hardening.
For every finding name the standard it comes from by name only (for example "CIS Benchmark", "DISA STIG", "systemd.exec(5)"). Do not give control, rule or section numbers.
Only report what you are confident about. Do not report:
- several ExecStartPre=, ExecStartPost=, ExecReload= or ExecStop= lines in a systemd unit; they are valid and run in order
- settings a Linux distribution ships on purpose (for example KillMode=process in Debian's ssh.service, which keeps open sessions alive across restarts), unless a standard requires a change; then explain the side effect
A fix must not break the service or cut off remote access. Mention any side effect in "suggestion".
For every finding:
- "details": explain the problem in detail, in plain language
- "impact": what can happen if it is left as it is
- "fix": the exact replacement or added line(s), in the file's own syntax, or "" when nothing should be added
- "steps": ordered steps to apply the fix safely and to verify it (for example a systemd drop-in with systemctl edit, a syntax test such as sshd -t or nginx -t, the reload command, and how to roll back)
Also describe the file and the threats to this kind of service:
- "description": "overview" says what this configuration does in plain language; "settings" explains the important settings in the file, one entry per setting
- "threats": the main known threats to this type of service in general (for example brute force, privilege escalation, data exposure, denial of service), each with its impact and the usual mitigation, and whether this file already mitigates it
Values shown as ******** were masked on purpose; do not report them as errors.
Answer with JSON only, no other text, in this shape:
{"status": "ok" | "warning" | "error",
 "summary": "one or two sentences",
 "description": {"overview": "...", "settings": [{"setting": "Name=value", "meaning": "..."}]},
 "threats": [{"threat": "...", "impact": "...", "mitigation": "...", "mitigated_here": true | false}],
 "findings": [{"severity": "info" | "warning" | "error", "line": <line number or null>, "standard": "e.g. CIS Benchmark", "issue": "short title", "details": "...", "impact": "...", "suggestion": "why and how to fix it, with side effects", "fix": "exact line(s) to use", "steps": ["step 1", "step 2"]}]}
Use "ok" with an empty findings list when the file already meets these standards; still fill "description" and "threats".
PROMPT;

    public function __construct(private readonly AiClient $client)
    {
    }

    /**
     * @return array{status: string, summary: string, description: array{overview: string, settings: array}, threats: array, findings: array<int, array{severity: string, line: int|null, issue: string, details: string, impact: string, suggestion: string, standard: string, fix: string, steps: array<int, string>}>, truncated: bool, masked: int}
     *
     * @throws RuntimeException with a message that is safe to show.
     */
    public function validate(array $connection, string $fileName, ?string $serviceName, string $content): array
    {
        $truncated = mb_strlen($content) > self::MAX_CHARS;
        if ($truncated) {
            $content = mb_substr($content, 0, self::MAX_CHARS);
        }

        [$content, $masked] = $this->maskSecrets($content);

        $prompt = 'File name: ' . $fileName . "\n"
            . 'Service: ' . ($serviceName ?: 'unknown') . "\n"
            . ($truncated ? 'Note: the file was cut at ' . self::MAX_CHARS . " characters.\n" : '')
            . "Content (line numbers added):\n"
            . $this->numberLines($content);

        $reply = $this->client->complete($connection, $prompt, self::MAX_TOKENS, self::SYSTEM_PROMPT, self::TIMEOUT_SECONDS);

        return $this->parse($reply) + ['truncated' => $truncated, 'masked' => $masked];
    }

    /**
     * Replaces the value of key=value / key: value / "key value" lines whose key looks secret.
     *
     * @return array{0: string, 1: int}
     */
    public function maskSecrets(string $content): array
    {
        $count = 0;
        $masked = preg_replace_callback(
            '/^(\s*[#;]?\s*[\w.\-]*(?:pass(?:word|wd)?|secret|token|api[_-]?key|private[_-]?key|credential)[\w.\-]*\s*(?:=|:|\s)\s*)(\S.*)$/im',
            function (array $m) use (&$count) {
                // Settings such as "PasswordAuthentication no" are not secrets and matter for the review.
                if (preg_match('/^["\']?(yes|no|true|false|on|off|none|\d+)["\']?\s*$/i', $m[2])) {
                    return $m[0];
                }
                $count++;

                return $m[1] . self::MASK;
            },
            $content
        );

        return [$masked ?? $content, $count];
    }

    private function numberLines(string $content): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $content);

        return collect($lines)
            ->map(fn (string $line, int $i) => str_pad((string) ($i + 1), 4, ' ', STR_PAD_LEFT) . ' | ' . $line)
            ->implode("\n");
    }

    /**
     * Small models sometimes drop a closing brace, e.g. `..."}]}` written as `..."]}`.
     * Inserts the missing closers so the answer can still be read.
     */
    private function balanceBrackets(string $json): string
    {
        $out = '';
        $stack = [];
        $inString = false;
        $escaped = false;
        $pairs = ['{' => '}', '[' => ']'];

        foreach (mb_str_split($json) as $char) {
            if ($inString) {
                $out .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif (isset($pairs[$char])) {
                $stack[] = $char;
            } elseif ($char === '}' || $char === ']') {
                while ($stack !== [] && $pairs[end($stack)] !== $char) {
                    $out .= $pairs[array_pop($stack)];
                }
                array_pop($stack);
            }
            $out .= $char;
        }

        while ($stack !== []) {
            $out .= $pairs[array_pop($stack)];
        }

        return $out;
    }

    /**
     * Reads the JSON answer; models sometimes wrap it in a code fence or add text around it.
     */
    private function parse(string $reply): array
    {
        $text = trim($reply);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = null;
        if ($start !== false && $end !== false && $end > $start) {
            $json = substr($text, $start, $end - $start + 1);
            $data = json_decode($json, true) ?? json_decode($this->balanceBrackets($json), true);
        }

        if (!is_array($data)) {
            if ($text === '') {
                throw new RuntimeException('The AI provider returned an empty answer. Try again or use a larger model.');
            }

            return ['status' => 'unknown', 'summary' => mb_substr($text, 0, 4000), 'description' => ['overview' => '', 'settings' => []], 'threats' => [], 'findings' => []];
        }

        $status = in_array($data['status'] ?? null, ['ok', 'warning', 'error'], true) ? $data['status'] : 'unknown';

        $findings = collect(is_array($data['findings'] ?? null) ? $data['findings'] : [])
            ->filter(fn ($f) => is_array($f))
            ->map(fn (array $f) => [
                'severity' => in_array($f['severity'] ?? null, ['info', 'warning', 'error'], true) ? $f['severity'] : 'info',
                'line' => is_numeric($f['line'] ?? null) ? (int) $f['line'] : null,
                'issue' => mb_substr((string) ($f['issue'] ?? ''), 0, 1000),
                'suggestion' => mb_substr((string) ($f['suggestion'] ?? ''), 0, 1000),
                'standard' => mb_substr((string) ($f['standard'] ?? ''), 0, 200),
                'details' => mb_substr((string) ($f['details'] ?? ''), 0, 3000),
                'impact' => mb_substr((string) ($f['impact'] ?? ''), 0, 1500),
                'fix' => mb_substr((string) ($f['fix'] ?? ''), 0, 2000),
                'steps' => $this->stringList($f['steps'] ?? [], 20, 1500),
            ])
            ->filter(fn (array $f) => $f['issue'] !== '')
            ->values()
            ->take(50)
            ->all();

        $description = is_array($data['description'] ?? null) ? $data['description'] : [];
        $settings = collect(is_array($description['settings'] ?? null) ? $description['settings'] : [])
            ->filter(fn ($item) => is_array($item) && trim((string) ($item['setting'] ?? '')) !== '')
            ->map(fn (array $item) => [
                'setting' => mb_substr((string) $item['setting'], 0, 300),
                'meaning' => mb_substr((string) ($item['meaning'] ?? ''), 0, 1000),
            ])
            ->values()
            ->take(40)
            ->all();

        $threats = collect(is_array($data['threats'] ?? null) ? $data['threats'] : [])
            ->filter(fn ($item) => is_array($item) && trim((string) ($item['threat'] ?? '')) !== '')
            ->map(fn (array $item) => [
                'threat' => mb_substr((string) $item['threat'], 0, 300),
                'impact' => mb_substr((string) ($item['impact'] ?? ''), 0, 1000),
                'mitigation' => mb_substr((string) ($item['mitigation'] ?? ''), 0, 1000),
                'mitigated_here' => is_bool($item['mitigated_here'] ?? null) ? $item['mitigated_here'] : null,
            ])
            ->values()
            ->take(20)
            ->all();

        return [
            'status' => $status,
            'summary' => mb_substr((string) ($data['summary'] ?? ''), 0, 2000),
            'description' => [
                'overview' => mb_substr((string) ($description['overview'] ?? ''), 0, 3000),
                'settings' => $settings,
            ],
            'threats' => $threats,
            'findings' => $findings,
        ];
    }

    /**
     * Accepts a list of strings, or one string with one step per line.
     */
    private function stringList(mixed $value, int $max, int $length): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\n/', $value);
        }

        // Models often number steps themselves ("1) ...") and pack several into one string.
        return collect(is_array($value) ? $value : [])
            ->filter(fn ($item) => is_scalar($item))
            ->flatMap(fn ($item) => preg_split('/\R(?=\s*\d+[.)]\s)/', (string) $item))
            ->map(fn (string $item) => trim((string) preg_replace('/^\s*\d+[.)]\s+/', '', $item)))
            ->filter(fn (string $item) => $item !== '')
            ->map(fn (string $item) => mb_substr($item, 0, $length))
            ->values()
            ->take($max)
            ->all();
    }
}
