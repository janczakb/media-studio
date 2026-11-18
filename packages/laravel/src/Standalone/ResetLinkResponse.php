<?php

namespace UseFlex\MediaStudio\Standalone;

use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

final class ResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(public ?string $status = null) {}

    public function toResponse($request)
    {
        return $request->wantsJson() ? response()->json(['message' => 'If that account exists, a password reset link has been sent.'], 202) : back()->with('status', 'If that account exists, a password reset link has been sent.');
    }
}
