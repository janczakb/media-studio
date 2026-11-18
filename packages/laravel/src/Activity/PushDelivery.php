<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Activity;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

class PushDelivery
{
    private ?WebPush $client = null;

    public function __construct(private ?ClientInterface $http = null) {}

    public function send(array $subscription, array $payload, int $ttl): int
    {
        $this->client ??= new WebPush(['VAPID' => [
            'subject' => config('media-studio.notifications.push.subject'),
            'publicKey' => config('media-studio.notifications.push.public_key'),
            'privateKey' => config('media-studio.notifications.push.private_key'),
        ]], client: $this->http ?? new Client(['timeout' => 4, 'allow_redirects' => false, 'connect_timeout' => 2]),
            requestFactory: new HttpFactory, streamFactory: new HttpFactory);
        $this->client->setReuseVAPIDHeaders(true);
        $result = $this->client->sendOneNotification(Subscription::create([
            ...$subscription, 'contentEncoding' => 'aes128gcm',
        ]), json_encode($payload, JSON_THROW_ON_ERROR), [
            'TTL' => $ttl, 'urgency' => 'normal',
        ]);

        return $result->getResponse()?->getStatusCode() ?? 0;
    }
}
