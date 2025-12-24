<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class ResetPasswordNotification extends ResetPassword
{
    /**
     * Get the reset URL for the given notifiable.
     */
    protected function resetUrl($notifiable): string
    {
        // Generate a signed URL for API password reset
        // In production, this would point to your frontend reset page
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        // For API, we'll return a URL that includes the token
        // Frontend can extract token and call the reset endpoint
        return $frontendUrl . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}

