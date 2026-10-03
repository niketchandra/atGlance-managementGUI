<?php

namespace App\Notifications\Channels;

use App\Models\NotificationGroup;
use App\Notifications\Message;
use App\Support\NotificationSettings;
use App\Support\SiteProfile;
use Illuminate\Mail\Message as MailMessage;
use Illuminate\Support\Facades\Mail;

/**
 * Email through the SMTP server set on the Email Configuration tab.
 */
class EmailChannel implements NotificationChannel
{
    public function send(NotificationGroup $group, Message $message): void
    {
        NotificationSettings::applyMailConfig();

        $subject = '[' . SiteProfile::current()->name() . '] ' . $message->title;

        Mail::mailer('smtp')->raw($message->text(), function (MailMessage $mail) use ($group, $subject) {
            $mail->to($group->targetList())->subject($subject);
        });
    }

    /**
     * Workspace members who chose this event. Bcc, so members do not see each other's address.
     *
     * @param array<int, string> $addresses
     */
    public function sendToMembers(array $addresses, Message $message): void
    {
        NotificationSettings::applyMailConfig();

        $subject = '[' . SiteProfile::current()->name() . '] ' . $message->title;
        $from = (string) config('mail.from.address');

        Mail::mailer('smtp')->raw($message->text(), function (MailMessage $mail) use ($addresses, $subject, $from) {
            $mail->to($from !== '' ? $from : $addresses[0])->bcc($addresses)->subject($subject);
        });
    }
}
