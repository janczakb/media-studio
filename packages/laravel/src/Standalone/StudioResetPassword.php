<?php

namespace UseFlex\MediaStudio\Standalone;

use Illuminate\Auth\Notifications\ResetPassword;
use UseFlex\MediaStudio\Mail\StudioMail;

final class StudioResetPassword extends ResetPassword
{
    public function toMail($notifiable)
    {
        return StudioMail::notification(parent::toMail($notifiable));
    }
}
