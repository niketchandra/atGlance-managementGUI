<?php

namespace App\Http\Controllers;

use App\Support\NotificationSettings;
use App\Support\SiteProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message as MailMessage;
use Illuminate\Support\Facades\Mail;

/**
 * "Send test email" on Admin Settings > Email Configuration. Uses the saved
 * SMTP settings through the same path as the email notification channel.
 */
class MailTestController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipient' => ['required', 'email', 'max:255'],
        ]);

        if (!NotificationSettings::mailConfigured()) {
            return response()->json(['ok' => false, 'message' => 'Save the SMTP host, port and From address first.'], 422);
        }

        $recipient = $validated['recipient'];
        $host = NotificationSettings::credential('mail_host');
        $port = NotificationSettings::credential('mail_port');
        $siteName = SiteProfile::current()->name();

        try {
            NotificationSettings::applyMailConfig();
            Mail::mailer('smtp')->raw(
                "This is a test email from {$siteName}.\n\n"
                . "SMTP server: {$host}:{$port}\n"
                . 'Sent at: ' . now()->toDateTimeString() . "\n\n"
                . 'If you received it, the Email Configuration works.',
                function (MailMessage $mail) use ($recipient, $siteName) {
                    $mail->to($recipient)->subject('[' . $siteName . '] Test email');
                }
            );
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'The test email was not sent: ' . $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'message' => 'Test email sent to ' . $recipient . '. Check the inbox (and the spam folder).']);
    }
}
