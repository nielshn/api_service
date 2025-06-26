<?php
// app/Notifications/CustomVerifyEmail.php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class CustomVerifyEmail extends Notification
{
    use Queueable;

    protected $pendingEmail;

    public function __construct($pendingEmail)
    {
        $this->pendingEmail = $pendingEmail;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Verifikasi Email Baru')
            ->line('Anda telah meminta untuk mengubah email Anda.')
            ->line('Klik tombol di bawah ini untuk memverifikasi email baru Anda.')
            ->action('Verifikasi Email', $verificationUrl)
            ->line('Jika Anda tidak meminta perubahan ini, abaikan email ini.')
            ->line('Link ini akan kedaluwarsa dalam 60 menit.');
    }

    protected function verificationUrl($notifiable)
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($this->pendingEmail),
            ]
        );
    }


}
