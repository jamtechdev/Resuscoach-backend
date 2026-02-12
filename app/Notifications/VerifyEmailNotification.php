<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends VerifyEmail
{
    /**
     * Build the mail representation of the notification with explicit sender name.
     */
    public function toMail($notifiable)
    {
        $message = parent::toMail($notifiable);

        return $message->from(
            config('mail.from.address'),
            config('mail.from.name')
        );
    }

    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl($notifiable): string
    {
        // Generate a signed URL for API verification
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
