<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;

final class AuthorizeMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        $allowed = config('media-studio.mcp.origins') ?? [rtrim((string) config('app.url'), '/')];
        abort_unless($origin === null || in_array($origin, (array) $allowed, true), 403);

        if (McpConnection::managed()) {
            $connection = McpConnection::fromRequest($request);
            abort_if($connection === null, 401, 'Invalid MCP credential.', ['WWW-Authenticate' => 'Bearer']);
            $actor = app(StudioPermissions::class)->actor($connection->principal);
            $request->setUserResolver(static fn () => $actor);
            abort_unless(app(WorkspaceAccess::class)->tenant($connection->principal)?->key() === $connection->tenantKey, 403);
        }

        abort_unless(app(McpAccess::class)->allows($request->user()), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
