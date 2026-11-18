<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

trait DisplaysStudioMedia
{
    private string|Closure|null $studioWorkspace = null;

    private ?array $studioFiles = null;

    public function studio(string|Closure $workspace): static
    {
        $this->studioWorkspace = $workspace;
        $this->extraImgAttributes(['loading' => 'lazy', 'decoding' => 'async']);

        return $this;
    }

    public function openStudioMedia(bool $newTab = true): static
    {
        return $this->url(fn (mixed $state): ?string => $this->studioFile($state)['url'] ?? null, $newTab);
    }

    public function getImageUrl(?string $state = null, ?Model $relatedRecord = null): ?string
    {
        $file = $this->studioFile($state);
        if ($file === null) {
            return null;
        }

        return StudioMediaDisplay::image($file) ?? self::documentIcon();
    }

    public function getAlt(mixed $state = null, ?Model $relatedRecord = null): ?string
    {
        $file = $this->studioFile($state);

        return parent::getAlt($state, $relatedRecord) ?? $file['alt'] ?? $file['name'] ?? null;
    }

    protected function studioWorkspace(): ?string
    {
        return $this->evaluate($this->studioWorkspace);
    }

    protected function studioValues(): array
    {
        $state = $this->getState();
        $values = $state instanceof Collection ? $state->all() : Arr::wrap($state);
        $limit = $this->getLimit();

        return $limit && count($values) > $limit ? array_slice($values, 0, $limit) : $values;
    }

    /** @return array<string, array<string, array>> */
    protected function loadStudioFiles(): array
    {
        $workspace = $this->studioWorkspace();

        return $workspace === null ? [] : [$workspace => app(StudioMediaDisplay::class)->resolve($workspace, $this->studioValues())];
    }

    private function studioFile(mixed $state): ?array
    {
        if (! is_string($state) || ($workspace = $this->studioWorkspace()) === null) {
            return null;
        }
        $this->studioFiles ??= $this->loadStudioFiles();

        return $this->studioFiles[$workspace][$state] ?? null;
    }

    private static function documentIcon(): string
    {
        return 'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect width="48" height="48" rx="8" fill="#e9edf3"/><path d="M16 12h11l6 6v18H16z M27 12v6h6 M20 24h9m-9 5h9" fill="none" stroke="#52637a" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>');
    }
}
