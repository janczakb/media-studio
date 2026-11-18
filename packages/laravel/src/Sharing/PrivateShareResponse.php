<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sharing;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrivateShareResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->expectsJson()) {
            $status = $response->getStatusCode();
            if ($status === 423 && $request->isMethod('GET')) {
                $response = redirect()->route('media-studio.shared.show', ['token' => $request->route('token')]);
            } elseif (in_array($status, [403, 404, 419, 429], true)) {
                // Do not distinguish a revoked/expired token from an unknown one or expose host error pages.
                $nonce = base64_encode(random_bytes(18));
                $response = response()->view('media-studio::shared-unavailable', [
                    'limited' => $status === 429, 'sessionExpired' => $status === 419, 'nonce' => $nonce,
                    'collectionUrl' => route('media-studio.shared.show', ['token' => $request->route('token')]),
                ], $status)->header('Content-Security-Policy', "default-src 'none'; style-src 'nonce-{$nonce}'; frame-ancestors 'none'; base-uri 'none'");
            }
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (! $response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        if ($response instanceof StreamedResponse) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; base-uri 'none'");
        }

        return $response;
    }
}
