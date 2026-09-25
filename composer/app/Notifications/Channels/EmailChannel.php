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
}
