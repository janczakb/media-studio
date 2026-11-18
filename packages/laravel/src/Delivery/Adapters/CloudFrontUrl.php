<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Delivery\Adapters;

use LogicException;
use UseFlex\MediaStudio\Delivery\DeliveryAdapter;
use UseFlex\MediaStudio\Delivery\DeliveryResource;
use UseFlex\MediaStudio\Delivery\DeliveryUrl;

final class CloudFrontUrl implements DeliveryAdapter
{
    public function url(DeliveryResource $resource, array $profile, int $expiresAt): string
    {
        $keyId = $profile['key_pair_id'] ?? '';
        if (! is_string($keyId) || ! preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $keyId)) {
            throw new LogicException('Configure the CloudFront public key ID.');
        }
        $pem = DeliveryUrl::secret($profile, 'private_key');
        if (! str_starts_with($pem, '-----BEGIN ')) {
            throw new LogicException('Configure the CloudFront signing key as PEM contents, not a path.');
        }
        $key = openssl_pkey_get_private($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new LogicException('CloudFront signing requires an RSA key of at least 2048 bits.');
        }
        $url = DeliveryUrl::object($resource, $profile).'?fmsv='.$resource->cacheKey();
        $policy = json_encode(['Statement' => [['Resource' => $url, 'Condition' => ['DateLessThan' => ['AWS:EpochTime' => $expiresAt]]]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! openssl_sign($policy, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new LogicException('CloudFront URL signing failed.');
        }
        $signature = strtr(base64_encode($signature), ['+' => '-', '=' => '_', '/' => '~']);

        return $url.'&Expires='.$expiresAt.'&Signature='.$signature.'&Key-Pair-Id='.$keyId.'&Hash-Algorithm=SHA256';
    }
}
