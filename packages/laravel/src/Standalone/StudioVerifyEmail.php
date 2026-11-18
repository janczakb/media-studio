<?php

namespace UseFlex\MediaStudio\Standalone;

use Illuminate\Auth\Notifications\VerifyEmail;
use UseFlex\MediaStudio\Mail\StudioMail;

final class StudioVerifyEmail extends VerifyEmail
{
    public function toMail($notifiable)
    {
        return StudioMail::notification(parent::toMail($notifiable));
    }
}
