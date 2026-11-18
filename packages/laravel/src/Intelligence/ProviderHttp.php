<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Intelligence;

use Illuminate\Support\Facades\Http;

final class ProviderHttp
{
    public function post(string $url, array $headers, array $body): array
    {
        // Fixed provider endpoints; no client-supplied remote addresses or automatic paid retries.
        $response = Http::acceptJson()->withHeaders($headers)->connectTimeout(3)->timeout(30)
            ->withOptions(['allow_redirects' => false])->post($url, $body);
        if (! $response->successful() || strlen($response->body()) > 262144 || ! is_array($response->json())) {
            throw new \RuntimeException('Image analysis provider failed.');
        }

        return $response->json();
    }
}
