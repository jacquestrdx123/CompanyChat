<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InviteNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token, public ?string $invitedBy = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function url(string $email, string $token): string
    {
        return config('app.frontend_url').'/accept-invite?'.http_build_query(['token' => $token, 'email' => $email]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');
        $days = (int) round(config('auth.passwords.invites.expire') / 1440);

        return (new MailMessage)
            ->subject("You're invited to {$app}")
            ->greeting("Hi {$notifiable->display_name},")
            ->line(($this->invitedBy ? "{$this->invitedBy} invited you" : 'You have been invited')." to join {$app}.")
            ->action('Accept invite and choose a password', self::url($notifiable->email, $this->token))
            ->line("This link expires in {$days} days.");
    }
}
