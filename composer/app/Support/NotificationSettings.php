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
     * Setup guide per channel, from each provider's official documentation (docs):
     * steps for the organization ('org') and for each workspace group's target ('group'),
     * an example target, and limits worth knowing ('notes').
     */
    public const GUIDES = [
        'email' => [
            'docs' => null,
            'org' => [
                'Set the SMTP server, port, encryption, username and From address on the Email Configuration tab, then send a test email from there.',
                'Use an address on a domain with SPF and DKIM records for your mail provider, so alerts are not marked as spam.',
            ],
            'group' => ['Enter one or more email addresses or distribution lists, separated by commas (up to 50).'],
            'example' => 'ops@example.com, oncall@example.com',
            'notes' => [],
        ],
        'teams' => [
            'docs' => 'https://support.microsoft.com/en-us/office/create-incoming-webhooks-with-workflows-for-microsoft-teams-8ae491c7-0394-4861-ba59-055e33f75498',
            'org' => [
                'Office 365 connectors are retired; Teams alerts use the Workflows app. A Teams admin must allow the Workflows (Power Automate) app in the Teams admin center.',
                'No organization-level connection is needed here.',
            ],
            'group' => [
                'In Teams, open the target channel > ... (More options) > Workflows.',
                'Choose the template "Send webhook alerts to a channel", confirm the team and channel, then Save.',
                'Copy the webhook URL shown at the end and paste it as this group\'s target.',
            ],
            'example' => 'https://….logic.azure.com/workflows/…/triggers/manual/paths/invoke?…',
            'notes' => [
                'Alerts are sent as Adaptive Cards, which the template accepts.',
                'Private channels are not supported by Workflows. A message can be at most about 28 KB.',
            ],
        ],
        'slack' => [
            'docs' => 'https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks',
            'org' => ['No organization-level connection is needed here: each webhook belongs to one Slack channel.'],
            'group' => [
                'Go to api.slack.com/apps > Create New App > From scratch, and pick your workspace.',
                'Open Incoming Webhooks and turn on Activate Incoming Webhooks.',
                'Select Add New Webhook to Workspace, choose the channel, and Allow.',
                'Copy the Webhook URL and paste it as this group\'s target.',
            ],
            'example' => 'https://hooks.slack.com/services/<workspace-id>/<webhook-id>/<token>',
            'notes' => ['Each webhook posts only to the channel chosen when it was created. Treat the URL as a secret.'],
        ],
        'whatsapp' => [
            'docs' => null,
            'org' => ['Not available yet: waiting for the provider\'s API details.'],
            'group' => [],
            'example' => '',
            'notes' => [],
        ],
        'n8n' => [
            'docs' => 'https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.webhook/',
            'org' => [
                'Optional: if your Webhook nodes use Header auth or Basic auth, enter the same credentials below. The console sends them with every n8n request.',
            ],
            'group' => [
                'In n8n, start a workflow with a Webhook node. HTTP Method: POST. Authentication: None, Header auth or Basic auth (matching the settings below).',
                'Copy the Production URL (not the Test URL) and paste it as this group\'s target.',
                'Publish (activate) the workflow: the Production URL only works while the workflow is active.',
            ],
            'example' => 'https://n8n.example.com/webhook/atglance-alerts',
            'notes' => ['The body is the same JSON as the Webhook channel: event, title, facts and time.'],
        ],
        'telegram' => [
            'docs' => 'https://core.telegram.org/bots/tutorial',
            'org' => [
                'In Telegram, message @BotFather, send /newbot and follow the steps.',
                'Copy the bot token BotFather gives you into the field below. Treat it like a password.',
            ],
            'group' => [
                'Add the bot to the group, or as an administrator of the channel that should receive alerts.',
                'Channel with a public username: enter @channelname.',
                'Group or private channel: send a message there, open https://api.telegram.org/bot<token>/getUpdates and copy the chat "id" (groups and channels start with -100).',
            ],
            'example' => '-1001234567890 or @ops_alerts',
            'notes' => [],
        ],
        'webhook' => [
            'docs' => null,
            'org' => [
                'Optional: set a signing secret so your endpoint can check that requests come from this console.',
                'Each request then carries X-AtGlance-Signature: sha256=<HMAC-SHA256 of the raw body, keyed with the secret>. Compare it in constant time.',
            ],
            'group' => [
                'Enter an http(s) URL that accepts POST requests with a JSON body (https strongly recommended).',
                'Return any 2xx status. Other statuses and timeouts count as failed deliveries.',
            ],
            'example' => 'https://hooks.example.com/atglance',
            'notes' => [
                'Headers: Content-Type: application/json, X-AtGlance-Event: <event key>, and X-AtGlance-Signature when a secret is set.',
                'Body: JSON with the event key, title, facts and time.',
            ],
        ],
        'sms' => [
            'docs' => 'https://mailchimp.com/developer/transactional/guides/send-first-sms/',
            'org' => [
                'Needs a Mailchimp Standard plan or higher with Transactional enabled, SMS credits, and an approved SMS sending number (apply on Mailchimp\'s SMS marketing setup page).',
                'In Mailchimp Transactional, go to Settings > SMTP & API Info and create an API key.',
                'Enter the API key, the approved sending number and the consent type below.',
            ],
            'group' => ['Enter phone numbers in E.164 format, separated by commas (up to 50). Every recipient must have agreed to receive these text messages.'],
            'example' => '+14155550100, +919876543210',
            'notes' => [
                'SMS consent is separate from email consent. Recipients who reply STOP are not sent further messages; many STOP replies can get the account suspended.',
                'Messages are cut at 1,600 characters.',
            ],
        ],
    ];

    /**
     * Organization-level settings per channel: setting key => label, secret (stored encrypted),
     * and optional type (text, select, checkbox), options, default, placeholder and help.
     */
    public const CREDENTIALS = [
        'telegram' => [
            'notify_telegram_bot_token' => ['label' => 'Bot token', 'secret' => true, 'placeholder' => '123456789:AA…', 'help' => 'From @BotFather.'],
            'notify_telegram_silent' => ['label' => 'Send silently', 'secret' => false, 'type' => 'checkbox', 'help' => 'Members get the message without a sound (disable_notification).'],
        ],
        'webhook' => [
            'notify_webhook_signing_secret' => ['label' => 'Signing secret (HMAC-SHA256)', 'secret' => true, 'help' => 'Optional. Any long random string; give the same value to your endpoint.'],
        ],
        'n8n' => [
            'notify_n8n_auth_header_name' => ['label' => 'Header auth: name (optional)', 'secret' => false, 'placeholder' => 'X-AtGlance-Token', 'help' => 'Same as the Name of the Header Auth credential on the Webhook node.'],
            'notify_n8n_auth_header_value' => ['label' => 'Header auth: value (optional)', 'secret' => true, 'help' => 'Same as the Value of that credential.'],
            'notify_n8n_basic_user' => ['label' => 'Basic auth: user (optional)', 'secret' => false, 'help' => 'Same as the User of the Basic Auth credential on the Webhook node.'],
            'notify_n8n_basic_password' => ['label' => 'Basic auth: password (optional)', 'secret' => true, 'help' => 'Same as the Password of that credential.'],
        ],
        'sms' => [
            'notify_sms_mailchimp_api_key' => ['label' => 'Mailchimp Transactional API key', 'secret' => true, 'help' => 'Settings > SMTP & API Info in Mailchimp Transactional.'],
            'notify_sms_from' => ['label' => 'From number', 'secret' => false, 'placeholder' => '+14155550100', 'help' => 'Your approved SMS sending number, in E.164 format.'],
            'notify_sms_consent' => ['label' => 'Consent type', 'secret' => false, 'type' => 'select', 'default' => 'recurring', 'options' => [
                'recurring' => 'Recurring: recipients agreed to a series of alerts (Mailchimp sends a confirmation text first)',
                'recurring-no-confirm' => 'Recurring, confirmation already sent by you',
                'onetime' => 'One-time: recipients agreed to a single message',
            ], 'help' => 'Required by Mailchimp: the kind of consent your recipients gave.'],
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
        $value = trim((string) AdminSetting::getValue($key, ''));
        if ($value !== '') {
            return $value;
        }
        foreach (self::CREDENTIALS as $credentials) {
            if (isset($credentials[$key]['default'])) {
                return (string) $credentials[$key]['default'];
            }
        }

        return '';
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
