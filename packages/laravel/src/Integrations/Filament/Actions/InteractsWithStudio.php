<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament\Actions;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Identity\Principal;

trait InteractsWithStudio
{
    protected string|Closure|null $studioWorkspace = null;

    protected ?Closure $studioEntry = null;

    public function workspace(string|Closure $workspace): static
    {
        $this->studioWorkspace = $workspace;

        return $this;
    }

    /** @param Closure(Model|array):array{id:string,revision:int} $resolver */
    public function entry(Closure $resolver): static
    {
        $this->studioEntry = $resolver;

        return $this;
    }

    protected function workspaceId(): string
    {
        $workspace = $this->evaluate($this->studioWorkspace);
        abort_unless(is_string($workspace) && Str::isUlid($workspace), 422, 'A Studio workspace ULID is required.');

        return strtoupper($workspace);
    }

    protected function snapshotAction(): string
    {
        return static::class.':'.$this->getName();
    }

    /** @return array{id:string,revision:int} */
    protected function entryIdentity(Model|array|null $record): array
    {
        abort_if($record === null, 404);
        $entry = $this->studioEntry === null
            ? ['id' => data_get($record, 'public_id'), 'revision' => data_get($record, 'revision')]
            : $this->evaluate($this->studioEntry, ['record' => $record], is_object($record) ? [$record::class => $record, Model::class => $record] : []);
        abort_unless(is_array($entry) && is_string($entry['id'] ?? null) && Str::isUlid($entry['id'])
            && is_int($entry['revision'] ?? null) && $entry['revision'] > 0, 422, 'The host record must map to a Studio ID and revision.');

        return ['id' => strtoupper($entry['id']), 'revision' => $entry['revision']];
    }

    protected function studioPrincipal(): Principal
    {
        $this->getAuthorizationResponse()->authorize();
        $panel = app()->bound('filament') ? Filament::getCurrentPanel() : null;
        $user = $panel === null ? auth()->user() : $panel->auth()->user();
        abort_unless($user instanceof Authenticatable, 403);

        return Principal::fromUser($user);
    }
}
