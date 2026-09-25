<?php

namespace App\Notifications;

/**
 * One notification, in the form every channel sends it.
 */
final class Message
{
    /**
     * @param array<string, string> $facts label => value, shown as a list
     * @param array<string, mixed> $data machine-readable payload for webhook and n8n
     */
    public function __construct(
        public readonly string $event,
        public readonly string $title,
        public readonly array $facts = [],
        public readonly array $data = [],
    ) {
    }

    /**
     * Plain text: the title, then one "Label: value" line per fact.
     */
    public function text(): string
    {
        $lines = [$this->title];
        foreach ($this->facts as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        return implode("\n", $lines);
    }

    /**
     * JSON body for webhook and n8n channels.
     */
    public function payload(): array
    {
        return [
            'event' => $this->event,
            'title' => $this->title,
            'text' => $this->text(),
            'facts' => $this->facts,
            'data' => $this->data,
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
