<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\BrowserExtension;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\TrustedClientContext;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class AuthorizeExtension
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokens = app(ExtensionTokens::class);
        $token = $tokens->authenticate($request->bearerToken());
        $key = 'fms:extension:device:'.$token->id;
        abort_if(RateLimiter::tooManyAttempts($key, 240), 429, 'Capture rate limit exceeded.', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        RateLimiter::hit($key, 60);
        $w = $tokens->workspace($token);
        $workspace = $request->route('workspace');
        abort_unless($workspace === null || $workspace === $w->public_id, 404);
        $principal = new Principal($token->principal_type, $token->principal_id);
        $request->setUserResolver(static fn () => app(StudioPermissions::class)->actor($principal));
        $request->attributes->set(TrustedClientContext::ATTRIBUTE, new TrustedClientContext($principal, TenantIdentity::fromWorkspace($w)));
        $request->attributes->set('fms.extension-token', $token);
        $request->attributes->set('fms.api-base', ExtensionTokens::prefix());
        try {
            return $next($request)->header('Cache-Control', 'private, no-store');
        } finally {
            $request->attributes->remove(TrustedClientContext::ATTRIBUTE);
        }
    }
}
