<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Support\Facades\Http;

/** Server-only transport. No credentials or index responses are returned to browsers. */
final class Meilisearch
{
    public function index(): string
    {
        $index = config('media-studio.search.meilisearch.index');
        if (! is_string($index) || ! preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $index)) {
            throw new \LogicException('Configure a valid Studio search index.');
        }

        return $index;
    }

    public function request(string $method, string $path, array $data = []): array
    {
        abort_unless(config('media-studio.search.enabled') === true, 404);
        $host = config('media-studio.search.meilisearch.host');
        $key = config('media-studio.search.meilisearch.key');
        $parts = is_string($host) ? parse_url($host) : false;
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! is_string($key) || $key === '') {
            throw new \LogicException('Configure the server-side Meilisearch host and key.');
        }
        $response = Http::acceptJson()->withToken($key)->connectTimeout(2)->timeout(10)
            ->withOptions(['allow_redirects' => false])->send($method, rtrim($host, '/').$path, $method === 'GET' ? [] : ['json' => $data]);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new \RuntimeException('Studio search service is unavailable.');
        }

        return $response->json();
    }

    public function configure(): int
    {
        $settings = [
            'searchableAttributes' => ['name', 'content', 'ai'],
            'displayedAttributes' => ['id', 'workspace', 'revision', 'version', 'contentHash', 'aiHash'],
            'filterableAttributes' => ['workspace'],
            'pagination' => ['maxTotalHits' => 1000],
        ];
        if (config('media-studio.search.semantic.enabled') === true) {
            $embedder = config('media-studio.search.semantic.embedder');
            if (! is_array($embedder) || ! in_array($embedder['source'] ?? null, ['openAi', 'huggingFace', 'rest'], true)) {
                throw new \LogicException('Configure a supported semantic embedder.');
            }
            $embedder += ['documentTemplate' => '{{doc.name}} {{doc.ai}} {{doc.content}}', 'documentTemplateMaxBytes' => 6000];
            $maxBytes = $embedder['documentTemplateMaxBytes'];
            if (! is_int($maxBytes) || $maxBytes < 1 || $maxBytes > 12000) {
                throw new \LogicException('Semantic document input must be bounded to 1–12000 bytes.');
            }
            $settings['embedders'] = ['studio' => $embedder];
        }

        return $this->taskId($this->request('PATCH', '/indexes/'.$this->index().'/settings', $settings));
    }

    public function publish(array $documents): int
    {
        if (count($documents) > 120) {
            throw new \InvalidArgumentException('Index batches cannot exceed 120 documents.');
        }

        return $this->taskId($this->request('POST', '/indexes/'.$this->index().'/documents?primaryKey=id', $documents));
    }

    public function taskId(array $response): int
    {
        if (! isset($response['taskUid']) || ! is_int($response['taskUid'])) {
            throw new \RuntimeException('Search service did not acknowledge the task.');
        }

        return $response['taskUid'];
    }
}
