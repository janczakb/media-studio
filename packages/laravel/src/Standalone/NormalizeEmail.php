<?php

namespace UseFlex\MediaStudio\Standalone;

use Closure;
use Illuminate\Http\Request;

final class NormalizeEmail
{
    public function handle(Request $request, Closure $next)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        return $next($request);
    }
}
