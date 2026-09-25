<?php

namespace App\Support;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Config;

/**
 * Notification channels the super admin has allowed, and their
 * organization-level credentials. Stored in admin_settings (group
 * "notification"); secrets are encrypted.
 */
class NotificationSettings
{
    /**
     * Channel catalog. "target" describes what a workspace admin enters for a group.
     * "available" is false for channels that cannot be used yet.
     */
    public const CHANNELS = [
        'email' => ['label' => 'Email', 'target' => 'Email addresses, comma separated', 'available' => true],
        'teams' => ['label' => 'Microsoft Teams', 'target' => 'Teams channel webhook URL (Workflows)', 'available' => true],
        'slack' => ['label' => 'Slack', 'target' => 'Slack incoming webhook URL', 'available' => true],
        'whatsapp' => ['label' => 'WhatsApp (SimpleFloww)', 'target' => 'Phone numbers', 'available' => false],
        'n8n' => ['label' => 'n8n workflow', 'target' => 'n8n webhook URL', 'available' => true],
        'telegram' => ['label' => 'Telegram', 'target' => 'Telegram chat ID (e.g. -1001234567890 or @channel)', 'available' => true],
        'webhook' => ['label' => 'Webhook', 'target' => 'Webhook URL', 'available' => true],
        'sms' => ['label' => 'SMS (Mailchimp Transactional)', 'target' => 'Phone numbers in E.164, comma separated (e.g. +919876543210)', 'available' => true],
    ];

    /**
     * Organization-level credentials per channel: setting key => [label, encrypted].
     */
    public const CREDENTIALS = [
        'telegram' => [
            'notify_telegram_bot_token' => ['label' => 'Bot token', 'secret' => true],
        ],
        'webhook' => [
            'notify_webhook_signing_secret' => ['label' => 'Signing secret (HMAC-SHA256)', 'secret' => true],
        ],
        'n8n' => [
            'notify_n8n_auth_header_name' => ['label' => 'Auth header name (optional)', 'secret' => false],
            'notify_n8n_auth_header_value' => ['label' => 'Auth header value (optional)', 'secret' => true],
        ],
        'sms' => [
            'notify_sms_mailchimp_api_key' => ['label' => 'Mailchimp Transactional API key', 'secret' => true],
            'notify_sms_from' => ['label' => 'From number (E.164)', 'secret' => false],
        ],
    ];

    /**
     * Credentials a channel cannot send without.
     */
    private const REQUIRED = [
        'telegram' => ['notify_telegram_bot_token'],
        'sms' => ['notify_sms_mailchimp_api_key', 'notify_sms_from'],
    ];

    public static function isAllowed(string $channel): bool
    {
        if (!(self::CHANNELS[$channel]['available'] ?? false)) {
            return false;
        }

        return filter_var((string) AdminSetting::getValue('notify_' . $channel . '_allowed', 'false'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Channels a workspace admin can add groups for: allowed and ready to send.
     *
     * @return array<string, string> channel => label
     */
    public static function usableChannels(): array
    {
        return collect(self::CHANNELS)
            ->filter(fn (array $meta, string $channel) => self::isAllowed($channel) && self::missingSetup($channel) === null)
            ->map(fn (array $meta) => $meta['label'])
            ->all();
    }

    /**
     * Why an allowed channel cannot send yet, or null when it is ready.
     */
    public static function missingSetup(string $channel): ?string
    {
        if ($channel === 'email' && !self::mailConfigured()) {
            return 'Complete the Email Configuration tab first.';
        }

        foreach (self::REQUIRED[$channel] ?? [] as $key) {
            if (self::credential($key) === '') {
                return 'Missing: ' . self::CREDENTIALS[$channel][$key]['label'] . '.';
            }
        }

        return null;
    }

    public static function credential(string $key): string
    {
        return trim((string) AdminSetting::getValue($key, ''));
    }

    public static function mailConfigured(): bool
    {
        foreach (['mail_host', 'mail_port', 'mail_from_address'] as $key) {
            if (trim((string) AdminSetting::getValue($key, '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Points the SMTP mailer at the Email Configuration tab's settings.
     */
    public static function applyMailConfig(): void
    {
        $encryption = strtolower(trim((string) AdminSetting::getValue('mail_encryption', '')));

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', trim((string) AdminSetting::getValue('mail_host', '')));
        Config::set('mail.mailers.smtp.port', (int) AdminSetting::getValue('mail_port', 587));
        Config::set('mail.mailers.smtp.username', trim((string) AdminSetting::getValue('mail_username', '')) ?: null);
        Config::set('mail.mailers.smtp.password', (string) AdminSetting::getValue('mail_password', '') ?: null);
        // "ssl" means implicit TLS (smtps); "tls"/"starttls" upgrade a plain connection.
        Config::set('mail.mailers.smtp.scheme', $encryption === 'ssl' ? 'smtps' : 'smtp');
        Config::set('mail.from.address', trim((string) AdminSetting::getValue('mail_from_address', '')));
        Config::set('mail.from.name', trim((string) AdminSetting::getValue('mail_from_name', '')) ?: SiteProfile::current()->name());

        app('mail.manager')->purge('smtp');
    }
}
