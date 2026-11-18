<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Api;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class AuthorizeApi
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('media-studio.api.enabled') === true, 404);
        // The stateless API never falls back to a browser cookie or the host guard.
        $token = app(ApiTokens::class)->authenticate($request->bearerToken());
        $usage = app(ApiUsage::class);
        $day = $usage->admit($token);
        $start = hrtime(true);
        try {
            $key = 'fms:api:'.$token->public_id;
            $limit = max(1, min(6000, (int) config('media-studio.api.requests_per_minute', 120)));
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                abort(429, 'API rate limit exceeded.', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }
            RateLimiter::hit($key, 60);
            $principal = new Principal($token->principal_type, $token->principal_id);
            $actor = app(StudioPermissions::class)->actor($principal);
            $request->setUserResolver(static fn () => $actor);
            abort_unless(app(WorkspaceAccess::class)->tenant($principal)?->key() === $token->tenant_key, 404);
            $workspace = $request->route('workspace');
            abort_unless($workspace === $token->workspace_public_id, 404);
            $w = app(WorkspaceAccess::class)->resolve($principal, $workspace);
            abort_unless((int) $w->id === (int) $token->workspace_id, 404);
            app(StudioPermissions::class)->authorize($actor, 'api.use', $w);
            abort_if(! $token->can_write && ! in_array($request->method(), ['GET', 'HEAD'], true), 403, 'This token is read-only.');
            $request->attributes->set('fms.api-token', $token);
            $request->attributes->set('fms.api-base', ApiTokens::prefix());
            $response = $next($request);
        } catch (Throwable $error) {
            $handler = app(ExceptionHandler::class);
            $handler->report($error);
            $response = $handler->render($request, $error);
        }
        try {
            $usage->finish($token, $day, $response->getStatusCode(), (int) ((hrtime(true) - $start) / 1000000));
        } catch (Throwable $error) {
            // An analytics failure must not turn an already committed mutation into a retryable 500.
            report($error);
        }
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
