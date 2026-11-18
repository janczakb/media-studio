<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Delivery;

use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use UseFlex\MediaStudio\Delivery\Adapters\BunnyUrl;
use UseFlex\MediaStudio\Delivery\Adapters\CloudFrontUrl;
use UseFlex\MediaStudio\Delivery\Adapters\PublicUrl;
use UseFlex\MediaStudio\Delivery\Adapters\S3Url;
use UseFlex\MediaStudio\Delivery\Adapters\WorkerUrl;

final class Delivery
{
    /** Public guest documents must permit only configured HTTPS delivery origins. */
    public function origins(): array
    {
        if (! config('media-studio.delivery.enabled', false)) {
            return [];
        }
        $origins = [];
        foreach ((array) config('media-studio.delivery.profiles', []) as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            if (isset($profile['url'])) {
                $url = parse_url(DeliveryUrl::base($profile));
                $origins[] = 'https://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
            }
            foreach ((array) ($profile['allowed_hosts'] ?? []) as $host) {
                if (is_string($host) && preg_match('/\A[a-zA-Z0-9.-]+\z/', $host)) {
                    $origins[] = 'https://'.$host;
                }
            }
        }

        return array_values(array_unique($origins));
    }

    public function redirect(DeliveryResource $resource): ?RedirectResponse
    {
        // Ciphertext must never be redirected to a plaintext CDN/browser decoder.
        if (config('filesystems.disks.'.$resource->disk.'.driver') === 'fms-encrypted' || ! config('media-studio.delivery.enabled', false)) {
            return null;
        }
        if (! $resource->download && preg_match('~\A(?:image/(?:jpeg|png|webp|avif|gif)|video/[a-z0-9.+-]+|audio/[a-z0-9.+-]+|application/pdf)\z~', $resource->mime) !== 1) {
            return null;
        }
        $roles = config('media-studio.delivery.roles', []);
        $profileName = $roles[$resource->role] ?? null;
        foreach ([config('media-studio.delivery.disks.'.$resource->disk, []), config('media-studio.delivery.workspaces.'.$resource->workspace, [])] as $overrides) {
            $profileName = $overrides[$resource->role] ?? $profileName;
        }
        if ($resource->preset !== null) {
            $profileName = config('media-studio.delivery.presets.'.$resource->preset, $profileName);
        }
        $resolver = config('media-studio.delivery.resolver');
        if ($resolver !== null) {
            $resolver = app($resolver);
            if (! $resolver instanceof DeliveryProfileResolver) {
                throw new LogicException('The delivery resolver must implement DeliveryProfileResolver.');
            }
            $profileName = $resolver->resolve($resource) ?? $profileName;
        }
        if ($profileName === null || $profileName === 'server') {
            return null;
        }
        if (! is_string($profileName) || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $profileName)) {
            throw new LogicException('Invalid delivery profile name.');
        }
        $profile = config('media-studio.delivery.profiles.'.$profileName);
        if (! is_array($profile)) {
            throw new LogicException('Unknown media delivery profile.');
        }
        $revocation = $profile['revocation'] ?? 'per_request';
        if (! in_array($revocation, ['per_request', 'expiry', 'public'], true)) {
            throw new LogicException('Invalid media delivery revocation mode.');
        }
        // A pre-signed address does not recheck live permissions. Never silently weaken this mode.
        $type = $profile['driver'] ?? '';
        if ($revocation === 'per_request' && $type !== 'worker') {
            return null;
        }
        if (($type === 'public') !== ($revocation === 'public')) {
            throw new LogicException('Public delivery requires matching driver and revocation configuration.');
        }
        $types = ['public' => PublicUrl::class, 's3' => S3Url::class, 'cloudfront' => CloudFrontUrl::class, 'bunny' => BunnyUrl::class, 'worker' => WorkerUrl::class];
        $adapterClass = $types[$type] ?? config('media-studio.delivery.adapters.'.$type);
        if (! is_string($adapterClass)) {
            throw new LogicException('Unknown media delivery driver.');
        }
        $adapter = app($adapterClass);
        if (! $adapter instanceof DeliveryAdapter) {
            throw new LogicException('Media delivery adapters must implement DeliveryAdapter.');
        }
        // Download disposition must be supported by the remote origin, not guessed by the frontend.
        if ($resource->download && ! in_array($type, ['s3', 'worker'], true) && ($profile['download_headers'] ?? false) !== true) {
            return null;
        }
        $ttl = $profile['ttl_seconds'] ?? 120;
        if (! is_int($ttl) || $ttl < 10 || $ttl > 3600) {
            throw new LogicException('Media delivery TTL must be between 10 and 3600 seconds.');
        }
        $expiresAt = min(now()->getTimestamp() + $ttl, $resource->expiresAt ?? PHP_INT_MAX);
        abort_unless($expiresAt > now()->getTimestamp(), 403);
        if ($revocation === 'per_request') {
            $grant = app(EdgeGrant::class)->issue($resource, $profileName, $expiresAt);
            if ($grant === null) {
                return null;
            }
            $profile['grant'] = $grant;
        }
        $url = $adapter->url($resource, $profile, $expiresAt);
        $parts = parse_url($url);
        $hosts = $profile['allowed_hosts'] ?? (isset($profile['url']) ? [parse_url(DeliveryUrl::base($profile), PHP_URL_HOST)] : []);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! is_array($hosts) || ! in_array($parts['host'] ?? '', $hosts, true) || strlen($url) > 16384 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new LogicException('Delivery URL must use HTTPS and an explicitly allowed host.');
        }

        return new RedirectResponse($url, 307, [
            'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
            'X-FMS-Delivery-Expires' => (string) $expiresAt,
        ]);
    }
}
