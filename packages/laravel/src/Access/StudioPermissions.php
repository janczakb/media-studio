<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\PrincipalActorResolver;

final class StudioPermissions
{
    public const OPERATIONS = ['extension.use', 'integrations.view', 'integrations.manage', 'api.use', 'api.manage', 'ai.view', 'ai.review', 'mcp.use', 'application-files.view', 'application-files.download', 'application-files.index', 'application-files.spatie.view', 'view', 'write', 'comments.view', 'comments.create', 'comments.resolve', 'comments.manage', 'workspaces.create', 'workspaces.manage', 'files.update', 'notes.create', 'notes.update', 'files.trash', 'files.restore', 'files.download', 'files.optimize', 'files.process', 'folders.create', 'uploads.create', 'tags.manage', 'shares.view', 'shares.manage', 'settings.view', 'settings.manage', 'notifications.view', 'notifications.manage', 'members.view', 'members.manage', 'members.invite', 'sources.sync'];

    public function allows(Authenticatable $actor, string $operation, ?object $workspace = null): bool
    {
        return Gate::forUser($actor)->allows(self::ability($operation), $workspace === null ? [] : [$workspace]);
    }

    public static function ability(string $operation): string
    {
        $ability = ((array) config('media-studio.permissions.abilities', []))[$operation] ?? 'studio.'.$operation;
        abort_unless(is_string($ability) && $ability !== '', 403);

        return $ability;
    }

    public function authorize(Authenticatable $actor, string $operation, ?object $workspace = null): void
    {
        abort_unless($this->allows($actor, $operation, $workspace), 403);
    }

    public function actor(Principal $principal): Authenticatable
    {
        $resolver = config('media-studio.permissions.actor_resolver');
        if (is_string($resolver) && $resolver !== '') {
            $instance = app($resolver);
            abort_unless($instance instanceof PrincipalActorResolver, 403);
            $actor = $instance->resolve($principal);
            abort_unless(Principal::fromUser($actor)->key() === $principal->key(), 403);

            return $actor;
        }
        $class = config('media-studio.principals.'.$principal->type);
        abort_unless(is_string($class) && is_subclass_of($class, Model::class) && is_subclass_of($class, Authenticatable::class), 403);
        $model = new $class;
        $actor = $model->newQuery()->where($model->getAuthIdentifierName(), $principal->id)->first();
        abort_unless($actor instanceof Authenticatable, 403);

        return $actor;
    }
}
