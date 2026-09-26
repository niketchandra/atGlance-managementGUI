<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\NotificationSettings;
use App\Support\SiteProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails one account alert (new sign-in, password changed, ...) to the user,
 * through the SMTP server on the Email Configuration tab. Queued, so sign-in
 * and settings requests never wait for SMTP.
 */
class SendAccountAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param array<string, string> $details label => value lines under the message
     */
    public function __construct(
        public int $userId,
        public string $subject,
        public string $message,
        public array $details = [],
    ) {
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (!$user || trim((string) $user->email) === '' || !NotificationSettings::mailConfigured()) {
            return;
        }

        NotificationSettings::applyMailConfig();
        $brand = SiteProfile::current()->name();

        $lines = [$this->message, ''];
        foreach ($this->details as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        $lines[] = '';
        $lines[] = 'If this was not you, change your password and sign out other sessions under Settings > Security.';
        $lines[] = 'You can turn off optional alerts under Settings > Preferences.';
        $lines[] = '';
        $lines[] = '- ' . $brand;

        try {
            Mail::mailer('smtp')->raw(implode("\n", $lines), function (Message $mail) use ($user, $brand) {
                $mail->to($user->email)->subject('[' . $brand . '] ' . $this->subject);
            });
        } catch (Throwable $e) {
            Log::warning('Account alert not sent', ['user_id' => $this->userId, 'subject' => $this->subject, 'error' => $e->getMessage()]);
        }
    }
}
